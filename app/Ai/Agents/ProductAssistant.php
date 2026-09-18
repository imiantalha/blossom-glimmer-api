<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Stringable;
use Laravel\Ai\Concerns\RemembersConversations;
use App\Ai\Tools\SearchProducts;
use App\Ai\Tools\GetProductDetails;

class ProductAssistant implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    /**
     * Get the instructions that the agent should follow.
     */
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
            - Do not claim that products exist unless the tool provides that information.
        PROMPT;
    }

    /**
     * Get the list of messages comprising the conversation so far.
     *
     * @return Message[]
     */
    // public function messages(): iterable
    // {
    //     return [];
    // }

    /**
     * Get the tools available to the agent.
     *
     * @return Tool[]
     */
    public function tools(): iterable
    {
        return [
            new SearchProducts(),
            new GetProductDetails(),
        ];
    }
}
