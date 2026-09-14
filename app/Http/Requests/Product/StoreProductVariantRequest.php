<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sku' => ['required', 'string', 'max:255', 'unique:product_variants,sku'],
            'barcode' => ['nullable', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'compare_at_price' => ['nullable', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'status' => ['required', 'in:active,inactive'],
            'metadata' => ['nullable', 'array'],
            'option_values' => ['required', 'array', 'min:1'],
            'option_values.*.product_option_id' => ['required', 'integer', 'exists:product_options,id'],
            'option_values.*.attribute_value_id' => ['required', 'integer', 'exists:attribute_values,id'],
        ];
    }
}
