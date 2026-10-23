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

        DB::table('mikrotik_pending_actions')->where('id', $action->id)->increment('attempts');

        try {
            if ($action->action === 'disable') {
                $mikrotik->disablePPPSecret($action->customer_unique_id ?: $action->id, $action->router_name, $action->username, false, true);

                DB::transaction(function () use ($action) {
                    $customer = CustomersInfo::where('customer_unique_id', $action->customer_unique_id)->lockForUpdate()->first();
                    if ($customer && $customer->status === 'active') {
                        $customer->status = 'disable';
                        $customer->disable_count = (int) $customer->disable_count + 1;
                        $customer->save();
                    }

                    PPPSecrets::where('router_name', $action->router_name)
                        ->where('username', $action->username)
                        ->update(['status' => 'disable']);
                });
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
