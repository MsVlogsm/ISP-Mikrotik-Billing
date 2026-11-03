<?php

namespace App\Livewire;

use App\Http\Controllers\MikrotikController;
use App\Models\AddressField;
use App\Models\BillingInfo;
use App\Models\CustomersAddress;
use App\Models\CustomersInfo;
use App\Models\OfficialInfo;
use App\Models\PackageList;
use App\Models\PPPSecrets;
use App\Models\Reseller;
use App\Models\RouterList;
use App\Models\User;
use App\Rules\ValidPhoneDigits;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Livewire\Attributes\Validate;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

class EditCustomer extends Component
{
    use WithFileUploads;

    #[Locked]
    public bool $macResellerPortalMode = false;

    public $customerId;

    public $fields = [];

    public $tempFields = [];

    public $ppp_user_id;

    public $routers;

    public $router_name;

    public $interface;

    public $ip_address;

    public $bandwidth;

    public $caller_id;

    public $profile;

    public $profileNames;

    public $service;

    public $queue_name;

    public $auto_disable_date;

    public $username;

    public $password;

    public $data = [];

    public $auto_disable;

    public $tempValue;

    public $ppp_remote_ip;

    public $comment;

    // public $dropdownOptions = [
    //     'customer_type' => ['Individual', 'Corporate'],
    //     'billing_status' => ['Active', 'Inactive', 'Pending'],
    // ];
    public $customerAddress = [];

    public $addressFields;

    public $packageLists;

    public $photo_url;

    public $userLists;

    public $resellersList = [];

    public $interfaceNames = [];

    private function mtValue(?string $value): string
    {
        return app(\App\Http\Controllers\MikrotikController::class)->mtQuote($value);
    }

    public function mount($customerId)
    {
        $this->macResellerPortalMode = auth()->user()->hasRole('Super Admin') && request()->is('mac-reseller*') && session()->has('mac_reseller_context_id');
        if (! hasAccess(['Super Admin'], ['edit-customer']) && ! ($this->macResellerPortalMode || auth()->user()->hasRole('Reseller')) && ! $this->macResellerPortalMode) {
            abort(403, 'Unauthorized action.');
        }

        $this->addressFields = AddressField::all();
        $isReseller = ($this->macResellerPortalMode || auth()->user()->hasRole('Reseller'));
        $this->routers = RouterList::query()
            ->select('id', 'router_name', 'action')
            ->where('action', 'connected')
            ->orderBy('router_name')
            ->get();
        $this->userLists = $isReseller
            ? User::query()->select('id', 'name', 'email')->whereKey(auth()->id())->get()
            : User::select('id', 'name', 'email')->get();
        if (! $isReseller && hasAccess(['Super Admin'], ['create-reseller'])) {
            $this->resellersList = Reseller::with('user')->get();
        }
        $this->customerId = $customerId;
        // Call loadCustomerData with the customerId parameter
        $this->loadCustomerData($customerId);
        $this->loadInterfaceNames();

        // Load packages based on the customer's router or reseller mapping
        $customer = CustomersInfo::where('customer_unique_id', decrypt($customerId))->first();
        $this->loadPackageLists($customer);
    }

    public function loadPackageLists($customer)
    {
        if ($customer && $customer->reseller_id) {
            $reseller = Reseller::find($customer->reseller_id);
            if ($reseller) {
                $routerName = $this->fields['pppUser']['router_name'] ?? null;
                $this->packageLists = PackageList::where(function ($q) use ($reseller) {
                    $q->whereIn('id', $reseller->assignedPackages->pluck('id'))
                        ->orWhere('reseller_id', $reseller->id);
                })
                    ->when(filled($routerName), function ($q) use ($routerName) {
                        $q->where(function ($routeQuery) use ($routerName) {
                            $routeQuery->where('router_name', $routerName)->orWhereNull('router_name');
                        });
                    })
                    ->pluck('package');
                return;
            }
        }
        
        $routerName = $this->fields['pppUser']['router_name'] ?? null;
        $this->packageLists = PackageList::where('router_name', ! empty($routerName) ? $routerName : null)
            ->pluck('package');
    }

    public function loadPackagePrice(): void
    {
        $packageName = trim((string) ($this->fields['pppUser']['package_name'] ?? ''));
        if (strcasecmp($packageName, 'Free Customer') === 0) {
            $this->fields['billing']['monthly_rent'] = 0;
            return;
        }
        if ($packageName === '') {
            return;
        }

        $routerName = trim((string) ($this->fields['pppUser']['router_name'] ?? ''));
        $query = PackageList::where('package', $packageName);
        if (($this->macResellerPortalMode || auth()->user()->hasRole('Reseller'))) {
            $reseller = $this->macResellerPortalMode ? Reseller::where('panel_type', 'mac')->find(session('mac_reseller_context_id')) : auth()->user()->reseller;
            abort_unless($reseller, 403);
            $query->where(function ($q) use ($reseller) {
                $q->whereIn('id', $reseller->assignedPackages()->select('package_lists.id'))
                    ->orWhere('reseller_id', $reseller->id);
            });
        }
        if ($routerName !== '') {
            $query->where(function ($routeQuery) use ($routerName) {
                $routeQuery->where('router_name', $routerName)->orWhereNull('router_name');
            });
        }

        $package = $query->first();
        if ($package) {
            $this->fields['billing']['monthly_rent'] = $package->price;
        }
    }

