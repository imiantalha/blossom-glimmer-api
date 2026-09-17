<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\StoreProductVariantRequest;
use App\Http\Requests\Product\UpdateProductVariantRequest;
use App\Http\Resources\ProductVariantResource;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\ProductVariantService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductVariantController extends Controller
{
    public function __construct(
        protected ProductVariantService $variantService
    ) {
    }

    public function index(Request $request, Product $product): JsonResponse
    {
        $variants = $this->variantService->paginate($product, $request->only([
            'search', 'per_page', 'sort', 'direction',
        ]));

        return ApiResponse::paginatedResponse(
            ProductVariantResource::collection($variants),
            'Product variants retrieved successfully.'
        );
    }

    public function store(StoreProductVariantRequest $request, Product $product): JsonResponse
    {
        $variant = $this->variantService->create($product, $request->validated());

        return ApiResponse::createdResponse(
            new ProductVariantResource($variant),
            'Product variant created successfully.'
        );
    }

    public function show(Product $product, ProductVariant $variant): JsonResponse
    {
        $variant = $this->variantService->find($variant);

        return ApiResponse::successResponse(
            new ProductVariantResource($variant),
            'Product variant retrieved successfully.'
        );
    }

    public function update(
        UpdateProductVariantRequest $request,
        Product $product,
        ProductVariant $variant
    ): JsonResponse {
        $variant = $this->variantService->update($variant, $request->validated());

        return ApiResponse::successResponse(
            new ProductVariantResource($variant),
            'Product variant updated successfully.'
        );
    }

    public function destroy(Product $product, ProductVariant $variant): JsonResponse
    {
        $this->variantService->delete($variant);

        return ApiResponse::deletedResponse(
            'Product variant deleted successfully.'
        );
    }
}
