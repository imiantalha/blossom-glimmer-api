<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Ai\Agents\ProductAssistant;
use Laravel\Ai\Responses\StructuredAgentResponse;

class ProductAssistantController extends Controller
{
    public function __construct(
        private ProductAssistant $productAssistant
    ) {}

    public function chat(Request $request): JsonResponse
    {
        $request->validate([
            'message' => ['required', 'string'],
            'conversation_id' => ['nullable', 'string'],
        ]);

        $user = $request->user();

        $agent = $request->conversation_id
            ? $this->productAssistant->continue(
                $request->conversation_id,
                as: $user,
            )
            : $this->productAssistant->forUser($user);

        $response = $agent->prompt(
            $request->message,
            provider: 'gemini',
        );

        /** @var StructuredAgentResponse $response */

        return response()->json([
            'answer' => $response['answer'],
            'products' => $response['products'],
            'conversation_id' => $response->conversationId,
        ]);
    }

    public function stream(Request $request)
    {
        $request->validate([
            'message' => ['required', 'string'],
            'conversation_id' => ['nullable', 'string'],
            'message_id' => ['nullable', 'string'],
        ]);

        $user = $request->user();

        $agent = $request->conversation_id
            ? $this->productAssistant->continue(
                $request->conversation_id,
                as: $user,
            )
            : $this->productAssistant->forUser($user);

        return $agent
            ->stream(
                $request->message,
                provider: 'gemini',
            )
            ->usingVercelDataProtocol(
                $request->input('message_id'),
            );
    }
}
