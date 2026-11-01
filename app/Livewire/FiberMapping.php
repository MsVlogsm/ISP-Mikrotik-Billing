<?php

namespace App\Livewire;

use App\Models\CustomersInfo;
use App\Models\NetworkInventoryDevice;
use App\Models\OltOnuCustomerMapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithFileUploads;

class FiberMapping extends Component
{
    use WithFileUploads;

    public string $tab = 'cables';
    public string $cable_id = '', $route_name = '', $start_location = '', $end_location = '', $start_latitude = '', $start_longitude = '', $end_latitude = '', $end_longitude = '', $core_count = '', $color_standard = 'TIA-598', $length_m = '', $installed_at = '', $manufacturer = '';
    public string $olt_device_id = '', $pon_slot = '', $pon_port = '', $port_description = '', $uplink_cable_id = '';
    public string $closure_id = '', $closure_location = '', $closure_latitude = '', $closure_longitude = '', $tray_reference = '';
    public string $source_cable_id = '', $source_core = '', $target_cable_id = '', $target_core = '', $tray_port = '';
    public string $splitter_id = '', $splitter_location = '', $splitter_latitude = '', $splitter_longitude = '', $ratio = '', $input_cable_id = '', $input_core = '', $input_port = '';
    public string $splitter_ref = '', $output_port = '', $next_splitter_id = '', $output_cable_id = '', $output_core = '';
    public string $customer_id = '', $latitude = '', $longitude = '', $location_note = '';
    public string $onu_mapping_id = '', $splitter_port_id = '', $onu_mac = '', $onu_serial = '', $pppoe_user_id = '', $verification_note = '';
    public string $trace_customer_id = '', $traceResult = '';
    public string $message = '';
    public $csvFile;

    public function mount(): void
    {
        if (!hasAccess(['Super Admin'], ['mikrotik-setup', 'network-inventory'])) abort(403);
    }

    private function audit(string $type, int $id, string $action, ?array $before, ?array $after, ?string $note = null): void
    {
        DB::table('fiber_mapping_audits')->insert([
            'entity_type'=>$type, 'entity_id'=>$id, 'action'=>$action,
            'before_data'=>$before ? json_encode($before) : null, 'after_data'=>$after ? json_encode($after) : null,
            'note'=>$note, 'operator_id'=>auth()->id(), 'created_at'=>now(), 'updated_at'=>now(),
        ]);
    }

    public function saveCable(): void
    {
        $d=$this->validate(['cable_id'=>'required|string|max:80|unique:fiber_cables,cable_id','route_name'=>'required|string|max:160','start_location'=>'nullable|string|max:190','end_location'=>'nullable|string|max:190','start_latitude'=>'nullable|numeric|between:-90,90','start_longitude'=>'nullable|numeric|between:-180,180','end_latitude'=>'nullable|numeric|between:-90,90','end_longitude'=>'nullable|numeric|between:-180,180','core_count'=>['required',Rule::in([4,6,12,24,48,96,144])],'color_standard'=>'required|string|max:60','length_m'=>'nullable|numeric|min:0.01','installed_at'=>'nullable|date','manufacturer'=>'nullable|string|max:120']);
        $id=DB::table('fiber_cables')->insertGetId($d+['status'=>'pending','created_by'=>auth()->id(),'created_at'=>now(),'updated_at'=>now()]);
        $this->audit('cable',$id,'created',null,$d); $this->reset(['cable_id','route_name','start_location','end_location','start_latitude','start_longitude','end_latitude','end_longitude','core_count','length_m','installed_at','manufacturer']); $this->message='Fiber cable saved as Pending verification.';
    }

    public function saveOltPon(): void
    {
        $d=$this->validate(['olt_device_id'=>'required|exists:network_inventory_devices,id','pon_slot'=>'required|string|max:40','pon_port'=>'required|string|max:40','port_description'=>'nullable|string|max:190','uplink_cable_id'=>'nullable|exists:fiber_cables,id']);
        $id=DB::table('fiber_olt_pon_mappings')->insertGetId($d+['status'=>'pending','created_by'=>auth()->id(),'created_at'=>now(),'updated_at'=>now()]);
        $this->audit('olt_pon',$id,'created',null,$d); $this->message='OLT/PON mapping saved as Pending verification.';
    }

