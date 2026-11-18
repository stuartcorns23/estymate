<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * Reads CSV text sent by the browser into a heading row and data rows.
 *
 * It works out whether the file uses commas, semicolons or tabs, removes a UTF-8
 * byte order mark, and converts older Excel (Windows-1252) files to UTF-8. It reads
 * from memory, so nothing is written to the temp folder.
 */
class CsvReader
{
    /**
     * @param  string  $noun  What each row is, for error messages, e.g. "clients".
     * @return array{0: list<string>, 1: list<list<string>>}
     *
     * @throws ValidationException
     */
    public static function read(string $text, int $maxRows, string $noun): array
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;

        if (trim($text) === '') {
            throw ValidationException::withMessages(['csv' => 'The file is empty.']);
        }

        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }

        $firstLine = strtok($text, "\r\n") ?: '';
        $delimiter = collect([',', ';', "\t"])->sortByDesc(fn ($d) => substr_count($firstLine, $d))->first();

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $text);
        rewind($stream);

        $headers = null;
        $rows = [];

        while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $cells = array_map(fn ($cell) => trim((string) $cell), $cells);

            if ($headers === null) {
                $headers = $cells;

                continue;
            }

            if (implode('', $cells) === '') {
                continue;
            }

            if (count($rows) >= $maxRows) {
                fclose($stream);

                throw ValidationException::withMessages(['csv' => 'The file has more than '.number_format($maxRows)." {$noun}. Split it into smaller files."]);
            }

            $rows[] = array_pad(array_slice($cells, 0, count($headers)), count($headers), '');
        }

        fclose($stream);

        if (! $headers || implode('', $headers) === '') {
            throw ValidationException::withMessages(['csv' => 'The file needs a heading row, such as "Name, Email, Phone".']);
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['csv' => "The file has headings but no {$noun}."]);
        }

        return [$headers, $rows];
    }
}
