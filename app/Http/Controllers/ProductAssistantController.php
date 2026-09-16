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
        ]);

        $response = $this->productAssistant->prompt(
            $request->message,
            provider: 'gemini',
        );

        return response()->json([
            'message' => $response->text,
        ]);
    }
}
