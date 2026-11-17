<?php

namespace Tests\Feature\Billing;

use App\Models\Business;
use App\Models\Client;
use App\Models\Document;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\User;
use App\Tax\TaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TaxReturnTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-17');
        $this->user = User::factory()->withBusiness()->create();
        $this->business = $this->user->currentBusiness;
        $this->business->forceFill(['plan_override' => 'pro'])->save();
        $this->actingAs($this->user);
    }

    public function test_tax_years_run_from_6_april_to_5_april()
    {
        $this->assertSame(2025, TaxYear::containing(CarbonImmutable::parse('2026-04-05'))->startYear);
        $this->assertSame(2026, TaxYear::containing(CarbonImmutable::parse('2026-04-06'))->startYear);
        $this->assertSame('2025-26', TaxYear::lastCompleted()->label());
        $this->assertSame('2027-01-31', (new TaxYear(2025))->filingDeadline()->toDateString());
    }

    public function test_cash_basis_counts_payments_received_and_expenses_paid_in_the_tax_year()
    {
        $invoice = $this->invoice('2025-05-01', subtotal: 100000, tax: 0);
        $this->payment($invoice, 60000, '2025-06-01');
        $this->payment($invoice, 40000, '2026-04-06'); // next tax year
        $this->payment($this->invoice('2025-03-01', 5000, 0), 5000, '2025-04-06'); // invoiced last year, paid this year

        $this->expense('materials', 12000, 2000, date: '2025-04-10', paidOn: '2025-04-10');
        $this->expense('travel', 3000, 0, date: '2025-07-10', paidOn: '2025-07-10');
        $this->expense('vehicle', 2000, 0, date: '2025-10-10', paidOn: '2025-10-10');
        $this->expense('software', 1000, 0, date: '2026-04-05', paidOn: '2026-04-05');
        $this->expense('office', 999, 0, date: '2026-03-01', paidOn: null); // unpaid, left out

        $this->get('/tax-return?year=2025&basis=cash')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('tax-return/index')
            ->where('included', true)
            ->where('report.tax_year.label', '2025-26')
            ->where('report.turnover', 65000)
            ->where('report.vat_registered', false)
            // Not VAT registered, so expenses include the VAT paid.
            ->where('report.total_expenses', 18000)
            ->where('report.net_profit', 47000)
            ->where('report.vat', null)
            ->where('report.expense_boxes.0.full_form_box', 17)
            ->where('report.expense_boxes.0.amount', 12000)
            ->where('report.expense_boxes.1.full_form_box', 20)
            ->where('report.expense_boxes.1.short_form_box', 12)
            ->where('report.expense_boxes.1.amount', 5000)
            ->has('report.expense_boxes.1.categories', 2)
            ->where('report.expense_boxes.2.full_form_box', 23)
            ->where('report.unpaid_expenses', ['count' => 1, 'total' => 999])
            ->where('report.quarters.0.turnover', 65000)
            ->where('report.quarters.0.expenses', 12000)
            ->where('report.quarters.1.expenses', 3000)
            ->where('report.quarters.3.expenses', 1000)
            ->has('years', 3)); // 2026-27 back to 2024-25, when the first invoice was issued
    }

    public function test_vat_registered_businesses_see_figures_without_vat_and_a_vat_summary()
    {
        $this->business->forceFill(['vat_number' => 'GB123456789'])->save();

        $invoice = $this->invoice('2025-05-01', subtotal: 100000, tax: 20000);
        $this->payment($invoice, 60000, '2025-06-01'); // part payment carries VAT in proportion
        $this->expense('professional', 12000, 2000, date: '2025-05-02', paidOn: '2025-05-02');

        $this->get('/tax-return?year=2025')->assertInertia(fn (Assert $page) => $page
            ->where('report.turnover', 50000)
            ->where('report.total_expenses', 10000)
            ->where('report.vat', ['output' => 10000, 'input' => 2000, 'net' => 8000]));
    }

    public function test_accruals_basis_counts_invoices_issued_and_expenses_billed()
    {
        $this->invoice('2025-05-01', subtotal: 100000, tax: 0);
        $this->invoice('2025-06-01', subtotal: 7000, tax: 0, status: 'draft');
        $this->invoice('2025-06-01', subtotal: 8000, tax: 0, status: 'void');
        $this->expense('office', 999, 0, date: '2026-03-01', paidOn: null);

        $this->get('/tax-return?year=2025&basis=accruals')->assertInertia(fn (Assert $page) => $page
            ->where('report.turnover', 100000)
            ->where('report.total_expenses', 999)
            ->where('report.unpaid_expenses', null));
    }

    public function test_figures_can_be_downloaded_as_csv_and_pdf()
    {
        $this->payment($this->invoice('2025-05-01', 100000, 0), 100000, '2025-06-01');
        $this->expense('materials', 12000, 0, date: '2025-04-10', paidOn: '2025-04-10');

        $csv = $this->get('/tax-return/csv?year=2025')->assertOk()->streamedContent();
        $this->assertStringContainsString('15,9,Turnover,1000.00', $csv);
        $this->assertStringContainsString('17,11,"Cost of goods bought for resale or goods used",120.00', $csv);
        $this->assertStringContainsString('47,,"Net profit",880.00', $csv);

        $this->get('/tax-return/pdf?year=2025')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_only_the_pro_plan_includes_tax_return_figures()
    {
        $this->business->forceFill(['plan_override' => 'starter'])->save();

        $this->get('/tax-return')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('included', false)
            ->where('requiredPlan', 'Pro')
            ->where('report', null));
        $this->get('/tax-return/csv')->assertForbidden();
        $this->get('/tax-return/pdf')->assertForbidden();
    }

    public function test_figures_only_include_the_current_business()
    {
        $other = User::factory()->withBusiness()->create();
        Expense::factory()->create(['business_id' => $other->current_business_id, 'date' => '2025-05-01', 'paid_on' => '2025-05-01', 'total' => 5000]);

        $this->get('/tax-return?year=2025')->assertInertia(fn (Assert $page) => $page->where('report.total_expenses', 0));
    }

    private function invoice(string $issued, int $subtotal, int $tax, string $status = 'sent'): Document
    {
        static $number = 0;

        return Document::forceCreate([
            'business_id' => $this->business->id,
            'client_id' => Client::factory()->create(['business_id' => $this->business->id])->id,
            'type' => 'invoice',
            'number' => 'INV-'.++$number,
            'status' => $status,
            'issue_date' => $issued,
            'subtotal' => $subtotal,
            'tax_total' => $tax,
            'total' => $subtotal + $tax,
        ]);
    }

    private function payment(Document $invoice, int $amount, string $paidOn): void
    {
        Payment::forceCreate(['business_id' => $this->business->id, 'document_id' => $invoice->id, 'amount' => $amount, 'paid_on' => $paidOn, 'method' => 'bank_transfer']);
    }

    private function expense(string $category, int $total, int $vat, string $date, ?string $paidOn): void
    {
        Expense::factory()->create([
            'business_id' => $this->business->id,
            'category' => $category,
            'total' => $total,
            'vat_amount' => $vat,
            'date' => $date,
            'paid_on' => $paidOn,
        ]);
    }
}
