<?php

namespace App\Livewire;

use App\Http\Controllers\MikrotikController;
use App\Models\BillingInfo;
use App\Models\CustomersInfo;
use App\Models\PaymentSummary;
use App\Models\PPPSecrets;
use App\Models\Reseller;
use App\Models\RouterList;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Livewire\Attributes\On;
use Livewire\Component;
use Yajra\DataTables\Facades\DataTables;

class CustomerList extends Component
{
    public $editingCustomerId = null;

    public $editingBillId = null;

    public $routers = [];

    public $selectedRouter = '';

    public $monthly_rent = 0;

    public $additional_charge = 0;

    public $discount = 0;

    public $advance = 0;

    public $vat = 0;

    public $previous_due = 0;

    public $bill_paid_amount = 0;

    public $auto_disable = false;

    public $sub_total_amount = 0;

    public $total_amount = 0;

    public $bill_due_amount = 0;

    public $bill_customer_name;

    public $bill_customer_unique_id;

    public $bill_username;

    public $bill_auto_disable_date;

    public $bulkClientModalOpen = false;

    public $bulkClientSelectedIds = [];

    public $bulkClientSourceRouter = '';

    public $bulkClientTargetRouter = '';

    public $bulkClientProfiles = [];

    public $bulkClientProfileMap = [];

    public $bulkClientPreview = [];

    public $bulkClientError = '';

    public function render()
    {
        if (! hasAccess(['Super Admin'], ['view-customer', 'all-customer'])) {
            abort(403, 'Unauthorized action.');
        }

        $this->routers = RouterList::all();
        $resellers = Reseller::with('user')->get()->reject(fn ($reseller) => in_array(trim((string) ($reseller->company ?: $reseller->user?->name)), ['MS Online', 'Mr. Abu Hanif'], true));

        $customerStats = [
            'total' => CustomersInfo::count(),
            'active' => CustomersInfo::whereNotIn('status', ['pending', 'disable', 'free', 'inactive'])->count(),
            'pending' => CustomersInfo::where('status', 'pending')->count(),
            'disabled' => CustomersInfo::where('status', 'disable')->count(),
            'free' => CustomersInfo::where('status', 'free')->count(),
        ];

        return view('livewire.customer-list', compact('resellers', 'customerStats'))->layout('layouts.app');
    }

    public function getData(Request $request)
    {
        if (! hasAccess(['Super Admin'], ['view-customer', 'all-customer'])) {
            abort(403, 'Unauthorized action.');
        }

        $statusFilter = ['pending', 'disable', 'free', 'inactive'];

        $data = CustomersInfo::query()
            ->with(['billing', 'pppUser', 'customerAddress', 'official', 'package', 'reseller'])
            ->select('customers_infos.*');

        // Global customer search: search across the customer's stored identity/contact
        // fields and related PPP/router data. This keeps the search useful even
        // when the matching field is not displayed as a visible table column.
        $search = trim((string) $request->input('search.value', ''));
        if ($search !== '') {
            $data->where(function ($q) use ($search) {
                $like = '%'.$search.'%';

                $q->where('customers_infos.customer_unique_id', 'like', $like)
                    ->orWhere('customers_infos.customer_name', 'like', $like)
                    ->orWhere('customers_infos.mobile', 'like', $like)
                    ->orWhere('customers_infos.contact_email', 'like', $like)
                    ->orWhere('customers_infos.contact_person', 'like', $like)
                    ->orWhere('customers_infos.identification_no', 'like', $like)
                    ->orWhereHas('customerAddress', function ($address) use ($like) {
                        $address->where('label_name', 'like', $like)
                            ->orWhere('input_type_text', 'like', $like)
                            ->orWhere('input_type_dropdown', 'like', $like)
                            ->orWhere('input_type_textarea', 'like', $like);
                    })
                    ->orWhereHas('pppUser', function ($ppp) use ($like) {
                        $ppp->where('username', 'like', $like)
                            ->orWhere('router_name', 'like', $like)
                            ->orWhere('ppp_remote_ip', 'like', $like)
                            ->orWhere('ip_address', 'like', $like)
                            ->orWhere('caller_id', 'like', $like);
                    })
                    ->orWhereHas('package', function ($package) use ($like) {
                        $package->where('package', 'like', $like);
                    });
            });
        }

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

            case 'missing_location':
                $data->whereNotIn('status', $statusFilter)
                    ->whereDoesntHave('customerAddress', function ($q) {
                        $q->where('label_name', 'Network Location')
                            ->whereNotNull('latitude')
                            ->whereNotNull('longitude');
                    });
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

            case 'reseller':
                if ($request->reseller_id) {
                    $data->where('reseller_id', $request->reseller_id);
                } else {
                    $data->whereNotNull('reseller_id');
                }
                break;

            case 'pending':
            case 'disable':
            case 'free':
            case 'inactive':
                $data->where('status', $request->filter);
                break;

            case 'recent':
                $data->whereMonth('created_at', Carbon::now()->month)
                    ->whereYear('created_at', Carbon::now()->year);
                break;

            default:
                $data->whereNotIn('status', $statusFilter);
        }

        // Read-only live PPPoE status, cached briefly to avoid polling MikroTik per row.
        $livePppUsers = \Cache::remember('customer-list:live-ppp-usernames-v2', now()->addSeconds(20), function () {
            $online = [];
            $controller = app(MikrotikController::class);
            foreach (RouterList::query()->where('action', 'connected')->get() as $router) {
                try {
                    foreach ($controller->getActivePppSessions((string) $router->router_name) as $session) {
                        $username = trim((string) ($session['name'] ?? ''));
                        if ($username !== '') {
                            $online[(string) $router->router_name][mb_strtolower($username)] = true;
                        }
                    }
                } catch (\Throwable $e) {
                    \Log::debug('Customer list live PPP status unavailable for router '.$router->router_name.': '.$e->getMessage());
                }
            }
            return $online;
        });

