<?php

namespace App\Jobs;

use App\Models\CustomersInfo;
use App\Services\Olt\CustomerOnuRealtimeSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncCustomerOnu implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public int $timeout = 180;

    public function __construct(
        public int $customerId,
        public int $userId,
        public string $lockOwner,
    ) {}

    public static function statusKey(int $userId, int $customerId): string
    {
        return 'customer-onu-realtime-sync:status:'.$userId.':'.$customerId;
    }

    public function handle(CustomerOnuRealtimeSyncService $service): void
    {
        $statusKey = self::statusKey($this->userId, $this->customerId);
        Cache::put($statusKey, ['status' => 'running'], now()->addMinutes(15));

        try {
            $customer = CustomersInfo::query()
                ->with('pppUser')
                ->findOrFail($this->customerId);

            $result = $service->sync($customer);
            $signal = $result['rx_power'] !== null
                ? 'RX '.number_format((float) $result['rx_power'], 2).' dBm'
                : 'এই poll-এ নতুন RX reading পাওয়া যায়নি';
            $session = $result['session_online'] ? 'Live PPPoE session' : 'Last-known PPPoE MAC';
            $message = "{$customer->customer_unique_id}: {$result['olt_name']} · PON {$result['pon']} / ONU {$result['onu_id']} · {$signal} · {$session} যাচাই হয়েছে।";

            if ($result['opm_fresh']) {
                $level = 'success';
            } else {
                $level = 'warning';
                $reason = ($result['opm_status'] ?? null) === 'olt_reports_offline'
                    ? 'OLT ONU-টিকে Offline দেখাচ্ছে; optical reading অনুপস্থিত।'
                    : 'OLT এই ONU-এর জন্য optical reading দেয়নি; আগের reading অপরিবর্তিত আছে।';
                $message .= ' '.$reason;
            }

            Cache::put($statusKey, [
                'status' => 'completed',
                'level' => $level,
                'message' => $message,
            ], now()->addMinutes(15));
        } catch (Throwable $exception) {
            Log::warning('Single customer ONU sync failed', [
                'user_id' => $this->userId,
                'customer_id' => $this->customerId,
                'error' => $exception->getMessage(),
            ]);

            Cache::put($statusKey, [
                'status' => 'failed',
                'message' => $exception->getMessage(),
            ], now()->addMinutes(15));
        } finally {
            $this->releaseSyncLock();
        }
    }

    public function failed(Throwable $exception): void
    {
        Cache::put(self::statusKey($this->userId, $this->customerId), [
            'status' => 'failed',
            'message' => 'ONU sync worker ব্যর্থ হয়েছে। আবার চেষ্টা করুন।',
        ], now()->addMinutes(15));

        Log::error('Single customer ONU sync job failed', [
            'user_id' => $this->userId,
            'customer_id' => $this->customerId,
            'error' => $exception->getMessage(),
        ]);

        $this->releaseSyncLock();
    }

    private function releaseSyncLock(): void
    {
        try {
            Cache::restoreLock('customer-onu-realtime-sync:'.$this->customerId, $this->lockOwner)->release();
        } catch (Throwable) {
        }
    }
}
