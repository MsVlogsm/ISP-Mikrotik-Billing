<?php

namespace App\Jobs;

use App\Http\Controllers\MikrotikController;
use App\Models\RouterList;
use App\Models\PPPSecrets;
use App\Models\CustomersInfo;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReconcileMikrotikPendingAction implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;
    public $tries = 3;

    public function __construct(public int $pendingActionId)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->pendingActionId;
    }

    public function handle(MikrotikController $mikrotik): void
    {
        $action = DB::table('mikrotik_pending_actions')->where('id', $this->pendingActionId)->first();
        // A job may already be queued when a newer opposite action supersedes it.
        // Never execute stale work after the row leaves the pending state.
        if (! $action || $action->status !== 'pending') {
            return;
        }

        $routerConnected = RouterList::where('router_name', $action->router_name)
            ->where('action', 'connected')
            ->exists();

        if (! $routerConnected) {
            return;
        }

        // Recheck the desired state immediately before touching the router.
        // A queued Disable must not undo a payment/Enable completed after it queued.
        $latestCustomer = CustomersInfo::with('billing')
            ->where('customer_unique_id', $action->customer_unique_id)
            ->first();

        $staleAction = false;
        if (in_array($action->action, ['disable', 'enable'], true)) {
            $staleAction = ! $latestCustomer;
        }

        if ($latestCustomer && $action->action === 'disable') {
            $staleAction = $latestCustomer->status !== 'active';
            if (! $staleAction && $latestCustomer->billing?->billing_type === 'prepaid') {
                $expiry = $latestCustomer->billing->auto_disable_date;
                if (! $expiry && $latestCustomer->connection_date) {
                    $expiry = \App\Services\PrepaidBillingCycle::nextExpiryDate(
                        $latestCustomer->connection_date,
                        $latestCustomer->connection_date
                    )->toDateString();
                }

                $staleAction = ! $expiry
                    || \Carbon\Carbon::parse($expiry)->startOfDay()->gt(today());
            }
        } elseif ($latestCustomer && $action->action === 'enable') {
            $staleAction = $latestCustomer->status !== 'active';
        }

        if ($staleAction) {
            DB::table('mikrotik_pending_actions')->where('id', $action->id)->where('status', 'pending')->update([
                'status' => 'superseded',
                'last_error' => 'Skipped because the current customer state no longer matches this queued action.',
                'processed_at' => now(),
                'updated_at' => now(),
            ]);
            return;
        }

        DB::table('mikrotik_pending_actions')->where('id', $action->id)->increment('attempts');

        try {
            if ($action->action === 'disable') {
                $mikrotik->disablePPPSecret($action->customer_unique_id ?: $action->id, $action->router_name, $action->username, false, true);

                $restoreEnable = false;
                DB::transaction(function () use ($action, &$restoreEnable) {
                    $customer = CustomersInfo::with('billing')
                        ->where('customer_unique_id', $action->customer_unique_id)
                        ->lockForUpdate()
                        ->first();

                    if ($customer && $customer->status === 'active') {
                        $billing = $customer->billing;
                        if ($billing?->billing_type === 'prepaid'
                            && $billing->auto_disable_date
                            && \Carbon\Carbon::parse($billing->auto_disable_date)->startOfDay()->gt(today())) {
                            // Payment landed while the network write was in flight.
                            $restoreEnable = true;
                            return;
                        }

                        $customer->status = 'disable';
                        $customer->disable_count = (int) $customer->disable_count + 1;
                        $customer->save();
                    }

                    PPPSecrets::where('router_name', $action->router_name)
                        ->where('username', $action->username)
                        ->update(['status' => $restoreEnable ? 'active' : 'disable']);
                });

                if ($restoreEnable) {
                    DB::table('mikrotik_pending_actions')->where('id', $action->id)->update([
                        'status' => 'superseded',
                        'last_error' => 'Payment renewed prepaid validity while Disable was running; queued Enable to restore router state.',
                        'processed_at' => now(),
                        'updated_at' => now(),
                    ]);
                    app(\App\Services\MikrotikPendingActionQueue::class)->queue(
                        $action->customer_unique_id,
                        $action->router_name,
                        $action->username,
                        'enable'
                    );
                    return;
                }
            } elseif ($action->action === 'enable') {
                $ppp = PPPSecrets::where('router_name', $action->router_name)
                    ->where('username', $action->username)
                    ->first();

                // If the local PPP secret was removed while this action waited,
                // this queued activation is obsolete and should not retry forever.
                if (! $ppp) {
                    DB::table('mikrotik_pending_actions')->where('id', $action->id)->update([
                        'status' => 'completed',
                        'last_error' => null,
                        'processed_at' => now(),
                        'updated_at' => now(),
                    ]);
                    return;
                }

                $mikrotik->enablePPPSecret($action->customer_unique_id ?: $action->id, $action->router_name, $action->username);
                if (filled($ppp->profile)) {
                    $mikrotik->updatePPPSecret($action->router_name, $action->username, 'profile', $ppp->profile);
                }
                try {
                    $mikrotik->singleWrite($action->router_name, '/ppp active remove [find name='.$mikrotik->mtQuote($action->username).']');
                } catch (\Throwable $e) {
                    Log::debug('Pending enable action session reset skipped: '.$e->getMessage());
                }
                PPPSecrets::whereKey($ppp->id)->update(['status' => 'active']);
            } elseif ($action->action === 'remove') {
                $mikrotik->removePPPSecret($action->customer_unique_id ?: $action->id, $action->router_name, $action->username);
            } else {
                throw new \InvalidArgumentException('Unsupported pending MikroTik action: '.$action->action);
            }

            DB::table('mikrotik_pending_actions')->where('id', $action->id)->update([
                'status' => 'completed',
                'last_error' => null,
                'processed_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            DB::table('mikrotik_pending_actions')->where('id', $action->id)->update([
                'status' => 'pending',
                'last_error' => $e->getMessage(),
                'updated_at' => now(),
            ]);

            Log::warning('Pending MikroTik action reconciliation failed', [
                'pending_action_id' => $action->id,
                'router_name' => $action->router_name,
                'username' => $action->username,
                'action' => $action->action,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