    public function loadInterfaceNames()
    {
        if ($this->fields['pppUser']['router_name'] ?? false) {
            $routerName = $this->fields['pppUser']['router_name'];

            // Router details
            $router = RouterList::where('router_name', $routerName)->first();

            if ($router && $router->action === 'connected') {
                // Clear profileNames before updating (fix: was incorrectly writing to interfaceNames)
                $this->profileNames = [];

                $results = app(MikrotikController::class)->singleRead(
                    $routerName,
                    '/ppp/profile/print',
                    '/ppp profile print without-paging terse'
                );

                foreach ($results as $item) {
                    if (is_array($item) && isset($item['name'])) {
                        $this->profileNames[] = $item['name'];
                    }
                }
            }
        }
    }

    public function loadCustomerData($customerId)
    {
        $query = CustomersInfo::where('customer_unique_id', decrypt($customerId))
            ->with('customerAddress', 'billing', 'official', 'pppUser', 'package');
        if (($this->macResellerPortalMode || auth()->user()->hasRole('Reseller'))) {
            $reseller = $this->macResellerPortalMode ? Reseller::where('panel_type', 'mac')->find(session('mac_reseller_context_id')) : auth()->user()->reseller;
            abort_unless($reseller && $reseller->isActive(), 403, 'Reseller account is not active.');
            $query->where('reseller_id', $reseller->id);
            // Hide cross-tenant IDs behind an explicit authorization response.
            $customer = $query->first();
            abort_unless($customer, 403, 'You can only edit your own clients.');
        } else {
            $customer = $query->firstOrFail();
        }

        if ($customer) {
            $addressFields = $this->addressFields; // Retrieve all AddressField entries

            $customerAddresses = $addressFields->mapWithKeys(function ($field) use ($customer) {
                $matchedValue = ''; // Default value

                // Loop through each address and find the first non-empty value for each field label
                foreach ($customer->customerAddress as $address) {
                    $inputTypeKey = 'input_type_'.$field->input_type;

                    // Check if the address field has a value for the current label
                    if (! empty($address->{$inputTypeKey}) && $address->label_name === $field->label) {
                        $matchedValue = $address->{$inputTypeKey};
                        break; // Stop after finding the first non-empty matching value
                    }
                }

                return [$field->label => $matchedValue];
            })->toArray();

            // Distribution Location Point is now part of Customer Address.
            // Backfill the edit form from the legacy OfficialInfo value when older
            // customers do not yet have an address record for this field.
            if (empty($customerAddresses['Distribution Location Point'] ?? '') && ! empty($customer->official?->distribution_location)) {
                $customerAddresses['Distribution Location Point'] = $customer->official->distribution_location;
            }

            $this->ppp_user_id = $customer->ppp_user_id;
            // Initialize fields for the customer and related data
            $this->fields = [
                'customer' => [
                    'customer_unique_id' => $customer->customer_unique_id ?? '',
                    'customer_name' => $customer->customer_name ?? '',
                    'contact_person' => $customer->contact_person ?? '',
                    'parents_name' => $customer->parents_name ?? '',
                    'spouse_name' => $customer->spouse_name ?? '',
                    'mobile' => $customer->mobile ?? '',
                    'alternative_mobile' => $customer->alternative_mobile ?? '',
                    'email' => $customer->email ?? '',
                    'identification_no' => $customer->identification_no ?? '',
                    'profession' => $customer->profession ?? '',
                    'created_at' => Carbon::parse($customer->created_at)->format('d M Y, h:i:s A') ?? '',
                    'photo_url' => $customer->photo_url ?? '',
                ],
                'billing' => [
                    'monthly_rent' => $customer->billing->monthly_rent ?? '',
                    'additional_charge' => $customer->billing->additional_charge ?? '',
                    'discount' => $customer->billing->discount ?? '',
                    'advance' => $customer->billing->advance ?? '',
                    'previous_due' => $customer->billing->previous_due ?? '',
                    'vat' => $customer->billing->vat ?? '',
                    'total_amount' => $customer->billing->total_amount ?? '',
                    'billing_type' => $customer->billing->billing_type ?? '',
                    // Add more billing-related fields
                ],
                'customerAddress' => $customerAddresses, // This will be an array of addresses

                'pppUser' => array_merge([
                    'connection_date' => $customer->connection_date ? Carbon::parse($customer->connection_date)->format('Y-m-d') : '',
                    'package_name' => $customer->package?->package ?? '',
                    'ppp_user_id' => $this->ppp_user_id ?? '',
                ], $this->ppp_user_id !== null ? [
                    'router_name' => $customer->pppUser->router_name ?? '',
                    'username' => $customer->pppUser->username ?? '',
                    'password' => $customer->pppUser->password ?? '',
                    'service' => $customer->pppUser->service ?? '',
                    'profile' => $customer->pppUser->profile ?? '',
                    'caller_id' => $customer->pppUser->caller_id ?? '',
                    'comment' => $customer->pppUser->comment ?? '',
                    'ppp_remote_ip' => $customer->pppUser->ppp_remote_ip ?? '',
                    'ip_address' => \Illuminate\Support\Facades\Schema::hasColumn('p_p_p_secrets', 'ip_address') ? ($customer->pppUser->ip_address ?? '') : '',
                    'bandwidth' => $customer->pppUser->bandwidth ?? '',
                    'interface' => \Illuminate\Support\Facades\Schema::hasColumn('p_p_p_secrets', 'interface') ? ($customer->pppUser->interface ?? '') : '',
                    'subnet_prefix' => \Illuminate\Support\Facades\Schema::hasColumn('p_p_p_secrets', 'subnet_prefix') ? ($customer->pppUser->subnet_prefix ?? '') : '',
                    'gateway' => \Illuminate\Support\Facades\Schema::hasColumn('p_p_p_secrets', 'gateway') ? ($customer->pppUser->gateway ?? '') : '',
                ] : [],
                    [
                        'auto_disable_date' => $customer->billing?->auto_disable_date ? Carbon::parse($customer->billing->auto_disable_date)->format('Y-m-d') : '',
                        'auto_disable_month' => $customer->billing?->auto_disable_month ?? 0,
                        'auto_disable' => $customer->billing->auto_disable ?? '',
                    ]
                ),

                'official' => array_merge(
                    [
                        'service_charge' => $customer->official->service_charge ?? '',
                        'security_deposit' => $customer->official->security_deposit ?? '',
                        'client_type' => $customer->official->client_type ?? '',
                        'billing_type' => $customer->official->billing_type ?? '',
                        'connection_type' => $customer->official->connection_type ?? '',
                        'connectivity_type' => $customer->official->connectivity_type ?? '',
                        // Distribution Location Point is now canonical in Customer Address.
                        // 'bill_create' => $customer->official->bill_create ?? '',
                        'bill_sms' => $customer->official->bill_sms ?? '',
                        'continue_bill' => $customer->official->continue_bill ?? '',
                        'description' => $customer->official->description ?? '',
                        'note' => $customer->official->note ?? '',
                        // The dropdown uses user IDs as option values; keep the stored bigint ID here.
                        'connected_by' => $customer->official?->connected_by ? (string) $customer->official->connected_by : '',
                    ],
                    hasAccess(['Super Admin'], ['create-reseller']) ? ['reseller_id' => $customer->reseller_id && $customer->reseller ? ($customer->reseller->company ? $customer->reseller->company . ' (' . $customer->reseller->user->name . ')' : $customer->reseller->user->name) : ''] : [],
                    [
                        'status' => $customer->status ?? '',
                    ]
                ),
            ];

            // Initialize class-level properties linked to the UI
            $this->router_name = $customer->pppUser->router_name ?? '';
            $this->service = $customer->pppUser->service ?? '';
            $this->auto_disable = $customer->billing->auto_disable ?? true;
            $this->auto_disable_date = $customer->billing->auto_disable_date ?? null;

            $location = $customer->customerAddress->firstWhere('label_name', 'Network Location');
            $this->fields['customer']['latitude'] = $location?->latitude ?? '';
            $this->fields['customer']['longitude'] = $location?->longitude ?? '';
        } else {
            flash()->error('Customer not found.');
        }
    }


