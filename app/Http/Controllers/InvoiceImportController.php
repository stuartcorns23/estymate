<?php

namespace App\Http\Controllers;

use App\Billing\Plan;
use App\Console\Commands\SendInvoiceReminders;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\PaymentMethod;
use App\Models\Business;
use App\Models\Client;
use App\Models\Document;
use App\Models\Payment;
use App\Support\InvoiceImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Import invoices from a CSV, such as a Billdu export: pick a file, check the
 * columns and invoices, then import them with their clients and payments.
 *
 * Like the client import, the browser reads the file and sends its text, so
 * there's no upload in the temp folder.
 */
class InvoiceImportController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('documents/import', [
            'fields' => InvoiceImport::fieldOptions(),
            'maxBytes' => InvoiceImport::MAX_BYTES,
        ]);
    }

    /**
     * Read the CSV and say what importing it would do, without saving anything.
     */
    public function preview(Request $request): JsonResponse
    {
        $request->validate($this->rules(mappingRequired: false));

        $import = InvoiceImport::fromCsv($request->string('csv')->value());
        $mapping = $this->mapping($request, $import);
        $business = $request->user()->currentBusiness;
        $plan = $business->plan();

        return response()->json([
            'headers' => $import->headers,
            'format' => $import->format(),
            'mapping' => $mapping,
            'samples' => $import->samples(),
            'invoices' => array_map($this->summary(...), $this->analyse($import, $mapping, $business)),
            'plan' => [
                'name' => $plan->name,
                'invoice_limit' => $plan->limit('invoices_per_month'),
                'invoices_remaining' => $this->remaining($business, 'invoices_per_month'),
                'client_limit' => $plan->limit('clients'),
                'clients_remaining' => $this->remaining($business, 'clients'),
            ],
        ]);
    }

    /**
     * Import the ticked invoices, up to what the business's plan allows.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            ...$this->rules(mappingRequired: true),
            'invoices' => ['required', 'array', 'min:1'],
            'invoices.*' => ['string'],
            'reminders' => ['boolean'],
        ], ['invoices.required' => 'Tick at least one invoice to import.']);

        $import = InvoiceImport::fromCsv($request->string('csv')->value());
        $mapping = $this->mapping($request, $import);
        $chosen = array_flip($request->input('invoices'));
        $reminders = $request->boolean('reminders');
        $business = $request->user()->currentBusiness;
        $plan = $business->plan();

        $result = DB::transaction(function () use ($import, $mapping, $chosen, $reminders, $business) {
            // Re-check the invoices here rather than trusting the preview: things may have changed since.
            $invoices = collect($this->analyse($import, $mapping, $business))
                ->filter(fn ($invoice) => isset($chosen[$invoice['key']]) && in_array($invoice['status'], ['ready', 'warning'], true))
                ->values();

            $invoicesLeft = $this->remaining($business, 'invoices_per_month');
            $clientsLeft = $this->remaining($business, 'clients');
            $createdClients = [];
            $imported = [];
            $result = ['invoices' => 0, 'clients' => 0, 'over_invoice_limit' => 0, 'over_client_limit' => 0];

            foreach ($invoices as $invoice) {
                if ($invoicesLeft !== null && $invoicesLeft <= 0) {
                    $result['over_invoice_limit']++;

                    continue;
                }

                $clientId = $invoice['client_id'] ?? $createdClients[$invoice['new_client']] ?? null;

                if ($clientId === null) {
                    if ($clientsLeft !== null && $clientsLeft <= 0) {
                        $result['over_client_limit']++;

                        continue;
                    }

                    $clientId = Client::create([...$invoice['client'], 'country' => 'GB'])->id;
                    $createdClients[$invoice['new_client']] = $clientId;
                    $clientsLeft = $clientsLeft === null ? null : $clientsLeft - 1;
                    $result['clients']++;
                }

                $this->createInvoice($invoice, $clientId, $reminders);
                $imported[] = $invoice['number'];
                $invoicesLeft = $invoicesLeft === null ? null : $invoicesLeft - 1;
                $result['invoices']++;
            }

            $this->moveNumberingOn($business, $imported);

            return $result;
        });

        return to_route('invoices.index')->with('status', $this->message($result, $plan));
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    private function createInvoice(array $invoice, int $clientId, bool $reminders): void
    {
        $status = match ($invoice['invoice_status']) {
            'draft' => DocumentStatus::Draft,
            'void' => DocumentStatus::Void,
            default => DocumentStatus::Sent,
        };

        $document = new Document;
        $document->forceFill([
            'client_id' => $clientId,
            'type' => DocumentType::Invoice,
            'status' => $status,
            'number' => $invoice['number'],
            'issue_date' => $invoice['issue_date'],
            'due_date' => $invoice['due_date'],
            'reference' => $invoice['reference'],
            'notes' => $invoice['notes'],
            'currency' => $invoice['currency'],
            'subtotal' => $invoice['subtotal'],
            'tax_total' => $invoice['tax_total'],
            'total' => $invoice['total'],
            'sent_at' => $status === DocumentStatus::Draft ? null : $invoice['issue_date'],
            // Old unpaid invoices don't start emailing clients unless the person asks for that.
            'reminders_sent' => $reminders ? 0 : SendInvoiceReminders::MAX_REMINDERS,
        ])->save();

        foreach ($invoice['items'] as $position => $item) {
            $document->items()->create([...$item, 'position' => $position]);
        }

        if ($invoice['amount_paid'] > 0) {
            $document->payments()->save(new Payment([
                'amount' => $invoice['amount_paid'],
                'paid_on' => $invoice['paid_date'],
                'method' => PaymentMethod::Other,
                'reference' => 'Imported',
            ]));
            $document->refreshPayments();
        }
    }

    /**
     * After importing "INV-0042", new invoices carry on from "INV-0043" rather than clashing with it.
     *
     * @param  list<string>  $numbers
     */
    private function moveNumberingOn(Business $business, array $numbers): void
    {
        $prefix = preg_quote($business->invoice_prefix, '/');
        $highest = 0;

        foreach ($numbers as $number) {
            if (preg_match("/^{$prefix}(\d+)$/i", $number, $match)) {
                $highest = max($highest, (int) $match[1]);
            }
        }

        Business::whereKey($business->id)->where('next_invoice_number', '<=', $highest)->update(['next_invoice_number' => $highest + 1]);
    }

    /**
     * @param  list<string|null>  $mapping
     * @return list<array<string, mixed>>
     */
    private function analyse(InvoiceImport $import, array $mapping, Business $business): array
    {
        $numbers = Document::withTrashed()->ofType(DocumentType::Invoice)->pluck('number')->all();

        return $import->analyse($mapping, Client::all(), $numbers, $business->currency ?? 'GBP', (int) ($business->payment_terms_days ?? 30));
    }

    /**
     * What the preview table needs for an invoice.
     *
     * @param  array<string, mixed>  $invoice
     * @return array<string, mixed>
     */
    private function summary(array $invoice): array
    {
        return [
            'key' => $invoice['key'],
            'rows' => $invoice['rows'],
            'status' => $invoice['status'],
            'messages' => $invoice['messages'],
            'number' => $invoice['number'] ?: null,
            'client' => $invoice['client_label'] ?? ($invoice['client']['name'] ?? null),
            'new_client' => ($invoice['new_client'] ?? null) !== null,
            'new_client_key' => $invoice['new_client'] ?? null,
            'issue_date' => $invoice['issue_date'] ?? null,
            'due_date' => $invoice['due_date'] ?? null,
            'invoice_status' => $invoice['invoice_status'] ?? null,
            'currency' => $invoice['currency'] ?? null,
            'total' => $invoice['total'] ?? null,
            'tax_total' => $invoice['tax_total'] ?? null,
            'amount_paid' => $invoice['amount_paid'] ?? null,
            'lines' => count($invoice['items'] ?? []),
        ];
    }

    /**
     * How many more of a resource the plan allows, or null for no limit.
     */
    private function remaining(Business $business, string $resource): ?int
    {
        $limit = $business->plan()->limit($resource);

        return $limit === null ? null : max(0, $limit - $business->planUsage()[$resource]);
    }

    /**
     * @param  array{invoices: int, clients: int, over_invoice_limit: int, over_client_limit: int}  $result
     */
    private function message(array $result, Plan $plan): string
    {
        $message = $result['invoices'] === 1 ? 'Imported 1 invoice' : "Imported {$result['invoices']} invoices";

        if ($result['clients'] > 0) {
            $message .= $result['clients'] === 1 ? ' and added 1 new client' : " and added {$result['clients']} new clients";
        }

        $message .= '.';

        if ($result['over_invoice_limit'] > 0) {
            $count = $result['over_invoice_limit'];
            $message .= " {$count} more ".($count === 1 ? 'was' : 'were')." left out because the {$plan->name} plan includes {$plan->limit('invoices_per_month')} invoices a month.";
        }

        if ($result['over_client_limit'] > 0) {
            $count = $result['over_client_limit'];
            $message .= " {$count} ".($count === 1 ? 'was' : 'were')." left out because their clients would go over the {$plan->name} plan's {$plan->limit('clients')} clients.";
        }

        if ($result['over_invoice_limit'] + $result['over_client_limit'] > 0) {
            $message .= ' Upgrade to import the rest.';
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $mappingRequired): array
    {
        return [
            'csv' => ['required', 'string', 'max:'.InvoiceImport::MAX_BYTES],
            'mapping' => [$mappingRequired ? 'required' : 'nullable', 'array'],
            'mapping.*' => ['nullable', Rule::in(array_keys(InvoiceImport::FIELDS))],
        ];
    }

    /**
     * The mapping the person chose, or our best guess when they haven't chosen yet.
     *
     * @return list<string|null>
     */
    private function mapping(Request $request, InvoiceImport $import): array
    {
        $mapping = $request->input('mapping');

        if (! is_array($mapping) || count($mapping) !== count($import->headers)) {
            return $import->guessMapping();
        }

        return array_map(fn ($field) => $field ?: null, array_values($mapping));
    }
}
