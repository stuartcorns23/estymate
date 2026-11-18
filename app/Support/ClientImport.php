<?php

namespace App\Support;

use App\Models\Client;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Turns a CSV of clients into rows ready to import, checking each one first.
 *
 * The CSV arrives as text (the browser reads the file), so nothing is written to
 * disk or the temp folder. Each column is mapped to a client field, to the notes,
 * or left out. A field can take several columns: the first one with a value wins
 * and the others are kept in the notes, so a Billdu "Cell" fills in for a missing
 * "Phone" without losing either number.
 */
class ClientImport
{
    public const MAX_ROWS = 2000;

    public const MAX_BYTES = 2_000_000;

    /**
     * Client fields a column can be mapped to, with their labels and maximum lengths.
     */
    public const FIELDS = [
        'name' => ['label' => 'Client or business name', 'max' => 255],
        'contact_name' => ['label' => 'Contact name', 'max' => 255],
        'email' => ['label' => 'Email', 'max' => 255],
        'phone' => ['label' => 'Phone', 'max' => 50],
        'address_line_1' => ['label' => 'Address line 1', 'max' => 255],
        'address_line_2' => ['label' => 'Address line 2', 'max' => 255],
        'city' => ['label' => 'Town or city', 'max' => 255],
        'county' => ['label' => 'County', 'max' => 255],
        'postcode' => ['label' => 'Postcode', 'max' => 20],
        'country' => ['label' => 'Country', 'max' => 2],
        'vat_number' => ['label' => 'VAT number', 'max' => 30],
        'default_po_number' => ['label' => 'PO number', 'max' => 100],
        'notes' => ['label' => 'Add to notes', 'max' => 5000],
    ];

    /**
     * Column headings we recognise, normalised to lowercase letters and digits.
     */
    private const ALIASES = [
        'name' => ['businessname', 'name', 'company', 'companyname', 'client', 'clientname', 'customer', 'customername', 'organisation', 'organization', 'organisationname', 'organizationname', 'business'],
        'contact_name' => ['contactperson', 'contact', 'contactname', 'fullname', 'person'],
        'email' => ['emails', 'email', 'emailaddress', 'emailaddresses', 'mail'],
        'phone' => ['phone', 'telephone', 'tel', 'phonenumber', 'telephonenumber', 'cell', 'mobile', 'mobilenumber', 'mobilephone', 'cellphone'],
        'address_line_1' => ['street', 'address', 'address1', 'addressline1', 'streetaddress', 'billingaddress', 'billingstreet'],
        'address_line_2' => ['address2', 'addressline2', 'street2', 'billingstreet2'],
        'city' => ['city', 'town', 'towncity', 'billingcity'],
        'county' => ['county', 'state', 'region', 'province', 'billingstate'],
        'postcode' => ['zipcode', 'zip', 'postcode', 'postalcode', 'billingpostcode', 'billingzip'],
        'country' => ['countrycode', 'country', 'billingcountry'],
        'vat_number' => ['vatregno', 'vatnumber', 'vat', 'vatno', 'vatregistrationnumber', 'vatid'],
        'default_po_number' => ['ponumber', 'pono', 'po', 'purchaseorder', 'purchaseordernumber', 'purchaseorderno'],
        'notes' => ['note', 'notes', 'comment', 'comments', 'description', 'website', 'web', 'url', 'fax', 'id', 'taxid', 'companynumber', 'companyregno', 'registrationnumber', 'crn'],
    ];

    private const COUNTRY_NAMES = [
        'uk' => 'GB', 'unitedkingdom' => 'GB', 'greatbritain' => 'GB', 'britain' => 'GB', 'england' => 'GB',
        'scotland' => 'GB', 'wales' => 'GB', 'northernireland' => 'GB', 'ireland' => 'IE', 'republicofireland' => 'IE',
        'eire' => 'IE', 'france' => 'FR', 'germany' => 'DE', 'spain' => 'ES', 'netherlands' => 'NL',
        'unitedstates' => 'US', 'usa' => 'US',
    ];

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    public function __construct(public readonly array $headers, public readonly array $rows) {}