    public function saveClosure(): void
    {
        $d=$this->validate(['closure_id'=>'required|string|max:80|unique:fiber_splice_closures,closure_id','closure_location'=>'nullable|string|max:190','closure_latitude'=>'nullable|numeric|between:-90,90','closure_longitude'=>'nullable|numeric|between:-180,180','tray_reference'=>'nullable|string|max:80']);
        $row=['closure_id'=>$d['closure_id'],'location'=>$d['closure_location']?:null,'latitude'=>$d['closure_latitude']?:null,'longitude'=>$d['closure_longitude']?:null,'tray_reference'=>$d['tray_reference']?:null,'created_by'=>auth()->id(),'created_at'=>now(),'updated_at'=>now()];
        $id=DB::table('fiber_splice_closures')->insertGetId($row); $this->audit('splice_closure',$id,'created',null,$row); $this->message='Splice closure saved.';
    }

    public function saveSplice(): void
    {
        $d=$this->validate(['closure_id'=>'required|exists:fiber_splice_closures,id','source_cable_id'=>'required|exists:fiber_cables,id','source_core'=>'required|integer|min:1','target_cable_id'=>'required|exists:fiber_cables,id|different:source_cable_id','target_core'=>'required|integer|min:1','tray_port'=>'nullable|string|max:80']);
        foreach ([['fiber_cables',$d['source_cable_id'],$d['source_core']],['fiber_cables',$d['target_cable_id'],$d['target_core']]] as [$table,$cid,$core]) { $c=DB::table($table)->find($cid); if(!$c || $core>$c->core_count){$this->addError('source_core','Core number exceeds the selected cable core count.');return;} }
        $conflict=DB::table('fiber_splice_records')->where(function($q)use($d){$q->where('source_cable_id',$d['source_cable_id'])->where('source_core',$d['source_core']);})->orWhere(function($q)use($d){$q->where('target_cable_id',$d['source_cable_id'])->where('target_core',$d['source_core']);})->orWhere(function($q)use($d){$q->where('source_cable_id',$d['target_cable_id'])->where('source_core',$d['target_core']);})->orWhere(function($q)use($d){$q->where('target_cable_id',$d['target_cable_id'])->where('target_core',$d['target_core']);})->exists();
        if($conflict){$this->addError('source_core','One of these cable cores is already assigned to another splice. Review the existing physical connection.');return;}
        $id=DB::table('fiber_splice_records')->insertGetId($d+['status'=>'pending','created_by'=>auth()->id(),'created_at'=>now(),'updated_at'=>now()]); $this->audit('splice',$id,'created',null,$d); $this->message='Splice saved as Pending verification.';
    }

    public function saveSplitter(): void
    {
        $d=$this->validate(['splitter_id'=>'required|string|max:80|unique:fiber_splitters,splitter_id','splitter_location'=>'nullable|string|max:190','splitter_latitude'=>'nullable|numeric|between:-90,90','splitter_longitude'=>'nullable|numeric|between:-180,180','ratio'=>['required',Rule::in(['1:4','1:8','1:16','1:32','1:64'])],'input_cable_id'=>'nullable|exists:fiber_cables,id','input_core'=>'nullable|integer|min:1','input_port'=>'nullable|string|max:40']);
        if($d['input_cable_id']){$c=DB::table('fiber_cables')->find($d['input_cable_id']);if(!$c||$d['input_core']>$c->core_count){$this->addError('input_core','Input core exceeds the selected cable core count.');return;}}
        $row=['splitter_id'=>$d['splitter_id'],'location'=>$d['splitter_location']?:null,'latitude'=>$d['splitter_latitude']?:null,'longitude'=>$d['splitter_longitude']?:null,'ratio'=>$d['ratio'],'input_cable_id'=>$d['input_cable_id']?:null,'input_core'=>$d['input_core']?:null,'input_port'=>$d['input_port']?:null,'status'=>'pending','created_by'=>auth()->id(),'created_at'=>now(),'updated_at'=>now()];
        $id=DB::table('fiber_splitters')->insertGetId($row); $this->audit('splitter',$id,'created',null,$row); $this->message='Splitter saved. Output ports must be entered manually.';
    }

