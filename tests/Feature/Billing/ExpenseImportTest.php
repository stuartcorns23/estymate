<?php

namespace Tests\Feature\Billing;

use App\Enums\ExpenseCategory;
use App\Models\Expense;
use App\Models\User;
use App\Support\ExpenseImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpenseImportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A made-up export in the layout of Billdu's real expense export.
     */
    private function billduCsv(): string
    {
        return "\xEF\xBB\xBF".implode("\r\n", [
            '"Category","Name","Business","VAT Reg. No.","Created","Due date","Reference","Currency","Paid","Total"',
            '"Office Supplies","Kenable - Cables for a client","Bright Signs Ltd","","24/03/2026","","002105659","GBP","Yes","24.18"',
            '"Licenses, permits","Web Host Co","","","01/01/2026","","SUB-1","USD","Yes","17.00"',
            '"Licenses, permits","Web Host Co","","","01/12/2025","","SUB-1","USD","Yes","17.00"',
            '"Print","Solopress - 4717416","","","05/11/2025","","","GBP","Yes","45.00"',
            '"","","","","05/11/2025","","","GBP","Yes","10.00"',
            '"Mystery things","Somebody","","","not a date","","","GBP","Yes","10.00"',
            '"Weird category","Odd Co","","","06/11/2025","","","GBP","Yes","12.50"',
            '"Accounting","Premium","","","07/11/2025","","","GBP","Yes","12.00"',
            '"Accounting","Premium","","","07/11/2025","","","GBP","Yes","12.00"',
        ]);
    }

    private function user(): User
    {
        $user = User::factory()->withBusiness()->create();
        $user->currentBusiness->forceFill(['plan_override' => 'free'])->save();
        $this->actingAs($user);

        return $user;
    }

    public function test_import_page_is_displayed()
    {
        $this->user();

        $this->get('/expenses/import')->assertOk();
    }

    public function test_billdu_exports_are_recognised_and_mapped()
    {
        $this->user();

        $response = $this->postJson('/expenses/import/preview', ['csv' => $this->billduCsv()])->assertOk();

        $response->assertJsonPath('format', 'Billdu');
        $response->assertJsonPath('mapping', [
            'category', 'supplier', 'notes', 'notes', 'date', 'notes', 'reference', 'currency', 'paid', 'total',
        ]);
    }

    public function test_preview_maps_categories_and_flags_problems()
    {
        $this->user();
        $rows = collect($this->postJson('/expenses/import/preview', ['csv' => $this->billduCsv()])->json('rows'))->keyBy('row');

        // The name is split into supplier and details; "Business" is the client, kept in the notes.
        $this->assertSame('ready', $rows[2]['status']);
        $this->assertSame('Kenable', $rows[2]['attributes']['supplier']);
        $this->assertSame('Cables for a client', $rows[2]['attributes']['description']);
        $this->assertSame('002105659', $rows[2]['attributes']['reference']);
        $this->assertSame('2026-03-24', $rows[2]['attributes']['date']);
        $this->assertSame(2418, $rows[2]['attributes']['total']);
        $this->assertSame('office', $rows[2]['attributes']['category']);
        $this->assertSame('2026-03-24', $rows[2]['attributes']['paid_on']);
        $this->assertStringContainsString('Business: Bright Signs Ltd', $rows[2]['attributes']['notes']);
        $this->assertStringContainsString('Category in file: Office Supplies', $rows[2]['attributes']['notes']);

        // A monthly subscription with the same reference each month isn't a duplicate.
        $this->assertSame('software', $rows[3]['attributes']['category']);
        $this->assertSame('USD', $rows[3]['attributes']['currency']);
        $this->assertSame('warning', $rows[3]['status']);
        $this->assertSame('warning', $rows[4]['status']);

        $this->assertSame('materials', $rows[5]['attributes']['category']);
        $this->assertSame('Solopress', $rows[5]['attributes']['supplier']);
        // An order number after the dash becomes the reference, not a description too.
        $this->assertSame('4717416', $rows[5]['attributes']['reference']);
        $this->assertNull($rows[5]['attributes']['description']);

        $this->assertSame('invalid', $rows[6]['status']);
        $this->assertSame(['There is no supplier.'], $rows[6]['messages']);
        $this->assertSame('invalid', $rows[7]['status']);

        // An unknown category becomes Other, with a warning.
        $this->assertSame('warning', $rows[8]['status']);
        $this->assertSame('other', $rows[8]['attributes']['category']);

        // Two identical rows in one file are both kept.
        $this->assertSame('professional', $rows[9]['attributes']['category']);
        $this->assertSame('ready', $rows[9]['status']);
        $this->assertSame('ready', $rows[10]['status']);
    }

    public function test_generic_files_work_out_vat_and_paid_status()
    {
        $this->user();
        $csv = implode("\n", [
            'Date,Vendor,Description,Net,VAT rate,Status',
            '2025-04-06,Train Co,Train to London,100.00,20%,Paid',
            '2025-04-07,Accountant Ltd,Year end accounts,500,0,Unpaid',
        ]);

        $rows = $this->postJson('/expenses/import/preview', ['csv' => $csv])->assertOk()->json('rows');

        $this->assertSame(12000, $rows[0]['attributes']['total']);
        $this->assertSame(2000, $rows[0]['attributes']['vat_amount']);
        $this->assertSame('travel', $rows[0]['attributes']['category']);
        $this->assertSame('2025-04-06', $rows[0]['attributes']['paid_on']);
        $this->assertSame('professional', $rows[1]['attributes']['category']);
        $this->assertNull($rows[1]['attributes']['paid_on']);
    }

    public function test_exact_category_names_match()
    {
        $this->assertSame(ExpenseCategory::BankCharges, ExpenseImport::category('Bank & finance charges')[0]);
        $this->assertTrue(ExpenseImport::category('bank_charges')[1]);
    }

    public function test_ticked_rows_are_imported_without_plan_limits()
    {
        $user = $this->user();
        $csv = "Date,Supplier,Total\n".collect(range(1, 12))->map(fn ($i) => "2025-05-{$i},Supplier {$i},{$i}.00")->implode("\n");

        $this->post('/expenses/import', [
            'csv' => $csv,
            'mapping' => ['date', 'supplier', 'total'],
            'rows' => range(2, 13),
        ])->assertRedirect('/expenses')->assertSessionHas('status', 'Imported 12 expenses.');

        $this->assertSame(12, Expense::count());
        $expense = Expense::where('supplier', 'Supplier 3')->first();
        $this->assertSame($user->current_business_id, $expense->business_id);
        $this->assertSame(300, $expense->total);
        $this->assertSame('GBP', $expense->currency);
    }

    public function test_duplicates_start_flagged()
    {
        $user = $this->user();
        Expense::factory()->for($user->currentBusiness)->create([
            'supplier' => 'Kenable Ltd', 'date' => '2026-03-24', 'total' => 2418, 'reference' => null,
        ]);
        Expense::factory()->for($user->currentBusiness)->create([
            'supplier' => 'Web Host Co', 'date' => '2026-01-01', 'total' => 1700, 'reference' => 'OTHER',
        ]);

        $rows = collect($this->postJson('/expenses/import/preview', ['csv' => $this->billduCsv()])->json('rows'))->keyBy('row');

        $this->assertSame('duplicate', $rows[2]['status']);
        // Same supplier, date and total but a different reference.
        $this->assertSame('warning', $rows[3]['status']);
    }

    public function test_other_businesses_expenses_are_not_duplicates()
    {
        $this->user();
        $other = User::factory()->withBusiness()->create();
        Expense::factory()->for($other->currentBusiness)->create([
            'supplier' => 'Kenable', 'date' => '2026-03-24', 'total' => 2418,
        ]);

        $rows = collect($this->postJson('/expenses/import/preview', ['csv' => $this->billduCsv()])->json('rows'))->keyBy('row');

        $this->assertSame('ready', $rows[2]['status']);
    }
}
