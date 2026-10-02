<?php

namespace App\Ai\Tools;

use App\Services\ProductService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class RecommendProducts implements Tool
{
    public function __construct(
        protected ProductService $productService
    ) {
    }

    public function description(): Stringable|string
    {
        return 'Find product candidates that match the user preferences. Use this when the user asks for recommendations rather than a direct product search.';
    }

    public function handle(Request $request): Stringable|string
    {
        $products = $this->productService->search(
            query: $request['query'],
            minPrice: $request['min_price'] ?? null,
            maxPrice: $request['max_price'] ?? null,
            status: $request['status'] ?? 'active',
            brand: $request['brand'] ?? null,
            category: $request['category'] ?? null,
        );

        if ($products->isEmpty()) {
            return 'No matching product candidates found.';
        }

        return $products->toJson(JSON_PRETTY_PRINT);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema
                ->string()
                ->description('The product type, use case, feature, or preference to match.')
                ->required(),

            'min_price' => $schema
                ->number()
                ->description('Preferred minimum product price.')
                ->nullable(),

            'max_price' => $schema
                ->number()
                ->description('Preferred maximum product price.')
                ->nullable(),

            'brand' => $schema
                ->string()
                ->description('Preferred brand name.')
                ->nullable(),

            'category' => $schema
                ->string()
                ->description('Preferred category name.')
                ->nullable(),

            'status' => $schema
                ->string()
                ->description('Product status. Defaults to active when omitted.')
                ->nullable(),
        ];
    }
}
