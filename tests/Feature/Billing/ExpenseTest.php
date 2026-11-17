<?php

namespace Tests\Feature\Billing;

use App\Enums\ExpenseCategory;
use App\Models\Client;
use App\Models\Document;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExpenseTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return [
            'supplier' => 'Screwfix',
            'reference' => 'INV-123',
            'date' => '2026-11-10',
            'category' => 'materials',
            'description' => 'Timber',
            'total' => '120.00',
            'vat_amount' => '20.00',
            ...$overrides,
        ];
    }

    public function test_expense_pages_are_displayed()
    {
        $user = User::factory()->withBusiness()->create();
        $expense = Expense::factory()->create(['business_id' => $user->current_business_id]);

        $this->actingAs($user);
        $this->get('/expenses')->assertOk();
        $this->get('/expenses/create')->assertOk();
        $this->get("/expenses/{$expense->id}/edit")->assertOk();
    }

    public function test_expenses_are_stored_in_pence_with_an_attachment()
    {
        Storage::fake(Expense::DISK);
        $user = User::factory()->withBusiness()->create();

        $this->actingAs($user)
            ->post('/expenses', $this->payload(['attachment' => UploadedFile::fake()->create('receipt.pdf', 200, 'application/pdf')]))
            ->assertSessionHasNoErrors()
            ->assertRedirect('/expenses');

        $expense = Expense::first();
        $this->assertSame(12000, $expense->total);
        $this->assertSame(2000, $expense->vat_amount);
        $this->assertSame(10000, $expense->net_amount);
        $this->assertSame(ExpenseCategory::Materials, $expense->category);
        $this->assertSame($user->current_business_id, $expense->business_id);
        $this->assertSame('receipt.pdf', $expense->attachment_name);
        Storage::disk(Expense::DISK)->assertExists($expense->attachment_path);

        $this->get("/expenses/{$expense->id}/attachment")->assertOk()->assertDownload('receipt.pdf');
    }

    public function test_attachments_can_be_replaced_and_removed()
    {
        Storage::fake(Expense::DISK);
        $user = User::factory()->withBusiness()->create();
        $this->actingAs($user)->post('/expenses', $this->payload(['attachment' => UploadedFile::fake()->image('first.jpg')]));
        $expense = Expense::first();
        $first = $expense->attachment_path;

        $this->put("/expenses/{$expense->id}", $this->payload(['attachment' => UploadedFile::fake()->image('second.png')]))
            ->assertSessionHasNoErrors();
        $expense->refresh();
        Storage::disk(Expense::DISK)->assertMissing($first);
        Storage::disk(Expense::DISK)->assertExists($expense->attachment_path);
        $this->assertSame('second.png', $expense->attachment_name);

        $second = $expense->attachment_path;
        $this->put("/expenses/{$expense->id}", $this->payload(['remove_attachment' => true]))->assertSessionHasNoErrors();
        $this->assertNull($expense->refresh()->attachment_path);
        Storage::disk(Expense::DISK)->assertMissing($second);
    }

    public function test_vat_cannot_be_more_than_the_total()
    {
        $this->actingAs(User::factory()->withBusiness()->create())
            ->post('/expenses', $this->payload(['total' => '10', 'vat_amount' => '20']))
            ->assertSessionHasErrors('vat_amount');
    }

    public function test_unknown_categories_and_files_are_rejected()
    {
        $this->actingAs(User::factory()->withBusiness()->create())
            ->post('/expenses', $this->payload(['category' => 'yachts', 'attachment' => UploadedFile::fake()->create('virus.exe', 10)]))
            ->assertSessionHasErrors(['category', 'attachment']);
    }

    public function test_expenses_are_private_to_their_business()
    {
        $other = User::factory()->withBusiness()->create();
        $expense = Expense::factory()->create(['business_id' => $other->current_business_id]);

        $this->actingAs(User::factory()->withBusiness()->create());
        $this->get("/expenses/{$expense->id}/edit")->assertNotFound();
        $this->get("/expenses/{$expense->id}/attachment")->assertNotFound();
        $this->delete("/expenses/{$expense->id}")->assertNotFound();
        $this->get('/expenses')->assertInertia(fn (Assert $page) => $page->has('expenses.data', 0));
    }

    public function test_expenses_are_not_limited_by_plan()
    {
        $user = User::factory()->withBusiness()->create();
        $user->currentBusiness->forceFill(['trial_ends_at' => now()->subDay()])->save();
        Expense::factory()->count(20)->create(['business_id' => $user->current_business_id]);

        $this->actingAs($user)->post('/expenses', $this->payload())->assertSessionHasNoErrors()->assertRedirect('/expenses');
    }

    public function test_index_filters_by_category_and_totals_the_results()
    {
        $user = User::factory()->withBusiness()->create();
        Expense::factory()->create(['business_id' => $user->current_business_id, 'category' => 'travel', 'total' => 5000, 'vat_amount' => 0]);
        Expense::factory()->create(['business_id' => $user->current_business_id, 'category' => 'software', 'total' => 1200, 'vat_amount' => 200]);

        $this->actingAs($user)->get('/expenses?category=software')->assertInertia(fn (Assert $page) => $page
            ->has('expenses.data', 1)
            ->where('totals.total', 1200)
            ->where('totals.vat', 200));
    }

    public function test_dashboard_shows_money_in_and_out_by_month_and_category()
    {
        $this->travelTo('2026-11-17');
        $user = User::factory()->withBusiness()->create();
        $business = $user->current_business_id;
        Expense::factory()->create(['business_id' => $business, 'date' => '2026-11-03', 'category' => 'travel', 'total' => 3000]);
        Expense::factory()->create(['business_id' => $business, 'date' => '2026-10-20', 'category' => 'travel', 'total' => 2000]);
        Expense::factory()->create(['business_id' => $business, 'date' => '2026-10-21', 'category' => 'software', 'total' => 1000]);
        Expense::factory()->create(['business_id' => $business, 'date' => '2025-10-31', 'category' => 'software', 'total' => 99999]);

        $this->actingAs($user);
        $invoice = $this->createInvoice();
        Payment::forceCreate(['business_id' => $business, 'document_id' => $invoice, 'amount' => 8000, 'paid_on' => '2026-10-05', 'method' => 'bank_transfer']);

        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('totals.spent_this_month', 3000)
            ->has('cashflow', 12)
            ->where('cashflow.0.month', '2025-12')
            ->where('cashflow.10', ['month' => '2026-10', 'income' => 8000, 'expenses' => 3000])
            ->where('cashflow.11', ['month' => '2026-11', 'income' => 0, 'expenses' => 3000])
            ->where('expenseCategories', [
                ['category' => 'travel', 'label' => 'Travel', 'total' => 5000],
                ['category' => 'software', 'label' => 'Software & subscriptions', 'total' => 1000],
            ]));
    }

    private function createInvoice(): int
    {
        $client = Client::factory()->create();
        $this->post('/invoices', [
            'client_id' => $client->id,
            'issue_date' => '2026-10-01',
            'items' => [['description' => 'Work', 'quantity' => '1', 'unit_price' => '80', 'tax_rate' => '0']],
        ])->assertSessionHasNoErrors();

        return Document::firstOrFail()->id;
    }
}
