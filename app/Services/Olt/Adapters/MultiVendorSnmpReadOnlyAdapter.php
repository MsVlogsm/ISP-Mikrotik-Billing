<?php

namespace App\Services\Olt\Adapters;

use App\Contracts\OltReadOnlyAdapter;
use App\Models\NetworkInventoryDevice;
use Illuminate\Support\Facades\Crypt;

/**
 * Vendor-neutral, read-only SNMP adapter with model/vendor OID profiles.
 * Vendor-specific OIDs are configuration-driven; no write operation is exposed.
 */
class MultiVendorSnmpReadOnlyAdapter implements OltReadOnlyAdapter
{
    public function key(): string { return 'multivendor_snmp_readonly'; }

    public function supports(NetworkInventoryDevice $device): bool
    {
        return $device->type === 'olt' && $this->profile($device) !== null;
    }

    public function read(NetworkInventoryDevice $device): array
    {
        if (!function_exists('snmp2_get')) return $this->fail($device, 'PHP SNMP extension is not installed.');

        $config = $this->config($device);
        $host = trim((string) ($device->ip_address ?: $device->host));
        $community = (string) ($config['community'] ?? $this->storedCommunity($device));
        if ($host === '') return $this->fail($device, 'OLT management host/IP is not configured.');
        if ($community === '') return $this->fail($device, 'SNMP community is not configured for the adapter.');

        $address = $host;
        $snmpPort = max(1, min(65535, (int) ($device->snmp_port ?: 161)));
        // PHP's SNMP extension expects host:port. The udp:host:port format is
        // for the CLI snmpget utility and can be misparsed as a DNS hostname here.
        $host = $address . ':' . $snmpPort;

        $reportedModel = (string) $device->model;
        if (strtoupper(trim((string) $device->vendor)) === 'VSOL') {
            $liveDescription = @snmp2_get($host, $community, '1.3.6.1.2.1.1.1.0', 300000, 0);
            if ($liveDescription !== false) $reportedModel = (string) $this->clean($liveDescription);
        }
        $profile = $this->profile($device, $reportedModel);
        if ($profile === null) return $this->fail($device, 'No verified SNMP profile for this OLT model.');

        $timeout = max(100000, (int) ($config['timeout_us'] ?? 1000000));
        $retries = max(0, (int) ($config['retries'] ?? 1));
        // Keep ONU table discovery strictly bounded; failed SNMP walks must not
        // stall the sync command for every ONU column.
        $onuDefaultTimeout = strtoupper(trim((string) $device->vendor)) === 'VSOL' ? 700000 : min($timeout, 250000);
        $onuTimeout = max(100000, (int) ($config['onu_walk_timeout_us'] ?? $onuDefaultTimeout));
        $onuRetries = max(0, (int) ($config['onu_walk_retries'] ?? 0));
        if (function_exists('snmp_set_oid_output_format') && defined('SNMP_OID_OUTPUT_NUMERIC')) snmp_set_oid_output_format(SNMP_OID_OUTPUT_NUMERIC);
        $oids = $this->mergedOids($profile, $config);
        $result = [
            'ok' => false, 'status' => 'reachable',
            'message' => 'Read-only multi-vendor SNMP probe completed.',
            'device' => ['id'=>$device->id,'host'=>$address,'vendor'=>$device->vendor,'model'=>$device->model,'transport'=>'snmp','snmp_port'=>$snmpPort,'version'=>strtoupper((string)($config['version'] ?? $device->snmp_version ?? '2C'))],
            'onus' => [],
            'interfaces' => [],
            'meta' => ['read_only'=>true,'writes_performed'=>false,'profile'=>$profile['key'],'enterprise_oid'=>$profile['enterprise_oid'],'oid_count'=>0,'onu_count'=>0,'failed_oids'=>[],'oid_source'=>$profile['source']],
        ];

        foreach ($oids as $name => $oid) {
            if (!is_string($oid) || !preg_match('/^(?:\\.?(?:\\d+\\.)*\\d+)$/', trim($oid))) continue;
            $value = @snmp2_get($host, $community, trim($oid), $timeout, $retries);
            $result['meta']['oid_count']++;
            if ($value === false) { $result['meta']['failed_oids'][] = $name; continue; }
            $result['device'][$name] = $this->clean($value);
        }

        $result['interfaces'] = $this->discoverInterfaces($host, $community, $onuTimeout, $onuRetries);
        $result['meta']['interface_count'] = count($result['interfaces']);
        $result['meta']['pon_interface_count'] = count(array_filter($result['interfaces'], static fn ($i) => ($i['role'] ?? '') === 'pon'));
        $result['meta']['uplink_interface_count'] = count(array_filter($result['interfaces'], static fn ($i) => str_starts_with((string) ($i['role'] ?? ''), 'uplink_')));

        // Vendor/model-specific hardware OIDs must be explicitly configured;
        // no guessed enterprise OIDs are used for chassis metrics.
        $hardwareOids = is_array($config['hardware_oids'] ?? null) ? $config['hardware_oids'] : [];
        $hardwareAvailability = [];
        foreach (['cpu_percent','memory_percent','chassis_temp_c'] as $metric) {
            $oid = $hardwareOids[$metric] ?? null;
            $raw = is_string($oid) && preg_match('/^\.?\d+(?:\.\d+)*$/', trim($oid))
                ? @snmp2_get($host, $community, trim($oid), $timeout, $retries)
                : false;
            $value = $raw === false ? null : $this->numeric($this->clean($raw));
            if (in_array($metric, ['cpu_percent','memory_percent'], true) && ($value === null || $value < 0 || $value > 100)) $value = null;
            if ($metric === 'chassis_temp_c' && ($value === null || $value < -20 || $value > 150)) $value = null;
            $result['device'][$metric] = $value;
            $hardwareAvailability[$metric] = $value !== null;
        }
        $result['meta']['hardware_metric_availability'] = $hardwareAvailability;

        $onuConfig = $config['onu_oids'] ?? ($profile['onu_oids'] ?? []);
        if (is_array($onuConfig) && $onuConfig) {
            $result['onus'] = $this->discoverOnus($host, $community, $onuConfig, $onuTimeout, $onuRetries);
            $result['meta']['onu_count'] = count($result['onus']);
        }

        $result['ok'] = $result['meta']['oid_count'] > 0 && count($result['meta']['failed_oids']) < $result['meta']['oid_count'];
        $result['status'] = $result['ok'] ? (empty($result['meta']['failed_oids']) ? 'ok' : 'partial') : 'unreachable';
        $result['message'] = $result['ok']
            ? 'Configured vendor OIDs returned read-only data.'
            : 'SNMP read failed or returned no configured vendor OID data. No OLT changes were made.';
        return $result;
    }

