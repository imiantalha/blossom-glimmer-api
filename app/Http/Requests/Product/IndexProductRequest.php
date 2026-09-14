<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;

class IndexProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string'],
            'type' => ['nullable', 'in:simple,variable'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', 'in:id,name,created_at,updated_at,base_price'],
            'direction' => ['nullable', 'in:asc,desc'],
        ];
    }
}
