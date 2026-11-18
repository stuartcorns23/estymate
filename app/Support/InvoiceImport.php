<?php

namespace App\Support;

use App\Models\Client;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Turns a CSV of invoices into invoices ready to import, checking each one first.
 *
 * A file can have one row per invoice (with its totals) or one row per line item,
 * in which case rows with the same invoice number become one invoice. Each invoice
 * keeps its original number, dates, amounts, VAT and paid status, and is linked to
 * a client: an existing one when the name or email matches, otherwise a new one.
 *
 * Amounts from the file are kept exactly (in pence) rather than recalculated, so an
 * imported invoice always shows the same totals it had in the old app.
 */
class InvoiceImport
{
    public const MAX_ROWS = 5000;

    public const MAX_BYTES = 4_000_000;

    /**
     * Fields a column can be mapped to, with their labels.
     */
    public const FIELDS = [
        'document_type' => 'Document type',
        'number' => 'Invoice number',
        'client_name' => 'Client name',
        'client_email' => 'Client email',
        'client_address_line_1' => 'Client address',
        'client_address_line_2' => 'Client address line 2',
        'client_city' => 'Client town or city',
        'client_postcode' => 'Client postcode',
        'client_country' => 'Client country',
        'client_vat_number' => 'Client VAT number',
        'issue_date' => 'Issue date',
        'due_date' => 'Due date',
        'status' => 'Status (paid, unpaid…)',
        'amount_paid' => 'Amount paid (or each payment)',
        'paid_date' => 'Date paid (or each payment date)',
        'payment_method' => 'Payment method',
        'payment_reference' => 'Payment reference',
        'payment_note' => 'Payment note',
        'discount' => 'Discount (kept in the notes)',
        'currency' => 'Currency',
        'po_number' => 'Client\'s PO number',
        'reference' => 'Reference',
        'notes' => 'Notes',
        'description' => 'Line: description',
        'quantity' => 'Line: quantity',
        'unit' => 'Line: unit',
        'unit_price' => 'Line: unit price (ex VAT)',
        'vat_rate' => 'Line: VAT rate %',
        'line_net' => 'Line: total ex VAT',
        'line_vat' => 'Line: VAT',
        'line_total' => 'Line: total inc VAT',
        'subtotal' => 'Invoice: total ex VAT',
        'vat_total' => 'Invoice: VAT',
        'total' => 'Invoice: total inc VAT',
    ];