    public function saveEditForm(): void
    {
        if (! hasAccess(['Super Admin'], ['edit-customer']) && ! ($this->macResellerPortalMode || auth()->user()->hasRole('Reseller'))) {
            abort(403, 'Unauthorized action.');
        }

        $uid = decrypt($this->customerId);
        $customer = CustomersInfo::where('customer_unique_id', $uid)->with(['pppUser', 'billing', 'official'])->firstOrFail();
        if (($this->macResellerPortalMode || auth()->user()->hasRole('Reseller'))) {
            $reseller = $this->macResellerPortalMode ? Reseller::where('panel_type', 'mac')->find(session('mac_reseller_context_id')) : auth()->user()->reseller;
            abort_unless($reseller && $customer->reseller_id === $reseller->id, 403, 'You can only edit your own clients.');
        }
        $newUid = trim((string) ($this->fields['customer']['customer_unique_id'] ?? ''));
        $this->fields['customer']['customer_unique_id'] = $newUid;

        $this->validate([
            'fields.customer.customer_unique_id' => [
                'required',
                'string',
                'max:255',
                Rule::unique('customers_infos', 'customer_unique_id')->ignore($uid, 'customer_unique_id'),
            ],
            'fields.customer.customer_name' => 'required|min:3|max:255',
            'fields.customer.email' => 'nullable|email',
            'fields.customer.mobile' => ['nullable', 'string', new ValidPhoneDigits],
            'fields.customer.alternative_mobile' => ['nullable', 'string', new ValidPhoneDigits],
            'fields.customer.identification_no' => 'nullable|string|max:255',
            'fields.customer.latitude' => 'nullable|numeric|between:-90,90',
            'fields.customer.longitude' => 'nullable|numeric|between:-180,180',
            'fields.pppUser.username' => 'nullable|string|max:25',
            'fields.pppUser.password' => 'nullable|string|max:255',
            'fields.pppUser.ppp_remote_ip' => 'nullable|ip',
            'fields.pppUser.ip_address' => 'nullable|ip',
            'fields.pppUser.interface' => 'nullable|string|max:255',
            'fields.pppUser.subnet_prefix' => 'nullable|string|max:45',
            'fields.pppUser.gateway' => 'nullable|ip',
            'fields.pppUser.caller_id' => 'nullable|mac_address',
            'fields.billing.monthly_rent' => 'required|numeric',
        ]);

        DB::transaction(function () use ($customer, $uid, $newUid) {
            $c = $this->fields['customer'];
            $customer->update([
                'customer_unique_id' => $newUid,
                'customer_name' => $c['customer_name'] ?? '',
                'email' => $c['email'] ?? null,
                'mobile' => CustomersInfo::normalizeBangladeshMobile($c['mobile'] ?? null),
                'alternative_mobile' => CustomersInfo::normalizeBangladeshMobile($c['alternative_mobile'] ?? null),
                'identification_no' => $c['identification_no'] ?? null,
                'profession' => $c['profession'] ?? null,
                'connection_date' => $this->fields['pppUser']['connection_date'] ?? $customer->connection_date,
            ]);

            if ($uid !== $newUid) {
                foreach ([
                    ['billing_infos', 'customer_bill_unique_id'],
                    ['customers_addresses', 'customer_address_unique_id'],
                    ['official_infos', 'customer_office_unique_id'],
                    ['payment_summaries', 'customer_payment_unique_id'],
                    ['collection_summaries', 'customer_collection_unique_id'],
                    ['support_tickets', 'customer_unique_id'],
                    ['customer_wifi_router_logins', 'customer_unique_id'],
                    ['mikrotik_pending_actions', 'customer_unique_id'],
                ] as [$table, $column]) {
                    if (\Illuminate\Support\Facades\Schema::hasTable($table) && \Illuminate\Support\Facades\Schema::hasColumn($table, $column)) {
                        DB::table($table)->where($column, $uid)->update([$column => $newUid]);
                    }
                }
            }

            $ppp = $customer->pppUser;
            if ($ppp) {
                $p = $this->fields['pppUser'];
                $ppp->router_name = $p['router_name'] ?? $ppp->router_name;
                $ppp->service = $p['service'] ?? $ppp->service;
                $ppp->profile = $p['profile'] ?? $ppp->profile;
                $ppp->username = $p['username'] ?? $ppp->username;
                if (filled($p['password'] ?? null)) $ppp->password = $p['password'];
                $ppp->ppp_remote_ip = $p['ppp_remote_ip'] ?? null;
                // Some installations do not have a separate ip_address column.
                if (\Illuminate\Support\Facades\Schema::hasColumn('p_p_p_secrets', 'ip_address')) {
                    $ppp->ip_address = $p['ip_address'] ?? null;
                }
                $ppp->caller_id = $p['caller_id'] ?? null;
                $ppp->bandwidth = $p['bandwidth'] ?? null;
                $ppp->comment = $p['comment'] ?? null;
                $ppp->profile = $p['profile'] ?? $ppp->profile;
                foreach (['interface', 'subnet_prefix', 'gateway'] as $optionalColumn) {
                    if (\Illuminate\Support\Facades\Schema::hasColumn('p_p_p_secrets', $optionalColumn)) {
                        $ppp->{$optionalColumn} = $p[$optionalColumn] ?? null;
                    }
                }
                $ppp->save();
                $customer->ppp_user_id = $ppp->id;
                $customer->save();
            }

            $packageName = $this->fields['pppUser']['package_name'] ?? null;
            $isResellerEditor = $this->macResellerPortalMode || auth()->user()->hasRole('Reseller');
            if (strcasecmp(trim((string) $packageName), 'Free Customer') === 0) {
                $customer->package_id = null;
                // Do not let reseller edits bypass the approval/status guard.
                // For approved customer accounts, keep the status aligned with the free plan.
                if (! $isResellerEditor && in_array((string) $customer->status, ['active', 'free'], true)) {
                    $customer->status = 'free';
                }
                $customer->save();
            } elseif ($packageName) {
                $packageQuery = PackageList::where('package', $packageName)
                    ->when($customer->pppUser?->router_name, function ($q, $router) {
                        $q->where(function ($routeQuery) use ($router) {
                            $routeQuery->where('router_name', $router)->orWhereNull('router_name');
                        });
                    });
                if ($isResellerEditor) {
                    $reseller = $this->macResellerPortalMode ? Reseller::where('panel_type', 'mac')->find(session('mac_reseller_context_id')) : auth()->user()->reseller;
                    abort_unless($reseller, 403);
                    $packageQuery->where(function ($q) use ($reseller) {
                        $q->whereIn('id', $reseller->assignedPackages()->select('package_lists.id'))
                            ->orWhere('reseller_id', $reseller->id);
                    });
                }
                $package = $packageQuery->first();
                if ($package) {
                    $customer->package_id = $package->id;
                    // Switching from Free Customer back to a real package restores the normal status.
                    if (! $isResellerEditor && $customer->status === 'free') {
                        $customer->status = 'active';
                    }
                    $customer->save();
                }
            }

            $b = $this->fields['billing'];
            $billing = $customer->billing ?: new BillingInfo(['customer_bill_unique_id' => $newUid]);
            $billing->customer_bill_unique_id = $newUid;
            $billing->monthly_rent = $b['monthly_rent'] ?? 0;
            $billing->additional_charge = $b['additional_charge'] ?? 0;
            $billing->discount = $b['discount'] ?? 0;
            $billing->advance = $b['advance'] ?? 0;
            $billing->previous_due = $b['previous_due'] ?? 0;
            $billing->vat = $b['vat'] ?? 0;
            $billing->billing_type = $b['billing_type'] ?? 'prepaid';
            $billing->total_amount = $b['total_amount'] ?? 0;
            $billing->due_amount = $b['due_amount'] ?? 0;
            $billing->auto_disable = $this->fields['pppUser']['auto_disable'] ?? $billing->auto_disable;
            $billing->auto_disable_date = !empty($this->fields['pppUser']['auto_disable_date']) ? Carbon::parse($this->fields['pppUser']['auto_disable_date'])->format('Y-m-d') : null;
            $billing->auto_disable_month = (int) filter_var($this->fields['pppUser']['auto_disable_month'] ?? 0, FILTER_SANITIZE_NUMBER_INT);
            $billing->save();

            $o = $this->fields['official'];
            if ($customer->official) {
                $customer->official->update([
                    'billing_type' => $o['billing_type'] ?? $billing->billing_type,
                    'connection_type' => $o['connection_type'] ?? null,
                    'connectivity_type' => $o['connectivity_type'] ?? null,
                    'client_type' => $o['client_type'] ?? null,
                    // Keep the legacy OfficialInfo value synchronized with the canonical address field.
                    'distribution_location' => $this->fields['customerAddress']['Distribution Location Point']
                        ?? $customer->official->distribution_location
                        ?? null,
                    'description' => $o['description'] ?? null,
                    'note' => $o['note'] ?? null,
                    // connected_by is a bigint user ID. Accept both the current ID value and
                    // an older/stale browser value containing the user's display name.
                    'connected_by' => is_numeric($o['connected_by'] ?? null)
                        ? (int) $o['connected_by']
                        : ($this->userLists->firstWhere('name', $o['connected_by'] ?? '')?->id),
                    'security_deposit' => $o['security_deposit'] ?? 0,
                ]);
            }

            foreach ($this->fields['customerAddress'] ?? [] as $label => $value) {
                $field = AddressField::where('label', $label)->first();
                if (!$field) continue;
                $column = 'input_type_'.$field->input_type;
                $address = CustomersAddress::firstOrNew([
                    'customer_address_unique_id' => $newUid,
                    'label_name' => $label,
                ]);
                $address->$column = $value;
                $address->save();
            }

            $location = CustomersAddress::firstOrNew([
                'customer_address_unique_id' => $newUid,
                'label_name' => 'Network Location',
            ]);
            $location->latitude = $this->fields['customer']['latitude'] ?? null;
            $location->longitude = $this->fields['customer']['longitude'] ?? null;
            $location->save();
        });

        flash()->addSuccess('Customer updated successfully.');

        // Return the reseller to its own isolated client list after a successful save.
        if (($this->macResellerPortalMode || auth()->user()->hasRole('Reseller'))) {
            $routePrefix = ($this->macResellerPortalMode || auth()->user()->reseller?->isMacPanel()) ? 'mac-reseller' : 'reseller';
            $this->redirectRoute($routePrefix.'.customers.index', navigate: true);
            return;
        }
        $this->redirectRoute('customers.index', navigate: true);
    }

