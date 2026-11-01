<?php
namespace App\Services\Olt;

use App\Models\NetworkInventoryDevice;
use Illuminate\Support\Facades\Crypt;
use phpseclib3\Net\SSH2;
use RuntimeException;

class VsolCliProvisioningService
{
    private SSH2 $ssh;
    private array $config;

    public function connect(NetworkInventoryDevice $device): void
    {
        $this->connectReadOnly($device);
        if (!$device->provisioning_enabled) throw new RuntimeException('OLT provisioning is disabled.');
        $profileKey=(string)($this->config['vsol_model_profile'] ?? ''); $profiles=config('olt_vsol.profiles',[]);
        if ($profileKey==='' || !isset($profiles[$profileKey])) throw new RuntimeException('No exact VSOL model command profile selected.');
        $profile=$profiles[$profileKey]; $model=trim((string)$device->model);
        if ($model==='' || !collect($profile['models']??[])->contains(fn($m)=>strcasecmp(trim($m),$model)===0)) throw new RuntimeException('OLT model does not exactly match the selected VSOL command profile.');
    }

    public function connectReadOnly(NetworkInventoryDevice $device): void
    {
        $this->config = $this->config($device);
        $host = (string)($device->ip_address ?: $device->host);
        if ($host === '') throw new RuntimeException('OLT management IP/host is not configured.');
        if (!$device->ssh_enabled) throw new RuntimeException('OLT SSH access is disabled.');
        $username = $this->secret($device, 'username');
        $password = $this->secret($device, 'password');
        if ($username === '' || $password === '') throw new RuntimeException('OLT SSH credentials are not configured.');
        $port = (int)($device->ssh_port ?: 22);
        $this->ssh = new SSH2($host, $port, max(3, (int)($device->cli_timeout ?: 10)));
        if (!$this->ssh->login($username, $password)) throw new RuntimeException('VSOL SSH login failed.');
    }

    public function discoverVersion(): string
    {
        return $this->runReadOnly('show version');
    }

    private function runReadOnly(string $command): string
    {
        if (preg_match('/[\r\n;$]/', $command) || !in_array($command, ['show version', 'show system'], true)) {
            throw new RuntimeException('Unsafe read-only discovery command rejected.');
        }
        $this->ssh->write($command."\n");
        return trim((string)$this->ssh->read('/(?:#|>)\s*$/m'));
    }

    public function authorizeByMac(string $pon, string $mac): string
    {
        $this->validatePon($pon); $this->validateMac($mac);
        return $this->run($this->command('authorize_mac', ['pon'=>$pon,'mac'=>$mac]));
    }

    public function removeByMac(string $pon, string $mac): string
    {
        $this->validatePon($pon); $this->validateMac($mac);
        return $this->run($this->command('remove_mac', ['pon'=>$pon,'mac'=>$mac]));
    }

    public function configurePppoe(string $pon, string $onu, string $username, string $password, string $server = ''): string
    {
        $this->validatePon($pon); $this->validateOnu($onu);
        if ($username === '' || strlen($username) > 128 || strlen($password) > 128 || strlen($server) > 128) throw new RuntimeException('Invalid PPPoE credentials.');
        $commands = $this->commands('pppoe', compact('pon','onu','username','password','server'));
        return $this->runMany($commands);
    }

