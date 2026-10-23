<?php

namespace App\Http\Controllers;

use App\Models\BillingInfo;
use App\Models\CollectionSummary;
use App\Models\CustomersInfo;
use App\Models\NotificationLogs;
use App\Models\PaymentSummary;
use App\Models\PPPSecrets;
use App\Models\RouterList;
use App\Models\SmsTemplate;
use App\Services\MikrotikSSHService;
use App\Services\PrepaidBillingCycle;
use Carbon\Carbon;
use XLink\SmsBridge\Facades\SmsBridge;

class ScheduledTasksController extends Controller
{
    protected $mikrotikSSHService;

    public function __construct(?MikrotikSSHService $mikrotikSSHService = null)
    {
        $this->mikrotikSSHService = $mikrotikSSHService;
    }

    //     public function backupDatabase()
    // {
    //     // Database configuration
    //     $dbHost = env('DB_HOST', '127.0.0.1');
    //     $dbUser = env('DB_USERNAME');
    //     $dbPassword = env('DB_PASSWORD');
    //     $dbName = env('DB_DATABASE');

    //     // Backup file name
    //     $backupFile = storage_path("app/backup_" . date('Y-m-d_H-i-s') . ".sql");

    //     // Use absolute path to mysqldump
    //     $mysqldumpPath = '/usr/bin/mysqldump'; // Change this based on your system
    //     $output = null;
    //     $resultCode = null;
    //     $backupFile = storage_path('app/backup_' . date('Y-m-d_H-i-s') . '.sql');
    //     $command = "mysqldump -h {$dbHost} -u {$dbUser} -p{$dbPassword} {$dbName} > {$backupFile}";

    //     exec($command, $output, $resultCode);

    //     if ($resultCode === 0) {
    //         // Save output to file
    //         $backupFile = storage_path("app/backup_" . date('Y-m-d_H-i-s') . ".sql");
    //         file_put_contents($backupFile, implode(PHP_EOL, $output));
    //         return response()->download($backupFile)->deleteFileAfterSend();
    //     } else {
    //         \Log::error("mysqldump failed", ['command' => $command, 'output' => $output, 'resultCode' => $resultCode]);
    //         return response()->json(['error' => 'Database backup failed!'], 500);
    //     }
    // }

