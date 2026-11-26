<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Attribute as CatalogAttribute;
use App\Models\AttributeValue;
use App\Models\Tag;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CatalogManagementController extends Controller
{
    public function brands(Request $request)
    {
        $page = Brand::query()->orderByDesc('created_at')
            ->paginate(min(max($request->integer('per_page', 100), 1), 200));
        $data = $page->getCollection()->map(fn (Brand $brand) => $this->brandPayload($brand))->values();

        return response()->json([
            'success' => true, 'message' => 'Brands retrieved.', 'data' => $data,
            'pagination' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(), 'total' => $page->total(), 'count' => $data->count(),
                'from' => $page->firstItem(), 'to' => $page->lastItem(),
                'has_previous' => $page->currentPage() > 1, 'has_next' => $page->hasMorePages(),
            ],
        ]);
    }

    public function brand(int $id)
    {
        $brand = Brand::findOrFail($id);
        return ApiResponse::successResponse($this->brandPayload($brand), 'Brand retrieved.');
    }

    public function storeBrand(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|min:3|max:25',
            'slug' => 'nullable|string|max:255|alpha_dash|unique:brands,slug',
            'status' => 'required|in:ACTIVE,INACTIVE,active,inactive',
            'logo' => 'nullable|url|max:500',
        ]);
        $slug = $data['slug'] ?: Str::slug($data['name']);
        abort_if(Brand::where('slug', $slug)->exists(), 422, 'This brand URL is already taken.');

        $brand = Brand::create([
            'name' => $data['name'], 'slug' => $slug, 'image_url' => $data['logo'] ?? null,
            'status' => strtolower($data['status']),
        ]);

        return ApiResponse::createdResponse($this->brandPayload($brand), 'Brand created.');
    }

    public function updateBrand(Request $request, int $id)
    {
        $brand = Brand::findOrFail($id);
        $data = $request->validate([
            'name' => 'required|string|min:3|max:25',
            'slug' => 'required|string|max:255|alpha_dash|unique:brands,slug,' . $id,
            'status' => 'required|in:ACTIVE,INACTIVE,active,inactive',
            'logo' => 'sometimes|nullable|url|max:500',
        ]);
        $brand->update([
            'name' => $data['name'], 'slug' => $data['slug'],
            'status' => strtolower($data['status']),
            'image_url' => array_key_exists('logo', $data) ? $data['logo'] : $brand->image_url,
        ]);

        return ApiResponse::successResponse($this->brandPayload($brand->fresh()), 'Brand updated.');
    }

    public function deleteBrand(int $id)
    {
        $brand = Brand::findOrFail($id);
        DB::transaction(function () use ($brand) {
            DB::table('products')->where('brand_id', $brand->id)->update(['brand_id' => null]);
            $brand->delete();
        });
        return ApiResponse::successResponse(null, 'Brand deleted.');
    }

    public function categories(Request $request)
    {
        $categories = Category::with('parent')->orderBy('parent_id')->orderBy('name')->get();
        $data = $categories->map(fn (Category $category) => $this->categoryPayload($category))->values();

        return ApiResponse::successResponse($data, 'Categories retrieved.');
    }

    public function category(int $id)
    {
        $category = Category::with(['parent', 'children'])->findOrFail($id);
        return ApiResponse::successResponse($this->categoryPayload($category, true), 'Category retrieved.');
    }

    public function storeCategory(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|alpha_dash|unique:categories,slug',
            'status' => 'required|in:ACTIVE,INACTIVE,active,inactive',
            'parent_id' => 'nullable|integer|exists:categories,id',
            'image' => 'sometimes|nullable|url|max:500',
        ]);
        $parent = !empty($data['parent_id']) ? Category::findOrFail($data['parent_id']) : null;
        abort_if($parent && $parent->parent_id !== null, 422, 'Only root categories can be selected as parents.');
        $slug = $data['slug'] ?: Str::slug($data['name']);
        abort_if(Category::where('slug', $slug)->exists(), 422, 'This category URL is already taken.');

        $category = Category::create([
            'name' => $data['name'], 'slug' => $slug, 'parent_id' => $parent?->id,
            'image_url' => $data['image'] ?? null, 'status' => strtolower($data['status']),
        ]);

        return ApiResponse::createdResponse($this->categoryPayload($category->load('parent')), 'Category created.');
    }

    public function updateCategory(Request $request, int $id)
    {
        $category = Category::withCount('children')->findOrFail($id);
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|alpha_dash|unique:categories,slug,' . $id,
            'status' => 'required|in:ACTIVE,INACTIVE,active,inactive',
            'parent_id' => 'nullable|integer|exists:categories,id',
            'image' => 'sometimes|nullable|url|max:500',
        ]);
        $parent = !empty($data['parent_id']) ? Category::findOrFail($data['parent_id']) : null;
        abort_if($parent && $parent->parent_id !== null, 422, 'Only root categories can be selected as parents.');
        abort_if($parent && $parent->id === $category->id, 422, 'A category cannot be its own parent.');
        abort_if($category->children_count > 0 && $parent, 422, 'Categories with children must remain root categories.');

        $category->update([
            'name' => $data['name'], 'slug' => $data['slug'], 'parent_id' => $parent?->id,
            'status' => strtolower($data['status']),
            'image_url' => array_key_exists('image', $data) ? $data['image'] : $category->image_url,
        ]);

        return ApiResponse::successResponse($this->categoryPayload($category->fresh('parent')), 'Category updated.');
    }

    public function deleteCategory(int $id)
    {
        $category = Category::withCount('children')->findOrFail($id);
        abort_if($category->children_count > 0, 422, 'Delete child categories before deleting this parent category.');
        $category->delete();
        return ApiResponse::successResponse(null, 'Category deleted.');
    }


    public function catalogSettings()
    {
        $attributes = CatalogAttribute::query()->with('values')->orderBy('name')->get()->map(fn (CatalogAttribute $attribute) => [
            'id' => (string) $attribute->id,
            'name' => $attribute->name,
            'slug' => $attribute->slug,
            'type' => $attribute->type,
            'isVariantAttribute' => (bool) $attribute->is_variant_attribute,
            'status' => strtoupper($attribute->status ?? 'active'),
            'values' => $attribute->values->map(fn (AttributeValue $value) => ['id' => (string) $value->id, 'value' => $value->value, 'slug' => $value->slug])->values(),
        ])->values();
        $tags = Tag::query()->orderBy('name')->get()->map(fn (Tag $tag) => [
            'id' => (string) $tag->id, 'name' => $tag->name, 'slug' => $tag->slug, 'status' => strtoupper($tag->status ?? 'active'),
        ])->values();

        return ApiResponse::successResponse(['attributes' => $attributes, 'tags' => $tags], 'Catalog settings retrieved.');
    }

    public function storeAttribute(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|min:2|max:100|unique:attributes,name',
            'slug' => 'nullable|string|max:120|alpha_dash|unique:attributes,slug',
            'type' => 'required|in:select,text,number,color',
            'is_variant_attribute' => 'required|boolean',
            'values' => 'array|max:30',
            'values.*' => 'required|string|max:100',
        ]);
        $slug = $data['slug'] ?: Str::slug($data['name']);
        abort_if($slug === '', 422, 'A valid attribute slug could not be generated.');
        abort_if(DB::table('attributes')->where('slug', $slug)->exists(), 422, 'This attribute URL is already taken.');

        $attribute = DB::transaction(function () use ($data, $slug) {
            $attribute = CatalogAttribute::create([
                'name' => $data['name'],
                'slug' => $slug,
                'type' => $data['type'],
                'is_variant_attribute' => $data['is_variant_attribute'],
                'status' => 'active',
            ]);
            foreach (array_values(array_unique($data['values'] ?? [])) as $value) {
                $valueSlug = Str::slug($value);
                if ($valueSlug !== '') {
                    AttributeValue::create(['attribute_id' => $attribute->id, 'value' => $value, 'slug' => $valueSlug]);
                }
            }
            return $attribute->load('values');
        });

        return ApiResponse::createdResponse([
            'id' => (string) $attribute->id, 'name' => $attribute->name, 'slug' => $attribute->slug,
            'type' => $attribute->type, 'isVariantAttribute' => (bool) $attribute->is_variant_attribute,
            'status' => strtoupper($attribute->status),
            'values' => $attribute->values->map(fn (AttributeValue $value) => ['id' => (string) $value->id, 'value' => $value->value, 'slug' => $value->slug])->values(),
        ], 'Attribute created.');
    }

    public function updateAttribute(Request $request, int $id)
    {
        $attribute = CatalogAttribute::findOrFail($id);
        $data = $request->validate([
            'name' => 'required|string|min:2|max:100|unique:attributes,name,' . $id,
            'slug' => 'nullable|string|max:120|alpha_dash|unique:attributes,slug,' . $id,
            'type' => 'required|in:select,text,number,color',
            'is_variant_attribute' => 'required|boolean',
            'values' => 'array|max:30',
            'values.*' => 'required|string|max:100',
        ]);
        $slug = $data['slug'] ?: Str::slug($data['name']);
        abort_if($slug === '', 422, 'A valid attribute slug could not be generated.');
        $attribute->update([
            'name' => $data['name'],
            'slug' => $slug,
            'type' => $data['type'],
            'is_variant_attribute' => $data['is_variant_attribute'],
        ]);

        // Add new approved values without deleting values already referenced by variants.
        foreach (array_values(array_unique($data['values'] ?? [])) as $value) {
            $valueSlug = Str::slug($value);
            if ($valueSlug !== '') {
                AttributeValue::firstOrCreate(
                    ['attribute_id' => $attribute->id, 'slug' => $valueSlug],
                    ['value' => $value],
                );
            }
        }

        return ApiResponse::successResponse([
            'id' => (string) $attribute->id,
            'name' => $attribute->name,
            'slug' => $attribute->slug,
            'type' => $attribute->type,
            'isVariantAttribute' => (bool) $attribute->is_variant_attribute,
            'status' => strtoupper($attribute->status),
            'values' => $attribute->fresh()->values->map(fn (AttributeValue $value) => ['id' => (string) $value->id, 'value' => $value->value, 'slug' => $value->slug])->values(),
        ], 'Attribute updated.');
    }

    public function updateAttributeStatus(Request $request, int $id)
    {
        $data = $request->validate(['status' => 'required|in:active,inactive,ACTIVE,INACTIVE']);
        $attribute = CatalogAttribute::findOrFail($id);
        $attribute->update(['status' => strtolower($data['status'])]);
        return ApiResponse::successResponse(['id' => (string) $attribute->id, 'status' => strtoupper($attribute->status)], 'Attribute status updated.');
    }

    public function storeTag(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|min:2|max:50|unique:tags,name',
            'slug' => 'nullable|string|max:80|alpha_dash|unique:tags,slug',
        ]);
        $slug = $data['slug'] ?: Str::slug($data['name']);
        abort_if($slug === '', 422, 'A valid tag slug could not be generated.');
        abort_if(DB::table('tags')->where('slug', $slug)->exists(), 422, 'This tag URL is already taken.');
        $tag = Tag::create(['name' => $data['name'], 'slug' => $slug, 'status' => 'active']);
        return ApiResponse::createdResponse(['id' => (string) $tag->id, 'name' => $tag->name, 'slug' => $tag->slug, 'status' => strtoupper($tag->status)], 'Tag created.');
    }

    public function updateTag(Request $request, int $id)
    {
        $tag = Tag::findOrFail($id);
        $data = $request->validate([
            'name' => 'required|string|min:2|max:50|unique:tags,name,' . $id,
            'slug' => 'nullable|string|max:80|alpha_dash|unique:tags,slug,' . $id,
        ]);
        $slug = $data['slug'] ?: Str::slug($data['name']);
        abort_if($slug === '', 422, 'A valid tag slug could not be generated.');
        $tag->update(['name' => $data['name'], 'slug' => $slug]);
        return ApiResponse::successResponse(['id' => (string) $tag->id, 'name' => $tag->name, 'slug' => $tag->slug, 'status' => strtoupper($tag->status)], 'Tag updated.');
    }

    public function updateTagStatus(Request $request, int $id)
    {
        $data = $request->validate(['status' => 'required|in:active,inactive,ACTIVE,INACTIVE']);
        $tag = Tag::findOrFail($id);
        $tag->update(['status' => strtolower($data['status'])]);
        return ApiResponse::successResponse(['id' => (string) $tag->id, 'status' => strtoupper($tag->status)], 'Tag status updated.');
    }


    public function marketplaceSettings()
    {
        $collections = DB::table('collections')->orderBy('sort_order')->orderBy('name')->get()->map(fn ($item) => [
            'id' => (string) $item->id,
            'name' => $item->name,
            'slug' => $item->slug,
            'eyebrow' => $item->eyebrow,
            'description' => $item->description,
            'imageUrl' => $item->image_url,
            'categorySlugs' => json_decode($item->category_slugs, true) ?: [],
            'featuredStores' => json_decode($item->featured_stores, true) ?: [],
            'isActive' => (bool) $item->is_active,
            'sortOrder' => (int) $item->sort_order,
        ])->values();
        $shippingMethods = DB::table('shipping_methods')->orderBy('sort_order')->orderBy('name')->get()->map(fn ($item) => [
            'id' => (string) $item->id,
            'code' => $item->code,
            'name' => $item->name,
            'description' => $item->description,
            'price' => (float) $item->price,
            'currency' => $item->currency,
            'estimatedDelivery' => $item->estimated_delivery,
            'countries' => json_decode($item->countries, true) ?: [],
            'isActive' => (bool) $item->is_active,
            'sortOrder' => (int) $item->sort_order,
        ])->values();

        return ApiResponse::successResponse(['collections' => $collections, 'shippingMethods' => $shippingMethods], 'Marketplace settings retrieved.');
    }

    public function storeCollection(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|min:2|max:150',
            'slug' => 'nullable|string|max:180|alpha_dash|unique:collections,slug',
            'eyebrow' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:3000',
            'image_url' => 'nullable|url|max:500',
            'category_slugs' => 'array|max:30',
            'category_slugs.*' => 'string|exists:categories,slug',
            'featured_stores' => 'array|max:30',
            'featured_stores.*' => 'string|max:120',
            'is_active' => 'required|boolean',
            'sort_order' => 'nullable|integer|min:0|max:100000',
        ]);
        $slug = $data['slug'] ?: Str::slug($data['name']);
        abort_if($slug === '', 422, 'A valid collection slug could not be generated.');
        abort_if(DB::table('collections')->where('slug', $slug)->exists(), 422, 'This collection slug is already taken.');

        $id = DB::table('collections')->insertGetId([
            'name' => $data['name'],
            'slug' => $slug,
            'eyebrow' => $data['eyebrow'] ?? null,
            'description' => $data['description'] ?? null,
            'image_url' => $data['image_url'] ?? null,
            'category_slugs' => json_encode(array_values(array_unique($data['category_slugs'] ?? []))),
            'featured_stores' => json_encode(array_values(array_unique($data['featured_stores'] ?? []))),
            'is_active' => $data['is_active'],
            'sort_order' => $data['sort_order'] ?? 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ApiResponse::createdResponse(['id' => (string) $id, 'slug' => $slug], 'Collection created.');
    }

    public function updateCollection(Request $request, int $id)
    {
        $collection = DB::table('collections')->where('id', $id)->first();
        abort_unless($collection, 404);
        $data = $request->validate([
            'name' => 'sometimes|required|string|min:2|max:150',
            'slug' => 'sometimes|required|string|max:180|alpha_dash|unique:collections,slug,' . $id,
            'eyebrow' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:3000',
            'image_url' => 'nullable|url|max:500',
            'category_slugs' => 'array|max:30',
            'category_slugs.*' => 'string|exists:categories,slug',
            'featured_stores' => 'array|max:30',
            'featured_stores.*' => 'string|max:120',
            'sort_order' => 'sometimes|integer|min:0|max:100000',
        ]);
        foreach (['category_slugs', 'featured_stores'] as $field) {
            if (array_key_exists($field, $data)) $data[$field] = json_encode(array_values(array_unique($data[$field] ?? [])));
        }
        if (array_key_exists('image_url', $data)) $data['image_url'] = $data['image_url'] ?: null;
        $data['updated_at'] = now();
        DB::table('collections')->where('id', $id)->update($data);
        return ApiResponse::successResponse(['id' => (string) $id], 'Collection updated.');
    }

    public function updateCollectionStatus(Request $request, int $id)
    {
        $data = $request->validate(['is_active' => 'required|boolean']);
        abort_unless(DB::table('collections')->where('id', $id)->exists(), 404);
        DB::table('collections')->where('id', $id)->update(['is_active' => $data['is_active'], 'updated_at' => now()]);
        return ApiResponse::successResponse(['id' => (string) $id, 'isActive' => (bool) $data['is_active']], 'Collection status updated.');
    }

    public function storeShippingMethod(Request $request)
    {
        $data = $request->validate([
            'code' => 'required|string|min:2|max:60|alpha_dash|unique:shipping_methods,code',
            'name' => 'required|string|min:2|max:150',
            'description' => 'nullable|string|max:2000',
            'price' => 'required|numeric|min:0|max:1000000',
            'currency' => 'required|string|size:3',
            'estimated_delivery' => 'nullable|string|max:120',
            'countries' => 'array|max:250',
            'countries.*' => 'string|size:2',
            'is_active' => 'required|boolean',
            'sort_order' => 'nullable|integer|min:0|max:100000',
        ]);
        $id = DB::table('shipping_methods')->insertGetId([
            'code' => strtolower($data['code']),
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'price' => $data['price'],
            'currency' => strtoupper($data['currency']),
            'estimated_delivery' => $data['estimated_delivery'] ?? null,
            'countries' => json_encode(array_values(array_unique(array_map('strtoupper', $data['countries'] ?? [])))),
            'is_active' => $data['is_active'],
            'sort_order' => $data['sort_order'] ?? 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return ApiResponse::createdResponse(['id' => (string) $id, 'code' => strtolower($data['code'])], 'Shipping method created.');
    }

    public function updateShippingMethod(Request $request, int $id)
    {
        abort_unless(DB::table('shipping_methods')->where('id', $id)->exists(), 404);
        $data = $request->validate([
            'name' => 'sometimes|required|string|min:2|max:150',
            'description' => 'nullable|string|max:2000',
            'price' => 'sometimes|required|numeric|min:0|max:1000000',
            'currency' => 'sometimes|required|string|size:3',
            'estimated_delivery' => 'nullable|string|max:120',
            'countries' => 'array|max:250',
            'countries.*' => 'string|size:2',
            'sort_order' => 'sometimes|integer|min:0|max:100000',
        ]);
        if (array_key_exists('currency', $data)) $data['currency'] = strtoupper($data['currency']);
        if (array_key_exists('countries', $data)) $data['countries'] = json_encode(array_values(array_unique(array_map('strtoupper', $data['countries'] ?? []))));
        $data['updated_at'] = now();
        DB::table('shipping_methods')->where('id', $id)->update($data);
        return ApiResponse::successResponse(['id' => (string) $id], 'Shipping method updated.');
    }

    public function updateShippingMethodStatus(Request $request, int $id)
    {
        $data = $request->validate(['is_active' => 'required|boolean']);
        abort_unless(DB::table('shipping_methods')->where('id', $id)->exists(), 404);
        DB::table('shipping_methods')->where('id', $id)->update(['is_active' => $data['is_active'], 'updated_at' => now()]);
        return ApiResponse::successResponse(['id' => (string) $id, 'isActive' => (bool) $data['is_active']], 'Shipping method status updated.');
    }

    private function brandPayload(Brand $brand): array
    {
        return [
            'id' => (string) $brand->id, 'name' => $brand->name, 'slug' => $brand->slug,
            'logo' => $brand->image_url, 'status' => strtoupper($brand->status),
            'createdById' => null, 'updatedById' => null,
            'createdAt' => $brand->created_at?->toISOString(), 'updatedAt' => $brand->updated_at?->toISOString(),
        ];
    }

    private function categoryPayload(Category $category, bool $includeChildren = false): array
    {
        $payload = [
            'id' => (string) $category->id, 'parentId' => $category->parent_id ? (string) $category->parent_id : null,
            'name' => $category->name, 'slug' => $category->slug, 'image' => $category->image_url,
            'status' => strtoupper($category->status), 'createdById' => null, 'updatedById' => null,
            'createdAt' => $category->created_at?->toISOString(), 'updatedAt' => $category->updated_at?->toISOString(),
            'parent' => $category->parent ? ['id' => (string) $category->parent->id, 'name' => $category->parent->name, 'slug' => $category->parent->slug] : null,
        ];
        if ($includeChildren) {
            $payload['children'] = $category->children->map(fn (Category $child) => $this->categoryPayload($child))->values();
        }
        return $payload;
    }
}
