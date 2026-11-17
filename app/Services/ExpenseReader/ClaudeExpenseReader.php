<?php

namespace App\Services\ExpenseReader;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIException;
use App\Enums\ExpenseCategory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads supplier invoices and receipts with Claude.
 */
class ClaudeExpenseReader implements ExpenseReader
{
    /**
     * File types Claude can read. HEIC photos can still be attached, just not read.
     */
    public const MIME_TYPES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    private const PROMPT = <<<'TEXT'
        This is a supplier invoice, bill or receipt that a UK small business has paid or needs to pay.
        Read it and fill in the expense details for their bookkeeping.

        - supplier: the business that issued it (not the customer it's addressed to).
        - reference: the supplier's invoice, receipt or order number.
        - date: the invoice or receipt date as YYYY-MM-DD. UK documents write dates day first, so 03/04/2026 is 3 April 2026.
        - total: the amount payable including VAT, as a plain number with two decimal places (for example 120.00), with no currency symbol.
        - vat_amount: the VAT included in the total, in the same format. Use 0.00 if the document shows no VAT.
        - category: the category that best fits what was bought, or "other" if none fits.
        - description: a few words on what was bought, like "Timber and fixings" or "Monthly software subscription".

        Use null for anything the document doesn't show or you can't read clearly. Don't guess amounts.
        TEXT;

    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $model,
        private readonly array $requestOptions = [],
    ) {}

    public function available(): bool
    {
        return filled($this->apiKey);
    }

    public function read(string $contents, string $mimeType): array
    {
        if (! $this->available()) {
            throw new ExpenseReaderFailed('Reading documents is not set up.');
        }

        if (! in_array($mimeType, self::MIME_TYPES, true)) {
            throw new ExpenseReaderFailed('This type of file can\'t be read. Try a PDF, JPG or PNG.');
        }

        $source = ['type' => 'base64', 'mediaType' => $mimeType, 'data' => base64_encode($contents)];
        $document = $mimeType === 'application/pdf'
            ? ['type' => 'document', 'source' => $source]
            : ['type' => 'image', 'source' => $source];

        try {
            $client = new Client(apiKey: $this->apiKey, requestOptions: ['timeout' => 60, ...$this->requestOptions]);

            $message = $client->beta->messages->create(
                maxTokens: 16000,
                model: $this->model,
                messages: [[
                    'role' => 'user',
                    'content' => [$document, ['type' => 'text', 'text' => self::PROMPT]],
                ]],
                outputConfig: ['effort' => 'medium', 'format' => ['type' => 'json_schema', 'schema' => $this->schema()]],
                // If the model declines, let the API retry on its default fallback model.
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );
        } catch (APIException $e) {
            Log::warning('Reading an expense document failed', ['error' => $e->getMessage()]);

            throw new ExpenseReaderFailed('The document couldn\'t be read right now. Please fill in the details yourself.', previous: $e);
        }

        if ($message->stopReason !== 'end_turn') {
            throw new ExpenseReaderFailed('The document couldn\'t be read. Please fill in the details yourself.');
        }

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                return $this->clean((array) json_decode($block->text, true));
            }
        }

        throw new ExpenseReaderFailed('The document couldn\'t be read. Please fill in the details yourself.');
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        $nullableString = ['type' => ['string', 'null']];

        return [
            'type' => 'object',
            'properties' => [
                'supplier' => $nullableString,
                'reference' => $nullableString,
                'date' => $nullableString,
                'total' => $nullableString,
                'vat_amount' => $nullableString,
                'category' => ['type' => 'string', 'enum' => array_column(ExpenseCategory::cases(), 'value')],
                'description' => $nullableString,
            ],
            'required' => ['supplier', 'reference', 'date', 'total', 'vat_amount', 'category', 'description'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Keep only values the expense form will accept.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function clean(array $data): array
    {
        $text = fn (string $key, int $max) => is_string($data[$key] ?? null) && trim($data[$key]) !== ''
            ? mb_substr(trim($data[$key]), 0, $max)
            : null;
        $money = function (string $key) use ($data) {
            $value = str_replace([',', '£', ' '], '', (string) ($data[$key] ?? ''));

            return is_numeric($value) && (float) $value >= 0 ? number_format((float) $value, 2, '.', '') : null;
        };
        $date = function () use ($data) {
            try {
                return is_string($data['date'] ?? null) ? Carbon::createFromFormat('!Y-m-d', $data['date'])->toDateString() : null;
            } catch (Throwable) {
                return null;
            }
        };

        $fields = [
            'supplier' => $text('supplier', 255),
            'reference' => $text('reference', 100),
            'date' => $date(),
            'total' => $money('total'),
            'vat_amount' => $money('vat_amount'),
            'category' => ExpenseCategory::tryFrom((string) ($data['category'] ?? ''))?->value,
            'description' => $text('description', 255),
        ];

        if ($fields['total'] !== null && $fields['vat_amount'] !== null && (float) $fields['vat_amount'] > (float) $fields['total']) {
            $fields['vat_amount'] = null;
        }

        return array_filter($fields, fn ($value) => $value !== null);
    }
}
