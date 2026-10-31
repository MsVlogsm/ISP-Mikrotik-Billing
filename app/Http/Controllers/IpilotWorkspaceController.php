<?php

namespace App\Http\Controllers;

use App\Models\CustomersInfo;
use App\Models\NetworkInventoryDevice;
use App\Models\OltOnuCustomerMapping;
use App\Models\RouterList;
use App\Services\AiEngineerDiagnosticService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class IpilotWorkspaceController extends Controller
{
    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->hasAnyRole(['Super Admin', 'Admin', 'Manager']), 403);
    }

    public function dashboard()
    {
        $this->authorizeAdmin();

        return view('xlink.ipilot', [
            'stats' => [
                'customers' => CustomersInfo::count(),
                'active_customers' => CustomersInfo::whereRaw("LOWER(status) IN ('active','free')")->count(),
                'routers' => RouterList::count(),
                'routers_online' => RouterList::where('action', 'connected')->count(),
                'olts' => NetworkInventoryDevice::where('type', 'olt')->count(),
                'onu_mappings' => OltOnuCustomerMapping::count(),
            ],
        ]);
    }

    public function reports()
    {
        $this->authorizeAdmin();
        return view('xlink.reports-hub');
    }

    public function btrc(Request $request)
    {
        $this->authorizeAdmin();

        $data = Validator::make($request->query(), [
            'operator_code' => ['nullable', 'string', 'max:40'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'status' => ['nullable', 'in:all,active,inactive,disable,pending'],
        ])->validate();

        $from = $data['from'] ?? now()->startOfMonth()->toDateString();
        $to = $data['to'] ?? now()->toDateString();
        $status = $data['status'] ?? 'all';
        $query = CustomersInfo::query()->with(['package', 'billing'])
            ->whereDate('connection_date', '>=', $from)
            ->whereDate('connection_date', '<=', $to)
            ->orderBy('customer_unique_id');

        if ($status !== 'all') $query->whereRaw('LOWER(status) = ?', [$status]);
        $preview = (clone $query)->limit(100)->get();

        return view('xlink.btrc-report', [
            'operatorCode' => $data['operator_code'] ?? '',
            'from' => $from,
            'to' => $to,
            'status' => $status,
            'preview' => $preview,
            'total' => (clone $query)->count(),
        ]);
    }

    public function exportBtrc(Request $request)
    {
        $this->authorizeAdmin();

        $data = $request->validate([
            'operator_code' => ['required', 'string', 'max:40'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'status' => ['required', 'in:all,active,inactive,disable,pending'],
        ]);

        $query = CustomersInfo::query()->with(['package', 'billing'])
            ->whereDate('connection_date', '>=', $data['from'])
            ->whereDate('connection_date', '<=', $data['to'])
            ->orderBy('customer_unique_id');
        if ($data['status'] !== 'all') $query->whereRaw('LOWER(status) = ?', [$data['status']]);

        $filename = 'xlink-btrc-draft-'.$data['operator_code'].'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($query, $data): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['X-Link LTD - BTRC Report Draft (Not Official Template)', 'Operator Code', $data['operator_code']]);
            fputcsv($out, ['Period', $data['from'], $data['to'], 'Status', $data['status']]);
            fputcsv($out, []);
            fputcsv($out, [
                'Customer ID', 'Customer Name', 'Mobile', 'Address',
                'Package', 'Monthly Rent (BDT)', 'Account Status',
                'Connection Date', 'Expiry Date', 'POP / Zone', 'Server Label',
            ]);

            foreach ($query->cursor() as $customer) {
                fputcsv($out, [
                    $customer->customer_unique_id,
                    $customer->customer_name,
                    $customer->mobile,
                    $customer->address,
                    $customer->package?->package,
                    $customer->billing?->monthly_rent,
                    $customer->status,
                    $customer->connection_date,
                    $customer->billing?->auto_disable_date,
                    $customer->zone_name,
                    $customer->server_label,
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function terminal()
    {
        $this->authorizeAdmin();
        return view('xlink.ipilot-terminal');
    }

    public function terminalChat(Request $request, AiEngineerDiagnosticService $engineer)
    {
        $this->authorizeAdmin();

        $data = $request->validate([
            'command' => ['required', 'string', 'max:4000'],
            'history' => ['nullable', 'array', 'max:20'],
        ]);

        $input = trim($data['command']);
        $command = strtolower(strtok($input, " \t") ?: $input);
        $argument = trim(substr($input, strlen(strtok($input, " \t") ?: $input)));
        $commands = [
            '/help' => 'List the available read-only diagnostic commands and explain their limits.',
            '/status' => 'Give a current, evidence-based summary of X-Link network and billing service health.',
            '/connect' => 'Check recorded connectivity and freshness for routers and OLT devices. Do not change connections.',
            '/online' => 'Summarize currently verified live PPPoE sessions and online network devices. State data freshness.',
            '/offline' => 'List routers and devices with recent offline evidence; distinguish stale records from confirmed outages.',
            '/alarms' => 'Summarize the latest network alarms and unresolved events from stored records.',
            '/onu' => 'Inspect ONU/customer mapping and last optical readings'.($argument !== '' ? ' for '.$argument : '').'.',
            '/optical' => 'Summarize recent optical RX readings and signal warnings from saved telemetry.',
            '/pppoe' => 'Inspect PPPoE session status'.($argument !== '' ? ' for '.$argument : '').' using read-only router checks.',
            '/bills' => 'Inspect customer billing state and expiry'.($argument !== '' ? ' for customer '.$argument : '').'. Do not record payments.',
            '/vlan' => 'Explain VLAN inventory visibility and current read-only limits. Do not issue router commands or alter VLAN configuration.',
            '/backup' => 'Summarize available backup status from configured system diagnostics; do not run restore or configuration changes.',
            '/hotspot' => 'Summarize available hotspot records and status without modifying router configuration.',
            '/suggest' => 'Suggest the next safest read-only network diagnostics based on current recorded evidence.',
        ];

        if (str_starts_with($input, '/')) {
            if ($command === '/vlan') {
                return response()->json([
                    'success' => true,
                    'read_only' => true,
                    'reply' => 'VLAN read-only note: this iPilot release does not yet have a verified VLAN inventory data source. No MikroTik CLI/API command is executed from this terminal. Use the existing MikroTik Interface / VLAN setup screen for separately reviewed configuration.',
                ]);
            }

            if ($command === '/help') {
                return response()->json([
                    'success' => true,
                    'read_only' => true,
                    'reply' => "Read-only commands: /status, /connect, /online, /offline, /alarms, /onu [ID], /optical, /pppoe [username], /bills [customer ID], /vlan, /backup, /hotspot, /suggest. Configuration, terminal shell, VLAN changes, reboot, provisioning and payment writes are disabled in this terminal.",
                ]);
            }
            if (! isset($commands[$command])) {
                return response()->json([
                    'success' => false,
                    'read_only' => true,
                    'message' => 'This command is not enabled. iPilot terminal only runs approved read-only diagnostics; no shell or device write commands are accepted.',
                ], 422);
            }
            $question = $commands[$command];
        } else {
            $question = $input;
            if (preg_match('/\b(configure|reboot|restart|shutdown|enable|disable|set vlan|change vlan|restore backup|execute command|run command|mikrotik-api\/exec)\b/i', $question)) {
                return response()->json([
                    'success' => false,
                    'read_only' => true,
                    'message' => 'Device configuration and arbitrary command execution are disabled. Use the existing approved configuration workflow with separate review and authorization.',
                ], 422);
            }
        }

        $result = $engineer->chat($question, null, $data['history'] ?? []);

        return response()->json([
            'success' => true,
            'read_only' => true,
            'reply' => $result,
        ]);
    }
}
