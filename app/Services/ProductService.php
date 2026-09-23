<?php

namespace App\Services;

use App\Models\Product;
use App\Repositories\Contracts\ProductRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Collection;

class ProductService
{
    public function __construct(
        protected ProductRepositoryInterface $productRepository
    ) {
    }

    public function paginate(array $filters): LengthAwarePaginator
    {
        return $this->productRepository->paginate($filters);
    }

    public function find(Product $product): Product
    {
        return $this->productRepository->find($product);
    }

    public function create(array $data): Product
    {
        return DB::transaction(function () use ($data) {
            $categoryIds = $data['category_ids'] ?? [];
            $tagIds = $data['tag_ids'] ?? [];

            unset($data['category_ids'], $data['tag_ids']);

            $product = $this->productRepository->create($data);
            $product->categories()->sync($categoryIds);
            $product->tags()->sync($tagIds);

            return $this->productRepository->find($product);
        });
    }

    public function update(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data) {
            if (array_key_exists('category_ids', $data)) {
                $product->categories()->sync($data['category_ids']);
                unset($data['category_ids']);
            }

            if (array_key_exists('tag_ids', $data)) {
                $product->tags()->sync($data['tag_ids']);
                unset($data['tag_ids']);
            }

            $product = $this->productRepository->update($product, $data);

            return $this->productRepository->find($product);
        });
    }

    public function delete(Product $product): bool
    {
        return DB::transaction(function () use ($product) {
            $product->categories()->detach();
            $product->tags()->detach();

            return $this->productRepository->delete($product);
        });
    }

    public function search(
        string $query,
        ?float $maxPrice = null,
        ?string $status = null
    ): Collection {
        return $this->productRepository->search(
            query: $query,
            maxPrice: $maxPrice,
            status: $status,
        );
    }
}