    /**
     * Column headings we recognise, normalised to lowercase letters and digits.
     * Billdu's headings come first in each list.
     */
    private const ALIASES = [
        'document_type' => ['documenttype', 'doctype', 'documentkind'],
        'number' => ['invoicenumber', 'number', 'invoiceno', 'invoicenr', 'documentnumber', 'documentno', 'no', 'nr', 'invoice', 'invoiceid'],
        'client_name' => ['client', 'businessname', 'clientname', 'clientbusinessname', 'customer', 'customername', 'company', 'companyname', 'billto', 'buyer', 'partner', 'name', 'contact'],
        'client_email' => ['emails', 'email', 'clientemail', 'clientemails', 'customeremail', 'emailaddress'],
        'client_address_line_1' => ['street', 'clientstreet', 'address', 'clientaddress', 'billingaddress', 'billingstreet'],
        'client_address_line_2' => ['apartmentsuite', 'address2', 'addressline2', 'street2', 'billingstreet2'],
        'client_city' => ['city', 'clientcity', 'town', 'billingcity'],
        'client_postcode' => ['zipcode', 'clientzipcode', 'zip', 'postcode', 'postalcode', 'billingpostcode'],
        'client_country' => ['countrycode', 'country', 'clientcountry', 'billingcountry'],
        'client_vat_number' => ['vatregno', 'clientvatregno', 'vatnumber', 'clientvatnumber', 'customervatnumber', 'vatno'],
        'issue_date' => ['issuedate', 'dateofissue', 'date', 'invoicedate', 'dateissued', 'issued', 'documentdate', 'created', 'createdat'],
        'due_date' => ['duedate', 'dateofmaturity', 'maturity', 'maturitydate', 'due', 'paymentdue', 'paymentduedate', 'duedateofpayment'],
        'status' => ['status', 'state', 'paymentstatus', 'paidstatus', 'invoicestatus'],
        'amount_paid' => ['sumofpayment', 'sumofpayments', 'paymentamount', 'amountofpayment', 'paid', 'amountpaid', 'paidamount', 'totalpaid', 'payments', 'paymentsreceived'],
        'paid_date' => ['paymentdate', 'paiddate', 'datepaid', 'dateofpayment', 'paidon', 'paidat'],
        'payment_method' => ['paymentmethod', 'method', 'paymenttype', 'paidby', 'paidvia'],
        'payment_reference' => ['paymentreference', 'paymentref'],
        'payment_note' => ['paymentnote', 'paymentnotes', 'paymentcomment'],
        'discount' => ['discount', 'discountpercent', 'discountrate', 'discountamount'],
        'currency' => ['currency', 'currencycode'],
        'po_number' => ['ordernumber', 'orderno', 'ponumber', 'pono', 'po', 'purchaseorder', 'purchaseordernumber', 'purchaseorderno', 'customerpo', 'clientpo', 'yourpo', 'yourponumber'],
        'reference' => ['reference', 'yourreference', 'variablesymbol'],
        'notes' => ['note', 'notes', 'comment', 'comments', 'footer', 'remarks'],
        'description' => ['item', 'itemname', 'description', 'itemdescription', 'product', 'productname', 'service', 'linedescription', 'details'],
        'quantity' => ['quantity', 'qty', 'itemquantity', 'count', 'hours'],
        'unit' => ['unit', 'units', 'uom', 'measure'],
        'unit_price' => ['unitprice', 'price', 'priceperunit', 'itemprice', 'unitpricewithoutvat', 'unitpriceexvat', 'unitpricenet', 'rate'],
        'vat_rate' => ['vatrate', 'taxrate', 'vatpercent', 'vatpercentage', 'rateofvat', 'itemvatrate'],
        'line_net' => ['linetotalwithoutvat', 'itemtotalwithoutvat', 'linenet', 'itemnet', 'linetotalexvat', 'linesubtotal', 'itemsubtotal'],
        'line_vat' => ['linevat', 'itemvat', 'linetax', 'itemtax', 'linevatamount'],
        'line_total' => ['linetotal', 'itemtotal', 'lineamount', 'itemamount', 'linetotalwithvat', 'itemtotalwithvat'],
        'subtotal' => ['totalwithoutvat', 'subtotal', 'net', 'nettotal', 'totalnet', 'totalexvat', 'totalexclvat', 'amountwithoutvat', 'withoutvat', 'netamount', 'base'],
        'vat_total' => ['vat', 'tax', 'vattotal', 'totalvat', 'vatamount', 'taxamount', 'taxtotal'],
        'total' => ['total', 'totalwithvat', 'grandtotal', 'totalamount', 'amount', 'gross', 'totalinclvat', 'totalincvat', 'invoicetotal', 'totaltopay', 'sum'],
    ];

    /**
     * VAT rates close to these are taken to be exactly these, after rounding in the old app.
     */
    private const STANDARD_RATES = [0, 5, 12.5, 15, 17.5, 20];

    private const PAYMENT_METHODS = [
        'bank_transfer' => ['transfer', 'bacs', 'bank', 'fps', 'fasterpayment', 'standingorder', 'directdebit'],
        'card' => ['card', 'debit', 'credit', 'visa', 'mastercard', 'amex'],
        'cash' => ['cash'],
        'cheque' => ['cheque', 'check'],
    ];

    private const CURRENCY_SYMBOLS = ['£' => 'GBP', '€' => 'EUR', '$' => 'USD'];

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    public function __construct(public readonly array $headers, public readonly array $rows) {}

    /**
     * @throws ValidationException
     */
    public static function fromCsv(string $text): self
    {
        return new self(...CsvReader::read($text, self::MAX_ROWS, 'rows'));
    }

