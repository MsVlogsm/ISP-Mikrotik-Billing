<?php
namespace App\Http\Controllers;
use App\Models\NetworkInventoryDevice;
use App\Models\OltProvisioningAudit;
use App\Services\Olt\VsolCliProvisioningService;
use App\Services\Olt\VsolModelDetector;
use Illuminate\Http\Request;
use Throwable;
class OltProvisioningController extends Controller {
 public function index(NetworkInventoryDevice $device){ abort_unless(hasAccess(['Super Admin'], ['network-inventory','mikrotik-setup']),403,'Unauthorized OLT access.'); abort_unless($device->type==='olt',404); $profiles=config('olt_vsol.profiles',[]); $audits=OltProvisioningAudit::where('olt_device_id',$device->id)->latest()->paginate(25); $selected=data_get($this->adapter($device),'vsol_model_profile'); return view('xlink.olt-provisioning',compact('device','profiles','audits','selected')); }
 public function discover(Request $request, NetworkInventoryDevice $device)
 {
  abort_unless(hasAccess(['Super Admin'], ['mikrotik-setup']),403,'Only Super Admin can change OLT discovery configuration.');
  abort_unless($device->type==='olt',404);
  $audit=OltProvisioningAudit::create(['olt_device_id'=>$device->id,'user_id'=>auth()->id(),'action'=>'discover_model','target'=>'read-only','model_profile'=>'discovery','transport'=>'ssh','request_payload'=>['command'=>'show version'],'status'=>'pending','source_ip'=>$request->ip()]);
  $start=microtime(true);
  try {
   $s=new VsolCliProvisioningService(); $s->connectReadOnly($device); $result=$s->discoverVersion();
   $detection=(new VsolModelDetector())->detect($result);
   $cfg=$this->adapter($device);
   if ($detection['status']==='detected') {
    $cfg['vsol_model_profile']=$detection['profile'];
    $device->adapter_config=$cfg;
    $device->save();
   }
   $audit->update([
    'status'=>'success',
    'result'=>$this->redact($result),
    'request_payload'=>['command'=>'show version','detection'=>$detection],
    'model_profile'=>$detection['profile'] ?? 'discovery',
    'target'=>$detection['model'] ?? 'read-only',
    'duration_ms'=>(int)round((microtime(true)-$start)*1000)
   ]);
   $message=$detection['status']==='detected'
    ? 'VSOL model detected: '.$detection['model'].'. Matching command profile has been selected automatically.'
    : 'VSOL version discovery completed, but no unique exact model profile was detected. Manual model selection is required.';
   return back()->with('discovery_message',$message);
  } catch (Throwable $e) {
   $audit->update(['status'=>'failed','error'=>$e->getMessage(),'duration_ms'=>(int)round((microtime(true)-$start)*1000)]);
   return back()->withErrors(['discovery'=>$e->getMessage()]);
  }
 }

 public function checkCliAccess(Request $request, NetworkInventoryDevice $device)
 {
  abort_unless(auth()->user()?->hasRole('Super Admin'),403,'Only Super Admin can test OLT CLI access.');
  abort_unless($device->type==='olt',404);
  $audit=OltProvisioningAudit::create([
   'olt_device_id'=>$device->id,'user_id'=>auth()->id(),'action'=>'cli_health_check',
   'target'=>'read-only show version','model_profile'=>$device->model ?: 'unknown','transport'=>'ssh',
   'request_payload'=>['command'=>'show version','read_only'=>true],'status'=>'pending','source_ip'=>$request->ip()
  ]);
  $start=microtime(true);
  try {
   $service=new VsolCliProvisioningService();
   $service->connectReadOnly($device);
   $output=$service->discoverVersion();
   if (trim($output)==='') throw new \RuntimeException('SSH connected, but the read-only show version command returned no output.');
   $audit->update(['status'=>'success','result'=>$this->redact($output),'duration_ms'=>(int)round((microtime(true)-$start)*1000)]);
   return back()->with('cli_check_success',true)->with('cli_check_message','SSH CLI test passed. Read-only show version responded. ONU reboot may be enabled for a supported, fresh ONU identity.');
  } catch(Throwable $e) {
   $audit->update(['status'=>'failed','error'=>$e->getMessage(),'duration_ms'=>(int)round((microtime(true)-$start)*1000)]);
   return back()->with('cli_check_success',false)->with('cli_check_message','SSH CLI test failed: '.$e->getMessage());
  }
 }

