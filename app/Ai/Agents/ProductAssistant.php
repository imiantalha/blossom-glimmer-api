<?php

namespace App\Ai\Agents;

use App\Ai\Tools\GetProductDetails;
use App\Ai\Tools\RecommendProducts;
use App\Ai\Tools\SearchProducts;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

class ProductAssistant implements Agent, Conversational, HasTools, HasStructuredOutput
{
    use Promptable, RemembersConversations;

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
            You are a helpful product assistant.

            Your job is to help users understand and find products.

            Follow these rules:
            - Give clear and concise answers.
            - Only provide information that is available to you.
            - Never invent product names, prices, features, stock, or other product details.
            - If you do not have enough information to answer, say so clearly.
            - Ask a follow-up question when more information is needed.
            - When a user asks to find or search for products, use the SearchProducts tool.
            - When a user asks for recommendations based on preferences or use cases, use the RecommendProducts tool.
            - Use SearchProducts for direct searches and RecommendProducts for recommendation requests.
            - Do not claim that products exist unless a tool provides that information.
            - When SearchProducts or RecommendProducts returns a product that the user wants more details about, use GetProductDetails with the product ID from the tool result.
            - Use the result of SearchProducts to determine which product ID should be passed to GetProductDetails.
            - The products in the structured response must only contain products returned by the available tools.
            - Never invent, infer, or substitute product data that was not returned by a tool.
            - If no products are returned by the tools, return an empty products array.
        PROMPT;
    }

    public function tools(): iterable
    {
        return [
            app(SearchProducts::class),
            app(RecommendProducts::class),
            app(GetProductDetails::class),
        ];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'answer' => $schema
                ->string()
                ->required(),

            'products' => $schema
                ->array()
                ->items(
                    $schema->object(fn ($schema) => [
                        'id' => $schema
                            ->integer()
                            ->required(),

                        'name' => $schema
                            ->string()
                            ->required(),

                        'sku' => $schema
                            ->string()
                            ->required(),

                        'price' => $schema
                            ->number()
                            ->required(),

                        'status' => $schema
                            ->string()
                            ->required(),

                        'short_description' => $schema
                            ->string()
                            ->required(),
                    ])
                )
                ->required(),
        ];
    }
}
