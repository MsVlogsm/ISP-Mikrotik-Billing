<?php

namespace App\Console\Commands;

use App\Models\CustomersInfo;
use App\Models\NetworkInventoryDevice;
use App\Models\OltOnuCustomerMapping;
use App\Models\PPPSecrets;
use App\Services\Olt\OltReadOnlyAdapterManager;
use App\Services\OnuMacLedgerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SyncOltOnuMappings extends Command
{
    protected $signature = 'olt:sync-onu-mappings {--device= : OLT device ID} {--dry-run : Read and resolve mappings without writing the local database}';
    protected $description = 'Read-only sync of ONU/customer mappings from OLT read-only adapters or configured telemetry';

    public function handle(OltReadOnlyAdapterManager $manager, OnuMacLedgerService $ledgerService): int
    {
        $query = NetworkInventoryDevice::where('type', 'olt');
        if ($this->option('device')) $query->whereKey((int) $this->option('device'));

        foreach ($query->get() as $olt) {
            $result = $this->readSource($olt, $manager);
            if (!$result['ok']) { $this->warn("OLT {$olt->id}: {$result['message']}"); continue; }

            $rows = is_array($result['onus'] ?? null) ? $result['onus'] : [];
            if (!$rows) { $this->info("OLT {$olt->id}: no normalized ONU rows returned; no mapping writes performed."); continue; }

            $count = 0; $skipped = 0; $autoMapped = 0; $issues = [];

            // Stable mapping identity: MAC/serial first, ONU index only as a fallback.
            // BDCOM/EPON discovery order can change between SNMP polls, so using only
            // onu_id can attach an old customer to a different physical ONU.
            $existingMappings = OltOnuCustomerMapping::where('olt_device_id', $olt->id)->get();
            // A dry-run must remain entirely read-only, including auxiliary history tables.
            if (! $this->option('dry-run')) {
                $ledgerService->bootstrapMissingMappings($existingMappings);
            }
            $seenMappingIds = [];
            $byId = $existingMappings->keyBy(fn ($m) => (string) $m->onu_id);
            $byMac = $existingMappings->filter(fn ($m) => trim((string) $m->onu_mac) !== '')
                ->groupBy(fn ($m) => $this->normalizeMac($m->onu_mac));
            $bySerial = $existingMappings->filter(fn ($m) => trim((string) $m->onu_serial) !== '')
                ->groupBy(fn ($m) => strtolower(trim((string) $m->onu_serial)));

            // Build unique read-only identifiers once so every ONU can be matched
            // without an N+1 customer query loop. Only an unambiguous exact match is applied.
            $customers = CustomersInfo::with('pppUser')->get();
            $byPppUsername = [];
            $byCallerMac = [];
            $byRemoteIp = [];
            $usedCustomerIds = [];
            $usedPppIds = [];
            foreach ($existingMappings as $mapping) {
                if ($mapping->customer_id) $usedCustomerIds[(string) $mapping->customer_id] = true;
                if ($mapping->ppp_user_id) $usedPppIds[(string) $mapping->ppp_user_id] = true;
            }
            foreach ($customers as $customer) {
                $ppp = $customer->pppUser;
                if (! $ppp) continue;
                $usernameKey = strtolower(trim((string) $ppp->username));
                if ($usernameKey !== '') $byPppUsername[$usernameKey][] = $customer;
                foreach ([$ppp->caller_id, $ppp->last_caller_id] as $mac) {
                    $macKey = $this->normalizeMac($mac);
                    if ($macKey !== '') $byCallerMac[$macKey][] = $customer;
                }
                $ipKey = trim((string) $ppp->ppp_remote_ip);
                if ($ipKey !== '') $byRemoteIp[$ipKey][] = $customer;
            }

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    $skipped++;
                    $issues[] = 'invalid ONU row was not an array';
                    continue;
                }
                $onuId = $row['onu_id'] ?? $row['onuId'] ?? $row['id'] ?? $row['snmp_index'] ?? null;
                if ($onuId === null || trim((string) $onuId) === '') { $skipped++; $issues[] = 'missing ONU id'; continue; }
                $onuId = trim((string) $onuId);

                $onuMac = $row['onu_mac'] ?? $row['mac'] ?? $row['onuMac'] ?? null;
                $onuSerial = $row['onu_serial'] ?? $row['serial'] ?? $row['onuSerial'] ?? null;
                $pppId = null; $customerId = null;

                $macKey = $this->normalizeMac($onuMac);
                $serialKey = strtolower(trim((string) $onuSerial));
                $stableMatches = $macKey !== '' ? ($byMac->get($macKey, collect())) : collect();
                if ($stableMatches->count() === 0 && $serialKey !== '') {
                    $stableMatches = $bySerial->get($serialKey, collect());
                }
                $existingMapping = $stableMatches->count() === 1 ? $stableMatches->first() : null;
                $sameIdMapping = $byId->get($onuId);
                $identityConflict = false;

                // If there is no stable match, an existing ONU ID can be reused only
                // when its stored stable identity is empty or agrees with this ONU.
                // A changed MAC/serial at the same index must not inherit the old customer.
                if (! $existingMapping && $sameIdMapping) {
                    $oldMac = $this->normalizeMac($sameIdMapping->onu_mac);
                    $oldSerial = strtolower(trim((string) $sameIdMapping->onu_serial));
                    $identityConflict = ($macKey !== '' && $oldMac !== '' && $macKey !== $oldMac)
                        || ($serialKey !== '' && $oldSerial !== '' && $serialKey !== $oldSerial);
                    if (! $identityConflict) $existingMapping = $sameIdMapping;
                }

                $allowAutoMatch = ! $identityConflict && (! $existingMapping || ! $existingMapping->customer_id);

                if ($existingMapping && $existingMapping->customer_id && ! $identityConflict) {
                    // Synchronisation refreshes telemetry only. Existing operator mappings
                    // are preserved; new customer assignments require explicit review/save.
                    $pppId = $existingMapping->ppp_user_id;
                    $customerId = $existingMapping->customer_id;
                } elseif ($identityConflict) {
                    $issues[] = "ONU {$onuId}: stable identity changed; previous customer mapping was not reused and requires review.";
                    if ($sameIdMapping) {
                        // In a real sync, clear only the stale customer assignment. A dry-run
                        // reports the conflict but never mutates the database.
                        if (! $this->option('dry-run')) {
                            $sameIdMapping->customer_id = null;
                            $sameIdMapping->ppp_user_id = null;
                            $reviewNote = 'Stable ONU identity changed; manual review required.';
                            $existingNotes = trim((string) $sameIdMapping->notes);
                            if (! str_contains($existingNotes, $reviewNote)) {
                                $sameIdMapping->notes = trim($existingNotes . ($existingNotes !== '' ? "\n" : '') . $reviewNote);
                            }
                            $sameIdMapping->save();
                        }
                        $existingMapping = $sameIdMapping;
                        $allowAutoMatch = true;
                    }
                }

                // Track the persisted mapping resolved by stable MAC/serial identity,
                // or by the existing ID after the conflict checks above.
                if ($existingMapping) {
                    $seenMappingIds[(string) $existingMapping->id] = true;
                }

                if ($allowAutoMatch && ! $customerId) {
                    // Apply only an unambiguous exact match. This covers new ONU rows,
                    // existing-unmapped rows, and rows whose old index identity changed.
                    // Priority: PPPoE username, ONU MAC == PPP caller/last-caller,
                    // then ONU IP == PPP remote IP.
                    $availableCandidates = function (array $items) use (&$usedCustomerIds, &$usedPppIds): array {
                        $items = $this->preferActiveCandidates($items);
                        return array_values(array_filter($items, static function ($customer) use (&$usedCustomerIds, &$usedPppIds): bool {
                            return !isset($usedCustomerIds[(string) $customer->id])
                                && !isset($usedPppIds[(string) $customer->ppp_user_id]);
                        }));
                    };

                    $candidates = [];
                    $onuUsername = strtolower(trim((string) ($row['pppoe_username'] ?? $row['ppp_username'] ?? '')));
                    if ($onuUsername !== '') {
                        $candidates = $availableCandidates($byPppUsername[$onuUsername] ?? []);
                    }

                    // Prefer dynamic client MACs learned behind this exact EPON
                    // interface. These are different from the ONU's own MAC and
                    // allow matching the customer's active PPP caller-ID identity.
                    if (!$candidates && is_array($row['client_macs'] ?? null)) {
                        $fdbCandidates = [];
                        foreach ($row['client_macs'] as $clientMac) {
                            $clientMacKey = $this->normalizeMac($clientMac);
                            if ($clientMacKey === '') continue;
                            foreach ($availableCandidates($byCallerMac[$clientMacKey] ?? []) as $candidate) {
                                $fdbCandidates[(string) $candidate->id] = $candidate;
                            }
                        }
                        $candidates = array_values($fdbCandidates);
                    }

                    if (!$candidates) {
                        $macKey = $this->normalizeMac($onuMac);
                        $candidates = $availableCandidates($macKey !== '' ? ($byCallerMac[$macKey] ?? []) : []);
                    }

                    if (!$candidates) {
                        $ipKey = trim((string) ($row['onu_ip'] ?? $row['ip'] ?? ''));
                        $candidates = $availableCandidates($ipKey !== '' ? ($byRemoteIp[$ipKey] ?? []) : []);
                    }

                    // Use the MAC ledger only as a last exact-identity source. If the live
                    // OLT supplies a PPPoE username, that stronger source must be resolved first.
                    if (!$candidates && $onuUsername === '') {
                        $ledgerCustomer = $ledgerService->findExactCustomerMatch(
                            (int) $olt->id,
                            $onuMac === null ? null : (string) $onuMac,
                            $onuSerial === null ? null : (string) $onuSerial,
                            $usedCustomerIds,
                            $usedPppIds,
                        );
                        if ($ledgerCustomer) {
                            $candidates = [$ledgerCustomer];
                        }
                    }

                    if (count($candidates) === 1) {
                        $customerId = $candidates[0]->id;
                        $pppId = $candidates[0]->ppp_user_id;
                        // Reserve this identity for the remainder of this pass so a
                        // duplicated MAC cannot auto-assign one account to two ONUs.
                        $usedCustomerIds[(string) $customerId] = true;
                        $usedPppIds[(string) $pppId] = true;
                        $autoMapped++;
                    } elseif (count($candidates) > 1) {
                        $issues[] = "ONU {$onuId}: multiple active customer identities were learned behind this ONU; left unmapped for review.";
                    }
                }

                $status = $this->normalizeStatus($row);
                if (!in_array($status, ['online', 'offline', 'unknown'], true)) {
                    $issues[] = "ONU {$onuId}: invalid status normalized to unknown";
                    $status = 'unknown';
                }
                $lastSeen = $row['last_seen'] ?? $row['lastSeen'] ?? null;
                if ($lastSeen !== null && $lastSeen !== '' && !strtotime((string) $lastSeen)) {
                    $issues[] = "ONU {$onuId}: invalid last_seen ignored";
                    $lastSeen = null;
                }
                // A successful live poll is itself evidence that an online ONU was seen now.
                // Preserve the previous timestamp when telemetry omits last_seen for an offline/unknown ONU.
                if (($lastSeen === null || $lastSeen === '') && $status === 'online') {
                    $lastSeen = now();
                } elseif (($lastSeen === null || $lastSeen === '') && $existingMapping?->last_seen_at) {
                    $lastSeen = $existingMapping->last_seen_at;
                }

                if ($this->option('dry-run')) {
                    $this->line("OLT {$olt->id}: ONU {$onuId} → customer=".($customerId ?? 'unmapped').", ppp=".($pppId ?? 'unmapped').", status={$status}");
                    $count++;
                    continue;
                }

                // Timestamp an OPM sample only when both optical values are present.
                // last_seen_at alone proves ONU status/identity was polled, not OPM.
                $hasFreshOpm = is_numeric($row['rx_power'] ?? $row['opticalRx'] ?? null)
                    && is_numeric($row['tx_power'] ?? $row['opticalTx'] ?? null);

                $mappingData = [
                    'customer_id' => $customerId ?? $existingMapping?->customer_id,
                    'ppp_user_id' => $pppId ?? $existingMapping?->ppp_user_id,
                    'onu_serial' => trim((string) $onuSerial) !== '' ? $onuSerial : $existingMapping?->onu_serial,
                    'onu_mac' => $this->normalizeMac($onuMac) !== '' ? $onuMac : $existingMapping?->onu_mac,
                    'pon_port' => trim((string) ($row['pon_port'] ?? $row['ponPort'] ?? $row['pon'] ?? '')) !== ''
                        ? ($row['pon_port'] ?? $row['ponPort'] ?? $row['pon'])
                        : $existingMapping?->pon_port,
                    'distance_m' => $row['distance'] ?? $row['distance_m'] ?? $existingMapping?->distance_m,
                    'temperature' => $row['temperature'] ?? $row['temp'] ?? $existingMapping?->temperature,
                    'supply_voltage' => $row['supply_voltage'] ?? $row['voltage'] ?? $existingMapping?->supply_voltage,
                    'tx_bias_current' => $row['tx_bias_current'] ?? $row['tx_bias'] ?? $row['bias_current'] ?? $existingMapping?->tx_bias_current,
                    'onu_type' => $row['onu_type'] ?? $row['type'] ?? $existingMapping?->onu_type,
                    'status' => $status,
                    'rx_power' => $row['rx_power'] ?? $row['opticalRx'] ?? $existingMapping?->rx_power,
                    'tx_power' => $row['tx_power'] ?? $row['opticalTx'] ?? $existingMapping?->tx_power,
                    'opm_source' => $row['opm_source'] ?? $existingMapping?->opm_source,
                    'opm_last_seen_at' => $hasFreshOpm ? now() : $existingMapping?->opm_last_seen_at,
                    'onu_ip' => $row['onu_ip'] ?? $row['ip'] ?? $row['onuIp'] ?? null,
                    'last_seen_at' => $lastSeen,
                    'notes' => $existingMapping ? $existingMapping->notes : ($row['detail'] ?? 'Discovered by read-only ONU synchronisation; customer mapping requires explicit review.'),
                ];

                if ($existingMapping) {
                    // Keep the stable mapping row. Update the index only when the
                    // current index is not already occupied by another mapping.
                    $idConflict = (string) $existingMapping->onu_id !== $onuId
                        && OltOnuCustomerMapping::where('olt_device_id', $olt->id)
                            ->where('onu_id', $onuId)
                            ->where('id', '<>', $existingMapping->id)
                            ->exists();
                    if (! $idConflict) $existingMapping->onu_id = $onuId;
                    $existingMapping->fill($mappingData);
                    $existingMapping->save();
                    $ledgerService->recordMapping($existingMapping);
                } else {
                    $savedMapping = OltOnuCustomerMapping::create($mappingData + [
                        'olt_device_id' => $olt->id,
                        'onu_id' => (string) $onuId,
                    ]);
                    $ledgerService->recordMapping($savedMapping);
                }
                $count++;
            }
            // Only make absence claims after a complete, healthy, explicitly read-only
            // poll. Do not infer offline status or overwrite any customer/PPPoE mapping.
            $completeReadOnlyPoll = ($result['status'] ?? null) === 'ok'
                && is_array($result['meta'] ?? null)
                && (($result['meta']['read_only'] ?? false) === true)
                && array_key_exists('writes_performed', $result['meta'])
                && ($result['meta']['writes_performed'] === false)
                && $count === count($rows)
                && $skipped === 0;

            $staleCandidates = $existingMappings->filter(
                fn ($mapping) => strtolower(trim((string) $mapping->status)) === 'online'
                    && ! isset($seenMappingIds[(string) $mapping->id])
            );

            if ($completeReadOnlyPoll) {
                foreach ($staleCandidates as $staleMapping) {
                    $this->warn("OLT {$olt->id}: ONU {$staleMapping->onu_id} / ".($staleMapping->onu_mac ?: 'MAC unknown')
                        ." is stored as online but absent from this complete read-only poll; status and mapping were left unchanged pending review.");
                }
            } elseif ($staleCandidates->isNotEmpty()) {
                $issues[] = 'Some stored online ONU rows were absent, but no absence warning was trusted because the poll was partial, invalid, or not explicitly verified as read-only.';
            }

            $this->info("OLT {$olt->id}: normalized {$count} ONU rows synced; auto-mapped {$autoMapped} unambiguous customer matches; stale online rows not observed: ".($completeReadOnlyPoll ? $staleCandidates->count() : 'not assessed')."; skipped {$skipped} invalid rows.");
            foreach (array_slice($issues, 0, 10) as $issue) $this->warn($issue);

            if (!empty($result['meta']['model_profile_mismatch'])) {
                $inventoryModel = (string) ($result['meta']['inventory_model'] ?? $olt->model ?? 'unknown');
                $liveModel = (string) ($result['meta']['snmp_sys_descr'] ?? 'unknown');
                $this->warn("OLT {$olt->id}: inventory model {$inventoryModel} differs from live sysDescr {$liveModel}; the adapter selected its live-verified read profile.");
            }
            if (($result['meta']['opm_status'] ?? null) === 'not_ready') {
                $this->warn("OLT {$olt->id}: optical TX/RX readings are unavailable in this poll; historical OPM values and timestamps were preserved.");
            }
            if (count($issues) > 10) $this->warn('Additional mapping validation issues: '.(count($issues)-10));
        }
        return self::SUCCESS;
    }

    private function readSource(NetworkInventoryDevice $olt, OltReadOnlyAdapterManager $manager): array
    {
        try {
            return $manager->read($olt);
        } catch (\Throwable) {
            return $this->readLegacyTelemetry($olt);
        }
    }

    private function readLegacyTelemetry(NetworkInventoryDevice $olt): array
    {
        $url = trim((string) env('OLT_TELEMETRY_URL', ''));
        if ($url === '') return ['ok' => false, 'status' => 'not_configured', 'message' => 'No read-only OLT adapter or OLT_TELEMETRY_URL is configured.', 'onus' => []];

        $endpoint = rtrim($url, '/') . '/onu/status?oltId=' . urlencode((string) $olt->id);
        try {
            $request = Http::timeout(5)->acceptJson();
            $token = trim((string) env('OLT_TELEMETRY_TOKEN', ''));
            if ($token !== '') $request = $request->withToken($token);
            $response = $request->get($endpoint);
            if (!$response->successful()) return ['ok' => false, 'status' => 'unreachable', 'message' => "Telemetry HTTP {$response->status()}.", 'onus' => []];

            $rows = $response->json();
            if (isset($rows['onus'])) $rows = $rows['onus'];
            return ['ok' => is_array($rows), 'status' => is_array($rows) ? 'ok' : 'invalid_response', 'message' => is_array($rows) ? 'Legacy telemetry read completed.' : 'Telemetry response was not an ONU array.', 'onus' => is_array($rows) ? $rows : []];
        } catch (\Throwable) {
            return ['ok' => false, 'status' => 'unreachable', 'message' => 'Legacy OLT telemetry is unavailable; no write performed.', 'onus' => []];
        }
    }

    /**
     * When a stale/removed PPP record and one live account share a caller ID,
     * prefer the single active customer. Multiple active candidates remain
     * ambiguous and are never auto-assigned.
     */
    private function preferActiveCandidates(array $candidates): array
    {
        // Caller ID and last-caller ID may contain the same MAC for one PPP user;
        // deduplicate by actual Customer ID before deciding uniqueness.
        $unique = [];
        foreach ($candidates as $customer) {
            if ($customer && $customer->id !== null) {
                $unique[(string) $customer->id] = $customer;
            }
        }
        $candidates = array_values($unique);
        if (count($candidates) <= 1) return $candidates;

        $active = array_values(array_filter($candidates, static function ($customer): bool {
            return strtolower(trim((string) ($customer->pppUser?->status ?? ''))) === 'active';
        }));

        return count($active) > 0 ? $active : $candidates;
    }

    private function normalizeMac($mac): string
    {
        return strtolower(preg_replace('/[^a-fA-F0-9]/', '', (string) $mac) ?? '');
    }

    private function normalizeStatus(array $row): string
    {
        if (array_key_exists('online', $row)) return ($row['online'] === true || $row['online'] === 1 || $row['online'] === '1') ? 'online' : 'offline';
        $status = strtolower(trim((string) ($row['status'] ?? 'unknown')));
        if (in_array($status, ['1', 'online', 'up', 'active', 'registered'], true)) return 'online';
        if (in_array($status, ['2', '4', 'offline', 'down', 'inactive', 'unregistered', 'deregistered', 'lost'], true)) return 'offline';
        if (in_array($status, ['0', '3', '5', 'authenticated', 'auto_config', 'standby', 'unknown'], true)) return 'unknown';
        return $status !== '' ? $status : 'unknown';
    }
}
