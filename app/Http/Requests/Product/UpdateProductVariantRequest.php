<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $variantId = $this->route('variant')?->id ?? $this->route('variant');

        return [
            'sku' => ['sometimes', 'string', 'max:255', Rule::unique('product_variants', 'sku')->ignore($variantId)],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:255'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'compare_at_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'cost_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'status' => ['sometimes', 'in:active,inactive'],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'option_values' => ['sometimes', 'array', 'min:1'],
            'option_values.*.product_option_id' => ['required_with:option_values', 'integer', 'exists:product_options,id'],
            'option_values.*.attribute_value_id' => ['required_with:option_values', 'integer', 'exists:attribute_values,id'],
        ];
    }
}
