<?php

namespace Tests\Feature\Billing;

use App\Console\Commands\SendInvoiceReminders;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\PaymentMethod;
use App\Models\Client;
use App\Models\Document;
use App\Models\User;
use App\Support\InvoiceImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceImportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A made-up invoice list in Billdu's style: one row per invoice, with totals.
     */
    private function billduCsv(): string
    {
        return "\xEF\xBB\xBF".implode("\r\n", [
            '"Number","Business name","E-mails","VAT Reg. No.","Date of issue","Date of maturity","Total without VAT","VAT","Total","Currency","Status","Paid"',
            '"INV-0007","Acme Plumbing","info@acme.test","GB123456789","03.02.2025","17.02.2025","100.00","20.00","120.00","GBP","Paid","120.00"',
            '"INV-0008","Bright Signs Ltd","","","10/02/2025","","1,000.00","200.00","1,200.00","GBP","Unpaid","0"',
            '"INV-0009","Bright Signs","","","2025-03-01","2025-03-31","250,00","50,00","300,00","GBP","Partially paid","100,00"',
            '"INV-0010","Corner Shop","shop@corner.test","","15.03.2025","","40.00","0.00","40.00","GBP","Cancelled",""',
            '"INV-0011","","","","15.03.2025","","40.00","0.00","40.00","GBP","Unpaid",""',
            '"INV-0012","Dee Designs","","","not a date","","40.00","0.00","40.00","GBP","Unpaid",""',
        ]);
    }

    /**
     * Rows in the layout of a real Billdu invoice export (names changed): several
     * payments in one cell, a number reused for another client, and an overpayment.
     */
    private function billduExportCsv(): string
    {
        return "\xEF\xBB\xBF".implode("\r\n", [
            '"Document type","Invoice number","Reference","Order number","Client","# VAT Reg. No.","Street","Apartment, suite","City","Zip Code","Country code","Country","Issue date","Due date","Delivery date","Discount","Total","Currency","Payment date","Sum of payment","Payment method","Payment reference","Payment note","Created by"',
            '"Invoice","001","GLS-071","001","Glass Co Limited","","Unit 2 Kelvin Way","West Bromwich","Birmingham","B70 7TN","GB","United Kingdom","08/11/2018","22/11/2018","","0.00","500.00","GBP","04.12.2018;08.12.2018","100,00;500,00","Transfer",";","Deposit;","Someone"',
            '"Invoice","001","PTA-0076","001","Party Hire","","146 Cannock Road","","Wolverhampton","WV10 8PX","GB","United Kingdom","08/02/2019","22/02/2019","08/02/2019","0.00","200.00","GBP","14.03.2019;19.06.2019","100,00;100,00","Transfer","001;001",";Paid by card","Someone"',
            '"Invoice","INV-081","","","Party Hire","","146 Cannock Road","","Wolverhampton","WV10 8PX","GB","United Kingdom","01/03/2020","15/03/2020","","15.00","34.99","GBP","2020-03-02","34,99","Transfer","","BACS","Someone"',
            '"Invoice","INV-338","","","Cranes Ltd","","","","","","GB","United Kingdom","01/11/2026","15/11/2026","","0.00","69.99","GBP","","","Transfer","","","Someone"',
            '"Proforma invoice","PRO-1","","","Cranes Ltd","","","","","","GB","United Kingdom","01/11/2026","15/11/2026","","0.00","69.99","GBP","","","Transfer","","","Someone"',
        ]);
    }

    /**
     * A generic export with one row per line item.
     */
    private function linesCsv(): string
    {
        return implode("\n", [
            'Invoice No;Customer;Invoice Date;Due Date;Description;Qty;Unit Price;VAT %;Status;PO Number',
            '1001;Acme Plumbing;01/04/2025;30/04/2025;Boiler service;1;80.00;20%;Paid;PO-881',
            '1001;Acme Plumbing;01/04/2025;30/04/2025;Parts;2;12.50;20%;Paid;',
            '1002;New Client Co;05/04/2025;05/05/2025;Consultation;1.5;100;0;Unpaid;',
        ]);
    }

    private function user(string $plan = 'pro'): User
    {
        $user = User::factory()->withBusiness()->create();
        $user->currentBusiness->forceFill(['plan_override' => $plan])->save();
        $this->actingAs($user);

        return $user;
    }

    /**
     * The preview's keys for the invoices with these numbers.
     *
     * @param  list<string>  $numbers
     * @return list<string>
     */
    private function keys(string $csv, array $numbers): array
    {
        return collect($this->preview($csv)['invoices'])->whereIn('number', $numbers)->pluck('key')->values()->all();
    }

    private function preview(string $csv): array
    {
        return $this->postJson('/invoices/import/preview', ['csv' => $csv])->assertOk()->json();
    }

    public function test_import_page_is_displayed()
    {
        $this->user();

        $this->get('/invoices/import')->assertOk();
    }

    public function test_billdu_exports_are_recognised_and_mapped()
    {
        $this->user();

        $preview = $this->preview($this->billduCsv());

        $this->assertSame('Billdu', $preview['format']);
        $this->assertSame([
            'number', 'client_name', 'client_email', 'client_vat_number', 'issue_date', 'due_date',
            'subtotal', 'vat_total', 'total', 'currency', 'status', 'amount_paid',
        ], $preview['mapping']);
    }

    public function test_preview_reads_amounts_dates_and_statuses()
    {
        $this->user();
        Client::factory()->create(['name' => 'ACME PLUMBING']);

        $invoices = collect($this->preview($this->billduCsv())['invoices'])->keyBy('number');

        $paid = $invoices['INV-0007'];
        $this->assertSame('ready', $paid['status']);
        $this->assertSame('2025-02-03', $paid['issue_date']);
        $this->assertSame('2025-02-17', $paid['due_date']);
        $this->assertSame(12000, $paid['total']);
        $this->assertSame(2000, $paid['tax_total']);
        $this->assertSame('paid', $paid['invoice_status']);
        $this->assertFalse($paid['new_client']);
        $this->assertSame('ACME PLUMBING', $paid['client']);

        // Thousands separators, a missing due date and a new client.
        $unpaid = $invoices['INV-0008'];
        $this->assertSame('warning', $unpaid['status']);
        $this->assertSame(120000, $unpaid['total']);
        $this->assertSame('sent', $unpaid['invoice_status']);
        $this->assertSame('2025-03-12', $unpaid['due_date']);
        $this->assertTrue($unpaid['new_client']);

        // Decimal commas and a part payment; "Bright Signs" is the same new client as "Bright Signs Ltd".
        $partial = $invoices['INV-0009'];
        $this->assertSame(30000, $partial['total']);
        $this->assertSame(10000, $partial['amount_paid']);
        $this->assertSame('sent', $partial['invoice_status']);
        $this->assertSame($unpaid['new_client_key'], $partial['new_client_key']);

        $this->assertSame('void', $invoices['INV-0010']['invoice_status']);
        $this->assertSame('invalid', $invoices['INV-0011']['status']);
        $this->assertSame('invalid', $invoices['INV-0012']['status']);
    }

    public function test_invoices_already_added_are_flagged()
    {
        $this->user();
        $this->post('/invoices/import', ['csv' => $this->billduCsv(), 'mapping' => $this->preview($this->billduCsv())['mapping'], 'invoices' => $this->keys($this->billduCsv(), ['INV-0007'])]);

        $invoices = collect($this->preview($this->billduCsv())['invoices'])->keyBy('number');

        $this->assertSame('duplicate', $invoices['INV-0007']['status']);
    }

    public function test_chosen_invoices_are_imported_with_clients_and_payments()
    {
        $user = $this->user();
        $acme = Client::factory()->create(['name' => 'Acme Plumbing Ltd']);
        $mapping = $this->preview($this->billduCsv())['mapping'];

        $this->post('/invoices/import', [
            'csv' => $this->billduCsv(),
            'mapping' => $mapping,
            'invoices' => $this->keys($this->billduCsv(), ['INV-0007', 'INV-0008', 'INV-0009', 'INV-0010', 'INV-0012']),
        ])->assertRedirect('/invoices')->assertSessionHas('status', 'Imported 4 invoices and added 2 new clients.');

        $paid = Document::where('number', 'INV-0007')->firstOrFail();
        $this->assertSame($acme->id, $paid->client_id);
        $this->assertSame(DocumentType::Invoice, $paid->type);
        $this->assertSame(DocumentStatus::Paid, $paid->status);
        $this->assertSame(12000, $paid->total);
        $this->assertSame(12000, $paid->amount_paid);
        $this->assertSame('2025-02-03', $paid->issue_date->toDateString());
        $this->assertSame('20.00', $paid->items->first()->tax_rate);

        $partial = Document::where('number', 'INV-0009')->firstOrFail();
        $this->assertSame(DocumentStatus::Sent, $partial->status);
        $this->assertSame(10000, $partial->amount_paid);
        $this->assertSame(SendInvoiceReminders::MAX_REMINDERS, $partial->reminders_sent);
        $this->assertSame(Document::where('number', 'INV-0008')->value('client_id'), $partial->client_id);

        $this->assertSame(DocumentStatus::Void, Document::where('number', 'INV-0010')->value('status'));
        $this->assertSame(3, Client::count());

        // New invoices carry on after the highest number imported.
        $this->assertSame(11, $user->currentBusiness->fresh()->next_invoice_number);
    }

    public function test_real_billdu_exports_import_every_payment()
    {
        $user = $this->user();
        $preview = $this->preview($this->billduExportCsv());

        $this->assertSame('Billdu', $preview['format']);
        $this->assertSame([
            'document_type', 'number', 'reference', 'po_number', 'client_name', 'client_vat_number', 'client_address_line_1',
            'client_address_line_2', 'client_city', 'client_postcode', 'client_country', null, 'issue_date', 'due_date',
            null, 'discount', 'total', 'currency', 'paid_date', 'amount_paid', 'payment_method', 'payment_reference',
            'payment_note', null,
        ], $preview['mapping']);

        $invoices = collect($preview['invoices'])->keyBy('number');
        $this->assertSame(['001', '001-2', 'INV-081', 'INV-338', 'PRO-1'], $invoices->keys()->all());
        $this->assertSame('invalid', $invoices['PRO-1']['status']);
        $this->assertStringContainsString('001-2', $invoices['001-2']['messages'][0]);

        $this->post('/invoices/import', [
            'csv' => $this->billduExportCsv(),
            'mapping' => $preview['mapping'],
            'invoices' => $invoices->pluck('key')->all(),
        ])->assertRedirect('/invoices')->assertSessionHas('status', 'Imported 4 invoices and added 3 new clients.');

        // Two payments, the second cut back so the total paid isn't more than the invoice.
        $glass = Document::where('number', '001')->with('payments')->firstOrFail();
        $this->assertSame(DocumentStatus::Paid, $glass->status);
        $this->assertSame([10000, 40000], $glass->payments->pluck('amount')->all());
        $this->assertSame(['2018-12-04', '2018-12-08'], $glass->payments->map(fn ($p) => $p->paid_on->toDateString())->all());
        $this->assertSame(PaymentMethod::BankTransfer, $glass->payments[0]->method);
        $this->assertSame('Deposit', $glass->payments[0]->reference);
        $this->assertSame('2018-12-08', $glass->paid_at->toDateString());

        $party = Document::where('number', '001-2')->with('payments', 'client')->firstOrFail();
        $this->assertSame('Party Hire', $party->client->name);
        $this->assertSame('West Bromwich', $glass->client->address_line_2);
        $this->assertSame([10000, 10000], $party->payments->pluck('amount')->all());
        $this->assertSame('001 · Paid by card', $party->payments[1]->reference);

        // Billdu's "Order number" is the PO number, but it repeats the invoice number when none was given.
        $this->assertSame('GLS-071', $glass->reference);
        $this->assertNull($glass->po_number);

        $discounted = Document::where('number', 'INV-081')->firstOrFail();
        $this->assertSame($party->client_id, $discounted->client_id);
        $this->assertStringContainsString('Discount in the old app: 15.00', $discounted->notes);
        $this->assertSame(DocumentStatus::Paid, $discounted->status);

        $unpaid = Document::where('number', 'INV-338')->firstOrFail();
        $this->assertSame(DocumentStatus::Sent, $unpaid->status);
        $this->assertSame(0, $unpaid->amount_paid);

        $this->assertSame(339, $user->currentBusiness->fresh()->next_invoice_number);
    }

    public function test_line_items_are_grouped_into_invoices()
    {
        $this->user();
        $preview = $this->preview($this->linesCsv());

        $this->assertNull($preview['format']);
        $this->assertSame(['number', 'client_name', 'issue_date', 'due_date', 'description', 'quantity', 'unit_price', 'vat_rate', 'status', 'po_number'], $preview['mapping']);
        $this->assertCount(2, $preview['invoices']);

        $this->post('/invoices/import', [
            'csv' => $this->linesCsv(),
            'mapping' => $preview['mapping'],
            'invoices' => $this->keys($this->linesCsv(), ['1001', '1002']),
        ])->assertRedirect('/invoices');

        $first = Document::where('number', '1001')->with('items')->firstOrFail();
        $this->assertCount(2, $first->items);
        $this->assertSame(10500, $first->subtotal);
        $this->assertSame(2100, $first->tax_total);
        $this->assertSame(DocumentStatus::Paid, $first->status);
        $this->assertSame('Parts', $first->items[1]->description);
        $this->assertSame(1250, $first->items[1]->unit_price);
        $this->assertSame('PO-881', $first->po_number);

        $second = Document::where('number', '1002')->firstOrFail();
        $this->assertSame(15000, $second->total);
        $this->assertSame('2025-05-05', $second->due_date->toDateString());
    }

    public function test_imports_stop_at_the_plan_limits()
    {
        $this->user('free'); // 5 invoices a month and 5 clients.
        Client::factory()->create(['name' => 'Acme Plumbing']);
        Client::factory()->count(3)->create();

        $preview = $this->preview($this->billduCsv());
        $this->assertSame(1, $preview['plan']['clients_remaining']);

        $this->post('/invoices/import', [
            'csv' => $this->billduCsv(),
            'mapping' => $preview['mapping'],
            'invoices' => $this->keys($this->billduCsv(), ['INV-0007', 'INV-0008', 'INV-0009', 'INV-0010']),
        ])->assertRedirect('/invoices');

        // Acme is already a client, Bright Signs takes the one client left, and Corner Shop would go over.
        $this->assertSame(['INV-0007', 'INV-0008', 'INV-0009'], Document::orderBy('number')->pluck('number')->all());
        $this->assertStringContainsString('1 was left out', session('status'));
    }

    public function test_other_businesses_invoice_numbers_do_not_clash()
    {
        $this->user();
        $this->post('/invoices/import', ['csv' => $this->billduCsv(), 'mapping' => $this->preview($this->billduCsv())['mapping'], 'invoices' => $this->keys($this->billduCsv(), ['INV-0007'])]);

        $this->user();
        $invoices = collect($this->preview($this->billduCsv())['invoices'])->keyBy('number');

        $this->assertSame('ready', $invoices['INV-0007']['status']);
    }

    public function test_numbers_and_dates_are_read_in_uk_and_european_styles()
    {
        $this->assertSame(123450, InvoiceImport::money('£1,234.50'));
        $this->assertSame(123450, InvoiceImport::money('1.234,50'));
        $this->assertSame(123450, InvoiceImport::money('1 234,50 €'));
        $this->assertSame(125000, InvoiceImport::money('1,250'));
        $this->assertSame(1250, InvoiceImport::money('12,5'));
        $this->assertNull(InvoiceImport::money(''));

        $this->assertSame('2025-02-03', InvoiceImport::date('03.02.2025')->toDateString());
        $this->assertSame('2025-02-03', InvoiceImport::date('3. 2. 2025')->toDateString());
        $this->assertSame('2025-02-03', InvoiceImport::date('03/02/25')->toDateString());
        $this->assertSame('2025-02-03', InvoiceImport::date('2025-02-03 10:00:00')->toDateString());
        $this->assertSame('2025-02-03', InvoiceImport::date('3 Feb 2025')->toDateString());
        $this->assertNull(InvoiceImport::date('31/02/2025'));
    }
}