    /**
     * The name of the app the CSV was exported from, when we recognise it.
     *
     * Billdu's invoice export has headings like "Document type", "# VAT Reg. No.",
     * "Apartment, suite", "Sum of payment" and "Created by", so any two of those next
     * to an invoice number is taken as Billdu.
     */
    public function format(): ?string
    {
        $headers = array_map(ClientImport::normalise(...), $this->headers);
        $billdu = array_intersect(['documenttype', 'vatregno', 'apartmentsuite', 'sumofpayment', 'paymentnote', 'createdby', 'deliverydate', 'businessname', 'emails', 'dateofmaturity'], $headers);
        $hasNumber = array_intersect(['number', 'invoicenumber', 'documentnumber'], $headers) !== [];

        return $hasNumber && count($billdu) >= 2 ? 'Billdu' : null;
    }

    /**
     * @return list<string>
     */
    public function samples(): array
    {
        return array_map(
            fn ($column) => Str::limit(collect($this->rows)->pluck($column)->first(fn ($value) => $value !== '') ?? '', 40),
            array_keys($this->headers),
        );
    }

    /**
     * A best guess at which field each column belongs to. Each field is guessed for one column only.
     *
     * @return list<string|null>
     */
    public function guessMapping(): array
    {
        $mapping = [];

        foreach ($this->headers as $header) {
            $key = ClientImport::normalise($header);

            // "VAT %" or "Tax (%)" is a rate, not an amount.
            if (str_contains($header, '%') && in_array($key, ['vat', 'tax', 'dph'], true)) {
                $key = 'vatrate';
            }

            $field = collect(self::ALIASES)->search(fn ($aliases) => in_array($key, $aliases, true));
            $mapping[] = $field === false || in_array($field, $mapping, true) ? null : $field;
        }

        return $mapping;
    }

    /**
     * Group the rows into invoices and check each one against the mapping, the
     * business's clients and its invoices.
     *
     * Each invoice is "ready", "warning" (will import, with changes explained),
     * "duplicate" (its number is already used, so it can't be imported) or "invalid".
     *
     * @param  list<string|null>  $mapping
     * @param  Collection<int, Client>  $clients
     * @param  list<string>  $existingNumbers
     * @return list<array<string, mixed>>
     */
    public function analyse(array $mapping, Collection $clients, array $existingNumbers, string $defaultCurrency, int $paymentTermsDays): array
    {
        $columns = [];

        foreach ($mapping as $column => $field) {
            if ($field !== null) {
                $columns[$field] ??= $column;
            }
        }

        $existing = array_flip(array_map(fn ($number) => Str::lower($number), $existingNumbers));
        $byName = $clients->keyBy(fn ($client) => ClientImport::nameKey($client->name));
        $byEmail = $clients->filter(fn ($client) => $client->email)->keyBy(fn ($client) => Str::lower($client->email));
        $newClients = [];
        $results = [];
        $numberCounts = [];

        foreach ($this->groups($columns) as $key => $group) {
            $invoice = $this->buildInvoice($group, $columns, $defaultCurrency, $paymentTermsDays);
            $invoice['key'] = $key;

            // Invoice numbers must be unique here, so a number used again for another client gets "-2", "-3"...
            if ($invoice['number'] !== '') {
                $count = $numberCounts[Str::lower($invoice['number'])] = ($numberCounts[Str::lower($invoice['number'])] ?? 0) + 1;

                if ($count > 1) {
                    $original = $invoice['number'];
                    $invoice['number'] = "{$original}-{$count}";

                    if ($invoice['status'] !== 'invalid') {
                        $invoice['status'] = 'warning';
                        $invoice['messages'][] = "Invoice number {$original} is used for more than one client, so this one is imported as {$invoice['number']}.";
                    }
                }
            }

            if ($invoice['status'] !== 'invalid') {
                $client = $invoice['client'];
                $email = $client['email'] ?? null;
                $nameKey = ClientImport::nameKey($client['name']);

                if ($match = $byName->get($nameKey) ?? ($email ? $byEmail->get(Str::lower($email)) : null)) {
                    $invoice['client_id'] = $match->id;
                    $invoice['client_label'] = $match->name;
                    $invoice['new_client'] = null;
                } else {
                    // Invoices for the same new client share one new client.
                    $newClients[$nameKey] ??= $client;
                    $invoice['client_id'] = null;
                    $invoice['client_label'] = $newClients[$nameKey]['name'];
                    $invoice['new_client'] = $nameKey;
                }

                if (isset($existing[Str::lower($invoice['number'])])) {
                    $invoice['status'] = 'duplicate';
                    array_unshift($invoice['messages'], "Invoice {$invoice['number']} is already in your invoices, so it can't be imported again.");
                }
            }

            $results[] = $invoice;
        }

        foreach ($results as &$invoice) {
            if (($invoice['new_client'] ?? null) !== null) {
                $invoice['client'] = $newClients[$invoice['new_client']];
            }
        }

        unset($invoice);

        return $results;
    }