    public function resetPPPUser()
    {
        $this->auto_disable_date = $this->ip_address = $this->interface = $this->queue_name = $this->profile = $this->username = $this->password = $this->ppp_remote_ip = $this->caller_id = $this->bandwidth = $this->service = null;
    }

    public function deletePPPUser()
    {
        if (! hasAccess(['Super Admin'], ['delete-customer'])) {
            abort(403, 'Unauthorized action.');
        }

        $customer = CustomersInfo::where('customer_unique_id', decrypt($this->customerId))
            ->with('pppUser')
            ->first();

        if (! $customer || ! $customer->pppUser) {
            flash()->error('PPP User not found.');
            return;
        }

        $pppUser = $customer->pppUser;

        // Keep the local record when router removal fails so the application
        // does not falsely report a successful deletion.
        if (! empty($pppUser->router_name)) {
            try {
                app(MikrotikController::class)->singleWrite(
                    $pppUser->router_name,
                    '/ppp secret remove [find name="'.$pppUser->username.'"]'
                );
            } catch (\Throwable $routerEx) {
                \Log::error('deletePPPUser router error: '.$routerEx->getMessage());
                report($routerEx);
                flash()->error('Failed to remove PPP User from MikroTik. Database record was kept.');
                return;
            }
        }

        $pppUserId = $pppUser->id;
        $resellerId = $customer->reseller_id;
        $resellerName = 'N/A';
        if ($customer->reseller) {
            $resellerName = $customer->reseller?->user?->name ?? 'N/A';
            if ($customer->reseller?->company) {
                $resellerName = $customer->reseller->company . ' (' . $resellerName . ')';
            }
        }

        $customer->update([
            'status' => 'inactive',
            'reseller_id' => null,
            'ppp_user_id' => null,
        ]);
        PPPSecrets::where('id', $pppUserId)->delete();

        activity()
            ->performedOn($customer)
            ->causedBy(auth()->user())
            ->withProperties([
                'Reseller Info' => [
                    'customer_unique_id' => $customer->customer_unique_id,
                    'customer_name' => $customer->customer_name,
                    'customer_mobile' => $customer->mobile,
                    'reseller_id' => $resellerId,
                    'reseller_name' => $resellerName,
                    'customer_status' => $customer->status,
                ],
            ])
            ->log("Deleted PPP User for customer {$customer->customer_name} (ID: {$customer->customer_unique_id}).");

        flash()->success('Customer PPP User deleted successfully!');
        $this->ppp_user_id = null;
        $this->loadCustomerData($this->customerId);
    }