    public function createMonthlyBill()
    {
        BillingInfo::query()->cursor()->each(function ($billing) {
            // Billing rows can remain after a customer is soft-deleted. Treat those
            // records as intentionally retired instead of raising a false orphan alert.
            $customer = CustomersInfo::withTrashed()
                ->where('customer_unique_id', $billing->customer_bill_unique_id)
                ->first();

            if ($customer && $customer->trashed()) {
                return;
            }

            if (! $customer) {
                NotificationLogs::create([
                    'title' => 'Monthly Bill Generation Error',
                    'message' => "Billing info exists for customer ID '{$billing->customer_bill_unique_id}' but the customer record was not found.",
                    'status' => 'Orphaned Billing Record Found',
                    'type' => 'System Alert',
                ]);

                return;
            }

            // Active customers always get a bill.
            // Non-active customers only get a bill if continue_bill is enabled in official info.
            if ($customer->status !== 'active') {
                if (! $customer->official || ! $customer->official->continue_bill) {
                    return;
                }
            }

            $nextMonthStart = Carbon::now()->addMonthNoOverflow()->startOfMonth();

            // Check if PaymentSummary already exists for the next month
            $existingPayment = PaymentSummary::where('customer_payment_unique_id', $billing->customer_bill_unique_id)
                ->where('summary_date', $nextMonthStart)
                ->exists();

            $totalCollectionAmount = CollectionSummary::where('customer_collection_unique_id', $billing->customer_bill_unique_id)
                ->whereBetween('collection_date', [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()])
                ->sum('collection_amount');
            // if ($totalCollectionAmount == $billing->paid_amount) {
            $subtotal = $billing->monthly_rent + $billing->additional_charge + $billing->vat + $billing->previous_due;
            $discountTotal = $subtotal - $billing->discount;
            $grandTotal = $discountTotal - $billing->advance;
            $calculateAmount = $grandTotal - $totalCollectionAmount;
            $nextMonthBill = $calculateAmount + ($billing->monthly_rent + $billing->additional_charge + $billing->vat);

            if ($calculateAmount > 0) {
                $due_amount = $calculateAmount;
                $advance = 0.00;
            } elseif ($calculateAmount < 0) {
                $due_amount = 0.00;
                $advance = abs($calculateAmount);
            } else {
                $due_amount = 0.00;
                $advance = 0.00;
            }
            $customer->update([
                'disable_count' => 0,
            ]);
            if (! $existingPayment) {
                if ($customer->status == 'free') {
                    PaymentSummary::create([
                        'customer_payment_unique_id' => $billing->customer_bill_unique_id,
                        'summary_date' => $nextMonthStart,
                        'monthly_rent' => 0.00,
                        'additional_charge' => 0.00,
                        'vat' => 0.00,
                        'discount' => 0.00,
                        'previous_due' => 0.00,
                        'advance' => 0.00,
                    ]);
                    // Update BillingInfo with new values
                    BillingInfo::where('customer_bill_unique_id', $billing->customer_bill_unique_id)
                        ->update([
                            'paid_amount' => 0.00,
                        ]);
                } else {
                    // Create new PaymentSummary for the next month
                    PaymentSummary::create([
                        'customer_payment_unique_id' => $billing->customer_bill_unique_id,
                        'summary_date' => $nextMonthStart,
                        'monthly_rent' => $billing->monthly_rent,
                        'additional_charge' => $billing->additional_charge,
                        'vat' => $billing->vat,
                        'discount' => $billing->discount,
                        'previous_due' => $due_amount,
                        'advance' => $advance,
                    ]);
                    // Update BillingInfo with new values
                    BillingInfo::where('customer_bill_unique_id', $billing->customer_bill_unique_id)
                        ->update([
                            'paid_amount' => 0.00,
                            'advance' => $advance,
                            'discount' => 0.00,
                            'previous_due' => $due_amount,
                            'total_amount' => $nextMonthBill,
                            'due_amount' => $nextMonthBill,
                        ]);
                }
            }
            // }
        });
    }

    public function allCustomersMonthlyBillSMS()
    {
        $successfulIDs = [];
        $errorIDs = [];
        $skippedIDs = [];

        CustomersInfo::where('status', 'active')
            ->with(['pppUser', 'billing', 'official'])
            ->cursor()
            ->each(function ($customer) use (&$successfulIDs, &$errorIDs, &$skippedIDs) {
                $payment = PaymentSummary::where('customer_payment_unique_id', $customer->customer_unique_id)->first();
                $lastDayOfMonth = Carbon::now()->endOfMonth()->format('d-M-Y');
                $thisMonth = Carbon::now()->format('F');
                $billMonth = Carbon::parse($customer->billing->auto_disable_date)->format('F');
                if ($thisMonth != $billMonth) {
                    $day = date('d', strtotime($customer->billing->auto_disable_date));
                    $y = date('Y');
                    $m = date('m');

                    $date = checkdate($m, $day, $y) ? "$y-$m-$day" : date('Y-m-t');
                    $billDate = date('d-M-Y', strtotime($date));
                } else {
                    $billDate = Carbon::parse($customer->billing->auto_disable_date)->format('d-M-Y');
                }
                // Skip SMS if bill_sms is not enabled for this customer
                if (! $customer->official || ! $customer->official->bill_sms) {
                    $skippedIDs[] = $customer->customer_unique_id.' ('.($customer->pppUser->username ?? '').') - bill_sms disabled';

                    return;
                }

                $data = [
                    'customer_name' => $customer->customer_name,
                    'month' => Carbon::now()->format('F Y'),
                    'bill_amount' => $customer->billing->total_amount,
                    'customer_id' => $customer->customer_unique_id,
                    'ip_or_user_name' => ($customer->pppUser->username ?? ''),
                    'last_day_of_pay_bill' => $billDate,
                    'company_name' => siteUrlSettings('site_name'),
                    'company_mobile' => siteUrlSettings('site_phone'),
                    'recipient' => $customer->mobile,
                ];
                $response = app(SMSController::class)->allCustomersSMS($data);
                if ($response && $response->isSuccessful()) {
                    $successfulIDs[] = $customer->customer_unique_id.' ('.($customer->pppUser->username ?? '').')';
                } else {
                    $errorMsg = $response ? $response->getMessage() : 'Unknown error';
                    $errorIDs[] = $customer->customer_unique_id.' ('.($customer->pppUser->username ?? '').') - Error: '.$errorMsg;
                }
            });

        // Save success and error messages in the log
        if (! empty($successfulIDs)) {
            NotificationLogs::create([
                'title' => 'Monthly Bill Alert',
                'message' => implode(', ', $successfulIDs),
                'status' => 'Message was successfully delivered',
                'type' => 'By SMS',
            ]);
        }
        if (! empty($errorIDs)) {
            NotificationLogs::create([
                'title' => 'Monthly Bill Alert Error',
                'message' => implode(', ', $errorIDs),
                'status' => 'Message was not delivered',
                'type' => 'By SMS',
            ]);
        }
    }