 public function rebootOnu(Request $request, NetworkInventoryDevice $device, \App\Models\OltOnuCustomerMapping $mapping)
 {
  abort_unless(auth()->user()?->hasRole('Super Admin'),403,'Only Super Admin can reboot an ONU.');
  abort_unless($device->type==='olt' && (int)$mapping->olt_device_id===(int)$device->id,404);
  $data=$request->validate(['confirmation'=>'required|in:REBOOT']);
  if (!$device->ssh_enabled || !$device->provisioning_enabled) return back()->with('onu_reboot_success',false)->with('onu_reboot_message','Reboot locked: enable the OLT SSH and provisioning controls first.');
  if (strcasecmp(trim((string)$device->model),'V1601E04-DP')!==0 || strtoupper(trim((string)$device->firmware))!=='V1.0.2R') return back()->with('onu_reboot_success',false)->with('onu_reboot_message','Reboot locked: this command profile is verified only for V-SOL V1601E04-DP firmware V1.0.2R.');
  if (strtolower((string)$mapping->status)!=='online' || !$mapping->last_seen_at || $mapping->last_seen_at->lt(now()->subMinutes(10))) return back()->with('onu_reboot_success',false)->with('onu_reboot_message','Reboot locked: this ONU is not confirmed Online by a recent sync (10-minute freshness limit).');
  $onuId=trim((string)$mapping->onu_id);
  if (!ctype_digit($onuId) || (int)$onuId<1 || (int)$onuId>64) return back()->with('onu_reboot_success',false)->with('onu_reboot_message','Reboot locked: ONU ID is outside the supported 1–64 range.');
  $ponRaw=trim((string)$mapping->pon_port);
  if (!preg_match('/(?:EPON0\/)?([1-4])$/i',$ponRaw,$ponMatch)) return back()->with('onu_reboot_success',false)->with('onu_reboot_message','Reboot locked: PON identity is not a verified EPON0/1–4 port.');
  $pon='0/'.$ponMatch[1];
  $lastCliCheck=OltProvisioningAudit::where('olt_device_id',$device->id)->where('action','cli_health_check')->latest('id')->first();
  if (!$lastCliCheck || $lastCliCheck->status!=='success' || !$lastCliCheck->created_at || $lastCliCheck->created_at->lt(now()->subMinutes(15))) return back()->with('onu_reboot_success',false)->with('onu_reboot_message','Reboot locked: run a successful Test SSH CLI Connection from OLT Diagnostics within the last 15 minutes first.');

  $profileKey='v1601e04_dp_v1_0_2r';
  $profile=config('olt_vsol.profiles.'.$profileKey);
  if (!is_array($profile) || !in_array(trim((string)$device->model),(array)($profile['models']??[]),true) || !isset($profile['commands']['reboot_onu'])) return back()->with('onu_reboot_success',false)->with('onu_reboot_message','Reboot locked: exact OLT reboot command profile is not configured.');

  $audit=OltProvisioningAudit::create([
   'olt_device_id'=>$device->id,'user_id'=>auth()->id(),'action'=>'reboot_onu',
   'target'=>'PON '.$pon.' / ONU '.$onuId,'model_profile'=>$profileKey,'transport'=>'ssh',
   'request_payload'=>['mapping_id'=>$mapping->id,'pon'=>$pon,'onu_id'=>$onuId,'onu_mac'=>$mapping->onu_mac,'onu_serial'=>$mapping->onu_serial,'confirmation'=>'REBOOT'],
   'status'=>'pending','source_ip'=>$request->ip()
  ]);
  $start=microtime(true);
  try {
   $raw=$device->getRawOriginal('adapter_config');
   $cfg=is_array($raw)?$raw:(json_decode((string)$raw,true)?:[]);
   $cfg['vsol_model_profile']=$profileKey;
   $cfg['commands']=$profile['commands'];
   $cfg['_validated_firmware']=strtoupper(trim((string)$device->firmware));
   $device->setAttribute('adapter_config',$cfg); // transient only; never overwrite inventory credentials/config here
   $service=new VsolCliProvisioningService();
   $service->connect($device);
   $output=$service->rebootOnu($pon,$onuId);
   $audit->update(['status'=>'success','result'=>$this->redact($output),'duration_ms'=>(int)round((microtime(true)-$start)*1000)]);
   return back()->with('onu_reboot_success',true)->with('onu_reboot_message','ONU reboot command was sent to PON '.$pon.', ONU '.$onuId.'. This records CLI command acceptance only; confirm the ONU re-registers on the next ONU sync. Audit #'.$audit->id.'.');
  } catch(Throwable $e) {
   $audit->update(['status'=>'failed','error'=>$e->getMessage(),'duration_ms'=>(int)round((microtime(true)-$start)*1000)]);
   return back()->with('onu_reboot_success',false)->with('onu_reboot_message','ONU reboot was not confirmed: '.$e->getMessage().' Audit #'.$audit->id.'.');
  }
 }

