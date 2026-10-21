<?php

namespace App\Http\Requests;

use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
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
            'description' => ['nullable', 'string', 'max:2000'],
            'unit_price' => ['required', 'numeric', 'min:0', 'max:10000000', 'decimal:0,2'],
            'unit' => ['nullable', 'string', 'max:30'],
            'tax_rate_id' => [
                'nullable',
                Rule::exists('tax_rates', 'id')->where('business_id', $this->user()->current_business_id),
            ],
        ];
    }

    /**
     * The validated data with the price in pence.
     *
     * @return array<string, mixed>
     */
    public function productData(): array
    {
        return [
            ...$this->validated(),
            'unit_price' => Money::toPence($this->validated('unit_price')),
        ];
    }
}