    public function getInterface($propertyName)
    {
        if (in_array($propertyName, ['service', 'router_name'])) {
            $this->data = [
                'service' => $this->service,
                'router_name' => $this->router_name,
            ];
            // Proceed only if service is static and router_name is set
            // auto disable date will be set only if router_name is set
            $normalizedRouterName = ! empty($this->router_name) ? $this->router_name : null;
            if ($normalizedRouterName) {
                $this->auto_disable = true;
                $this->auto_disable_date = now()->addDays(30)->format('Y-m-d');
            } else {
                $this->auto_disable = false;
                $this->auto_disable_date = $this->ip_address = $this->interface = $this->queue_name = $this->profile = $this->username = $this->password = $this->ppp_remote_ip = $this->caller_id = $this->bandwidth = $this->service = null;
            }
            // Proceed only if service is static and router_name is set than fetch interfaces and profile
            if ($this->service == 'static' && $normalizedRouterName) {
                try {
                    // Load physical interfaces via pooled/cached controller
                    $this->interfaceNames = [];
                    $results = app(MikrotikController::class)->singleRead(
                        $normalizedRouterName,
                        '/interface/print',
                        '/interface print without-paging terse where type="ether" or type="vlan"'
                    );
                    foreach ($results as $item) {
                        if (is_array($item) && isset($item['name'])) {
                            $this->interfaceNames[] = $item['name'];
                        }
                    }
                } catch (\Exception $e) {
                    report($e);
                    flash()->error('Router is not connected. Please check the router connection.');
                }

                $this->profileNames = [];
                $this->username = $this->password = $this->ppp_remote_ip = $this->caller_id = null;

                return;
            } elseif ($this->service == 'pppoe' && $normalizedRouterName) {
                // Proceed only if service is pppoe and router_name is set
                try {
                    // Load PPP profiles via pooled/cached controller
                    $this->profileNames = [];
                    $results = app(MikrotikController::class)->singleRead(
                        $normalizedRouterName,
                        '/ppp/profile/print',
                        '/ppp profile print without-paging terse'
                    );
                    foreach ($results as $item) {
                        if (is_array($item) && isset($item['name'])) {
                            $this->profileNames[] = $item['name'];
                        }
                    }
                } catch (\Exception $e) {
                    report($e);
                    flash()->error('Router is not connected. Please check the router connection.');
                }

                $this->interfaceNames = [];
                $this->ip_address = $this->queue_name = $this->caller_id = $this->bandwidth = null;

                return;
            } else {
                $this->interfaceNames = $this->profileNames = [];
                $this->auto_disable_date = $this->ip_address = $this->queue_name = $this->username = $this->password = $this->ppp_remote_ip = $this->caller_id = $this->bandwidth = $this->service = null;

                return;
            }
        }
    }

