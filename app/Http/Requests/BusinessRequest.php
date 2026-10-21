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
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'county' => ['nullable', 'string', 'max:255'],
            'postcode' => ['nullable', 'string', 'max:20'],
            // UK VAT numbers: GB followed by 9 or 12 digits, spaces allowed.
            'vat_number' => ['nullable', 'string', 'regex:/^(GB)?\s*\d{3}\s*\d{4}\s*\d{2}(\s*\d{3})?$/i'],
            // Companies House numbers: 8 characters, optionally with a 2-letter prefix such as SC or NI.
            'company_number' => ['nullable', 'string', 'regex:/^([A-Z]{2}\d{6}|\d{8})$/i'],
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
        ];
    }
}
