<?php

namespace App\Services\Olt;

use InvalidArgumentException;

class OnuMappingIdentityReconciler
{
    public function plan(array $liveOnus, iterable $mappings): array
    {
        $liveByMac = [];
        $liveById = [];
        foreach ($liveOnus as $row) {
            $onuId = trim((string) ($row['onu_id'] ?? ''));
            $mac = self::normalizeMac($row['onu_mac'] ?? null);
            $pon = trim((string) ($row['pon_port'] ?? ''));
            if ($onuId === '' || $mac === null || $pon === '') {
                throw new InvalidArgumentException('Live ONU data is missing a valid ONU ID, MAC, or PON port.');
            }
            if (isset($liveByMac[$mac])) throw new InvalidArgumentException("Live SNMP returned duplicate ONU MAC {$mac}.");
            if (isset($liveById[$onuId])) throw new InvalidArgumentException("Live SNMP returned duplicate ONU ID {$onuId}.");
            $row['onu_id'] = $onuId;
            $row['pon_port'] = $pon;
            $row['onu_mac'] = $mac;
            $status = strtolower(trim((string) ($row['status'] ?? 'unknown')));
            $row['status'] = in_array($status, ['online', 'offline'], true) ? $status : 'unknown';
            $liveByMac[$mac] = $row;
            $liveById[$onuId] = true;
        }
        if (!$liveByMac) throw new InvalidArgumentException('Live SNMP returned no valid ONU identities.');

        $mappingByMac = [];
        $stale = [];
        $blockers = [];
        foreach ($mappings as $mapping) {
            $data = is_array($mapping) ? $mapping : $mapping->getAttributes();
            $mac = self::normalizeMac($data['onu_mac'] ?? null);
            $rowId = (int) ($data['id'] ?? 0);
            if ($rowId <= 0) throw new InvalidArgumentException('Stored ONU mapping has no primary key.');
            if ($mac !== null && isset($liveByMac[$mac])) {
                if (isset($mappingByMac[$mac])) throw new InvalidArgumentException("Stored ONU MAC {$mac} occurs in multiple mapping rows.");
                $mappingByMac[$mac] = $data;
                continue;
            }
            $linked = !empty($data['customer_id']) || !empty($data['ppp_user_id']);
            $stale[] = ['mapping_id' => $rowId, 'current_onu_id' => (string) ($data['onu_id'] ?? ''), 'onu_mac' => $mac, 'linked' => $linked];
            // Never archive/rekey a stale identity automatically while a customer
            // or PPPoE account is attached; manual identity review is required.
            if ($linked) {
                $blockers[] = "Stale mapping row {$rowId} has a customer/PPPoE link; verify its live identity before applying reconciliation.";
            }
        }

        $matched = [];
        $new = [];
        foreach ($liveByMac as $mac => $live) {
            if (isset($mappingByMac[$mac])) {
                $stored = $mappingByMac[$mac];
                $currentMac = self::normalizeMac($stored['onu_mac'] ?? null);
                $currentId = (string) ($stored['onu_id'] ?? '');
                $currentPon = trim((string) ($stored['pon_port'] ?? ''));
                $currentStatus = strtolower(trim((string) ($stored['status'] ?? '')));
                $matched[] = [
                    'mapping_id' => (int) $stored['id'],
                    'current_onu_id' => $currentId,
                    'target_onu_id' => $live['onu_id'],
                    'onu_mac' => $mac,
                    'pon_port' => $live['pon_port'],
                    'status' => $live['status'],
                    'changed' => $currentId !== $live['onu_id'] || $currentMac !== $mac || $currentPon !== $live['pon_port'] || $currentStatus !== $live['status'],
                ];
            } else {
                $new[] = ['onu_id' => $live['onu_id'], 'onu_mac' => $mac, 'pon_port' => $live['pon_port'], 'status' => $live['status']];
            }
        }
        $targetIds = [];
        foreach (array_merge(
            array_map(static fn ($r) => (string) $r['target_onu_id'], $matched),
            array_map(static fn ($r) => (string) $r['onu_id'], $new)
        ) as $id) {
            if (isset($targetIds[$id])) throw new InvalidArgumentException("Duplicate live target ONU ID {$id}.");
            $targetIds[$id] = true;
        }
        return ['matched' => $matched, 'stale' => $stale, 'new' => $new, 'blockers' => $blockers];
    }

    public static function normalizeMac(mixed $value): ?string
    {
        if (!is_string($value) && !is_numeric($value)) return null;
        $hex = preg_replace('/[^0-9a-f]/i', '', (string) $value);
        if (!is_string($hex) || strlen($hex) !== 12 || !ctype_xdigit($hex)) return null;
        return strtolower(implode(':', str_split($hex, 2)));
    }
}
