<?php

namespace Tests\Feature\Billing;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Mail\DocumentMail;
use App\Mail\InvoiceReminderMail;
use App\Models\Client;
use App\Models\Document;
use App\Models\RecurringInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class GettingPaidTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->withBusiness()->create();
        $this->actingAs($this->user);
    }

    /**
     * A sent invoice for £120 (£100 + 20% VAT).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function sentInvoice(array $attributes = []): Document
    {
        $client = Client::factory()->create();

        $this->post('/invoices', [
            'client_id' => $client->id,
            'issue_date' => today()->toDateString(),
            'due_date' => today()->addDays(30)->toDateString(),
            'items' => [['description' => 'Work', 'quantity' => '1', 'unit_price' => '100', 'tax_rate' => '20']],
        ])->assertSessionHasNoErrors();

        $invoice = Document::latest('id')->first();
        $invoice->forceFill(['status' => DocumentStatus::Sent, ...$attributes])->save();

        return $invoice;
    }

    private function pay(Document $invoice, string $amount)
    {
        return $this->post("/invoices/{$invoice->id}/payments", [
            'amount' => $amount,
            'paid_on' => today()->toDateString(),
            'method' => 'bank_transfer',
            'reference' => 'BACS',
        ]);
    }

    public function test_part_payments_reduce_the_balance_and_full_payment_marks_paid()
    {
        $invoice = $this->sentInvoice();

        $this->pay($invoice, '50')->assertSessionHasNoErrors();
        $invoice->refresh();
        $this->assertSame(5000, $invoice->amount_paid);
        $this->assertSame(7000, $invoice->balanceDue());
        $this->assertSame(DocumentStatus::Sent, $invoice->status);
        $this->assertFalse($invoice->isEditable());

        $this->pay($invoice, '70')->assertSessionHasNoErrors();
        $invoice->refresh();
        $this->assertSame(DocumentStatus::Paid, $invoice->status);
        $this->assertNotNull($invoice->paid_at);
    }

    public function test_overpayments_are_rejected()
    {
        $invoice = $this->sentInvoice();

        $this->pay($invoice, '120.01')->assertSessionHasErrors('amount');
    }

    public function test_removing_a_payment_reopens_the_invoice()
    {
        $invoice = $this->sentInvoice();
        $this->pay($invoice, '120');
        $payment = $invoice->payments()->first();

        $this->delete("/invoices/{$invoice->id}/payments/{$payment->id}")->assertRedirect();

        $invoice->refresh();
        $this->assertSame(0, $invoice->amount_paid);
        $this->assertSame(DocumentStatus::Sent, $invoice->status);
        $this->assertNull($invoice->paid_at);
    }

    public function test_paid_cannot_be_chosen_by_hand()
    {
        $invoice = $this->sentInvoice();

        $this->patch("/invoices/{$invoice->id}/status", ['status' => 'paid'])->assertSessionHasErrors('status');
    }

    public function test_dashboard_shows_outstanding_and_overdue_totals()
    {
        $this->sentInvoice();
        $overdue = $this->sentInvoice(['due_date' => today()->subDays(3)]);
        $this->pay($overdue, '20');

        $this->get('/dashboard')->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->where('totals.outstanding', 12000 + 10000)
            ->where('totals.overdue', 10000)
            ->where('totals.paid_this_month', 2000)
            ->has('overdueInvoices', 1));
    }

    public function test_reminders_are_sent_for_overdue_invoices_then_weekly_up_to_three()
    {
        Mail::fake();
        $overdue = $this->sentInvoice(['due_date' => today()->subDay()]);
        $this->sentInvoice();
        Auth::logout();

        $this->artisan('invoices:send-reminders')->assertSuccessful();
        Mail::assertSent(InvoiceReminderMail::class, 1);
        $this->assertSame(1, $overdue->fresh()->reminders_sent);

        // Running again the same day sends nothing.
        $this->artisan('invoices:send-reminders');
        Mail::assertSent(InvoiceReminderMail::class, 1);

        foreach ([2, 3, 3] as $expected) {
            $this->travel(7)->days();
            $this->artisan('invoices:send-reminders');
            $this->assertSame($expected, $overdue->fresh()->reminders_sent);
        }

        Mail::assertSent(InvoiceReminderMail::class, 3);
    }

    public function test_reminders_can_be_turned_off()
    {
        Mail::fake();
        $this->sentInvoice(['due_date' => today()->subDay()]);
        $this->user->currentBusiness->update(['send_reminders' => false]);
        Auth::logout();

        $this->artisan('invoices:send-reminders');

        Mail::assertNothingSent();
    }

    public function test_invoices_can_be_repeated_on_a_schedule()
    {
        Mail::fake();
        $template = $this->sentInvoice();

        $this->post("/invoices/{$template->id}/recurring", [
            'frequency' => 'monthly',
            'next_issue_date' => today()->toDateString(),
            'auto_send' => true,
        ])->assertRedirect('/recurring-invoices');

        $this->get('/recurring-invoices')->assertOk();
        Auth::logout();

        $this->artisan('invoices:issue-recurring')->assertSuccessful();

        $issued = Document::withoutGlobalScopes()->where('recurring_invoice_id', '!=', null)->first();
        $this->assertSame('INV-0002', $issued->number);
        $this->assertSame(DocumentType::Invoice, $issued->type);
        $this->assertSame(DocumentStatus::Sent, $issued->status);
        $this->assertSame($template->total, $issued->total);
        $this->assertSame(today()->addDays(30)->toDateString(), $issued->due_date->toDateString());
        Mail::assertSent(DocumentMail::class, 1);

        $schedule = RecurringInvoice::withoutGlobalScopes()->first();
        $this->assertSame(today()->addMonthNoOverflow()->toDateString(), $schedule->next_issue_date->toDateString());

        // Nothing more is due until next month.
        $this->artisan('invoices:issue-recurring');
        $this->assertSame(1, Document::withoutGlobalScopes()->whereNotNull('recurring_invoice_id')->count());
    }

    public function test_paused_schedules_do_not_issue_invoices()
    {
        $template = $this->sentInvoice();
        $this->post("/invoices/{$template->id}/recurring", ['frequency' => 'weekly', 'next_issue_date' => today()->toDateString()]);
        $schedule = RecurringInvoice::first();

        $this->patch("/recurring-invoices/{$schedule->id}", ['is_active' => false])->assertRedirect();
        Auth::logout();
        $this->artisan('invoices:issue-recurring');

        $this->assertSame(0, Document::withoutGlobalScopes()->whereNotNull('recurring_invoice_id')->count());
    }

    public function test_schedules_from_another_business_cannot_be_changed()
    {
        $template = $this->sentInvoice();
        $this->post("/invoices/{$template->id}/recurring", ['frequency' => 'weekly', 'next_issue_date' => today()->toDateString()]);
        $schedule = RecurringInvoice::first();

        $this->actingAs(User::factory()->withBusiness()->create())
            ->delete("/recurring-invoices/{$schedule->id}")
            ->assertNotFound();
    }
}
