<?php
namespace App\Services;
use App\Models\BillingInfo;
use App\Models\CustomersInfo;
use App\Models\DueCallReminderLog;
use App\Models\MainSiteData;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Throwable;
class DueCallReminderService {
 public function runBatch(bool $force=false): array {
  $enabled=(bool)MainSiteData::getValue('due_call_enabled',0); $provider=(string)MainSiteData::getValue('due_call_provider','custom'); $url=trim((string)MainSiteData::getValue('due_call_api_url','')); $storedToken=(string)MainSiteData::getValue('due_call_api_token',''); $token=''; if($storedToken!==''){try{$token=Crypt::decryptString($storedToken);}catch(\Throwable $e){$token=$storedToken;}}
  $templateId=trim((string)MainSiteData::getValue('due_call_template_id','')); $sid=trim((string)MainSiteData::getValue('due_call_twilio_sid','')); $storedAuth=(string)MainSiteData::getValue('due_call_twilio_auth_token',''); $auth=''; if($storedAuth!==''){try{$auth=Crypt::decryptString($storedAuth);}catch(\Throwable $e){$auth=$storedAuth;}} $from=trim((string)MainSiteData::getValue('due_call_twilio_from',''));
  if(!$force&&!$enabled)return ['processed'=>0,'skipped'=>0,'message'=>'Auto call is disabled.'];
  if($provider==='twilio'){if(!preg_match('/^AC[0-9a-f]{32}$/i',$sid)||$auth===''||$from==='')return ['processed'=>0,'skipped'=>0,'message'=>'Configure Twilio Account SID, Auth Token and verified caller ID first.'];}elseif($url===''||!filter_var($url,FILTER_VALIDATE_URL)||parse_url($url,PHP_URL_SCHEME)!=='https')return ['processed'=>0,'skipped'=>0,'message'=>'Configure a valid HTTPS voice-call API endpoint before enabling calls.'];elseif($token==='')return ['processed'=>0,'skipped'=>0,'message'=>'Configure the voice provider API key/token first.'];
  if($provider==='protiddhoni'&&$templateId==='')return ['processed'=>0,'skipped'=>0,'message'=>'Configure the Protiddhoni template ID before enabling or running voice reminders.'];
  $limit=max(1,min(500,(int)MainSiteData::getValue('due_call_daily_limit',50))); $template=(string)MainSiteData::getValue('due_call_message','Assalamu Alaikum {customer_name}. Your internet bill due is {due_amount} taka. Please pay at your earliest convenience.');
  $payloadTemplate=MainSiteData::getValue('due_call_payload_json','{"to":"{phone}","message":"{message}","customer_id":"{customer_id}","amount":"{due_amount}"}'); if(is_string($payloadTemplate))$payload=json_decode($payloadTemplate,true);else $payload=$payloadTemplate;
  if(!is_array($payload))return ['processed'=>0,'skipped'=>0,'message'=>'Call API JSON payload template is invalid.'];
  $today=now()->toDateString();$processed=0;$skipped=0;
  foreach(BillingInfo::where('due_amount','>',0)->with(['customer'=>fn($q)=>$q->whereNull('deleted_at')])->orderByDesc('due_amount')->limit(3000)->get() as $bill){
   if($processed >= $limit)break;$c=$bill->customer;if(!$c||!$c->mobile){$skipped++;continue;}$phone=CustomersInfo::normalizeBangladeshMobile($c->mobile);if(!preg_match('/^01[3-9]\d{8}$/',(string)$phone)){$skipped++;continue;}
   if(DueCallReminderLog::where('customer_code',$c->customer_unique_id)->whereDate('reminder_date',$today)->exists()){$skipped++;continue;}
   $due=number_format((float)$bill->due_amount,2,'.','');$company=(string)MainSiteData::getValue('site_name','X-Link LTD');$message=strtr($template,['{customer_name}'=>(string)$c->customer_name,'{customer_id}'=>(string)$c->customer_unique_id,'{phone}'=>(string)$phone,'{due_amount}'=>$due,'{company_name}'=>$company]);
   $body=$this->replaceTemplate($payload,['{customer_name}'=>(string)$c->customer_name,'{customer_id}'=>(string)$c->customer_unique_id,'{phone}'=>(string)$phone,'{mobile}'=>(string)$phone,'{template_id}'=>$templateId,'{due_amount}'=>$due,'{message}'=>$message,'{company_name}'=>$company]);if($provider==='protiddhoni'){$body['template_id']=$templateId;$body['mobile']=$phone;}
   $log=DueCallReminderLog::create(['customer_id'=>$c->id,'customer_code'=>$c->customer_unique_id,'customer_name'=>$c->customer_name,'phone'=>$phone,'due_amount'=>$bill->due_amount,'reminder_date'=>$today,'status'=>'pending']);
   try{if($provider==='twilio'){$to=$this->toE164((string)$phone);$twiml='<Response><Say language="en-US">'.htmlspecialchars($message,ENT_XML1|ENT_QUOTES,'UTF-8').'</Say></Response>';$response=Http::timeout(25)->acceptJson()->asForm()->withBasicAuth($sid,$auth)->post('https://api.twilio.com/2010-04-01/Accounts/'.$sid.'/Calls.json',['To'=>$to,'From'=>$from,'Twiml'=>$twiml]);}else{$req=Http::timeout(20)->acceptJson()->asJson();if($provider==='protiddhoni')$req=$req->withHeaders(['X-API-Key'=>$token]);else $req=$req->withToken($token);$response=$req->post($url,$body);}$data=$response->json();$ok=$response->successful()&&(!is_array($data)||!array_key_exists('success',$data)||$data['success']!==false);$callId=is_array($data)?($data['call_id']??$data['sid']??$data['id']??$data['request_id']??null):null;$log->update(['status'=>$ok?'submitted':'failed','provider_call_id'=>$callId?(string)$callId:null,'provider_response'=>Str::limit($response->body(),4000),'attempted_at'=>now()]);}
   catch(Throwable $e){$log->update(['status'=>'failed','provider_response'=>Str::limit($e->getMessage(),1000),'attempted_at'=>now()]);}$processed++;
  }
  return ['processed'=>$processed,'skipped'=>$skipped,'message'=>"Call requests submitted: {$processed}; skipped: {$skipped}. Provider status is recorded in call history."];
 }
 private function toE164(string $phone): string { $phone=preg_replace('/[^0-9+]/','',$phone); if(str_starts_with($phone,'01'))return '+880'.substr($phone,1); if(str_starts_with($phone,'880'))return '+'.$phone; return $phone; }
 private function replaceTemplate(array $v,array $vars): array {foreach($v as $k=>$item)$v[$k]=is_array($item)?$this->replaceTemplate($item,$vars):(is_string($item)?strtr($item,$vars):$item);return $v;}
}
