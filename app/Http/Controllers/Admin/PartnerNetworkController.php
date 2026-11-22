<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CollectionSummary;
use App\Models\CustomersInfo;
use App\Models\Reseller;
use App\Models\ResellerCommission;
use App\Models\ResellerWalletTransaction;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PartnerNetworkController extends Controller
{
    private function authorizeModule(string $permission): void
    {
        abort_unless(hasAccess(['Super Admin'], [$permission]), 403, 'Unauthorized action.');
    }

    private function period(Request $request): array
    {
        $from = $request->query('from', now()->startOfMonth()->toDateString());
        $to = $request->query('to', now()->toDateString());
        try {
            $fromDate = Carbon::parse($from)->startOfDay();
            $toDate = Carbon::parse($to)->endOfDay();
        } catch (\Throwable) {
            $fromDate = now()->startOfMonth()->startOfDay();
            $toDate = now()->endOfDay();
        }
        if ($fromDate->gt($toDate)) {
            [$fromDate, $toDate] = [$toDate->copy()->startOfDay(), $fromDate->copy()->endOfDay()];
        }
        return [$fromDate, $toDate];
    }

    public function index(Request $request)
    {
        $this->authorizeModule('partner-network');
        [$from, $to] = $this->period($request);
        $resellers = Reseller::with('user')->orderBy('id')->get();

        $collections = CollectionSummary::postedForAccounting()
            ->whereBetween('collection_date', [$from, $to])
            ->whereHas('customer', fn ($q) => $q->whereNotNull('reseller_id'))
            ->sum('collection_amount');
        $commissions = ResellerCommission::whereBetween('created_at', [$from, $to])->sum('amount');
        $payouts = ResellerWalletTransaction::whereBetween('created_at', [$from, $to])
            ->where('description', 'like', 'Commission Payout:%')->sum('amount');

        return view('partner-network.index', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'resellers' => $resellers,
            'stats' => [
                'partners' => $resellers->count(),
                'active' => $resellers->where('status', 'active')->count(),
                'collection' => $collections,
                'commission' => $commissions,
                'payouts' => $payouts,
                'wallet' => (float) $resellers->sum(fn ($r) => (float) $r->balance),
            ],
        ]);
    }

    public function cashflow(Request $request)
    {
        $this->authorizeModule('reseller-cashflow');
        [$from, $to] = $this->period($request);
        $resellerId = $request->query('reseller_id');

        $collectionQuery = CollectionSummary::postedForAccounting()
            ->with('customer:id,customer_unique_id,customer_name,reseller_id')
            ->whereBetween('collection_date', [$from, $to])
            ->whereHas('customer', fn ($q) => $q->whereNotNull('reseller_id'));
        if ($resellerId) {
            $collectionQuery->whereHas('customer', fn ($q) => $q->where('reseller_id', $resellerId));
        }
        $collections = $collectionQuery->latest('collection_date')->get();

        $walletQuery = ResellerWalletTransaction::query()
            ->with('reseller.user')
            ->whereBetween('created_at', [$from, $to]);
        if ($resellerId) {
            $walletQuery->where('reseller_id', $resellerId);
        }
        $wallet = $walletQuery->latest()->get();

        $incoming = (float) $collections->sum('collection_amount')
            + (float) $wallet->where('type', 'credit')->sum('amount');
        $spend = (float) $wallet->where('type', 'debit')->sum('amount');

        return view('partner-network.cashflow', [
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'resellers' => Reseller::with('user')->orderBy('id')->get(),
            'resellerId' => $resellerId,
            'collections' => $collections,
            'wallet' => $wallet,
            'stats' => compact('incoming', 'spend'),
        ]);
    }

    public function ledger(Request $request)
    {
        $this->authorizeModule('reseller-ledger');
        [$from, $to] = $this->period($request);
        $resellerId = $request->query('reseller_id');
        $type = $request->query('type', 'all');
        $search = trim((string) $request->query('q', ''));
        $perPage = max(10, min(100, (int) $request->query('per_page', 25)));

        $rows = collect();

        $collections = CollectionSummary::postedForAccounting()
            ->with('customer:id,customer_unique_id,customer_name,reseller_id')
            ->whereBetween('collection_date', [$from, $to])
            ->whereHas('customer', fn ($q) => $q->whereNotNull('reseller_id'))
            ->latest('collection_date')->get();
        foreach ($collections as $row) {
            if ($resellerId && (int) optional($row->customer)->reseller_id !== (int) $resellerId) continue;
            $rows->push([
                'date' => $row->collection_date,
                'reseller_id' => optional($row->customer)->reseller_id,
                'reseller' => optional(optional($row->customer)->reseller)->user->name ?? 'Partner',
                'customer' => optional($row->customer)->customer_name ?: optional($row->customer)->customer_unique_id,
                'type' => 'incoming',
                'method' => $row->payment_method ?: 'collection',
                'reference' => $row->transaction_id ?: ($row->invoice_no ?: '-'),
                'details' => 'Customer payment collection',
                'by' => $row->collected_by ?: 'System',
                'amount' => (float) $row->collection_amount,
            ]);
        }

        $wallet = ResellerWalletTransaction::query()->with('reseller.user')
            ->whereBetween('created_at', [$from, $to])->latest()->get();
        foreach ($wallet as $row) {
            if ($resellerId && (int) $row->reseller_id !== (int) $resellerId) continue;
            $rows->push([
                'date' => $row->created_at,
                'reseller_id' => $row->reseller_id,
                'reseller' => optional($row->reseller->user)->name ?: ('Partner #'.$row->reseller_id),
                'customer' => '-',
                'type' => $row->type === 'credit' ? 'incoming' : 'spend',
                'method' => $row->reference_type ?: 'wallet',
                'reference' => $row->reference_id ?: '-',
                'details' => $row->description,
                'by' => 'Admin',
                'amount' => (float) $row->amount,
            ]);
        }

        if (in_array($type, ['incoming', 'spend'], true)) {
            $rows = $rows->where('type', $type)->values();
        }
        if ($search !== '') {
            $rows = $rows->filter(function ($row) use ($search) {
                $needle = mb_strtolower($search);
                return str_contains(mb_strtolower((string) $row['reseller']), $needle)
                    || str_contains(mb_strtolower((string) $row['customer']), $needle)
                    || str_contains(mb_strtolower((string) $row['details']), $needle)
                    || str_contains(mb_strtolower((string) $row['reference']), $needle);
            })->values();
        }

        $rows = $rows->sortByDesc(fn ($row) => $row['date'])->values();
        $page = max(1, (int) $request->query('page', 1));
        $total = $rows->count();
        $pageRows = $rows->slice(($page - 1) * $perPage, $perPage)->values();
        $pages = max(1, (int) ceil($total / $perPage));

        if ($request->boolean('export')) {
            return $this->exportCsv($rows, $request->query('export', 'csv'));
        }

        return view('partner-network.ledger', [
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'resellers' => Reseller::with('user')->orderBy('id')->get(),
            'resellerId' => $resellerId, 'type' => $type, 'search' => $search,
            'rows' => $pageRows, 'page' => $page, 'pages' => $pages,
            'total' => $total,
            'incomingTotal' => (float) $rows->where('type', 'incoming')->sum('amount'),
            'spendTotal' => (float) $rows->where('type', 'spend')->sum('amount'),
        ]);
    }

    private function exportCsv($rows, string $format): StreamedResponse
    {
        $filename = 'partner-ledger-'.now()->format('Ymd_His').'.csv';
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date / Time', 'Reseller', 'Customer', 'Type', 'Method / Ref', 'Details / By', 'Amount']);
            foreach ($rows as $row) {
                fputcsv($out, [
                    Carbon::parse($row['date'])->format('Y-m-d H:i:s'),
                    $row['reseller'], $row['customer'], ucfirst($row['type']),
                    trim($row['method'].' / '.$row['reference']),
                    $row['details'].' / '.$row['by'], number_format($row['amount'], 2, '.', ''),
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
