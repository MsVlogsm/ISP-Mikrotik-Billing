<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class CollectionSummary extends Model
{
    use LogsActivity;

    protected static $recordEvents = ['deleted', 'updated'];

    /**
     * Cash/manual/import collection paths may not supply an invoice number.
     * The live collection_summaries.invoice_no column is NOT NULL and unique,
     * so fill it centrally before every insert rather than failing the payment.
     */
    protected static function booted(): void
    {
        static::creating(function (CollectionSummary $collection): void {
            $invoiceNo = $collection->getAttribute('invoice_no');

            if (! is_numeric($invoiceNo) || (int) $invoiceNo < 1) {
                $collection->invoice_no = static::nextInvoiceNumber();
            }
        });
    }

    /**
     * Return the next invoice number. A locking read is used within the
     * payment transaction so concurrent cash collections serialize against
     * the latest invoice row. The legacy invoice series starts at 100001.
     */
    public static function nextInvoiceNumber(): int
    {
        $query = static::query()->orderByDesc('invoice_no');

        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }

        $latest = $query->first(['invoice_no']);
        $lastNumber = $latest ? (int) $latest->invoice_no : 100000;

        return max(100001, $lastNumber + 1);
    }

    protected $fillable = [
        'customer_collection_unique_id',
        'collection_date',
        'collection_amount',
        'collected_by',
        'payment_type',
        'payment_method',
        'transaction_id',
        'details',
        'payment_status',
        'bill_month',
        'invoice_no',
    ];

    public function customer()
    {
        return $this->belongsTo(CustomersInfo::class, 'customer_collection_unique_id', 'customer_unique_id');
    }

    public function pppUser()
    {
        return $this->customer()->pppUser();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['customer_collection_unique_id', 'collection_date', 'collection_amount', 'collected_by', 'payment_type', 'payment_method', 'transaction_id', 'payment_status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * Override the log name dynamically.
     */
    public function getLogNameToUse(string $eventName = ''): string
    {
        return "{$this->customer_collection_unique_id} - {$this->collection_date}";
    }
}
