<?php

namespace App\Http\Controllers;

use App\Models\CollectionSummary;
use App\Models\CustomersInfo;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class ApiV1Controller extends Controller
{
    /**
     * List customers for trusted X-Link Android/backend integrations.
     * GET /api/v1/customers?status=active
     */
    public function customers(Request $request): JsonResponse
    {
        if ($denied = $this->authorizeTokenAbility($request, 'api:customers:read')) {
            return $denied;
        }

        $status = strtolower((string) $request->query('status', 'active'));
        $allowedStatuses = ['active', 'inactive', 'pending', 'deleted', 'disable', 'free', 'all'];

        if (! in_array($status, $allowedStatuses, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid status filter.',
                'allowed_statuses' => $allowedStatuses,
            ], 422);
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));

        $query = CustomersInfo::query()
            ->with(['pppUser', 'package', 'billing'])
            ->orderBy('customer_unique_id');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $page = $query->paginate($perPage)->withQueryString();

        $data = $page->getCollection()->map(function (CustomersInfo $customer): array {
            $ppp = $customer->pppUser;
            $billing = $customer->billing;
            $package = $customer->package;

            return [
                'customerId' => $customer->customer_unique_id,
                'name' => $customer->customer_name,
                'mobile' => $customer->mobile,
                'email' => $customer->email,
                'address' => $customer->address,
                'status' => $customer->status,
                'connectionDate' => $customer->connection_date,
                'pppoe' => $ppp ? [
                    'username' => $ppp->username,
                    'routerName' => $ppp->router_name,
                    'service' => $ppp->service,
                    'profile' => $ppp->profile,
                    'status' => $ppp->status,
                    'remoteIp' => $ppp->ppp_remote_ip,
                ] : null,
                'package' => $package ? [
                    'id' => $package->id,
                    'name' => $package->package,
                    'price' => $package->price,
                ] : null,
                'billing' => $billing ? [
                    'monthlyRent' => (float) $billing->monthly_rent,
                    'dueAmount' => (float) $billing->due_amount,
                    'expiryDate' => $billing->auto_disable_date,
                ] : null,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * Record a confirmed payment through the existing billing service.
     * POST /api/v1/payments
     *
     * Call this endpoint only after the payment provider/backend has verified
     * that the transaction succeeded. Never embed the privileged bearer token
     * in a publicly distributed Android APK.
     */
    public function createPayment(Request $request, PaymentService $paymentService): JsonResponse
    {
        if ($denied = $this->authorizeTokenAbility($request, 'api:payments:create')) {
            return $denied;
        }

        // Never accept a client claim as proof of payment. Keep writes disabled
        // until a trusted server-side payment-provider verification flow is configured.
        if (! (bool) config('services.android_api.payment_writes_enabled', false)) {
            return response()->json([
                'success' => false,
                'status' => 'payment_verification_not_configured',
                'message' => 'Payment recording is disabled until server-side payment-provider verification is configured.',
            ], 503);
        }

        $request->merge([
            'method' => strtolower(trim((string) $request->input('method', ''))),
        ]);

        $validated = $request->validate([
            'customerId' => ['required', 'string', 'max:100'],
            'invoiceId' => ['required', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'method' => ['required', 'string', 'in:bkash,nagad,rocket,upay,sslcommerz'],
            'transactionId' => ['required', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $customer = CustomersInfo::query()
            ->where('customer_unique_id', trim($validated['customerId']))
            ->first();

        if (! $customer) {
            return response()->json([
                'success' => false,
                'message' => 'Customer not found.',
            ], 404);
        }

        if (! $customer->billing) {
            return response()->json([
                'success' => false,
                'message' => 'Billing information is not configured for this customer.',
            ], 422);
        }

        $transactionId = trim($validated['transactionId']);
        $existing = CollectionSummary::query()
            ->where('transaction_id', $transactionId)
            ->first();

        if ($existing) {
            if (
                $existing->payment_status === 'paid'
                && $existing->customer_collection_unique_id === $customer->customer_unique_id
                && (float) $existing->collection_amount === (float) $validated['amount']
            ) {
                return response()->json([
                    'success' => true,
                    'status' => 'duplicate',
                    'message' => 'This payment was already processed.',
                    'transactionId' => $transactionId,
                ]);
            }

            return response()->json([
                'success' => false,
                'status' => 'transaction_id_conflict',
                'message' => 'This transaction ID has already been used.',
            ], 409);
        }

        $metadata = [
            'source' => 'xlink_android_api',
            'external_invoice_id' => trim($validated['invoiceId']),
            'notes' => $validated['notes'] ?? null,
            'api_token_id' => $request->user()->currentAccessToken()->getKey(),
        ];

        try {
            $processed = $paymentService->processSuccessPayment(
                $customer,
                (float) $validated['amount'],
                strtolower($validated['method']),
                $transactionId,
                $metadata
            );

            if (! $processed) {
                return response()->json([
                    'success' => true,
                    'status' => 'duplicate',
                    'message' => 'This payment was already processed.',
                    'transactionId' => $transactionId,
                ]);
            }

            $customer->refresh();
            $billing = $customer->billing;

            return response()->json([
                'success' => true,
                'status' => 'success',
                'message' => 'Payment recorded successfully.',
                'customerId' => $customer->customer_unique_id,
                'invoiceId' => $validated['invoiceId'],
                'transactionId' => $transactionId,
                'amount' => (float) $validated['amount'],
                'method' => strtolower($validated['method']),
                'customerStatus' => $customer->status,
                'dueAmount' => $billing ? (float) $billing->due_amount : null,
            ], 201);
        } catch (\Throwable $e) {
            Log::error('X-Link Android API payment failed.', [
                'customer_id' => $customer->customer_unique_id,
                'transaction_id' => $transactionId,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Payment could not be processed. Check the customer billing record and server logs.',
            ], 500);
        }
    }

    private function authorizeTokenAbility(Request $request, string $ability): ?JsonResponse
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        // Reject browser/session authentication. These endpoints require a real,
        // scoped Sanctum personal access token.
        if (! $user || ! $token instanceof PersonalAccessToken) {
            return response()->json([
                'success' => false,
                'message' => 'A valid API bearer token is required.',
            ], 401);
        }

        if (! $token->can($ability)) {
            return response()->json([
                'success' => false,
                'message' => 'This API token does not have permission for this operation.',
            ], 403);
        }

        return null;
    }
}