 public function execute(Request $request, NetworkInventoryDevice $device){
  abort_unless(hasAccess(['Super Admin'], ['mikrotik-setup']),403,'Only Super Admin can execute OLT provisioning commands.');
  abort_unless($device->type==='olt',404); $profiles=config('olt_vsol.profiles',[]); $profileKey=(string)$request->input('profile'); abort_unless(isset($profiles[$profileKey]),422,'Unknown VSOL model profile.'); $profile=$profiles[$profileKey];
  $action=(string)$request->input('action'); $rules=['profile'=>'required|string','action'=>'required|in:authorize_mac,remove_mac,configure_pppoe,configure_static_ip,disable_onu,enable_onu'];
  if(in_array($action,['authorize_mac','remove_mac'],true))$rules+=['pon'=>'required|regex:/^\d+\/\d+$/','mac'=>'required|regex:/^(?:[0-9a-f]{2}:){5}[0-9a-f]{2}$/i'];
  if(in_array($action,['configure_pppoe','configure_static_ip','disable_onu','enable_onu'],true))$rules+=['pon'=>'required|regex:/^\d+\/\d+$/','onu'=>'required|integer|min:1|max:65535'];
  if($action==='configure_pppoe')$rules+=['username'=>'required|string|max:128','password'=>'required|string|max:128','server'=>'nullable|string|max:128'];
  if($action==='configure_static_ip')$rules+=['ip'=>'required|ip','netmask'=>'required|ip','gateway'=>'required|ip','dns1'=>'required|ip','dns2'=>'required|ip'];
  $data=$request->validate($rules); $audit=OltProvisioningAudit::create(['olt_device_id'=>$device->id,'user_id'=>auth()->id(),'action'=>$action,'target'=>($data['pon']??'').'/'.($data['onu']??($data['mac']??'')),'model_profile'=>$profileKey,'transport'=>$profile['transport']??'ssh','request_payload'=>array_diff_key($data,['password'=>true]),'status'=>'pending','source_ip'=>$request->ip()]); $start=microtime(true);
  $model=trim((string)$device->model);
  if ($model==='' || !collect($profile['models'] ?? [])->contains(fn($m)=>strcasecmp(trim($m),$model)===0)) {
   $message='OLT model must be set and exactly match the selected VSOL command profile. No command was sent.';
   $audit->update(['status'=>'failed','error'=>$message,'duration_ms'=>0]);
   return back()->withErrors(['provisioning'=>$message]);
  }
  try{ $cfg=$this->adapter($device); $cfg['vsol_model_profile']=$profileKey; $cfg['commands']=$profile['commands']; $cfg['write_transport']=$profile['transport']??'ssh'; $device->adapter_config=$cfg; $s=new VsolCliProvisioningService(); $s->connect($device);
   $result=match($action){'authorize_mac'=>$s->authorizeByMac($data['pon'],$data['mac']),'remove_mac'=>$s->removeByMac($data['pon'],$data['mac']),'configure_pppoe'=>$s->configurePppoe($data['pon'],$data['onu'],$data['username'],$data['password'],$data['server']??''),'configure_static_ip'=>$s->configureStaticIp($data['pon'],$data['onu'],$data['ip'],$data['netmask'],$data['gateway'],$data['dns1'],$data['dns2']),'disable_onu'=>$s->disableOnu($data['pon'],$data['onu']),'enable_onu'=>$s->enableOnu($data['pon'],$data['onu'])};
   $audit->update(['status'=>'success','result'=>$this->redact((string)$result),'duration_ms'=>(int)round((microtime(true)-$start)*1000)]); return back()->with('provisioning_message','Provisioning command completed. Audit ID #'.$audit->id);
  }catch(Throwable $e){$audit->update(['status'=>'failed','error'=>$e->getMessage(),'duration_ms'=>(int)round((microtime(true)-$start)*1000)]); return back()->withErrors(['provisioning'=>$e->getMessage()]);}
 }
 private function adapter(NetworkInventoryDevice $d):array{$r=$d->adapter_config;if(is_array($r))return $r;$v=json_decode((string)$r,true);return is_array($v)?$v:[];}
 private function redact(string $s):string{return preg_replace('/(pwd\s+|password[=: ]+)[^\s;]+/i','$1[REDACTED]',$s);}
}