<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DocumentRequest extends FormRequest
{
    /**
     * Normalise VAT rates like "20" to "20.00" so they match the stored rates.
     */
    protected function prepareForValidation(): void
    {
        $items = $this->input('items');

        if (! is_array($items)) {
            return;
        }

        $this->merge([
            'items' => array_map(function ($item) {
                if (is_array($item) && is_numeric($item['tax_rate'] ?? null)) {
                    $item['tax_rate'] = number_format((float) $item['tax_rate'], 2, '.', '');
                }

                return $item;
            }, $items),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $business = $this->user()->currentBusiness;

        return [
            'client_id' => [
                'required',
                Rule::exists('clients', 'id')->where('business_id', $business->id)->whereNull('deleted_at'),
            ],
            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => [
                'nullable',
                Rule::exists('products', 'id')->where('business_id', $business->id),
            ],
            'items.*.description' => ['required', 'string', 'max:1000'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:1000000', 'decimal:0,2'],
            'items.*.unit' => ['nullable', 'string', 'max:30'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:10000000', 'decimal:0,2'],
            'items.*.tax_rate' => [
                'required',
                Rule::in($business->taxRates()->pluck('rate')->map(fn ($rate) => (string) $rate)->all()),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'Add at least one line.',
            'items.*.description.required' => 'Enter a description.',
            'items.*.quantity.gt' => 'Quantity must be more than 0.',
            'items.*.tax_rate.in' => 'Choose one of your VAT rates.',
        ];
    }
}