    /**
     * Rows grouped into invoices, in the order they first appear.
     *
     * Rows with the same number and client are one invoice (one row per line item).
     * The same number for a different client is a separate invoice: Billdu lets
     * numbers repeat, such as "001" for each client. A line row that leaves the
     * client blank belongs to the last invoice with its number.
     *
     * @param  array<string, int>  $columns
     * @return array<string, list<array{row: int, cells: list<string>}>>
     */
    private function groups(array $columns): array
    {
        $groups = [];
        $lastForNumber = [];

        foreach ($this->rows as $index => $cells) {
            $row = $index + 2; // The row number in a spreadsheet, under the heading row.
            $number = isset($columns['number']) ? Str::lower(self::clean($cells[$columns['number']] ?? '')) : '';
            $client = isset($columns['client_name']) ? ClientImport::nameKey(self::clean($cells[$columns['client_name']] ?? '')) : '';

            if ($number === '') {
                $key = "row-{$row}";
            } elseif ($client === '' && isset($lastForNumber[$number])) {
                $key = $lastForNumber[$number];
            } else {
                $key = "n-{$number}-{$client}";
                $lastForNumber[$number] = $key;
            }

            $groups[$key][] = ['row' => $row, 'cells' => $cells];
        }

        return $groups;
    }

    /**
     * Build one invoice from its rows.
     *
     * @param  list<array{row: int, cells: list<string>}>  $group
     * @param  array<string, int>  $columns
     * @return array<string, mixed>
     */
    private function buildInvoice(array $group, array $columns, string $defaultCurrency, int $paymentTermsDays): array
    {
        $messages = [];
        // The invoice's own fields come from the first row that has them.
        $first = function (string $field) use ($group, $columns): string {
            if (! isset($columns[$field])) {
                return '';
            }

            foreach ($group as $line) {
                $value = self::clean($line['cells'][$columns[$field]] ?? '');

                if ($value !== '') {
                    return $value;
                }
            }

            return '';
        };

        $invoice = [
            'rows' => array_column($group, 'row'),
            'number' => Str::limit($first('number'), 255, ''),
            'status' => 'ready',
            'messages' => [],
        ];

        $invalid = function (string $message) use ($invoice): array {
            return [...$invoice, 'status' => 'invalid', 'messages' => [$message]];
        };

        if ($invoice['number'] === '') {
            return $invalid('There is no invoice number.');
        }

        $clientName = $first('client_name');

        if ($clientName === '') {
            return $invalid('There is no client name.');
        }

        $type = ClientImport::normalise($first('document_type'));

        if ($type !== '' && ! in_array($type, ['invoice', 'taxinvoice', 'salesinvoice', 'faktura'], true)) {
            return $invalid('This is a '.Str::lower($first('document_type')).", not an invoice, so it isn't imported.");
        }

        $issueText = $first('issue_date');
        $issueDate = self::date($issueText);

        if ($issueDate === null) {
            return $invalid($issueText === '' ? 'There is no issue date.' : "\"{$issueText}\" isn't a date we can read.");
        }

        $dueText = $first('due_date');
        $dueDate = self::date($dueText);

        if ($dueDate === null) {
            $dueDate = $issueDate->addDays($paymentTermsDays);
            $messages[] = $dueText === ''
                ? "There's no due date, so it's set to {$paymentTermsDays} days after the issue date."
                : "\"{$dueText}\" isn't a date we can read, so the due date is {$paymentTermsDays} days after the issue date.";
        }

        if ($dueDate->lessThan($issueDate)) {
            $dueDate = $issueDate;
        }

        [$items, $itemMessages] = $this->buildItems($group, $columns, $invoice['number'], $first);
        $messages = [...$messages, ...$itemMessages];

        if ($items === null) {
            return $invalid($itemMessages[0] ?? 'There are no amounts for this invoice.');
        }

        $subtotal = array_sum(array_column($items, 'net_amount'));
        $taxTotal = array_sum(array_column($items, 'tax_amount'));
        $total = $subtotal + $taxTotal;
        $fileTotal = self::money($first('total'));

        if ($fileTotal !== null && abs($fileTotal - $total) > count($items)) {
            $messages[] = 'The lines add up to '.Money::format($total).' but the file says '.Money::format($fileTotal).'. Check the invoice after importing.';
        }

        [$payments, $paymentMessages] = $this->payments($first, $total, $issueDate);
        [$status, $paid, $statusMessages] = $this->status($first('status'), $payments === null ? $first('amount_paid') : '', $total, $payments);
        $messages = [...$messages, ...$paymentMessages, ...$statusMessages];

        if ($status === 'void' || ($status === 'draft' && $paid === 0)) {
            $payments = [];
        } elseif ($payments === null) {
            // A paid status or "yes" with no amounts: one payment for what was paid.
            $payments = $paid > 0 ? [[
                'amount' => $paid,
                'paid_on' => (self::date(explode(';', $first('paid_date'))[0]) ?? $issueDate)->toDateString(),
                'method' => self::paymentMethod($first('payment_method')),
                'reference' => self::paymentReference($first('payment_reference'), $first('payment_note')),
            ]] : [];
        }

        $paidDate = $payments === [] ? null : end($payments)['paid_on'];

        if (($discount = $first('discount')) !== '' && (self::number($discount) ?? 0) > 0) {
            $notes = trim($first('notes')."\nDiscount in the old app: {$discount} (already taken off the total).");
        }

        $currency = self::currency($first('currency')) ?? $defaultCurrency;

        $client = array_filter([
            'name' => Str::limit($clientName, 255, ''),
            'email' => self::email($first('client_email')),
            'address_line_1' => Str::limit($first('client_address_line_1'), 255, '') ?: null,
            'address_line_2' => Str::limit($first('client_address_line_2'), 255, '') ?: null,
            'country' => preg_match('/^[A-Za-z]{2}$/', $first('client_country')) ? Str::upper($first('client_country')) : null,
            'city' => Str::limit($first('client_city'), 255, '') ?: null,
            'postcode' => Str::upper(Str::limit($first('client_postcode'), 20, '')) ?: null,
            'vat_number' => Str::limit($first('client_vat_number'), 30, '') ?: null,
        ]);

        return [
            ...$invoice,
            'status' => $messages === [] ? 'ready' : 'warning',
            'messages' => $messages,
            'client' => $client,
            'issue_date' => $issueDate->toDateString(),
            'due_date' => $dueDate->toDateString(),
            'reference' => Str::limit($first('reference'), 255, '') ?: null,
            // Billdu fills its "Order number" with the invoice number when no order number was given.
            'po_number' => ($poNumber = Str::limit($first('po_number'), 100, '')) !== '' && $poNumber !== $first('number') ? $poNumber : null,
            'notes' => Str::limit($notes ?? $first('notes'), 5000, '') ?: null,
            'currency' => $currency,
            'invoice_status' => $status,
            'amount_paid' => array_sum(array_column($payments, 'amount')),
            'paid_date' => $paidDate,
            'payments' => $payments,
            'subtotal' => $subtotal,
            'tax_total' => $taxTotal,
            'total' => $total,
            'items' => $items,
        ];
    }

