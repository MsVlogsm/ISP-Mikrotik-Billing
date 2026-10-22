<?php

namespace App\Livewire\Reseller;

use App\Models\CustomersInfo;
use App\Models\PaymentSummary;
use App\Models\PPPSecrets;
use App\Models\RouterList;
use App\Models\Reseller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Livewire\Attributes\On;
use Livewire\Component;
use Yajra\DataTables\Facades\DataTables;

class ResellerCustomerList extends Component
{
    public $editingCustomerId = null;

    public $routers = [];

    private function currentReseller(): ?Reseller
    {
        $user = auth()->user();
        if ($user?->hasRole('Super Admin')) {
            $id = session('mac_reseller_context_id');
            return $id ? Reseller::where('panel_type', 'mac')->find($id) : null;
        }
        return $user?->reseller;
    }

    public function render()
    {
        $reseller = $this->currentReseller();
        if (! $reseller) {
            abort(403);
        }

        $this->routers = RouterList::query()->select('id', 'router_name')->where('action', 'connected')->orderBy('router_name')->get();
        $panelRoutePrefix = $reseller->isMacPanel() ? 'mac-reseller' : 'reseller';

        return view('livewire.reseller.customer-list', compact('panelRoutePrefix'))->layout('layouts.app');
    }

    public function getData(Request $request)
    {
        $reseller = $this->currentReseller();
        if (! $reseller) {
            abort(403, 'Unauthorized action.');
        }

        $statusFilter = ['pending', 'disable', 'free', 'inactive'];

        $data = CustomersInfo::query()
            ->where('reseller_id', $reseller->id)
            ->with(['billing', 'pppUser', 'customerAddress', 'official', 'package'])
            ->select('customers_infos.*');

        // Router Filter
        if ($request->router_name) {
            $data->whereHas('pppUser', function ($q) use ($request) {
                $q->where('p_p_p_secrets.router_name', $request->router_name);
            });
        }

        // Filter logic
        switch ($request->filter) {
            case 'all':
                break;

            case 'all_active':
                $data->whereNotIn('status', $statusFilter);
                break;

            case 'without_collection':
                $data->whereHas('billing', function ($q) {
                    $q->where('paid_amount', 0);
                })->whereNotIn('status', $statusFilter);
                break;

            case 'collection':
                $data->whereHas('billing', function ($q) {
                    $q->where('paid_amount', '>', 0);
                })->whereNotIn('status', $statusFilter);
                break;

            case 'pending':
            case 'disable':
            case 'free':
            case 'inactive':
                $data->where('status', $request->filter);
                break;

            default:
                $data->whereNotIn('status', $statusFilter);
        }

        return DataTables::eloquent($data)
            ->addIndexColumn()
            ->addColumn('customer_identity', function ($row) {
                // Round avatar: photo if exists, else coloured initials
                $initials = mb_strtoupper(mb_substr($row->customer_name ?? '', 0, 1, 'UTF-8'), 'UTF-8');
                $colors = ['#4f46e5', '#0ea5e9', '#16a34a', '#ea580c', '#dc2626', '#7c3aed', '#0891b2', '#ca8a04'];
                $bgColor = $colors[abs(crc32($row->customer_unique_id)) % count($colors)];

                if (! empty($row->photo_url)) {
                    $avatar = '<img src="'.e(asset('storage/'.$row->photo_url)).'" '
                        .'alt="'.e($row->customer_name).'" '
                        .'class="avatar avatar-2xl rounded-circle">';
                } else {
                    $avatar = '<div class="avatar avatar-2xl rounded-circle d-flex align-items-center justify-content-center fw-bold text-white" style="background:'.$bgColor.';font-size:15px;">'
                        .$initials.'</div>';
                }

                return '<div class="d-flex align-items-center gap-2">'.
                    $avatar.
                    '<div>'.
                    '<div class="fw-bold text-dark">'.e($row->customer_name).
                    (! empty($row->contact_person && $row->contact_person != '-') ? '<small class="text-muted"> ('.e($row->contact_person).')</small>' : '').'</div>'.

                    '<div class="small">
                        <span class="badge bg-secondary bg-opacity-10 text-secondary pe-2">'.e($row->customer_unique_id).'</span> '.

                        (! empty($row->mobile)
                            ? '<span class="text-muted"><i class="bi bi-telephone text-success"></i> '.e($row->mobile).'</span> '
                            : '').

                        (! empty($row->contact_email)
                            ? '<span class="text-muted"><i class="bi bi-envelope text-success"></i> '.e($row->contact_email).'</span>'
                            : '').

                    '</div>'.
                    '</div></div>';
            })
            ->addColumn('customers_address', function ($row) {
                $formattedAddresses = [];
                foreach ($row->customerAddress as $address) {
                    $parts = array_filter([$address->input_type_text, $address->input_type_dropdown, $address->input_type_textarea]);
                    $formattedAddresses[] = implode(', ', array_map('e', $parts));
                }

                return implode(', ', $formattedAddresses);
            })
            ->addColumn('billing_breakdown', function ($row) {
                $rent = number_format($row->billing?->monthly_rent ?? 0, 2);
                $p_due = number_format($row->billing?->previous_due ?? 0, 2);
                $a_charge = number_format($row->billing?->additional_charge ?? 0, 2);
                $vat = $row->billing?->vat ?? 0;
                $disc = number_format($row->billing?->discount ?? 0, 2);
                $adv = number_format($row->billing?->advance ?? 0, 2);

                return '<div class="small text-muted" style="font-size: 0.7rem; line-height: 1.4;">'.
                       '<div><i class="bi bi-calendar3 me-1"></i>Rent: <span class="text-dark fw-bold">'.$rent.',</span></div>'.
                       '<div><i class="bi bi-exclamation-triangle me-1"></i>P.Due: <span class="text-dark fw-bold">'.$p_due.',</span></div>'.
                       '<div><i class="bi bi-plus-circle me-1"></i>Add: <span class="text-dark fw-bold">'.$a_charge.'</span> | <i class="bi bi-percent me-1"></i>Vat: <span class="text-dark fw-bold">'.$vat.',</span></div>'.
                       '<div><i class="bi bi-tag me-1"></i>Disc: <span class="text-danger fw-bold">'.$disc.'</span> | <i class="bi bi-wallet-fill me-1"></i>Adv: <span class="text-success fw-bold">'.$adv.'</span></div>'.
                       '</div>';
            })
            ->addColumn('connection_details', function ($row) {
                return '<div class="mb-1 fw-bold text-info"><i class="bi bi-person-fill"></i> '.e($row->pppUser->username ?? 'N/A').'</div>'.
                       '<span class="badge bg-info bg-opacity-10 text-white border border-info border-opacity-25">'.
                       '<i class="bi bi-router-fill me-1"></i>'.e($row->pppUser->router_name ?? 'N/A').'</span>';
            })
            ->addColumn('billing_summary', function ($row) {
                $bill = number_format($row->billing?->total_amount ?? 0, 2);
                $paid = number_format($row->billing?->paid_amount ?? 0, 2);
                $due = number_format($row->billing?->due_amount ?? 0, 2);

                return '<div class="billing-card small">'.
                       '<div class="d-flex justify-content-between"><span>Bill:</span> <span class="fw-bold text-primary">'.$bill.'</span></div>'.
                       '<div class="d-flex justify-content-between"><span>Paid:</span> <span class="fw-bold text-success">'.$paid.'</span></div>'.
                       '<hr class="my-1">'.
                       '<div class="d-flex justify-content-between"><span>Due:</span> <span class="fw-bold text-danger">'.$due.'</span></div>'.
                       '</div>';
            })
            ->addColumn('disable_details', function ($row) {
                $statusClass = $row->billing?->auto_disable == 1 ? 'bg-danger text-white' : 'bg-light text-muted';
                $disableDate = $row->billing?->auto_disable_date ? Carbon::parse($row->billing->auto_disable_date)->format('d-M-Y') : 'N/A';

                return '<div class="small fw-bold mb-1">Count: '.$row->disable_count.'</div>'.
                       '<span class="badge badge-soft '.$statusClass.' mb-1">Auto: '.($row->billing?->auto_disable == 1 ? 'Yes' : 'No').'</span>'.
                       '<div class="text-primary fw-bold" style="font-size: 0.75rem"><i class="bi bi-calendar-x me-1"></i>'.$disableDate.'</div>'.
                       '<div class="text-muted" style="font-size: 0.7rem">Ext: '.($row->billing?->auto_disable_month ?? 0).' Mon</div>';
            })
            ->addColumn('action', function ($row) {
                $encryptedId = encrypt($row->customer_unique_id);
                $delete_btn = in_array($row->status, ['active', 'free'], true)
                    ? '<button class="btn btn-secondary" disabled title="'.($row->status === 'free' ? 'Administrator-managed client' : 'Disable before removing').'"><i class="bi bi-trash"></i></button>'
                    : '<button onclick="confirmDeleteCustomer(\''.$encryptedId.'\')" class="btn btn-danger" title="Remove client"><i class="bi bi-trash"></i></button>';
                $customers_edit_btn = '<button onclick="Livewire.dispatch(\'open-edit-customer\', { id: \''.$encryptedId.'\' })" class="edit btn btn-primary" title="Edit client"><i class="bi bi-pencil-square"></i></button>';
                if (in_array($row->status, ['disable', 'pending'], true)) {
                    $statusBtn = '<button onclick="confirmActivateCustomer(\''.$encryptedId.'\')" class="btn btn-success" title="Activate client"><i class="bi bi-person-check-fill"></i></button>';
                } elseif ($row->status === 'free') {
                    $statusBtn = '<button class="btn btn-secondary" disabled title="Free client status is controlled by the administrator"><i class="bi bi-shield-lock"></i></button>';
                } else {
                    $statusBtn = '<button onclick="confirmDisableCustomer(\''.$encryptedId.'\')" class="btn btn-warning" title="Disable client"><i class="bi bi-person-fill-slash"></i></button>';
                }

                return '<div class="action-btns d-flex justify-content-center">'.$customers_edit_btn.$statusBtn.$delete_btn.'</div>';
            })
            ->rawColumns(['customer_identity', 'customers_address', 'billing_breakdown', 'connection_details', 'billing_summary', 'action', 'disable_details'])
            ->make(true);
    }

