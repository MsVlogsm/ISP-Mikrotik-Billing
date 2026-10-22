<?php
namespace App\Http\Middleware;
use App\Models\Reseller;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
class EnsureUserIsMacReseller {
    public function handle(Request $request, Closure $next): Response {
        if (! $request->user()) return redirect()->route('login');
        $user = $request->user();
        $path = trim($request->path(), '/');
        if ($user->hasRole('Super Admin')) {
            if (in_array($path, ['mac-reseller/choose', 'mac-reseller/select', 'mac-reseller/exit'], true)) return $next($request);
            $resellerId = $request->session()->get('mac_reseller_context_id');
            if (! $resellerId && $path === 'mac-reseller/dashboard') return $next($request);
            $selected = $resellerId ? Reseller::find($resellerId) : null;
            if (! $selected || ! $selected->isMacPanel()) {
                $request->session()->forget('mac_reseller_context_id');
                return redirect()->route('mac-reseller.choose')->with('error', 'Choose a MAC Reseller portal first.');
            }
            return $next($request);
        }
        $reseller = $user->reseller;
        abort_unless($request->user()->hasRole('Reseller') && $reseller, 403, 'MAC Reseller account required.');
        abort_unless($reseller->isActive(), 403, 'Your reseller account is suspended.');
        abort_unless($reseller->isMacPanel(), 403, 'This account is not a MAC Reseller.');
        return $next($request);
    }
}
