<?php

namespace App\Services\Olt;

use App\Models\NetworkInventoryDevice;
use phpseclib3\Net\SSH2;
use Illuminate\Support\Facades\Crypt;

class VsolSshOpmCollector
{
    public function collect(NetworkInventoryDevice $device, array $baseOnus = [], ?string $targetPon = null, ?string $targetOnuId = null): array
    {
        $config = $this->config($device);
        $host = trim((string) ($device->ip_address ?: $device->host));
        $username = trim((string) data_get($config, 'ssh.username', data_get($config, 'username', $device->username)));
        $password = $this->secret(data_get($config, 'ssh.password'), $device->getRawOriginal('password'));
        $port = (int) data_get($config, 'ssh.port', $device->ssh_port ?: 22);
        $timeout = max(3, min(12, (int) data_get($config, 'ssh.timeout', 7)));

        if ($host === '') return $this->fail('VSOL SSH host/IP is not configured.');
        if ($username === '') return $this->fail('VSOL SSH username is not configured.');
        if ($password === '') return $this->fail('VSOL SSH password is not configured.');

        try {
            $ssh = new SSH2($host, $port, $timeout);
            $ssh->setTimeout($timeout);
            if (! $ssh->login($username, $password)) return $this->fail('VSOL SSH authentication failed.');

            // VSOL exposes its network CLI on the interactive SSH channel. The
            // SSH exec channel can land in a Linux shell instead (where "show"
            // is not a command), so OPM reads must use write()/read() and the
            // device prompt. Only the known read-only OPM command is accepted.
            $ssh->write("enable\n");
            $prompt = (string) $ssh->read('/(?:Password:\s*|[A-Za-z0-9_.-]+[>#]\s*)$/mi');
            $promptLines = preg_split('/\\R/', trim($prompt)) ?: [];
            $lastPromptLine = trim((string) end($promptLines));
            if (preg_match('/^Password:\s*$/i', $lastPromptLine)) {
                $enablePassword = $this->secret(null, $device->getRawOriginal('enable_password'));
                if ($enablePassword === '') return $this->fail('VSOL privileged CLI requires a separate enable password. Save it in OLT Admin settings.');
                $ssh->write($enablePassword."\n");
                $prompt = (string) $ssh->read('/(?:Password:\s*|[A-Za-z0-9_.-]+[>#]\s*)$/mi');
                $promptLines = preg_split('/\R/', trim($prompt)) ?: [];
                $lastPromptLine = trim((string) end($promptLines));
                if (preg_match('/^Password:\s*$/i', $lastPromptLine)) return $this->fail('VSOL rejected the CLI enable password.');
            }
            if (!str_ends_with($lastPromptLine, '#')) return $this->fail('VSOL did not confirm privileged CLI mode.');

            // Disable paging for this SSH VTY session only (not a saved OLT config),
            // then read the advertised dynamic MAC table to bind PPPoE caller IDs
            // to the exact EPON PON/ONU interface.
            $ssh->write("terminal length 0\n");
            $pagingOutput = trim((string) $ssh->read('/BT-EPON-OLT#\s*$/D'));
            if (preg_match('/(?:Unknown command|Command incomplete|There is no matched command)/i', $pagingOutput)) {
                return $this->fail('VSOL CLI did not accept the per-session terminal paging command.');
            }
            $macOutput = $this->runCliCommand($ssh, 'show mac address-table');
            $clientMacRows = $this->parseClientMacTable($macOutput);

            // V-SOL V1600D4/V1.0.2R exposes ONU optical diagnosis in config CLI
            // context. This changes only the interactive SSH command mode; the
            // following command is show-only and no configuration is written.
            $ssh->write("configure terminal\n");
            $configPrompt = trim((string) $ssh->read('/BT-EPON-OLT\(config\)#\s*$/D'));
            if (!str_ends_with($configPrompt, '(config)#')) {
                return $this->fail('VSOL did not enter config CLI context for read-only OPM diagnostics.');
            }

            $opmCommand = trim((string) data_get($config, 'opm.command', 'show onu opm-diag all'));
            if ($opmCommand !== 'show onu opm-diag all') return $this->fail('Only the approved read-only VSOL OPM command is allowed.');
            $opmOutput = $this->runCliCommand($ssh, $opmCommand);
            $opmRows = $this->parseOpm($opmOutput);

            // The "all" command can return a partial OPM table (for example,
            // 57 of 78 ONUs). When a caller requests one ONU, retry its PON even
            // when other rows were parsed; only retry all PONs for a full sweep
            // when the all-table command returned no parseable rows at all.
            $ponNumbers = [];
            if ($targetPon !== null && $targetOnuId !== null) {
                $targetPonNumber = $this->ponNumber($targetPon);
                if ($targetPonNumber !== null) {
                    $targetCliId = (int) $targetOnuId;
                    $numericIds = array_values(array_filter(array_map(
                        fn ($onu) => ctype_digit(trim((string) ($onu['onu_id'] ?? ''))) ? (int) trim((string) $onu['onu_id']) : null,
                        $baseOnus
                    ), fn ($id) => $id !== null));
                    if (in_array(0, $numericIds, true)) $targetCliId++;
                    $targetKey = $targetPonNumber.':'.$targetCliId;
                    $targetPresent = collect($opmRows)->contains(fn ($row) => ($row['cli_key'] ?? null) === $targetKey);
                    if (! $targetPresent) $ponNumbers[$targetPonNumber] = true;
                }
            } elseif (! $opmRows) {
                foreach ($baseOnus as $onu) {
                    $pon = $this->ponNumber($onu['pon_port'] ?? '');
                    if ($pon !== null) $ponNumbers[$pon] = true;
                }
            }

            foreach (array_keys($ponNumbers) as $ponNumber) {
                $output = $this->runCliCommand($ssh, "show onu opm-diag pon {$ponNumber}");
                $seenKeys = array_fill_keys(array_map(fn ($row) => $row['cli_key'] ?? '', $opmRows), true);
                foreach ($this->parseOpm($output) as $row) {
                    if (! isset($seenKeys[$row['cli_key']])) {
                        $opmRows[] = $row;
                        $seenKeys[$row['cli_key']] = true;
                    }
                }
            }

            if (! $opmRows && ! $clientMacRows) {
                return $this->fail('VSOL MAC table and OPM command returned no parseable ONU rows.');
            }

            // The CLI uses EPON0/<PON>:<ONU> and normally starts ONU numbering at 1,
            // while some SNMP normalized inventories expose the same ONU as zero-based.
            // Detect the base inventory convention first, then map the CLI identity
            // deterministically so ONU 1 cannot accidentally bind to inventory ONU 1
            // when that row actually represents CLI ONU 2.
            $numericIds = array_values(array_filter(array_map(
                fn ($onu) => ctype_digit(trim((string) ($onu['onu_id'] ?? ''))) ? (int) trim((string) $onu['onu_id']) : null,
                $baseOnus
            ), fn ($id) => $id !== null));
            $inventoryZeroBased = in_array(0, $numericIds, true);

            $byCliKey = [];
            foreach ($baseOnus as $index => $onu) {
                $pon = $this->ponNumber($onu['pon_port'] ?? '');
                $id = trim((string) ($onu['onu_id'] ?? ''));
                if ($pon === null || $id === '' || !ctype_digit($id)) continue;
                $cliId = (int) $id + ($inventoryZeroBased ? 1 : 0);
                $byCliKey[$pon.':'.$cliId] ??= $index;
            }

            $macMerged = 0;
            foreach ($clientMacRows as $row) {
                $baseIndex = $byCliKey[$row['cli_key']] ?? null;
                if ($baseIndex === null || ! isset($baseOnus[$baseIndex])) continue;
                $existing = array_map(fn ($mac) => $this->normalizeMac($mac), (array) ($baseOnus[$baseIndex]['client_macs'] ?? []));
                if (!in_array($row['mac'], $existing, true)) {
                    $baseOnus[$baseIndex]['client_macs'][] = $row['mac'];
                    $macMerged++;
                }
            }

            $merged = 0;
            foreach ($opmRows as $row) {
                $baseIndex = $byCliKey[$row['cli_key']] ?? null;
                if ($baseIndex === null || ! isset($baseOnus[$baseIndex])) continue;

                $baseOnus[$baseIndex]['opm_source'] = 'vsol-cli-ssh';
                $baseOnus[$baseIndex]['tx_power'] = $row['tx_power'];
                $baseOnus[$baseIndex]['rx_power'] = $row['rx_power'];
                $merged++;
            }

            $success = $merged > 0 || $macMerged > 0;
            $message = "VSOL SSH read-only sync: {$macMerged} client MACs mapped to live ONU interfaces; {$merged} OPM rows merged.";
            if (!$success) $message = 'VSOL SSH MAC/OPM output did not match the normalized ONU index inventory.';

            return [
                'ok' => $success,
                'status' => ($merged > 0 && $macMerged > 0) ? 'ok' : ($success ? 'partial' : 'not_ready'),
                'message' => $message,
                'onus' => $baseOnus,
                'meta' => [
                    'source' => 'ssh-cli',
                    'opm_rows' => count($opmRows),
                    'target_opm_requested' => $targetPon !== null && $targetOnuId !== null,
                    'target_opm_found' => $targetPon !== null && $targetOnuId !== null
                        ? collect($opmRows)->contains(fn ($row) => ($row['cli_key'] ?? null) === $this->ponNumber($targetPon).':'.((int) $targetOnuId + (in_array(0, array_filter(array_map(fn ($onu) => ctype_digit(trim((string) ($onu['onu_id'] ?? ''))) ? (int) trim((string) $onu['onu_id']) : null, $baseOnus), fn ($id) => $id !== null), true) ? 1 : 0)))
                        : null,
                    'merged_rows' => $merged,
                    'client_mac_rows' => count($clientMacRows),
                    'client_mac_merged' => $macMerged,
                    'client_mac_onu_count' => count(array_filter($baseOnus, static fn ($onu) => !empty($onu['client_macs']))),
                    'read_only' => true,
                    'writes_performed' => false,
                ],
            ];
        } catch (\Throwable $e) {
            return $this->fail('VSOL SSH OPM read failed: '.$e->getMessage());
        }
    }

