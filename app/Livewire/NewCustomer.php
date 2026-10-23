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
use App\Models\RouterList;
use App\Models\Reseller;
use App\Models\User;
use App\Services\PrepaidBillingCycle;
use Carbon\Carbon;
use Livewire\Attributes\Locked;
// MikrotikSSHService removed — all router I/O routed through MikrotikController (pooled + cached)
use App\Rules\ValidPhoneDigits;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Livewire\Component;
use Livewire\WithFileUploads;

class NewCustomer extends Component
{
    use WithFileUploads;

    #[Locked]
    public bool $macResellerPortalMode = false;

    public $customer_name;

    public $customer_unique_id;

    public $email;

    public $identification_no;

    public $mobile;

    public $alternative_mobile;

    public $profession;

    public $connection_date;

    public $service;

    public $profile;

    public $ip_address;

    public $ppp_remote_ip;

    public $username;

    public $password;

    public $queue_name;

    public $caller_id;

    public $comment;

    public $interface;

    public $bandwidth;

    public $package_name;

    public $monthly_rent;

    public $due_amount;

    public $additional_charge;

    public $discount;

    public $advance;

    public $vat;

    public $total_amount;

    public $client_type;

    public $distribution_location;

    public $description;

    public $note;

    public $connected_by;

    public $security_deposit;

    public $auto_disable;

    public $auto_disable_date;

    public $router_name = '';

    public bool $importingMikrotikClient = false;

    public $auto_disable_month = 0;

    public $step = 1;

    public $billing_type = 'prepaid';

    public $connection_type = 'fiber';

    public $connectivity_type = 'shared';

    public $address = []; // Initialize as an array

    public $latitude;

    public $longitude;

    public $data = [];

    public $interfaceNames = []; // Make sure this is an array for Livewire updates

    public $profileNames = []; // Make sure this is an array for Livewire updates

    public $photo_url;

    // for on load
    public $addressFields;

    public $routers;

    public $packages;

    public $users; // Declare the property to hold address fields

    private function mtValue(?string $value): string
    {
        return app(\App\Http\Controllers\MikrotikController::class)->mtQuote($value);
    }

    private function currentReseller(): ?Reseller
    {
        if ($this->macResellerPortalMode) {
            return Reseller::where('panel_type', 'mac')->find(session('mac_reseller_context_id'));
        }
        return auth()->user()->reseller()->first();
    }

    public function mount()
    {
        $this->macResellerPortalMode = auth()->user()->hasRole('Super Admin') && request()->is('mac-reseller*') && session()->has('mac_reseller_context_id');
        if (! auth()->user()->can('create-customer') && ! ($this->macResellerPortalMode || auth()->user()->hasRole('Reseller')) && ! $this->macResellerPortalMode) {
            abort(403, 'Unauthorized action.');
        }

        $this->connected_by = auth()->id();
        if (! filled($this->connection_date)) {
            $this->connection_date = now()->format('Y-m-d');
        }

        $mikrotikClientId = request()->integer('mikrotik_client');
        if ($mikrotikClientId && ($this->macResellerPortalMode || auth()->user()->hasRole('Reseller'))) {
            abort(403, 'MikroTik user import is only available to authorized billing administrators.');
        }
        if ($mikrotikClientId) {
            $secret = PPPSecrets::findOrFail($mikrotikClientId);
            abort_unless($secret->service === null || strtolower((string) $secret->service) === 'pppoe', 422, 'Only PPPoE users can be imported from MikroTik.');

            $this->importingMikrotikClient = true;
            $this->customer_name = $secret->username;
            $this->router_name = $secret->router_name ?? '';
            $this->service = strtolower((string) ($secret->service ?: 'pppoe'));
            $this->profile = $secret->profile;
            $this->username = $secret->username;
            $this->password = $secret->password;
            $this->ppp_remote_ip = $secret->ppp_remote_ip;
            $this->caller_id = $secret->caller_id;
            $this->comment = $secret->comment;
            $this->connection_date = optional($secret->created_at)->format('Y-m-d') ?: now()->format('Y-m-d');

            // Parse the structured MikroTik comment and prefill matching customer fields.
            $parsedComment = $this->parseMikrotikComment($secret->comment);
            if (! empty($parsedComment['client_name'])) {
                $this->customer_name = $parsedComment['client_name'];
            }
            $this->mobile = $parsedComment['contact_number'] ?? null;
            if (! empty($parsedComment['present_address'])) {
                $this->address['Present Address'] = $parsedComment['present_address'];
            }
            if (! empty($parsedComment['zone_name'])) {
                $this->address['Zone Name'] = $parsedComment['zone_name'];
            }
            if (! empty($parsedComment['joining_date']) && strtotime($parsedComment['joining_date'])) {
                $this->connection_date = date('Y-m-d', strtotime($parsedComment['joining_date']));
            }
            $this->profileNames = $this->profile ? [$this->profile] : [];
            $this->auto_disable = true;
            if (($this->billing_type ?? 'prepaid') === 'prepaid' && filled($this->connection_date)) {
                $this->updatedConnectionDate($this->connection_date);
            } else {
                $this->auto_disable_date = now()->addDays(30)->format('Y-m-d');
            }
        }

        return true;
    }

