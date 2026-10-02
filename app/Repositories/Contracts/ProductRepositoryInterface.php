<?php

namespace App\Repositories\Contracts;

use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface ProductRepositoryInterface
{
    public function paginate(array $filters): LengthAwarePaginator;

    public function find(Product $product): Product;

    public function findById(int $productId): ?Product;

    public function create(array $data): Product;

    public function update(Product $product, array $data): Product;

    public function delete(Product $product): bool;

    public function search(
        string $query,
        ?float $minPrice = null,
        ?float $maxPrice = null,
        ?string $status = null,
        ?string $brand = null,
        ?string $category = null
    ): Collection;
}