    public function createAlert()
    {
        $expiredDate = Carbon::now()->addDays(2)->toDateString(); // Set as date only
        $successfulIDs = [];
        $errorIDs = [];

        $template = SmsTemplate::where('template_name', 'reminder')->first();
        if ($template && ! $template->is_active) {
            return;
        }

        CustomersInfo::where('status', 'active')
            ->where('ppp_user_id', '!=', null)
            ->whereHas('billing', fn ($q) => $q->autoDisable()->unpaid())
            ->with(['pppUser', 'billing'])
            ->each(function ($customer) use (&$successfulIDs, &$errorIDs, &$expiredDate, $template) {
                $disableDate = Carbon::parse($customer->billing->auto_disable_date)->format('Y-m-d');
                $disableDate2 = Carbon::parse($customer->billing->auto_disable_date)->addMonths($customer->billing->auto_disable_month)->format('Y-m-d');
                if ($disableDate === $expiredDate || $disableDate2 === $expiredDate) {
                    $customer_bill = ($customer->billing->monthly_rent + $customer->billing->additional_charge + $customer->billing->vat);
                    $due_amount = $customer->billing->due_amount;

                    if ($customer_bill * ($customer->billing->auto_disable_month) < $due_amount) {
                        if ($template) {
                            $message = str_replace(
                                ['{CUSTOMER_NAME}', '{AUTO_TEMPORARY_DAY}', '{ID}', '{DUE_AMOUNT}', '{COMPANY_NAME}', '{COMPANY_MOBILE}'],
                                [
                                    $customer->customer_name,
                                    Carbon::parse($expiredDate)->format('d-M-Y'),
                                    $customer->customer_unique_id.'('.($customer->pppUser->username ?? '').')',
                                    $customer->billing->due_amount,
                                    siteUrlSettings('site_name'),
                                    siteUrlSettings('site_phone'),
                                ],
                                $template->template
                            );
                        } else {
                            $message = 'Dear '.$customer->customer_name.', Your ID '.$customer->customer_unique_id.'('.($customer->pppUser->username ?? '').') is EXPIRED on: '.Carbon::parse($expiredDate)->format('d-M-Y').', Your Due amount: '.$customer->billing->due_amount.'TK, Please Pay it before '.Carbon::parse($expiredDate)->format('d-M-Y').' to avoid Disconnection. Regards, '.siteUrlSettings('site_name').', Mobile: '.siteUrlSettings('site_phone');
                        }

                        $response = SmsBridge::to($customer->mobile)
                            ->message($message)
                            ->send();

                        if ($response && $response->isSuccessful()) {
                            $successfulIDs[] = $customer->customer_unique_id.' ('.($customer->pppUser->username ?? '').')';
                        } else {
                            $errorMsg = $response ? $response->getMessage() : 'Unknown error';
                            $errorIDs[] = $customer->customer_unique_id.' ('.($customer->pppUser->username ?? '').') - Error: '.$errorMsg;
                        }
                    }
                }
            });

        // store logs for successful and error messages
        if (! empty($successfulIDs)) {
            NotificationLogs::create([
                'title' => 'Disconnection Alert Success',
                'message' => implode(', ', $successfulIDs),
                'status' => 'Message was successfully delivered',
                'type' => 'By SMS',
            ]);
        }
        if (! empty($errorIDs)) {
            NotificationLogs::create([
                'title' => 'Disconnection Alert Error',
                'message' => implode(', ', $errorIDs),
                'status' => 'Message was not delivered',
                'type' => 'By SMS',
            ]);
        }
    }