    public function saveSplitterPort(): void
    {
        $d=$this->validate(['splitter_ref'=>'required|exists:fiber_splitters,id','output_port'=>'required|integer|min:1|max:64','next_splitter_id'=>'nullable|exists:fiber_splitters,id','output_cable_id'=>'nullable|exists:fiber_cables,id','output_core'=>'nullable|integer|min:1']);
        $sp=DB::table('fiber_splitters')->find($d['splitter_ref']);$limit=(int)substr($sp->ratio,strpos($sp->ratio,':')+1);
        if($d['output_port']>$limit){$this->addError('output_port','Output port exceeds the selected splitter ratio.');return;}
        if($d['next_splitter_id'] && $d['output_cable_id']){$this->addError('next_splitter_id','Choose either a next splitter or an output cable, not both.');return;}
        if($d['output_cable_id']){$c=DB::table('fiber_cables')->find($d['output_cable_id']);if(!$c||$d['output_core']>$c->core_count){$this->addError('output_core','Output core exceeds cable core count.');return;}}
        $row=['splitter_id'=>$d['splitter_ref'],'output_port'=>$d['output_port'],'next_splitter_id'=>$d['next_splitter_id']?:null,'output_cable_id'=>$d['output_cable_id']?:null,'output_core'=>$d['output_core']?:null,'status'=>'pending','created_by'=>auth()->id(),'created_at'=>now(),'updated_at'=>now()];
        $id=DB::table('fiber_splitter_ports')->insertGetId($row);$this->audit('splitter_port',$id,'created',null,$row);$this->message='Splitter output port saved as Pending verification.';
    }

    public function saveCustomerLocation(): void
    {
        $d=$this->validate(['customer_id'=>'required|exists:customers_infos,id','latitude'=>'required|numeric|between:-90,90','longitude'=>'required|numeric|between:-180,180','location_note'=>'nullable|string|max:190']);
        $before=DB::table('fiber_customer_locations')->where('customer_id',$d['customer_id'])->first();
        $row=['latitude'=>$d['latitude'],'longitude'=>$d['longitude'],'location_note'=>$d['location_note']?:null,'status'=>'pending','created_by'=>auth()->id(),'verified_by'=>null,'verified_at'=>null,'updated_at'=>now()];
        if($before){DB::table('fiber_customer_locations')->where('id',$before->id)->update($row);$id=$before->id;}else{$id=DB::table('fiber_customer_locations')->insertGetId($row+['customer_id'=>$d['customer_id'],'created_at'=>now()]);}
        $this->audit('customer_location',$id,$before?'updated':'created',$before?(array)$before:null,$row);$this->message='Customer GPS saved as Pending location verification.';
    }