    /**
     * The invoice's lines: one per row when the file has line items, otherwise a
     * single line for the invoice's totals. Null when there's nothing to import.
     *
     * @param  list<array{row: int, cells: list<string>}>  $group
     * @param  array<string, int>  $columns
     * @return array{0: list<array<string, mixed>>|null, 1: list<string>}
     */
    private function buildItems(array $group, array $columns, string $number, callable $first): array
    {
        $messages = [];
        $hasLines = (bool) array_intersect(['description', 'unit_price', 'line_net', 'line_total'], array_keys($columns));
        $invoiceNet = self::money($first('subtotal'));
        $invoiceVat = self::money($first('vat_total'));
        $invoiceTotal = self::money($first('total'));

        foreach ([$invoiceNet, $invoiceVat, $invoiceTotal] as $amount) {
            if ($amount !== null && $amount < 0) {
                return [null, ['This looks like a credit note (a negative amount). Credit notes can\'t be imported yet.']];
            }
        }

        // A rate for the whole invoice, for lines that don't say their own.
        $invoiceNetForRate = $invoiceNet ?? ($invoiceTotal !== null && $invoiceVat !== null ? $invoiceTotal - $invoiceVat : null);
        $invoiceRate = $invoiceNetForRate && $invoiceVat !== null ? self::snapRate($invoiceVat / $invoiceNetForRate * 100) : null;

        if ($hasLines) {
            $items = [];

            foreach ($group as $line) {
                $cell = fn (string $field) => isset($columns[$field]) ? self::clean($line['cells'][$columns[$field]] ?? '') : '';

                $description = $cell('description');
                $quantity = self::number($cell('quantity')) ?? 1.0;
                $unitPrice = self::money($cell('unit_price'));
                $net = self::money($cell('line_net'));
                $vat = self::money($cell('line_vat'));
                $gross = self::money($cell('line_total'));
                $rate = self::rate($cell('vat_rate'));

                if ($description === '' && $unitPrice === null && $net === null && $gross === null) {
                    continue; // A row with no line on it, such as a totals-only row.
                }

                foreach ([$unitPrice, $net, $vat, $gross, $quantity] as $amount) {
                    if ($amount !== null && $amount < 0) {
                        return [null, ["Row {$line['row']} has a negative amount. Credit notes and discounts below zero can't be imported yet."]];
                    }
                }

                $rate ??= $net && $vat !== null ? self::snapRate($vat / $net * 100) : null;
                $rate ??= $net && $gross !== null ? self::snapRate(($gross - $net) / $net * 100) : null;
                $rate ??= $invoiceRate ?? 0.0;

                $net ??= $unitPrice !== null ? (int) round($unitPrice * $quantity) : null;
                $net ??= $gross !== null ? (int) round($gross / (1 + $rate / 100)) : null;
                $net ??= 0;
                $vat ??= $gross !== null ? max(0, $gross - $net) : (int) round($net * $rate / 100);
                $unitPrice ??= $quantity > 0 ? (int) round($net / $quantity) : $net;

                $items[] = [
                    'description' => Str::limit($description !== '' ? $description : "Invoice {$number}", 1000, ''),
                    'quantity' => round($quantity, 2),
                    'unit' => Str::limit($cell('unit'), 255, '') ?: null,
                    'unit_price' => $unitPrice,
                    'tax_rate' => $rate,
                    'net_amount' => $net,
                    'tax_amount' => $vat,
                ];
            }

            if ($items !== []) {
                return [$items, $messages];
            }
        }

        // One line for the invoice's totals.
        $net = $invoiceNet ?? ($invoiceTotal !== null ? $invoiceTotal - ($invoiceVat ?? 0) : null);

        if ($net === null) {
            return [null, ['There are no amounts for this invoice. Match a column to a total or to the line prices.']];
        }

        $vat = $invoiceVat ?? ($invoiceTotal !== null && $invoiceNet !== null ? max(0, $invoiceTotal - $invoiceNet) : 0);
        $rate = $net > 0 ? self::snapRate($vat / $net * 100) : 0.0;

        return [[[
            'description' => "Invoice {$number}",
            'quantity' => 1.0,
            'unit' => null,
            'unit_price' => $net,
            'tax_rate' => $rate,
            'net_amount' => $net,
            'tax_amount' => $vat,
        ]], $messages];
    }

