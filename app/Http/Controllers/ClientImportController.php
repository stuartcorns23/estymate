<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Support\ClientImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Import clients from a CSV: pick a file, check the columns and rows, then import.
 *
 * The browser reads the file and sends its text, so there's no upload in the temp
 * folder to go wrong (uploads have failed there on Windows before).
 */
class ClientImportController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('clients/import', [
            'fields' => ClientImport::fieldOptions(),
            'maxBytes' => ClientImport::MAX_BYTES,
        ]);
    }

    /**
     * Read the CSV and say what importing it would do, without saving anything.
     */
    public function preview(Request $request): JsonResponse
    {
        $request->validate($this->rules(mappingRequired: false));

        $import = ClientImport::fromCsv($request->string('csv')->value());
        $mapping = $this->mapping($request, $import);
        $business = $request->user()->currentBusiness;
        $plan = $business->plan();
        $limit = $plan->limit('clients');

        return response()->json([
            'headers' => $import->headers,
            'format' => $import->format(),
            'mapping' => $mapping,
            'samples' => $import->samples(),
            'rows' => $import->analyse($mapping, Client::all()),
            'plan' => [
                'name' => $plan->name,
                'limit' => $limit,
                'remaining' => $limit === null ? null : max(0, $limit - $business->planUsage()['clients']),
            ],
        ]);
    }

    /**
     * Import the ticked rows, up to what the business's plan allows.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            ...$this->rules(mappingRequired: true),
            'rows' => ['required', 'array', 'min:1'],
            'rows.*' => ['integer'],
        ], ['rows.required' => 'Tick at least one client to import.']);

        $import = ClientImport::fromCsv($request->string('csv')->value());
        $mapping = $this->mapping($request, $import);
        $chosen = array_flip(array_map('intval', $request->input('rows')));
        $business = $request->user()->currentBusiness;
        $plan = $business->plan();

        [$imported, $overLimit] = DB::transaction(function () use ($import, $mapping, $chosen, $business, $plan) {
            // Re-check the rows here rather than trusting the preview: the clients may have changed since.
            $rows = collect($import->analyse($mapping, Client::all()))
                ->filter(fn ($row) => isset($chosen[$row['row']]) && $row['status'] !== 'invalid')
                ->values();

            $limit = $plan->limit('clients');
            $remaining = $limit === null ? $rows->count() : max(0, $limit - $business->planUsage()['clients']);

            foreach ($rows->take($remaining) as $row) {
                Client::create($row['attributes']);
            }

            return [min($remaining, $rows->count()), max(0, $rows->count() - $remaining)];
        });

        $status = $imported === 1 ? 'Imported 1 client.' : "Imported {$imported} clients.";

        if ($overLimit > 0) {
            $status .= " {$overLimit} more ".($overLimit === 1 ? 'was' : 'were')." left out because the {$plan->name} plan includes {$plan->limit('clients')} clients. Upgrade to import the rest.";
        }

        return to_route('clients.index')->with('status', $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $mappingRequired): array
    {
        return [
            'csv' => ['required', 'string', 'max:'.ClientImport::MAX_BYTES],
            'mapping' => [$mappingRequired ? 'required' : 'nullable', 'array'],
            'mapping.*' => ['nullable', Rule::in(array_keys(ClientImport::FIELDS))],
        ];
    }

    /**
     * The mapping the person chose, or our best guess when they haven't chosen yet.
     *
     * @return list<string|null>
     */
    private function mapping(Request $request, ClientImport $import): array
    {
        $mapping = $request->input('mapping');

        if (! is_array($mapping) || count($mapping) !== count($import->headers)) {
            return $import->guessMapping();
        }

        return array_map(fn ($field) => $field ?: null, array_values($mapping));
    }
}
