<?php

namespace App\Services\Olt;

use App\Http\Controllers\MikrotikController;
use App\Models\CustomersInfo;
use App\Models\NetworkInventoryDevice;
use App\Models\OltOnuCustomerMapping;
use App\Models\OltOnuCustomerPppLink;
use App\Models\PPPSecrets;
use App\Models\RouterList;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CustomerOnuRealtimeSyncService
{
    public function __construct(
        private readonly OltReadOnlyAdapterManager $adapterManager,
        private readonly VsolCliProvisioningService $vsolCli,
        private readonly VsolSshOpmCollector $vsolOpmCollector,
        private readonly MikrotikController $mikrotik,
    ) {}

    /** Refresh only one customer's live PPP identity and matching ONU telemetry. */
    public function sync(CustomersInfo $customer): array
    {
        $customer->loadMissing('pppUser');
        /** @var PPPSecrets|null $ppp */
        $ppp = $customer->pppUser;

        if (! $ppp || blank($ppp->username) || blank($ppp->router_name)) {
            throw new RuntimeException('এই customer-এর সঙ্গে active PPPoE account linked নেই।');
        }

        $routerName = trim((string) $ppp->router_name);
        $router = RouterList::query()
            ->where('router_name', $routerName)
            ->where('action', 'connected')
            ->first();

        if (! $router) {
            throw new RuntimeException("MikroTik router '{$routerName}' connected নয়; ONU sync করা যায়নি।");
        }

        $username = trim((string) $ppp->username);
        $quotedUsername = $this->mikrotik->mtQuote($username);

        $activeRows = $this->mikrotik->singleRead(
            $routerName,
            '/ppp/active/print',
            '/ppp active print where name='.$quotedUsername.' without-paging',
            ['name' => $username],
            false,
            true,
        );
        $secretRows = $this->mikrotik->singleRead(
            $routerName,
            '/ppp/secret/print',
            '/ppp secret print where name='.$quotedUsername.' without-paging',
            ['name' => $username],
            false,
            true,
        );

        $active = $this->findRouterRow($activeRows, $username);
        $secret = $this->findRouterRow($secretRows, $username);
        if (! $secret) {
            throw new RuntimeException('MikroTik-এ এই PPPoE secret পাওয়া যায়নি। আগে Router/PPPoE identity ঠিক করুন।');
        }

        $callerMac = $this->normalizeMac($active['caller-id'] ?? $active['caller_id'] ?? null);
        $identitySource = 'live_pppoe_session';
        if (! $callerMac) {
            $callerMac = $this->normalizeMac($secret['caller-id'] ?? $secret['caller_id'] ?? null)
                ?: $this->normalizeMac($ppp->last_caller_id);
            $identitySource = 'last_known_ppp_caller_id';
        }

        if (! $callerMac) {
            throw new RuntimeException('PPPoE session/secret থেকে client MAC পাওয়া যায়নি; exact ONU identity ছাড়া sync করা নিরাপদ নয়।');
        }

        // Fast path: when this customer's saved PPPoE MAC still matches the live
        // RouterOS caller-id, reuse the previously verified ONU identity and poll
        // only that OLT inventory. No full MAC/OPM sweep is needed for this row.
        $knownMappings = collect();
        $sharedLinks = OltOnuCustomerPppLink::query()
            ->with('onuMapping.olt')
            ->where('customer_id', $customer->id)
            ->where('ppp_user_id', $ppp->id)
            ->where('status', 'active')
            ->get();

        foreach ($sharedLinks as $link) {
            if ($this->normalizeMac($link->client_mac) === $callerMac && $link->onuMapping) {
                $knownMappings->push($link->onuMapping);
            }
        }

        if ($this->normalizeMac($ppp->last_caller_id) === $callerMac) {
            OltOnuCustomerMapping::query()
                ->with('olt')
                ->where('customer_id', $customer->id)
                ->where('ppp_user_id', $ppp->id)
                ->get()
                ->each(fn ($mapping) => $knownMappings->push($mapping));
        }

        $knownMappings = $knownMappings->unique('id')->values();
        $matches = [];
        $oltErrors = [];

        if ($knownMappings->count() > 1) {
            throw new RuntimeException('এই customer-এর saved PPPoE identity একাধিক ONU-তে linked; realtime sync ambiguous, কোনো পরিবর্তন করা হয়নি।');
        }

        if ($knownMappings->count() === 1) {
            /** @var OltOnuCustomerMapping $knownMapping */
            $knownMapping = $knownMappings->first();
            $knownOlt = $knownMapping->olt;
            if (! $knownOlt || ! $knownOlt->is_active) {
                throw new RuntimeException('Saved ONU mapping-এর OLT inactive বা unavailable।');
            }

            try {
                $source = $this->adapterManager->read($knownOlt);
                $rows = array_values(array_filter((array) ($source['onus'] ?? []), 'is_array'));
                $targetRows = array_values(array_filter($rows, function (array $row) use ($knownMapping): bool {
                    $pon = trim((string) ($row['pon_port'] ?? $row['pon'] ?? $row['ponPort'] ?? ''));
                    $onuId = trim((string) ($row['onu_id'] ?? $row['onuId'] ?? $row['id'] ?? ''));

                    return $pon === (string) $knownMapping->pon_port && $onuId === (string) $knownMapping->onu_id;
                }));

                if (count($targetRows) !== 1) {
                    throw new RuntimeException('Saved PON/ONU identity live OLT inventory-তে unique ভাবে পাওয়া যায়নি।');
                }

                $targetOnu = $targetRows[0];
                if (strcasecmp((string) $knownOlt->vendor, 'VSOL') === 0) {
                    // The normalized SNMP inventory may contain identity/status
                    // but omit live optical readings. Enrich this same poll with
                    // the VSOL read-only SSH OPM table, then select only this ONU.
                    $opm = $this->vsolOpmCollector->collect($knownOlt, $rows, (string) $knownMapping->pon_port, (string) $knownMapping->onu_id);
                    if (($opm['meta']['read_only'] ?? false) === true
                        && ($opm['meta']['writes_performed'] ?? true) === false
                        && is_array($opm['onus'] ?? null)) {
                        $opmRows = array_values(array_filter((array) $opm['onus'], function (array $row) use ($knownMapping): bool {
                            return trim((string) ($row['pon_port'] ?? $row['pon'] ?? $row['ponPort'] ?? '')) === (string) $knownMapping->pon_port
                                && trim((string) ($row['onu_id'] ?? $row['onuId'] ?? $row['id'] ?? '')) === (string) $knownMapping->onu_id;
                        }));
                        if (count($opmRows) === 1) {
                            $targetOnu = array_merge($targetOnu, array_filter($opmRows[0], static fn ($value, $key) => in_array($key, ['rx_power', 'tx_power', 'temperature', 'supply_voltage', 'distance_m', 'opm_source'], true), ARRAY_FILTER_USE_BOTH));
                        }
                    }
                }

                $matches[$knownOlt->id.'|'.$knownMapping->pon_port.'|'.$knownMapping->onu_id] = [
                    'olt' => $knownOlt,
                    'onu' => $targetOnu,
                    'pon' => (string) $knownMapping->pon_port,
                    'onu_id' => (string) $knownMapping->onu_id,
                ];
            } catch (\Throwable $exception) {
                $oltErrors[] = $knownOlt->name.': '.$exception->getMessage();
            }
        }

        // A saved identity is only a hint. If it is not present exactly once in
        // live inventory, fall back to exact live PPPoE-MAC discovery. This is
        // read-only and will not guess or update a mapping unless one ONU matches.
        if ($matches === []) {
            // If saved PON/ONU identity is stale or ambiguous, resolve this exact
            // live PPP caller-id against the read-only OLT MAC table instead.
            $olts = NetworkInventoryDevice::query()
                ->where('type', 'olt')
                ->where('is_active', true)
                ->get();

            if ($olts->isEmpty()) {
                throw new RuntimeException('কোনো active OLT পাওয়া যায়নি।');
            }

            foreach ($olts as $olt) {
                try {
                    $source = $this->adapterManager->read($olt);
                    $onus = array_values(array_filter((array) ($source['onus'] ?? []), 'is_array'));

                    if (strcasecmp((string) $olt->vendor, 'VSOL') === 0 && $onus !== []) {
                        // V1600D4/V1.0.2R has a verified CLI MAC table while its
                        // selected SNMP authInfo profile intentionally disables
                        // bridge-FDB discovery. Read the CLI table directly and
                        // join its exact EPON0/PON:ONU port to live SNMP inventory.
                        $macTable = $this->vsolCli->readOnlyMacTable($olt);
                        $macRows = array_values(array_filter(
                            (array) ($macTable['dynamic_onu_client_macs'] ?? []),
                            fn ($row) => $this->normalizeMac($row['mac'] ?? null) === $callerMac
                        ));

                        foreach ($macRows as $macRow) {
                            $pon = trim((string) ($macRow['pon_port'] ?? ''));
                            $onuId = trim((string) ($macRow['onu_id'] ?? ''));
                            if ($pon === '' || $onuId === '') continue;

                            $targetRows = array_values(array_filter($onus, function (array $row) use ($pon, $onuId): bool {
                                return trim((string) ($row['pon_port'] ?? $row['pon'] ?? $row['ponPort'] ?? '')) === $pon
                                    && trim((string) ($row['onu_id'] ?? $row['onuId'] ?? $row['id'] ?? '')) === $onuId;
                            }));
                            if (count($targetRows) !== 1) continue;

                            $matches[$olt->id.'|'.$pon.'|'.$onuId] = [
                                'olt' => $olt,
                                'onu' => $targetRows[0],
                                'pon' => $pon,
                                'onu_id' => $onuId,
                            ];
                        }
                    } else {
                        foreach ($onus as $onu) {
                            $clientMacs = collect((array) ($onu['client_macs'] ?? []))
                                ->map(fn ($mac) => $this->normalizeMac($mac))
                                ->filter()
                                ->unique();

                            if (! $clientMacs->contains($callerMac)) continue;

                            $pon = trim((string) ($onu['pon_port'] ?? $onu['pon'] ?? $onu['ponPort'] ?? ''));
                            $onuId = trim((string) ($onu['onu_id'] ?? $onu['onuId'] ?? $onu['id'] ?? ''));
                            if ($pon === '' || $onuId === '') continue;

                            $matches[$olt->id.'|'.$pon.'|'.$onuId] = [
                                'olt' => $olt,
                                'onu' => $onu,
                                'pon' => $pon,
                                'onu_id' => $onuId,
                            ];
                        }
                    }
                } catch (\Throwable $exception) {
                    $oltErrors[] = $olt->name.': '.$exception->getMessage();
                }
            }
            }

        if (count($matches) !== 1) {
            if (count($matches) > 1) {
                throw new RuntimeException('একই PPPoE client MAC একাধিক ONU interface-এ পাওয়া গেছে; ভুল mapping এড়াতে কোনো পরিবর্তন করা হয়নি।');
            }

            $detail = $oltErrors !== [] ? ' OLT read error: '.implode(' | ', array_slice($oltErrors, 0, 2)) : '';
            throw new RuntimeException('Live PPPoE MAC '.$callerMac.' কোনো OLT-এর Dynamic MAC table-এ পাওয়া যায়নি।'.$detail);
        }

        $match = array_values($matches)[0];
        $olt = $match['olt'];
        $onu = $match['onu'];
        $pon = $match['pon'];
        $onuId = $match['onu_id'];

        $result = DB::transaction(function () use ($customer, $ppp, $callerMac, $identitySource, $active, $olt, $onu, $pon, $onuId): array {
            $mappingRows = OltOnuCustomerMapping::query()
                ->where('olt_device_id', $olt->id)
                ->where('pon_port', $pon)
                ->where('onu_id', $onuId)
                ->lockForUpdate()
                ->get();

            if ($mappingRows->count() > 1) {
                throw new RuntimeException("OLT {$olt->name} PON {$pon} / ONU {$onuId}-এর একাধিক mapping row আছে; manual review প্রয়োজন।");
            }

            $targetMapping = $mappingRows->first();
            $activeLinks = OltOnuCustomerPppLink::query()
                ->where('ppp_user_id', $ppp->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->get();
            if ($activeLinks->count() > 1) {
                throw new RuntimeException('এই PPPoE account একাধিক active ONU link-এ আছে; manual review প্রয়োজন।');
            }

            $previousPrimaryMappings = OltOnuCustomerMapping::query()
                ->where('ppp_user_id', $ppp->id)
                ->when($targetMapping, fn ($query) => $query->where('id', '<>', $targetMapping->id))
                ->lockForUpdate()
                ->get();
            if ($previousPrimaryMappings->count() > 1) {
                throw new RuntimeException('এই PPPoE account-এর একাধিক legacy primary mapping আছে; manual review প্রয়োজন।');
            }

            $previousLink = $activeLinks->first();
            $previousLinkMapping = $previousLink && (!$targetMapping || (int) $previousLink->olt_onu_customer_mapping_id !== (int) $targetMapping->id)
                ? OltOnuCustomerMapping::query()->lockForUpdate()->find($previousLink->olt_onu_customer_mapping_id)
                : null;
            $previousPrimary = $previousPrimaryMappings->first();
            $previousMapping = $previousPrimary ?: $previousLinkMapping;

            if ($previousPrimary && $previousLinkMapping && (int) $previousPrimary->id !== (int) $previousLinkMapping->id) {
                throw new RuntimeException('Legacy primary mapping এবং active PPP link ভিন্ন ONU-তে আছে; manual review প্রয়োজন।');
            }
            if ($previousMapping) {
                if ((int) $previousMapping->olt_device_id !== (int) $olt->id
                    || ($previousMapping->customer_id && (int) $previousMapping->customer_id !== (int) $customer->id)
                    || ($previousMapping->ppp_user_id && (int) $previousMapping->ppp_user_id !== (int) $ppp->id)) {
                    throw new RuntimeException('আগের ONU mapping অন্য customer/OLT-এর; স্বয়ংক্রিয়ভাবে স্থানান্তর করা হয়নি।');
                }
                $otherSubscribers = OltOnuCustomerPppLink::query()
                    ->where('olt_onu_customer_mapping_id', $previousMapping->id)
                    ->where('status', 'active')
                    ->where('ppp_user_id', '<>', $ppp->id)
                    ->exists();
                if ($otherSubscribers) {
                    throw new RuntimeException('আগের ONU mapping-এ অন্য active subscriber আছে; shared ONU mapping manual review ছাড়া সরানো হবে না।');
                }
            }

            $allowedPreviousIds = collect([$previousMapping?->id])->filter()->values()->all();
            $mapping = $targetMapping ?: ($previousMapping ?: new OltOnuCustomerMapping([
                'olt_device_id' => $olt->id,
                'pon_port' => $pon,
                'onu_id' => $onuId,
            ]));
            $mappingId = $mapping->exists ? $mapping->id : null;

            $otherPrimary = OltOnuCustomerMapping::query()
                ->when($mappingId, fn ($query) => $query->where('id', '<>', $mappingId))
                ->when($allowedPreviousIds !== [], fn ($query) => $query->whereNotIn('id', $allowedPreviousIds))
                ->where(function ($query) use ($customer, $ppp): void {
                    $query->where('customer_id', $customer->id)
                        ->orWhere('ppp_user_id', $ppp->id);
                })
                ->exists();

            if ($otherPrimary) {
                throw new RuntimeException('এই customer/PPPoE account-এর অন্য ONU-তে conflicting primary mapping আছে; manual review প্রয়োজন।');
            }

            $now = now();
            $mapping->fill([
                'pon_port' => $pon,
                'onu_id' => $onuId,
                'onu_mac' => $onu['onu_mac'] ?? $mapping->onu_mac,
                'onu_serial' => $onu['onu_serial'] ?? $mapping->onu_serial,
                'onu_type' => $onu['onu_type'] ?? $mapping->onu_type,
                'status' => strtolower((string) ($onu['status'] ?? 'online')) === 'offline' ? 'offline' : 'online',
                'last_seen_at' => $now,
            ]);

            $hasFreshOpm = false;
            foreach (['rx_power', 'tx_power', 'temperature', 'supply_voltage', 'distance_m'] as $field) {
                if (isset($onu[$field]) && is_numeric($onu[$field])) {
                    $mapping->{$field} = (float) $onu[$field];
                    if (in_array($field, ['rx_power', 'tx_power'], true)) {
                        $hasFreshOpm = true;
                    }
                }
            }
            if ($hasFreshOpm) {
                $mapping->opm_source = $onu['opm_source'] ?? 'vsol-ssh-opm';
                $mapping->opm_last_seen_at = $now;
            }

            // Preserve an existing primary subscriber. Claim primary only when this
            // physical ONU has no legacy owner; otherwise add a separate PPP link.
            if (! $mapping->customer_id && ! $mapping->ppp_user_id) {
                $mapping->customer_id = $customer->id;
                $mapping->ppp_user_id = $ppp->id;
            } elseif ((int) $mapping->customer_id === (int) $customer->id
                && (! $mapping->ppp_user_id || (int) $mapping->ppp_user_id === (int) $ppp->id)) {
                $mapping->ppp_user_id = $ppp->id;
            }

            $mapping->save();

            if ($previousMapping && (int) $previousMapping->id !== (int) $mapping->id) {
                $previousMapping->customer_id = null;
                $previousMapping->ppp_user_id = null;
                $previousMapping->status = 'offline';
                $previousMapping->save();
            }

            $link = $previousLink ?: OltOnuCustomerPppLink::query()
                ->where('olt_onu_customer_mapping_id', $mapping->id)
                ->where('ppp_user_id', $ppp->id)
                ->first();

            $linkValues = [
                'customer_id' => $customer->id,
                'client_mac' => $callerMac,
                'source' => 'customer_list_realtime_sync:'.$identitySource,
                'status' => strtolower((string) $customer->status) === 'active'
                    && strtolower((string) $ppp->status) === 'active' ? 'active' : 'inactive',
                'last_seen_at' => $now,
            ];

            if ($link) {
                $link->olt_onu_customer_mapping_id = $mapping->id;
                $link->fill($linkValues)->save();
            } else {
                OltOnuCustomerPppLink::create($linkValues + [
                    'olt_onu_customer_mapping_id' => $mapping->id,
                    'ppp_user_id' => $ppp->id,
                    'first_seen_at' => $now,
                ]);
            }

            if (is_array($active) && $identitySource === 'live_pppoe_session') {
                $ppp->last_caller_id = $callerMac;
                $ppp->save();
            }

            $subscriberCount = OltOnuCustomerPppLink::query()
                ->where('olt_onu_customer_mapping_id', $mapping->id)
                ->where('status', 'active')
                ->count();

            return [
                'mapping_id' => $mapping->id,
                'pon' => $pon,
                'onu_id' => $onuId,
                'rx_power' => is_numeric($onu['rx_power'] ?? null) ? (float) $onu['rx_power'] : null,
                'tx_power' => is_numeric($onu['tx_power'] ?? null) ? (float) $onu['tx_power'] : null,
                'opm_fresh' => $hasFreshOpm,
                'opm_status' => $hasFreshOpm ? 'fresh' : (strtolower((string) ($onu['status'] ?? '')) === 'offline' ? 'olt_reports_offline' : 'not_reported_by_olt'),
                'subscriber_count' => $subscriberCount,
                'identity_source' => $identitySource,
                'session_online' => is_array($active) && $identitySource === 'live_pppoe_session',
            ];
        }, 3);

        \Log::info('Single customer ONU realtime sync completed', [
            'customer_id' => $customer->id,
            'ppp_user_id' => $ppp->id,
            'olt_device_id' => $olt->id,
            'mapping_id' => $result['mapping_id'],
            'pon' => $result['pon'],
            'onu_id' => $result['onu_id'],
            'identity_source' => $result['identity_source'],
            'opm_fresh' => $result['opm_fresh'],
        ]);

        return $result + ['olt_name' => $olt->name, 'customer_code' => $customer->customer_unique_id];
    }

    private function findRouterRow(array $rows, string $username): ?array
    {
        foreach ($rows as $row) {
            if (is_array($row) && strcasecmp((string) ($row['name'] ?? ''), $username) === 0) {
                return $row;
            }
        }

        return null;
    }

    private function normalizeMac(mixed $value): ?string
    {
        $normalized = strtolower(preg_replace('/[^a-f0-9]/i', '', (string) $value) ?: '');
        if (strlen($normalized) !== 12) {
            return null;
        }

        return implode(':', str_split($normalized, 2));
    }
}
