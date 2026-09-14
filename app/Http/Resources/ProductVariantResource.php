<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductVariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'name' => $this->name,
            'price' => $this->price,
            'compare_at_price' => $this->compare_at_price,
            'cost_price' => $this->cost_price,
            'status' => $this->status,
            'metadata' => $this->metadata,
            'combination_key' => $this->combination_key,
            'product' => $this->whenLoaded('product'),
            'options' => $this->whenLoaded('options'),
            'attribute_values' => $this->whenLoaded('attributeValues'),
            'inventory_items' => $this->whenLoaded('inventoryItems'),
            'media' => $this->whenLoaded('media'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
