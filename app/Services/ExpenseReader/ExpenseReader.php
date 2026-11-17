<?php

namespace App\Services\ExpenseReader;

/**
 * Reads a supplier invoice or receipt and suggests the expense's details.
 *
 * Kept behind an interface so tests can fake it without calling an AI model.
 */
interface ExpenseReader
{
    /**
     * Whether reading documents is set up (for example, an API key is configured).
     */
    public function available(): bool;

    /**
     * Suggest the expense's details from the document. Anything the reader
     * couldn't find is left out.
     *
     * @return array{supplier?: string, reference?: string, date?: string, category?: string, description?: string, total?: string, vat_amount?: string}
     *
     * @throws ExpenseReaderFailed
     */
    public function read(string $contents, string $mimeType): array;
}
