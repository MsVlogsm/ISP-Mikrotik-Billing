<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\BandwidthInvoice; use App\Models\BandwidthReseller; use App\Models\BandwidthService; use App\Models\BandwidthTicket; use App\Models\BandwidthProvider; use App\Models\BandwidthTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
class BandwidthResellerController extends Controller {
 private function guard(string $p): void {abort_unless(hasAccess(['Super Admin'],[$p]),403);}
 private function view(string $mode,array $data=[]){return view('bandwidth-reseller.index',array_merge(compact('mode'),$data));}
 public function index(Request $r){$this->guard('bandwidth-reseller');$q=BandwidthReseller::withCount('services','tickets');if($r->q)$q->where(fn($x)=>$x->where('company','like','%'.$r->q.'%')->orWhere('code','like','%'.$r->q.'%')->orWhere('contact_name','like','%'.$r->q.'%'));if($r->status)$q->where('status',$r->status);$resellers=$q->orderBy('company')->paginate(25)->withQueryString();return $this->view('resellers',compact('resellers'));}
 public function store(Request $r){$this->guard('bandwidth-reseller');$d=$r->validate(['code'=>'required|max:40|unique:bandwidth_resellers,code','company'=>'required|max:255','contact_name'=>'required|max:255','email'=>'nullable|email','phone'=>'required|max:30','username'=>'nullable|max:120','monthly_due'=>'required|numeric|min:0','credit_balance'=>'required|numeric|min:0','billing_day'=>'required|integer|min:1|max:28','status'=>'required|in:active,suspended','notes'=>'nullable']);BandwidthReseller::create($d);return back()->with('success','Bandwidth reseller added.');}
 public function update(Request $r,BandwidthReseller $bandwidthReseller){$this->guard('bandwidth-reseller');$d=$r->validate(['code'=>'required|max:40|unique:bandwidth_resellers,code,'.$bandwidthReseller->id,'company'=>'required|max:255','contact_name'=>'required|max:255','email'=>'nullable|email','phone'=>'required|max:30','username'=>'nullable|max:120','monthly_due'=>'required|numeric|min:0','credit_balance'=>'required|numeric|min:0','billing_day'=>'required|integer|min:1|max:28','status'=>'required|in:active,suspended','notes'=>'nullable']);$bandwidthReseller->update($d);return back()->with('success','Bandwidth reseller updated.');}
 public function destroy(BandwidthReseller $bandwidthReseller){$this->guard('bandwidth-reseller');$bandwidthReseller->delete();return back()->with('success','Bandwidth reseller removed.');}
 public function services(){ $this->guard('bandwidth-services');$services=BandwidthService::with('reseller')->latest()->paginate(25);$resellers=BandwidthReseller::orderBy('company')->get();return $this->view('services',compact('services','resellers'));}
 public function storeService(Request $r){$this->guard('bandwidth-services');BandwidthService::create($r->validate(['bandwidth_reseller_id'=>'required|exists:bandwidth_resellers,id','name'=>'required|max:255','service_type'=>'required|max:80','monthly_charge'=>'required|numeric|min:0','status'=>'required|in:active,disabled','notes'=>'nullable']));return back()->with('success','Service added.');}
 public function toggleService(BandwidthService $service){$this->guard('bandwidth-services');$service->update(['status'=>$service->status==='active'?'disabled':'active']);return back();}
 public function invoices(Request $r){$this->guard('bandwidth-billing');$q=BandwidthInvoice::with('reseller')->latest('billing_month');if($r->reseller_id)$q->where('bandwidth_reseller_id',$r->reseller_id);if($r->status)$q->where('status',$r->status);$invoices=$q->paginate(25)->withQueryString();$resellers=BandwidthReseller::orderBy('company')->get();return $this->view('invoices',compact('invoices','resellers'));}
 public function storeInvoice(Request $r){$this->guard('bandwidth-billing');$d=$r->validate(['bandwidth_reseller_id'=>'required|exists:bandwidth_resellers,id','billing_month'=>'required|date','due_date'=>'required|date','total_amount'=>'required|numeric|min:0.01','notes'=>'nullable']);$d['invoice_no']='BW-'.now()->format('Ym').'-'.str_pad((string)(BandwidthInvoice::max('id')+1),5,'0',STR_PAD_LEFT);BandwidthInvoice::create($d);return back()->with('success','Invoice created.');}
 public function payInvoice(Request $r,BandwidthInvoice $invoice){$this->guard('bandwidth-billing');$a=$r->validate(['amount'=>'required|numeric|min:0.01'])['amount'];$invoice->paid_amount=min((float)$invoice->total_amount,(float)$invoice->paid_amount+(float)$a);$invoice->status=$invoice->paid_amount>=(float)$invoice->total_amount?'paid':($invoice->paid_amount>0?'partial':'unpaid');$invoice->save();return back()->with('success','Invoice payment recorded.');}
 public function trading(Request $r){
  $this->guard('bandwidth-billing');
  $q=BandwidthTransaction::with(['provider','reseller'])->latest('transaction_date')->latest('id');
  if($r->direction && in_array($r->direction,['purchase','sale'],true))$q->where('direction',$r->direction);
  $transactions=$q->paginate(30)->withQueryString();
  $providers=BandwidthProvider::where('status','active')->orderBy('name')->get();
  $resellers=BandwidthReseller::where('status','active')->orderBy('company')->get();
  $summary=[
   'purchase'=>BandwidthTransaction::where('direction','purchase')->sum('total_amount'),
   'sale'=>BandwidthTransaction::where('direction','sale')->sum('total_amount'),
   'purchase_due'=>DB::table('bandwidth_transactions')->where('direction','purchase')->sum(DB::raw('total_amount-paid_amount')),
   'sale_due'=>DB::table('bandwidth_transactions')->where('direction','sale')->sum(DB::raw('total_amount-paid_amount')),
  ];
  return $this->view('trading',compact('transactions','providers','resellers','summary'));
 }
 public function storeProvider(Request $r){
  $this->guard('bandwidth-billing');
  BandwidthProvider::create($r->validate(['code'=>'required|max:40|unique:bandwidth_providers,code','name'=>'required|max:255','contact_name'=>'nullable|max:255','phone'=>'nullable|max:40','email'=>'nullable|email|max:255','notes'=>'nullable']));
  return back()->with('success','Upstream bandwidth provider added.');
 }
 public function storeTrading(Request $r){
  $this->guard('bandwidth-billing');
  $d=$r->validate([
   'direction'=>'required|in:purchase,sale',
   'provider_id'=>['nullable','required_if:direction,purchase','prohibited_unless:direction,purchase','exists:bandwidth_providers,id'],
   'bandwidth_reseller_id'=>['nullable','required_if:direction,sale','prohibited_unless:direction,sale','exists:bandwidth_resellers,id'],
   'reference'=>'nullable|max:100','bandwidth_mbps'=>'required|numeric|gt:0','unit_rate'=>'required|numeric|min:0',
   'transaction_date'=>'required|date','billing_period'=>'nullable|max:30','paid_amount'=>'required|numeric|min:0',
   'notes'=>'nullable|max:5000',
  ]);
  $d['total_amount']=round((float)$d['bandwidth_mbps']*(float)$d['unit_rate'],2);
  abort_if($d['total_amount']<=0,422,'Transaction total must be greater than zero.');
  abort_if((float)$d['paid_amount']>(float)$d['total_amount'],422,'Paid amount cannot exceed transaction total.');
  $d['status']=(float)$d['paid_amount'] >= (float)$d['total_amount'] ? 'paid' : ((float)$d['paid_amount']>0 ? 'partial' : 'unpaid');
  BandwidthTransaction::create($d);
  return back()->with('success',ucfirst($d['direction']).' bandwidth transaction recorded.');
 }
 public function payTrading(Request $r,BandwidthTransaction $transaction){
  $this->guard('bandwidth-billing');
  $amount=(float)$r->validate(['amount'=>'required|numeric|gt:0'])['amount'];
  DB::transaction(function()use($transaction,$amount){
   $locked=BandwidthTransaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();
   abort_if($amount > ((float)$locked->total_amount-(float)$locked->paid_amount)+0.0001,422,'Payment exceeds outstanding balance.');
   $locked->paid_amount=round((float)$locked->paid_amount+$amount,2);
   $locked->status=$locked->paid_amount >= (float)$locked->total_amount ? 'paid' : 'partial';
   $locked->save();
  });
  return back()->with('success','Bandwidth transaction payment recorded.');
 }

 public function tickets(Request $r){$this->guard('bandwidth-support');$q=BandwidthTicket::with('reseller')->latest();if($r->status)$q->where('status',$r->status);if($r->q)$q->where('subject','like','%'.$r->q.'%');$tickets=$q->paginate(25)->withQueryString();$resellers=BandwidthReseller::orderBy('company')->get();return $this->view('tickets',compact('tickets','resellers'));}
 public function storeTicket(Request $r){$this->guard('bandwidth-support');$d=$r->validate(['bandwidth_reseller_id'=>'required|exists:bandwidth_resellers,id','subject'=>'required|max:255','description'=>'required','priority'=>'required|in:low,normal,high,urgent','status'=>'required|in:new,open,pending,closed','assigned_to'=>'nullable|max:255']);$d['ticket_no']=BandwidthTicket::nextNo();BandwidthTicket::create($d);return back()->with('success','Support ticket created.');}
 public function updateTicket(Request $r,BandwidthTicket $ticket){$this->guard('bandwidth-support');$ticket->update($r->validate(['status'=>'required|in:new,open,pending,closed','priority'=>'required|in:low,normal,high,urgent','assigned_to'=>'nullable|max:255']));return back()->with('success','Ticket updated.');}
}