    private function profile(NetworkInventoryDevice $device, ?string $reportedModel = null): ?array
    {
        $vendor = strtoupper(trim((string) $device->vendor));
        $model = strtoupper(trim((string) ($reportedModel ?? $device->model)));
        if ($vendor === 'VSOL' && str_contains($model, 'V1600D4')) {
            return [
                'key' => 'vsol_v1600d4_auth_info_table',
                'enterprise_oid' => '1.3.6.1.4.1.37950',
                'source' => 'V-Solution V1600D4 authInfoTable; live SNMP verified on firmware V1.0.2R',
                'oids' => ['sysDescr' => '1.3.6.1.2.1.1.1.0', 'sysUpTime' => '1.3.6.1.2.1.1.3.0'],
                'onu_oids' => [
                    'pon_port' => '1.3.6.1.4.1.37950.1.1.5.12.1.12.1.2',
                    'onu_id' => '1.3.6.1.4.1.37950.1.1.5.12.1.12.1.3',
                    'llid' => '1.3.6.1.4.1.37950.1.1.5.12.1.12.1.4',
                    'status' => '1.3.6.1.4.1.37950.1.1.5.12.1.12.1.5',
                    'onu_mac' => '1.3.6.1.4.1.37950.1.1.5.12.1.12.1.6',
                ],
            ];
        }
        $profiles = [
            'BDCOM'=>['key'=>'bdcom_epon','enterprise_oid'=>'1.3.6.1.4.1.3320.101','source'=>'BDCOM P3616-2TE EPON NMS MIB','oids'=>['sysDescr'=>'1.3.6.1.2.1.1.1.0','sysUpTime'=>'1.3.6.1.2.1.1.3.0'],'onu_oids'=>['onu_id'=>'1.3.6.1.4.1.3320.101.10.1.1.3','onu_vendor'=>'1.3.6.1.4.1.3320.101.10.1.1.1','onu_model'=>'1.3.6.1.4.1.3320.101.10.1.1.2','status'=>'1.3.6.1.4.1.3320.101.10.1.1.26','distance'=>'1.3.6.1.4.1.3320.101.10.1.1.27','onu_uptime'=>'1.3.6.1.4.1.3320.101.10.1.1.80','onu_mac'=>'1.3.6.1.4.1.3320.101.10.4.1.1','rx_power'=>'1.3.6.1.4.1.3320.101.10.5.1.5','tx_power'=>'1.3.6.1.4.1.3320.101.10.5.1.6','__table_root'=>'1.3.6.1.4.1.3320.101.10.1.1','__column_map'=>[1=>'onu_vendor',2=>'onu_model',3=>'onu_id',27=>'distance']]],
            'HUAWEI'=>['key'=>'huawei','enterprise_oid'=>'1.3.6.1.4.1.2011','source'=>'Huawei private MIB + standard system OIDs','oids'=>['sysDescr'=>'1.3.6.1.2.1.1.1.0','sysUpTime'=>'1.3.6.1.2.1.1.3.0','onuStatus'=>'1.3.6.1.4.1.2011.6.128.1.1.2.62.1.22'],'onu_oids'=>['onu_serial'=>'1.3.6.1.4.1.2011.6.128.1.1.2.43.1.3','status'=>'1.3.6.1.4.1.2011.6.128.1.1.2.62.1.22','rx_power'=>'1.3.6.1.4.1.2011.6.128.1.1.2.51.1.4']],
            'VSOL'=>['key'=>'vsol','enterprise_oid'=>'1.3.6.1.4.1.37950','source'=>'VSOL V1601E/V1600D EPON authInfo2Table validated by read-only SNMP','oids'=>['sysDescr'=>'1.3.6.1.2.1.1.1.0','sysUpTime'=>'1.3.6.1.2.1.1.3.0'],'onu_oids'=>['pon_port'=>'1.3.6.1.4.1.37950.1.1.5.12.1.25.1.1','onu_id'=>'1.3.6.1.4.1.37950.1.1.5.12.1.25.1.2','llid'=>'1.3.6.1.4.1.37950.1.1.5.12.1.25.1.3','status'=>'1.3.6.1.4.1.37950.1.1.5.12.1.25.1.4','onu_mac'=>'1.3.6.1.4.1.37950.1.1.5.12.1.25.1.5','onu_type'=>'1.3.6.1.4.1.37950.1.1.5.12.1.25.1.6','onu_model'=>'1.3.6.1.4.1.37950.1.1.5.12.1.25.1.13','distance'=>'1.3.6.1.4.1.37950.1.1.5.12.1.25.1.17','tx_power'=>'1.3.6.1.4.1.37950.1.1.5.12.2.1.8.1.6','rx_power'=>'1.3.6.1.4.1.37950.1.1.5.12.2.1.8.1.7']],
            'C-DATA'=>['key'=>'cdata','enterprise_oid'=>'1.3.6.1.4.1.25355','source'=>'C-Data GPON profile + standard system OIDs','oids'=>['sysDescr'=>'1.3.6.1.2.1.1.1.0','sysUpTime'=>'1.3.6.1.2.1.1.3.0'],'onu_oids'=>['onu_name'=>'1.3.6.1.4.1.25355.3.3.1.1.1.2','onu_serial'=>'1.3.6.1.4.1.25355.3.3.1.1.1.5','status'=>'1.3.6.1.4.1.25355.3.3.1.1.1.11','tx_power'=>'1.3.6.1.4.1.25355.3.3.1.1.4.1.2','rx_power'=>'1.3.6.1.4.1.25355.3.3.1.1.4.1.1']],
            'HSGQ'=>['key'=>'hsgq','enterprise_oid'=>null,'source'=>'HSGQ model-specific MIB/configuration; no unverified ONU OIDs embedded','oids'=>['sysDescr'=>'1.3.6.1.2.1.1.1.0','sysUpTime'=>'1.3.6.1.2.1.1.3.0'],'onu_oids'=>[]],
            'PHOTON'=>['key'=>'photon','enterprise_oid'=>null,'source'=>'PHOTON model-specific MIB/configuration','oids'=>['sysDescr'=>'1.3.6.1.2.1.1.1.0','sysUpTime'=>'1.3.6.1.2.1.1.3.0'],'onu_oids'=>[]],
            'ZTE'=>['key'=>'zte','enterprise_oid'=>'1.3.6.1.4.1.3902','source'=>'ZTE ZXA10 GPON MIB; model-specific indexes supported','oids'=>['sysDescr'=>'1.3.6.1.2.1.1.1.0','sysUpTime'=>'1.3.6.1.2.1.1.3.0'],'onu_oids'=>['status'=>'1.3.6.1.4.1.3902.1012.4']],
            'FIBERHOME'=>['key'=>'fiberhome','enterprise_oid'=>'1.3.6.1.4.1.5875','source'=>'FiberHome private MIB + standard system OIDs','oids'=>['sysDescr'=>'1.3.6.1.2.1.1.1.0','sysUpTime'=>'1.3.6.1.2.1.1.3.0'],'onu_oids'=>[]],
            'NOKIA'=>['key'=>'nokia','enterprise_oid'=>'1.3.6.1.4.1.637.61','source'=>'Nokia private MIB + standard system OIDs','oids'=>['sysDescr'=>'1.3.6.1.2.1.1.1.0','sysUpTime'=>'1.3.6.1.2.1.1.3.0'],'onu_oids'=>[]],
            'GENERIC'=>['key'=>'generic','enterprise_oid'=>null,'source'=>'RFC standard system MIB','oids'=>['sysDescr'=>'1.3.6.1.2.1.1.1.0','sysUpTime'=>'1.3.6.1.2.1.1.3.0'],'onu_oids'=>[]],
        ];
        return $profiles[$vendor] ?? null;
    }