    /**
     * Read CSV text, working out whether it uses commas, semicolons or tabs.
     *
     * @throws ValidationException
     */
    public static function fromCsv(string $text): self
    {
        return new self(...CsvReader::read($text, self::MAX_ROWS, 'clients'));
    }

    /**
     * The name of the app the CSV was exported from, when we recognise it.
     */
    public function format(): ?string
    {
        $headers = array_map(self::normalise(...), $this->headers);

        if (in_array('businessname', $headers) && in_array('vatregno', $headers) && in_array('emails', $headers)) {
            return 'Billdu';
        }

        return null;
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

        foreach ($this->headers as $header) {
            $key = self::normalise($header);
            $field = collect(self::ALIASES)->search(fn ($aliases) => in_array($key, $aliases, true));

            // Only one column is guessed as the name; another "name" column could be the contact.
            if ($field === 'name' && in_array('name', $mapping, true)) {
                $field = in_array('contact_name', $mapping, true) ? false : 'contact_name';
            }

            $mapping[] = $field === false ? null : $field;
        }

        return $mapping;
    }

    /**
     * Check every row against the mapping, the business's clients and the rows above it.
     *
     * Each row is "ready", "warning" (will import, with changes explained), "duplicate"
     * (left unticked to begin with) or "invalid" (can't be imported).
     *
     * @param  list<string|null>  $mapping
     * @param  Collection<int, Client>  $existing
     * @return list<array{row: int, status: string, attributes: array<string, string|null>, messages: list<string>}>
     */
    public function analyse(array $mapping, Collection $existing): array
    {
        $byName = $existing->keyBy(fn ($client) => self::nameKey($client->name));
        $byEmail = $existing->filter(fn ($client) => $client->email)->keyBy(fn ($client) => Str::lower($client->email));
        $seenNames = [];
        $seenEmails = [];
        $results = [];

        foreach ($this->rows as $index => $cells) {
            $row = $index + 2; // The row number in a spreadsheet, under the heading row.
            [$attributes, $messages] = $this->buildRow($cells, $mapping);
            $status = $messages === [] ? 'ready' : 'warning';

            if (($attributes['name'] ?? '') === '') {
                $status = 'invalid';
                $messages = ['There is no client or business name.'];
            } else {
                $nameKey = self::nameKey($attributes['name']);
                $email = $attributes['email'] ?? null;

                if ($match = $byName->get($nameKey) ?? ($email ? $byEmail->get($email) : null)) {
                    $status = 'duplicate';
                    array_unshift($messages, "Looks like {$match->name}, who is already in your clients.");
                } elseif ($earlier = $seenNames[$nameKey] ?? ($email ? $seenEmails[$email] ?? null : null)) {
                    $status = 'duplicate';
                    array_unshift($messages, "Looks like a repeat of row {$earlier}.");
                }

                $seenNames[$nameKey] ??= $row;

                if ($email) {
                    $seenEmails[$email] ??= $row;
                }
            }

            $results[] = ['row' => $row, 'status' => $status, 'attributes' => $attributes, 'messages' => $messages];
        }

        return $results;
    }