    #[On('activate-reseller-customer')]
    public function activateCustomer($id): void
    {
        $id = is_array($id) ? ($id['id'] ?? $id) : $id;
        $reseller = $this->currentReseller();
        abort_unless($reseller && (auth()->user()?->hasRole('Super Admin') || (auth()->user()?->hasRole('Reseller') && $reseller->isActive())), 403);

        try {
            $uniqueId = decrypt($id);
            $customer = CustomersInfo::where('reseller_id', $reseller->id)
                ->where('customer_unique_id', $uniqueId)
                ->with(['pppUser', 'billing'])
                ->firstOrFail();

            if ($customer->status === 'active') {
                flash()->addInfo('Client is already active.');
                $this->dispatch('customer-action-done');
                return;
            }
            if (! in_array($customer->status, ['pending', 'disable'], true)) {
                flash()->addError('Only pending or disabled clients can be activated.');
                $this->dispatch('customer-action-done');
                return;
            }
            if (! $customer->billing) {
                flash()->addError('Cannot activate this client: billing information is missing.');
                $this->dispatch('customer-action-done');
                return;
            }

            $bill = $customer->billing;
            PaymentSummary::firstOrCreate(
                [
                    'customer_payment_unique_id' => $uniqueId,
                    'summary_date' => Carbon::now()->startOfMonth()->toDateString(),
                ],
                [
                    'monthly_rent' => $bill->monthly_rent,
                    'additional_charge' => $bill->additional_charge,
                    'vat' => $bill->vat,
                    'previous_due' => $bill->previous_due,
                    'advance' => $bill->advance,
                    'discount' => $bill->discount,
                ]
            );

            \DB::transaction(function () use ($customer) {
                $customer->status = 'active';
                $customer->save();
                if ($customer->pppUser) {
                    PPPSecrets::where('id', $customer->ppp_user_id)->update(['status' => 'active']);
                }
            });

            $ppp = $customer->pppUser;
            $queued = false;
            if ($ppp && filled($ppp->router_name) && filled($ppp->username)) {
                $routerConnected = RouterList::where('router_name', $ppp->router_name)
                    ->where('action', 'connected')->exists();
                if ($routerConnected) {
                    try {
                        app(\App\Http\Controllers\MikrotikController::class)
                            ->enablePPPSecret($uniqueId, $ppp->router_name, $ppp->username);
                        if (filled($ppp->profile)) {
                            app(\App\Http\Controllers\MikrotikController::class)
                                ->updatePPPSecret($ppp->router_name, $ppp->username, 'profile', $ppp->profile);
                        }
                    } catch (\Throwable $e) {
                        report($e);
                        $routerConnected = false;
                    }
                }

                $queue = app(\App\Services\MikrotikPendingActionQueue::class);
                $queue->supersedePending($uniqueId, $ppp->router_name, $ppp->username, 'enable');
                if (! $routerConnected) {
                    $queue->queue($uniqueId, $ppp->router_name, $ppp->username, 'enable');
                    $queued = true;
                }
            }

            if ($queued) {
                flash()->addWarning('Client activated locally; router activation queued until it is available.');
            } else {
                flash()->addSuccess('Client activated successfully.');
            }
        } catch (\Throwable $e) {
            report($e);
            flash()->addError('Unable to activate this client.');
        }

        $this->dispatch('customer-action-done');
    }

