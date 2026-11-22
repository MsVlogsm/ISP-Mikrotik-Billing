<?php

namespace Tests\Feature;

use App\Models\CollectionSummary;
use App\Models\PaymentSummary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FinanceAccountingReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::findOrCreate('Super Admin', 'web');
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole($role);

        $this->actingAs($user);
        $this->withoutVite();
    }

    public function test_posted_accounting_scope_includes_paid_returns_and_excludes_non_paid_statuses(): void
    {
        $this->recordCollection('PAID-CASH', '2026-11-22 10:00:00', 100, 'paid', 'cash', 990001);
        $this->recordCollection('PAID-RETURN', '2026-11-22 10:05:00', -25, 'paid', 'return', 990002);
        $this->recordCollection('REVERSED', '2026-11-22 10:10:00', 50, 'reversed', 'cash', 990003);
        $this->recordCollection('RECONCILED', '2026-11-22 10:15:00', -50, 'reconciled', 'duplicate_reversal', 990004);
        $this->recordCollection('PENDING', '2026-11-22 10:20:00', 75, 'pending', 'bkash', 990005);
        $this->recordCollection('UNSPECIFIED', '2026-11-22 10:25:00', 80, null, 'cash', 990006);

        $posted = CollectionSummary::postedForAccounting();
        $adjustments = CollectionSummary::nonPostedForAccounting();

        $this->assertSame(2, $posted->count());
        $this->assertEquals(75.00, (float) $posted->sum('collection_amount'));
        $this->assertSame(4, $adjustments->count());
        $this->assertEquals(155.00, (float) $adjustments->sum('collection_amount'));
    }

    public function test_payment_summary_due_uses_paid_collections_only(): void
    {
        $summary = PaymentSummary::create([
            'customer_payment_unique_id' => 'PAYMENT-SUMMARY-1',
            'summary_date' => '2026-11-22',
            'monthly_rent' => 500,
        ]);

        $this->recordCollection('PAYMENT-SUMMARY-1', '2026-11-22 10:00:00', 100, 'paid', 'cash', 990007);
        $this->recordCollection('PAYMENT-SUMMARY-1', '2026-11-22 10:05:00', 50, 'reversed', 'cash', 990008);
        $this->recordCollection('PAYMENT-SUMMARY-1', '2026-11-22 10:10:00', 75, 'pending', 'bkash', 990009);
        $this->recordCollection('PAYMENT-SUMMARY-1', '2026-11-22 10:15:00', -25, 'reconciled', 'duplicate_reversal', 990010);

        $this->assertEquals(100.00, $summary->paid_amount);
        $this->assertEquals(400.00, $summary->due_amount);
    }

    public function test_income_report_includes_full_to_date_and_separates_adjustments(): void
    {
        $this->recordCollection('END-OF-DAY', '2026-11-30 23:59:59', 100, 'paid', 'cash', 990011, 'END-DAY-RECEIPT');
        $this->recordCollection('NEXT-DAY', '2026-12-01 00:00:00', 900, 'paid', 'cash', 990012, 'NEXT-DAY-RECEIPT');
        $this->recordCollection('REVERSED-ROW', '2026-11-30 23:59:58', 40, 'reversed', 'cash', 990013, 'REVERSED-RECEIPT');
        $this->recordCollection('RECONCILED-ROW', '2026-11-30 23:59:57', -40, 'reconciled', 'duplicate_reversal', 990014, 'RECONCILED-RECEIPT');

        $response = $this->get('/income-summary?from=2026-11-30&to=2026-11-30');

        $response->assertOk()
            ->assertSee('Posted Income')
            ->assertSee('END-DAY-RECEIPT')
            ->assertDontSee('NEXT-DAY-RECEIPT')
            ->assertSee('Non-posted adjustments')
            ->assertSee('REVERSED-RECEIPT')
            ->assertSee('RECONCILED-RECEIPT')
            ->assertSee('৳100.00');
    }

    public function test_ledger_total_is_not_limited_to_the_hundred_visible_rows(): void
    {
        for ($i = 1; $i <= 105; $i++) {
            $this->recordCollection(
                'LEDGER-'.$i,
                '2026-11-22 12:00:00',
                10,
                'paid',
                'cash',
                991000 + $i,
                'LEDGER-RECEIPT-'.$i
            );
        }

        $response = $this->get('/ledger-summary?from=2026-11-22&to=2026-11-22');

        $response->assertOk()
            ->assertSee('Posted Credit (paid only)')
            ->assertSee('৳1,050.00')
            ->assertSee('Up to 100 entries shown');
    }

    private function recordCollection(
        string $customerId,
        string $date,
        float $amount,
        ?string $status,
        ?string $type,
        int $invoiceNo,
        ?string $transactionId = null
    ): CollectionSummary {
        return CollectionSummary::create([
            'customer_collection_unique_id' => $customerId,
            'collection_date' => $date,
            'collection_amount' => $amount,
            'collected_by' => 'Finance accounting test',
            'payment_type' => $type,
            'payment_method' => 'cash',
            'transaction_id' => $transactionId,
            'payment_status' => $status,
            'invoice_no' => $invoiceNo,
        ]);
    }
}
