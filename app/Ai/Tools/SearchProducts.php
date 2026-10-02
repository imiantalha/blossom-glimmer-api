<?php

namespace App\Ai\Tools;

use App\Services\ProductService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchProducts implements Tool
{
    public function __construct(
        protected ProductService $productService
    ) {
    }

    public function description(): Stringable|string
    {
        return 'Search products using keywords, price range, status, brand, and category filters.';
    }

    public function handle(Request $request): Stringable|string
    {
        $products = $this->productService->search(
            query: $request['query'],
            minPrice: $request['min_price'] ?? null,
            maxPrice: $request['max_price'] ?? null,
            status: $request['status'] ?? null,
            brand: $request['brand'] ?? null,
            category: $request['category'] ?? null,
        );

        if ($products->isEmpty()) {
            return 'No products found.';
        }

        return $products->toJson(JSON_PRETTY_PRINT);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema
                ->string()
                ->description('The product name, keyword, SKU, or search phrase to look for.')
                ->required(),

            'min_price' => $schema
                ->number()
                ->description('Only return products with a price greater than or equal to this amount.')
                ->nullable(),

            'max_price' => $schema
                ->number()
                ->description('Only return products with a price less than or equal to this amount.')
                ->nullable(),

            'status' => $schema
                ->string()
                ->description('Filter products by status, such as active or inactive.')
                ->nullable(),

            'brand' => $schema
                ->string()
                ->description('Filter products by brand name.')
                ->nullable(),

            'category' => $schema
                ->string()
                ->description('Filter products by category name.')
                ->nullable(),
        ];
    }
}
