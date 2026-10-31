<?php

namespace App\Http\Controllers;

use App\Models\CustomersInfo;
use App\Models\NetworkInventoryDevice;
use App\Models\OltOnuCustomerMapping;
use App\Models\RouterList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class ApiV2Controller extends Controller
{
    private function authorized(Request $request, string $scope): ?JsonResponse
    {
        $token = $request->user()?->currentAccessToken();
        if (! $token instanceof PersonalAccessToken) {
            return response()->json(['success' => false, 'message' => 'A valid bearer token is required.'], 401);
        }
        if (! $token->can($scope) && ! $token->can('*')) {
            return response()->json(['success' => false, 'message' => 'This token does not have the required scope: '.$scope], 403);
        }
        return null;
    }

    public function customers(Request $request): JsonResponse
    {
        if ($denied = $this->authorized($request, 'customers:read')) return $denied;

        $status = strtolower((string) $request->query('status', 'all'));
        if (! in_array($status, ['active', 'inactive', 'disable', 'disabled', 'pending', 'all'], true)) {
            return response()->json(['success' => false, 'message' => 'Invalid status filter.'], 422);
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));
        $query = CustomersInfo::query()->with(['package', 'billing'])->orderBy('id');
        if ($status !== 'all') $query->whereRaw('LOWER(status) = ?', [$status]);

        $page = $query->paginate($perPage);
        $includeBilling = $request->user()->currentAccessToken()->can('billing:read');
        $includePackages = $request->user()->currentAccessToken()->can('packages:read');
        $includeContact = $request->user()->currentAccessToken()->can('customers:contact:read');

        return response()->json([
            'success' => true,
            'data' => $page->getCollection()->map(function (CustomersInfo $customer) use ($includeBilling, $includePackages, $includeContact) {
                return [
                    'customer_id' => $customer->customer_unique_id,
                    'name' => $customer->customer_name,
                    'mobile' => $includeContact ? $customer->mobile : null,
                    'status' => $customer->status,
                    'connection_date' => $customer->connection_date,
                    'package' => $includePackages && $customer->package ? [
                        'name' => $customer->package->package,
                        'monthly_price' => $customer->package->price,
                    ] : null,
                    'billing' => $includeBilling && $customer->billing ? [
                        'type' => $customer->billing->billing_type,
                        'monthly_rent' => (float) $customer->billing->monthly_rent,
                        'due_amount' => (float) $customer->billing->due_amount,
                        'expiry_date' => $customer->billing->auto_disable_date,
                    ] : null,
                ];
            })->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function routers(Request $request): JsonResponse
    {
        if ($denied = $this->authorized($request, 'network:read')) return $denied;

        return response()->json([
            'success' => true,
            'data' => RouterList::query()->orderBy('router_name')->get([
                'id', 'router_name', 'action', 'latitude', 'longitude',
                'location', 'last_latency_ms', 'last_checked_at',
            ]),
        ]);
    }

    public function olts(Request $request): JsonResponse
    {
        if ($denied = $this->authorized($request, 'network:read')) return $denied;

        return response()->json([
            'success' => true,
            'data' => NetworkInventoryDevice::query()->where('type', 'olt')->orderBy('name')
                ->get(['id', 'name', 'ip_address', 'vendor', 'model', 'status', 'health_status', 'onu_total', 'onu_online', 'last_checked_at']),
        ]);
    }

    public function onus(Request $request): JsonResponse
    {
        if ($denied = $this->authorized($request, 'network:read')) return $denied;

        $rows = OltOnuCustomerMapping::query()->with(['olt', 'customer'])
            ->latest('id')->paginate(min(100, max(1, (int) $request->query('per_page', 50))));

        return response()->json([
            'success' => true,
            'data' => $rows->getCollection()->map(fn ($mapping) => [
                'olt' => $mapping->olt?->name,
                'pon_port' => $mapping->pon_port,
                'onu_id' => $mapping->onu_id,
                'serial' => $mapping->onu_serial,
                'mac' => $mapping->onu_mac,
                'status' => $mapping->status,
                'rx_power_dbm' => $mapping->rx_power,
                'customer_id' => $mapping->customer?->customer_unique_id,
            ])->values(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        if ($denied = $this->authorized($request, 'reports:read')) return $denied;

        return response()->json([
            'success' => true,
            'generated_at' => now()->toIso8601String(),
            'customers' => [
                'total' => CustomersInfo::count(),
                'active' => CustomersInfo::whereRaw("LOWER(status) IN ('active','free')")->count(),
                'inactive' => CustomersInfo::whereRaw("LOWER(status) IN ('inactive','disable','disabled')")->count(),
            ],
            'network' => [
                'routers' => RouterList::count(),
                'routers_connected' => RouterList::where('action', 'connected')->count(),
                'olts' => NetworkInventoryDevice::where('type', 'olt')->count(),
                'onus_mapped' => OltOnuCustomerMapping::count(),
            ],
        ]);
    }
}