    /**
     * Work out the invoice's status and how much has been paid.
     *
     * @return array{0: string, 1: int, 2: list<string>}
     */
    /**
     * @param  list<array{amount: int}>|null  $payments  Payments read from the file, if it lists them.
     */
    private function status(string $statusText, string $paidText, int $total, ?array $payments = null): array
    {
        $key = ClientImport::normalise($statusText);
        $messages = [];

        $status = match (true) {
            $key === '' => null,
            Str::contains($key, ['cancel', 'void', 'storno', 'credited', 'writtenoff']) => 'void',
            Str::contains($key, ['draft', 'concept']) => 'draft',
            Str::contains($key, ['partial', 'partly']) => 'partial',
            Str::contains($key, ['unpaid', 'notpaid', 'overdue', 'outstanding', 'open', 'due', 'sent', 'issued', 'pending', 'awaiting']) => 'sent',
            Str::contains($key, ['paid', 'settled', 'closed', 'complete']) || in_array($key, ['yes', 'y', 'true', '1'], true) => 'paid',
            default => 'unknown',
        };

        $paidKey = ClientImport::normalise($paidText);
        $paid = $payments !== null ? array_sum(array_column($payments, 'amount')) : match (true) {
            in_array($paidKey, ['yes', 'y', 'true', 'paid'], true) => $total,
            in_array($paidKey, ['no', 'n', 'false', 'unpaid'], true) => 0,
            default => self::money($paidText),
        };

        if ($status === 'unknown') {
            $messages[] = "\"{$statusText}\" isn't a status we recognise, so the invoice is imported as unpaid.";
            $status = 'sent';
        }

        if ($status === 'void') {
            return ['void', 0, $messages];
        }

        if ($status === 'draft' && ! $paid) {
            return ['draft', 0, $messages];
        }

        $paid ??= $status === 'paid' ? $total : 0;

        if ($paid > $total) {
            $messages[] = 'The amount paid is more than the total, so the invoice is imported as paid in full.';
            $paid = $total;
        }

        return [$paid >= $total && $total > 0 ? 'paid' : 'sent', max(0, $paid), $messages];
    }

