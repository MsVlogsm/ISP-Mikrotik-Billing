<?php

namespace App\Console\Commands;

use App\Models\NetworkInventoryDevice;
use App\Models\OltOnuCustomerMapping;
use App\Services\Olt\OltReadOnlyAdapterManager;
use App\Services\Olt\OnuMappingIdentityReconciler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ReconcileOnuMappingIdentities extends Command
{
    protected $signature = 'olt:reconcile-onu-identities {--device= : OLT device ID} {--apply : Apply only after a verified backup; default is dry-run}';
    protected $description = 'Reconcile stored ONU indexes to live MAC identities while preserving customer/PPPoE links';

    public function handle(OltReadOnlyAdapterManager $manager, OnuMappingIdentityReconciler $reconciler): int
    {
        $query = NetworkInventoryDevice::where('type', 'olt')->whereRaw('UPPER(vendor) = ?', ['VSOL']);
        if ($this->option('device')) $query->whereKey((int) $this->option('device'));
        $devices = $query->get();
        if ($devices->count() !== 1) {
            $this->error('Specify one VSOL OLT with --device=<id>; no changes performed.');
            return self::FAILURE;
        }
        $olt = $devices->first();

        try {
            $result = $manager->read($olt);
            if (empty($result['ok']) || empty($result['meta']['read_only']) || !empty($result['meta']['writes_performed'])) {
                $this->error('Read-only SNMP discovery did not return safe data: '.($result['message'] ?? 'unknown error'));
                return self::FAILURE;
            }
            if (($result['meta']['mib_profile'] ?? null) !== 'v1600d4_auth_info_table') {
                $this->error('Expected live-verified V1600D4 legacy MIB profile; selected '.($result['meta']['mib_profile'] ?? 'unknown').'.');
                return self::FAILURE;
            }
            $mappings = OltOnuCustomerMapping::where('olt_device_id', $olt->id)->orderBy('id')->get();
            $snapshotHash = $this->snapshotHash($mappings);
            $plan = $reconciler->plan((array) ($result['onus'] ?? []), $mappings);
        } catch (Throwable $e) {
            $this->error('Identity validation failed safely: '.$e->getMessage());
            return self::FAILURE;
        }

        $this->info("OLT #{$olt->id} {$olt->name}: live ONU rows=".count($result['onus'] ?? []).', stored mapping rows='.$mappings->count());
        $this->line('Stable MAC matches: '.count($plan['matched']).'; ID/metadata corrections: '.count(array_filter($plan['matched'], fn ($r) => $r['changed'])).'; stale rows retained: '.count($plan['stale']).'; new live rows: '.count($plan['new']));
        foreach ($plan['matched'] as $r) if ($r['changed']) $this->line("  mapping row {$r['mapping_id']}: ONU {$r['current_onu_id']} -> {$r['target_onu_id']} via stable MAC {$r['onu_mac']}");
        foreach ($plan['stale'] as $r) $this->line("  stale row {$r['mapping_id']}: current ONU {$r['current_onu_id']}; linked=".($r['linked'] ? 'yes' : 'no').'; retained for audit');
        foreach ($plan['new'] as $r) $this->line("  new ONU {$r['onu_id']} / PON {$r['pon_port']} / {$r['onu_mac']} -> unmapped pending exact match");

        if ($plan['blockers']) {
            foreach ($plan['blockers'] as $blocker) $this->error($blocker);
            $this->error('A stale row has a customer/PPPoE link. No changes were applied.');
            return self::FAILURE;
        }
        if (!$this->option('apply')) {
            $this->comment('DRY RUN ONLY; no database writes. Use --apply after reviewing the planned MAC-to-ONU-ID rekeys.');
            return self::SUCCESS;
        }

        $timestamp = now()->format('Ymd-His');
        $backupDir = storage_path('app/olt-mapping-backups');
        if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
            $this->error('Cannot create backup directory; no changes performed.');
            return self::FAILURE;
        }
        $backupPath = $backupDir.'/olt-'.$olt->id.'-before-identity-reconcile-'.$timestamp.'.json';
        $payload = [
            'created_at' => now()->toIso8601String(),
            'olt' => ['id' => $olt->id, 'name' => $olt->name, 'vendor' => $olt->vendor, 'model' => $olt->model],
            'mapping_rows' => $mappings->toArray(),
            'live_onus' => (array) ($result['onus'] ?? []),
        ];
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || file_put_contents($backupPath, $encoded, LOCK_EX) === false) {
            $this->error('Backup could not be written; no mapping changes performed.');
            return self::FAILURE;
        }
        @chmod($backupPath, 0600);
        $checksum = hash_file('sha256', $backupPath);
        if (!is_string($checksum) || !hash_equals($checksum, hash('sha256', $encoded))) {
            $this->error('Backup checksum verification failed; no mapping changes performed.');
            return self::FAILURE;
        }
        if (file_put_contents($backupPath.'.sha256', $checksum.'  '.basename($backupPath).PHP_EOL, LOCK_EX) === false) {
            $this->error('Backup checksum manifest could not be written; no mapping changes performed.');
            return self::FAILURE;
        }
        @chmod($backupPath.'.sha256', 0600);

        try {
            DB::transaction(function () use ($olt, $snapshotHash, $plan): void {
                $current = OltOnuCustomerMapping::where('olt_device_id', $olt->id)->orderBy('id')->get();
                if (!hash_equals($snapshotHash, $this->snapshotHash($current))) {
                    throw new RuntimeException('Mapping rows changed after planning; refusing to apply stale plan.');
                }
                $byId = $current->keyBy('id');
                foreach ($current as $mapping) {
                    $mapping->onu_id = '__tmp_'.$mapping->id;
                    $mapping->save();
                }
                foreach ($plan['matched'] as $r) {
                    $mapping = $byId->get($r['mapping_id']);
                    if (!$mapping) throw new RuntimeException('Planned mapping row disappeared.');
                    $mapping->onu_id = $r['target_onu_id'];
                    $mapping->onu_mac = $r['onu_mac'];
                    $mapping->pon_port = $r['pon_port'];
                    $mapping->status = $r['status'];
                    if ($r['status'] === 'online') $mapping->last_seen_at = now();
                    $mapping->save();
                }
                foreach ($plan['stale'] as $r) {
                    $mapping = $byId->get($r['mapping_id']);
                    if (!$mapping) throw new RuntimeException('Stale mapping row disappeared.');
                    $mapping->onu_id = '__stale_'.$mapping->id;
                    $note = 'Retained as stale identity by read-only VSOL reconciliation on '.now()->toDateTimeString().'; MAC absent from current live table.';
                    $old = trim((string) $mapping->notes);
                    $mapping->notes = trim($old.($old !== '' ? "\n" : '').$note);
                    $mapping->save();
                }
                foreach ($plan['new'] as $r) {
                    OltOnuCustomerMapping::create([
                        'olt_device_id' => $olt->id, 'customer_id' => null, 'ppp_user_id' => null,
                        'onu_id' => $r['onu_id'], 'onu_mac' => $r['onu_mac'], 'pon_port' => $r['pon_port'],
                        'status' => $r['status'], 'last_seen_at' => $r['status'] === 'online' ? now() : null,
                        'notes' => 'Discovered from live-verified VSOL read-only SNMP; Customer ↔ ONU assignment awaits exact identity evidence.',
                    ]);
                }
            });
        } catch (Throwable $e) {
            $this->error('Database reconciliation rolled back: '.$e->getMessage());
            $this->line('Backup: '.$backupPath);
            return self::FAILURE;
        }
        $this->info('Identity reconciliation applied. Existing customer/PPPoE links were preserved; unmatched rows remain as audit records.');
        $this->line('Verified backup: '.$backupPath);
        $this->line('Next: php artisan olt:sync-onu-mappings --device='.$olt->id.' --dry-run');
        return self::SUCCESS;
    }

    private function snapshotHash($mappings): string
    {
        $snapshot = $mappings->map(static fn ($m) => [(int) $m->id, (string) $m->onu_id, (string) $m->onu_mac, (string) $m->customer_id, (string) $m->ppp_user_id])->values()->all();
        return hash('sha256', json_encode($snapshot, JSON_UNESCAPED_SLASHES) ?: '[]');
    }
}