    /**
     * Turn one row's cells into client attributes, tidying values as we go.
     *
     * @param  list<string>  $cells
     * @param  list<string|null>  $mapping
     * @return array{0: array<string, string|null>, 1: list<string>}
     */
    private function buildRow(array $cells, array $mapping): array
    {
        $attributes = [];
        $notes = [];
        $extras = [];
        $messages = [];

        foreach ($mapping as $column => $field) {
            $value = self::clean($cells[$column] ?? '');

            if ($field === null || $value === '') {
                continue;
            }

            $label = $this->headers[$column] ?: 'Column '.($column + 1);

            if ($field === 'notes') {
                $notes[] = self::isNoteColumn($label) ? $value : "{$label}: {$value}";

                continue;
            }

            if ($field === 'email') {
                [$value, $other, $message] = self::email($value);

                if ($other !== null) {
                    $extras[] = "{$label}: {$other}";
                }

                if ($message !== null) {
                    $messages[] = $message;
                }

                if ($value === null) {
                    continue;
                }
            }

            if ($field === 'phone' && strlen(preg_replace('/\D/', '', $value)) < 10) {
                $messages[] = "The phone number \"{$value}\" looks incomplete, so it's kept in the notes.";
                $extras[] = "{$label}: {$value}";

                continue;
            }

            if ($field === 'country') {
                $code = self::country($value);

                if ($code === null) {
                    $messages[] = "\"{$value}\" isn't a country we recognise, so it's set to the United Kingdom.";
                    $extras[] = "{$label}: {$value}";
                    $code = 'GB';
                }

                $value = $code;
            }

            if (isset($attributes[$field])) {
                // The field already has a value from an earlier column, so keep this one in the notes.
                if ($attributes[$field] !== $value) {
                    $extras[] = "{$label}: {$value}";
                }

                continue;
            }

            $max = self::FIELDS[$field]['max'];

            if (mb_strlen($value) > $max) {
                $messages[] = self::FIELDS[$field]['label']." was longer than {$max} characters, so the full text is kept in the notes.";
                $extras[] = "{$label}: {$value}";
                $value = mb_substr($value, 0, $max);
            }

            $attributes[$field] = $value;
        }

        if (isset($attributes['postcode']) && ($attributes['country'] ?? 'GB') === 'GB') {
            $attributes['postcode'] = Str::upper($attributes['postcode']);
        }

        $notes = implode("\n", array_unique([...$notes, ...$extras]));

        if ($notes !== '') {
            $attributes['notes'] = mb_substr($notes, 0, self::FIELDS['notes']['max']);
        }

        return [$attributes, $messages];
    }

    /**
     * The first valid email address in a cell, any others, and a note if it wasn't valid.
     *
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    private static function email(string $value): array
    {
        $parts = preg_split('/[\s,;]+/', Str::lower($value), -1, PREG_SPLIT_NO_EMPTY);
        $valid = array_values(array_filter($parts, fn ($part) => filter_var($part, FILTER_VALIDATE_EMAIL)));

        if ($valid === []) {
            return [null, $value, "\"{$value}\" isn't a valid email address, so it's kept in the notes."];
        }

        $others = array_slice($valid, 1);

        return [$valid[0], $others ? implode(', ', $others) : null, null];
    }

    private static function country(string $value): ?string
    {
        if (preg_match('/^[A-Za-z]{2}$/', $value)) {
            return Str::upper($value) === 'UK' ? 'GB' : Str::upper($value);
        }

        return self::COUNTRY_NAMES[self::normalise($value)] ?? null;
    }

    /**
     * Tidy a value: single spaces, and no stray commas left over at the end of an address.
     */
    private static function clean(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value, " \t,");
    }

    private static function isNoteColumn(string $label): bool
    {
        return in_array(self::normalise($label), ['note', 'notes', 'comment', 'comments', 'description'], true);
    }

    /**
     * Lowercase letters and digits only, for matching headings.
     */
    public static function normalise(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($value))) ?? '';
    }

    /**
     * A business name reduced to what makes it unique, so "J&N Superfood Stores Ltd"
     * matches "J & N Super Food Stores".
     */
    public static function nameKey(string $name): string
    {
        $name = Str::lower(Str::ascii($name));
        $name = preg_replace('/\b(ltd|limited|plc|llp|llc|inc)\b\.?/', '', $name) ?? $name;
        $name = preg_replace('/^the\s+/', '', trim($name)) ?? $name;
        $name = str_replace(['&', '+'], 'and', $name);

        return preg_replace('/[^a-z0-9]/', '', $name) ?: Str::lower($name);
    }

    /**
     * The fields to offer for mapping, as {value, label} pairs.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function fieldOptions(): array
    {
        return Arr::map(array_keys(self::FIELDS), fn ($field) => ['value' => $field, 'label' => self::FIELDS[$field]['label']]);
    }
}