    public function updatedConnectionDate($value): void
    {
        if (($this->billing_type ?? 'prepaid') !== 'prepaid' || blank($value)) {
            return;
        }

        $connectionDate = Carbon::parse($value)->startOfDay();
        $referenceDate = $connectionDate->gt(today()) ? $connectionDate : today();
        $this->auto_disable_date = PrepaidBillingCycle::nextExpiryDate($connectionDate, $referenceDate)->toDateString();
    }

    public function updatedBillingType($value): void
    {
        if ($value === 'prepaid' && filled($this->connection_date)) {
            $this->updatedConnectionDate($this->connection_date);
        }
    }

    public function updatedAutoDisable($value): void
    {
        if ((bool) $value && ($this->billing_type ?? 'prepaid') === 'prepaid' && filled($this->connection_date)) {
            $this->updatedConnectionDate($this->connection_date);
        }
    }

    private function parseMikrotikComment(?string $comment): array
    {
        $result = [];
        foreach (preg_split('/\r?\n/', (string) $comment) as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode(':', $line, 2));
            if ($key === '' || $value === '') {
                continue;
            }
            $normalized = strtolower(preg_replace('/[^a-z0-9]+/', '_', $key));
            $result[$normalized] = $value;
        }

        return [
            'client_name' => $result['client_name'] ?? $result['name'] ?? null,
            'contact_number' => $result['contact_number'] ?? $result['phone'] ?? $result['mobile'] ?? null,
            'zone_name' => $result['zone_name'] ?? $result['zone'] ?? null,
            'present_address' => $result['present_address'] ?? $result['address'] ?? null,
            'joining_date' => $result['joining_date'] ?? $result['connection_date'] ?? null,
        ];
    }

    public function rules()
    {
        // Start with the base rules
        $rules = [
            'customer_name' => 'required|min:3|max:255',
            'customer_unique_id' => 'required|string|max:255|unique:customers_infos,customer_unique_id',
            'mobile' => ['nullable', 'string', new ValidPhoneDigits],
            'email' => 'nullable|email',
            'alternative_mobile' => ['nullable', 'string', new ValidPhoneDigits],
            'identification_no' => 'nullable|string|max:255',
            'router_name' => 'nullable|required_with:service',
            'service' => 'nullable|required_with:router_name',
            'interface' => 'nullable|required_if:service,static',
            'ip_address' => 'nullable|required_if:service,static|ip',
            'bandwidth' => 'nullable|required_if:service,static|regex:/^\d+(M|K)\/\d+(M|K)$/',
            'caller_id' => 'nullable|mac_address',
            'queue_name' => 'nullable|required_if:service,static|string|max:25',
            'profile' => 'nullable|required_if:service,pppoe|string|max:25',
            'username' => 'nullable|required_if:service,pppoe|string|max:25',
            'monthly_rent' => 'required|numeric',
            'package_name' => 'nullable|string|max:255',
            'connected_by' => 'required',
            'billing_type' => 'required',
            'connection_type' => 'required',
            'connectivity_type' => 'required',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'photo_url' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ];

        // Add dynamic address rules if they exist
        if ($this->addressFields) {
            // dd($this->addressFields);
            foreach ($this->addressFields as $addressField) {
                if ($addressField->required == true) {
                    // Create rules for each required address field
                    $rules['address.'.$addressField->label] = 'required|string|max:255';
                }
            }
        }

        return $rules; // Return the combined rules
    }

    public function updated($propertyName)
    {
        $rules = $this->rules();
        if (array_key_exists($propertyName, $rules)) {
            $this->validateOnly($propertyName);
        }
    }

    public function updatedPackageName(): void
    {
        $this->calculateTotal('package_name');
    }

    public function calculateTotal($value)
    {
        // Free Customer is a deliberate zero-charge option, not a real package row.
        if (strcasecmp(trim((string) $this->package_name), 'Free Customer') === 0) {
            $this->monthly_rent = 0;
        } elseif ($this->package_name) {
            if (($this->macResellerPortalMode || auth()->user()->hasRole('Reseller'))) {
                $reseller = $this->currentReseller();
                $package = PackageList::where('package', $this->package_name)
                    ->where(function ($q) use ($reseller) {
                        $q->whereIn('id', $reseller->assignedPackages->pluck('id'))
                            ->orWhere('reseller_id', $reseller->id);
                    })
                    ->when(filled($this->router_name), function ($q) {
                        $routerName = $this->router_name;
                        $q->where(function ($routeQuery) use ($routerName) {
                            $routeQuery->where('router_name', $routerName)->orWhereNull('router_name');
                        });
                    })
                    ->first();
            } else {
                $package = PackageList::where('package', $this->package_name)
                    ->when(! empty($this->router_name), fn ($q) => $q->where('router_name', $this->router_name))
                    ->first();
            }
            $this->monthly_rent = $package?->price ?? '';
        }

        // Prevent modifying 'monthly_rent' if 'package_name' is set and warn the user
        if ($value == 'monthly_rent' && $this->package_name) {
            $this->addError('monthly_rent', 'First unset Package Name before changing Monthly Rent.');

            return;
        }

        // Recalculate total if any of the relevant fields change
        if (in_array($value, ['monthly_rent', 'due_amount', 'additional_charge', 'discount', 'advance', 'vat']) || $value == 'package_name') {
            $subtotal = (float) $this->monthly_rent + (float) $this->due_amount + (float) $this->additional_charge;
            $vatAmount = ($subtotal * (float) $this->vat) / 100;
            $this->total_amount = $subtotal + $vatAmount - ((float) $this->advance + (float) $this->discount);
        }
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
            if ($this->router_name) {
                $this->auto_disable = true;
                if (($this->billing_type ?? 'prepaid') === 'prepaid' && filled($this->connection_date)) {
                    $this->updatedConnectionDate($this->connection_date);
                } else {
                    $this->auto_disable_date = now()->addDays(30)->format('Y-m-d');
                }
            } else {
                $this->auto_disable = false;
                $this->auto_disable_date = $this->ip_address = $this->interface = $this->queue_name = $this->profile = $this->username = $this->password = $this->ppp_remote_ip = $this->caller_id = $this->bandwidth = $this->service = null;
            }
            // Proceed only if service is static and router_name is set than fetch interfaces and profile
            if ($this->service == 'static' && $this->router_name) {
                try {
                    // Load physical interfaces via pooled/cached controller
                    $this->interfaceNames = [];
                    $results = app(MikrotikController::class)->singleRead(
                        $this->router_name,
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
            } elseif ($this->service == 'pppoe' && $this->router_name) {
                // Proceed only if service is pppoe and router_name is set
                try {
                    // Load PPP profiles via pooled/cached controller
                    $this->profileNames = [];
                    $results = app(MikrotikController::class)->singleRead(
                        $this->router_name,
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

    public function removePhoto()
    {
        $this->photo_url = null;
        flash()->warning('Image Removed successfully!');
    }

    public function validateStepAndGo($targetStep)
    {
        // If user wants to go backward, just let them go
        if ($targetStep < $this->step) {
            $this->step = $targetStep;
            return;
        }

        // Validate step-by-step with a single validate pass
        try {
            $allRules = $this->rules();
            $stepRules = [];

            if ($this->step === 1) {
                // Collect rules for Step 1
                $stepRules['customer_name'] = $allRules['customer_name'];
                $stepRules['mobile'] = $allRules['mobile'];
                if ($this->email) {
                    $stepRules['email'] = $allRules['email'];
                }
                if ($this->alternative_mobile) {
                    $stepRules['alternative_mobile'] = $allRules['alternative_mobile'];
                }
                if ($this->identification_no) {
                    $stepRules['identification_no'] = $allRules['identification_no'];
                }
                
                $this->validate($stepRules);

            } elseif ($this->step === 2) {
                // Collect rules for Step 2 (Address)
                if ($this->addressFields) {
                    foreach ($this->addressFields as $addressField) {
                        if ($addressField->required == true) {
                            $fieldName = 'address.' . $addressField->label;
                            if (isset($allRules[$fieldName])) {
                                $stepRules[$fieldName] = $allRules[$fieldName];
                            }
                        }
                    }
                }
                if (!empty($stepRules)) {
                    $this->validate($stepRules);
                }

            } elseif ($this->step === 3 && !($this->macResellerPortalMode || auth()->user()->hasRole('Reseller'))) {
                // Collect rules for Step 3 (Network Config)
                if ($this->router_name || $this->service) {
                    $stepRules['router_name'] = $allRules['router_name'];
                    $stepRules['service'] = $allRules['service'];
                    
                    if ($this->service === 'static') {
                        $stepRules['interface'] = $allRules['interface'] ?? 'required';
                        $stepRules['ip_address'] = $allRules['ip_address'] ?? 'required|ip';
                        $stepRules['bandwidth'] = $allRules['bandwidth'] ?? 'required';
                        $stepRules['queue_name'] = $allRules['queue_name'] ?? 'required';
                    } elseif ($this->service === 'pppoe') {
                        $stepRules['profile'] = $allRules['profile'] ?? 'required';
                        $stepRules['username'] = $allRules['username'] ?? 'required';
                    }
                }
                if ($this->caller_id) {
                    $stepRules['caller_id'] = $allRules['caller_id'];
                }
                
                if (!empty($stepRules)) {
                    $this->validate($stepRules);
                }

            } elseif ($this->step === (!($this->macResellerPortalMode || auth()->user()->hasRole('Reseller')) ? 4 : 3)) {
                // Collect rules for Step 4 (Billing Info)
                $stepRules['package_name'] = 'required|string|max:255';
                $stepRules['monthly_rent'] = 'required|numeric';
                
                $this->validate($stepRules);
            }

            // If we successfully validated the current step's rules, check if intermediate steps are also valid
            if ($targetStep > $this->step + 1) {
                $targetRules = [];
                for ($s = $this->step + 1; $s < $targetStep; $s++) {
                    if ($s === 2) {
                        if ($this->addressFields) {
                            foreach ($this->addressFields as $addressField) {
                                if ($addressField->required == true) {
                                    $targetRules['address.' . $addressField->label] = 'required|string|max:255';
                                }
                            }
                        }
                    } elseif ($s === 3 && !($this->macResellerPortalMode || auth()->user()->hasRole('Reseller'))) {
                        if ($this->router_name || $this->service) {
                            $targetRules['router_name'] = 'required';
                            $targetRules['service'] = 'required';
                            if ($this->service === 'static') {
                                $targetRules['interface'] = 'required';
                                $targetRules['ip_address'] = 'required|ip';
                                $targetRules['bandwidth'] = 'required|regex:/^\d+(M|K)\/\d+(M|K)$/';
                                $targetRules['queue_name'] = 'required|string|max:25';
                            } elseif ($this->service === 'pppoe') {
                                $targetRules['profile'] = 'required|string|max:25';
                                $targetRules['username'] = 'required|string|max:25';
                            }
                        }
                    } elseif ($s === (!($this->macResellerPortalMode || auth()->user()->hasRole('Reseller')) ? 4 : 3)) {
                        $targetRules['package_name'] = 'required|string|max:255';
                        $targetRules['monthly_rent'] = 'required|numeric';
                    }
                }
                if (!empty($targetRules)) {
                    $this->validate($targetRules);
                }
            }

            $this->step = $targetStep;

        } catch (ValidationException $e) {
            // Re-throw validation exception to let Livewire display standard error messages on inputs
            throw $e;
        }
    }

    public function save()
    {
        if (! hasAccess(['Super Admin'], ['create-customer']) && ! ($this->macResellerPortalMode || auth()->user()->hasRole('Reseller'))) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $this->calculateTotal('package_name');
            $this->validate();

            if ($this->importingMikrotikClient) {
                // Importing an existing MikroTik PPP secret: never write it back to the router.
                $this->createUser();
                return;
            }

            if ($this->service == 'pppoe') {
                try {
                    // Add PPP secret via pooled/cached controller
                    if ($this->ppp_remote_ip != '') {
                        $cmd = "/ppp secret add name={$this->mtValue($this->username)} password={$this->mtValue($this->password)} service={$this->mtValue($this->service)} profile={$this->mtValue($this->profile)} comment={$this->mtValue($this->comment)} remote-address={$this->mtValue($this->ppp_remote_ip)} caller-id={$this->mtValue($this->caller_id)} disabled=yes";
                    } else {
                        $cmd = "/ppp secret add name={$this->mtValue($this->username)} password={$this->mtValue($this->password)} service={$this->mtValue($this->service)} profile={$this->mtValue($this->profile)} comment={$this->mtValue($this->comment)} caller-id={$this->mtValue($this->caller_id)} disabled=yes";
                    }

                    app(MikrotikController::class)->singleWrite($this->router_name, $cmd);

                    // Router write succeeded
                    $this->createUser();

                } catch (\Exception $e) {
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

                    // Router write succeeded
                    $this->createUser();
                } catch (\Exception $e) {
                    report($e);
                    flash()->error('Router is not connected. Please check the router connection.');
                }
            } else {
                $this->createUser();
            }
        } catch (ValidationException $e) {
            // Validation failed, extract error messages
            $errors = $e->validator->errors()->all();

            // Loop through the errors and each as a toast notification
            foreach ($errors as $error) {
                flash()->error($error);
            }

            // Re-throw the validation exception to allow @error directive to work
            throw $e;
        } catch (\Exception $e) {
            report($e);
            flash()->error('Operation failed. Please try again.');
        }
    }

    public function createUser()
    {
        // Start a database transaction
        DB::beginTransaction();

        try {
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
            }

            $pppUser = null;
            if (auth()->user()->can('mikrotik-user-create') || ($this->macResellerPortalMode || auth()->user()->hasRole('Reseller'))) {
                // create ppp_user table record
                $pppUserCheck = PPPSecrets::query()
                    ->when(filled($this->router_name), fn ($q) => $q->where('router_name', $this->router_name))
                    ->where(function ($q) {
                        $q->where('username', $this->username)
                            ->orWhere('username', $this->queue_name);
                    })
                    ->first();

                // Check if this PPP user is already linked to a customer
                if ($pppUserCheck && CustomersInfo::where('ppp_user_id', $pppUserCheck->id)->exists()) {
                    throw ValidationException::withMessages([
                        'username' => 'This PPP user is already linked to a customer.',
                    ]);
                }

                if ($pppUserCheck) {
                    $pppUser = $pppUserCheck;
                } else {
                    if ($this->router_name) {
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
                    }
                }
            }

            // Customer Unique Id is manual only. Never auto-generate an ID.
            $newId = trim((string) $this->customer_unique_id);
            if ($newId === '') {
                throw ValidationException::withMessages([
                    'customer_unique_id' => 'Customer Unique Id is required.',
                ]);
            }
            if (CustomersInfo::where('customer_unique_id', $newId)->exists()) {
                throw ValidationException::withMessages([
                    'customer_unique_id' => 'This Customer Unique Id is already in use.',
                ]);
            }
            $customer = new CustomersInfo;
            $isFreeCustomer = strcasecmp(trim((string) $this->package_name), 'Free Customer') === 0;
            if (($this->macResellerPortalMode || auth()->user()->hasRole('Reseller'))) {
                $customer->reseller_id = $this->currentReseller()?->id ?? abort(403, 'Select a MAC Reseller portal first.');
                // Reseller-created accounts must remain pending until an admin approves them.
                $customer->status = 'pending';
            } elseif ($isFreeCustomer) {
                // Free Customer is a first-class customer status, not just a missing package.
                $customer->status = 'free';
            }
            $customer->customer_unique_id = $newId;
            $customer->customer_name = $this->customer_name;
            $customer->email = $this->email;
            $customer->identification_no = $this->identification_no;
            $customer->photo_url = $this->photo_url ? $path : null;
            // Keep customer contact numbers in Bangladesh local format only.
            // CustomersInfo normalizes legacy international formats on import,
            // but Add Customer must not add a country-code prefix.
            $customer->mobile = filled($this->mobile) ? trim((string) $this->mobile) : null;
            $customer->alternative_mobile = filled($this->alternative_mobile) ? trim((string) $this->alternative_mobile) : null;
            $customer->profession = $this->profession;
            $customer->ppp_user_id = $pppUser?->id;
            $customer->connection_date = $this->connection_date;

            // Get package_id based on selected package name and router
            $assignedPackageId = null;
            if ($this->package_name && strcasecmp(trim((string) $this->package_name), 'Free Customer') !== 0) {
                if (($this->macResellerPortalMode || auth()->user()->hasRole('Reseller'))) {
                    $reseller = $this->currentReseller();
                    $pkg = PackageList::where('package', $this->package_name)
                        ->where(function ($q) use ($reseller) {
                            $q->whereIn('id', $reseller->assignedPackages->pluck('id'))
                                ->orWhere('reseller_id', $reseller->id);
                        })
                        ->when(filled($this->router_name), function ($q) {
                            $routerName = $this->router_name;
                            $q->where(function ($routeQuery) use ($routerName) {
                                $routeQuery->where('router_name', $routerName)->orWhereNull('router_name');
                            });
                        })
                        ->first();
                    $assignedPackageId = $pkg?->id;
                } else {
                    $pkg = PackageList::where('package', $this->package_name)
                        ->where('router_name', ! empty($this->router_name) ? $this->router_name : null);
                    $assignedPackageId = $pkg->first()?->id;
                }
            }
            $customer->package_id = $assignedPackageId;

            $customer->save();

            foreach ($this->address as $key => $value) {
                // for each address field, create a new record
                $customerAddress = new CustomersAddress;
                $customerAddress->customer_address_unique_id = $customer->customer_unique_id;
                $customerAddress->label_name = $key;

                // input type should be fetched from the address field table
                $inputType = AddressField::where('label', $key)->first();
                if ($inputType) {
                    $customerInputType = 'input_type_'.$inputType->input_type;
                    $customerAddress->$customerInputType = $value;
                }
                $customerAddress->save();
            }

            $latitude = is_numeric($this->latitude) ? (float) $this->latitude : null;
            $longitude = is_numeric($this->longitude) ? (float) $this->longitude : null;

            if ($latitude !== null || $longitude !== null) {
                CustomersAddress::updateOrCreate(
                    [
                        'customer_address_unique_id' => $customer->customer_unique_id,
                        'label_name' => 'Network Location',
                    ],
                    [
                        'latitude' => $latitude,
                        'longitude' => $longitude,
                    ]
                );
            }

            $customerBilling = new BillingInfo;
            $customerBilling->customer_bill_unique_id = $customer->customer_unique_id;
            $customerBilling->billing_type = $this->billing_type;
            $customerBilling->monthly_rent = $this->normalizeValue($this->monthly_rent);
            $customerBilling->due_amount = $this->normalizeValue($this->due_amount);
            $customerBilling->additional_charge = $this->normalizeValue($this->additional_charge);
            $customerBilling->discount = $this->normalizeValue($this->discount);
            $customerBilling->advance = $this->normalizeValue($this->advance);
            $customerBilling->vat = $this->normalizeValue($this->vat);
            $customerBilling->auto_disable = $this->auto_disable ?? 1;
            $customerBilling->auto_disable_date = $this->auto_disable_date ?? null;
            if ($customerBilling->billing_type === 'prepaid' && filled($customer->connection_date)) {
                $connectionDate = Carbon::parse($customer->connection_date)->startOfDay();
                $referenceDate = $connectionDate->gt(today()) ? $connectionDate : today();
                $customerBilling->auto_disable_date = PrepaidBillingCycle::nextExpiryDate($connectionDate, $referenceDate)->toDateString();
            }
            $customerBilling->auto_disable_month = $this->auto_disable_month;
            $customerBilling->total_amount = $this->total_amount;
            $customerBilling->due_amount = $this->total_amount;
            $customerBilling->save();

            // create Official information table record
            $customerOfficial = new OfficialInfo;
            $customerOfficial->customer_office_unique_id = $customer->customer_unique_id;
            $customerOfficial->billing_type = $this->billing_type;
            $customerOfficial->connection_type = $this->connection_type;
            $customerOfficial->connectivity_type = $this->connectivity_type;
            $customerOfficial->client_type = $this->client_type;
            $customerOfficial->distribution_location = $this->address['Distribution Location Point'] ?? null;
            $customerOfficial->description = $this->description;
            $customerOfficial->note = $this->note;
            $customerOfficial->security_deposit = $this->normalizeValue($this->security_deposit);
            $customerOfficial->connected_by = $this->connected_by;
            $customerOfficial->save();

            // Commit transaction if all goes well
            DB::commit();

            flash()->success('Customer created successfully!');

            // Clear form while preserving the locked portal context.
            $this->resetExcept('macResellerPortalMode');

            // Redirect to customers list
            if (($this->macResellerPortalMode || auth()->user()->hasRole('Reseller'))) {
                $routePrefix = $this->currentReseller()?->isMacPanel() ? 'mac-reseller' : 'reseller';
                return redirect()->route($routePrefix.'.customers.index');
            }

            return redirect('/customers');

        } catch (\Exception $e) {
            // Rollback transaction if any error occurs
            DB::rollBack();

            // Delete the image if it was saved
            // if (Storage::disk('public')->exists($path)) {
            //     Storage::disk('public')->delete($path);
            // }
            if (file_exists(public_path($path))) {
                unlink(public_path($path));
            }
            report($e);
            flash()->error('Operation failed. Please try again.');
        }
    }

    private function normalizeValue($value)
    {
        return $value === '' || $value === null ? 0 : $value;
    }

    public function render()
    {
        $this->addressFields = AddressField::orderBy('order', 'asc')->get();
        $this->routers = RouterList::select('router_name')->where('action', 'connected')->get();

        if (($this->macResellerPortalMode || auth()->user()->hasRole('Reseller'))) {
            $reseller = $this->currentReseller();
            $this->packages = PackageList::where(function ($q) use ($reseller) {
                $q->whereIn('id', $reseller->assignedPackages->pluck('id'))
                    ->orWhere('reseller_id', $reseller->id);
            })
                ->when(filled($this->router_name), function ($q) {
                    $routerName = $this->router_name;
                    $q->where(function ($routeQuery) use ($routerName) {
                        $routeQuery->where('router_name', $routerName)->orWhereNull('router_name');
                    });
                })
                ->select('price', 'package')
                ->get();
        } else {
            $this->packages = PackageList::select('price', 'package')
                ->when(! empty($this->router_name), fn ($q) => $q->where('router_name', $this->router_name))
                ->get();
        }

        // Keep internal user lists out of reseller Livewire state.
        $this->users = ($this->macResellerPortalMode || auth()->user()->hasRole('Reseller'))
            ? User::query()->select('id', 'name')->whereKey(auth()->id())->get()
            : User::select('id', 'name')->get();

        return view('livewire.new-customer')->layout('layouts.app');
    }
}
