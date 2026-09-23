<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use App\Services\ProductService;

class SearchProducts implements Tool
{
    public function __construct(
        protected ProductService $productService
    ) {
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Search for products and return products matching the user search query.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $products = $this->productService->search(
            query: $request['query'],
            maxPrice: $request['max_price'] ?? null,
            status: $request['status'] ?? null,
        );

        if ($products->isEmpty()) {
            return 'No products found.';
        }

        return $products->toJson(JSON_PRETTY_PRINT);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema
                ->string()
                ->description('The product name, keyword, or search phrase to look for.')
                ->required(),

            'max_price' => $schema
                ->number()
                ->description('Only return products with a price less than or equal to this amount.')
                ->nullable(),

            'status' => $schema
                ->string()
                ->description('Filter products by status, such as active or inactive.')
                ->nullable(),
        ];
    }
}