    private function mergedOids(array $profile, array $config): array
    {
        return array_merge($profile['oids'] ?? [], is_array($config['oids'] ?? null) ? $config['oids'] : []);
    }

    /** Generic interface inventory from standard IF-MIB. */
    private function discoverInterfaces(string $host, string $community, int $timeout, int $retries): array
    {
        if (!function_exists('snmp2_real_walk')) return [];
        $bases = [
            'description'=>'1.3.6.1.2.1.2.2.1.2','oper_status'=>'1.3.6.1.2.1.2.2.1.8',
            'in_octets'=>'1.3.6.1.2.1.2.2.1.10','out_octets'=>'1.3.6.1.2.1.2.2.1.16',
            'in_octets_hc'=>'1.3.6.1.2.1.31.1.1.1.6','out_octets_hc'=>'1.3.6.1.2.1.31.1.1.1.10',
            'in_errors'=>'1.3.6.1.2.1.2.2.1.14','out_errors'=>'1.3.6.1.2.1.2.2.1.20',
            'in_discards'=>'1.3.6.1.2.1.2.2.1.13','out_discards'=>'1.3.6.1.2.1.2.2.1.19',
            'in_ucast'=>'1.3.6.1.2.1.2.2.1.11','in_nucast'=>'1.3.6.1.2.1.2.2.1.12',
            'out_ucast'=>'1.3.6.1.2.1.2.2.1.17','out_nucast'=>'1.3.6.1.2.1.2.2.1.18',
        ];
        $rows=[];
        foreach ($bases as $field=>$oid) {
            $walk=@snmp2_real_walk($host,$community,$oid,$timeout,$retries);
            if (!is_array($walk)) continue;
            foreach ($walk as $returned=>$value) {
                $index=$this->oidIndex((string)$returned,$oid);
                if ($index!==null && ctype_digit($index)) $rows[$index][$field]=$this->clean($value);
            }
        }
        foreach ($rows as $index=>&$row) {
            $description=trim((string)($row['description']??''));
            if (preg_match('/^EPON\d+\/\d+$/i',$description)) $role='pon';
            elseif (preg_match('/^EPON\d+\/\d+:\d+$/i',$description)) $role='onu';
            elseif (preg_match('/^(GE|GIGABITETHERNET)\d+\/\d+$/i',$description)) $role='uplink_ge';
            elseif (preg_match('/^(XGE|10GE|SFP\+?)\d*\/\d+$/i',$description)) $role='uplink_sfp';
            else $role='other';
            $row['if_index']=(int)$index;$row['description']=$description;$row['role']=$role;
            $state=trim((string)($row['oper_status']??''));
            $row['oper_status']=preg_match('/\b1\b/',$state)?'up':(preg_match('/\b2\b/',$state)?'down':'unknown');
            $row['in_octets']=$this->counterValue($row['in_octets_hc']??$row['in_octets']??null);
            $row['out_octets']=$this->counterValue($row['out_octets_hc']??$row['out_octets']??null);
            foreach(['in_errors','out_errors','in_discards','out_discards'] as $counter)$row[$counter]=$this->counterValue($row[$counter]??null);
            $inU=$this->counterValue($row['in_ucast']??null);$inN=$this->counterValue($row['in_nucast']??null);$outU=$this->counterValue($row['out_ucast']??null);$outN=$this->counterValue($row['out_nucast']??null);
            $row['in_packets']=($inU!==null||$inN!==null)?($inU??0)+($inN??0):null;$row['out_packets']=($outU!==null||$outN!==null)?($outU??0)+($outN??0):null;
            unset($row['in_octets_hc'],$row['out_octets_hc'],$row['in_ucast'],$row['in_nucast'],$row['out_ucast'],$row['out_nucast']);
        }
        unset($row);ksort($rows,SORT_NUMERIC);return array_values($rows);
    }

