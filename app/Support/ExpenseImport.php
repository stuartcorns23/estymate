<?php

namespace App\Support;

use App\Enums\ExpenseCategory;
use App\Models\Expense;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Turns a CSV of expenses (purchase invoices, bills and receipts) into expenses
 * ready to import, checking each one first.
 *
 * It follows the client and invoice imports: the browser sends the file's text, each
 * column is mapped to an expense field, and every row is checked before anything is
 * saved. Categories in the file (Billdu's own, or anything typed in a spreadsheet)
 * are matched to our fixed categories, and the original wording is kept in the notes
 * when it isn't an exact match.
 */
class ExpenseImport
{
    public const MAX_ROWS = 5000;

    public const MAX_BYTES = 4_000_000;

    /**
     * Expense fields a column can be mapped to.
     */
    public const FIELDS = [
        'supplier' => 'Supplier',
        'reference' => 'Supplier\'s invoice or receipt number',
        'date' => 'Date',
        'category' => 'Category',
        'description' => 'Description',
        'total' => 'Total (including VAT)',
        'net' => 'Amount before VAT',
        'vat_amount' => 'VAT amount',
        'vat_rate' => 'VAT rate',
        'currency' => 'Currency',
        'paid_on' => 'Date paid',
        'paid' => 'Paid? (yes / no or status)',
        'notes' => 'Add to notes',
    ];

    /**
     * Column headings we recognise, normalised to lowercase letters and digits.
     */
    private const ALIASES = [
        'supplier' => ['supplier', 'suppliername', 'vendor', 'vendorname', 'payee', 'merchant', 'company', 'companyname', 'from', 'seller', 'shop', 'store', 'client', 'partner', 'businessname'],
        'reference' => ['reference', 'ref', 'expensenumber', 'number', 'documentnumber', 'invoicenumber', 'invoiceno', 'billnumber', 'receiptnumber', 'receiptno', 'variablesymbol', 'supplierreference', 'suppliersinvoicenumber'],
        'date' => ['issuedate', 'created', 'createdon', 'datecreated', 'date', 'expensedate', 'invoicedate', 'receiptdate', 'billdate', 'dateissued', 'transactiondate', 'purchasedate'],
        'category' => ['category', 'expensecategory', 'type', 'expensetype', 'account', 'nominal', 'nominalcode', 'tag', 'tags'],
        'description' => ['description', 'item', 'items', 'name', 'title', 'subject', 'details', 'detail', 'expense', 'what', 'memo'],
        'total' => ['total', 'totalamount', 'amount', 'gross', 'grossamount', 'totalincvat', 'totalincludingvat', 'amountincvat', 'totalwithvat', 'grandtotal', 'sum', 'price'],
        'net' => ['net', 'netamount', 'subtotal', 'totalexvat', 'totalexcludingvat', 'amountexvat', 'totalwithoutvat', 'base', 'taxbase'],
        'vat_amount' => ['vat', 'vatamount', 'tax', 'taxamount', 'totalvat', 'vattotal', 'salestax'],
        'vat_rate' => ['vatrate', 'vatpercent', 'taxrate', 'rate'],
        'currency' => ['currency', 'currencycode'],
        'paid_on' => ['paymentdate', 'paidon', 'datepaid', 'paiddate', 'paymentdates'],
        'paid' => ['paid', 'status', 'paymentstatus', 'ispaid'],
        'notes' => ['note', 'notes', 'comment', 'comments', 'duedate', 'paymentmethod', 'paymentreference', 'paymentnote', 'ordernumber', 'vatregno', 'sumofpayment', 'business'],
    ];

    /**
     * Words in a category, description or supplier name that point to one of our categories.
     * The first match wins, so more specific words come before general ones. A trailing *
     * marks a stem that can start a longer word.
     */
    private const CATEGORY_WORDS = [
        'bank_charges' => ['bank', 'finance', 'interest', 'loan', 'stripe fee', 'paypal fee', 'card fee', 'merchant fee', 'charges'],
        'software' => ['software', 'subscription', 'saas', 'hosting', 'domain', 'licence', 'license', 'cloud', 'app', 'microsoft', 'adobe', 'google workspace', 'web services'],
        'vehicle' => ['vehicle', 'fuel', 'petrol', 'diesel', 'car', 'van', 'mileage', 'parking', 'mot', 'garage', 'tyre', 'congestion', 'motor'],
        'travel' => ['travel', 'train', 'rail', 'flight', 'taxi', 'uber', 'bus', 'hotel', 'accommodation', 'subsistence', 'meal', 'transport'],
        'premises' => ['rent', 'premises', 'utilities', 'utility', 'electric', 'gas', 'water', 'rates', 'office rent', 'heating', 'energy', 'property'],
        'insurance' => ['insurance', 'indemnity', 'liability'],
        'professional' => ['accountan*', 'legal', 'solicitor', 'lawyer', 'professional', 'consult*', 'bookkeep*', 'audit'],
        'marketing' => ['marketing', 'advertis*', 'promotion', 'ads', 'seo', 'social media', 'print', 'leaflet', 'business card', 'sponsor'],
        'subcontractors' => ['subcontract*', 'contractor', 'freelance*', 'outsourc*'],
        'staff' => ['staff', 'salary', 'salaries', 'wages', 'payroll', 'pension', 'employee'],
        'training' => ['training', 'course', 'education', 'conference', 'seminar', 'book'],
        'equipment' => ['equipment', 'hardware', 'computer', 'laptop', 'tools', 'machinery', 'furniture', 'asset', 'printer'],
        'office' => ['office', 'phone', 'telephone', 'mobile', 'broadband', 'internet', 'stationery', 'postage', 'post', 'courier', 'delivery'],
        'materials' => ['material*', 'stock', 'goods', 'supplies', 'purchase', 'resale', 'parts', 'cost of sales'],
    ];

    /**
     * Billdu's expense categories, where keywords alone would guess wrong.
     */
    private const KNOWN_CATEGORIES = [
        'licensespermits' => 'software',
        'webhosting' => 'software',
        'productsmaterials' => 'materials',
        'print' => 'materials',
        'booksandprint' => 'materials',
        'officesupplies' => 'office',
        'administration' => 'office',
        'postage' => 'office',
        'cellphone' => 'office',
        'hardware' => 'equipment',
        'repairs' => 'equipment',
        'maintenance' => 'equipment',
        'accounting' => 'professional',
        'courses' => 'training',
        'companies' => 'other',
        'other' => 'other',
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
        return new self(...CsvReader::read($text, self::MAX_ROWS, 'expenses'));
    }

    /**
     * The name of the app the CSV was exported from, when we recognise it.
     */
    public function format(): ?string
    {
        $headers = array_map(self::normalise(...), $this->headers);

        // Billdu's expense export: Category, Name, Business, VAT Reg. No., Created, Due date,
        // Reference, Currency, Paid, Total.
        if (array_diff(['category', 'name', 'business', 'created', 'paid', 'total'], $headers) === []) {
            return 'Billdu';
        }

        // Billdu's other document exports share headings like these.
        $billdu = array_intersect($headers, ['documenttype', 'createdby', 'sumofpayment', 'vatregno', 'issuedate']);

        return count($billdu) >= 2 ? 'Billdu' : null;
    }

    /**
     * The first value in each column, to help match it to a field.
     *
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
     * A best guess at which field each column belongs to, from its heading.
     *
     * @return list<string|null>
     */
    public function guessMapping(): array
    {
        $mapping = [];
        $keys = array_map(self::normalise(...), $this->headers);

        // Billdu has a number column and a separate free-text "Reference" (e.g. "Printing order"),
        // so when there's a number, the reference text is the description.
        $hasNumber = array_intersect($keys, ['expensenumber', 'invoicenumber', 'documentnumber', 'number', 'billnumber', 'receiptnumber']) !== [];
        $hasSupplier = collect($keys)->contains(fn ($key) => in_array($key, self::ALIASES['supplier'], true));

        foreach ($keys as $key) {
            $field = collect(self::ALIASES)->search(fn ($aliases) => in_array($key, $aliases, true));

            if ($key === 'reference' && $hasNumber) {
                $field = 'description';
            }

            // In Billdu's expense export, "Name" is who the expense is from (often with an order
            // number after a dash) and "Business" is usually the client it was bought for, so
            // that goes in the notes. Elsewhere a lone "Name" column is the supplier.
            if ($key === 'name' && ! $hasSupplier) {
                $field = 'supplier';
            }

            // Only the first column is guessed for each field (except notes), so a second
            // "date" or "amount" column doesn't quietly replace the first.
            if ($field !== false && $field !== 'notes' && in_array($field, $mapping, true)) {
                $field = 'notes';
            }

            $mapping[] = $field === false ? null : $field;
        }

        return $mapping;
    }

    /**
     * Check every row against the mapping and the business's expenses.
     *
     * Each row is "ready", "warning" (will import, with changes explained), "duplicate"
     * (left unticked to begin with) or "invalid" (can't be imported).
     *
     * @param  list<string|null>  $mapping
     * @param  Collection<int, Expense>  $existing
     * @return list<array{row: int, status: string, attributes: array<string, mixed>, messages: list<string>}>
     */
    public function analyse(array $mapping, Collection $existing, string $defaultCurrency): array
    {
        // Only expenses already saved count as duplicates. Rows in the same file are separate
        // records (Billdu exports often have two identical orders on one day).
        $known = $existing
            ->groupBy(fn (Expense $expense) => self::duplicateKey($expense->supplier, $expense->date->format('Y-m-d'), (int) $expense->total))
            ->map(fn ($expenses) => $expenses->map(fn (Expense $expense) => $expense->reference ? Str::lower(trim($expense->reference)) : null)->all());

        $results = [];

        foreach ($this->rows as $index => $cells) {
            $row = $index + 2; // The row number in a spreadsheet, under the heading row.
            [$attributes, $messages, $error] = $this->buildRow($cells, $mapping, $defaultCurrency);

            if ($error !== null) {
                $results[] = ['row' => $row, 'status' => 'invalid', 'attributes' => $attributes, 'messages' => [$error]];

                continue;
            }

            $status = $messages === [] ? 'ready' : 'warning';
            $references = $known->get(self::duplicateKey($attributes['supplier'], $attributes['date'], $attributes['total']), []);
            $reference = $attributes['reference'] ? Str::lower(trim($attributes['reference'])) : null;

            // Same supplier, date and total, and the references don't disagree.
            if ($references !== [] && ($reference === null || in_array(null, $references, true) || in_array($reference, $references, true))) {
                $status = 'duplicate';
                array_unshift($messages, 'Looks like an expense you already have.');
            }

            $results[] = ['row' => $row, 'status' => $status, 'attributes' => $attributes, 'messages' => $messages];
        }

        return $results;
    }

    /**
     * Turn one row's cells into expense attributes.
     *
     * @param  list<string>  $cells
     * @param  list<string|null>  $mapping
     * @return array{0: array<string, mixed>, 1: list<string>, 2: string|null}
     */
    private function buildRow(array $cells, array $mapping, string $defaultCurrency): array
    {
        $values = [];
        $notes = [];
        $messages = [];

        foreach ($mapping as $column => $field) {
            $value = self::clean($cells[$column] ?? '');

            if ($field === null || $value === '') {
                continue;
            }

            if ($field === 'notes') {
                $label = $this->headers[$column] ?: 'Column '.($column + 1);
                $notes[] = in_array(self::normalise($label), ['note', 'notes', 'comment', 'comments'], true) ? $value : "{$label}: {$value}";

                continue;
            }

            $values[$field] ??= $value;
        }

        $attributes = [
            'supplier' => Str::limit($values['supplier'] ?? '', 255, ''),
            'reference' => isset($values['reference']) ? Str::limit($values['reference'], 100, '') : null,
            'description' => isset($values['description']) ? Str::limit($values['description'], 255, '') : null,
        ];

        // "Pencarrie - DEYN122501" or "Kenable - Cables for a client": the supplier, then details.
        // A detail that looks like an order number ("DEYN122501") is the reference instead.
        if ($attributes['description'] === null && preg_match('/^(.+?)\s+-\s+(.+)$/u', $attributes['supplier'], $m)) {
            $attributes['supplier'] = $m[1];
            $detail = $m[2];

            if (! str_contains($detail, ' ') && preg_match('/\d/', $detail)) {
                $attributes['reference'] ??= Str::limit($detail, 100, '');
            }

            if (Str::lower($detail) !== Str::lower($attributes['reference'] ?? '')) {
                $attributes['description'] = Str::limit($detail, 255, '');
            }
        }

        if ($attributes['supplier'] === '') {
            return [$attributes, [], 'There is no supplier.'];
        }

        $date = InvoiceImport::date($values['date'] ?? '');

        if ($date === null) {
            return [$attributes, [], isset($values['date']) ? "\"{$values['date']}\" isn't a date we can read." : 'There is no date.'];
        }

        $attributes['date'] = $date->format('Y-m-d');

        // Amounts: the total, or the amount before VAT plus VAT.
        $total = isset($values['total']) ? InvoiceImport::money($values['total']) : null;
        $net = isset($values['net']) ? InvoiceImport::money($values['net']) : null;
        $vat = isset($values['vat_amount']) ? InvoiceImport::money($values['vat_amount']) : null;
        $rate = isset($values['vat_rate']) ? self::rate($values['vat_rate']) : null;

        if ($total === null && $net !== null) {
            $vat ??= $rate !== null ? (int) round($net * $rate / 100) : 0;
            $total = $net + $vat;
        }

        if ($total === null) {
            return [$attributes, [], isset($values['total']) ? "\"{$values['total']}\" isn't an amount we can read." : 'There is no amount.'];
        }

        if ($total < 0) {
            return [$attributes, [], 'The amount is negative. Refunds from suppliers can\'t be imported as expenses.'];
        }

        if ($total > 1_000_000_000) {
            return [$attributes, [], 'The amount is more than £10,000,000.'];
        }

        if ($vat === null && $net !== null) {
            $vat = $total - $net;
        }

        if ($vat === null && $rate !== null) {
            $vat = (int) round($total * $rate / (100 + $rate));
        }

        $vat ??= 0;

        if ($vat < 0 || $vat > $total) {
            $messages[] = 'The VAT didn\'t add up with the total, so it\'s set to none. Check it after importing.';
            $notes[] = 'VAT in file: '.($values['vat_amount'] ?? Money::format($vat));
            $vat = 0;
        }

        $attributes['total'] = $total;
        $attributes['vat_amount'] = $vat;

        // Category.
        [$category, $exact] = self::category($values['category'] ?? null, $attributes['description'], $attributes['supplier']);
        $attributes['category'] = $category->value;

        if (isset($values['category']) && ! $exact) {
            $notes[] = "Category in file: {$values['category']}";

            if ($category === ExpenseCategory::Other && ! isset(self::KNOWN_CATEGORIES[self::normalise($values['category'])])) {
                $messages[] = "\"{$values['category']}\" doesn't match one of our categories, so it's set to Other.";
            }
        }

        // Currency.
        $currency = isset($values['currency']) ? self::currency($values['currency']) : null;
        $attributes['currency'] = $currency ?? $defaultCurrency;

        if ($currency !== null && $currency !== $defaultCurrency) {
            $messages[] = "This expense is in {$currency}. The amount is kept in {$currency}, not converted to {$defaultCurrency}.";
        }

        // Paid. Billdu puts several payments in one cell, separated by semicolons; the last one settles it.
        $paidOn = null;

        if (isset($values['paid_on'])) {
            $dates = array_filter(array_map(fn ($part) => InvoiceImport::date($part), explode(';', $values['paid_on'])));
            $paidOn = $dates ? max($dates) : null;

            if ($paidOn === null) {
                $messages[] = "\"{$values['paid_on']}\" isn't a date we can read, so the expense is marked unpaid.";
            }
        } elseif (isset($values['paid']) && self::saysPaid($values['paid'])) {
            $paidOn = $date;
        }

        if ($paidOn instanceof CarbonImmutable && isset($values['paid']) && self::saysUnpaid($values['paid'])) {
            $paidOn = null;
        }

        $attributes['paid_on'] = $paidOn?->format('Y-m-d');

        $notes = implode("\n", array_unique($notes));
        $attributes['notes'] = $notes === '' ? null : mb_substr($notes, 0, 5000);

        return [$attributes, $messages, null];
    }

    /**
     * Match a category from the file to one of ours. When the file has no category, the
     * description and supplier are used instead. Returns whether it was an exact match.
     *
     * @return array{0: ExpenseCategory, 1: bool}
     */
    public static function category(?string $text, ?string $description = null, ?string $supplier = null): array
    {
        if ($text !== null && $text !== '') {
            $key = self::normalise($text);

            foreach (ExpenseCategory::cases() as $case) {
                if ($key === self::normalise($case->value) || $key === self::normalise($case->label())) {
                    return [$case, true];
                }
            }

            if (isset(self::KNOWN_CATEGORIES[$key])) {
                return [ExpenseCategory::from(self::KNOWN_CATEGORIES[$key]), false];
            }

            return [self::categoryFromWords($text) ?? ExpenseCategory::Other, false];
        }

        return [self::categoryFromWords(trim(($description ?? '').' '.($supplier ?? ''))) ?? ExpenseCategory::Other, false];
    }

    private static function categoryFromWords(string $text): ?ExpenseCategory
    {
        $text = ' '.Str::lower(Str::ascii($text)).' ';

        foreach (self::CATEGORY_WORDS as $category => $words) {
            foreach ($words as $word) {
                // Whole words (or their plurals), so "car" doesn't match "card" or "train" match
                // "training". A word ending in * is a stem: "accountan*" matches "accountancy".
                $pattern = str_ends_with($word, '*')
                    ? '/\b'.preg_quote(rtrim($word, '*'), '/').'/'
                    : '/\b'.preg_quote($word, '/').'s?\b/';

                if (preg_match($pattern, $text)) {
                    return ExpenseCategory::from($category);
                }
            }
        }

        return null;
    }

    private static function saysPaid(string $value): bool
    {
        return in_array(self::normalise($value), ['yes', 'y', 'true', '1', 'paid', 'settled', 'complete', 'completed', 'fullypaid'], true);
    }

    private static function saysUnpaid(string $value): bool
    {
        return in_array(self::normalise($value), ['no', 'n', 'false', '0', 'unpaid', 'notpaid', 'due', 'overdue', 'outstanding'], true);
    }

    private static function rate(string $value): ?float
    {
        $number = InvoiceImport::number($value);

        if ($number === null || $number < 0) {
            return null;
        }

        if ($number > 0 && $number < 1 && ! str_contains($value, '%')) {
            $number *= 100;
        }

        return $number > 100 ? null : $number;
    }

    private static function currency(string $value): ?string
    {
        $value = trim($value);

        if (isset(self::CURRENCY_SYMBOLS[$value])) {
            return self::CURRENCY_SYMBOLS[$value];
        }

        return preg_match('/^[A-Za-z]{3}$/', $value) ? Str::upper($value) : null;
    }

    private static function duplicateKey(string $supplier, string $date, int $total): string
    {
        return ClientImport::nameKey($supplier)."|{$date}|{$total}";
    }

    private static function clean(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    /**
     * Lowercase letters and digits only, for matching headings.
     */
    public static function normalise(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($value))) ?? '';
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function fieldOptions(): array
    {
        return Arr::map(array_keys(self::FIELDS), fn ($field) => ['value' => $field, 'label' => self::FIELDS[$field]]);
    }
}
