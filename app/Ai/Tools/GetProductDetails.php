<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use App\Models\Product;

class GetProductDetails implements Tool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'A description of the tool.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $product = Product::query()
            ->with('brand')
            ->find($request['product_id']);

        if (!$product) {
            return 'Product not found.';
        }

        return $product->toJson(JSON_PRETTY_PRINT);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema
                ->integer()
                ->description('The ID of the product to retrieve.')
                ->required(),
        ];
    }
}
