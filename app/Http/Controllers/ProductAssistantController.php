<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Ai\Agents\ProductAssistant;

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

        return response()->json([
            'message' => $response->text,
            'conversation_id' => $response->conversationId,
        ]);
    }
}