    private function runCliCommand(SSH2 $ssh, string $command): string
    {
        if ($command !== 'show onu opm-diag all' && $command !== 'show mac address-table' && !preg_match('/^show onu opm-diag pon [1-4]$/', $command)) {
            throw new \RuntimeException('Unsafe VSOL read-only command rejected.');
        }
        $ssh->write($command."\n");
        return trim((string) $ssh->read('/(?:[A-Za-z0-9_.()\/-]+[>#]\s*)$/m'));
    }

    /** Parse only dynamic learned MAC rows associated with an EPON ONU interface. */
    private function parseClientMacTable(string $output): array
    {
        $rows = [];
        foreach (preg_split('/\\R/', $output) ?: [] as $line) {
            if (!preg_match('/^\\s*(\\d+)\\s+((?:[0-9a-f]{4}:){2}[0-9a-f]{4}|(?:[0-9a-f]{2}[:-]){5}[0-9a-f]{2})\\s+Dynamic\\s+EPON0\\/(\\d+):(\\d+)/i', trim($line), $m)) continue;
            $hex = preg_replace('/[^0-9a-f]/i', '', $m[2]) ?: '';
            if (strlen($hex) !== 12) continue;
            $rows[] = ['vlan' => (int) $m[1], 'mac' => implode(':', str_split(strtolower($hex), 2)), 'pon' => $m[3], 'onu' => $m[4], 'cli_key' => $m[3].':'.$m[4]];
        }
        return $rows;
    }

