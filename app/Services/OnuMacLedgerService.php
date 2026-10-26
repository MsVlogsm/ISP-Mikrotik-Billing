<?php

namespace App\Services;

use App\Models\CustomersInfo;
use App\Models\OltOnuCustomerMapping;
use App\Models\OnuMacLedger;
use Illuminate\Support\Collection;

class OnuMacLedgerService
{
    /**
     * Seed missing ledger identities from the already-saved ONU register.
     * Existing ledger history and customer links are never overwritten by this backfill.
     */
    public function bootstrapMissingMappings(Collection $mappings): int
    {
        $created = 0;

        foreach ($mappings->groupBy('olt_device_id') as $oltId => $oltMappings) {
            $records = OnuMacLedger::where('olt_device_id', $oltId)->get();

            foreach ($oltMappings as $mapping) {
                if (! $this->hasIdentityRecord($records, $mapping)) {
                    // Do not preserve a questionable Customer/PPPoE link from a mapping
                    // explicitly flagged for manual review. Keep only the ONU identity.
                    $includeCustomer = ! $this->requiresManualReview($mapping);
                    $record = $this->createFromMapping($mapping, $includeCustomer);
                    if ($record) {
                        $records->push($record);
                        $created++;
                    }
                }
            }
        }

        return $created;
    }

    /** Maintain the ledger from a saved mapping without changing the mapping itself. */
    public function recordMapping(OltOnuCustomerMapping $mapping): ?OnuMacLedger
    {
        $mac = $this->normalizeMac($mapping->onu_mac);
        $serial = $this->normalizeSerial($mapping->onu_serial);
        if ($mac === '' && $serial === '') {
            return null;
        }

        $records = OnuMacLedger::where('olt_device_id', $mapping->olt_device_id)->get();
        $matches = $this->identityMatches($records, $mac, $serial);

        // Duplicate identities are evidence of a conflict, not permission to pick a row.
        if ($matches->count() > 1) {
            return null;
        }

        $record = $matches->first();
        if ($record && $mac !== '' && $serial !== '' && $this->normalizeSerial($record->serial_no) !== ''
            && $this->normalizeSerial($record->serial_no) !== $serial
            && $this->normalizeMac($record->current_mac) === $mac) {
            // Same MAC with a conflicting non-empty serial requires manual review.
            return null;
        }

        if (! $record) {
            return $this->createFromMapping($mapping);
        }

        $mapping->loadMissing(['customer', 'pppUser']);
        $oldMac = trim((string) $record->current_mac);
        $oldMacKey = $this->normalizeMac($oldMac);
        $sameSerial = $serial !== '' && $this->normalizeSerial($record->serial_no) === $serial;

        if ($mac !== '' && $oldMacKey !== '' && $oldMacKey !== $mac) {
            // MAC history is only carried forward when the ONU serial is identical.
            if (! $sameSerial) {
                return null;
            }
            $record->previous_mac = $oldMac;
            $record->mac_changed_at = now();
            $this->appendNote($record, 'MAC address changed while the ONU serial remained the same; history retained for review.');
        }

        if ($mac !== '') {
            $record->current_mac = $mapping->onu_mac;
        }
        if (trim((string) $mapping->onu_serial) !== '') {
            $record->serial_no = $mapping->onu_serial;
        }
        $record->olt_device_id = $mapping->olt_device_id;
        $record->onu_id = $mapping->onu_id ?: $record->onu_id;
        $record->pon_port = $mapping->pon_port ?: $record->pon_port;
        $record->status = $mapping->status ?: ($record->status ?: 'unknown');
        $record->rx_power = $mapping->rx_power ?? $record->rx_power;
        $record->tx_power = $mapping->tx_power ?? $record->tx_power;
        $record->first_seen_at = $record->first_seen_at ?: ($mapping->created_at ?: now());
        $record->last_seen_at = $mapping->last_seen_at ?: $record->last_seen_at;

        if ($mapping->customer_id && $mapping->customer) {
            $record->customer_id = $mapping->customer_id;
            $record->customer_unique_id = $mapping->customer->customer_unique_id;
            $record->pppoe_username = $mapping->pppUser?->username ?: $mapping->customer->pppUser?->username ?: $record->pppoe_username;
            $record->service_type = $mapping->pppUser?->service ?: $record->service_type;
        }

        if (trim((string) $mapping->notes) !== '') {
            $this->appendNote($record, trim((string) $mapping->notes));
        }

        $record->save();

        return $record;
    }

