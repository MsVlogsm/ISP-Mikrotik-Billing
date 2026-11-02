<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class CompanyAreaZoneController extends Controller
{
    private const TABS = [
        'zones' => 'Zones',
        'sub_zones' => 'Sub-Zones',
        'distribution_boxes' => 'Distribution Boxes (TJ)',
        'districts' => 'Districts',
        'upazilas' => 'Upazilas',
    ];

    public function index(Request $request)
    {
        $tab = array_key_exists($request->string('tab')->toString(), self::TABS)
            ? $request->string('tab')->toString()
            : 'zones';
        $search = trim((string) $request->query('q', ''));
        $status = in_array($request->query('status'), ['active', 'inactive'], true)
            ? $request->query('status')
            : 'all';

        $zoneTotal = DB::table('service_zones')->count();
        $zoneActive = DB::table('service_zones')->where('is_active', true)->count();
        $subZoneTotal = DB::table('service_sub_zones')->count();
        $subZoneActive = DB::table('service_sub_zones')->where('is_active', true)->count();
        $tjBoxTotal = DB::table('distribution_boxes')->count();
        $tjBoxActive = DB::table('distribution_boxes')->where('is_active', true)->count();
        $splitterTotal = Schema::hasTable('fiber_splitters') ? DB::table('fiber_splitters')->count() : 0;
        $boxTotal = $tjBoxTotal + $splitterTotal;
        $portCapacity = (int) DB::table('distribution_boxes')->sum('port_capacity');
        $portsUsed = (int) DB::table('distribution_boxes')->sum('ports_used');
        $capacityUsedPercent = $portCapacity > 0 ? round(($portsUsed / $portCapacity) * 100, 1) : 0.0;

        $counts = [
            'zones' => $zoneTotal,
            'sub_zones' => $subZoneTotal,
            'distribution_boxes' => $tjBoxTotal,
            'districts' => DB::table('service_districts')->count(),
            'upazilas' => DB::table('service_upazilas')->count(),
        ];

        [$query, $searchColumns, $orderColumn, $statusColumn] = $this->buildListQuery($tab);
        if ($search !== '') {
            $query->where(function ($builder) use ($searchColumns, $search) {
                foreach ($searchColumns as $index => $column) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $builder->{$method}($column, 'like', '%' . $search . '%');
                }
            });
        }
        if ($status === 'active') {
            $query->where($statusColumn, true);
        } elseif ($status === 'inactive') {
            $query->where($statusColumn, false);
        }

        $options = $query->orderBy($orderColumn)->paginate(10)->withQueryString();
        // Include inactive parent records in edit forms so existing links can still be retained or reassigned.
        $zones = DB::table('service_zones')->orderBy('name')->get(['id', 'name', 'is_active']);
        $subZones = DB::table('service_sub_zones')->orderBy('name')->get(['id', 'name', 'service_zone_id', 'is_active']);
        $districts = DB::table('service_districts')->orderBy('name')->get(['id', 'name', 'is_active']);

        return view('xlink.company-area-zone-setup', [
            'tab' => $tab,
            'tabs' => self::TABS,
            'counts' => $counts,
            'options' => $options,
            'search' => $search,
            'status' => $status,
            'zones' => $zones,
            'subZones' => $subZones,
            'districts' => $districts,
            'stats' => [
                'zone_total' => $zoneTotal,
                'zone_active' => $zoneActive,
                'sub_zone_total' => $subZoneTotal,
                'sub_zone_active' => $subZoneActive,
                'box_total' => $boxTotal,
                'splitter_total' => $splitterTotal,
                'tj_box_total' => $tjBoxTotal,
                'tj_box_active' => $tjBoxActive,
                'capacity_total' => $portCapacity,
                'ports_used' => $portsUsed,
                'capacity_percent' => $capacityUsedPercent,
            ],
        ]);
    }

    private function buildListQuery(string $tab): array
    {
        return match ($tab) {
            'sub_zones' => [
                DB::table('service_sub_zones as sz')
                    ->join('service_zones as z', 'z.id', '=', 'sz.service_zone_id')
                    ->leftJoinSub(
                        DB::table('distribution_boxes')->select('service_sub_zone_id')->selectRaw('count(*) as boxes_count')->groupBy('service_sub_zone_id'),
                        'bc',
                        'bc.service_sub_zone_id',
                        '=',
                        'sz.id'
                    )
                    ->select('sz.*', 'z.name as zone_name')
                    ->selectRaw('coalesce(bc.boxes_count, 0) as boxes_count'),
                ['sz.name', 'sz.code', 'z.name'],
                'sz.name',
                'sz.is_active',
            ],
            'distribution_boxes' => [
                DB::table('distribution_boxes as b')
                    ->join('service_zones as z', 'z.id', '=', 'b.service_zone_id')
                    ->leftJoin('service_sub_zones as sz', 'sz.id', '=', 'b.service_sub_zone_id')
                    ->select('b.*', 'z.name as zone_name', 'sz.name as sub_zone_name'),
                ['b.name', 'b.code', 'z.name', 'sz.name'],
                'b.name',
                'b.is_active',
            ],
            'districts' => [
                DB::table('service_districts as d')
                    ->leftJoinSub(
                        DB::table('service_upazilas')->select('service_district_id')->selectRaw('count(*) as upazilas_count')->groupBy('service_district_id'),
                        'uc',
                        'uc.service_district_id',
                        '=',
                        'd.id'
                    )
                    ->select('d.*')
                    ->selectRaw('coalesce(uc.upazilas_count, 0) as upazilas_count'),
                ['d.name', 'd.code'],
                'd.name',
                'd.is_active',
            ],
            'upazilas' => [
                DB::table('service_upazilas as u')
                    ->leftJoin('service_districts as d', 'd.id', '=', 'u.service_district_id')
                    ->select('u.*', 'd.name as district_name'),
                ['u.name', 'u.code', 'd.name'],
                'u.name',
                'u.is_active',
            ],
            default => [
                DB::table('service_zones as z')
                    ->leftJoinSub(
                        DB::table('service_sub_zones')->select('service_zone_id')->selectRaw('count(*) as sub_zones_count')->groupBy('service_zone_id'),
                        'sc',
                        'sc.service_zone_id',
                        '=',
                        'z.id'
                    )
                    ->leftJoinSub(
                        DB::table('distribution_boxes')->select('service_zone_id')->selectRaw('count(*) as boxes_count')->groupBy('service_zone_id'),
                        'bc',
                        'bc.service_zone_id',
                        '=',
                        'z.id'
                    )
                    ->select('z.*')
                    ->selectRaw('coalesce(sc.sub_zones_count, 0) as sub_zones_count')
                    ->selectRaw('coalesce(bc.boxes_count, 0) as boxes_count'),
                ['z.name', 'z.code', 'z.manager_name', 'z.manager_phone'],
                'z.name',
                'z.is_active',
            ],
        };
    }

    public function store(Request $request)
    {
        $tab = $request->validate(['tab' => ['required', Rule::in(array_keys(self::TABS))]])['tab'];
        $now = now();

        if ($tab === 'zones') {
            $data = $request->validate([
                'name' => 'required|string|max:150|unique:service_zones,name',
                'code' => 'nullable|string|max:60|regex:/^[A-Za-z0-9_-]+$/|unique:service_zones,code',
                'manager_name' => 'nullable|string|max:150',
                'manager_phone' => 'nullable|string|max:40',
            ]);
            DB::table('service_zones')->insert($data + ['is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            $this->syncAddressOption('Zone', $data['name']);
        } elseif ($tab === 'sub_zones') {
            $data = $request->validate([
                'service_zone_id' => 'required|exists:service_zones,id',
                'name' => ['required', 'string', 'max:150', Rule::unique('service_sub_zones', 'name')->where(fn ($q) => $q->where('service_zone_id', $request->input('service_zone_id')))],
                'code' => 'nullable|string|max:60|regex:/^[A-Za-z0-9_-]+$/',
            ]);
            DB::table('service_sub_zones')->insert($data + ['is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        } elseif ($tab === 'distribution_boxes') {
            $data = $this->validateBox($request);
            DB::table('distribution_boxes')->insert($data + ['box_type' => 'TJ', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        } elseif ($tab === 'districts') {
            $data = $request->validate([
                'name' => 'required|string|max:150|unique:service_districts,name',
                'code' => 'nullable|string|max:60|regex:/^[A-Za-z0-9_-]+$/|unique:service_districts,code',
            ]);
            DB::table('service_districts')->insert($data + ['is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            $this->syncAddressOption('District', $data['name']);
        } else {
            $data = $request->validate([
                'service_district_id' => 'nullable|exists:service_districts,id',
                'name' => [
                    'required',
                    'string',
                    'max:150',
                    Rule::unique('service_upazilas', 'name')->where(fn ($q) => $request->filled('service_district_id')
                        ? $q->where('service_district_id', $request->input('service_district_id'))
                        : $q->whereNull('service_district_id')),
                ],
                'code' => 'nullable|string|max:60|regex:/^[A-Za-z0-9_-]+$/',
            ]);
            DB::table('service_upazilas')->insert([
                'service_district_id' => $data['service_district_id'] ?? null,
                'name' => $data['name'],
                'code' => $data['code'] ?? null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->syncAddressOption('Thana / Upazila', $data['name']);
        }

        return redirect()->route('company-settings.area-zone-setup', ['tab' => $tab])->with('company_settings_message', self::TABS[$tab] . ' record added.');
    }

    public function update(Request $request, string $tab, int $id)
    {
        abort_unless(array_key_exists($tab, self::TABS), 404);
        $now = now();

        if ($tab === 'zones') {
            $record = DB::table('service_zones')->where('id', $id)->firstOrFail();
            $data = $request->validate([
                'name' => ['required', 'string', 'max:150', Rule::unique('service_zones', 'name')->ignore($id)],
                'code' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('service_zones', 'code')->ignore($id)],
                'manager_name' => 'nullable|string|max:150',
                'manager_phone' => 'nullable|string|max:40',
            ]);
            DB::table('service_zones')->where('id', $id)->update($data + ['updated_at' => $now]);
            $this->syncAddressOption('Zone', $data['name'], $record->name);
        } elseif ($tab === 'sub_zones') {
            DB::table('service_sub_zones')->where('id', $id)->firstOrFail();
            $data = $request->validate([
                'service_zone_id' => 'required|exists:service_zones,id',
                'name' => ['required', 'string', 'max:150', Rule::unique('service_sub_zones', 'name')->where(fn ($q) => $q->where('service_zone_id', $request->input('service_zone_id')))->ignore($id)],
                'code' => 'nullable|string|max:60|regex:/^[A-Za-z0-9_-]+$/',
            ]);
            DB::table('service_sub_zones')->where('id', $id)->update($data + ['updated_at' => $now]);
        } elseif ($tab === 'distribution_boxes') {
            DB::table('distribution_boxes')->where('id', $id)->firstOrFail();
            $data = $this->validateBox($request, $id);
            DB::table('distribution_boxes')->where('id', $id)->update($data + ['updated_at' => $now]);
        } elseif ($tab === 'districts') {
            $record = DB::table('service_districts')->where('id', $id)->firstOrFail();
            $data = $request->validate([
                'name' => ['required', 'string', 'max:150', Rule::unique('service_districts', 'name')->ignore($id)],
                'code' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('service_districts', 'code')->ignore($id)],
            ]);
            DB::table('service_districts')->where('id', $id)->update($data + ['updated_at' => $now]);
            $this->syncAddressOption('District', $data['name'], $record->name);
        } else {
            $record = DB::table('service_upazilas')->where('id', $id)->firstOrFail();
            $data = $request->validate([
                'service_district_id' => 'nullable|exists:service_districts,id',
                'name' => [
                    'required',
                    'string',
                    'max:150',
                    Rule::unique('service_upazilas', 'name')->where(fn ($q) => $request->filled('service_district_id')
                        ? $q->where('service_district_id', $request->input('service_district_id'))
                        : $q->whereNull('service_district_id'))->ignore($id),
                ],
                'code' => 'nullable|string|max:60|regex:/^[A-Za-z0-9_-]+$/',
            ]);
            DB::table('service_upazilas')->where('id', $id)->update([
                'service_district_id' => $data['service_district_id'] ?? null,
                'name' => $data['name'],
                'code' => $data['code'] ?? null,
                'updated_at' => $now,
            ]);
            $this->syncAddressOption('Thana / Upazila', $data['name'], $record->name);
        }

        return redirect()->route('company-settings.area-zone-setup', ['tab' => $tab])->with('company_settings_message', self::TABS[$tab] . ' record updated.');
    }

    private function validateBox(Request $request, ?int $ignoreId = null): array
    {
        $codeRule = Rule::unique('distribution_boxes', 'code');
        if ($ignoreId) {
            $codeRule->ignore($ignoreId);
        }

        return $request->validate([
            'service_zone_id' => 'required|exists:service_zones,id',
            'service_sub_zone_id' => [
                'nullable',
                Rule::exists('service_sub_zones', 'id')->where(fn ($q) => $q->where('service_zone_id', $request->input('service_zone_id'))),
            ],
            'name' => 'required|string|max:150',
            'code' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9_-]+$/', $codeRule],
            'port_capacity' => 'required|integer|min:1|max:1024',
            'ports_used' => 'required|integer|min:0|lte:port_capacity',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
        ]);
    }

    public function toggle(string $tab, int $id)
    {
        abort_unless(array_key_exists($tab, self::TABS), 404);
        $table = $this->tableFor($tab);
        $record = DB::table($table)->where('id', $id)->firstOrFail();
        DB::table($table)->where('id', $id)->update(['is_active' => ! (bool) $record->is_active, 'updated_at' => now()]);

        return redirect()->route('company-settings.area-zone-setup', ['tab' => $tab])->with('company_settings_message', self::TABS[$tab] . ' status updated.');
    }

    public function destroy(string $tab, int $id)
    {
        abort_unless(array_key_exists($tab, self::TABS), 404);
        $table = $this->tableFor($tab);
        $record = DB::table($table)->where('id', $id)->firstOrFail();

        if ($tab === 'zones') {
            $hasSubZones = DB::table('service_sub_zones')->where('service_zone_id', $id)->exists();
            $hasBoxes = DB::table('distribution_boxes')->where('service_zone_id', $id)->exists();
            $hasCustomers = DB::table('customers_infos')->where('zone_name', $record->name)->exists()
                || $this->addressOptionIsUsed('Zone', $record->name);
            abort_if($hasSubZones || $hasBoxes || $hasCustomers, 422, 'This zone is in use. Remove or reassign its sub-zones, boxes, and customer address records first.');
            $this->syncAddressOption('Zone', null, $record->name, true);
        } elseif ($tab === 'sub_zones') {
            abort_if(DB::table('distribution_boxes')->where('service_sub_zone_id', $id)->exists(), 422, 'This sub-zone contains distribution boxes. Reassign or remove them first.');
        } elseif ($tab === 'districts') {
            $hasUpazilas = DB::table('service_upazilas')->where('service_district_id', $id)->exists();
            $hasCustomerAddresses = $this->addressOptionIsUsed('District', $record->name);
            abort_if($hasUpazilas || $hasCustomerAddresses, 422, 'This district is in use by upazilas or customer address records.');
            $this->syncAddressOption('District', null, $record->name, true);
        } elseif ($tab === 'upazilas') {
            abort_if($this->addressOptionIsUsed('Thana / Upazila', $record->name), 422, 'This upazila is used in customer address records.');
            $this->syncAddressOption('Thana / Upazila', null, $record->name, true);
        }

        DB::table($table)->where('id', $id)->delete();

        return redirect()->route('company-settings.area-zone-setup', ['tab' => $tab])->with('company_settings_message', self::TABS[$tab] . ' record deleted.');
    }

    private function tableFor(string $tab): string
    {
        return match ($tab) {
            'zones' => 'service_zones',
            'sub_zones' => 'service_sub_zones',
            'distribution_boxes' => 'distribution_boxes',
            'districts' => 'service_districts',
            'upazilas' => 'service_upazilas',
        };
    }

    private function addressOptionIsUsed(string $label, string $value): bool
    {
        $labels = $label === 'Thana / Upazila'
            ? ['Thana / Upazila', 'Other Thana / Upazila']
            : [$label];

        return DB::table('customers_addresses')
            ->whereIn('label_name', $labels)
            ->where('input_type_dropdown', $value)
            ->exists();
    }

    private function syncAddressOption(string $label, ?string $newName, ?string $oldName = null, bool $remove = false): void
    {
        $fieldLabels = $label === 'Thana / Upazila'
            ? ['Thana / Upazila', 'Other Thana / Upazila']
            : [$label];

        foreach ($fieldLabels as $fieldLabel) {
            $field = DB::table('address_fields')->where('label', $fieldLabel)->first();
            if (! $field) {
                continue;
            }

            $values = json_decode($field->dropdown_list ?: '[]', true);
            if (! is_array($values)) {
                $values = [];
            }

            if ($oldName !== null && ($remove || ! $this->addressOptionIsUsed($label, $oldName))) {
                $values = array_values(array_filter($values, fn ($value) => (string) $value !== $oldName));
            }
            if (! $remove && $newName !== null && ! in_array($newName, $values, true)) {
                $values[] = $newName;
            }

            DB::table('address_fields')->where('id', $field->id)->update([
                'input_type' => 'dropdown',
                'dropdown_list' => json_encode(array_values($values), JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        }
    }
}