    private function parseStatus(string $output): array
    {
        $map = [];
        foreach (preg_split('/\R/', $output) as $line) {
            if (! preg_match('/EPON0\/(\d+):(\d+)\s+(online|offline|up|down)\s+([0-9A-Fa-f:.-]{12,17})(?:\s+(?:\d+))?/i', trim($line), $m)) continue;
            $key = $m[1].':'.$m[2];
            $map[$key] = ['pon' => (string) $m[1], 'onu' => (string) $m[2], 'mac' => $this->normalizeMac($m[4]), 'status' => strtolower($m[3])];
            if (preg_match('/EPON0\/\d+:\d+\s+(?:online|offline|up|down)\s+[0-9A-Fa-f:.-]{12,17}\s+(\d+)/i', trim($line), $d)) $map[$key]['distance'] = (float) $d[1];
        }
        return $map;
    }
    private function parseOpm(string $output): array
    {
        $rows = [];
        foreach (preg_split('/\R/', $output) as $line) {
            $line = trim(preg_replace('/\s+/', ' ', $line) ?? '');
            if ($line === '' || stripos($line, 'ONU-ID') === 0 || str_starts_with($line, '------')) continue;

            // Expected VSOL format:
            // EPON0/1:1 27.17 3.28 0.00 2.10 -10.47
            // Be tolerant of optional unit text around optical power values.
            if (! preg_match(
                '/^(EPON0\/(\d+):(\d+))\s+([-+]?\d+(?:\.\d+)?)\s+([-+]?\d+(?:\.\d+)?)\s+([-+]?\d+(?:\.\d+)?)\s+([-+]?\d+(?:\.\d+)?)(?:\s+(?:mW|dBm))?\s+([-+]?\d+(?:\.\d+)?)(?:\s*dBm)?$/i',
                $line,
                $m
            )) continue;

            $rows[] = [
                'cli_id' => $m[1],
                'cli_key' => $m[2].':'.$m[3],
                'temperature' => (float) $m[4],
                'supply_voltage' => (float) $m[5],
                'tx_bias_current' => (float) $m[6],
                'tx_power' => (float) $m[7],
                'rx_power' => (float) $m[8],
            ];
        }
        return $rows;
    }

    private function ponNumber(mixed $value): ?int
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        if (preg_match('/(?:EPON0\/|PON)?(\d+)$/i', $value, $m)) return (int) $m[1];
        if (preg_match('/EPON0\/(\d+)/i', $value, $m)) return (int) $m[1];
        return ctype_digit($value) ? (int) $value : null;
    }

    private function config(NetworkInventoryDevice $device): array
    {
        $raw = $device->adapter_config;
        if (is_array($raw)) return $raw;
        if (! is_string($raw) || trim($raw) === '') return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function secret(mixed $preferred, mixed $stored): string
    {
        $raw = is_string($preferred) && trim($preferred) !== '' ? $preferred : $stored;
        if (! is_string($raw) || trim($raw) === '') return '';
        try { return (string) Crypt::decryptString($raw); } catch (\Throwable) { return $raw; }
    }

    private function normalizeMac(mixed $value): string
    {
        $hex = preg_replace('/[^0-9a-f]/i', '', (string) $value) ?? '';
        if (strlen($hex) !== 12) return strtolower(trim((string) $value));
        return implode(':', str_split(strtolower($hex), 2));
    }

    private function fail(string $message): array
    {
        return ['ok' => false, 'status' => 'not_ready', 'message' => $message, 'onus' => [], 'meta' => ['source' => 'ssh-cli', 'read_only' => true, 'writes_performed' => false]];
    }
}