    /**
     * The payments the file lists for an invoice, or null when it only says "paid" or
     * "yes". Billdu puts several payments in one cell, separated by semicolons, with
     * the dates, methods, references and notes in matching order.
     *
     * Payments that add up to more than the total are cut back to the total, since
     * an overpayment can't be recorded (Billdu sometimes lists a payment twice).
     *
     * @return array{0: list<array{amount: int, paid_on: string, method: string, reference: ?string}>|null, 1: list<string>}
     */
    private function payments(callable $first, int $total, CarbonImmutable $issueDate): array
    {
        $amountText = $first('amount_paid');
        $amounts = explode(';', $amountText);

        if ($amountText === '' || (count($amounts) === 1 && self::money($amountText) === null)) {
            return [null, []];
        }

        $dates = explode(';', $first('paid_date'));
        $methods = explode(';', $first('payment_method'));
        $references = explode(';', $first('payment_reference'));
        $notes = explode(';', $first('payment_note'));
        $payments = [];
        $messages = [];
        $left = $total;

        foreach ($amounts as $index => $text) {
            $amount = self::money($text);

            if ($amount === null || $amount <= 0) {
                continue;
            }

            if ($amount > $left) {
                $messages[] = 'The payments add up to more than the total, so only '.Money::format($total).' is recorded as paid.';
                $amount = $left;
            }

            if ($amount <= 0) {
                break;
            }

            $dateText = trim($dates[$index] ?? '');
            $date = self::date($dateText);

            if ($date === null && $dateText !== '') {
                $messages[] = "\"{$dateText}\" isn't a date we can read, so that payment is dated the issue date.";
            }

            $payments[] = [
                'amount' => $amount,
                'paid_on' => ($date ?? $issueDate)->toDateString(),
                // A single method applies to every payment.
                'method' => self::paymentMethod($methods[$index] ?? $methods[0]),
                'reference' => self::paymentReference($references[$index] ?? '', $notes[$index] ?? ''),
            ];
            $left -= $amount;
        }

        return [$payments, array_values(array_unique($messages))];
    }

    private static function paymentMethod(string $value): string
    {
        $key = ClientImport::normalise($value);

        foreach (self::PAYMENT_METHODS as $method => $words) {
            if ($key !== '' && Str::contains($key, $words)) {
                return $method;
            }
        }

        return 'other';
    }

    private static function paymentReference(string $reference, string $note): ?string
    {
        $text = implode(' · ', array_filter([self::clean($reference), self::clean($note)], fn ($part) => $part !== ''));

        return $text === '' ? null : Str::limit($text, 255, '');
    }

