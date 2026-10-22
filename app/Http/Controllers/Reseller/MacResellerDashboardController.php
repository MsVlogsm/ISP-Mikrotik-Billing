<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Models\CustomersInfo;
use App\Models\Reseller;
use Illuminate\Http\Request;

class MacResellerDashboardController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        if ($user->hasRole('Super Admin')) {
            $resellerId = session('mac_reseller_context_id');
            if (! $resellerId) return redirect()->route('mac-reseller.choose');
            $reseller = Reseller::find($resellerId);
        } else {
            $reseller = $user->reseller;
        }
        abort_unless($reseller && $reseller->isMacPanel(), 403);

        $base = CustomersInfo::query()->where('reseller_id', $reseller->id);
        $totalCustomers = (clone $base)->count();
        $activeCustomers = (clone $base)->where('status', 'active')->count();
        $disabledCustomers = (clone $base)->where('status', 'disable')->count();
        $pendingCustomers = (clone $base)->where('status', 'pending')->count();
        $recentCustomers = (clone $base)->latest('created_at')->limit(8)->get();

        return view('mac-reseller.dashboard', compact(
            'reseller', 'totalCustomers', 'activeCustomers', 'disabledCustomers', 'pendingCustomers', 'recentCustomers'
        ));
    }

    public function choose()
    {
        abort_unless(auth()->user()?->hasRole('Super Admin'), 403);
        $resellers = Reseller::with('user')->withCount('customers')->where('panel_type', 'mac')->orderBy('company')->orderBy('id')->get();
        $selectedResellerId = session('mac_reseller_context_id');
        return view('mac-reseller.admin-select', compact('resellers', 'selectedResellerId'));
    }

    public function select(Request $request)
    {
        abort_unless(auth()->user()?->hasRole('Super Admin'), 403);
        $data = $request->validate(['reseller_id' => ['required', 'integer', 'exists:resellers,id']]);
        $reseller = Reseller::findOrFail($data['reseller_id']);
        abort_unless($reseller->isMacPanel(), 422, 'Selected account is not a MAC Reseller.');
        $request->session()->put('mac_reseller_context_id', $reseller->id);
        return redirect()->route('mac-reseller.dashboard')->with('success', 'Opened MAC Reseller portal: '.($reseller->company ?: '#'.$reseller->id));
    }

    public function exit(Request $request)
    {
        abort_unless(auth()->user()?->hasRole('Super Admin'), 403);
        $request->session()->forget('mac_reseller_context_id');
        return redirect()->route('mac-reseller.choose')->with('success', 'Exited MAC Reseller portal.');
    }
}