    public function userDisable()
    {
        $tz = config('app.timezone') ?: 'Asia/Dhaka';
        $today = Carbon::now($tz)->startOfDay();
        $successfulIDs = [];
        $errorIDs = [];
        $fallbackIDs = [];

        $template = SmsTemplate::where('template_name', 'auto_temporary_disable_alert')->first();

        CustomersInfo::active()
            ->where(function ($customerQuery) {
                // Keep the existing postpaid/grace-limit selection unchanged.
                $customerQuery->where(function ($legacyQuery) {
                    $legacyQuery->underDisableLimit(siteUrlSettings('disable_check_no') ?? 1)
                        ->whereHas('billing', fn ($q) => $q->autoDisable()->unpaid()->where('billing_type', '!=', 'prepaid'));
                })->orWhereHas('billing', fn ($q) => $q->autoDisable()->where('billing_type', 'prepaid')->where('monthly_rent', '>', 0));
            })
            ->hasPPPUser()
            ->with(['pppUser', 'billing'])
            ->each(function ($customer) use (&$successfulIDs, &$errorIDs, &$fallbackIDs, $today, $template, $tz) {
                $billing = $customer->billing;
                $pppUser = $customer->pppUser;

                $monthlyRent = $billing->monthly_rent;
                $additionalCharge = $billing->additional_charge;
                $vat = $billing->vat;
                $due = $billing->due_amount;

                $shouldDisable = false;
                $disableFor = '';

                if ($billing->billing_type === 'prepaid') {
                    // Correct legacy expiry drift in-place, but preserve the paid-through month.
                    $cycleAnchor = $customer->connection_date ?: $billing->auto_disable_date;
                    if (! $cycleAnchor) {
                        return;
                    }

                    if ($billing->auto_disable_date) {
                        $autoDisableDate = PrepaidBillingCycle::alignToConnectionDay($cycleAnchor, $billing->auto_disable_date);
                        if (Carbon::parse($billing->auto_disable_date, $tz)->toDateString() !== $autoDisableDate->toDateString()) {
                            $billing->auto_disable_date = $autoDisableDate->toDateString();
                            $billing->save();
                        }
                    } else {
                        $autoDisableDate = PrepaidBillingCycle::nextExpiryDate($cycleAnchor, $today);
                        $billing->auto_disable_date = $autoDisableDate->toDateString();
                        $billing->save();
                    }

                    $shouldDisable = (float) $monthlyRent > 0 && $today->gte($autoDisableDate->copy()->startOfDay());
                    $disableFor = 'Prepaid connection-day expiry';
                } else {
                    // Existing postpaid/grace-period logic remains unchanged.
                    $disableCheckNo = (int) (siteUrlSettings('disable_check_no') ?? 1);
                    $baseDate = Carbon::parse($billing->auto_disable_date, $tz);

                    if ($baseDate->isPast() && ($baseDate->month !== now($tz)->month || $baseDate->year !== now($tz)->year)) {
                        $baseDate->month(now($tz)->month)->year(now($tz)->year);
                    }

                    if ($disableCheckNo <= 1) {
                        $autoDisableDate = $baseDate->copy()->startOfDay();
                    } else {
                        $autoDisableDate = $baseDate->copy()->addDays((int) siteUrlSettings('disable_check_days') * $customer->disable_count)->startOfDay();
                    }

                    if ($disableCheckNo <= 1) {
                        $previousDueDisableDate = Carbon::parse($billing->auto_disable_date, $tz)->month(now($tz)->month)->year(now($tz)->year)->startOfDay();
                    } else {
                        $previousDueDisableDate = Carbon::parse($billing->auto_disable_date, $tz)->month(now($tz)->month)->year(now($tz)->year)->addDays((int) siteUrlSettings('disable_check_days') * $customer->disable_count)->startOfDay();
                    }
                    $autoDisableMonth = $billing->auto_disable_month;
                    $disableLimitDate = $autoDisableDate->copy()->addMonths($autoDisableMonth);
                    $totalBill = ($monthlyRent + $additionalCharge + $vat) * $autoDisableMonth;

                    if ($billing->previous_due > 0 && $due > $totalBill && $today->isSameDay($previousDueDisableDate)) {
                        $shouldDisable = true;
                        $disableFor = 'Auto Disable for Previous Due';
                    } elseif ($due > $totalBill && $today->gte($autoDisableDate)) {
                        $shouldDisable = true;
                        $disableFor = 'Auto Disable for Due';
                    } elseif ($due > 0 && $due <= $totalBill && $today->gte($disableLimitDate)) {
                        $shouldDisable = true;
                        $disableFor = 'Auto Disable Date reached';
                    }
                }

                if ($shouldDisable && $pppUser && $pppUser->username) {
                    if (filled($pppUser->router_name) && \DB::table('mikrotik_pending_actions')
                        ->where('customer_unique_id', $customer->customer_unique_id)
                        ->where('router_name', $pppUser->router_name)
                        ->where('username', $pppUser->username)
                        ->where('action', 'disable')
                        ->where('status', 'pending')
                        ->exists()) {
                        return;
                    }

                    $router = RouterList::where('router_name', $pppUser->router_name)->first();
                    if ($router) {
                        try {
                            // Use centralized redirection logic (Profile=Expired + Kick Session)
                            $disableStatus = app(MikrotikController::class)->disablePPPSecret(
                                $customer->customer_unique_id,
                                $router->router_name,
                                $pppUser->username,
                                false,
                                true
                            );

                            if ($disableStatus === 'fallback') {
                                $fallbackIDs[] = $customer->customer_unique_id.' ('.$pppUser->username.')';
                            }

                            // If we reached here, it's successful
                            $successfulID = $customer->customer_unique_id.' ('.$pppUser->username.')'.($disableStatus === 'hard' ? ' [hard disabled]' : '');
                            $customer->update([
                                'status' => 'disable',
                                'disable_count' => $customer->disable_count + 1,
                            ]);

                            if ($customer->ppp_user_id) {
                                PPPSecrets::where('id', $customer->ppp_user_id)->update([
                                    'status' => 'disable',
                                ]);
                            }

                            if ($template) {
                                if ($template->is_active) {
                                    $message = str_replace(
                                        ['{CUSTOMER_NAME}', '{CUSTOMER_ID}', '{DUE_AMOUNT}', '{COMPANY_NAME}', '{COMPANY_MOBILE}'],
                                        [
                                            $customer->customer_name,
                                            $customer->customer_unique_id.'('.($pppUser->username ?? '').')',
                                            $due,
                                            siteUrlSettings('site_name'),
                                            siteUrlSettings('site_phone'),
                                        ],
                                        $template->template
                                    );

                                    // Send SMS
                                    $responseSms = SmsBridge::to($customer->mobile)
                                        ->message($message)
                                        ->send();

                                    if ($responseSms && $responseSms->isSuccessful()) {
                                        $successfulSMS = '->{sms sent}';
                                    } else {
                                        $errorMsg = $responseSms ? $responseSms->getMessage() : 'Unknown error';
                                        $errorSMS = '->{sms error: '.$errorMsg.'}';
                                    }
                                } else {
                                    $successfulSMS = '->{sms disabled}';
                                }
                            } else {
                                $message = 'Dear '.$customer->customer_name.', Your ID '.$customer->customer_unique_id.'('.($customer->pppUser->username ?? '').') is temporarily disconnected, Your Due amount: '.$due.'TK. Regards, '.siteUrlSettings('site_name').', Mobile: '.siteUrlSettings('site_phone');

                                // Send SMS
                                $responseSms = SmsBridge::to($customer->mobile)
                                    ->message($message)
                                    ->send();

                                if ($responseSms && $responseSms->isSuccessful()) {
                                    $successfulSMS = '->{sms sent}';
                                } else {
                                    $errorMsg = $responseSms ? $responseSms->getMessage() : 'Unknown error';
                                    $errorSMS = '->{sms error: '.$errorMsg.'}';
                                }
                            }

                            if (isset($successfulID)) {
                                $successfulIDs[] = $successfulID.' '.($successfulSMS ?? $errorSMS ?? '').' - ('.$disableFor.')';
                            }
                        } catch (\Throwable $e) {
                            try {
                                if (filled($pppUser->router_name) && filled($pppUser->username)) {
                                    app(\App\Services\MikrotikPendingActionQueue::class)->queue(
                                        $customer->customer_unique_id,
                                        $pppUser->router_name,
                                        $pppUser->username,
                                        'disable'
                                    );
                                }
                            } catch (\Throwable $queueError) {
                                \Log::critical('Could not queue scheduled customer disable after router failure.', [
                                    'customer_unique_id' => $customer->customer_unique_id,
                                    'router' => $pppUser->router_name,
                                    'username' => $pppUser->username,
                                    'error' => $queueError->getMessage(),
                                ]);
                            }

                            NotificationLogs::create([
                                'title' => 'Disconnection Error',
                                'message' => $customer->customer_unique_id.' ('.$pppUser->username.') - '.$e->getMessage().' A hard-disable retry has been queued where router mapping is available.',
                                'status' => 'Error on Mikrotik Command',
                                'type' => 'Mikrotik Command',
                            ]);
                            $errorIDs[] = $customer->customer_unique_id.' ('.$pppUser->username.') {'.$e->getMessage().'}';
                        }
                    } else {
                        try {
                            if (filled($pppUser->router_name) && filled($pppUser->username)) {
                                app(\App\Services\MikrotikPendingActionQueue::class)->queue(
                                    $customer->customer_unique_id,
                                    $pppUser->router_name,
                                    $pppUser->username,
                                    'disable'
                                );
                            }
                        } catch (\Throwable $queueError) {
                            \Log::critical('Could not queue scheduled customer disable because router is missing.', [
                                'customer_unique_id' => $customer->customer_unique_id,
                                'router' => $pppUser->router_name,
                                'username' => $pppUser->username,
                                'error' => $queueError->getMessage(),
                            ]);
                        }

                        NotificationLogs::create([
                            'title' => 'Disconnection Warning',
                            'message' => "Router '{$pppUser->router_name}' not found in router list for customer ID '{$customer->customer_unique_id}'. A hard-disable retry has been queued where possible.",
                            'status' => 'Router Not Found',
                            'type' => 'System Alert',
                        ]);
                        $errorIDs[] = $customer->customer_unique_id.' ('.$pppUser->username.') {Router not found}';
                    }
                }
            });

        // ✅ successful user log
        if (! empty($successfulIDs)) {
            NotificationLogs::create([
                'title' => 'Disconnection Success',
                'message' => implode(', ', $successfulIDs),
                'status' => 'User(s) disabled successfully',
                'type' => 'Mikrotik Command',
            ]);
        }

        // ⚠️ fallback hard disabled user log
        if (! empty($fallbackIDs)) {
            $expiredProfile = siteUrlSettings('expired_profile_name') ?? 'Expired';
            NotificationLogs::create([
                'title' => "User(s) profile \"{$expiredProfile}\" not found. The following user(s) were HARD DISABLED instead:",
                'message' => implode(', ', $fallbackIDs),
                'status' => 'Hard Disabled (Fallback)',
                'type' => 'Mikrotik Command',
            ]);
        }

        // ❌ failed user log
        if (! empty($errorIDs)) {
            NotificationLogs::create([
                'title' => 'Disconnection Failed',
                'message' => implode(', ', $errorIDs),
                'status' => 'Failed to disable user(s)',
                'type' => 'Mikrotik Command',
            ]);
        }
    }
}
