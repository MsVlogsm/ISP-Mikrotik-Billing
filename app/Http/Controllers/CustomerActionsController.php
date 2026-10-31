<?php

namespace App\Http\Controllers;

use App\Models\BillingInfo;
use App\Models\CollectionSummary;
use App\Models\CustomersInfo;
use App\Models\OfficialInfo;
use App\Models\PackageList;
use App\Models\Reseller;
use App\Models\RouterList;
use App\Models\SupportTicket;
use App\Models\SupportTicketTemplate;
use App\Models\SmsTemplate;
use App\Models\User;
use App\Services\PrepaidExpiryGuard;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use XLink\SmsBridge\Facades\SmsBridge;

class CustomerActionsController extends Controller
{
    private function customer(string $id): CustomersInfo
    {
        try { $id = decrypt($id); } catch (\Throwable) {}
        $customer = CustomersInfo::withTrashed()->with(['billing','pppUser','package','official','reseller','collectionSummary','customerAddress'])->where('customer_unique_id',$id)->firstOrFail();
        if (auth()->user()?->hasRole('Reseller')) abort_unless($customer->reseller_id === auth()->user()?->reseller?->id,403);
        return $customer;
    }

    private function authorizeAction(string $action): void
    {
        $permissions = match ($action) {
            'owner' => ['manage-customer-assignment'],
            'class', 'password' => ['push-customers'],
            'billing-date', 'recharge', 'grace' => ['update-bill'],
            'cash-credit' => ['payment-collection'],
            'ledger' => ['payment-history', 'collection-list', 'amount-collection-report'],
            'invoice', 'pos-print' => ['payment-collection-invoice'],
            'sms' => ['create-sms'],
            'online-graph', 'live-traffic' => ['network-inventory', 'mikrotik-setup'],
            'ticket', 'ticket-history' => ['view-tickets', 'manage-tickets'],
            'wifi-login' => ['mikrotik-setup'],
            default => [],
        };

        $user = auth()->user();
        abort_unless(
            $user && ($user->hasRole('Super Admin') || ($permissions !== [] && $user->hasAnyPermission($permissions))),
            403,
            'You do not have permission to perform this customer action.'
        );
    }

    public function show(string $id, string $action)
    {
        $this->authorizeAction($action);
        abort_unless(in_array($action,['owner','class','billing-date','password','ledger','cash-credit','recharge','grace','invoice','pos-print','sms','online-graph','ticket','ticket-history','wifi-login'],true),404);
        $customer=$this->customer($id);
        if ($action === 'invoice') return redirect()->route('customer.actions.invoice',['id'=>encrypt($customer->customer_unique_id)]);
        if ($action === 'pos-print') return redirect()->route('customer.actions.print',['id'=>encrypt($customer->customer_unique_id)]);
        $resellers=Reseller::with('user')->orderBy('company')->get();
        $packages=PackageList::orderBy('package')->get();
        $tickets=SupportTicket::where('customer_unique_id',$customer->customer_unique_id)->latest()->limit(100)->get();
        $templates=SupportTicketTemplate::where('active',true)->orderBy('sort_order')->get();
        $smsTemplates=SmsTemplate::where('is_active',1)->orderBy('template_name')->get();
        $snapshots=Schema::hasTable('customer_connection_snapshots') ? DB::table('customer_connection_snapshots')->where('customer_unique_id',$customer->customer_unique_id)->latest('sampled_at')->limit(50)->get()->reverse()->values() : collect();
        $collections=CollectionSummary::where('customer_collection_unique_id',$customer->customer_unique_id)->latest('collection_date')->limit(100)->get();
        $wifi=Schema::hasTable('customer_wifi_router_logins') ? DB::table('customer_wifi_router_logins')->where('customer_unique_id',$customer->customer_unique_id)->first() : null;
        // Never send a saved WiFi password back to the browser; blank keeps the encrypted value.
        $wifiPassword='';
        return view('customers.actions',compact('customer','action','resellers','packages','tickets','templates','smsTemplates','snapshots','collections','wifi','wifiPassword'));
    }

