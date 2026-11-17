<?php

namespace App\Services\Assistant;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\ExpenseCategory;
use App\Models\AssistantConversation;
use App\Models\AssistantInvoiceDraft;
use App\Models\Business;
use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentItem;
use App\Models\Expense;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * The tools the assistant can use. Every one reads or prepares data for one business only.
 *
 * Queries always start from the business passed in, never from ids the model supplies alone,
 * so the assistant can't see or touch another business's data.
 */
class AssistantTools
{
    private const LIST_LIMIT = 50;

    public function __construct(
        private readonly Business $business,
        private readonly AssistantConversation $conversation,
    ) {}

    /**
     * Tool definitions for the Claude API.
     *
     * Optional fields are left out of "required" rather than made nullable, so the
     * model never sends nulls (the SDK drops nulls, which would change the replayed history).
     *
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        $date = ['type' => 'string', 'description' => 'A date as YYYY-MM-DD.'];
        $limit = ['type' => 'integer', 'description' => 'How many to list, up to '.self::LIST_LIMIT.'. Defaults to 20.'];

        return [
            [
                'name' => 'search_invoices',
                'description' => 'Find the business\'s invoices and get their totals. Returns matching invoices (newest first) plus the count and totals of every match, '
                    .'including invoices not listed. Statuses: draft (not sent yet), unpaid (sent, not fully paid), overdue (unpaid and past the due date), paid, void.',
                'strict' => true,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'string', 'enum' => ['any', 'draft', 'unpaid', 'overdue', 'paid', 'void']],
                        'client' => ['type' => 'string', 'description' => 'Part of a client\'s name.'],
                        'number' => ['type' => 'string', 'description' => 'Part of an invoice number or reference.'],
                        'issued_from' => $date,
                        'issued_to' => $date,
                        'limit' => $limit,
                    ],
                    'required' => ['status'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'get_invoice',
                'description' => 'Get one invoice in full, with its line items and payments.',
                'strict' => true,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'number' => ['type' => 'string', 'description' => 'The invoice number, like INV-0012.'],
                    ],
                    'required' => ['number'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'invoice_totals',
                'description' => 'Total up invoices issued in a period (drafts and void invoices excluded), grouped by month, client or status. '
                    .'Shows the amount invoiced, paid and still owed for each group.',
                'strict' => true,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'issued_from' => $date,
                        'issued_to' => $date,
                        'group_by' => ['type' => 'string', 'enum' => ['month', 'client', 'status']],
                    ],
                    'required' => ['issued_from', 'issued_to', 'group_by'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'search_expenses',
                'description' => 'Find the business\'s expenses (supplier bills and receipts it paid or has to pay), newest first, plus the count and totals of every match.',
                'strict' => true,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'from' => $date,
                        'to' => $date,
                        'category' => ['type' => 'string', 'enum' => array_column(ExpenseCategory::cases(), 'value')],
                        'search' => ['type' => 'string', 'description' => 'Part of the supplier, reference or description.'],
                        'limit' => $limit,
                    ],
                    'required' => [],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'expense_totals',
                'description' => 'Total up expenses dated in a period, grouped by category, supplier or month. Shows the total, VAT and net amount for each group.',
                'strict' => true,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'from' => $date,
                        'to' => $date,
                        'group_by' => ['type' => 'string', 'enum' => ['category', 'supplier', 'month']],
                        'search' => ['type' => 'string', 'description' => 'Only expenses whose supplier, reference or description contains this.'],
                    ],
                    'required' => ['from', 'to', 'group_by'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'find_clients',
                'description' => 'Look up clients by name or email, with each one\'s id and the amount they owe. Use this to get the client_id for prepare_invoice.',
                'strict' => true,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'search' => ['type' => 'string', 'description' => 'Part of the name or email. Leave out to list clients A to Z.'],
                    ],
                    'required' => [],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'list_products',
                'description' => 'Look up the business\'s saved products and services, with prices (excluding VAT) and VAT rates.',
                'strict' => true,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'search' => ['type' => 'string', 'description' => 'Part of the name or description.'],
                    ],
                    'required' => [],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'prepare_invoice',
                'description' => 'Prepare a draft invoice for the person to check. This does NOT create the invoice: it is shown to the person with a button to create it. '
                    .'Prices are per unit, in pounds, excluding VAT. vat_rate must be one of the business\'s VAT rates.',
                'strict' => true,
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'client_id' => ['type' => 'integer', 'description' => 'From find_clients.'],
                        'issue_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD. Defaults to today.'],
                        'due_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD. Defaults to the business\'s payment terms.'],
                        'reference' => ['type' => 'string', 'description' => 'A PO number or job reference, if the person gave one.'],
                        'notes' => ['type' => 'string', 'description' => 'Notes to print on the invoice. Defaults to the business\'s payment details.'],
                        'items' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'product_id' => ['type' => 'integer', 'description' => 'From list_products, when the line is a saved product.'],
                                    'description' => ['type' => 'string'],
                                    'quantity' => ['type' => 'string', 'description' => 'Like "1" or "2.5".'],
                                    'unit' => ['type' => 'string', 'description' => 'Like "hours" or "days".'],
                                    'unit_price' => ['type' => 'string', 'description' => 'Pounds excluding VAT, like "450.00".'],
                                    'vat_rate' => ['type' => 'string', 'description' => 'Like "20.00".'],
                                ],
                                'required' => ['description', 'quantity', 'unit_price', 'vat_rate'],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                    'required' => ['client_id', 'items'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /**
     * Run a tool and return its result for the model.
     *
     * @param  array<string, mixed>  $input
     * @return array{content: string, is_error: bool, draft?: AssistantInvoiceDraft}
     */
    public function run(string $name, array $input): array
    {
        try {
            $result = match ($name) {
                'search_invoices' => $this->searchInvoices($input),
                'get_invoice' => $this->getInvoice($input),
                'invoice_totals' => $this->invoiceTotals($input),
                'search_expenses' => $this->searchExpenses($input),
                'expense_totals' => $this->expenseTotals($input),
                'find_clients' => $this->findClients($input),
                'list_products' => $this->listProducts($input),
                'prepare_invoice' => $this->prepareInvoice($input),
                default => throw new ToolError("There is no tool called {$name}."),
            };
        } catch (ToolError $e) {
            return ['content' => $e->getMessage(), 'is_error' => true];
        }

        $draft = $result['_draft'] ?? null;
        unset($result['_draft']);

        return array_filter([
            'content' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'is_error' => false,
            'draft' => $draft,
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function searchInvoices(array $input): array
    {
        $query = $this->invoices()
            ->with('client:id,name')
            ->when($input['client'] ?? null, fn (Builder $query, string $client) => $query
                ->whereHas('client', fn (Builder $query) => $query->where('name', 'like', '%'.$this->like($client).'%')))
            ->when($input['number'] ?? null, fn (Builder $query, string $number) => $query->where(fn (Builder $query) => $query
                ->where('number', 'like', '%'.$this->like($number).'%')
                ->orWhere('reference', 'like', '%'.$this->like($number).'%')))
            ->when($this->date($input, 'issued_from'), fn (Builder $query, Carbon $from) => $query->whereDate('issue_date', '>=', $from))
            ->when($this->date($input, 'issued_to'), fn (Builder $query, Carbon $to) => $query->whereDate('issue_date', '<=', $to));

        match ($input['status'] ?? 'any') {
            'draft' => $query->where('status', DocumentStatus::Draft),
            'unpaid' => $query->where('status', DocumentStatus::Sent),
            'overdue' => $query->where('status', DocumentStatus::Sent)->whereDate('due_date', '<', today()),
            'paid' => $query->where('status', DocumentStatus::Paid),
            'void' => $query->where('status', DocumentStatus::Void),
            default => null,
        };

        $all = (clone $query)->get(['id', 'total', 'amount_paid']);
        $invoices = $query->latest('issue_date')->latest('id')->limit($this->limit($input))->get();

        return [
            'count' => $all->count(),
            'total' => $this->money((int) $all->sum('total')),
            'still_owed' => $this->money((int) $all->sum(fn (Document $invoice) => $invoice->balanceDue())),
            'listed' => $invoices->count(),
            'invoices' => $invoices->map(fn (Document $invoice) => $this->invoiceSummary($invoice))->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function getInvoice(array $input): array
    {
        $invoice = $this->invoices()
            ->with(['client', 'items', 'payments'])
            ->where('number', trim((string) ($input['number'] ?? '')))
            ->first();

        if (! $invoice) {
            throw new ToolError('No invoice has that number. Use search_invoices to find it.');
        }

        return [
            ...$this->invoiceSummary($invoice),
            'reference' => $invoice->reference,
            'client_email' => $invoice->client?->email,
            'subtotal' => $this->money($invoice->subtotal),
            'vat' => $this->money($invoice->tax_total),
            'amount_paid' => $this->money($invoice->amount_paid),
            'sent_at' => $invoice->sent_at?->toDateString(),
            'notes' => $invoice->notes,
            'items' => $invoice->items->map(fn ($item) => [
                'description' => $item->description,
                'quantity' => (string) $item->quantity,
                'unit' => $item->unit,
                'unit_price' => $this->money($item->unit_price),
                'vat_rate' => $item->tax_rate.'%',
                'net' => $this->money($item->net_amount),
            ])->all(),
            'payments' => $invoice->payments->map(fn ($payment) => [
                'amount' => $this->money($payment->amount),
                'paid_on' => $payment->paid_on->toDateString(),
                'method' => $payment->method->label(),
            ])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function invoiceTotals(array $input): array
    {
        [$from, $to] = $this->period($input, 'issued_from', 'issued_to');

        $invoices = $this->invoices()
            ->with('client:id,name')
            ->whereNotIn('status', [DocumentStatus::Draft, DocumentStatus::Void])
            ->whereDate('issue_date', '>=', $from)
            ->whereDate('issue_date', '<=', $to)
            ->get(['id', 'client_id', 'status', 'issue_date', 'due_date', 'total', 'amount_paid']);

        $groups = $invoices->groupBy(fn (Document $invoice) => match ($input['group_by'] ?? 'month') {
            'client' => $invoice->client?->name ?? 'Unknown client',
            'status' => $invoice->isOverdue() ? 'overdue' : ($invoice->status === DocumentStatus::Sent ? 'unpaid' : $invoice->status->value),
            default => $invoice->issue_date->format('Y-m'),
        });

        $summarise = fn (Collection $invoices) => [
            'invoices' => $invoices->count(),
            'invoiced' => $this->money((int) $invoices->sum('total')),
            'paid' => $this->money((int) $invoices->sum('amount_paid')),
            'still_owed' => $this->money((int) $invoices->sum(fn (Document $invoice) => $invoice->balanceDue())),
        ];

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'overall' => $summarise($invoices),
            'groups' => $groups->sortKeys()->map(fn (Collection $group, string $key) => ['group' => $key, ...$summarise($group)])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function searchExpenses(array $input): array
    {
        $query = $this->expenseQuery($input)
            ->when($input['category'] ?? null, fn (Builder $query, string $category) => $query->where('category', $category))
            ->when($this->date($input, 'from'), fn (Builder $query, Carbon $from) => $query->whereDate('date', '>=', $from))
            ->when($this->date($input, 'to'), fn (Builder $query, Carbon $to) => $query->whereDate('date', '<=', $to));

        $all = (clone $query)->get(['id', 'total', 'vat_amount']);
        $expenses = $query->latest('date')->latest('id')->limit($this->limit($input))->get();

        return [
            'count' => $all->count(),
            'total' => $this->money((int) $all->sum('total')),
            'vat' => $this->money((int) $all->sum('vat_amount')),
            'listed' => $expenses->count(),
            'expenses' => $expenses->map(fn (Expense $expense) => [
                'date' => $expense->date->toDateString(),
                'supplier' => $expense->supplier,
                'reference' => $expense->reference,
                'category' => $expense->category->label(),
                'description' => $expense->description,
                'total' => $this->money($expense->total),
                'vat' => $this->money($expense->vat_amount),
                'paid_on' => $expense->paid_on?->toDateString(),
            ])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function expenseTotals(array $input): array
    {
        [$from, $to] = $this->period($input, 'from', 'to');

        $expenses = $this->expenseQuery($input)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->get(['id', 'supplier', 'category', 'date', 'total', 'vat_amount']);

        $groups = $expenses->groupBy(fn (Expense $expense) => match ($input['group_by'] ?? 'category') {
            'supplier' => $expense->supplier,
            'month' => $expense->date->format('Y-m'),
            default => $expense->category->label(),
        });

        $summarise = fn (Collection $expenses) => [
            'expenses' => $expenses->count(),
            'total' => $this->money((int) $expenses->sum('total')),
            'vat' => $this->money((int) $expenses->sum('vat_amount')),
            'net' => $this->money((int) ($expenses->sum('total') - $expenses->sum('vat_amount'))),
            '_sort' => (int) $expenses->sum('total'),
        ];

        $groups = $groups->map(fn (Collection $group, string $key) => ['group' => $key, ...$summarise($group)]);
        $groups = ($input['group_by'] ?? null) === 'month' ? $groups->sortKeys() : $groups->sortByDesc('_sort');
        $overall = $summarise($expenses);
        unset($overall['_sort']);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'overall' => $overall,
            'groups' => $groups->take(self::LIST_LIMIT)->map(fn (array $group) => collect($group)->except('_sort')->all())->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function findClients(array $input): array
    {
        $search = trim((string) ($input['search'] ?? ''));

        $clients = $this->business->clients()
            ->when($search !== '', fn (Builder|HasMany $query) => $query->where(fn (Builder $query) => $query
                ->where('name', 'like', '%'.$this->like($search).'%')
                ->orWhere('email', 'like', '%'.$this->like($search).'%')))
            ->orderBy('name')
            ->limit(self::LIST_LIMIT)
            ->get(['id', 'name', 'email']);

        $owed = $this->invoices()
            ->where('status', DocumentStatus::Sent)
            ->whereIn('client_id', $clients->pluck('id'))
            ->get(['client_id', 'total', 'amount_paid'])
            ->groupBy('client_id')
            ->map(fn (Collection $invoices) => (int) $invoices->sum(fn (Document $invoice) => $invoice->balanceDue()));

        return [
            'clients' => $clients->map(fn (Client $client) => [
                'id' => $client->id,
                'name' => $client->name,
                'email' => $client->email,
                'owes' => $this->money($owed[$client->id] ?? 0),
            ])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function listProducts(array $input): array
    {
        $search = trim((string) ($input['search'] ?? ''));

        $products = Product::query()
            ->where('business_id', $this->business->id)
            ->with('taxRate:id,rate')
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('name', 'like', '%'.$this->like($search).'%')
                ->orWhere('description', 'like', '%'.$this->like($search).'%')))
            ->orderBy('name')
            ->limit(self::LIST_LIMIT)
            ->get();

        return [
            'vat_rates' => $this->vatRates(),
            'products' => $products->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'description' => $product->description,
                'unit' => $product->unit,
                'unit_price' => number_format($product->unit_price / 100, 2, '.', ''),
                'vat_rate' => $product->taxRate?->rate,
            ])->all(),
        ];
    }

    /**
     * Check the invoice the model wants and save it as a draft for the person to confirm.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function prepareInvoice(array $input): array
    {
        $data = [
            'client_id' => $input['client_id'] ?? null,
            'issue_date' => $input['issue_date'] ?? today()->toDateString(),
            'due_date' => $input['due_date'] ?? null,
            'reference' => $input['reference'] ?? null,
            'notes' => $input['notes'] ?? $this->business->payment_details,
            'items' => array_map(fn ($item) => [
                'product_id' => $item['product_id'] ?? null,
                'description' => $item['description'] ?? '',
                'quantity' => $item['quantity'] ?? '',
                'unit' => $item['unit'] ?? null,
                'unit_price' => $item['unit_price'] ?? '',
                'tax_rate' => is_numeric($item['vat_rate'] ?? null) ? number_format((float) $item['vat_rate'], 2, '.', '') : ($item['vat_rate'] ?? ''),
            ], is_array($input['items'] ?? null) ? $input['items'] : []),
        ];

        if (! isset($input['due_date']) && $this->date($data, 'issue_date')) {
            $data['due_date'] = $this->date($data, 'issue_date')->addDays($this->business->payment_terms_days)->toDateString();
        }

        $validator = Validator::make($data, self::invoiceRules($this->business));

        if ($validator->fails()) {
            throw new ToolError('The invoice can\'t be prepared: '.implode(' ', $validator->errors()->all()));
        }

        $plan = $this->business->plan();
        $limit = $plan->limit('invoices_per_month');
        $limitReached = $limit !== null && $this->business->planUsage()['invoices_per_month'] >= $limit;

        $draft = new AssistantInvoiceDraft(['data' => $validator->validated(), 'status' => AssistantInvoiceDraft::PENDING]);
        $draft->business_id = $this->business->id;
        $draft->conversation()->associate($this->conversation);
        $draft->save();

        $preview = self::preview($this->business, $draft);

        return [
            'result' => 'Prepared. The draft is now shown to the person with a "Create invoice" button. It has NOT been created yet; tell them to check it and confirm.',
            'client' => $preview['client_name'],
            'total' => $this->money($preview['total']),
            ...($limitReached ? ['plan_limit' => "The {$plan->name} plan allows {$limit} invoices a month and this month's are used up, so it can't be created until they upgrade. Tell the person."] : []),
            '_draft' => $draft,
        ];
    }

    /**
     * The same rules the invoice form uses.
     *
     * @return array<string, mixed>
     */
    public static function invoiceRules(Business $business): array
    {
        return [
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->where('business_id', $business->id)->whereNull('deleted_at')],
            'issue_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:issue_date'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('business_id', $business->id)],
            'items.*.description' => ['required', 'string', 'max:1000'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:1000000', 'decimal:0,2'],
            'items.*.unit' => ['nullable', 'string', 'max:30'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:10000000', 'decimal:0,2'],
            'items.*.tax_rate' => ['required', Rule::in($business->documentTaxRates()->pluck('rate')->map(fn ($rate) => (string) $rate)->all())],
        ];
    }

    /**
     * What the person sees on the confirmation card.
     *
     * @return array<string, mixed>
     */
    public static function preview(Business $business, AssistantInvoiceDraft $draft): array
    {
        $data = $draft->data;
        $subtotal = 0;
        $vat = 0;

        $items = array_map(function (array $item) use (&$subtotal, &$vat) {
            $amounts = DocumentItem::amountsFor($item['quantity'], Money::toPence($item['unit_price']), $item['tax_rate']);
            $subtotal += $amounts['net_amount'];
            $vat += $amounts['tax_amount'];

            return [
                'description' => $item['description'],
                'quantity' => (string) $item['quantity'],
                'unit' => $item['unit'] ?? null,
                'unit_price' => Money::toPence($item['unit_price']),
                'tax_rate' => (string) $item['tax_rate'],
                'net_amount' => $amounts['net_amount'],
            ];
        }, $data['items']);

        return [
            'id' => $draft->id,
            'status' => $draft->status,
            'client_name' => $business->clients()->withTrashed()->whereKey($data['client_id'])->value('name'),
            'issue_date' => $data['issue_date'],
            'due_date' => $data['due_date'] ?? null,
            'reference' => $data['reference'] ?? null,
            'items' => $items,
            'subtotal' => $subtotal,
            'tax_total' => $vat,
            'total' => $subtotal + $vat,
            'currency' => $business->currency,
            'document' => $draft->document ? ['number' => $draft->document->number, 'url' => route('invoices.show', $draft->document)] : null,
        ];
    }

    /**
     * @return Builder<Document>
     */
    private function invoices(): Builder
    {
        return Document::query()->where('business_id', $this->business->id)->ofType(DocumentType::Invoice);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return Builder<Expense>
     */
    private function expenseQuery(array $input): Builder
    {
        return Expense::query()
            ->where('business_id', $this->business->id)
            ->when(trim((string) ($input['search'] ?? '')), fn (Builder $query, string $search) => $query->where(fn (Builder $query) => $query
                ->where('supplier', 'like', '%'.$this->like($search).'%')
                ->orWhere('reference', 'like', '%'.$this->like($search).'%')
                ->orWhere('description', 'like', '%'.$this->like($search).'%')));
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceSummary(Document $invoice): array
    {
        return [
            'number' => $invoice->number,
            'client' => $invoice->client?->name,
            'status' => $invoice->isOverdue() ? 'overdue' : ($invoice->status === DocumentStatus::Sent ? 'unpaid' : $invoice->status->value),
            'issue_date' => $invoice->issue_date->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'total' => $this->money($invoice->total),
            'still_owed' => $this->money($invoice->balanceDue()),
        ];
    }

    /**
     * @return list<string>
     */
    private function vatRates(): array
    {
        return $this->business->documentTaxRates()->orderByDesc('rate')->pluck('rate')->map(fn ($rate) => (string) $rate)->all();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{Carbon, Carbon}
     */
    private function period(array $input, string $fromKey, string $toKey): array
    {
        $from = $this->date($input, $fromKey) ?? throw new ToolError("{$fromKey} must be a date like 2026-04-01.");
        $to = $this->date($input, $toKey) ?? throw new ToolError("{$toKey} must be a date like 2026-06-30.");

        if ($to->isBefore($from)) {
            throw new ToolError("{$toKey} must be on or after {$fromKey}.");
        }

        return [$from, $to];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function date(array $input, string $key): ?Carbon
    {
        try {
            return is_string($input[$key] ?? null) ? Carbon::createFromFormat('!Y-m-d', $input[$key]) : null;
        } catch (Throwable) {
            throw new ToolError("{$key} must be a date like 2026-04-01.");
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function limit(array $input): int
    {
        return max(1, min(self::LIST_LIMIT, (int) ($input['limit'] ?? 20)));
    }

    private function money(int $pence): string
    {
        return Money::format($pence, $this->business->currency);
    }

    /**
     * Escape LIKE wildcards in text the model sends.
     */
    private function like(string $value): string
    {
        return addcslashes(trim($value), '%_\\');
    }
}