    #[On('disable-reseller-customer')]
    public function disableCustomer($id): void
    {
        $id = is_array($id) ? ($id['id'] ?? $id) : $id;
        $reseller = $this->currentReseller();
        abort_unless($reseller && (auth()->user()?->hasRole('Super Admin') || (auth()->user()?->hasRole('Reseller') && $reseller->isActive())), 403);

        try {
            $uniqueId = decrypt($id);
            $customer = CustomersInfo::where('reseller_id', $reseller->id)
                ->where('customer_unique_id', $uniqueId)
                ->with('pppUser')
                ->firstOrFail();

            if ($customer->status === 'disable') {
                flash()->addInfo('Client is already disabled.');
                $this->dispatch('customer-action-done');
                return;
            }
            if ($customer->status === 'free') {
                flash()->addError('Free-status clients can only be managed by an administrator.');
                $this->dispatch('customer-action-done');
                return;
            }

            $ppp = $customer->pppUser;
            $pendingAction = null;
            if ($ppp && filled($ppp->router_name) && filled($ppp->username)) {
                $routerConnected = RouterList::where('router_name', $ppp->router_name)
                    ->where('action', 'connected')->exists();
                if ($routerConnected) {
                    try {
                        app(\App\Http\Controllers\MikrotikController::class)
                            ->disablePPPSecret($uniqueId, $ppp->router_name, $ppp->username);
                    } catch (\Throwable $e) {
                        report($e);
                        $routerConnected = false;
                    }
                }
                if (! $routerConnected) {
                    $pendingAction = [
                        'customer_unique_id' => $uniqueId,
                        'router_name' => $ppp->router_name,
                        'username' => $ppp->username,
                        'action' => 'disable',
                        'status' => 'pending',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            $pendingId = \DB::transaction(function () use ($customer, $ppp, $pendingAction, $uniqueId) {
                $pendingId = null;
                if ($ppp && filled($ppp->router_name) && filled($ppp->username)) {
                    $queue = app(\App\Services\MikrotikPendingActionQueue::class);
                    $queue->supersedePending($uniqueId, $ppp->router_name, $ppp->username, 'disable');
                    if ($pendingAction) {
                        $pendingId = $queue->queue($uniqueId, $ppp->router_name, $ppp->username, 'disable');
                    }
                    PPPSecrets::where('id', $customer->ppp_user_id)->update(['status' => 'disable']);
                }
                $customer->status = 'disable';
                $customer->save();
                return $pendingId;
            });

            if ($pendingId) {
                \Log::warning('MAC/standard reseller disabled a client while router was unavailable; action queued', [
                    'customer_id' => $uniqueId,
                    'router_name' => $pendingAction['router_name'],
                    'pending_action_id' => $pendingId,
                ]);
                flash()->addWarning('Client disabled locally; router action queued until it is available.');
            } else {
                flash()->addSuccess('Client disabled successfully.');
            }
        } catch (\Throwable $e) {
            report($e);
            flash()->addError('Unable to disable this client.');
        }

        $this->dispatch('customer-action-done');
    }

    #[On('delete-customer')]
    public function deleteCustomer($id): void
    {
        $id = is_array($id) ? ($id['id'] ?? $id) : $id;
        $reseller = $this->currentReseller();
        abort_unless($reseller && (auth()->user()?->hasRole('Super Admin') || (auth()->user()?->hasRole('Reseller') && $reseller->isActive())), 403);

        try {
            $uniqueId = decrypt($id);
            $customer = CustomersInfo::where('reseller_id', $reseller->id)
                ->where('customer_unique_id', $uniqueId)
                ->with('pppUser')
                ->first();

            if (! $customer) {
                flash()->addError('Client not found in your account.');
                $this->dispatch('customer-action-done');
                return;
            }
            if (in_array($customer->status, ['active', 'free'], true)) {
                flash()->addError($customer->status === 'free' ? 'Free-status clients can only be removed by an administrator.' : 'Disable the client before removing it.');
                $this->dispatch('customer-action-done');
                return;
            }

            $ppp = $customer->pppUser;
            $pendingAction = null;
            if ($ppp && filled($ppp->router_name) && filled($ppp->username)) {
                $routerConnected = RouterList::where('router_name', $ppp->router_name)
                    ->where('action', 'connected')->exists();
                if ($routerConnected) {
                    try {
                        app(\App\Http\Controllers\MikrotikController::class)
                            ->removePPPSecret($uniqueId, $ppp->router_name, $ppp->username);
                    } catch (\Throwable $e) {
                        report($e);
                        $routerConnected = false;
                    }
                }
                if (! $routerConnected) {
                    $pendingAction = [
                        'customer_unique_id' => $uniqueId,
                        'router_name' => $ppp->router_name,
                        'username' => $ppp->username,
                        'action' => 'remove',
                        'status' => 'pending',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            $pendingId = \DB::transaction(function () use ($customer, $ppp, $pendingAction, $uniqueId) {
                $pendingId = null;
                if ($ppp && filled($ppp->router_name) && filled($ppp->username)) {
                    $queue = app(\App\Services\MikrotikPendingActionQueue::class);
                    $queue->supersedePending($uniqueId, $ppp->router_name, $ppp->username, 'remove');
                    if ($pendingAction) {
                        $pendingId = $queue->queue($uniqueId, $ppp->router_name, $ppp->username, 'remove');
                    }
                    $ppp->delete();
                }
                $customer->delete();
                return $pendingId;
            });

            if ($pendingId) {
                flash()->addWarning('Client removed locally; router removal queued until it is available.');
            } else {
                flash()->addSuccess('Client removed successfully.');
            }
        } catch (\Throwable $e) {
            report($e);
            flash()->addError('Unable to remove this client.');
        }

        $this->dispatch('customer-action-done');
    }

    #[On('open-edit-customer')]
    public function openEditCustomerModal($id)
    {
        $id = is_array($id) ? ($id['id'] ?? $id) : $id;
        $reseller = $this->currentReseller();
        abort_unless($reseller && (auth()->user()?->hasRole('Super Admin') || (auth()->user()?->hasRole('Reseller') && $reseller->isActive())), 403);
        $uniqueId = decrypt($id);
        abort_unless(CustomersInfo::where('reseller_id', $reseller->id)->where('customer_unique_id', $uniqueId)->exists(), 403);
        $this->editingCustomerId = $id;
    }

    public function closeEditCustomerModal()
    {
        $this->editingCustomerId = null;
        $this->dispatch('customer-action-done');
    }
}