    /**
     * Return a subscriber only when one current ledger identity and its linked active
     * Customer/PPPoE pair agree exactly. Previous MACs are history, never match keys.
     */
    public function findExactCustomerMatch(
        int $oltId,
        ?string $onuMac,
        ?string $onuSerial,
        array $usedCustomerIds = [],
        array $usedPppIds = [],
    ): ?CustomersInfo {
        $mac = $this->normalizeMac($onuMac);
        $serial = $this->normalizeSerial($onuSerial);
        if ($mac === '' && $serial === '') {
            return null;
        }

        $records = OnuMacLedger::where('olt_device_id', $oltId)->get();
        $matches = $this->identityMatches($records, $mac, $serial);
        if ($matches->count() !== 1) {
            return null;
        }

        $record = $matches->first();
        if ($mac !== '' && $serial !== '' && $this->normalizeMac($record->current_mac) === $mac
            && $this->normalizeSerial($record->serial_no) !== ''
            && $this->normalizeSerial($record->serial_no) !== $serial) {
            return null;
        }

        if (! $record->customer_id || ! trim((string) $record->customer_unique_id)
            || ! trim((string) $record->pppoe_username)) {
            return null;
        }

        $customer = CustomersInfo::with('pppUser')->find($record->customer_id);
        if (! $customer || strtolower(trim((string) $customer->status)) !== 'active'
            || trim((string) $customer->customer_unique_id) !== trim((string) $record->customer_unique_id)) {
            return null;
        }

        $ppp = $customer->pppUser;
        if (! $ppp || strtolower(trim((string) $ppp->status)) !== 'active'
            || strtolower(trim((string) $ppp->username)) !== strtolower(trim((string) $record->pppoe_username))) {
            return null;
        }

        if (isset($usedCustomerIds[(string) $customer->id])
            || isset($usedPppIds[(string) $ppp->id])) {
            return null;
        }

        return $customer;
    }

    private function createFromMapping(OltOnuCustomerMapping $mapping, bool $includeCustomer = true): ?OnuMacLedger
    {
        if ($this->normalizeMac($mapping->onu_mac) === '' && $this->normalizeSerial($mapping->onu_serial) === '') {
            return null;
        }

        $mapping->loadMissing(['customer', 'pppUser']);
        $customer = $includeCustomer ? $mapping->customer : null;
        $ppp = $includeCustomer ? ($mapping->pppUser ?: $customer?->pppUser) : null;
        $seenAt = $mapping->last_seen_at ?: $mapping->created_at ?: now();

        return OnuMacLedger::create([
            'olt_device_id' => $mapping->olt_device_id,
            'customer_id' => $customer?->id,
            'customer_unique_id' => $customer?->customer_unique_id,
            'pon_port' => $mapping->pon_port,
            'onu_id' => $mapping->onu_id,
            'serial_no' => $mapping->onu_serial,
            'current_mac' => $mapping->onu_mac,
            'service_type' => $ppp?->service,
            'pppoe_username' => $ppp?->username,
            'static_ip' => $mapping->onu_ip,
            'rx_power' => $mapping->rx_power,
            'tx_power' => $mapping->tx_power,
            'status' => $mapping->status ?: 'unknown',
            'first_seen_at' => $seenAt,
            'last_seen_at' => $mapping->last_seen_at,
            'notes' => trim((string) $mapping->notes) !== '' ? $mapping->notes : null,
        ]);
    }

    private function requiresManualReview(OltOnuCustomerMapping $mapping): bool
    {
        $notes = strtolower(trim((string) $mapping->notes));

        return str_contains($notes, 'manual review required')
            || str_contains($notes, 'stable onu identity changed');
    }

    private function hasIdentityRecord(Collection $records, OltOnuCustomerMapping $mapping): bool
    {
        return $this->identityMatches(
            $records,
            $this->normalizeMac($mapping->onu_mac),
            $this->normalizeSerial($mapping->onu_serial),
        )->isNotEmpty();
    }

    private function identityMatches(Collection $records, string $mac, string $serial): Collection
    {
        if ($serial !== '') {
            $serialMatches = $records->filter(fn (OnuMacLedger $record) => $this->normalizeSerial($record->serial_no) === $serial)->values();
            if ($serialMatches->isNotEmpty()) {
                return $serialMatches;
            }
        }

        if ($mac === '') {
            return collect();
        }

        return $records->filter(fn (OnuMacLedger $record) => $this->normalizeMac($record->current_mac) === $mac)->values();
    }

    private function normalizeMac(?string $value): string
    {
        return strtolower(preg_replace('/[^a-f0-9]/i', '', (string) $value) ?? '');
    }

    private function normalizeSerial(?string $value): string
    {
        return strtolower(trim((string) $value));
    }

    private function appendNote(OnuMacLedger $record, string $note): void
    {
        $notes = trim((string) $record->notes);
        if ($notes === '' || ! str_contains($notes, $note)) {
            $record->notes = trim($notes.($notes !== '' ? "\n" : '').$note);
        }
    }
}
