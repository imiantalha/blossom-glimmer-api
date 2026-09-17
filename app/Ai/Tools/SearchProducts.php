<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use App\Models\Product;

class SearchProducts implements Tool
{
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
        $query = Product::query()
            ->where('name', 'like', '%' . $request['query'] . '%');

        if (!empty($request['max_price'])) {
            $query->where('base_price', '<=', $request['max_price']);
        }

        if (!empty($request['status'])) {
            $query->where('status', $request['status']);
        }

        $products = $query
            ->limit(5)
            ->get([
                'id',
                'name',
                'sku',
                'base_price',
                'status',
                'short_description',
            ]);

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
