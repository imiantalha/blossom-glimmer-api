<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Repositories\Contracts\ProductVariantRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductVariantService
{
    public function __construct(
        protected ProductVariantRepositoryInterface $variantRepository
    ) {
    }

    public function paginate(Product $product, array $filters): LengthAwarePaginator
    {
        return $this->variantRepository->paginate($product->id, $filters);
    }

    public function find(ProductVariant $variant): ProductVariant
    {
        return $this->variantRepository->find($variant);
    }

    public function create(Product $product, array $data): ProductVariant
    {
        return DB::transaction(function () use ($product, $data) {
            $optionValues = $data['option_values'];
            unset($data['option_values']);

            $this->validateOptionValuesBelongToProduct($product, $optionValues);

            $valueIds = collect($optionValues)
                ->pluck('attribute_value_id')
                ->sort()
                ->values();

            $combinationKey = $valueIds->implode('-');

            if ($product->variants()->where('combination_key', $combinationKey)->exists()) {
                throw ValidationException::withMessages([
                    'option_values' => ['This variant combination already exists for the product.'],
                ]);
            }

            $data['product_id'] = $product->id;
            $data['combination_key'] = $combinationKey;

            $variant = $this->variantRepository->create($data);

            $variant->options()->sync(
                collect($optionValues)->mapWithKeys(fn ($item) => [
                    $item['product_option_id'] => ['attribute_value_id' => $item['attribute_value_id']],
                ])->all()
            );

            return $this->variantRepository->find($variant);
        });
    }

    public function update(ProductVariant $variant, array $data): ProductVariant
    {
        return DB::transaction(function () use ($variant, $data) {
            if (array_key_exists('option_values', $data)) {
                $optionValues = $data['option_values'];
                unset($data['option_values']);

                $this->validateOptionValuesBelongToProduct($variant->product, $optionValues);

                $valueIds = collect($optionValues)
                    ->pluck('attribute_value_id')
                    ->sort()
                    ->values();

                $combinationKey = $valueIds->implode('-');

                $exists = $variant->product->variants()
                    ->where('combination_key', $combinationKey)
                    ->whereKeyNot($variant->id)
                    ->exists();

                if ($exists) {
                    throw ValidationException::withMessages([
                        'option_values' => ['This variant combination already exists for the product.'],
                    ]);
                }

                $data['combination_key'] = $combinationKey;

                $variant->options()->sync(
                    collect($optionValues)->mapWithKeys(fn ($item) => [
                        $item['product_option_id'] => ['attribute_value_id' => $item['attribute_value_id']],
                    ])->all()
                );
            }

            $variant = $this->variantRepository->update($variant, $data);

            return $this->variantRepository->find($variant);
        });
    }

    public function delete(ProductVariant $variant): bool
    {
        return DB::transaction(function () use ($variant) {
            $variant->options()->detach();
            return $this->variantRepository->delete($variant);
        });
    }

    protected function validateOptionValuesBelongToProduct(Product $product, array $optionValues): void
    {
        $optionIds = collect($optionValues)->pluck('product_option_id');

        if ($optionIds->count() !== $optionIds->unique()->count()) {
            throw ValidationException::withMessages([
                'option_values' => ['Each product option can only be selected once.'],
            ]);
        }

        $productOptionIds = $product->options()->whereIn('id', $optionIds)->pluck('id');

        if ($productOptionIds->count() !== $optionIds->count()) {
            throw ValidationException::withMessages([
                'option_values' => ['One or more options do not belong to this product.'],
            ]);
        }

        foreach ($optionValues as $item) {
            $allowed = DB::table('product_option_values')
                ->where('product_option_id', $item['product_option_id'])
                ->where('attribute_value_id', $item['attribute_value_id'])
                ->exists();

            if (! $allowed) {
                throw ValidationException::withMessages([
                    'option_values' => ['One or more attribute values are not available for the selected product option.'],
                ]);
            }
        }
    }
}
