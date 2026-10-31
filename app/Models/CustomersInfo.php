<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CustomersInfo extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'customer_unique_id',
        'customer_name',
        'contact_person',
        'parents_name',
        'spouse_name',
        'address',
        'email',
        'mobile',
        'alternative_mobile',
        'identification_no',
        'profession',
        'photo_url',
        'disable_count',
        'ppp_user_id',
        'connection_date',
        'package_id',
        'status',
        'reseller_id',
        'left_date',
        'left_reason',
        'zone_name',
        'left_equipment',
        'recovery_status',
        'server_label',
        // 'auto_disabled',
        // 'expired_date',
        // 'auto_disabled_month',
        // 'extra_date',
    ];

    protected $casts = [
        'left_date' => 'datetime',
    ];

    /**
     * Keep Bangladesh customer mobile numbers in local format (01XXXXXXXXX).
     * Entry screens should never require a country code, and this mutator also
     * protects database writes coming from imports, controllers, and other forms.
     */
    public static function normalizeBangladeshMobile($value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $value);

        if (str_starts_with($digits, '00880')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '8801') && strlen($digits) >= 13) {
            return '0'.substr($digits, 3);
        }

        if (str_starts_with($digits, '01') && strlen($digits) === 11) {
            return $digits;
        }

        return $digits !== '' ? $digits : null;
    }

    public function setMobileAttribute($value): void
    {
        $this->attributes['mobile'] = self::normalizeBangladeshMobile($value);
    }

    public function setAlternativeMobileAttribute($value): void
    {
        $this->attributes['alternative_mobile'] = self::normalizeBangladeshMobile($value);
    }

    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($customer) {
            if ($customer->pppUser()->exists()) {
                throw new \Exception('Cannot delete customer because they are associated with an active PPPoE user. Please remove the PPPoE user first.');
            }

            if (! $customer->isForceDeleting() && ! $customer->trashed()) {
                $customer->archiveCidForReuse();
            }
        });
    }

    /**
     * Release the human-readable CID without detaching the deleted customer's
     * billing and service history. Related tables are moved to an internal key
     * that stays linked to this specific customer record.
     */
    private function archiveCidForReuse(): void
    {
        DB::transaction(function (): void {
            if (! Schema::hasColumn('customers_infos', 'deleted_original_customer_unique_id')) {
                throw new \RuntimeException(
                    'Customer CID archive migration is required before this customer can be deleted safely.'
                );
            }

            $originalCid = trim((string) $this->getAttribute('customer_unique_id'));
            if ($originalCid === '' || str_starts_with($originalCid, '__ARCHIVED_CUSTOMER_')) {
                return;
            }

            $archiveKey = '__ARCHIVED_CUSTOMER_'.$this->getKey();
            $keyInUse = static::withTrashed()
                ->where('customer_unique_id', $archiveKey)
                ->where('id', '<>', $this->getKey())
                ->exists();

            if ($keyInUse) {
                throw new \RuntimeException('Unable to release Customer CID because the internal archive key is already in use.');
            }

            $this->setAttribute('deleted_original_customer_unique_id', $originalCid);
            $this->setAttribute('customer_unique_id', $archiveKey);
            $this->saveQuietly();

            self::archiveCidReferences($originalCid, $archiveKey);
        });
    }

    private static function archiveCidReferences(string $originalCid, string $archiveKey): void
    {
        $references = [
            'billing_infos' => 'customer_bill_unique_id',
            'collection_summaries' => 'customer_collection_unique_id',
            'customer_connection_snapshots' => 'customer_unique_id',
            'customer_wifi_router_logins' => 'customer_unique_id',
            'customers_addresses' => 'customer_address_unique_id',
            'kyc_requests' => 'customer_unique_id',
            'mikrotik_pending_actions' => 'customer_unique_id',
            'network_alarm_events' => 'customer_unique_id',
            'official_infos' => 'customer_office_unique_id',
            'onu_mac_ledgers' => 'customer_unique_id',
            'payment_summaries' => 'customer_payment_unique_id',
            'support_tickets' => 'customer_unique_id',
        ];

        foreach ($references as $table => $column) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                DB::table($table)->where($column, $originalCid)->update([$column => $archiveKey]);
            }
        }

        if (Schema::hasTable('activity_log') && Schema::hasColumn('activity_log', 'properties')) {
            $activities = DB::table('activity_log')
                ->where('properties->customer_unique_id', $originalCid)
                ->get(['id', 'properties']);

            foreach ($activities as $activity) {
                $properties = is_array($activity->properties)
                    ? $activity->properties
                    : json_decode((string) $activity->properties, true);

                if (! is_array($properties)) {
                    continue;
                }

                $properties['customer_unique_id'] = $archiveKey;
                DB::table('activity_log')->where('id', $activity->id)->update([
                    'properties' => json_encode($properties, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            }
        }
    }

    public function displayCustomerUniqueId(): string
    {
        if ($this->trashed() && filled($this->deleted_original_customer_unique_id)) {
            return (string) $this->deleted_original_customer_unique_id;
        }

        return (string) $this->customer_unique_id;
    }

    public function setConnectionDateAttribute($value): void
    {
        $this->attributes['connection_date'] = filled($value) ? $value : null;
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeUnderDisableLimit($query, $limit)
    {
        return $query->where('disable_count', '<', $limit);
    }

    public function scopeHasPPPUser($query)
    {
        return $query->whereNotNull('ppp_user_id');
    }

    public function pppUser()
    {
        return $this->belongsTo(PPPSecrets::class, 'ppp_user_id', 'id');
    }

    public function package()
    {
        return $this->belongsTo(PackageList::class, 'package_id', 'id');
    }

    public function customerAddress()
    {
        return $this->hasMany(CustomersAddress::class, 'customer_address_unique_id', 'customer_unique_id');
    }

    public function paymentSummary()
    {
        return $this->hasMany(PaymentSummary::class, 'customer_payment_unique_id', 'customer_unique_id');
    }

    public function collectionSummary()
    {
        return $this->hasMany(CollectionSummary::class, 'customer_collection_unique_id', 'customer_unique_id');
    }

    public function billing()
    {
        return $this->belongsTo(BillingInfo::class, 'customer_unique_id', 'customer_bill_unique_id');
    }

    public function official()
    {
        return $this->belongsTo(OfficialInfo::class, 'customer_unique_id', 'customer_office_unique_id');
    }

    public function scopeSearch($query, $search)
    {
        $term = '%'.$search.'%';

        return $query->where(function ($query) use ($term) {
            $query->where('customer_unique_id', 'like', $term)
                ->orWhere('customer_name', 'like', $term)
                ->orWhere('contact_person', 'like', $term)
                ->orWhere('parents_name', 'like', $term)
                ->orWhere('spouse_name', 'like', $term)
                ->orWhere('address', 'like', $term)
                ->orWhere('email', 'like', $term)
                ->orWhere('mobile', 'like', $term)
                ->orWhere('alternative_mobile', 'like', $term)
                ->orWhere('identification_no', 'like', $term)
                ->orWhere('profession', 'like', $term)
                ->orWhereHas('pppUser', function ($q) use ($term) {
                    $q->where('username', 'like', $term);
                });
        });
    }

    public function kycRequests()
    {
        return $this->hasMany(KycRequest::class, 'customer_unique_id', 'customer_unique_id');
    }

    public function reseller()
    {
        return $this->belongsTo(Reseller::class, 'reseller_id');
    }

    protected static function booted(): void
    {
        static::created(function (self $customer): void {
            app(\App\Services\IntegrationWebhookDispatcher::class)->dispatch('customer.created', [
                'customer_id' => $customer->customer_unique_id,
                'name' => $customer->customer_name,
                'status' => $customer->status,
                'created_at' => $customer->created_at?->toIso8601String(),
            ]);
        });

        static::updated(function (self $customer): void {
            if (! $customer->wasChanged(['customer_name', 'mobile', 'status', 'package_id', 'connection_date', 'address'])) return;

            $previous = strtolower((string) $customer->getOriginal('status'));
            $current = strtolower((string) $customer->status);
            $event = in_array($current, ['disable', 'disabled', 'inactive'], true)
                && in_array($previous, ['active', 'free'], true)
                ? 'customer.suspended'
                : 'customer.updated';

            app(\App\Services\IntegrationWebhookDispatcher::class)->dispatch($event, [
                'customer_id' => $customer->customer_unique_id,
                'name' => $customer->customer_name,
                'status' => $customer->status,
                'updated_at' => $customer->updated_at?->toIso8601String(),
            ]);
        });

        static::deleted(function (self $customer): void {
            app(\App\Services\IntegrationWebhookDispatcher::class)->dispatch('customer.deleted', [
                'customer_id' => $customer->customer_unique_id,
                'deleted_at' => now()->toIso8601String(),
            ]);
        });
    }
}
