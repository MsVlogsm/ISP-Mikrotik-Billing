<?php

namespace App\Console\Commands;

use App\Models\CustomersInfo;
use App\Models\NetworkInventoryDevice;
use App\Models\OltOnuCustomerMapping;
use App\Models\OltMonitoringSnapshot;
use App\Models\PPPSecrets;
use App\Services\Olt\OltReadOnlyAdapterManager;
use App\Services\OnuMacLedgerService;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
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
            $duplicateMacRepair = $this->autoFixDuplicateMacMappings(
                $olt,
                $rows,
                $existingMappings,
                $ledgerService,
                $result,
                (bool) $this->option('dry-run'),
            );
            $issues = array_merge($issues, $duplicateMacRepair['issues']);
            $skipLiveRows = $duplicateMacRepair['skip_live_rows'];
            if ($duplicateMacRepair['refreshed']) {
                $existingMappings = OltOnuCustomerMapping::where('olt_device_id', $olt->id)->get();
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
                $livePon = trim((string) ($row['pon_port'] ?? $row['ponPort'] ?? $row['pon'] ?? ''));
                if ($livePon !== '' && isset($skipLiveRows[$livePon.'|'.$onuId])) {
                    $skipped++;
                    continue;
                }

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

                $targetPon = trim((string) ($mappingData['pon_port'] ?? ''));
                $occupiedMapping = $targetPon !== ''
                    ? OltOnuCustomerMapping::where('olt_device_id', $olt->id)
                        ->where('pon_port', $targetPon)
                        ->where('onu_id', $onuId)
                        ->when($existingMapping, fn ($query) => $query->where('id', '<>', $existingMapping->id))
                        ->first(['id'])
                    : null;

                if ($occupiedMapping) {
                    $skipped++;
                    $issues[] = "ONU {$onuId}: live PON {$targetPon}/ONU {$onuId} is already occupied by mapping #{$occupiedMapping->id}; existing mapping #".($existingMapping?->id ?? 'unresolved')." was left unchanged for manual review.";
                    continue;
                }

                $mappingToRecord = $existingMapping;
                try {
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
                    } else {
                        $mappingToRecord = OltOnuCustomerMapping::create($mappingData + [
                            'olt_device_id' => $olt->id,
                            'onu_id' => (string) $onuId,
                        ]);
                    }
                } catch (UniqueConstraintViolationException) {
                    $existingMapping?->refresh();
                    $conflictingMapping = $targetPon !== ''
                        ? OltOnuCustomerMapping::where('olt_device_id', $olt->id)
                            ->where('pon_port', $targetPon)
                            ->where('onu_id', $onuId)
                            ->when($existingMapping, fn ($query) => $query->where('id', '<>', $existingMapping->id))
                            ->first(['id'])
                        : null;
                    $conflictLabel = $conflictingMapping ? " with mapping #{$conflictingMapping->id}" : '';
                    $issues[] = "ONU {$onuId}: duplicate OLT/PON/ONU index conflict{$conflictLabel}; existing mapping #".($existingMapping?->id ?? 'unresolved')." was preserved for manual review.";
                    $skipped++;
                    continue;
                }

                $ledgerService->recordMapping($mappingToRecord);
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

            // Keep OLT-level live counters in sync only after a complete, verified,
            // read-only poll. Capacity remains a separate PON-port property.
            if ($completeReadOnlyPoll && ! $this->option('dry-run')) {
                $liveOnuIds = collect($rows)
                    ->map(fn (array $row) => trim((string) ($row['onu_id'] ?? $row['onuId'] ?? $row['id'] ?? $row['snmp_index'] ?? '')))
                    ->filter(fn (string $id) => $id !== '')
                    ->unique()
                    ->values();
                $liveTotal = $liveOnuIds->count();
                $liveOnline = collect($rows)
                    ->filter(fn (array $row) => $this->normalizeStatus($row) === 'online')
                    ->count();
                $liveMappings = OltOnuCustomerMapping::where('olt_device_id', $olt->id)
                    ->whereIn('onu_id', $liveOnuIds->all())
                    ->get(['id', 'customer_id']);
                $mappedOnuCount = $liveMappings->whereNotNull('customer_id')->count();
                $mappedCustomerCount = $liveMappings->whereNotNull('customer_id')->pluck('customer_id')->unique()->count();

                $onlineCount = collect($rows)->filter(fn (array $row) => $this->normalizeStatus($row) === 'online')->count();
                $offlineCount = collect($rows)->filter(fn (array $row) => $this->normalizeStatus($row) === 'offline')->count();
                $unknownCount = max(0, $liveTotal - $onlineCount - $offlineCount);
                $rxReadings = collect($rows)
                    ->pluck('rx_power')
                    ->filter(fn ($value) => is_numeric($value))
                    ->map(fn ($value) => (float) $value)
                    ->values();
                $goodMinimum = (float) config('olt_dashboard.signal_good_min_dbm', -20);
                $warningMinimum = (float) config('olt_dashboard.signal_warning_min_dbm', -27);
                $signalGood = $rxReadings->filter(fn (float $value) => $value >= $goodMinimum)->count();
                $signalWarning = $rxReadings->filter(fn (float $value) => $value < $goodMinimum && $value >= $warningMinimum)->count();
                $signalCritical = $rxReadings->filter(fn (float $value) => $value < $warningMinimum)->count();

                $olt->update([
                    'onu_total' => $liveTotal,
                    'onu_online' => $onlineCount,
                    'customer_count' => $mappedCustomerCount,
                ]);

                $snapshotInterval = max(1, (int) config('olt_dashboard.snapshot_interval_minutes', 5));
                $snapshot = OltMonitoringSnapshot::where('olt_device_id', $olt->id)
                    ->latest('sampled_at')
                    ->first();

                // Keep the first sample timestamp within a window. Moving it on
                // every poll would create a sliding window and suppress new trend
                // points forever when the scheduler runs more frequently than 5 min.
                if (! $snapshot || $snapshot->sampled_at->lt(now()->subMinutes($snapshotInterval))) {
                    $snapshot = new OltMonitoringSnapshot([
                        'olt_device_id' => $olt->id,
                        'sampled_at' => now(),
                    ]);
                }

                $snapshot->fill([
                    'onu_total' => $liveTotal,
                    'onu_online' => $onlineCount,
                    'onu_offline' => $offlineCount,
                    'onu_unknown' => $unknownCount,
                    'mapped_onus' => $mappedOnuCount,
                    'mapped_customers' => $mappedCustomerCount,
                    'rx_sample_count' => $rxReadings->count(),
                    'signal_good' => $signalGood,
                    'signal_warning' => $signalWarning,
                    'signal_critical' => $signalCritical,
                    'signal_no_sample' => max(0, $liveTotal - $rxReadings->count()),
                    'avg_rx_power' => $rxReadings->isNotEmpty() ? round($rxReadings->avg(), 2) : null,
                    'min_rx_power' => $rxReadings->isNotEmpty() ? round($rxReadings->min(), 2) : null,
                    'avg_latency_ms' => is_numeric($olt->last_latency_ms) ? (int) $olt->last_latency_ms : null,
                ])->save();

                $this->line("OLT {$olt->id}: live counters reconciled — {$onlineCount}/{$liveTotal} online; {$mappedCustomerCount} mapped customers; RX samples {$rxReadings->count()}.");
            }

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

            $this->info("OLT {$olt->id}: normalized {$count} ONU rows synced; auto-mapped {$autoMapped} unambiguous customer matches; stale online rows not observed: ".($completeReadOnlyPoll ? $staleCandidates->count() : 'not assessed')."; skipped {$skipped} rows.");
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

        $retentionDays = max(7, (int) config('olt_dashboard.snapshot_retention_days', 90));
        OltMonitoringSnapshot::where('sampled_at', '<', now()->subDays($retentionDays))->delete();

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
     * Reconcile duplicate ONU MAC rows only when one live online location and its
     * offline historical location are both explicitly present in a verified read-only poll.
     * Customer/PPPoE links move only when the existing MAC ledger confirms the exact pair.
     */
    private function autoFixDuplicateMacMappings(
        NetworkInventoryDevice $olt,
        array $liveRows,
        $existingMappings,
        OnuMacLedgerService $ledgerService,
        array $poll,
        bool $dryRun,
    ): array {
        $result = ['skip_live_rows' => [], 'issues' => [], 'refreshed' => false];
        $meta = is_array($poll['meta'] ?? null) ? $poll['meta'] : [];

        if (empty($poll['ok'])
            || ($meta['read_only'] ?? false) !== true
            || ! array_key_exists('writes_performed', $meta)
            || $meta['writes_performed'] !== false
            || ($meta['onu_discovery_configured'] ?? false) !== true
            || ! is_numeric($meta['onu_count'] ?? null)
            || (int) $meta['onu_count'] !== count($liveRows)) {
            return $result;
        }

        $liveByMac = [];
        foreach ($liveRows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $mac = $this->normalizeMac($row['onu_mac'] ?? $row['mac'] ?? $row['onuMac'] ?? null);
            $pon = trim((string) ($row['pon_port'] ?? $row['ponPort'] ?? $row['pon'] ?? ''));
            $onuId = trim((string) ($row['onu_id'] ?? $row['onuId'] ?? $row['id'] ?? $row['snmp_index'] ?? ''));
            if ($mac === '' || $pon === '' || $onuId === '') {
                continue;
            }

            $liveByMac[$mac][] = [
                'pon_port' => $pon,
                'onu_id' => $onuId,
                'status' => $this->normalizeStatus($row),
                'row' => $row,
            ];
        }

        foreach ($liveByMac as $mac => $liveGroup) {
            if (count($liveGroup) < 2) {
                continue;
            }

            $displayMac = implode(':', str_split($mac, 2));
            $onlineRows = array_values(array_filter($liveGroup, static fn ($row) => $row['status'] === 'online'));
            $offlineRows = array_values(array_filter($liveGroup, static fn ($row) => $row['status'] === 'offline'));

            $skipAllLiveRows = function () use (&$result, $liveGroup): void {
                foreach ($liveGroup as $live) {
                    $result['skip_live_rows'][$live['pon_port'].'|'.$live['onu_id']] = true;
                }
            };

            if (count($onlineRows) !== 1 || count($offlineRows) !== count($liveGroup) - 1) {
                $skipAllLiveRows();
                $result['issues'][] = "Duplicate live ONU MAC {$displayMac}: no single online location was verified; all live copies were skipped for review.";
                continue;
            }

            $online = $onlineRows[0];
            foreach ($offlineRows as $offline) {
                $result['skip_live_rows'][$offline['pon_port'].'|'.$offline['onu_id']] = true;
            }

            $storedGroup = $existingMappings
                ->filter(fn ($mapping) => $this->normalizeMac($mapping->onu_mac) === $mac)
                ->values();

            if ($storedGroup->count() < 2) {
                continue;
            }

            $canonicalMatches = $storedGroup->filter(
                fn ($mapping) => trim((string) $mapping->pon_port) === $online['pon_port']
                    && trim((string) $mapping->onu_id) === $online['onu_id']
            )->values();

            $blocker = null;
            if ($canonicalMatches->count() !== 1) {
                $blocker = 'there is not exactly one stored mapping at the unique live online location';
            }

            $canonical = $canonicalMatches->first();
            $staleRows = $canonical
                ? $storedGroup->reject(fn ($mapping) => (int) $mapping->id === (int) $canonical->id)->values()
                : collect();

            $offlineLocations = [];
            foreach ($offlineRows as $offline) {
                $offlineLocations[$offline['pon_port'].'|'.$offline['onu_id']] = true;
            }

            if ($blocker === null && $staleRows->count() !== count($offlineRows)) {
                $blocker = 'stored duplicate rows do not match every offline live location';
            }

            if ($blocker === null) {
                foreach ($staleRows as $stale) {
                    $location = trim((string) $stale->pon_port).'|'.trim((string) $stale->onu_id);
                    if (! isset($offlineLocations[$location]) || strtolower(trim((string) $stale->status)) !== 'offline') {
                        $blocker = "mapping #{$stale->id} is not a confirmed offline historical copy";
                        break;
                    }
                }
            }

            $staleIds = $staleRows->pluck('id')->all();
            if ($blocker === null && DB::table('olt_onu_customer_ppp_links')
                ->whereIn('olt_onu_customer_mapping_id', $staleIds)
                ->exists()) {
                $blocker = 'a stale duplicate row still has PPP links attached';
            }

            $assignedPairs = [];
            if ($blocker === null) {
                foreach ($storedGroup as $mapping) {
                    $customerId = (int) ($mapping->customer_id ?? 0);
                    $pppUserId = (int) ($mapping->ppp_user_id ?? 0);
                    if (($customerId === 0) !== ($pppUserId === 0)) {
                        $blocker = "mapping #{$mapping->id} has an incomplete customer/PPPoE pair";
                        break;
                    }
                    if ($customerId > 0) {
                        $assignedPairs[$customerId.'|'.$pppUserId] = [
                            'customer_id' => $customerId,
                            'ppp_user_id' => $pppUserId,
                        ];
                    }
                }
            }

            if ($blocker === null && count($assignedPairs) > 1) {
                $blocker = 'duplicate rows contain conflicting customer/PPPoE assignments';
            }

            $pairToPreserve = count($assignedPairs) === 1 ? array_values($assignedPairs)[0] : null;
            if ($blocker === null && $pairToPreserve !== null) {
                $ledgerCustomer = $ledgerService->findExactCustomerMatch(
                    (int) $olt->id,
                    $displayMac,
                    null,
                    [],
                    [],
                );

                if (! $ledgerCustomer
                    || (int) $ledgerCustomer->id !== $pairToPreserve['customer_id']
                    || (int) $ledgerCustomer->ppp_user_id !== $pairToPreserve['ppp_user_id']) {
                    $blocker = 'the existing ONU MAC ledger does not verify the exact customer/PPPoE pair';
                }
            }

            if ($blocker !== null) {
                $skipAllLiveRows();
                $result['issues'][] = "Duplicate ONU MAC {$displayMac}: automatic repair withheld — {$blocker}; all live copies were skipped for review.";
                continue;
            }

            $mappingIds = $storedGroup->pluck('id')->all();
            $canonicalId = (int) $canonical->id;
            $staleLabels = $staleRows->pluck('id')->map(fn ($id) => '#'.$id)->implode(', ');
            $noteSuffix = $pairToPreserve !== null
                ? " Verified customer {$pairToPreserve['customer_id']} / PPPoE {$pairToPreserve['ppp_user_id']} was preserved on mapping #{$canonicalId}."
                : '';

            if ($dryRun) {
                if ($pairToPreserve !== null) {
                    $canonical->customer_id = $pairToPreserve['customer_id'];
                    $canonical->ppp_user_id = $pairToPreserve['ppp_user_id'];
                }
                foreach ($staleRows as $stale) {
                    $stale->onu_id = '__stale_'.$stale->id;
                    $stale->onu_mac = null;
                    $stale->customer_id = null;
                    $stale->ppp_user_id = null;
                }
                $result['issues'][] = "DRY-RUN: duplicate ONU MAC {$displayMac}; would retain mapping #{$canonicalId} at PON {$online['pon_port']}/ONU {$online['onu_id']} and archive {$staleLabels}.{$noteSuffix}";
                continue;
            }

            try {
                $backupPath = $this->backupDuplicateMacMappings($olt, $mac, $storedGroup, $liveGroup);
            } catch (\Throwable) {
                $backupPath = null;
            }
            if (! is_string($backupPath) || $backupPath === '') {
                $skipAllLiveRows();
                $result['issues'][] = "Duplicate ONU MAC {$displayMac}: automatic repair withheld because a verified backup could not be created.";
                continue;
            }

            try {
                DB::transaction(function () use (
                    $olt,
                    $mac,
                    $storedGroup,
                    $canonicalId,
                    $staleRows,
                    $pairToPreserve,
                    $online,
                    $displayMac,
                    $backupPath,
                ): void {
                    $ids = $storedGroup->pluck('id')->all();
                    $currentRows = OltOnuCustomerMapping::where('olt_device_id', $olt->id)
                        ->whereIn('id', $ids)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('id');

                    if ($currentRows->count() !== count($ids)) {
                        throw new \RuntimeException('Duplicate ONU mappings changed after the backup was created.');
                    }

                    foreach ($storedGroup as $expected) {
                        $current = $currentRows->get($expected->id);
                        if (! $current
                            || $this->normalizeMac($current->onu_mac) !== $mac
                            || (string) $current->pon_port !== (string) $expected->pon_port
                            || (string) $current->onu_id !== (string) $expected->onu_id
                            || (int) ($current->customer_id ?? 0) !== (int) ($expected->customer_id ?? 0)
                            || (int) ($current->ppp_user_id ?? 0) !== (int) ($expected->ppp_user_id ?? 0)
                            || strtolower(trim((string) $current->status)) !== strtolower(trim((string) $expected->status))) {
                            throw new \RuntimeException("Duplicate ONU mapping #{$expected->id} changed after planning.");
                        }
                    }

                    $canonicalCurrent = $currentRows->get($canonicalId);
                    if ($pairToPreserve !== null) {
                        $currentCustomerId = (int) ($canonicalCurrent->customer_id ?? 0);
                        $currentPppUserId = (int) ($canonicalCurrent->ppp_user_id ?? 0);
                        if (($currentCustomerId !== 0 || $currentPppUserId !== 0)
                            && ($currentCustomerId !== $pairToPreserve['customer_id']
                                || $currentPppUserId !== $pairToPreserve['ppp_user_id'])) {
                            throw new \RuntimeException("Canonical ONU mapping #{$canonicalId} has a conflicting customer/PPPoE assignment.");
                        }
                        $canonicalCurrent->customer_id = $pairToPreserve['customer_id'];
                        $canonicalCurrent->ppp_user_id = $pairToPreserve['ppp_user_id'];
                    }

                    $appendNote = static function ($mapping, string $note): void {
                        $existing = trim((string) $mapping->notes);
                        $mapping->notes = trim($existing.($existing !== '' ? "\n" : '').$note);
                    };

                    $appendNote(
                        $canonicalCurrent,
                        "Automatically retained as the unique live online identity for ONU MAC {$displayMac} at PON {$online['pon_port']}/ONU {$online['onu_id']} on ".now()->toDateTimeString()."; backup: {$backupPath}."
                    );
                    $canonicalCurrent->status = 'online';
                    $canonicalCurrent->save();

                    foreach ($staleRows as $staleExpected) {
                        $stale = $currentRows->get($staleExpected->id);
                        $oldPon = (string) $stale->pon_port;
                        $oldOnuId = (string) $stale->onu_id;
                        $appendNote(
                            $stale,
                            "Archived as an offline duplicate ONU MAC identity on ".now()->toDateTimeString().": MAC {$displayMac}, old PON {$oldPon}/ONU {$oldOnuId}; live identity retained on mapping #{$canonicalId} at PON {$online['pon_port']}/ONU {$online['onu_id']}.{$noteSuffix} Backup: {$backupPath}."
                        );
                        $stale->onu_id = '__stale_'.$stale->id;
                        $stale->onu_mac = null;
                        $stale->customer_id = null;
                        $stale->ppp_user_id = null;
                        $stale->save();
                    }
                }, 3);
            } catch (\Throwable $e) {
                $skipAllLiveRows();
                $result['issues'][] = "Duplicate ONU MAC {$displayMac}: automatic repair rolled back safely — {$e->getMessage()}";
                continue;
            }

            $result['refreshed'] = true;
            $result['issues'][] = "Auto-fixed duplicate ONU MAC {$displayMac}: retained mapping #{$canonicalId} at PON {$online['pon_port']}/ONU {$online['onu_id']}; archived {$staleLabels}; verified backup {$backupPath}.";
        }

        return $result;
    }

    /**
     * Back up the exact duplicate mapping group and its PPP/ONU ledger references
     * before the automated transaction is allowed to change any mapping identity.
     */
    private function backupDuplicateMacMappings(
        NetworkInventoryDevice $olt,
        string $mac,
        $mappings,
        array $liveGroup,
    ): ?string {
        $directory = storage_path('app/olt-mapping-backups');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            return null;
        }

        $ids = $mappings->pluck('id')->all();
        $ledgerRows = DB::table('onu_mac_ledgers')
            ->where('olt_device_id', $olt->id)
            ->get()
            ->filter(fn ($row) => $this->normalizeMac($row->current_mac) === $mac)
            ->values()
            ->all();

        $payload = [
            'created_at' => now()->toIso8601String(),
            'reason' => 'Automatic stale duplicate ONU MAC reconciliation',
            'olt' => ['id' => $olt->id, 'name' => $olt->name, 'vendor' => $olt->vendor, 'model' => $olt->model],
            'onu_mac' => implode(':', str_split($mac, 2)),
            'mapping_rows' => $mappings->toArray(),
            'ppp_links' => DB::table('olt_onu_customer_ppp_links')
                ->whereIn('olt_onu_customer_mapping_id', $ids)
                ->get()
                ->toArray(),
            'onu_mac_ledgers' => $ledgerRows,
            'live_rows' => array_map(static fn ($row) => $row['row'], $liveGroup),
        ];

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded)) {
            return null;
        }

        $path = $directory.'/olt-'.$olt->id.'-duplicate-mac-'.substr(hash('sha256', $mac), 0, 10).'-'.now()->format('Ymd-His').'-'.substr(hash('sha256', $encoded), 0, 8).'.json';
        if (file_put_contents($path, $encoded, LOCK_EX) === false) {
            return null;
        }
        @chmod($path, 0600);

        $checksum = hash_file('sha256', $path);
        if (! is_string($checksum) || ! hash_equals($checksum, hash('sha256', $encoded))) {
            @unlink($path);
            return null;
        }

        $manifest = $path.'.sha256';
        if (file_put_contents($manifest, $checksum.'  '.basename($path).PHP_EOL, LOCK_EX) === false) {
            @unlink($path);
            return null;
        }
        @chmod($manifest, 0600);

        return $path;
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