    public function rules()
    {
        return [
            'router_name' => 'required|required_with:service',
            'service' => 'nullable|required_with:router_name',
            'interface' => 'nullable|required_if:service,static|string|max:255',
            'ip_address' => 'nullable|required_if:service,static|ip',
            'subnet_prefix' => 'nullable|string|max:45',
            'gateway' => 'nullable|ip',
            'bandwidth' => ['nullable', 'required_if:service,static', 'regex:/^\d+(?:M|K)\/\d+(?:M|K)$/'],
            'caller_id' => 'nullable|mac_address',
            'queue_name' => 'nullable|required_if:service,static|string|max:25',
            'profile' => 'nullable|required_if:service,pppoe|string|max:25',
            // 'username' => 'nullable|required_if:service,pppoe|string|max:25|unique:p_p_p_secrets,username',
            'username' => [
                'nullable',
                'required_if:service,pppoe',
                'string',
                'max:25',
                function ($attribute, $value, $fail) {
                    $customer = CustomersInfo::with('pppUser')
                        ->where('customer_unique_id', decrypt($this->customerId))
                        ->first();

                    if ($customer && $customer->pppUser) {
                        // Check if the new username is different from the current one
                        $currentUsername = $customer->pppUser->username ?? null;

                        if ($currentUsername !== $value) {
                            $exists = CustomersInfo::whereHas('pppUser', function ($query) use ($value) {
                                $query->where('username', $value);
                            })->exists();

                            if ($exists) {
                                $fail("The username '{$value}' is already taken within PPP users.");
                            }
                        }
                    }
                },
            ],
        ];
    }