        return DataTables::eloquent($data)
            ->addIndexColumn()
            ->addColumn('customer_identity', function ($row) {
                $resellerBadge = $row->reseller_id && $row->reseller
                    ? ' <span class="badge ms-1" style="background-color: rgba(111, 66, 193, 0.1); color: rgb(111, 66, 193); border: 1px solid rgba(111, 66, 193, 0.25); font-size: 0.75rem;"><i class="bi bi-person-badge me-1"></i>'.$row->reseller->name.'</span>'
                    : '';

                // Round avatar: photo if exists, else coloured initials
                $initials = mb_strtoupper(mb_substr($row->customer_name ?? '', 0, 1, 'UTF-8'), 'UTF-8');
                $colors = ['#4f46e5', '#0ea5e9', '#16a34a', '#ea580c', '#dc2626', '#7c3aed', '#0891b2', '#ca8a04'];
                $bgColor = $colors[abs(crc32($row->customer_unique_id)) % count($colors)];

                if (! empty($row->photo_url)) {
                    $avatar = '<img src="'.asset('storage/'.$row->photo_url).'" '
                        .'alt="'.e($row->customer_name).'" '
                        .'class="avatar avatar-2xl rounded-circle">';
                } else {
                    $avatar = '<div class="avatar avatar-2xl rounded-circle d-flex align-items-center justify-content-center fw-bold text-white" style="background:'.$bgColor.';font-size:15px;">'
                        .$initials.'</div>';
                }

                return '<div class="d-flex align-items-center gap-2">'.
                    $avatar.
                    '<div>'.
                    '<div class="fw-bold text-dark">'.$row->customer_name.
                    (! empty($row->contact_person && $row->contact_person != '-') ? '<small class="text-muted"> ('.$row->contact_person.')</small>' : '').
                    $resellerBadge.
                    '</div>'.

                    '<div class="small">
                        <span class="badge bg-secondary bg-opacity-10 text-secondary pe-2">'.$row->customer_unique_id.'</span> '.

                        (! empty($row->mobile)
                            ? '<span class="text-muted"><i class="bi bi-telephone text-success"></i> '.$row->mobile.'</span> '
                            : '').

                        (! empty($row->contact_email)
                            ? '<span class="text-muted"><i class="bi bi-envelope text-success"></i> '.$row->contact_email.'</span>'
                            : '').

                    '</div>'.
                    '</div></div>';
            })
            ->addColumn('customers_address', function ($row) {
                $formattedAddresses = [];
                foreach ($row->customerAddress as $address) {
                    $parts = array_filter([$address->input_type_text, $address->input_type_dropdown, $address->input_type_textarea]);
                    $formattedAddresses[] = implode(', ', $parts);
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
                return '<div class="mb-1 fw-bold text-dark"><i class="bi bi-person-fill"></i> '.($row->pppUser->username ?? 'N/A').'<span class="badge bg-info bg-opacity-10 text-dark border border-info border-opacity-25 ms-1"><i class="bi bi-router-fill me-1"></i>'.($row->pppUser->router_name ?? 'N/A').'</span></div><div class="badge bg-success bg-opacity-10 text-dark border border-info border-opacity-25"><i class="bi bi-package me-1"></i>'.($row->package->package ?? 'N/A').'</span><span class="text-white px-1 py-0 fw-bold"><i class="bi bi-cash me-1"></i>'.($row->package->price ?? 'N/A').'</span></div>';
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
            ->addColumn('cid', function ($row) use ($livePppUsers) {
                $url = route('customer.details', ['id' => encrypt($row->customer_unique_id)]);
                $username = trim((string) ($row->pppUser?->username ?? ''));
                $router = (string) ($row->pppUser?->router_name ?? '');
                $isOnline = $username !== '' && ! empty($livePppUsers[$router][mb_strtolower($username)]);
                $dotClass = $isOnline ? 'customer-live-dot customer-live-dot--active' : 'customer-live-dot customer-live-dot--inactive';
                $label = $isOnline ? 'Online now (live MikroTik PPP session)' : 'Offline (not found in live MikroTik PPP sessions)';
                $liveText = '<small class="d-block '.($isOnline ? 'text-success' : 'text-muted').'">'.($isOnline ? 'Online' : 'Offline').'</small>';
                return '<span class="customer-cid-live" title="'.e($label).'"><span class="'.e($dotClass).'" aria-label="'.e($label).'"></span>'
                    .'<a href="'.e($url).'" class="fw-bold text-primary text-decoration-none" title="Open Customer Details">'
                    .e($row->customer_unique_id)
                    .'</a>'.$liveText.'</span>';
            })
            ->addColumn('customer_name_display', function ($row) {
                return '<div class="fw-semibold text-dark">'.e($row->customer_name ?? 'N/A').'</div>';
            })
            ->addColumn('connection', function ($row) {
                $username = $row->pppUser?->username;
                $ip = $row->pppUser?->ppp_remote_ip ?: $row->pppUser?->ip_address;
                if ($username) {
                    return '<div><span class="badge bg-secondary-subtle text-secondary">PPPoE</span> <span class="fw-semibold">'.e($username).'</span></div>';
                }
                if ($ip) {
                    return '<div><span class="badge bg-info-subtle text-info-emphasis">STATIC</span> <span class="fw-semibold">'.e($ip).'</span></div>';
                }
                return '<span class="text-muted">N/A</span>';
            })
            ->addColumn('mobile_display', function ($row) {
                if (empty($row->mobile)) {
                    return '<span class="text-muted">—</span>';
                }
                $mobile = preg_replace('/\\D+/', '', (string) $row->mobile);
                if (str_starts_with($mobile, '00880')) {
                    $mobile = substr($mobile, 2);
                }
                if (str_starts_with($mobile, '8801') && strlen($mobile) >= 13) {
                    $mobile = '0'.substr($mobile, 3);
                }
                $display = $mobile ?: (string) $row->mobile;
                $dial = preg_replace('/[^0-9+]/', '', (string) $row->mobile);
                return '<span class="d-inline-flex align-items-center gap-2 text-nowrap">'
                    .'<span>'.e($display).'</span>'
                    .'<a class="btn btn-sm btn-outline-success py-1 px-2" href="tel:'.e($dial).'" aria-label="Call '.e($display).'" title="Call customer">'
                    .'<i class="bi bi-telephone-fill" aria-hidden="true"></i> <span>Call</span></a></span>';
            })
            ->addColumn('package_display', function ($row) {
                $name = $row->package?->package ?: $row->package_name;
                $price = $row->billing?->monthly_rent ?? $row->package?->price;
                return '<div class="fw-semibold">'.e($name ?: 'N/A').'</div>'
                    .($price !== null ? '<small class="text-muted">'.number_format((float) $price, 2).' ৳</small>' : '');
            })
            ->addColumn('billing_date_display', function ($row) {
                if (! $row->billing?->auto_disable_date) {
                    return '<span class="text-muted">—</span>';
                }
                $date = Carbon::parse($row->billing->auto_disable_date);
                return '<span class="text-nowrap">Day of '.$date->format('j').'</span>';
            })
            ->addColumn('balance_display', function ($row) {
                $balance = (float) ($row->billing?->due_amount ?? 0);
                $class = $balance > 0 ? 'text-danger' : 'text-success';
                return '<span class="fw-bold '.$class.'">'.number_format($balance, 2).' ৳</span>';
            })
            ->addColumn('expiry_display', function ($row) {
                if (! $row->billing?->auto_disable_date) {
                    return '<span class="text-muted">—</span>';
                }
                $date = Carbon::parse($row->billing->auto_disable_date);
                $class = $date->isPast() && ! in_array($row->status, ['disable', 'inactive'], true) ? 'text-danger' : 'text-dark';
                return '<span class="fw-semibold text-nowrap '.$class.'">'.$date->format('d M Y').'</span>';
            })
            ->addColumn('address_display', function ($row) {
                $parts = [];
                foreach ($row->customerAddress as $address) {
                    if (($address->label_name ?? '') === 'Network Location') continue;
                    $value = array_filter([$address->input_type_text, $address->input_type_dropdown, $address->input_type_textarea]);
                    if ($value) $parts[] = implode(', ', $value);
                }
                $address = implode(', ', $parts);
                return $address !== '' ? '<span title="'.e($address).'">'.e($address).'</span>' : '<span class="text-muted">—</span>';
            })
            ->addColumn('pop_area', function ($row) {
                $pop = $row->official?->distribution_location;
                return $pop ? '<span class="badge bg-light text-dark border">'.e($pop).'</span>' : '<span class="text-muted">—</span>';
            })
            ->addColumn('monthly_bill_display', function ($row) {
                return '<span class="fw-bold text-primary">'.number_format((float) ($row->billing?->monthly_rent ?? 0), 2).' ৳</span>';
            })
            ->addColumn('action', function ($row) {
                $id = encrypt($row->customer_unique_id);
                $viewBtn = '<a href="'.e(route('customer.details', $row->customer_unique_id)).'" class="view btn btn-outline-secondary" title="View Customer"><i class="bi bi-person-vcard"></i></a>';
                $editBtn = '<button onclick="Livewire.dispatch(\'open-edit-customer\', { id: \''.$id.'\' })" class="edit btn btn-primary" title="Edit"><i class="bi bi-pencil-square"></i></button>';
                $billBtn = '<button onclick="Livewire.dispatch(\'open-bill-modal\', { id: \''.$id.'\' })" class="bill btn btn-info" title="Update Bill"><i class="bi bi-journal-arrow-up"></i></button>';
                $enableBtn = '<button onclick="confirmEnableCustomer(\''.$id.'\')" class="btn btn-success" title="Enable"><i class="bi bi-power"></i></button>';
                $disableBtn = '<button onclick="confirmDisableCustomer(\''.$id.'\')" class="btn btn-warning text-dark" title="Disable"><i class="bi bi-slash-circle"></i></button>';
                $deleteBtn = '<button onclick="confirmDeleteCustomer(\''.$id.'\')" class="btn btn-danger" title="Delete"><i class="bi bi-trash"></i></button>';

                $isAdmin = (bool) auth()->user()?->hasRole('Super Admin');
                $canAction = fn (array $permissions) => $isAdmin || (auth()->user()?->hasAnyPermission($permissions) ?? false);
                $canView = $canAction(['view-customer', 'all-customer']);
                $canEdit = $canAction(['edit-customer']);
                $canBill = $canAction(['update-bill']);
                $canOwner = $canAction(['manage-customer-assignment']);
                $canPush = $canAction(['push-customers']);
                $canWifi = $canAction(['mikrotik-setup']);
                $canLedger = $canAction(['payment-history', 'collection-list', 'amount-collection-report']);
                $canCollect = $canAction(['payment-collection']);
                $canInvoice = $canAction(['payment-collection-invoice']);
                $canSms = $canAction(['create-sms']);
                $canGraph = $canAction(['network-inventory', 'mikrotik-setup']);
                $canTicket = $canAction(['manage-tickets', 'view-tickets']);
                $actionUrl = fn ($action) => route('customer.actions', ['id' => $id, 'action' => $action]);
                $menu = '<div class="dropdown customer-row-actions">'
                    .'<button class="btn btn-sm btn-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-lightning-charge me-1"></i>Actions</button>'
                    .'<ul class="dropdown-menu dropdown-menu-end shadow">';

                if ($canView) {
                    $menu .= '<li><a class="dropdown-item" href="'.e(route('customer.details', $row->customer_unique_id)).'"><i class="bi bi-person-vcard me-2 text-primary"></i>Customer overview</a></li>';
                }
                if ($canEdit) {
                    $menu .= '<li><button class="dropdown-item" type="button" onclick="Livewire.dispatch(\'open-edit-customer\', { id: \''.$id.'\' })"><i class="bi bi-pencil-square me-2 text-primary"></i>Edit customer</button></li>';
                }
                if ($canOwner) {
                    $menu .= '<li><a class="dropdown-item" href="'.e($actionUrl('owner')).'"><i class="bi bi-person-gear me-2"></i>Change owner / package</a></li>';
                }
                if ($canPush) {
                    $menu .= '<li><a class="dropdown-item" href="'.e($actionUrl('class')).'"><i class="bi bi-tags me-2"></i>Change customer class</a></li>';
                    $menu .= '<li><a class="dropdown-item" href="'.e($actionUrl('password')).'"><i class="bi bi-key me-2"></i>Change PPPoE password</a></li>';
                }
                if ($canWifi) {
                    $menu .= '<li><a class="dropdown-item" href="'.e($actionUrl('wifi-login')).'"><i class="bi bi-wifi me-2"></i>Wi-Fi router login</a></li>';
                }
                if ($canBill && ! in_array($row->status, ['pending', 'disable', 'inactive'], true)) {
                    $menu .= '<li><button class="dropdown-item" type="button" onclick="Livewire.dispatch(\'open-bill-modal\', { id: \''.$id.'\' })"><i class="bi bi-journal-arrow-up me-2 text-info"></i>Update bill</button></li>';
                }
                if ($canLedger) {
                    $menu .= '<li><a class="dropdown-item" href="'.e($actionUrl('ledger')).'"><i class="bi bi-journal-text me-2"></i>Billing ledger</a></li>';
                }
                if ($canCollect) {
                    $menu .= '<li><a class="dropdown-item" href="'.e($actionUrl('cash-credit')).'"><i class="bi bi-cash-coin me-2"></i>Cash / credit / return</a></li>';
                }
                if ($canBill) {
                    $menu .= '<li><a class="dropdown-item" href="'.e($actionUrl('recharge')).'"><i class="bi bi-calendar-plus me-2"></i>Monthly recharge</a></li>';
                    $menu .= '<li><a class="dropdown-item" href="'.e($actionUrl('billing-date')).'"><i class="bi bi-calendar-date me-2"></i>Change billing date</a></li>';
                    $menu .= '<li><a class="dropdown-item" href="'.e($actionUrl('grace')).'"><i class="bi bi-hourglass me-2"></i>Extra grace</a></li>';
                }
                if ($canInvoice) {
                    $menu .= '<li><a class="dropdown-item" href="'.e($actionUrl('invoice')).'" target="_blank"><i class="bi bi-receipt me-2"></i>Invoice</a></li>';
                    $menu .= '<li><a class="dropdown-item" href="'.e($actionUrl('pos-print')).'" target="_blank"><i class="bi bi-printer me-2"></i>POS print</a></li>';
                }
                if ($canTicket) {
                    $menu .= '<li><a class="dropdown-item" href="'.e($actionUrl('ticket')).'"><i class="bi bi-ticket-perforated me-2"></i>Create support ticket</a></li>';
                    $menu .= '<li><a class="dropdown-item" href="'.e($actionUrl('ticket-history')).'"><i class="bi bi-clock-history me-2"></i>Ticket history</a></li>';
                }
                if ($canSms) {
                    $menu .= '<li><a class="dropdown-item" href="'.e($actionUrl('sms')).'"><i class="bi bi-chat-dots me-2"></i>Send SMS</a></li>';
                }
                if ($canGraph) {
                    $menu .= '<li><a class="dropdown-item" href="'.e($actionUrl('online-graph')).'"><i class="bi bi-graph-up me-2"></i>Online graph</a></li>';
                }
                $menu .= '<li><hr class="dropdown-divider"></li>';
                if (in_array($row->status, ['pending', 'disable'], true)
                    && ($isAdmin || hasAccess(['Super Admin'], ['enable-pending-customer', 'enable-customer']))) {
                    $menu .= '<li><button class="dropdown-item text-success" type="button" onclick="confirmEnableCustomer(\''.$id.'\')"><i class="bi bi-power me-2"></i>Enable customer</button></li>';
                }
                if (! in_array($row->status, ['pending', 'disable', 'inactive'], true)
                    && ($isAdmin || hasAccess(['Super Admin'], ['disable-customer']))) {
                    $menu .= '<li><button class="dropdown-item text-warning" type="button" onclick="confirmDisableCustomer(\''.$id.'\')"><i class="bi bi-slash-circle me-2"></i>Disable customer</button></li>';
                }
                if ($row->pppUser && ! empty($row->pppUser->router_name) && hasAccess(['Super Admin'], ['push-customers'])) {
                    $menu .= '<li><button class="dropdown-item" type="button" onclick="confirmPushCustomer(\''.$id.'\')"><i class="bi bi-cloud-arrow-up me-2"></i>Push to MikroTik</button></li>';
                }
                if ($isAdmin || hasAccess(['Super Admin'], ['delete-customer'])) {
                    $menu .= '<li><button class="dropdown-item text-danger" type="button" onclick="confirmDeleteCustomer(\''.$id.'\')"><i class="bi bi-trash me-2"></i>Delete customer</button></li>';
                }
                return $menu.'</ul></div>';
            })
            ->rawColumns(['customer_identity', 'customers_address', 'billing_breakdown', 'connection_details', 'billing_summary', 'action', 'disable_details', 'cid', 'customer_name_display', 'connection', 'mobile_display', 'package_display', 'billing_date_display', 'balance_display', 'expiry_display', 'address_display', 'pop_area', 'monthly_bill_display'])
            ->make(true);
    }

    public function show(string $id)
    {
        if (! hasAccess(['Super Admin'], ['view-customer', 'all-customer'])) {
            abort(403, 'Unauthorized action.');
        }

        $unique_id = decrypt($id);
        $data = CustomersInfo::where('customer_unique_id', $unique_id)
            ->join('billing_infos', 'customers_infos.customer_unique_id', '=', 'billing_infos.customer_bill_unique_id')
            ->leftJoin('p_p_p_secrets', 'p_p_p_secrets.id', '=', 'customers_infos.ppp_user_id')
            ->select('customers_infos.customer_unique_id', 'customers_infos.customer_name', 'billing_infos.*', 'p_p_p_secrets.username as username')
            ->first();

        return response()->json($data);
    }

    public function edit(string $id)
    {
        if (! auth()->user()?->hasRole('Super Admin') && ! hasAccess(['Super Admin'], ['edit-customer'])) {
            abort(403, 'Unauthorized action.');
        }

        return view('edit-customer', [
            'customerId' => $id,
        ]);
    }

    #[On('enable-customer')]
    public function enableCustomer($id): void
    {
        $id = is_array($id) ? $id['id'] ?? $id : $id;

        if (! auth()->user()?->hasRole('Super Admin') && ! hasAccess(['Super Admin'], ['enable-pending-customer', 'enable-customer'])) {
            flash()->addError('Unauthorized action.');
            $this->dispatch('customer-action-done');
            return;
        }

        try {
            $unique_id = decrypt($id);
            $bill = BillingInfo::where('customer_bill_unique_id', $unique_id)->first();

            if (! $bill) {
                flash()->addError('Billing Information not found.');
                $this->dispatch('customer-action-done');
                return;
            }

            $customer = CustomersInfo::where('customer_unique_id', $unique_id)
                ->with('pppUser')
                ->first();

            if (! $customer) {
                flash()->addError('Customer not found.');
                $this->dispatch('customer-action-done');
                return;
            }

            if (! in_array($customer->status, ['pending', 'disable'], true)) {
                flash()->addError('Only pending or disabled customers can be enabled from this action.');
                $this->dispatch('customer-action-done');
                return;
            }

            // Keep database activation atomic. Router/network operations are
            // intentionally performed only after the database commit.
            \DB::transaction(function () use ($unique_id, $bill, $customer) {
                $summaryExists = PaymentSummary::where('customer_payment_unique_id', $unique_id)
                    ->where('summary_date', Carbon::now()->firstOfMonth()->format('Y-m-d'))
                    ->exists();

                if (! $summaryExists) {
                    PaymentSummary::create([
                        'customer_payment_unique_id' => $unique_id,
                        'summary_date' => Carbon::now()->firstOfMonth()->format('Y-m-d'),
                        'monthly_rent' => $bill->monthly_rent,
                        'additional_charge' => $bill->additional_charge,
                        'vat' => $bill->vat,
                        'previous_due' => $bill->previous_due,
                        'advance' => $bill->advance,
                        'discount' => $bill->discount,
                    ]);
                }

                if ($customer->pppUser && filled($customer->pppUser->router_name) && filled($customer->pppUser->username)) {
                    app(\App\Services\MikrotikPendingActionQueue::class)->supersedePending(
                        $unique_id,
                        $customer->pppUser->router_name,
                        $customer->pppUser->username,
                        'enable'
                    );
                }

                $customer->status = 'active';
                $customer->save();

                if ($bill->auto_disable_date) {
                    $autoDisableDate = Carbon::parse($bill->auto_disable_date)->startOfDay();
                    $autoDisableMonth = $bill->auto_disable_month;
                    $disableDate = $autoDisableDate->copy()->addMonths($autoDisableMonth);

                    if ($disableDate->lte(today())) {
                        while ($disableDate->lte(today())) {
                            $disableDate->addMonth();
                        }

                        $bill->auto_disable_date = $disableDate->copy()
                            ->subMonths($autoDisableMonth)
                            ->toDateString();
                        $bill->save();
                    }
                }

                if ($customer->pppUser) {
                    PPPSecrets::where('id', $customer->ppp_user_id)
                        ->update(['status' => 'active']);
                }
            });

            $routerSyncPending = false;
            $ppp = $customer->pppUser;

            if ($ppp && filled($ppp->router_name) && filled($ppp->username)) {
                $routerConnected = RouterList::where('router_name', $ppp->router_name)
                    ->where('action', 'connected')
                    ->exists();

                if ($routerConnected) {
                    try {
                        $mikrotik = app(MikrotikController::class);
                        $mikrotik->enablePPPSecret($unique_id, $ppp->router_name, $ppp->username);
                        if (filled($ppp->profile)) {
                            $mikrotik->updatePPPSecret($ppp->router_name, $ppp->username, 'profile', $ppp->profile);
                        }
                    } catch (\Throwable $e) {
                        $routerSyncPending = true;
                        \Log::warning('Customer activated locally; MikroTik activation queued after router failure', [
                            'customer_unique_id' => $unique_id,
                            'router' => $ppp->router_name,
                            'username' => $ppp->username,
                            'error' => $e->getMessage(),
                        ]);
                    }
                } else {
                    $routerSyncPending = true;
                }

                if ($routerSyncPending) {
                    app(\App\Services\MikrotikPendingActionQueue::class)->queue(
                        $unique_id,
                        $ppp->router_name,
                        $ppp->username,
                        'enable'
                    );
                }
            }

            if ($routerSyncPending) {
                flash()->addWarning('Customer enabled locally. MikroTik activation is queued until the router is available.');
            } else {
                flash()->addSuccess($ppp
                    ? 'Customer enabled successfully and PPP secret activated.'
                    : 'Customer enabled successfully.');
            }
        } catch (\Throwable $e) {
            \Log::error('Failed to enable customer '.$id.': '.$e->getMessage());
            report($e);
            flash()->addError('Failed to enable customer. Please try again.');
        }

        $this->dispatch('customer-action-done');
    }

    public function updated($propertyName)
    {
        if (in_array($propertyName, ['monthly_rent', 'additional_charge', 'discount', 'advance', 'vat', 'previous_due'])) {
            $this->calculateBill();
        }
    }

    public function calculateBill()
    {
        $monthlyRent = (float) ($this->monthly_rent ?: 0);
        $previousDue = (float) ($this->previous_due ?: 0);
        $additionalCharge = (float) ($this->additional_charge ?: 0);
        $discount = (float) ($this->discount ?: 0);
        $advance = (float) ($this->advance ?: 0);
        $vat = (float) ($this->vat ?: 0);
        $paid = (float) ($this->bill_paid_amount ?: 0);

        $subtotal = $monthlyRent + $previousDue + $additionalCharge;
        $vatAmount = ($vat / 100) * $subtotal;
        $this->sub_total_amount = round($subtotal + $vatAmount, 2);
        $this->total_amount = round($this->sub_total_amount - ($discount + $advance), 2);
        $this->bill_due_amount = round($this->total_amount - $paid, 2);
    }

    #[On('open-bill-modal')]
    public function openBillModal($id)
    {
        if (! hasAccess(['Super Admin'], ['update-bill'])) {
            abort(403, 'Unauthorized action.');
        }

        $id = is_array($id) ? $id['id'] ?? $id : $id;
        $this->editingBillId = $id;

        $unique_id = decrypt($id);
        $customer = CustomersInfo::where('customer_unique_id', $unique_id)
            ->with(['billing', 'pppUser'])
            ->first();

        if ($customer) {
            $this->bill_customer_name = $customer->customer_name;
            $this->bill_customer_unique_id = $customer->customer_unique_id;
            $this->bill_username = $customer->pppUser?->username ?? '';
            $this->bill_auto_disable_date = $customer->billing?->auto_disable_date ?? '';

            $this->monthly_rent = $customer->billing?->monthly_rent ?? 0;
            $this->additional_charge = $customer->billing?->additional_charge ?? 0;
            $this->discount = $customer->billing?->discount ?? 0;
            $this->advance = $customer->billing?->advance ?? 0;
            $this->vat = $customer->billing?->vat ?? 0;
            $this->previous_due = $customer->billing?->previous_due ?? 0;
            $this->bill_paid_amount = $customer->billing?->paid_amount ?? 0;
            $this->auto_disable = (bool) ($customer->billing?->auto_disable == 1);

            $this->calculateBill();
        }
    }

    public function updateBill()
    {
        if (! hasAccess(['Super Admin'], ['update-bill'])) {
            flash()->addError('Unauthorized action.');

            return;
        }

        try {
            $billing = BillingInfo::where('customer_bill_unique_id', decrypt($this->editingBillId))->firstOrFail();
            $billing->monthly_rent = $this->monthly_rent ?: 0;
            $billing->additional_charge = $this->additional_charge ?: 0;
            $billing->discount = $this->discount ?: 0;
            $billing->advance = $this->advance ?: 0;
            $billing->vat = $this->vat ?: 0;
            $billing->total_amount = $this->total_amount ?: 0;
            $billing->due_amount = $this->bill_due_amount ?: 0;
            $billing->auto_disable = $this->auto_disable ? 1 : 0;
            $billing->save();

            flash()->success('Billing information updated successfully.');
            $this->closeBillModal();
        } catch (\Illuminate\Validation\ValidationException $e) {
            flash()->addError($e->validator->errors()->first());
        } catch (\Exception $e) {
            report($e);
            flash()->addError('Operation failed. Please try again.');
        }
    }

    public function closeBillModal()
    {
        $this->editingBillId = null;
        $this->dispatch('customer-action-done');
    }

    #[On('disable-customer')]
    public function disableCustomer($id): void
    {
        $id = is_array($id) ? $id['id'] ?? $id : $id;

        if (! auth()->user()?->hasRole('Super Admin') && ! hasAccess(['Super Admin'], ['disable-customer'])) {
            flash()->addError('Unauthorized action.');
            $this->dispatch('customer-action-done');
            return;
        }

        try {
            $uniqueId = decrypt($id);
            $customer = CustomersInfo::where('customer_unique_id', $uniqueId)
                ->with('pppUser')
                ->first();

            if (! $customer) {
                flash()->addError('Customer not found.');
                $this->dispatch('customer-action-done');
                return;
            }

            // Record disconnected-router work in the same transaction as the local
            // status update, so a failed DB update cannot leave a stale remote action.
            $pendingAction = null;
            if ($customer->pppUser && ! empty($customer->pppUser->router_name)) {
                $routerName = $customer->pppUser->router_name;
                $routerConnected = RouterList::where('router_name', $routerName)
                    ->where('action', 'connected')
                    ->exists();

                if ($routerConnected) {
                    try {
                        app(MikrotikController::class)->disablePPPSecret(
                            $uniqueId,
                            $routerName,
                            $customer->pppUser->username
                        );
                    } catch (\Throwable $routerError) {
                        report($routerError);
                        $routerConnected = false;
                    }
                }

                if (! $routerConnected && filled($customer->pppUser->username)) {
                    $pendingAction = [
                        'customer_unique_id' => $uniqueId,
                        'router_name' => $routerName,
                        'username' => $customer->pppUser->username,
                        'action' => 'disable',
                        'status' => 'pending',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            $pendingId = \DB::transaction(function () use ($customer, $pendingAction, $uniqueId) {
                $pendingId = null;
                if ($customer->pppUser && filled($customer->pppUser->router_name) && filled($customer->pppUser->username)) {
                    $pendingQueue = app(\App\Services\MikrotikPendingActionQueue::class);
                    $pendingQueue->supersedePending($uniqueId, $customer->pppUser->router_name, $customer->pppUser->username, 'disable');
                    if ($pendingAction) {
                        $pendingId = $pendingQueue->queue($uniqueId, $customer->pppUser->router_name, $customer->pppUser->username, 'disable');
                    }
                }

                $customer->status = 'disable';
                $customer->save();

                if ($customer->pppUser) {
                    PPPSecrets::where('id', $customer->ppp_user_id)
                        ->update(['status' => 'disable']);
                }

                return $pendingId;
            });

            if ($pendingId) {
                \Log::warning('Customer disabled locally while router is unavailable; remote action queued', [
                    'customer_id' => $uniqueId,
                    'router_name' => $pendingAction['router_name'],
                    'username' => $pendingAction['username'],
                    'pending_action_id' => $pendingId,
                ]);
            }

            flash()->addSuccess('Customer disabled successfully.');
        } catch (\Throwable $e) {
            \Log::error('Failed to disable customer', [
                'customer_id' => $id,
                'error' => $e->getMessage(),
            ]);
            report($e);
            flash()->addError('Failed to disable customer. Please try again.');
        }

        $this->dispatch('customer-action-done');
    }

    #[On('delete-customer')]
    public function deleteCustomer($id): void
    {
        $id = is_array($id) ? $id['id'] ?? $id : $id;

        if (! auth()->user()?->hasRole('Super Admin') && ! hasAccess(['Super Admin'], ['delete-customer'])) {
            flash()->addError('Unauthorized action.');
            $this->dispatch('customer-action-done');

            return;
        }
        try {
            $decryptedId = decrypt($id);
            $customerDelete = CustomersInfo::where('customer_unique_id', $decryptedId)->with('pppUser')->first();

            if (! $customerDelete) {
                flash()->addError('Customer not found.');
                $this->dispatch('customer-action-done');

                return;
            }

            if ($customerDelete->status === 'active') {
                flash()->addError('Disable the customer before deleting the customer record.');
                $this->dispatch('customer-action-done');

                return;
            }

            // Keep the queued remote removal atomic with local deletion. Otherwise,
            // a failed local transaction could still remove a customer's PPP secret later.
            $pppUser = $customerDelete->pppUser;
            $pendingAction = null;
            if ($pppUser && ! empty($pppUser->router_name)) {
                $routerName = $pppUser->router_name;
                $routerConnected = RouterList::where('router_name', $routerName)
                    ->where('action', 'connected')
                    ->exists();

                if ($routerConnected) {
                    try {
                        app(MikrotikController::class)->removePPPSecret(
                            $decryptedId,
                            $routerName,
                            $pppUser->username
                        );
                    } catch (\Throwable $routerError) {
                        report($routerError);
                        $routerConnected = false;
                    }
                }

                if (! $routerConnected && filled($pppUser->username)) {
                    $pendingAction = [
                        'customer_unique_id' => $decryptedId,
                        'router_name' => $routerName,
                        'username' => $pppUser->username,
                        'action' => 'remove',
                        'status' => 'pending',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            $pendingId = \DB::transaction(function () use ($customerDelete, $pppUser, $pendingAction, $decryptedId) {
                $pendingId = null;
                if ($pppUser && filled($pppUser->router_name) && filled($pppUser->username)) {
                    $pendingQueue = app(\App\Services\MikrotikPendingActionQueue::class);
                    $pendingQueue->supersedePending($decryptedId, $pppUser->router_name, $pppUser->username, 'remove');
                    if ($pendingAction) {
                        $pendingId = $pendingQueue->queue($decryptedId, $pppUser->router_name, $pppUser->username, 'remove');
                    }
                }

                if ($pppUser) {
                    $pppUser->delete();
                }

                $customerDelete->delete();

                return $pendingId;
            });

            if ($pendingId) {
                \Log::warning('Customer deleted locally while router is unavailable; remote removal queued', [
                    'customer_id' => $decryptedId,
                    'router_name' => $pendingAction['router_name'],
                    'username' => $pendingAction['username'],
                    'pending_action_id' => $pendingId,
                ]);
            }

            flash()->addSuccess('Customer deleted successfully.');
        } catch (\Exception $e) {
            report($e);
            flash()->addError('Operation failed. Please try again.');
        }

        $this->dispatch('customer-action-done');
    }

    #[On('open-edit-customer')]
    public function openEditCustomerModal($id)
    {
        if (! auth()->user()?->hasRole('Super Admin') && ! hasAccess(['Super Admin'], ['edit-customer'])) {
            flash()->addError('Unauthorized action.');
            $this->dispatch('customer-action-done');
            return;
        }

        $this->editingCustomerId = is_array($id) ? $id['id'] ?? $id : $id;
    }

    #[On('close-edit-customer')]
    public function closeEditCustomerModal()
    {
        $this->editingCustomerId = null;
        $this->dispatch('customer-action-done');
    }

    #[On('push-customer')]
    public function pushCustomer($id): void
    {
        $id = is_array($id) ? $id['id'] ?? $id : $id;

        if (! hasAccess(['Super Admin'], ['push-customers'])) {
            flash()->addError('Unauthorized action.');
            $this->dispatch('customer-action-done');

            return;
        }

        try {
            $unique_id = decrypt($id);
            $customer = CustomersInfo::where('customer_unique_id', $unique_id)->with('pppUser')->first();

            if (! $customer) {
                flash()->addError('Customer not found.');
                $this->dispatch('customer-action-done');

                return;
            }

            if (! $customer->pppUser) {
                flash()->addError('Customer does not have PPP/Mikrotik User details.');
                $this->dispatch('customer-action-done');

                return;
            }

            $this->syncCustomer($customer);

            flash()->addSuccess("Customer successfully pushed to MikroTik router.");
        } catch (\Exception $e) {
            \Log::error("Failed to push customer: " . $e->getMessage());
            report($e);
            flash()->addError('Failed to push to router. Please try again.');
        }

        $this->dispatch('customer-action-done');
    }

    #[On('push-all-customers')]
    public function pushAllCustomers(): void
    {
        if (! hasAccess(['Super Admin'], ['push-customers'])) {
            flash()->addError('Unauthorized action.');
            $this->dispatch('customer-action-done');
            return;
        }

        try {
            $customers = CustomersInfo::whereHas('pppUser', function ($q) {
                $q->whereNotNull('router_name')->where('router_name', '!=', '');
            })->with('pppUser')->get();

            if ($customers->isEmpty()) {
                flash()->addError('No customers with router configuration found.');
                $this->dispatch('customer-action-done');
                return;
            }

            $success = 0; $failed = 0;
            foreach ($customers as $customer) {
                try {
                    $this->syncCustomer($customer);
                    $success++;
                } catch (\Exception $e) {
                    \Log::error("Failed bulk push for customer {$customer->customer_unique_id}: " . $e->getMessage());
                    $failed++;
                }
            }

            if ($failed > 0) {
                flash()->addWarning("Push completed with some issues. Success: {$success}, Failed: {$failed}");
            } else {
                flash()->addSuccess("Successfully pushed/synchronized {$success} customers to MikroTik.");
            }

        } catch (\Exception $e) {
            \Log::error("Failed bulk push to routers: " . $e->getMessage());
            report($e);
            flash()->addError('Failed to push all customers. Please try again.');
        }

        $this->dispatch('customer-action-done');
    }

    public function openBulkClientChange(array $customerIds = []): void
    {
        if (! auth()->user()?->hasRole('Super Admin') && ! hasAccess(['Super Admin'], ['edit-customer'])) {
            abort(403, 'Unauthorized action.');
        }

        $ids = collect($customerIds)
            ->map(fn ($id) => trim((string) $id))
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty() || $ids->count() > 100) {
            flash()->addError($ids->isEmpty()
                ? 'Select at least one customer from the list.'
                : 'Bulk change supports up to 100 selected customers per batch.');

            return;
        }

        $customers = CustomersInfo::with('pppUser')
            ->whereIn('customer_unique_id', $ids->all())
            ->get();

        if ($customers->count() !== $ids->count()) {
            flash()->addError('Some selected customers could not be found. Refresh the list and select them again.');

            return;
        }

        if ($customers->contains(fn ($customer) => ! $customer->pppUser || strtolower((string) $customer->pppUser->service) !== 'pppoe')) {
            flash()->addError('Every selected customer must have a PPPoE user linked.');

            return;
        }

        $sourceRouters = $customers->pluck('pppUser.router_name')->filter()->unique()->values();
        if ($sourceRouters->count() !== 1) {
            flash()->addError('Select customers from one source MikroTik router at a time.');

            return;
        }

        $this->bulkClientSelectedIds = $customers->pluck('customer_unique_id')->values()->all();
        $this->bulkClientSourceRouter = (string) $sourceRouters->first();
        $this->bulkClientTargetRouter = '';
        $this->bulkClientProfiles = [];
        $this->bulkClientProfileMap = [];
        $this->bulkClientError = '';
        $this->bulkClientPreview = $customers->map(fn ($customer) => [
            'cid' => (string) $customer->customer_unique_id,
            'customer' => (string) $customer->customer_name,
            'username' => (string) $customer->pppUser->username,
            'profile' => (string) $customer->pppUser->profile,
        ])->values()->all();
        foreach ($this->bulkClientPreview as $client) {
            $this->bulkClientProfileMap[$client['cid']] = '';
        }
        $this->bulkClientModalOpen = true;
    }

    public function updatedBulkClientTargetRouter($routerName): void
    {
        $this->bulkClientProfiles = [];
        $this->bulkClientError = '';

        if (! filled($routerName) || $routerName === $this->bulkClientSourceRouter) {
            if ($routerName === $this->bulkClientSourceRouter) {
                $this->bulkClientError = 'Choose a different destination router.';
            }

            return;
        }

        $router = RouterList::where('router_name', $routerName)
            ->where('action', 'connected')
            ->first();

        if (! $router) {
            $this->bulkClientError = 'Destination router is not connected.';
            return;
        }

        try {
            $profiles = app(MikrotikController::class)->singleRead(
                $routerName,
                '/ppp/profile/print',
                'ppp profile print without-paging terse',
                [],
                false,
                true
            );

            $this->bulkClientProfiles = collect($profiles)
                ->pluck('name')
                ->filter(fn ($name) => is_string($name) && trim($name) !== '')
                ->unique()
                ->sort()
                ->values()
                ->all();

            if (empty($this->bulkClientProfiles)) {
                $this->bulkClientError = 'No PPP profiles could be read from the destination router.';
            } else {
                foreach ($this->bulkClientPreview as $client) {
                    $selected = (string) ($this->bulkClientProfileMap[$client['cid']] ?? '');
                    $current = (string) ($client['profile'] ?? '');
                    if ($selected === '' || ! in_array($selected, $this->bulkClientProfiles, true)) {
                        $this->bulkClientProfileMap[$client['cid']] = in_array($current, $this->bulkClientProfiles, true) ? $current : '';
                    }
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('Bulk client change could not load target PPP profiles', [
                'target_router' => $routerName,
                'error' => $e->getMessage(),
            ]);
            $this->bulkClientError = 'Unable to load PPP profiles from the destination router.';
        }
    }

    public function closeBulkClientChange(): void
    {
        $this->bulkClientModalOpen = false;
        $this->bulkClientSelectedIds = [];
        $this->bulkClientPreview = [];
        $this->bulkClientProfiles = [];
        $this->bulkClientTargetRouter = '';
        $this->bulkClientError = '';
    }

    public function submitBulkClientChange(): void
    {
        if (! auth()->user()?->hasRole('Super Admin') && ! hasAccess(['Super Admin'], ['edit-customer'])) {
            abort(403, 'Unauthorized action.');
        }

        $this->bulkClientError = '';
        $sourceName = trim((string) $this->bulkClientSourceRouter);
        $targetName = trim((string) $this->bulkClientTargetRouter);
        $ids = collect($this->bulkClientSelectedIds)->map(fn ($id) => trim((string) $id))->filter()->unique()->values();

        if ($ids->isEmpty() || $ids->count() > 100 || $sourceName === '' || $targetName === '') {
            $this->bulkClientError = 'Select customers, source router, destination router and destination PPP profile.';
            return;
        }

        if ($sourceName === $targetName) {
            $this->bulkClientError = 'Source and destination routers must be different.';
            return;
        }

        $sourceRouter = RouterList::where('router_name', $sourceName)->where('action', 'connected')->first();
        $targetRouter = RouterList::where('router_name', $targetName)->where('action', 'connected')->first();
        if (! $sourceRouter || ! $targetRouter) {
            $this->bulkClientError = 'Both source and destination MikroTik routers must be connected.';
            return;
        }

        $customers = CustomersInfo::with('pppUser')
            ->whereIn('customer_unique_id', $ids->all())
            ->get();

        if ($customers->count() !== $ids->count()) {
            $this->bulkClientError = 'Selected customer records changed. Close this window, refresh the table and select the customers again.';
            return;
        }

        $invalidCustomers = $customers->filter(function ($customer) use ($sourceName) {
            return ! $customer->pppUser
                || strtolower((string) $customer->pppUser->service) !== 'pppoe'
                || (string) $customer->pppUser->router_name !== $sourceName
                || ! filled($customer->pppUser->username);
        });

        if ($invalidCustomers->isNotEmpty()) {
            $this->bulkClientError = 'All selected customers must still have a PPPoE user on the selected source router.';
            return;
        }

        $mikrotik = app(MikrotikController::class);

        try {
            $targetProfiles = $mikrotik->singleRead(
                $targetName,
                '/ppp/profile/print',
                'ppp profile print without-paging terse',
                [],
                false,
                true
            );
            $profileNames = collect($targetProfiles)->pluck('name')->filter()->map(fn ($name) => (string) $name)->all();
            $profileMap = [];
            $preflightErrors = [];
            foreach ($customers as $customer) {
                $cid = (string) $customer->customer_unique_id;
                $selectedProfile = trim((string) ($this->bulkClientProfileMap[$cid] ?? ''));
                if ($selectedProfile === '' || ! in_array($selectedProfile, $profileNames, true)) {
                    $preflightErrors[] = $cid.': select a valid destination PPP profile';
                    continue;
                }
                $profileMap[$cid] = $selectedProfile;
            }

            $sourceSecrets = $mikrotik->singleRead(
                $sourceName,
                '/ppp/secret/print',
                'ppp secret print without-paging terse',
                [],
                false,
                true
            );
            $targetSecrets = $mikrotik->singleRead(
                $targetName,
                '/ppp/secret/print',
                'ppp secret print without-paging terse',
                [],
                false,
                true
            );

            $sourceByUsername = collect($sourceSecrets)->filter(fn ($secret) => filled($secret['name'] ?? null))
                ->keyBy(fn ($secret) => mb_strtolower((string) $secret['name']));
            $targetUsernames = collect($targetSecrets)->pluck('name')->filter()
                ->map(fn ($name) => mb_strtolower((string) $name))->all();

            $prepared = [];
            $seen = [];

            foreach ($customers as $customer) {
                $ppp = $customer->pppUser;
                $username = trim((string) $ppp->username);
                $key = mb_strtolower($username);
                if (isset($seen[$key])) {
                    $preflightErrors[] = $customer->customer_unique_id.': duplicate PPP username in selected customers';
                    continue;
                }
                $seen[$key] = true;

                if (in_array($key, $targetUsernames, true)) {
                    $preflightErrors[] = $customer->customer_unique_id.': username already exists on destination router';
                    continue;
                }

                $sourceSecret = $sourceByUsername->get($key);
                if (! $sourceSecret) {
                    $preflightErrors[] = $customer->customer_unique_id.': PPP secret was not found on source router';
                    continue;
                }

                $password = (string) ($sourceSecret['password'] ?? '');
                if ($password === '' || $password === '-' || str_contains($password, '*')) {
                    $password = (string) $ppp->password;
                }
                if ($password === '' || $password === '-' || str_contains($password, '*') || preg_match('/^\$2[ayb]\$/', $password)) {
                    $preflightErrors[] = $customer->customer_unique_id.': recoverable PPP password is not available';
                    continue;
                }

                $disabledValue = strtolower((string) ($sourceSecret['disabled'] ?? 'unknown'));
                if (! in_array($disabledValue, ['true', 'false', 'yes', 'no', '1', '0'], true)) {
                    $preflightErrors[] = $customer->customer_unique_id.': source enabled/disabled state could not be read';
                    continue;
                }

                $prepared[] = [
                    'customer' => $customer,
                    'ppp' => $ppp,
                    'target_profile' => $profileMap[(string) $customer->customer_unique_id] ?? '',
                    'source_secret' => $sourceSecret,
                    'password' => $password,
                    'was_disabled' => in_array($disabledValue, ['true', 'yes', '1'], true),
                ];
            }

            if (! empty($preflightErrors)) {
                $this->bulkClientError = 'Pre-check stopped the whole batch; no router or customer records were changed: '.implode(' | ', $preflightErrors);
                return;
            }
        } catch (\Throwable $e) {
            \Log::warning('Bulk client copy preflight failed', [
                'source_router' => $sourceName,
                'target_router' => $targetName,
                'error' => $e->getMessage(),
            ]);
            $this->bulkClientError = 'Router pre-check failed. No PPP secrets were copied; verify both router connections and try again.';
            return;
        }

        $copied = 0;
        $failed = 0;
        $warnings = [];

        foreach ($prepared as $item) {
            $customer = $item['customer'];
            $ppp = $item['ppp'];
            $sourceSecret = $item['source_secret'];
            $targetProfile = (string) $item['target_profile'];
            $username = (string) $ppp->username;
            $targetCreated = false;

            $params = [
                'name' => $username,
                'password' => $item['password'],
                'service' => 'pppoe',
                'profile' => $targetProfile,
                'disabled' => 'yes',
            ];

            $comment = (string) ($sourceSecret['comment'] ?? $ppp->comment ?? '');
            $callerId = (string) ($sourceSecret['caller-id'] ?? $ppp->caller_id ?? '');
            $remoteAddress = (string) ($sourceSecret['remote-address'] ?? $ppp->ppp_remote_ip ?? '');
            if ($comment !== '') $params['comment'] = $comment;
            if ($callerId !== '') $params['caller-id'] = $callerId;
            if ($remoteAddress !== '') $params['remote-address'] = $remoteAddress;

            try {
                // Stage the destination secret as disabled first. The source remains
                // untouched until the destination entry has been read back successfully.
                $mikrotik->singleWrite($targetName, '/ppp/secret/add', $params);
                $targetCreated = true;

                $targetCheck = $mikrotik->singleRead(
                    $targetName,
                    '/ppp/secret/print',
                    'ppp secret print without-paging terse',
                    ['name' => $username],
                    false,
                    true
                );
                $targetRecord = collect($targetCheck)->first(
                    fn ($secret) => mb_strtolower((string) ($secret['name'] ?? '')) === mb_strtolower($username)
                );
                $verifiedProfile = (string) ($targetRecord['profile'] ?? '');
                if (! $targetRecord || $verifiedProfile !== $targetProfile) {
                    throw new \RuntimeException('Destination PPP secret or selected profile could not be verified after creation.');
                }

                // The source router and local customer/PPP assignment are intentionally
                // left unchanged. This workflow only creates a copy on the destination.
                try {
                    $mikrotik->singleWrite(
                        $targetName,
                        '/ppp/secret/set [find name='.$mikrotik->mtQuote($username).']',
                        ['disabled' => $item['was_disabled'] ? 'yes' : 'no']
                    );
                } catch (\Throwable $stateError) {
                    $warnings[] = $customer->customer_unique_id.' copied to destination but remains disabled because its original state could not be restored.';
                    \Log::warning('Bulk client copy destination secret state could not be restored', [
                        'customer_id' => $customer->customer_unique_id,
                        'target_router' => $targetName,
                        'error' => $stateError->getMessage(),
                    ]);
                }

                $copied++;
            } catch (\Throwable $e) {
                $failed++;
                if ($targetCreated) {
                    try {
                        $mikrotik->singleWrite($targetName, '/ppp/secret/remove [find name='.$mikrotik->mtQuote($username).']');
                    } catch (\Throwable $cleanupError) {
                        \Log::error('Bulk client copy could not remove an incomplete destination PPP secret', [
                            'customer_id' => $customer->customer_unique_id,
                            'target_router' => $targetName,
                            'error' => $cleanupError->getMessage(),
                        ]);
                    }
                }

                \Log::error('Bulk client copy failed for customer', [
                    'customer_id' => $customer->customer_unique_id,
                    'source_router' => $sourceName,
                    'target_router' => $targetName,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->bulkClientModalOpen = false;
        $this->dispatch('customer-action-done');

        if ($failed === 0 && $copied > 0 && empty($warnings)) {
            flash()->addSuccess("Bulk Client Copy completed. {$copied} customer PPP secret(s) copied from {$sourceName} to {$targetName}. Source secrets and customer assignments were left unchanged.");
        } elseif ($copied > 0) {
            flash()->addWarning("Bulk Client Copy finished. Copied: {$copied}, Failed: {$failed}. ".count($warnings).' additional warning(s); review the application log.');
        } else {
            flash()->addError("Bulk Client Copy failed. Copied: 0, Failed: {$failed}. The source router and customer assignments were left unchanged.");
        }
    }

    private function syncCustomer(CustomersInfo $customer): void
    {
        $user = $customer->pppUser;
        if (!$user || empty($user->router_name)) return;

        $router = $user->router_name;
        $name = $user->username;
        $qName = app(MikrotikController::class)->mtQuote($name);
        $ip = $user->ppp_remote_ip ?: $user->ip_address;
        $comment = $user->comment ?: '';

        if ($user->service === 'pppoe') {
            // Check if secret exists on router
            $check = app(MikrotikController::class)->singleRead($router, '/ppp/secret/print', "ppp secret print without-paging terse where name=$qName", ['name' => $name], false);
            $exists = is_array($check) && count($check) > 0;

            $pass = $user->password;
            $prof = $user->profile;
            $caller = $user->caller_id ?? '';

            if (!$exists) {
                $cmd = "/ppp secret add name=\"$name\" password=\"$pass\" service=\"pppoe\" profile=\"$prof\" comment=\"$comment\" caller-id=\"$caller\"" . (!empty($ip) ? " remote-address=\"$ip\"" : "") . " disabled=yes";
            } else {
                $cmd = "/ppp secret set [find name=$qName] password=\"$pass\" profile=\"$prof\" comment=\"$comment\" caller-id=\"$caller\"" . (!empty($ip) ? " remote-address=\"$ip\"" : "");
            }
            app(MikrotikController::class)->singleWrite($router, $cmd);

            // Restore status
            if ($customer->status === 'active') {
                app(MikrotikController::class)->enablePPPSecret($customer->customer_unique_id, $router, $name);
            } elseif ($customer->status === 'disable') {
                app(MikrotikController::class)->disablePPPSecret($customer->customer_unique_id, $router, $name);
            } else {
                app(MikrotikController::class)->singleWrite($router, "/ppp secret set [find name=$qName] disabled=yes");
            }
        } elseif ($user->service === 'static') {
            // Check if simple queue exists on router
            $check = app(MikrotikController::class)->singleRead($router, '/queue/simple/print', "queue simple print without-paging terse where name=$qName", ['name' => $name], false);
            $exists = is_array($check) && count($check) > 0;

            $limit = $user->bandwidth ?: '10M/10M';
            $interface = $user->profile ?: '';

            if (!$exists) {
                $cmd = "/queue simple add name=\"$name\" profile=\"$interface\" address=\"$ip\" max-limit=\"$limit\" comment=\"$comment\" disabled=yes";
            } else {
                $cmd = "/queue simple set [find name=$qName] profile=\"$interface\" address=\"$ip\" max-limit=\"$limit\" comment=\"$comment\"";
            }
            app(MikrotikController::class)->singleWrite($router, $cmd);

            // Restore status
            app(MikrotikController::class)->toggleSimpleQueue($router, $name, $customer->status === 'active');
        }
    }
}