    public function saveCustomerMapping(): void
    {
        $d=$this->validate(['onu_mapping_id'=>'nullable|exists:olt_onu_customer_mappings,id','customer_id'=>'required|exists:customers_infos,id','splitter_port_id'=>'required|exists:fiber_splitter_ports,id','onu_mac'=>'nullable|string|max:40','onu_serial'=>'nullable|string|max:100','pppoe_user_id'=>'nullable|string|max:120','verification_note'=>'nullable|string|max:1000']);
        if(DB::table('fiber_customer_mappings')->where('splitter_port_id',$d['splitter_port_id'])->exists()){$this->addError('splitter_port_id','This splitter output is already assigned.');return;}
        if($d['onu_mapping_id']){$onu=OltOnuCustomerMapping::findOrFail($d['onu_mapping_id']); if($onu->customer_id && (int)$onu->customer_id !== (int)$d['customer_id']){$this->addError('customer_id','Selected ONU ledger is already linked to a different customer.');return;} if($d['onu_mac'] && $onu->onu_mac && strtoupper($d['onu_mac'])!==strtoupper($onu->onu_mac)){$this->addError('onu_mac','Entered MAC does not match the selected ONU ledger record.');return;} if($d['onu_serial'] && $onu->onu_serial && strtoupper($d['onu_serial'])!==strtoupper($onu->onu_serial)){$this->addError('onu_serial','Entered serial does not match the selected ONU ledger record.');return;} $d['onu_mac']=$d['onu_mac'] ?: $onu->onu_mac; $d['onu_serial']=$d['onu_serial'] ?: $onu->onu_serial;}
        if(!$d['onu_mac'] && !$d['onu_serial']){$this->addError('onu_mac','Enter a real ONU MAC or serial number.');return;}
        if($d['onu_mac'] && DB::table('fiber_customer_mappings')->where('onu_mac',$d['onu_mac'])->exists()){$this->addError('onu_mac','This ONU MAC is already mapped.');return;}
        if($d['onu_serial'] && DB::table('fiber_customer_mappings')->where('onu_serial',$d['onu_serial'])->exists()){$this->addError('onu_serial','This ONU serial is already mapped.');return;}
        $customer=CustomersInfo::with('pppUser')->findOrFail($d['customer_id']);
        if($d['pppoe_user_id'] && $customer->pppUser && $customer->pppUser->username && strcasecmp($d['pppoe_user_id'],$customer->pppUser->username)!==0){$this->addError('pppoe_user_id','PPPoE User ID does not match the selected customer record.');return;}
        $row=['onu_mapping_id'=>$d['onu_mapping_id']?:null,'customer_id'=>$d['customer_id'],'splitter_port_id'=>$d['splitter_port_id'],'onu_mac'=>$d['onu_mac']?:null,'onu_serial'=>$d['onu_serial']?:null,'pppoe_user_id'=>$d['pppoe_user_id']?:null,'verification_note'=>$d['verification_note']?:null,'status'=>'pending','created_by'=>auth()->id(),'created_at'=>now(),'updated_at'=>now()];
        $id=DB::table('fiber_customer_mappings')->insertGetId($row);$this->audit('customer_mapping',$id,'created',null,$row);$this->message='ONU/Customer mapping saved as Pending. No automatic matching was performed.';
    }

    public function verify(string $type,int $id): void
    {
        $tables=['cable'=>'fiber_cables','olt_pon'=>'fiber_olt_pon_mappings','splice'=>'fiber_splice_records','splitter'=>'fiber_splitters','splitter_port'=>'fiber_splitter_ports','customer_location'=>'fiber_customer_locations','customer_mapping'=>'fiber_customer_mappings'];
        abort_unless(isset($tables[$type]),404);$table=$tables[$type];$row=DB::table($table)->find($id);abort_unless($row,404);
        if($type==='customer_location' && (!is_numeric($row->latitude)||!is_numeric($row->longitude))){$this->message='GPS coordinates are incomplete.';return;}
        if($type==='olt_pon' && (!$row->uplink_cable_id || DB::table('fiber_cables')->where('id',$row->uplink_cable_id)->where('status','verified')->doesntExist())){$this->message='Select an already verified uplink cable before verifying this PON mapping.';return;}
        if($type==='splice'){$source=DB::table('fiber_cables')->find($row->source_cable_id);$target=DB::table('fiber_cables')->find($row->target_cable_id);if(!$source||!$target||$source->status!=='verified'||$target->status!=='verified'){$this->message='Both source and target cables must be verified before verifying the splice.';return;}}
        if($type==='splitter' && (!$row->input_cable_id || !$row->input_core)){$this->message='Record the real input cable and core before verifying this splitter.';return;}
        if($type==='splitter_port') {if(!$row->next_splitter_id && !$row->output_cable_id){$this->message='Output must be manually mapped to a next splitter or cable before verification.';return;} if($row->output_cable_id){$c=DB::table('fiber_cables')->find($row->output_cable_id);if(!$c||$c->status!=='verified'){$this->message='Output cable must be verified first.';return;}}if($row->next_splitter_id){$s=DB::table('fiber_splitters')->find($row->next_splitter_id);if(!$s||$s->status!=='verified'){$this->message='Next splitter must be verified first.';return;}}}
        if($type==='customer_mapping') {if(!$row->onu_mac && !$row->onu_serial){$this->message='ONU MAC or Serial is required before verification.';return;} $port=DB::table('fiber_splitter_ports')->find($row->splitter_port_id);$splitter=$port?DB::table('fiber_splitters')->find($port->splitter_id):null;$gps=DB::table('fiber_customer_locations')->where('customer_id',$row->customer_id)->where('status','verified')->exists();if(!$port||$port->status!=='verified'||!$splitter||$splitter->status!=='verified'){$this->message='Splitter and selected output port must both be verified first.';return;}if(!$gps){$this->message='Customer GPS must be entered and verified before final ONU mapping verification.';return;}}
        $before=(array)$row;DB::table($table)->where('id',$id)->update(['status'=>'verified','verified_by'=>auth()->id(),'verified_at'=>now(),'updated_at'=>now()]);$after=(array)DB::table($table)->find($id);$this->audit($type,$id,'verified',$before,$after);$this->message='Record verified by operator.';
    }

