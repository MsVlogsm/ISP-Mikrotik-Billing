<?php

namespace App\Services;

use App\Models\CustomersInfo;
use App\Models\OltOnuCustomerMapping;
use App\Models\PPPSecrets;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DataIntegrityAuditor
{
    /**
     * Read-only checks. This service never repairs, enables, disables, deletes,
     * or remaps customer/router records automatically.
     *
     * @return array{message:string,metadata:array<string,mixed>}
     */
    public function run(): array
    {
        $checks = [];

        $checks['active_customers_without_ppp'] = [
            'label' => 'Active Customers without a PPPoE account',
            'severity' => 'warning',
            'count' => CustomersInfo::query()->where('status', 'active')
                ->where(function ($query) {
                    $query->whereNull('ppp_user_id')->orWhereDoesntHave('pppUser');
                })->count(),
            'detail' => 'Active customer records with no usable linked PPPoE record.',
        ];

        $checks['active_customers_with_inactive_ppp'] = [
            'label' => 'Active Customers linked to inactive PPPoE accounts',
            'severity' => 'warning',
            'count' => CustomersInfo::query()->where('status', 'active')
                ->whereNotNull('ppp_user_id')
                ->whereDoesntHave('pppUser', fn ($query) => $query->where('status', 'active'))
                ->count(),
            'detail' => 'Review customer and PPPoE status; no status is changed by this audit.',
        ];

        $checks['duplicate_router_ppp_usernames'] = [
            'label' => 'Duplicate PPPoE usernames on the same router',
            'severity' => 'critical',
            'count' => PPPSecrets::query()
                ->select('router_name')
                ->selectRaw('LOWER(TRIM(username)) AS normalized_username')
                ->whereNotNull('router_name')
                ->where('status', '!=', 'removed')
                ->groupBy('router_name')
                ->groupByRaw('LOWER(TRIM(username))')
                ->havingRaw('COUNT(*) > 1')
                ->get()->count(),
            'detail' => 'Each router/username pair should identify one PPP secret for accurate live-session matching.',
        ];

        $checks['orphan_billing_records'] = [
            'label' => 'Billing records without a Customer record',
            'severity' => 'warning',
            'count' => Schema::hasTable('billing_infos') && Schema::hasTable('customers_infos')
                ? DB::table('billing_infos AS b')->whereNotExists(function ($query) {
                    $query->selectRaw('1')->from('customers_infos AS c')
                        ->whereColumn('c.customer_unique_id', 'b.customer_bill_unique_id');
                })->count()
                : 0,
            'detail' => 'Includes only billing rows with no matching customer row, including soft-deleted customer rows in the lookup.',
        ];

        $checks['onu_customer_ppp_mismatch'] = [
            'label' => 'ONU mapping Customer/PPPoE mismatch',
            'severity' => 'critical',
            'count' => Schema::hasTable('olt_onu_customer_mappings') && Schema::hasTable('customers_infos')
                ? DB::table('olt_onu_customer_mappings AS m')
                    ->join('customers_infos AS c', 'c.id', '=', 'm.customer_id')
                    ->whereNotNull('m.ppp_user_id')
                    ->where(function ($query) {
                        $query->whereNull('c.ppp_user_id')
                            ->orWhereColumn('c.ppp_user_id', '!=', 'm.ppp_user_id');
                    })->count()
                : 0,
            'detail' => 'Mapping rows whose linked Customer does not point to the same PPP user; these are reported, never auto-corrected.',
        ];

        $checks['pending_router_actions_over_5m'] = [
            'label' => 'Pending MikroTik actions older than 5 minutes',
            'severity' => 'warning',
            'count' => Schema::hasTable('mikrotik_pending_actions')
                ? DB::table('mikrotik_pending_actions')->where('status', 'pending')
                    ->where('created_at', '<', now()->subMinutes(5))->count()
                : 0,
            'detail' => 'Check router connectivity and queue worker for actions that remain pending.',
        ];

        $checks['queue_jobs_waiting_over_10m'] = [
            'label' => 'Database queue jobs waiting over 10 minutes',
            'severity' => 'warning',
            'count' => config('queue.default') === 'database' && Schema::hasTable('jobs')
                ? DB::table('jobs')->where('created_at', '<', now()->subMinutes(10)->timestamp)->count()
                : 0,
            'detail' => 'A growing backlog may indicate a stopped or unhealthy queue worker.',
        ];

        $checks['monitored_devices_with_stale_health'] = [
            'label' => 'Monitored devices without a fresh health check',
            'severity' => 'warning',
            'count' => Schema::hasTable('network_inventory_devices')
                ? DB::table('network_inventory_devices')->where('monitor_enabled', true)
                    ->where(function ($query) {
                        $query->whereNull('last_checked_at')
                            ->orWhere('last_checked_at', '<', now()->subMinutes(10));
                    })->count()
                : 0,
            'detail' => 'Monitored devices should normally receive health checks at least every minute.',
        ];

        $checks['online_onu_telemetry_over_5m'] = [
            'label' => 'ONUs with stale telemetry (online/unknown status)',
            'severity' => 'warning',
            'count' => OltOnuCustomerMapping::query()->whereIn('status', ['online', 'unknown'])
                ->where(function ($query) {
                    $query->whereNull('last_seen_at')
                        ->orWhere('last_seen_at', '<', now()->subMinutes(5));
                })->count(),
            'detail' => 'An OLT sync failure can leave an old online status visible; review OLT polling before changing mappings.',
        ];

        $findingCount = collect($checks)->sum(fn ($check) => (int) $check['count']);
        $criticalCount = collect($checks)->filter(fn ($check) => $check['severity'] === 'critical')
            ->sum(fn ($check) => (int) $check['count']);

        return [
            'message' => $findingCount === 0
                ? 'Data integrity audit completed: no findings were detected.'
                : "Data integrity audit completed: {$findingCount} finding(s) across ".collect($checks)->filter(fn ($check) => (int) $check['count'] > 0)->count().' check(s).',
            'metadata' => [
                'checked_at' => now()->toIso8601String(),
                'health' => $criticalCount > 0 ? 'critical' : ($findingCount > 0 ? 'warning' : 'healthy'),
                'finding_count' => $findingCount,
                'critical_count' => $criticalCount,
                'checks' => $checks,
            ],
        ];
    }
}