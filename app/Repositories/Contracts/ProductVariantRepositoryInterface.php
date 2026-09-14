<?php

namespace App\Repositories\Contracts;

use App\Models\ProductVariant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ProductVariantRepositoryInterface
{
    public function paginate(int $productId, array $filters): LengthAwarePaginator;

    public function find(ProductVariant $variant): ProductVariant;

    public function create(array $data): ProductVariant;

    public function update(ProductVariant $variant, array $data): ProductVariant;

    public function delete(ProductVariant $variant): bool;
}
