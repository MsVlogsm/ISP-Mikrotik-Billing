<?php

namespace App\Http\Controllers;

use App\Models\ZabbixServer;
use App\Services\ZabbixMonitoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ZabbixServerController extends Controller
{
    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->hasAnyRole(['Super Admin', 'Admin', 'Manager']), 403);
    }

    public function index()
    {
        $this->authorizeAdmin();
        $servers = ZabbixServer::with('creator')->orderBy('name')->paginate(15);
        $activeServers = ZabbixServer::query()->where('is_active', true)->count();
        $activeProblems = (int) ZabbixServer::query()->where('is_active', true)->sum('problem_count');

        return view('xlink.zabbix-servers', compact('servers', 'activeServers', 'activeProblems'));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeAdmin();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'api_url' => ['required', 'url', 'max:2048'],
            'api_token' => ['required', 'string', 'max:4096'],
        ]);

        $parts = parse_url($data['api_url']);
        abort_unless(
            is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && ! empty($parts['host'])
            && ! isset($parts['user'], $parts['pass']),
            422,
            'Enter an HTTPS Zabbix URL without embedded credentials.'
        );

        $server = new ZabbixServer([
            'created_by' => $request->user()->id,
            'name' => trim($data['name']),
            'api_url' => rtrim(trim($data['api_url']), '/'),
            'is_active' => true,
        ]);
        $server->api_token = $data['api_token'];
        $server->save();

        return redirect()->route('ipilot.zabbix')
            ->with('zabbix_message', 'Zabbix server saved with encrypted API token. Run Test or wait for the 5-minute read-only sync.');
    }

    public function test(ZabbixServer $server, ZabbixMonitoringService $monitor): RedirectResponse
    {
        $this->authorizeAdmin();
        try {
            $result = $monitor->syncServer($server);

            return back()->with(
                'zabbix_message',
                'Zabbix connection verified. API version '.$result['version'].'; '.$result['hosts'].' hosts; '.$result['active_problems'].' unresolved problems.'
            );
        } catch (\Throwable $exception) {
            return back()->with('zabbix_error', 'Connection test failed: '.$exception->getMessage());
        }
    }

    public function hosts(ZabbixServer $server, ZabbixMonitoringService $monitor): JsonResponse
    {
        $this->authorizeAdmin();
        abort_unless($server->is_active, 404);

        try {
            return response()->json([
                'success' => true,
                'server' => $server->name,
                'data' => $monitor->hosts($server),
            ]);
        } catch (\Throwable $exception) {
            return response()->json(['success' => false, 'message' => 'Zabbix host data is unavailable.'], 502);
        }
    }

    public function problems(ZabbixServer $server, ZabbixMonitoringService $monitor): JsonResponse
    {
        $this->authorizeAdmin();
        abort_unless($server->is_active, 404);

        try {
            return response()->json([
                'success' => true,
                'server' => $server->name,
                'data' => $monitor->problems($server),
            ]);
        } catch (\Throwable $exception) {
            return response()->json(['success' => false, 'message' => 'Zabbix data is unavailable.'], 502);
        }
    }

    public function toggle(ZabbixServer $server): RedirectResponse
    {
        $this->authorizeAdmin();
        $server->update(['is_active' => ! $server->is_active]);

        return back()->with('zabbix_message', 'Zabbix server status updated.');
    }

    public function destroy(ZabbixServer $server): RedirectResponse
    {
        $this->authorizeAdmin();
        $server->delete();

        return back()->with('zabbix_message', 'Zabbix server and encrypted API token removed.');
    }
}
