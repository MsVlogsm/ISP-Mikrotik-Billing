<?php

namespace App\Http\Controllers;

use App\Models\MikrotikBridgeHostMovement;
use App\Models\MikrotikBridgeHostSnapshot;
use App\Models\RouterList;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MikrotikBridgeHostController extends Controller
{
    public function index(Request $request): View
    {
        $routers = RouterList::query()
            ->orderBy('router_name')
            ->get(['id', 'router_name', 'action']);

        $requestedRouterId = (int) $request->query('router_id', $routers->first()?->id ?? 0);
        $selectedRouter = $routers->firstWhere('id', $requestedRouterId) ?? $routers->first();

        $latestAttempt = null;
        $latestSuccessfulSnapshot = null;
        $entries = null;
        $movements = null;

        if ($selectedRouter) {
            $latestAttempt = MikrotikBridgeHostSnapshot::query()
                ->where('router_list_id', $selectedRouter->id)
                ->orderByDesc('sampled_at')
                ->orderByDesc('id')
                ->first();

            $latestSuccessfulSnapshot = MikrotikBridgeHostSnapshot::query()
                ->where('router_list_id', $selectedRouter->id)
                ->whereIn('status', ['ok', 'empty'])
                ->orderByDesc('sampled_at')
                ->orderByDesc('id')
                ->first();

            $entries = $latestSuccessfulSnapshot?->entries()
                ->orderBy('mac_address')
                ->paginate(50)
                ->withQueryString();

            $movements = MikrotikBridgeHostMovement::query()
                ->where('router_list_id', $selectedRouter->id)
                ->orderByDesc('moved_at')
                ->paginate(25)
                ->withQueryString();
        }

        return view('mikrotik.bridge-hosts', compact(
            'routers',
            'selectedRouter',
            'latestAttempt',
            'latestSuccessfulSnapshot',
            'entries',
            'movements'
        ));
    }
}
