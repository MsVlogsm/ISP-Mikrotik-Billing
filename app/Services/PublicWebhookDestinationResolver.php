<?php

namespace App\Services;

use RuntimeException;

class PublicWebhookDestinationResolver
{
    /**
     * Resolve and pin an HTTPS webhook destination to a public address.
     * The HTTP client must use the returned CURLOPT_RESOLVE entry so a second
     * DNS lookup cannot redirect the request to a private network.
     */
    public function resolve(string $url): array
    {
        $parts = parse_url(trim($url));
        if (
            ! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw new RuntimeException('Webhook destination must be a valid HTTPS URL without embedded credentials.');
        }

        $host = strtolower(rtrim(trim((string) $parts['host'], '[]'), '.'));
        $port = (int) ($parts['port'] ?? 443);
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('Webhook destination has an invalid port.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses = [$host];
        } else {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            $addresses = [];
            foreach (is_array($records) ? $records : [] as $record) {
                $ip = $record['ip'] ?? $record['ipv6'] ?? null;
                if (is_string($ip) && $ip !== '') $addresses[] = $ip;
            }
            $addresses = array_values(array_unique($addresses));
        }

        if ($addresses === []) {
            throw new RuntimeException('Webhook destination DNS lookup returned no A or AAAA records.');
        }

        foreach ($addresses as $address) {
            if (! $this->isPublicAddress($address)) {
                throw new RuntimeException('Webhook destination resolves to a private, local or reserved network.');
            }
        }

        $ip = $addresses[0];
        $curlAddress = str_contains($ip, ':') ? '['.$ip.']' : $ip;

        return [
            'host' => $host,
            'port' => $port,
            'ip' => $ip,
            'curl_resolve' => $host.':'.$port.':'.$curlAddress,
        ];
    }

    public function isPublicAddress(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
