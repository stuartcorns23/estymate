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
     * A made-up export laid out like Billdu's document exports.
     */
    private function billduCsv(): string
    {
        return "\xEF\xBB\xBF".implode("\r\n", [
            '"Document type","Invoice number","Reference","Supplier","# VAT Reg. No.","Category","Issue date","Due date","Total","Currency","Payment date","Sum of payment","Payment method","Note","Created by"',
            '"Expense","INV-881","Printer paper","Viking Direct","GB111","Office supplies","30/10/2025","13/11/2025","59.99","GBP","2025-10-30","59,99","Transfer","","Tandonta"',
            '"Expense","F-12","Fuel","Shell","","","02/11/2025","","45.00","GBP","","","","","Tandonta"',
            '"Expense","A1","Web hosting","Some Host Ltd","","Hosting & Domains","05/11/2025","","120.00","GBP","2025-11-05;2025-11-20","60,00;60,00","Card","Two payments","Tandonta"',
            '"Expense","","","","","","05/11/2025","","10.00","GBP","","","","",""',
            '"Expense","X","Mystery","Somebody","","Miscellaneous stuff","not a date","","10.00","GBP","","","","",""',
            '"Expense","Z9","Odd thing","Odd Co","","Weird category","06/11/2025","","12.50","EUR","","","","",""',
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
            null, 'reference', 'description', 'supplier', 'notes', 'category', 'date', 'notes',
            'total', 'currency', 'paid_on', 'notes', 'notes', 'notes', null,
        ]);
    }

    public function test_preview_maps_categories_and_flags_problems()
    {
        $this->user();
        $rows = collect($this->postJson('/expenses/import/preview', ['csv' => $this->billduCsv()])->json('rows'))->keyBy('row');

        $this->assertSame('ready', $rows[2]['status']);
        $this->assertSame('Viking Direct', $rows[2]['attributes']['supplier']);
        $this->assertSame('INV-881', $rows[2]['attributes']['reference']);
        $this->assertSame('Printer paper', $rows[2]['attributes']['description']);
        $this->assertSame('2025-10-30', $rows[2]['attributes']['date']);
        $this->assertSame(5999, $rows[2]['attributes']['total']);
        $this->assertSame('office', $rows[2]['attributes']['category']);
        $this->assertSame('2025-10-30', $rows[2]['attributes']['paid_on']);
        $this->assertStringContainsString('Category in file: Office supplies', $rows[2]['attributes']['notes']);

        // No category: guessed from the description; no payment means unpaid.
        $this->assertSame('vehicle', $rows[3]['attributes']['category']);
        $this->assertNull($rows[3]['attributes']['paid_on']);

        // Several payments: the last one is the date paid.
        $this->assertSame('software', $rows[4]['attributes']['category']);
        $this->assertSame('2025-11-20', $rows[4]['attributes']['paid_on']);

        $this->assertSame('invalid', $rows[5]['status']);
        $this->assertSame(['There is no supplier.'], $rows[5]['messages']);
        $this->assertSame('invalid', $rows[6]['status']);

        // An unknown category becomes Other, with a warning; a foreign currency is kept.
        $this->assertSame('warning', $rows[7]['status']);
        $this->assertSame('other', $rows[7]['attributes']['category']);
        $this->assertSame('EUR', $rows[7]['attributes']['currency']);
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
            'supplier' => 'Viking Direct Ltd', 'date' => '2025-10-30', 'total' => 5999,
        ]);

        $rows = collect($this->postJson('/expenses/import/preview', ['csv' => $this->billduCsv()])->json('rows'))->keyBy('row');

        $this->assertSame('duplicate', $rows[2]['status']);
        $this->assertSame('ready', $rows[3]['status']);
    }

    public function test_other_businesses_expenses_are_not_duplicates()
    {
        $this->user();
        $other = User::factory()->withBusiness()->create();
        Expense::factory()->for($other->currentBusiness)->create([
            'supplier' => 'Viking Direct', 'date' => '2025-10-30', 'total' => 5999,
        ]);

        $rows = collect($this->postJson('/expenses/import/preview', ['csv' => $this->billduCsv()])->json('rows'))->keyBy('row');

        $this->assertSame('ready', $rows[2]['status']);
    }
}
