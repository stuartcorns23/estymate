<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class BusinessRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'lowercase', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'website' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'county' => ['nullable', 'string', 'max:255'],
            'postcode' => ['nullable', 'string', 'max:20'],
            // UK VAT numbers: GB followed by 9 or 12 digits, spaces allowed.
            'vat_number' => ['nullable', 'string', 'regex:/^(GB)?\s*\d{3}\s*\d{4}\s*\d{2}(\s*\d{3})?$/i'],
            // Companies House numbers: 8 characters, optionally with a 2-letter prefix such as SC or NI.
            'company_number' => ['nullable', 'string', 'regex:/^([A-Z]{2}\d{6}|\d{8})$/i'],
            'estimate_prefix' => ['sometimes', 'string', 'max:20'],
            'invoice_prefix' => ['sometimes', 'string', 'max:20'],
            'proforma_prefix' => ['sometimes', 'string', 'max:20'],
            'creditnote_prefix' => ['sometimes', 'string', 'max:20'],
            'deliverynote_prefix' => ['sometimes', 'string', 'max:20'],
            'payment_terms_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'payment_details' => ['nullable', 'string', 'max:2000'],
            'send_reminders' => ['sometimes', 'boolean'],
            // Bank details, shown in the footer of estimates and invoices.
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account_name' => ['nullable', 'string', 'max:255'],
            'bank_sort_code' => ['nullable', 'string', 'regex:/^\d{2}[-\s]?\d{2}[-\s]?\d{2}$/'],
            'bank_account_number' => ['nullable', 'string', 'regex:/^\d{6,10}$/'],
            'bank_iban' => ['nullable', 'string', 'regex:/^[A-Z]{2}\d{2}[A-Z0-9 ]{10,32}$/i'],
            'bank_bic' => ['nullable', 'string', 'regex:/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/i'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'vat_number.regex' => 'Enter a valid UK VAT number, like GB123456789.',
            'company_number.regex' => 'Enter a valid Companies House number, like 01234567 or SC123456.',
            'bank_sort_code.regex' => 'Enter a sort code like 60-83-71.',
            'bank_account_number.regex' => 'Enter the account number as digits only, like 12345678.',
            'bank_iban.regex' => 'Enter a valid IBAN, like GB03SRLG60837112814925.',
            'bank_bic.regex' => 'Enter a valid BIC or SWIFT code, like SRLGGB2L.',
        ];
    }
}