    public function configureStaticIp(string $pon, string $onu, string $ip, string $netmask, string $gateway, string $dns1 = '', string $dns2 = ''): string
    {
        $this->validatePon($pon); $this->validateOnu($onu);
        foreach ([$ip,$gateway] as $value) if (!filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) throw new RuntimeException('Invalid IPv4 address.');
        if (!filter_var($netmask, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) throw new RuntimeException('Invalid IPv4 netmask.');
        foreach ([$dns1, $dns2] as $dns) if ($dns !== '' && !filter_var($dns, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) throw new RuntimeException('Invalid DNS IPv4 address.');
        return $this->runMany($this->commands('static_ip', compact('pon','onu','ip','netmask','gateway','dns1','dns2')));
    }

    /** Restart exactly one already-registered ONU using the selected model profile. */
    public function rebootOnu(string $pon, string $onu): string
    {
        $this->validatePon($pon);
        $this->validateOnu($onu);
        $profileKey = (string) ($this->config['vsol_model_profile'] ?? '');
        $profile = config('olt_vsol.profiles.'.$profileKey, []);
        $allowedFirmware = (array) ($profile['firmware'] ?? []);
        if ($profileKey !== 'v1601e04_dp_v1_0_2r' || ! in_array(strtoupper(trim((string) ($this->config['_validated_firmware'] ?? ''))), $allowedFirmware, true)) {
            throw new RuntimeException('ONU reboot requires the exact V1601E04-DP V1.0.2R CLI profile.');
        }
        $output = trim($this->runMany($this->commands('reboot_onu', compact('pon', 'onu'))));
        if ($output === '') {
            throw new RuntimeException('OLT closed or returned no CLI response after the reset request. Command acceptance cannot be confirmed.');
        }
        if (preg_match('/(?:unknown command|incomplete command|ambiguous command|command failed|no such onu|invalid (?:onu|command)|%\s*error)/i', $output)) {
            throw new RuntimeException('OLT rejected the ONU reset command: '.mb_substr($output, 0, 500));
        }
        return $output;
    }

    public function disableOnu(string $pon, string $onu): string
    {
        $this->validatePon($pon); $this->validateOnu($onu);
        return $this->run($this->command('disable_onu', compact('pon','onu')));
    }

    public function enableOnu(string $pon, string $onu): string
    {
        $this->validatePon($pon); $this->validateOnu($onu);
        return $this->run($this->command('enable_onu', compact('pon','onu')));
    }

    public function save(): string
    {
        return $this->run((string)($this->config['commands']['save'] ?? 'write'));
    }

    private function commands(string $type, array $vars): array
    {
        $configured = data_get($this->config, 'commands.'.$type);
        if (is_array($configured) && $configured) return array_map(fn($c)=>(string)$this->render($c,$vars), $configured);
        if ($type === 'pppoe') return [
            'configure terminal','interface epon '.$vars['pon'],
            'onu '.$vars['onu'].' pri wan_conn add route internet nat enable',
            'onu '.$vars['onu'].' pri wan_conn index 1 pppoe proxy disable user '.$this->quote($vars['username']).' pwd '.$this->quote($vars['password']).' server '.$this->quote($vars['server'] ?? '').' mode auto',
            'onu '.$vars['onu'].' pri wan_conn commit','exit','exit',
        ];
        if ($type === 'static_ip') return [
            'configure terminal','interface epon '.$vars['pon'],
            'onu '.$vars['onu'].' pri wan_conn add route internet nat enable',
            'onu '.$vars['onu'].' pri wan_conn index 1 static ip '.$vars['ip'].' mask '.$vars['netmask'].' gw '.$vars['gateway'].' dns master '.$vars['dns1'].' slave '.$vars['dns2'],
            'onu '.$vars['onu'].' pri wan_conn commit','exit','exit',
        ];
        throw new RuntimeException('No safe VSOL command template configured for '.$type.'. Configure adapter_config.commands.'.$type.' for this exact model.');
    }

    private function command(string $type, array $vars): string
    {
        $configured = data_get($this->config, 'commands.'.$type);
        if (is_array($configured) && $configured) {
            return implode("\n", array_map(fn ($line) => (string) $this->render((string) $line, $vars), $configured));
        }
        if (!$configured) {
            if ($type === 'authorize_mac') return "configure terminal\ninterface epon {$vars['pon']}\nonu-auth mode mac\nonu mac-auth add {$vars['mac']}\nexit\nexit";
            if ($type === 'remove_mac') return "configure terminal\ninterface epon {$vars['pon']}\nonu mac-auth del {$vars['mac']}\nexit\nexit";
            if ($type === 'disable_onu') return "configure terminal\ninterface epon {$vars['pon']}\nonu {$vars['onu']} disable\nexit\nexit";
            if ($type === 'enable_onu') return "configure terminal\ninterface epon {$vars['pon']}\nonu {$vars['onu']} enable\nexit\nexit";
        }
        throw new RuntimeException('No safe VSOL command template configured for '.$type.'.');
    }

    private function runMany(array $commands): string { return $this->run(implode("\n", $commands)); }
    private function run(string $commands): string
    {
        $this->ssh->write($commands."\n");
        $out = $this->ssh->read('/(?:#|>)\s*$/m');
        return trim((string)$out);
    }
    private function render(string $template, array $vars): string
    {
        foreach ($vars as $key=>$value) $template = str_replace('{{'.$key.'}}', $this->quote((string)$value), $template);
        return $template;
    }
    private function quote(string $value): string
    {
        if (preg_match('/[\r\n;`$]/', $value)) throw new RuntimeException('Unsafe command parameter rejected.');
        return $value;
    }
    private function validatePon(string $pon): void { if (!preg_match('/^\d+\/\d+$/',$pon)) throw new RuntimeException('PON must use slot/port format.'); }
    private function validateOnu(string $onu): void { if (!preg_match('/^\d+$/',$onu)) throw new RuntimeException('ONU ID must be numeric.'); }
    private function validateMac(string $mac): void { if (!preg_match('/^(?:[0-9a-f]{2}:){5}[0-9a-f]{2}$/i',$mac)) throw new RuntimeException('Invalid ONU MAC address.'); }
    private function secret(NetworkInventoryDevice $device, string $field): string
    {
        $raw = $device->getRawOriginal($field); if (!$raw) return '';
        try { return (string)Crypt::decryptString($raw); } catch (\Throwable) { return (string)$raw; }
    }
    private function config(NetworkInventoryDevice $device): array
    {
        $raw=$device->adapter_config; if (is_array($raw)) return $raw;
        $decoded=json_decode((string)$raw,true); return is_array($decoded)?$decoded:[];
    }
}