    private function counterValue(mixed $value): ?int
    {
        if($value===null||$value==='')return null;if(is_int($value))return $value>=0?$value:null;
        if(preg_match('/\d+/',(string)$value,$m))return (int)$m[0];return null;
    }

    private function numeric(mixed $value): ?float
    {
        if ($value===null || $value==='') return null;
        if (is_numeric($value)) return (float)$value;
        if (preg_match('/-?\\d+(?:\\.\\d+)?/',(string)$value,$m)) return (float)$m[0];
        return null;
    }

    private function discoverOnus(string $host, string $community, array $onuConfig, int $timeout, int $retries): array
    {
        $tables = [];
        $tableRootLoaded = false;
        $columnMap = $onuConfig['__column_map'] ?? [];

        if (isset($onuConfig['__entry_root']) && is_array($columnMap)) {
            $root = trim((string) $onuConfig['__entry_root']);
            foreach ($columnMap as $column => $field) {
                $columnOid = $root . '.' . (int) $column;
                $walk = @snmp2_real_walk($host, $community, $columnOid, $timeout, $retries);
                if (!is_array($walk)) continue;
                foreach ($walk as $returnedOid => $value) {
                    $normalized = preg_replace('/^iso\\./i', '', (string) $returnedOid) ?? (string) $returnedOid;
                    $normalized = ltrim($normalized, '.');
                    if (!preg_match('/\\.([0-9]+)$/', $normalized, $m)) continue;
                    $tables[$m[1]][$field] = $this->clean($value);
                    $tableRootLoaded = true;
                }
            }
        }
        if (isset($onuConfig['__table_root']) && is_array($columnMap) && $columnMap) {
            $root = trim((string) $onuConfig['__table_root']);
            $walk = @snmp2_real_walk($host, $community, $root, $timeout, $retries);
            if (!is_array($walk)) {
                // Fall back to a small set of useful ONU columns instead of
                // abandoning discovery when the aggregate table root is unsupported.
                $onuConfig = array_intersect_key($onuConfig, array_flip([
                    'onu_id', 'onu_mac', 'status', 'distance', 'rx_power', 'tx_power', 'onu_uptime',
                ]));
                $columnMap = [];
            }
            if (is_array($walk)) {
                foreach ($walk as $returnedOid => $value) {
                    $suffix = $this->oidIndex((string) $returnedOid, $root);
                    if ($suffix === null || !str_contains($suffix, '.')) continue;
                    [$column, $index] = explode('.', $suffix, 2);
                    $field = $columnMap[(int) $column] ?? null;
                    if ($field && $index !== '') {
                        $tables[$index][$field] = $this->clean($value);
                        $tableRootLoaded = true;
                    }
                }
            }
        }

        foreach ($onuConfig as $field=>$baseOid) {
            if (str_starts_with((string) $field, '__')) continue;
            // The root walk already returns these columns. Avoid walking the same
            // table once per column, which can multiply SNMP latency on large OLTs.
            if ($tableRootLoaded && in_array($field, $columnMap, true)) continue;
            if (!is_string($field)||!is_string($baseOid)||!preg_match('/^(?:\\.?(?:\\d+\\.)*\\d+)$/',trim($baseOid))) continue;
            $walk=@snmp2_real_walk($host,$community,trim($baseOid),$timeout,$retries);
            if(!is_array($walk)) {
                $plain=@snmp2_walk($host,$community,trim($baseOid),$timeout,$retries);
                if(is_array($plain)) {
                    $walk=[];
                    foreach($plain as $i=>$value) $walk[trim($baseOid,'.').'.'.(string)$i]=$value;
                }
            }
            if(!is_array($walk)) continue;
            foreach($walk as $returnedOid=>$value){$index=$this->oidIndex((string)$returnedOid,trim($baseOid));if($index===null)continue;$tables[$index][$field]=$this->clean($value);}
        }
        $out=[];
        foreach($tables as $index=>$row){$parts=explode('.',(string)$index);$row['snmp_index']=$index;$row['onu_id']=$row['onu_id']??$row['id']??end($parts);$row['onu_serial']=$row['onu_serial']??$row['serial']??null;$row['onu_mac']=$this->normalizeMac($row['onu_mac']??$row['mac']??null);$row['pon_port']=$row['pon_port']??$row['pon']??(count($parts)>1?$parts[0]:null);$row['status']=$this->normalizeEponStatus($row['status']??($row['online']??null));$row['onu_type']=$row['onu_model']??$row['onu_type']??null;$row['rx_power']=$this->normalizeOpticalPower($row['rx_power']??$row['optical_rx']??null);$row['tx_power']=$this->normalizeOpticalPower($row['tx_power']??$row['optical_tx']??null);$row['distance_m']=$row['distance']??$row['distance_m']??null;$out[]=$row;}
        return $out;
    }
    private function oidIndex(string $returned,string $base):?string{$returned=preg_replace('/^iso\\./i','',$returned)??$returned;$returned=ltrim($returned,'.');$base=ltrim($base,'.');$prefix=$base.'.';return $returned===$base?'':(str_starts_with($returned,$prefix)?substr($returned,strlen($prefix)):null);}
    private function clean(mixed $value):mixed{if(!is_string($value))return $value;return preg_replace('/^[A-Z0-9-]+:\\s*/i','',trim($value))??trim($value);}
    private function normalizeMac(mixed $value):?string{if(!is_string($value)||trim($value)==='')return null;$v=trim($value);if(preg_match('/^(?:[0-9A-Fa-f]{2}[ :.-]?){6}$/',$v)){$hex=preg_replace('/[^0-9A-Fa-f]/','',$v);return implode(':',str_split(strtolower($hex),2));}return $v;}
    private function normalizeEponStatus(mixed $value):string{$v=strtolower(trim((string)$value));return match($v){'1','registered','auth success'=>'online','2','deregistered','4','lost'=>'offline','0','authenticated','3','auto_config','5','standby',''=>'unknown',default=>$v};}
    private function storedCommunity(NetworkInventoryDevice $device):string{$raw=$device->getRawOriginal('snmp_community');if(!$raw)return '';try{return (string)Crypt::decryptString($raw);}catch(\Throwable){return (string)$raw;}}
    private function config(NetworkInventoryDevice $device):array{$raw=$device->adapter_config;if(is_array($raw))return $raw;if(!is_string($raw)||trim($raw)==='')return []; $v=json_decode($raw,true);return is_array($v)?$v:[];}
    private function fail(NetworkInventoryDevice $device,string $message):array{return ['ok'=>false,'status'=>'not_ready','message'=>$message,'device'=>['id'=>$device->id,'host'=>$device->ip_address?:$device->host,'vendor'=>$device->vendor,'model'=>$device->model,'transport'=>'snmp'],'onus'=>[],'meta'=>['read_only'=>true,'writes_performed'=>false,'oid_count'=>0]];}
}