    public function savePPPUser()
    {
        if (($this->macResellerPortalMode || auth()->user()->hasRole('Reseller'))) {
            abort(403, 'Unauthorized action.');
        }
        try {
            $this->validate();

            if ($this->service == 'pppoe') {
                try {
                    $pppUserCheck = PPPSecrets::where('router_name', $this->router_name)->where('username', $this->username)->first();

                    if ($pppUserCheck) {
                        // Check if the same PPP user ID is already linked to another customer
                        $isUsed = CustomersInfo::where('ppp_user_id', $pppUserCheck->id);

                        if ($isUsed->exists()) {
                            flash()->error('This PPP User ID is already assigned to another customer.'.$isUsed->first()->customer_name.' ('.$isUsed->first()->customer_unique_id.') !');

                            return;
                        }
                    }

                    // Validate the prepaid expiry before creating anything on the router.
                    // This prevents an unpaid renewal attempt from leaving an orphan PPP secret.
                    $customerId = decrypt($this->customerId);
                    $billingPreflight = BillingInfo::where('customer_bill_unique_id', $customerId)->firstOrFail();
                    $billingPreflight->auto_disable_date = $this->auto_disable_date
                        ? Carbon::parse($this->auto_disable_date)->toDateString()
                        : Carbon::now()->addDays(30)->toDateString();
                    app(\App\Services\PrepaidExpiryGuard::class)->assertCanSave($billingPreflight);

                    // Build and execute PPP secret add via pooled/cached controller
                    if ($this->ppp_remote_ip != '') {
                        $cmd = "/ppp secret add name={$this->mtValue($this->username)} password={$this->mtValue($this->password)} service={$this->mtValue($this->service)} profile={$this->mtValue($this->profile)} comment={$this->mtValue($this->comment)} remote-address={$this->mtValue($this->ppp_remote_ip)} caller-id={$this->mtValue($this->caller_id)}";
                    } else {
                        $cmd = "/ppp secret add name={$this->mtValue($this->username)} password={$this->mtValue($this->password)} service={$this->mtValue($this->service)} profile={$this->mtValue($this->profile)} comment={$this->mtValue($this->comment)} caller-id={$this->mtValue($this->caller_id)}";
                    }

                    // Validate the prepaid expiry before creating anything on the router.
                    // This prevents an unpaid renewal attempt from leaving an orphan PPP secret.
                    $customerId = decrypt($this->customerId);
                    $billingPreflight = BillingInfo::where('customer_bill_unique_id', $customerId)->firstOrFail();
                    $billingPreflight->auto_disable_date = $this->auto_disable_date
                        ? Carbon::parse($this->auto_disable_date)->toDateString()
                        : Carbon::now()->addDays(30)->toDateString();
                    app(\App\Services\PrepaidExpiryGuard::class)->assertCanSave($billingPreflight);

                    app(MikrotikController::class)->singleWrite($this->router_name, $cmd);

                    // Router write succeeded — persist to database
                    try {
                        $customerId = decrypt($this->customerId);
                    } catch (\Exception $e) {
                        flash()->error('Invalid Customer ID!');

                        return;
                    }

                    // Create or fetch the PPP User record
                    $pppUser = PPPSecrets::firstOrCreate(
                        ['router_name' => $this->router_name, 'username' => $this->username],
                        [
                            'password' => $this->password,
                            'service' => $this->service,
                            'profile' => $this->profile,
                            'comment' => $this->comment,
                            'caller_id' => $this->caller_id,
                            'status' => 'active',
                            'ppp_remote_ip' => ! empty($this->ppp_remote_ip) ? $this->ppp_remote_ip : $this->ip_address,
                        ]
                    );

                    // Update Billing Info through Eloquent so the prepaid expiry guard applies.
                    $billing = BillingInfo::where('customer_bill_unique_id', $customerId)->firstOrFail();
                    $billing->auto_disable_date = $this->auto_disable_date
                        ? Carbon::parse($this->auto_disable_date)->toDateString()
                        : Carbon::now()->addDays(30)->toDateString();
                    $billing->save();

                    // Update Customer Info if PPP user is created successfully
                    if ($pppUser->exists) {
                        CustomersInfo::where('customer_unique_id', $customerId)->update([
                            'status' => 'active',
                            'ppp_user_id' => $pppUser->id,
                        ]);
                    }

                    flash()->success('Customer PPP User created successfully!');

                    // Reload customer data and reset form
                    $this->loadCustomerData($this->customerId);
                    $this->resetPPPUser();

                } catch (ValidationException $e) {
                    flash()->error($e->validator->errors()->first());
                    return;
                } catch (\Exception $e) {
                    // Handle any connection or execution errors
                    report($e);
                    flash()->error('Router is not connected. Please check the router connection.');
                }
            } elseif ($this->service == 'static') {
                try {
                    // Add simple queue via pooled/cached controller
                    app(MikrotikController::class)->singleWrite(
                        $this->router_name,
                        "/queue simple add name={$this->mtValue($this->queue_name)} profile={$this->mtValue($this->profile)} address={$this->mtValue($this->ip_address)} max-limit={$this->mtValue($this->bandwidth)} comment={$this->mtValue($this->comment)} disabled=yes"
                    );

                    // Router write succeeded — persist to database
                    $pppUser = new PPPSecrets;
                    $pppUser->router_name = $this->router_name;
                    $pppUser->username = ($this->username != '') ? $this->username : $this->queue_name;
                    $pppUser->password = $this->password;
                    $pppUser->service = $this->service;
                    $pppUser->profile = ($this->profile != '') ? $this->profile : $this->interface;
                    $pppUser->bandwidth = $this->bandwidth;
                    $pppUser->comment = $this->comment;
                    $pppUser->caller_id = $this->caller_id;
                    $pppUser->ppp_remote_ip = ($this->ppp_remote_ip != '') ? $this->ppp_remote_ip : $this->ip_address;
                    $pppUser->save();
                    CustomersInfo::where('customer_unique_id', decrypt($this->customerId))->update([
                        'status' => 'active',
                    ]);
                    flash()->success('Customer PPP User created successfully!');
                    $this->loadCustomerData($this->customerId);
                    $this->resetPPPUser();
                } catch (\Exception $e) {
                    // Handle any connection or execution errors
                    report($e);
                    flash()->error('Router is not connected. Please check the router connection.');
                }
            }
        } catch (ValidationException $e) {
            // Validation failed, extract error messages
            $errors = $e->validator->errors()->all();

            // Loop through the errors and dispatch each as a toast notification
            foreach ($errors as $error) {
                flash()->error($error);
            }

            // Re-throw the validation exception to allow @error directive to work
            throw $e;
        } catch (\Exception $e) {
            // Handle any other type of exception
            report($e);
            flash()->error('Operation failed. Please try again.');
        }
    }

