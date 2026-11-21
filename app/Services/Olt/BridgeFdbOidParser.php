<?php

namespace App\Services\Olt;

/**
 * Parses the instance suffixes defined by BRIDGE-MIB and Q-BRIDGE-MIB.
 * No vendor-private indexes are inferred here.
 */
class BridgeFdbOidParser
{
    /**
     * @return array{mac:string,fdb_id:?int}|null
     */
    public function parseIndex(string $suffix, string $source): ?array
    {
        $parts = explode('.', ltrim(trim($suffix), '.'));

        if ($source === 'dot1d') {
            // The standard fixed-size MacAddress index is six octets. Some
            // OLT firmware also emits an explicit leading length of 6.
            if (count($parts) === 6) {
                $octets = $parts;
            } elseif (count($parts) === 7 && $parts[0] === '6') {
                $octets = array_slice($parts, 1, 6);
            } else {
                return null;
            }

            $fdbId = null;
        } elseif ($source === 'dot1q') {
            // INDEX { dot1qFdbId, dot1qTpFdbAddress }. Accept both the
            // standard fixed-size form and firmware that inserts length=6.
            if (! isset($parts[0]) || ! ctype_digit($parts[0])) {
                return null;
            }

            $fdbId = (int) $parts[0];
            if (count($parts) === 7) {
                $octets = array_slice($parts, 1, 6);
            } elseif (count($parts) === 8 && $parts[1] === '6') {
                $octets = array_slice($parts, 2, 6);
            } else {
                return null;
            }
        } else {
            return null;
        }

        if (count($octets) !== 6) {
            return null;
        }

        foreach ($octets as $octet) {
            if (! ctype_digit($octet) || (int) $octet < 0 || (int) $octet > 255) {
                return null;
            }
        }

        $hex = array_map(static fn (string $octet): string => sprintf('%02x', (int) $octet), $octets);
        $mac = implode(':', $hex);

        // Reject all-zero and broadcast addresses; they are not learned unicast hosts.
        if ($mac === '00:00:00:00:00:00' || $mac === 'ff:ff:ff:ff:ff:ff') {
            return null;
        }

        return ['mac' => $mac, 'fdb_id' => $fdbId];
    }

    public function normalizeOidValue(mixed $value): ?int
    {
        if (is_int($value) || (is_string($value) && ctype_digit(trim($value)))) {
            return max(0, (int) $value);
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if (preg_match('/(?:INTEGER|Gauge32|Counter32|Unsigned32|INTEGER32):\s*(\d+)/i', $value, $matches)) {
            return (int) $matches[1];
        }

        return preg_match('/^\d+$/', $value) ? (int) $value : null;
    }

    public function statusName(?int $status): string
    {
        return match ($status) {
            1 => 'other',
            2 => 'invalid',
            3 => 'learned',
            4 => 'self',
            5 => 'management',
            default => 'unknown',
        };
    }

    public function normalizeMac(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $hex = preg_replace('/[^0-9a-f]/i', '', preg_replace('/^0x/i', '', trim($value)) ?? '') ?? '';
        if (strlen($hex) !== 12) {
            return null;
        }

        $mac = strtolower(implode(':', str_split($hex, 2)));
        if ($mac === '00:00:00:00:00:00' || $mac === 'ff:ff:ff:ff:ff:ff') {
            return null;
        }

        return $mac;
    }
}