    public function traceCustomer(): void
    {
        $this->validate(['trace_customer_id'=>'required|exists:customers_infos,id']);
        $m=DB::table('fiber_customer_mappings as m')->join('fiber_splitter_ports as p','p.id','=','m.splitter_port_id')->join('fiber_splitters as s','s.id','=','p.splitter_id')->where('m.customer_id',$this->trace_customer_id)->select('m.*','p.output_port','p.next_splitter_id','p.output_cable_id','p.output_core','s.splitter_id as splitter_code','s.input_cable_id','s.input_core as splitter_input_core','s.ratio')->first();
        if(!$m){$this->traceResult='Incomplete — no manual ONU/Customer to splitter-port mapping exists.';return;}
        $parts=['Customer ID '.$this->trace_customer_id,'Splitter '.$m->splitter_code.' / OUT-'.$m->output_port];$complete=$m->status==='verified';$splitter=$m;
        $seen=[];while($splitter){if(isset($seen[$splitter->splitter_code])){$complete=false;$parts[]='Loop detected at splitter '.$splitter->splitter_code;break;}$seen[$splitter->splitter_code]=true;
            if($splitter->next_splitter_id){$next=DB::table('fiber_splitters')->find($splitter->next_splitter_id);if(!$next){$complete=false;$parts[]='Missing next splitter record';break;}$parts[]='Next splitter '.$next->splitter_id;$complete=$complete&&$next->status==='verified';$splitter=$next;continue;}
            $cableId=$splitter->output_cable_id ?: $splitter->input_cable_id;$core=$splitter->output_core ?: $splitter->splitter_input_core;if(!$cableId||!$core){$complete=false;$parts[]='Missing manually recorded cable/core';break;}
            $cable=DB::table('fiber_cables')->find($cableId);if(!$cable){$complete=false;$parts[]='Cable record missing';break;}$parts[]='Cable '.$cable->cable_id.' · Core '.$core.' ('.$this->coreColour((int)$core,$cable->color_standard).')';$complete=$complete&&$cable->status==='verified';
            $splice=DB::table('fiber_splice_records')->where(function($q)use($cableId,$core){$q->where('source_cable_id',$cableId)->where('source_core',$core);})->orWhere(function($q)use($cableId,$core){$q->where('target_cable_id',$cableId)->where('target_core',$core);})->first();
            if(!$splice){$complete=false;$parts[]='Missing splice / upstream connection';break;}$complete=$complete&&$splice->status==='verified';$otherCable=$splice->source_cable_id==$cableId?$splice->target_cable_id:$splice->source_cable_id;$otherCore=$splice->source_cable_id==$cableId?$splice->target_core:$splice->source_core;$oc=DB::table('fiber_cables')->find($otherCable);if(!$oc){$complete=false;$parts[]='Splice target cable missing';break;}$parts[]='Splice closure '.(DB::table('fiber_splice_closures')->where('id',$splice->closure_id)->value('closure_id')?:'unknown').' → Cable '.$oc->cable_id.' Core '.$otherCore.' ('.$this->coreColour((int)$otherCore,$oc->color_standard).')';
            $pon=DB::table('fiber_olt_pon_mappings')->where('uplink_cable_id',$otherCable)->first();if(!$pon){$complete=false;$parts[]='OLT/PON uplink mapping missing';break;}$parts[]='OLT '.$pon->olt_device_id.' / Slot '.$pon->pon_slot.' / PON '.$pon->pon_port;$complete=$complete&&$pon->status==='verified';break;
        }
        $this->traceResult=($complete?'Verified':'Incomplete').' — '.implode(' → ',$parts);
    }

