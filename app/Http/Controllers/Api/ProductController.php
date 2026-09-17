<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\IndexProductRequest;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\ProductService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    public function __construct(
        protected ProductService $productService
    ) {
    }

    public function index(IndexProductRequest $request): JsonResponse
    {
        $products = $this->productService->paginate($request->validated());

        return ApiResponse::paginatedResponse(
            ProductResource::collection($products),
            'Products retrieved successfully.'
        );
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = $this->productService->create($request->validated());

        return ApiResponse::createdResponse(
            new ProductResource($product),
            'Product created successfully.'
        );
    }

    public function show(Product $product): JsonResponse
    {
        $product = $this->productService->find($product);

        return ApiResponse::successResponse(
            new ProductResource($product),
            'Product retrieved successfully.'
        );
    }

    public function update(
        UpdateProductRequest $request,
        Product $product
    ): JsonResponse {
        $product = $this->productService->update(
            $product,
            $request->validated()
        );

        return ApiResponse::successResponse(
            new ProductResource($product),
            'Product updated successfully.'
        );
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->productService->delete($product);

        return ApiResponse::deletedResponse(
            'Product deleted successfully.'
        );
    }
}