    public function liveTraffic(string $id)
    {
        $this->authorizeAction('live-traffic');
        $customer = $this->customer($id);
        $ppp = $customer->pppUser;

        if (! $ppp?->router_name || ! $ppp?->username) {
            return response()->json([
                'ok' => false,
                'message' => 'No PPPoE router or username is linked to this customer.',
            ], 422);
        }

        $traffic = app(\App\Http\Controllers\MikrotikController::class)
            ->getLiveCustomerTraffic((string) $ppp->router_name, (string) $ppp->username);

        // RouterOS counters are from the router's side of the PPPoE interface:
        // TX = bytes/rate sent to the customer (download); RX = received from customer (upload).
        $traffic['download-bits-per-second'] = (int) ($traffic['tx-bits-per-second'] ?? 0);
        $traffic['upload-bits-per-second'] = (int) ($traffic['rx-bits-per-second'] ?? 0);
        $traffic['download-mb'] = (float) ($traffic['tx-mb'] ?? 0);
        $traffic['upload-mb'] = (float) ($traffic['rx-mb'] ?? 0);

        return response()->json([
            'ok' => true,
            'customer' => $customer->customer_unique_id,
            'username' => $ppp->username,
            'router' => $ppp->router_name,
            'online' => ! empty($traffic['interface']),
            'timestamp' => now()->toIso8601String(),
            'traffic' => $traffic,
        ]);
    }