    /**
     * Read a date, taking day before month as UK and European apps write them.
     */
    public static function date(string $value): ?CarbonImmutable
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);

        if ($value === '') {
            return null;
        }

        // An ISO date, possibly with a time after it.
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $value, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? CarbonImmutable::create((int) $m[1], (int) $m[2], (int) $m[3]) : null;
        }

        // 31/12/2025, 31.12.2025, 31-12-25 or 31. 12. 2025, possibly with a time after it.
        if (preg_match('/^(\d{1,2})\s?[.\/-]\s?(\d{1,2})\s?[.\/-]\s?(\d{2}|\d{4})\b/', $value, $m)) {
            $year = strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3];

            return checkdate((int) $m[2], (int) $m[1], $year) ? CarbonImmutable::create($year, (int) $m[2], (int) $m[1]) : null;
        }

        // 12 Mar 2025 or March 12, 2025.
        if (preg_match('/[a-z]{3}/i', $value)) {
            try {
                return CarbonImmutable::parse($value)->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * Read an amount of money into pence: "£1,234.50", "1 234,50" or "1234.5".
     */
    public static function money(string $value): ?int
    {
        $number = self::number($value);

        return $number === null ? null : (int) round($number * 100);
    }

    /**
     * Read a number written with either a decimal point or a decimal comma.
     */
    public static function number(string $value): ?float
    {
        $value = trim($value);
        $negative = str_starts_with($value, '-') || (str_starts_with($value, '(') && str_ends_with($value, ')'));
        $value = preg_replace('/[^\d.,]/u', '', $value) ?? '';

        if ($value === '' || ! preg_match('/\d/', $value)) {
            return null;
        }

        $lastDot = strrpos($value, '.');
        $lastComma = strrpos($value, ',');

        if ($lastDot !== false && $lastComma !== false) {
            // Whichever comes last is the decimal separator.
            $decimal = $lastDot > $lastComma ? '.' : ',';
            $value = str_replace($decimal === '.' ? ',' : '.', '', $value);
            $value = str_replace(',', '.', $value);
        } elseif ($lastComma !== false) {
            // "12,50" is twelve pounds fifty; "1,250" is one thousand two hundred and fifty.
            $value = preg_match('/,\d{1,2}$/', $value) && substr_count($value, ',') === 1
                ? str_replace(',', '.', $value)
                : str_replace(',', '', $value);
        } elseif (substr_count($value, '.') > 1) {
            $value = str_replace('.', '', $value);
        }

        if (! is_numeric($value)) {
            return null;
        }

        return $negative ? -(float) $value : (float) $value;
    }

    /**
     * Read a VAT rate like "20", "20%", "20.00 %" or "0.2".
     */
    private static function rate(string $value): ?float
    {
        $number = self::number($value);

        if ($number === null || $number < 0) {
            return null;
        }

        if ($number > 0 && $number < 1 && ! str_contains($value, '%')) {
            $number *= 100;
        }

        return $number > 100 ? null : self::snapRate($number);
    }

    private static function snapRate(float $rate): float
    {
        foreach (self::STANDARD_RATES as $standard) {
            if (abs($rate - $standard) < 0.15) {
                return (float) $standard;
            }
        }

        return round(min(max($rate, 0), 100), 2);
    }

    private static function currency(string $value): ?string
    {
        $value = trim($value);

        if (isset(self::CURRENCY_SYMBOLS[$value])) {
            return self::CURRENCY_SYMBOLS[$value];
        }

        return preg_match('/^[A-Za-z]{3}$/', $value) ? Str::upper($value) : null;
    }

    private static function email(string $value): ?string
    {
        foreach (preg_split('/[\s,;]+/', Str::lower($value), -1, PREG_SPLIT_NO_EMPTY) as $part) {
            if (filter_var($part, FILTER_VALIDATE_EMAIL)) {
                return Str::limit($part, 255, '');
            }
        }

        return null;
    }

    private static function clean(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function fieldOptions(): array
    {
        return Arr::map(array_keys(self::FIELDS), fn ($field) => ['value' => $field, 'label' => self::FIELDS[$field]]);
    }
}
