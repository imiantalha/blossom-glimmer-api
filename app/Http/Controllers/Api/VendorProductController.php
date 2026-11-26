<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tag;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VendorProductController extends Controller
{
    private function ensureVendorStoreCanSell(Request $request): void
    {
        $profile = $request->user()->vendorProfile()->first();
        abort_if(!$profile || $profile->status === 'suspended' || $profile->deleted_at, 403, 'Your store is disabled. Contact an administrator for assistance.');
    }

    private function ownedProduct(Request $request, int $id): Product
    {
        return Product::where('id', $id)->where('vendor_id', $request->user()->id)->firstOrFail();
    }

    private function adminPayload(Product $product): array
    {
        $product->load(['vendor.vendorProfile', 'brand', 'categories.parent', 'variants.inventoryItems']);
        $profile = $product->vendor?->vendorProfile;
        $images = collect(data_get($product->metadata, 'images', []))->values()->map(fn ($image, $index) => [
            'id' => (string) ($image['id'] ?? 'image-' . $index), 'url' => $image['url'] ?? '',
            'altText' => $image['altText'] ?? $image['alt'] ?? null, 'sortOrder' => (int) ($image['sortOrder'] ?? $index),
            'createdAt' => $product->created_at?->toISOString(),
        ])->all();
        $category = $product->categories->first();
        $variants = $product->variants->map(function ($variant) {
            $inventory = $variant->inventoryItems->sum(fn ($item) => max(0, $item->stock_quantity - $item->reserved_quantity));
            $attributes = data_get($variant->metadata, 'attributes', []);
            return [
                'id' => (string) $variant->id, 'sku' => $variant->sku, 'price' => (string) ($variant->compare_at_price ?? $variant->price),
                'salePrice' => $variant->compare_at_price ? (string) $variant->price : null,
                'inventory' => $inventory, 'stockQuantity' => $inventory, 'weight' => (float) data_get($variant->metadata, 'weight', 1),
                'length' => (float) data_get($variant->metadata, 'length', 1), 'width' => (float) data_get($variant->metadata, 'width', 1),
                'height' => (float) data_get($variant->metadata, 'height', 1), 'label' => $variant->name ?: 'Default',
                'createdAt' => $variant->created_at?->toISOString(), 'updatedAt' => $variant->updated_at?->toISOString(),
                'images' => data_get($variant->metadata, 'images', []),
                'attributeValues' => collect($attributes)->map(fn ($value, $key) => ['id' => (string) $variant->id . '-' . Str::slug($key), 'attributeId' => Str::slug($key), 'attributeName' => $key, 'attributeValue' => $value])->values(),
            ];
        })->values();
        if ($product->type === 'simple') {
            $inventory = $product->inventoryItems()->whereNull('variant_id')->first();
            $variants = collect([[
                'id' => 'simple-' . $product->id, 'sku' => $product->sku,
                'price' => (string) ($product->compare_at_price ?? $product->base_price),
                'salePrice' => $product->compare_at_price ? (string) $product->base_price : null,
                'inventory' => (int) ($inventory?->stock_quantity ?? 0), 'stockQuantity' => (int) ($inventory?->stock_quantity ?? 0),
                'weight' => 1, 'length' => 1, 'width' => 1, 'height' => 1, 'label' => 'Default',
                'images' => [], 'attributeValues' => [],
            ]]);
        }
        $productAttributes = collect(data_get($product->metadata, 'attributes', []))->map(fn ($attribute, $index) => [
            'id' => (string) ($index + 1), 'attributeId' => Str::slug($attribute['name'] ?? 'attribute'),
            'attributeName' => $attribute['name'] ?? 'Attribute', 'values' => collect($attribute['values'] ?? [])->map(fn ($value, $valueIndex) => ['id' => (string) ($valueIndex + 1), 'value' => is_array($value) ? ($value['value'] ?? '') : $value])->values(),
        ])->values();
        $ownerName = trim((string) ($product->vendor?->name ?? ''));
        $ownerParts = preg_split('/\s+/', $ownerName, 2) ?: [];

        return [
            'id' => (string) $product->id, 'storeId' => (string) ($profile?->id ?? $product->vendor_id),
            'brandId' => $product->brand_id ? (string) $product->brand_id : null,
            'categoryId' => $category ? (string) $category->id : null,
            'productTypeId' => $product->type, 'shippingProfileId' => (string) data_get($product->metadata, 'shippingProfileId', ''),
            'name' => $product->name, 'slug' => $product->slug, 'shortDescription' => $product->short_description,
            'description' => $product->description, 'status' => strtoupper($product->status), 'isFeatured' => (bool) $product->is_featured,
            'approvalStatus' => strtoupper(data_get($product->metadata, 'approvalStatus', $product->status === 'active' ? 'approved' : 'pending')),
            'rejectionReason' => data_get($product->metadata, 'rejectionReason'),
            'createdById' => (string) $product->vendor_id, 'updatedById' => (string) $product->vendor_id,
            'createdAt' => $product->created_at?->toISOString(), 'updatedAt' => $product->updated_at?->toISOString(),
            'store' => [
                'id' => (string) ($profile?->id ?? $product->vendor_id),
                'name' => $profile?->name ?? $product->vendor?->name,
                'slug' => $profile?->slug ?? Str::slug($product->vendor?->name ?? ''),
                'owner' => $product->vendor ? [
                    'id' => (string) $product->vendor->id,
                    'firstName' => $ownerParts[0] ?? '',
                    'lastName' => $ownerParts[1] ?? '',
                    'email' => $product->vendor->email,
                ] : null,
            ],
            'brand' => $product->brand ? ['id' => (string) $product->brand->id, 'name' => $product->brand->name, 'slug' => $product->brand->slug, 'logo' => $product->brand->image_url] : null,
            'category' => $category ? ['id' => (string) $category->id, 'name' => $category->name, 'slug' => $category->slug] : null,
            'productType' => ['id' => $product->type, 'name' => ucfirst($product->type)],
            'shippingProfile' => ['id' => (string) data_get($product->metadata, 'shippingProfileId', ''), 'name' => (string) data_get($product->metadata, 'shippingProfileName', 'Default shipping')],
            'images' => $images, 'variants' => $variants, 'attributes' => $productAttributes,
            'tags' => collect(data_get($product->metadata, 'tags', []))->map(fn ($tag, $index) => ['id' => (string) ($index + 1), 'name' => $tag])->values(),
            'specifications' => collect(data_get($product->metadata, 'specifications', []))->values()->map(fn ($item, $index) => ['id' => (string) ($index + 1), 'key' => $item['key'] ?? '', 'value' => $item['value'] ?? '']),
            'faqs' => collect(data_get($product->metadata, 'faqs', []))->values()->map(fn ($item, $index) => ['id' => (string) ($index + 1), 'question' => $item['question'] ?? '', 'answer' => $item['answer'] ?? '']), 'variantCount' => $variants->count(),
        ];
    }

    public function options(Request $request)
    {
        $brands = Brand::where('status', 'active')->orderBy('name')->get(['id', 'name', 'slug']);
        $categories = Category::where('status', 'active')->orderBy('name')->get(['id', 'name', 'slug']);
        $shipping = DB::table('shipping_methods')->where('is_active', true)->orderBy('sort_order')->get(['code', 'name']);
        $attributes = DB::table('attributes')->where('status', 'active')->orderBy('name')->get()->map(fn ($attribute) => [
            'id' => (string) $attribute->id, 'name' => $attribute->name,
            'values' => DB::table('attribute_values')->where('attribute_id', $attribute->id)->orderBy('value')->get()->map(fn ($value) => ['id' => (string) $value->id, 'value' => $value->value]),
        ]);
        $tags = DB::table('tags')->where('status', 'active')->orderBy('name')->get(['id', 'name', 'slug'])->map(fn ($tag) => ['id' => (string) $tag->id, 'name' => $tag->name, 'slug' => $tag->slug]);
        return ApiResponse::successResponse([
            'brands' => $brands->map(fn ($item) => ['id' => (string) $item->id, 'name' => $item->name, 'slug' => $item->slug]),
            'categories' => $categories->map(fn ($item) => ['id' => (string) $item->id, 'name' => $item->name, 'slug' => $item->slug]),
            'shippingProfiles' => $shipping->map(fn ($item) => ['id' => $item->code, 'name' => $item->name]),
            'productTypes' => [['id' => 'simple', 'name' => 'Simple'], ['id' => 'variable', 'name' => 'Variable']],
            'attributes' => $attributes,
            'tags' => $tags,
        ], 'Product creation options retrieved.');
    }

    public function index(Request $request)
    {
        $query = Product::where('vendor_id', $request->user()->id)->with(['vendor.vendorProfile', 'brand', 'categories.parent', 'variants.inventoryItems']);
        if ($request->filled('name')) $query->where('name', 'like', '%' . $request->input('name') . '%');
        if ($request->filled('slug')) $query->where('slug', $request->input('slug'));
        if ($request->filled('brand_id')) $query->where('brand_id', $request->integer('brand_id'));
        if ($request->filled('category_id')) $query->whereHas('categories', fn ($q) => $q->where('categories.id', $request->integer('category_id')));
        if ($request->filled('status')) $query->where('status', strtolower($request->input('status')));
        if ($request->filled('approval_status')) {
            $approvalStatus = strtolower($request->input('approval_status'));
            $approvalPattern = '%"approvalStatus":"' . $approvalStatus . '"%';
            $query->where(function ($approval) use ($approvalStatus, $approvalPattern) {
                $approval->where('metadata', 'like', $approvalPattern);
                if ($approvalStatus === 'approved') {
                    $approval->orWhere(function ($legacy) {
                        $legacy->where('status', 'active')->where(function ($metadata) {
                            $metadata->whereNull('metadata')->orWhere('metadata', 'not like', '%"approvalStatus"%');
                        });
                    });
                }
            });
        }
        if ($request->filled('sku')) $query->where(fn ($q) => $q->where('sku', 'like', '%' . $request->input('sku') . '%')->orWhereHas('variants', fn ($v) => $v->where('sku', 'like', '%' . $request->input('sku') . '%')));
        $page = $query->latest()->paginate(min(max($request->integer('per_page', 20), 1), 100));
        $data = $page->getCollection()->map(fn ($product) => $this->adminPayload($product));
        return response()->json(['success' => true, 'message' => 'Vendor products retrieved.', 'data' => $data, 'pagination' => [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
            'count' => $data->count(), 'from' => $page->firstItem(), 'to' => $page->lastItem(), 'has_previous' => $page->currentPage() > 1, 'has_next' => $page->hasMorePages(),
        ]]);
    }

    public function show(Request $request, int $id)
    {
        return ApiResponse::successResponse($this->adminPayload($this->ownedProduct($request, $id)), 'Vendor product retrieved.');
    }

    public function store(Request $request)
    {
        $this->ensureVendorStoreCanSell($request);
        $data = $this->validateProduct($request);
        $userId = $request->user()->id;
        $slug = $data['slug'] ?: Str::slug($data['name']);
        abort_if(Product::where('slug', $slug)->exists(), 422, 'This product URL is already taken.');
        $type = strtolower($data['productType']) === 'variable' ? 'variable' : 'simple';
        $regularPrices = collect($data['variants'])->map(fn ($variant) => (float) ($variant['salePrice'] ?? $variant['price']));
        $product = DB::transaction(function () use ($data, $userId, $slug, $type, $regularPrices) {
            $product = Product::create([
                'vendor_id' => $userId, 'brand_id' => $data['brandId'], 'name' => $data['name'], 'slug' => $slug,
                'type' => $type, 'sku' => $type === 'simple' ? ($data['variants'][0]['sku'] ?? null) : null,
                'short_description' => $data['shortDescription'] ?? null, 'description' => $data['fullDescription'] ?? null,
                'base_price' => $regularPrices->min(), 'status' => ($data['intent'] ?? 'draft') === 'submit' ? 'inactive' : 'draft',
                'metadata' => $this->metadata($data, $data['intent'] ?? 'draft'),
            ]);
            $product->categories()->sync([$data['categoryId']]);
            $this->saveTags($product, $data['tags'] ?? []);
            $this->saveInventoryAndVariants($product, $data, $type);
            return $product;
        });
        return ApiResponse::createdResponse($this->adminPayload($product->fresh()), 'Product saved.');
    }

    public function update(Request $request, int $id)
    {
        $this->ensureVendorStoreCanSell($request);
        $product = $this->ownedProduct($request, $id);
        $data = $this->validateProduct($request, $product->id);
        $slug = $data['slug'] ?: Str::slug($data['name']);
        abort_if(Product::where('slug', $slug)->where('id', '<>', $product->id)->exists(), 422, 'This product URL is already taken.');
        $type = strtolower($data['productType']) === 'variable' ? 'variable' : 'simple';
        $prices = collect($data['variants'])->map(fn ($variant) => (float) ($variant['salePrice'] ?? $variant['price']));
        DB::transaction(function () use ($product, $data, $slug, $type, $prices) {
            $product->update([
                'brand_id' => $data['brandId'], 'name' => $data['name'], 'slug' => $slug, 'type' => $type,
                'sku' => $type === 'simple' ? ($data['variants'][0]['sku'] ?? null) : null,
                'short_description' => $data['shortDescription'] ?? null, 'description' => $data['fullDescription'] ?? null,
                'base_price' => $prices->min(), 'metadata' => $this->metadata($data, $data['intent'] ?? 'draft'),
                'status' => ($data['intent'] ?? 'draft') === 'submit' ? 'inactive' : 'draft',
            ]);
            $product->categories()->sync([$data['categoryId']]);
            $this->saveTags($product, $data['tags'] ?? []);
            $this->saveInventoryAndVariants($product, $data, $type);
        });
        return ApiResponse::successResponse($this->adminPayload($product->fresh()), 'Product updated.');
    }

    private function validateProduct(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255', 'slug' => ['nullable', 'string', 'max:255', 'alpha_dash'],
            'brandId' => 'required|integer|exists:brands,id', 'categoryId' => 'required|integer|exists:categories,id',
            'productType' => ['required', Rule::in(['Simple', 'Variable', 'simple', 'variable'])],
            'shippingProfileId' => 'required|string|exists:shipping_methods,code',
            'shortDescription' => 'nullable|string|max:500', 'fullDescription' => 'nullable|string|max:5000',
            'tags' => 'array|max:10', 'tags.*' => 'string|max:50',
            'media.featuredImage.url' => 'required|url|max:500', 'media.featuredImage.altText' => 'nullable|string|max:255',
            'media.galleryImages' => 'array|max:10', 'media.galleryImages.*.url' => 'required|url|max:500',
            'attributes' => 'array|max:3', 'attributes.*.name' => 'required|string|max:100', 'attributes.*.values' => 'array|max:12',
            'variants' => 'required|array|min:1', 'variants.*.sku' => 'required|string|max:100',
            'variants.*.price' => 'required|numeric|min:0.01', 'variants.*.salePrice' => 'nullable|numeric|min:0.01',
            'variants.*.stockQuantity' => 'required|integer|min:0', 'variants.*.weight' => 'required|numeric|min:0',
            'variants.*.length' => 'required|numeric|min:0', 'variants.*.width' => 'required|numeric|min:0', 'variants.*.height' => 'required|numeric|min:0',
            'variants.*.label' => 'nullable|string|max:255', 'variants.*.attributeValues' => 'array',
            'variants.*.images' => 'array|max:10', 'variants.*.images.*.url' => 'required|url|max:500',
            'specifications' => 'array', 'faqs' => 'array', 'intent' => 'nullable|in:draft,submit',
        ]);
        Brand::where('id', $data['brandId'])->where('status', 'active')->firstOrFail();
        Category::where('id', $data['categoryId'])->where('status', 'active')->firstOrFail();

        foreach ($data['tags'] ?? [] as $tagName) {
            if (!DB::table('tags')->where('slug', Str::slug($tagName))->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(['tags' => ["The tag '{$tagName}' is not in the approved catalog. Ask an administrator to add it."]]);
            }
        }

        foreach ($data['attributes'] ?? [] as $attributeData) {
            $attribute = DB::table('attributes')->where('name', $attributeData['name'])->where('status', 'active')->first();
            if (!$attribute) {
                throw ValidationException::withMessages(['attributes' => ["The attribute '{$attributeData['name']}' is not in the approved catalog."]]);
            }
            $submittedValues = collect($attributeData['values'] ?? [])->pluck('value')->filter()->values();
            if ($submittedValues->isNotEmpty()) {
                $allowedCount = DB::table('attribute_values')->where('attribute_id', $attribute->id)->whereIn('value', $submittedValues)->count();
                if ($allowedCount !== $submittedValues->unique()->count()) {
                    throw ValidationException::withMessages(['attributes' => ["One or more values for '{$attributeData['name']}' are not approved."]]);
                }
            }
        }

        return $data;
    }

    private function metadata(array $data, string $intent): array
    {
        $images = collect([$data['media']['featuredImage']])->merge($data['media']['galleryImages'] ?? [])->values()->all();
        return [
            'images' => $images, 'tags' => $data['tags'] ?? [], 'attributes' => $data['attributes'] ?? [],
            'specifications' => $data['specifications'] ?? [], 'faqs' => $data['faqs'] ?? [],
            'shippingProfileId' => $data['shippingProfileId'], 'approvalStatus' => $intent === 'submit' ? 'pending' : 'draft',
        ];
    }

    private function saveTags(Product $product, array $tags): void
    {
        $ids = [];
        foreach ($tags as $name) {
            $tag = Tag::query()->where('slug', Str::slug($name))->where('status', 'active')->first();
            if (!$tag) {
                throw ValidationException::withMessages(['tags' => ["The tag '{$name}' is not in the approved catalog."]]);
            }
            $ids[] = $tag->id;
        }
        $product->tags()->sync($ids);
    }

    private function saveInventoryAndVariants(Product $product, array $data, string $type): void
    {
        $existingVariants = $product->variants()->with('inventoryItems')->get();
        $incomingVariants = collect($data['variants']);
        $incomingSkus = $incomingVariants->pluck('sku')->all();
        $existingIds = $existingVariants->pluck('id');

        if ($type === 'simple') {
            if ($existingVariants->isNotEmpty()) {
                $hasReservedStock = DB::table('inventory_items')->whereIn('variant_id', $existingIds)->where('reserved_quantity', '>', 0)->exists();
                $hasCartReferences = DB::table('cart_items')->whereIn('variant_id', $existingIds)->exists();
                $hasOrderReferences = DB::table('customer_order_items')->whereIn('variant_id', $existingIds)->exists();

                if ($hasReservedStock || $hasCartReferences || $hasOrderReferences) {
                    throw ValidationException::withMessages([
                        'productType' => ['This product cannot be changed to a simple product while its variants have reserved stock, cart items, or order history.'],
                    ]);
                }

                DB::table('inventory_items')->whereIn('variant_id', $existingIds)->delete();
                DB::table('variant_option_values')->whereIn('variant_id', $existingIds)->delete();
                $product->variants()->delete();
            }

            $variant = $incomingVariants->first();
            $salePrice = $variant['salePrice'] ?? null;
            $product->update([
                'base_price' => $salePrice ?? $variant['price'],
                'compare_at_price' => $salePrice ? $variant['price'] : null,
            ]);

            $inventory = DB::table('inventory_items')
                ->where('product_id', $product->id)
                ->whereNull('variant_id')
                ->lockForUpdate()
                ->first();
            $reserved = (int) ($inventory->reserved_quantity ?? 0);

            if ((int) $variant['stockQuantity'] < $reserved) {
                throw ValidationException::withMessages([
                    'variants.0.stockQuantity' => ['Stock quantity cannot be lower than stock reserved for existing orders.'],
                ]);
            }

            $inventoryValues = [
                'manage_inventory' => true,
                'stock_quantity' => $variant['stockQuantity'],
                'reserved_quantity' => $reserved,
                'low_stock_threshold' => (int) ($inventory->low_stock_threshold ?? 5),
                'updated_at' => now(),
            ];

            if ($inventory) {
                DB::table('inventory_items')->where('id', $inventory->id)->update($inventoryValues);
            } else {
                DB::table('inventory_items')->insert($inventoryValues + [
                    'product_id' => $product->id,
                    'variant_id' => null,
                    'created_at' => now(),
                ]);
            }

            return;
        }

        $simpleInventory = DB::table('inventory_items')
            ->where('product_id', $product->id)
            ->whereNull('variant_id')
            ->lockForUpdate()
            ->first();

        if ($simpleInventory && (int) $simpleInventory->reserved_quantity > 0) {
            throw ValidationException::withMessages([
                'productType' => ['This product cannot become variable while simple-product stock is reserved for existing orders.'],
            ]);
        }

        if ($simpleInventory) {
            DB::table('inventory_items')->where('id', $simpleInventory->id)->delete();
        }

        foreach ($incomingVariants as $index => $variantData) {
            $attributes = collect($variantData['attributeValues'] ?? [])
                ->mapWithKeys(fn ($attribute) => [$attribute['attributeName'] => $attribute['value']])
                ->all();
            $salePrice = $variantData['salePrice'] ?? null;
            $metadata = [
                'attributes' => $attributes,
                'weight' => $variantData['weight'],
                'length' => $variantData['length'],
                'width' => $variantData['width'],
                'height' => $variantData['height'],
                'images' => $variantData['images'] ?? [],
            ];

            $variant = $existingVariants->firstWhere('sku', $variantData['sku']);
            if ($variant) {
                $variant->update([
                    'name' => $variantData['label'] ?? 'Default',
                    'price' => $salePrice ?? $variantData['price'],
                    'compare_at_price' => $salePrice ? $variantData['price'] : null,
                    'status' => 'active',
                    'combination_key' => sha1(json_encode($attributes)),
                    'metadata' => $metadata,
                ]);
            } else {
                $variant = ProductVariant::create([
                    'product_id' => $product->id,
                    'sku' => $variantData['sku'],
                    'name' => $variantData['label'] ?? 'Default',
                    'price' => $salePrice ?? $variantData['price'],
                    'compare_at_price' => $salePrice ? $variantData['price'] : null,
                    'status' => 'active',
                    'combination_key' => sha1(json_encode($attributes)),
                    'metadata' => $metadata,
                ]);
            }

            $inventory = DB::table('inventory_items')->where('variant_id', $variant->id)->lockForUpdate()->first();
            $reserved = (int) ($inventory->reserved_quantity ?? 0);
            if ((int) $variantData['stockQuantity'] < $reserved) {
                throw ValidationException::withMessages([
                    "variants.{$index}.stockQuantity" => ['Stock quantity cannot be lower than stock reserved for existing orders.'],
                ]);
            }

            $inventoryValues = [
                'product_id' => null,
                'manage_inventory' => true,
                'stock_quantity' => $variantData['stockQuantity'],
                'reserved_quantity' => $reserved,
                'low_stock_threshold' => (int) ($inventory->low_stock_threshold ?? 5),
                'updated_at' => now(),
            ];

            if ($inventory) {
                DB::table('inventory_items')->where('id', $inventory->id)->update($inventoryValues);
            } else {
                DB::table('inventory_items')->insert($inventoryValues + [
                    'variant_id' => $variant->id,
                    'created_at' => now(),
                ]);
            }
        }

        $removedVariants = $existingVariants->whereNotIn('sku', $incomingSkus);
        foreach ($removedVariants as $variant) {
            $hasReservedStock = DB::table('inventory_items')->where('variant_id', $variant->id)->where('reserved_quantity', '>', 0)->exists();
            $hasCartReference = DB::table('cart_items')->where('variant_id', $variant->id)->exists();
            $hasOrderReference = DB::table('customer_order_items')->where('variant_id', $variant->id)->exists();

            if ($hasReservedStock || $hasCartReference || $hasOrderReference) {
                throw ValidationException::withMessages([
                    'variants' => ["Variant {$variant->sku} cannot be removed while it has reserved stock, cart items, or order history."],
                ]);
            }

            DB::table('inventory_items')->where('variant_id', $variant->id)->delete();
            DB::table('variant_option_values')->where('variant_id', $variant->id)->delete();
            $variant->delete();
        }

        $product->update(['base_price' => null, 'compare_at_price' => null]);
    }

    public function destroy(Request $request, int $id)
    {
        $this->ensureVendorStoreCanSell($request);
        $product = $this->ownedProduct($request, $id);
        $product->update(['status' => 'archived']);
        return ApiResponse::deletedResponse('Product archived.');
    }

    public function adminIndex(Request $request)
    {
        $query = Product::with(['vendor.vendorProfile', 'brand', 'categories.parent', 'variants.inventoryItems']);
        if ($request->filled('name')) $query->where('name', 'like', '%' . $request->input('name') . '%');
        if ($request->filled('brand_id')) $query->where('brand_id', $request->integer('brand_id'));
        if ($request->filled('category_id')) $query->whereHas('categories', fn ($q) => $q->where('categories.id', $request->integer('category_id')));
        if ($request->filled('status')) $query->where('status', strtolower($request->input('status')));
        if ($request->filled('approval_status')) {
            $approvalStatus = strtolower($request->input('approval_status'));
            $query->where(function ($approval) use ($approvalStatus) {
                $approval->where('metadata->approvalStatus', $approvalStatus);
                if ($approvalStatus === 'approved') {
                    if (DB::connection()->getDriverName() === 'sqlsrv') {
                        $approval->orWhereRaw("JSON_VALUE([metadata], '$.approvalStatus') IS NULL AND [status] = ?", ['active']);
                    } else {
                        $approval->orWhereRaw("json_extract(metadata, '$.approvalStatus') IS NULL AND status = ?", ['active']);
                    }
                }
            });
        }
        if ($request->filled('sku')) $query->where(fn ($q) => $q->where('sku', 'like', '%' . $request->input('sku') . '%')->orWhereHas('variants', fn ($v) => $v->where('sku', 'like', '%' . $request->input('sku') . '%')));
        $page = $query->latest()->paginate(min(max($request->integer('per_page', 20), 1), 100));
        $data = $page->getCollection()->map(fn ($product) => $this->adminPayload($product));
        return response()->json(['success' => true, 'message' => 'Admin products retrieved.', 'data' => $data, 'pagination' => [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
            'count' => $data->count(), 'from' => $page->firstItem(), 'to' => $page->lastItem(), 'has_previous' => $page->currentPage() > 1, 'has_next' => $page->hasMorePages(),
        ]]);
    }

    public function adminShow(int $id)
    {
        return ApiResponse::successResponse($this->adminPayload(Product::findOrFail($id)), 'Product retrieved.');
    }

    public function updateAdminProduct(Request $request, int $id)
    {
        $product = Product::findOrFail($id);
        abort_if($product->status === 'archived', 422, 'Archived products must be restored before editing.');

        $data = $this->validateProduct($request, $product->id);
        $slug = $data['slug'] ?: Str::slug($data['name']);
        abort_if(Product::where('slug', $slug)->where('id', '<>', $product->id)->exists(), 422, 'This product URL is already taken.');

        $type = strtolower($data['productType']) === 'variable' ? 'variable' : 'simple';
        $prices = collect($data['variants'])->map(fn ($variant) => (float) ($variant['salePrice'] ?? $variant['price']));
        $metadata = $this->metadata($data, 'draft');
        $existingMetadata = $product->metadata ?? [];
        $approval = strtolower((string) ($existingMetadata['approvalStatus'] ?? ($product->status === 'active' ? 'approved' : 'pending')));
        $metadata['approvalStatus'] = $approval;
        if (!empty($existingMetadata['rejectionReason'])) {
            $metadata['rejectionReason'] = $existingMetadata['rejectionReason'];
        }

        DB::transaction(function () use ($product, $data, $slug, $type, $prices, $metadata) {
            $product->update([
                'brand_id' => $data['brandId'],
                'name' => $data['name'],
                'slug' => $slug,
                'type' => $type,
                'sku' => $type === 'simple' ? ($data['variants'][0]['sku'] ?? null) : null,
                'short_description' => $data['shortDescription'] ?? null,
                'description' => $data['fullDescription'] ?? null,
                'base_price' => $prices->min(),
                'metadata' => $metadata,
            ]);
            $product->categories()->sync([$data['categoryId']]);
            $this->saveTags($product, $data['tags'] ?? []);
            $this->saveInventoryAndVariants($product, $data, $type);
        });

        return ApiResponse::successResponse($this->adminPayload($product->fresh()), 'Product updated by administrator.');
    }

    public function updateApproval(Request $request, int $id)
    {
        $data = $request->validate([
            'decision' => 'required|in:approve,reject',
            'reason' => 'required_if:decision,reject|nullable|string|max:1000',
        ]);
        $product = Product::findOrFail($id);
        $product->load('vendor.vendorProfile');
        abort_if($product->status === 'archived', 422, 'Archived products cannot be approved or rejected.');
        if ($data['decision'] === 'approve') {
            abort_unless($product->vendor?->vendorProfile?->status === 'active' && !$product->vendor?->vendorProfile?->deleted_at, 422, 'The vendor store must be active before approving products.');
        }
        $metadata = $product->metadata ?? [];
        $metadata['approvalStatus'] = $data['decision'] === 'approve' ? 'approved' : 'rejected';
        if ($data['decision'] === 'reject') {
            $metadata['rejectionReason'] = trim($data['reason']);
        } else {
            unset($metadata['rejectionReason']);
        }
        $product->update([
            'status' => $data['decision'] === 'approve' ? 'active' : 'inactive',
            'is_featured' => $data['decision'] === 'approve' ? $product->is_featured : false,
            'metadata' => $metadata,
        ]);
        return ApiResponse::successResponse($this->adminPayload($product->fresh()), 'Product approval updated.');
    }

    public function updateAdminStatus(Request $request, int $id)
    {
        $data = $request->validate(['status' => 'required|in:active,inactive,archived']);
        $product = Product::with('vendor.vendorProfile')->findOrFail($id);
        $metadata = $product->metadata ?? [];
        $approval = strtolower((string) ($metadata['approvalStatus'] ?? ($product->status === 'active' ? 'approved' : 'pending')));
        abort_if($data['status'] === 'active' && $approval !== 'approved', 422, 'Only approved products can be activated.');
        abort_if($data['status'] === 'active' && ($product->vendor?->vendorProfile?->status !== 'active' || $product->vendor?->vendorProfile?->deleted_at), 422, 'The vendor store must be active before activating products.');
        if (!array_key_exists('approvalStatus', $metadata)) {
            $metadata['approvalStatus'] = $approval;
        }
        $product->update([
            'status' => $data['status'],
            'is_featured' => $data['status'] === 'archived' ? false : $product->is_featured,
            'metadata' => $metadata,
        ]);
        return ApiResponse::successResponse($this->adminPayload($product->fresh()), 'Product status updated.');
    }

    public function updateFeatured(Request $request, int $id)
    {
        $data = $request->validate(['is_featured' => 'required|boolean']);
        $product = Product::with('vendor.vendorProfile')->findOrFail($id);
        abort_if($data['is_featured'] && $product->status !== 'active', 422, 'Only active products can be featured.');
        abort_if($data['is_featured'] && ($product->vendor?->vendorProfile?->status !== 'active' || $product->vendor?->vendorProfile?->deleted_at), 422, 'The vendor store must be active before featuring products.');
        $product->update(['is_featured' => $data['is_featured']]);
        return ApiResponse::successResponse($this->adminPayload($product->fresh()), 'Product featured status updated.');
    }

    public function updateInventory(Request $request, int $id)
    {
        $this->ensureVendorStoreCanSell($request);
        $product = $this->ownedProduct($request, $id);
        $data = $request->validate([
            'variant_id' => 'nullable|integer', 'stock_quantity' => 'required|integer|min:0',
            'manage_inventory' => 'nullable|boolean', 'low_stock_threshold' => 'nullable|integer|min:0',
        ]);
        if (!empty($data['variant_id'])) {
            abort_unless($product->variants()->where('id', $data['variant_id'])->exists(), 404);
            $key = ['variant_id' => $data['variant_id']];
            $values = ['product_id' => null];
        } else {
            abort_if($product->type === 'variable', 422, 'Select a variant to update variable-product stock.');
            $key = ['product_id' => $product->id, 'variant_id' => null];
            $values = [];
        }
        $inventory = DB::transaction(function () use ($key, $values, $data) {
            $query = DB::table('inventory_items');
            foreach ($key as $column => $value) {
                $query->where($column, $value);
            }
            $inventory = $query->lockForUpdate()->first();

            $reserved = (int) ($inventory->reserved_quantity ?? 0);
            if ($data['stock_quantity'] < $reserved) {
                abort(422, 'Stock quantity cannot be lower than the quantity reserved for existing orders.');
            }

            $attributes = $values + [
                'manage_inventory' => $data['manage_inventory'] ?? (bool) ($inventory->manage_inventory ?? true),
                'stock_quantity' => $data['stock_quantity'],
                'reserved_quantity' => $reserved,
                'low_stock_threshold' => $data['low_stock_threshold'] ?? (int) ($inventory->low_stock_threshold ?? 5),
                'updated_at' => now(),
            ];

            if ($inventory) {
                DB::table('inventory_items')->where('id', $inventory->id)->update($attributes);
            } else {
                DB::table('inventory_items')->insert($attributes + ['created_at' => now()]);
            }

            return $reserved;
        });

        return ApiResponse::successResponse([
            'productId' => $product->id,
            'variantId' => $data['variant_id'] ?? null,
            'stockQuantity' => $data['stock_quantity'],
            'reservedQuantity' => $inventory,
            'availableQuantity' => max(0, $data['stock_quantity'] - $inventory),
        ], 'Inventory updated.');
    }
}