    public function removePhoto()
    {
        $this->photo_url = null;
        flash()->warning('Image Removed successfully!');
    }

    public function deletePhoto()
    {
        $photoUrl = CustomersInfo::where('customer_unique_id', decrypt($this->customerId))->firstOrFail();
        if (($this->macResellerPortalMode || auth()->user()->hasRole('Reseller'))) {
            $reseller = $this->macResellerPortalMode ? Reseller::where('panel_type', 'mac')->find(session('mac_reseller_context_id')) : auth()->user()->reseller;
            abort_unless($reseller && $photoUrl->reseller_id === $reseller->id, 403, 'You can only edit your own clients.');
        }
        if ($photoUrl->photo_url && file_exists(public_path($photoUrl->photo_url))) {
            unlink(public_path($photoUrl->photo_url));
        }
        $photoUrl->update([
            'photo_url' => null,
        ]);
        $this->fields['customer']['photo_url'] = null;
        flash()->warning('Image Removed successfully!');
    }

    public function savePhoto()
    {
        $customer = CustomersInfo::where('customer_unique_id', decrypt($this->customerId))->firstOrFail();
        if (($this->macResellerPortalMode || auth()->user()->hasRole('Reseller'))) {
            $reseller = $this->macResellerPortalMode ? Reseller::where('panel_type', 'mac')->find(session('mac_reseller_context_id')) : auth()->user()->reseller;
            abort_unless($reseller && $customer->reseller_id === $reseller->id, 403, 'You can only edit your own clients.');
        }

        // Generate a unique filename and define the path
        $filename = uniqid().'.jpg';
        $path = 'customer-images/'.$filename;

        if ($this->photo_url) {
            $image_file = $this->photo_url->getRealPath();
            // create new manager instance with desired driver
            $manager = new ImageManager(new Driver);
            // read image from file system
            $image = $manager->read($image_file);
            // Image resize
            $image->resize(300, 300);
            // save modified image in new format
            $image->save(public_path("$path"));

            $photoUrl = CustomersInfo::where('customer_unique_id', decrypt($this->customerId))->update([
                'photo_url' => $path,
            ]);
            flash()->success('Image uploaded successfully!');
        }
    }

    public function startEditing($field)
    {
        // Store the current value of the field being edited in tempFields
        data_set($this->tempFields, $field, data_get($this->fields, $field));
    }

    public function cancelEditing($field)
    {
        // Restore the value of the field from tempFields if editing is canceled
        if (data_get($this->tempFields, $field) !== null) {
            data_set($this->fields, $field, data_get($this->tempFields, $field));
            unset($this->tempFields[$field]); // Clear temp value after cancel
        }
    }

    public function checkboxUpdated($propertyName)
    {
        $uid = decrypt($this->customerId);
        $customerQuery = CustomersInfo::where('customer_unique_id', $uid);
        if (($this->macResellerPortalMode || auth()->user()->hasRole('Reseller'))) {
            $reseller = $this->macResellerPortalMode ? Reseller::where('panel_type', 'mac')->find(session('mac_reseller_context_id')) : auth()->user()->reseller;
            abort_unless($reseller, 403);
            $customerQuery->where('reseller_id', $reseller->id);
        }
        $customer = $customerQuery->firstOrFail();

        $segments = explode('.', (string) $propertyName);
        abort_unless(count($segments) === 3 && $segments[0] === 'fields', 403);
        [, $group, $field] = $segments;
        $allowed = [
            'pppUser' => ['auto_disable'],
            'official' => ['billing_type', 'connection_type', 'connectivity_type', 'client_type'],
        ];
        abort_unless(in_array($field, $allowed[$group] ?? [], true), 403, 'Unsupported checkbox field.');

        if ($group === 'pppUser') {
            $billing = BillingInfo::where('customer_bill_unique_id', $customer->customer_unique_id)->firstOrFail();
            $billing->{$field} = data_get($this->fields, $propertyName);
            $updated = $billing->save();
        } else {
            $updated = OfficialInfo::where('customer_office_unique_id', $customer->customer_unique_id)
                ->update([$field => data_get($this->fields, $propertyName)]);
        }

        if ($updated) {

[executed on device: bill (d46e2bb3-ca74-4b6d-b341-ee3299469042)]