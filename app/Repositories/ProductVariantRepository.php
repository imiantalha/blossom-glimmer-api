<?php

namespace App\Repositories;

use App\Models\ProductVariant;
use App\Repositories\Contracts\ProductVariantRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ProductVariantRepository implements ProductVariantRepositoryInterface
{
    public function paginate(int $productId, array $filters): LengthAwarePaginator
    {
        return ProductVariant::query()
            ->where('product_id', $productId)
            ->with(['options.attribute', 'attributeValues', 'inventoryItems'])
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%");
                });
            })
            ->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function find(ProductVariant $variant): ProductVariant
    {
        return $variant->load(['product', 'options.attribute', 'attributeValues', 'inventoryItems', 'media']);
    }

    public function create(array $data): ProductVariant
    {
        return ProductVariant::create($data);
    }

    public function update(ProductVariant $variant, array $data): ProductVariant
    {
        $variant->update($data);

        return $variant->refresh();
    }

    public function delete(ProductVariant $variant): bool
    {
        return (bool) $variant->delete();
    }
}
