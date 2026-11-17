<?php

namespace App\Http\Requests;

use App\Enums\ExpenseCategory;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ExpenseRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'supplier' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:100'],
            'date' => ['required', 'date'],
            'category' => ['required', Rule::enum(ExpenseCategory::class)],
            'description' => ['nullable', 'string', 'max:255'],
            'total' => ['required', 'numeric', 'min:0', 'max:10000000', 'decimal:0,2'],
            'vat_amount' => ['nullable', 'numeric', 'min:0', 'max:10000000', 'decimal:0,2'],
            'paid_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp,heic', 'max:10240'],
            'remove_attachment' => ['nullable', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (! $validator->errors()->has('total') && (float) $this->input('vat_amount') > (float) $this->input('total')) {
                    $validator->errors()->add('vat_amount', 'The VAT can\'t be more than the total.');
                }
            },
        ];
    }

    /**
     * The validated data with money in pence, ready to save.
     *
     * @return array<string, mixed>
     */
    public function expenseData(): array
    {
        return [
            ...$this->safe()->except(['attachment', 'remove_attachment']),
            'total' => Money::toPence($this->validated('total')),
            'vat_amount' => Money::toPence($this->validated('vat_amount') ?? 0),
        ];
    }
}