    public function handle(Request $request, string $id, string $action)
    {
        $this->authorizeAction($action);
        if ($action === 'owner' && $request->filled('billing_date')) {
            $this->authorizeAction('billing-date');
        }
        if ($request->boolean('send_sms')) {
            $this->authorizeAction('sms');
        }
        $customer=$this->customer($id);
        $uid=$customer->customer_unique_id;
        $back=route('customer.actions',['id'=>encrypt($uid),'action'=>$action]);

        if ($action==='owner') {
            $data=$request->validate(['reseller_id'=>['nullable','integer','exists:resellers,id'],'package_id'=>['nullable','integer','exists:package_lists,id'],'pop_area'=>['nullable','string','max:190'],'reason'=>['nullable','string','max:500']]);
            if (!empty($data['package_id']) && $customer->pppUser) {
                $targetPackage=PackageList::findOrFail($data['package_id']);
                if ($targetPackage->router_name && (string)$targetPackage->router_name !== (string)$customer->pppUser->router_name) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['package_id'=>'This package belongs to a different MikroTik router. To avoid disrupting the customer, select a package on the current router; cross-router migration must be completed as a separate verified operation.']);
                }
            }
            DB::transaction(function() use($customer,$data,$uid,$request) {
                $customer->reseller_id=$data['reseller_id'] ?? null;
                if (!empty($data['package_id'])) $customer->package_id=$data['package_id'];
                $customer->save();
                if (array_key_exists('pop_area',$data)) OfficialInfo::updateOrCreate(['customer_office_unique_id'=>$uid],['distribution_location'=>$data['pop_area'] ?: null]);
                if ($request->filled('billing_date')) {
                    $bill = BillingInfo::where('customer_bill_unique_id',$uid)->lockForUpdate()->firstOrFail();
                    $bill->auto_disable_date = $request->validate(['billing_date'=>['date']])['billing_date'];
                    $bill->save(); // Model guard rejects a payment-free prepaid extension.
                }
                activity()->causedBy(auth()->user())->withProperties(['customer_unique_id'=>$uid,'reason'=>$data['reason'] ?? null])->log('Customer owner/package changed');
            });
        } elseif ($action==='class') {
            $data=$request->validate(['client_type'=>['required','string','max:80']]);
            OfficialInfo::updateOrCreate(['customer_office_unique_id'=>$uid],['client_type'=>$data['client_type']]);
            if ($customer->pppUser && $customer->pppUser->router_name) {
                $routerName=$customer->pppUser->router_name;
                if (RouterList::where('router_name',$routerName)->where('action','connected')->exists()) {
                    $profiles=app(MikrotikController::class)->singleRead($routerName,'/ppp/profile/print','/ppp profile print without-paging terse',[],false,true);
                    $matchingProfile=collect($profiles)->first(fn($row)=>(string)($row['name']??'')===(string)$data['client_type']);
                    if ($matchingProfile) {
                        try {
                            app(MikrotikController::class)->updatePPPSecret($routerName,$customer->pppUser->username,'profile',$data['client_type']);
                            $customer->pppUser->profile=$data['client_type']; $customer->pppUser->save();
                        } catch (\Throwable $e) {
                            Log::warning('Customer class saved but MikroTik profile update failed',['customer'=>$uid,'router'=>$routerName,'error'=>$e->getMessage()]);
                            session()->flash('warning','Customer class saved, but MikroTik profile update failed. Check router connectivity and sync again.');
                        }
                    } else {
                        session()->flash('warning','Customer class saved. No MikroTik profile with the exact class name was found, so the router profile was left unchanged.');
                    }
                } else {
                    session()->flash('warning','Customer class saved locally. MikroTik router is offline, so its profile was not changed.');
                }
            }
        } elseif ($action==='billing-date') {
            $data=$request->validate(['expiry_date'=>['required','date'],'charge'=>['nullable','numeric','min:0'],'payment_method'=>['nullable','in:cash,bkash,nagad,rocket,bank,other'],'receipt_no'=>['nullable','string','max:100'],'send_sms'=>['nullable','boolean']]);
            DB::transaction(function() use($data,$customer,$uid) {
                $bill=BillingInfo::where('customer_bill_unique_id',$uid)->lockForUpdate()->firstOrFail();
                $bill->auto_disable_date=$data['expiry_date'];
                $charge=(float)($data['charge']??0);
                if ($charge>0) {
                    CollectionSummary::create(['customer_collection_unique_id'=>$uid,'collection_date'=>now(),'collection_amount'=>$charge,'collected_by'=>auth()->user()->email,'payment_type'=>'billing_date_charge','payment_method'=>$data['payment_method']??'cash','transaction_id'=>$data['receipt_no']??null,'payment_status'=>'paid']);
                    $bill->paid_amount=(float)$bill->paid_amount+$charge;
                    $bill->due_amount=(float)$bill->total_amount-(float)$bill->paid_amount;
                    $bill->paid_date=now();
                }
                if ($charge > 0) {
                    app(PrepaidExpiryGuard::class)->saveAfterPaidCollection($bill);
                } else {
                    $bill->save(); // Earlier/same dates remain editable; forward prepaid dates are blocked.
                }
            });
            if ($request->boolean('send_sms')) $this->sendSms($customer,'Billing date/expiry updated. New expiry: '.$data['expiry_date']);
        } elseif ($action==='password') {
            $data=$request->validate(['password'=>['required','string','min:4','max:128'],'confirm_password'=>['required','same:password']]);
            abort_unless($customer->pppUser,422,'Customer has no PPPoE account.');
            $ppp=$customer->pppUser;
            $ppp->password=$data['password']; $ppp->save();
            $router=RouterList::where('router_name',$ppp->router_name)->where('action','connected')->exists();
            if ($router) {
                try { app(MikrotikController::class)->updatePPPSecret($ppp->router_name,$ppp->username,'password',$data['password']); }
                catch (\Throwable $e) { Log::warning('Password saved locally; MikroTik update failed',['customer'=>$uid,'error'=>$e->getMessage()]); return back()->with('warning','Password saved in billing, but MikroTik update failed. Retry router sync.'); }
            } else {
                Log::warning('PPPoE password changed locally while router unavailable',['customer'=>$uid,'router'=>$ppp->router_name]);
                return redirect($back)->with('warning','Password saved in billing, but the MikroTik router is offline. Synchronize it when the router is available.');
            }
        } elseif ($action==='cash-credit') {
            $data=$request->validate([
                'type'=>['required','in:cash_received,credit,return'],
                'amount'=>['required','numeric','min:0.01'],
                'payment_method'=>['required','in:cash,bkash,nagad,rocket,bank,other'],
                'receipt_no'=>['nullable','string','max:100',Rule::unique('collection_summaries','transaction_id')],
                'details'=>['nullable','string','max:1000'],
                'send_sms'=>['nullable','boolean'],
            ], [
                'receipt_no.unique'=>'This receipt / transaction number has already been recorded. Enter a different number or leave it blank if no receipt number is available.',
            ]);

            if ($data['type'] === 'return') {
                abort_unless(auth()->user()?->hasRole('Super Admin') || auth()->user()?->can('payment-delete'), 403, 'Only Admin or Super Admin can reverse a recorded payment.');
            }

            try {
                DB::transaction(function() use($data,$customer,$uid) {
                    $bill=BillingInfo::where('customer_bill_unique_id',$uid)->lockForUpdate()->firstOrFail();
                    $amount=(float)$data['amount']; $paid=(float)$bill->paid_amount;
                    if ($data['type']==='return' && $amount>$paid) {
                        throw ValidationException::withMessages(['amount'=>'Return amount cannot exceed recorded paid amount.']);
                    }
                    $signed=$data['type']==='return' ? -$amount : $amount;
                    CollectionSummary::create(['customer_collection_unique_id'=>$uid,'collection_date'=>now(),'collection_amount'=>$signed,'collected_by'=>auth()->user()->email,'payment_type'=>$data['type'],'payment_method'=>$data['payment_method'],'transaction_id'=>$data['receipt_no']??null,'details'=>$data['details']??null,'payment_status'=>'paid']);
                    $bill->paid_amount=max(0,$paid+$signed); $bill->due_amount=(float)$bill->total_amount-(float)$bill->paid_amount; $bill->paid_date=now(); $bill->save();
                });
            } catch (QueryException $e) {
                // Normal duplicate submissions fail validation above. This fallback
                // turns a simultaneous duplicate submit into a field error too.
                if (str_contains(strtolower($e->getMessage()), 'collection_summaries_transaction_id_unique')) {
                    throw ValidationException::withMessages([
                        'receipt_no'=>'This receipt / transaction number has already been recorded. Enter a different number or leave it blank if no receipt number is available.',
                    ]);
                }
                throw $e;
            }

            if ($request->boolean('send_sms')) $this->sendSms($customer,'Payment transaction '.$data['type'].' recorded: '.$data['amount'].'. Current due: '.($customer->billing?->due_amount ?? 0));
        } elseif ($action==='recharge') {
            $data=$request->validate(['months'=>['required','integer','min:1','max:24'],'payment_mode'=>['required','in:receive_payment,extend_only'],'payment_method'=>['nullable','in:cash,bkash,nagad,rocket,bank,other'],'receipt_no'=>['nullable','string','max:100'],'send_sms'=>['nullable','boolean']]);
            if ($customer->billing?->billing_type === 'prepaid' && $data['payment_mode'] === 'extend_only') {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'payment_mode' => 'Prepaid validity cannot be extended without recording a payment. Use Receive Payment.'
                ]);
            }
            DB::transaction(function() use($data,$customer,$uid) {
                $bill=BillingInfo::where('customer_bill_unique_id',$uid)->lockForUpdate()->firstOrFail();
                $base=$bill->auto_disable_date ? \Carbon\Carbon::parse($bill->auto_disable_date) : today();
                if ($base->lt(today())) $base=today();
                $bill->auto_disable_date=$base->addMonths((int)$data['months'])->toDateString();
                if ($data['payment_mode']==='receive_payment') {
                    $amount=(float)$bill->monthly_rent*(int)$data['months'];
                    CollectionSummary::create(['customer_collection_unique_id'=>$uid,'collection_date'=>now(),'collection_amount'=>$amount,'collected_by'=>auth()->user()->email,'payment_type'=>'monthly_recharge','payment_method'=>$data['payment_method']??'cash','transaction_id'=>$data['receipt_no']??null,'payment_status'=>'paid']);
                    $bill->paid_amount=(float)$bill->paid_amount+$amount; $bill->due_amount=(float)$bill->total_amount-(float)$bill->paid_amount; $bill->paid_date=now();
                }
                if ($data['payment_mode'] === 'receive_payment') {
                    app(PrepaidExpiryGuard::class)->saveAfterPaidCollection($bill);
                } else {
                    $bill->save();
                }
            });
            if ($request->boolean('send_sms')) $this->sendSms($customer,'Monthly recharge completed for '.$data['months'].' month(s).');
        } elseif ($action==='grace') {
            $data=$request->validate(['days'=>['required','integer','min:0','max:90'],'hours'=>['required','integer','min:0','max:23'],'note'=>['nullable','string','max:1000']]);
            abort_if((int)$data['days']===0 && (int)$data['hours']===0,422,'Grace duration must be greater than zero.');
            $bill=BillingInfo::where('customer_bill_unique_id',$uid)->firstOrFail();
            $base=$bill->extra_date ? \Carbon\Carbon::parse($bill->extra_date) : now(); if($base->lt(now())) $base=now();
            $bill->extra_date=$base->addDays((int)$data['days'])->addHours((int)$data['hours']); $bill->save();
            activity()->causedBy(auth()->user())->withProperties(['customer_unique_id'=>$uid,'note'=>$data['note']??null])->log('Customer extra grace applied');
        } elseif ($action==='sms') {
            $data=$request->validate(['template_id'=>['nullable','integer','exists:sms_templates,id'],'message'=>['nullable','string','max:1000','required_without:template_id']]);
            $message=trim((string)($data['message']??''));
            if (!empty($data['template_id'])) {
                $template=SmsTemplate::where('is_active',1)->findOrFail($data['template_id']);
                $message=str_replace(['{CUSTOMER_NAME}','{CUSTOMER_ID}','{IP_OR_USER_NAME_OR_ID}','{COMPANY_NAME}','{COMPANY_MOBILE}','{BALANCE}'],[$customer->customer_name,$uid,$customer->pppUser?->username ?? '',siteUrlSettings('site_name'),siteUrlSettings('company_mobile'),$customer->billing?->due_amount ?? 0],$template->template);
            }
            $this->sendSms($customer,$message);
        } elseif ($action==='ticket') {
            $data=$request->validate(['ticket_type'=>['required','in:complain,task,sales,legacy_sales'],'topic'=>['nullable','string','max:120'],'subject'=>['required','string','max:190'],'priority'=>['required','in:low,medium,high,urgent'],'description'=>['required','string','max:5000']]);
            SupportTicket::create(['ticket_no'=>SupportTicket::generateTicketNo(),'customer_unique_id'=>$uid,'ticket_type'=>$data['ticket_type'],'topic'=>$data['topic']??null,'ppp_username'=>$customer->pppUser?->username,'subject'=>$data['subject'],'description'=>$data['description'],'priority'=>$data['priority'],'status'=>'new']);
        } elseif ($action==='wifi-login') {
            abort_unless(Schema::hasTable('customer_wifi_router_logins'),500,'WiFi login storage is not installed.');
            $data=$request->validate(['customer_ip'=>['nullable','ip'],'wifi_port'=>['required','integer','min:1','max:65535'],'username'=>['nullable','string','max:190'],'password'=>['nullable','string','max:500'],'local_login_url'=>['nullable','url','max:1000'],'remote_login_url'=>['nullable','url','max:1000']]);
            $old=DB::table('customer_wifi_router_logins')->where('customer_unique_id',$uid)->first();
            DB::table('customer_wifi_router_logins')->updateOrInsert(['customer_unique_id'=>$uid],['customer_ip'=>$data['customer_ip']??null,'wifi_port'=>$data['wifi_port'],'username'=>$data['username']??null,'password_encrypted'=>filled($data['password']??'')?Crypt::encryptString($data['password']):($old->password_encrypted??null),'local_login_url'=>$data['local_login_url']??null,'remote_login_url'=>$data['remote_login_url']??null,'updated_at'=>now(),'created_at'=>$old->created_at??now()]);
        } else {
            abort(404);
        }

        return redirect($back)->with('success','Customer action completed successfully.');
    }

    public function invoicePage(string $id)
    {
        return $this->renderInvoice($id, false);
    }

    public function printPage(string $id)
    {
        return $this->renderInvoice($id, true);
    }

    private function renderInvoice(string $id, bool $print)
    {
        $this->authorizeAction($print ? 'pos-print' : 'invoice');
        $customer=$this->customer($id);
        $collections=CollectionSummary::where('customer_collection_unique_id',$customer->customer_unique_id)->latest('collection_date')->limit(50)->get();
        return view('customers.action-invoice',compact('customer','collections','print'));
    }

    private function sendSms(CustomersInfo $customer,string $message): void
    {
        if (empty($customer->mobile)) { session()->flash('warning','Customer action was saved, but no mobile number is on file.'); return; }
        try {
            $response=SmsBridge::to($customer->mobile)->message($message)->send();
            if (!$response || !$response->isSuccessful()) throw new \RuntimeException('SMS provider returned an unsuccessful response.');
        } catch (\Throwable $e) {
            Log::warning('Customer action SMS failed',['customer'=>$customer->customer_unique_id,'mobile'=>$customer->mobile,'error'=>$e->getMessage()]);
            session()->flash('warning','The customer action was saved, but SMS delivery failed. Check SMS gateway configuration/balance.');
        }
    }
}