    private function coreColour(int $core,string $standard): string
    {
        if(strtoupper($standard)!=='TIA-598')return 'manufacturer colour not supplied';$colors=['Blue','Orange','Green','Brown','Slate','White','Red','Black','Yellow','Violet','Rose','Aqua'];return $colors[($core-1)%12]??'unknown';
    }

    public function exportCsv()
    {
        $rows=[];$headers=['record_type'];foreach(['fiber_cables','fiber_olt_pon_mappings','fiber_splice_closures','fiber_splice_records','fiber_splitters','fiber_splitter_ports','fiber_customer_locations','fiber_customer_mappings'] as $table){foreach(DB::table($table)->get() as $row){$data=['record_type'=>$table]+(array)$row;$rows[]=$data;$headers=array_values(array_unique(array_merge($headers,array_keys($data))));}}
        return response()->streamDownload(function()use($rows,$headers){$out=fopen('php://output','w');fputcsv($out,$headers);foreach($rows as $row){$line=[];foreach($headers as $key)$line[]=$row[$key]??'';fputcsv($out,$line);}fclose($out);},'xlink-fiber-mapping-'.now()->format('Ymd-His').'.csv',['Content-Type'=>'text/csv']);
    }

    public function importCablesCsv(): void
    {
        $this->validate(['csvFile'=>'required|file|mimes:csv,txt|max:10240']);$path=$this->csvFile->getRealPath();$h=fopen($path,'r');$header=fgetcsv($h);$required=['cable_id','route_name','core_count','color_standard'];if(!$header||array_diff($required,$header)){$this->addError('csvFile','CSV headers must include cable_id, route_name, core_count, color_standard.');fclose($h);return;}$count=0;
        while(($line=fgetcsv($h))!==false){if(count($header)!==count($line))continue;$r=array_combine($header,$line);if(empty($r['cable_id'])||empty($r['route_name'])||!in_array((int)$r['core_count'],[4,6,12,24,48,96,144],true))continue;DB::table('fiber_cables')->updateOrInsert(['cable_id'=>$r['cable_id']],['route_name'=>$r['route_name'],'core_count'=>(int)$r['core_count'],'color_standard'=>$r['color_standard'],'start_location'=>$r['start_location']??null,'end_location'=>$r['end_location']??null,'length_m'=>is_numeric($r['length_m']??null)?$r['length_m']:null,'installed_at'=>$r['installed_at']??null,'status'=>'pending','created_by'=>auth()->id(),'updated_at'=>now(),'created_at'=>now()]);$count++;}
        fclose($h);$this->message="Imported {$count} cable rows as Pending verification. Existing records were not auto-verified.";
    }

    public function render()
    {
        return view('livewire.fiber-mapping',[
            'customers'=>CustomersInfo::orderBy('customer_unique_id')->get(['id','customer_unique_id','customer_name','ppp_user_id']),
            'olts'=>NetworkInventoryDevice::where('type','olt')->orderBy('name')->get(['id','name']),
            'onus'=>OltOnuCustomerMapping::with('olt')->orderBy('onu_id')->get(),
            'cables'=>DB::table('fiber_cables')->orderBy('cable_id')->get(),
            'pons'=>DB::table('fiber_olt_pon_mappings')->orderBy('id','desc')->get(),
            'closures'=>DB::table('fiber_splice_closures')->orderBy('closure_id')->get(),
            'splices'=>DB::table('fiber_splice_records')->orderByDesc('id')->get(),
            'splitters'=>DB::table('fiber_splitters')->orderBy('splitter_id')->get(),
            'ports'=>DB::table('fiber_splitter_ports')->orderBy('splitter_id')->orderBy('output_port')->get(),
            'locations'=>DB::table('fiber_customer_locations')->orderByDesc('updated_at')->get(),
            'mappings'=>DB::table('fiber_customer_mappings')->orderByDesc('id')->get(),
            'audits'=>DB::table('fiber_mapping_audits')->orderByDesc('id')->limit(100)->get(),
        ])->layout('layouts.app');
    }
}
