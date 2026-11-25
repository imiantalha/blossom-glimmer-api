<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Category;
use App\Models\Brand;
use App\Constants\Role;
use App\Http\Resources\UserResource;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StorefrontController extends Controller
{

    private function paginatedPayload($items, Request $request, int $defaultPerPage = 24)
    {
        $perPage = min(max($request->integer('per_page', $defaultPerPage), 1), 100);
        $page = max($request->integer('page', 1), 1);
        $total = $items->count();
        $data = $items->forPage($page, $perPage)->values();

        return response()->json([
            'success' => true,
            'message' => 'Resources retrieved.',
            'data' => $data,
            'pagination' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'per_page' => $perPage,
                'total' => $total,
                'count' => $data->count(),
                'from' => $data->isEmpty() ? null : (($page - 1) * $perPage) + 1,
                'to' => $data->isEmpty() ? null : (($page - 1) * $perPage) + $data->count(),
                'has_previous' => $page > 1,
                'has_next' => $page * $perPage < $total,
            ],
        ]);
    }

    private function productQuery()
    {
        return Product::query()->where('status', 'active')
            ->with(['brand', 'vendor.vendorProfile', 'categories.parent', 'tags', 'featureMedia', 'media', 'variants.options.attribute', 'variants.attributeValues', 'variants.inventoryItems', 'inventoryItems']);
    }

    private function productPayload(Product $p): array
    {
        $metadataImages = collect(data_get($p->metadata, 'images', []));
        $images = $metadataImages->isNotEmpty()
            ? $metadataImages->values()
            : collect([$p->featureMedia])->merge($p->media)->filter()->unique('id')->values()->map(fn ($m) => [
                'id' => $m->id, 'url' => $m->external_url ?: \Illuminate\Support\Facades\Storage::disk($m->disk)->url($m->file_path), 'alt' => $m->alt_text,
            ]);
        $stock = $p->variants->isNotEmpty()
            ? $p->variants->sum(fn ($v) => $v->inventoryItems->sum(fn ($i) => max(0, $i->stock_quantity - $i->reserved_quantity)))
            : $p->inventoryItems->sum(fn ($i) => max(0, $i->stock_quantity - $i->reserved_quantity));
        $prices = $p->variants->pluck('price')->filter()->map(fn ($v) => (float) $v);
        $categories = $p->categories;
        $category = $categories->first();
        return [
            'id' => $p->id, 'name' => $p->name, 'slug' => $p->slug, 'sku' => $p->sku,
            'description' => $p->description, 'shortDescription' => $p->short_description,
            'price' => $p->type === 'variable' ? ($prices->min() ?? null) : (float) $p->base_price,
            'compareAtPrice' => $p->compare_at_price ? (float) $p->compare_at_price : ($p->variants->pluck('compare_at_price')->filter()->isNotEmpty() ? (float) $p->variants->pluck('compare_at_price')->filter()->min() : null),
            'currency' => 'USD', 'status' => 'published', 'stock' => $stock, 'inStock' => $stock > 0,
            'images' => $images, 'variants' => $p->variants->map(fn ($v) => [
                'id' => $v->id, 'sku' => $v->sku, 'name' => $v->name, 'price' => (float) $v->price,
                'compareAtPrice' => $v->compare_at_price ? (float) $v->compare_at_price : null,
                'stock' => $v->inventoryItems->sum(fn ($i) => max(0, $i->stock_quantity - $i->reserved_quantity)),
                'attributes' => data_get($v->metadata, 'attributes', $v->attributeValues->mapWithKeys(fn ($a) => [$a->attribute->name => $a->value])->all()),
            ])->values(),
            'tags' => $p->tags->pluck('slug')->values(),
            'category' => $category ? ['id' => $category->parent?->id ?? $category->id, 'name' => $category->parent?->name ?? $category->name, 'slug' => $category->parent?->slug ?? $category->slug] : null,
            'subcategory' => ($category && $category->parent) ? ['id' => $category->id, 'name' => $category->name, 'slug' => $category->slug] : null,
            'brand' => $p->brand ? ['id' => $p->brand->id, 'name' => $p->brand->name, 'slug' => $p->brand->slug] : null,
            'vendor' => $p->vendor ? ['id' => $p->vendor->id, 'name' => $p->vendor->name, 'slug' => $p->vendor->vendorProfile?->slug ?? Str::slug($p->vendor->name), 'rating' => $p->vendor->vendorProfile?->rating, 'reviewCount' => $p->vendor->vendorProfile?->review_count ?? 0] : null,
            'rating' => (float) (DB::table('product_reviews')->where('product_id', $p->id)->where('status', 'approved')->avg('rating') ?? data_get($p->metadata, 'rating', 0)),
            'reviewCount' => DB::table('product_reviews')->where('product_id', $p->id)->where('status', 'approved')->count() ?: (int) data_get($p->metadata, 'review_count', 0),
            'soldCount' => (int) $p->sold_count,
            'badges' => data_get($p->metadata, 'badges', []),
            'season' => data_get($p->metadata, 'season', []), 'occasion' => data_get($p->metadata, 'occasion', []),
            'collections' => data_get($p->metadata, 'collections', []), 'specifications' => data_get($p->metadata, 'specifications', []),
            'featured' => (bool) $p->is_featured,
            'newArrival' => (bool) $p->is_new_arrival,
        ];
    }

    public function products(Request $request)
    {
        $query = $this->productQuery();

        if ($search = $request->string('search')->trim()->toString()) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%")
                ->orWhere('sku', 'like', "%{$search}%"));
        }
        if ($slug = $request->input('category')) {
            $query->where(fn ($q) => $q->whereHas('categories', fn ($category) => $category->where('slug', $slug))
                ->orWhereHas('categories.parent', fn ($parent) => $parent->where('slug', $slug)));
        }
        if ($slug = $request->input('subcategory')) {
            $query->whereHas('categories', fn ($q) => $q->where('slug', $slug));
        }
        if ($slug = $request->input('brand')) {
            $query->whereHas('brand', fn ($q) => $q->where('slug', $slug));
        }
        if ($slug = $request->input('vendor')) {
            $vendorId = DB::table('vendor_profiles')->where('slug', $slug)->value('user_id');
            if ($vendorId) $query->where('vendor_id', $vendorId);
            else $query->whereHas('vendor', fn ($q) => $q->whereRaw('LOWER(REPLACE(name, ?, ?)) = ?', [' ', '-', $slug]));
        }
        if ($request->filled('min_price')) {
            $query->whereRaw('COALESCE(base_price, (SELECT MIN(price) FROM product_variants WHERE product_variants.product_id = products.id AND product_variants.status = ?)) >= ?', ['active', (float) $request->min_price]);
        }
        if ($request->filled('max_price')) {
            $query->whereRaw('COALESCE(base_price, (SELECT MIN(price) FROM product_variants WHERE product_variants.product_id = products.id AND product_variants.status = ?)) <= ?', ['active', (float) $request->max_price]);
        }
        if ($request->boolean('discounted')) {
            $query->where(function ($q) {
                $q->where(fn ($simple) => $simple->whereNotNull('compare_at_price')->whereColumn('compare_at_price', '>', 'base_price'))
                    ->orWhereHas('variants', fn ($variant) => $variant->whereNotNull('compare_at_price')->whereColumn('compare_at_price', '>', 'price'));
            });
        }
        if ($request->boolean('in_stock')) {
            $query->where(function ($q) {
                $q->whereHas('inventoryItems', fn ($stock) => $stock->where('manage_inventory', false)->orWhereRaw('stock_quantity > reserved_quantity'))
                    ->orWhereHas('variants.inventoryItems', fn ($stock) => $stock->where('manage_inventory', false)->orWhereRaw('stock_quantity > reserved_quantity'));
            });
        }
        foreach (['season', 'occasion'] as $metadataFilter) {
            if ($request->filled($metadataFilter)) {
                $query->whereJsonContains('metadata->' . $metadataFilter, $request->input($metadataFilter));
            }
        }
        if ($request->filled('collection')) {
            $collection = DB::table('collections')->where('slug', $request->input('collection'))->where('is_active', true)->first();
            if (!$collection) {
                $query->whereRaw('1 = 0');
            } else {
                $categorySlugs = json_decode($collection->category_slugs, true) ?: [];
                if ($categorySlugs) $query->where(fn ($q) => $q->whereHas('categories', fn ($category) => $category->whereIn('slug', $categorySlugs))->orWhereHas('categories.parent', fn ($parent) => $parent->whereIn('slug', $categorySlugs)));
            }
        }
        foreach ($request->query() as $key => $value) {
            if (str_starts_with($key, 'attribute_') && is_scalar($value)) {
                $attributeName = substr($key, 10);
                $query->whereHas('variants', fn ($variant) => $variant->whereJsonContains('metadata->attributes', [$attributeName => (string) $value])
                    ->orWhereHas('attributeValues', fn ($attributeValue) => $attributeValue->whereHas('attribute', fn ($a) => $a->where('slug', Str::slug($attributeName)))->where('value', (string) $value)));
            }
        }
        if ($request->filled('min_rating')) {
            $query->whereRaw('(SELECT AVG(rating) FROM product_reviews WHERE product_reviews.product_id = products.id AND product_reviews.status = ?) >= ?', ['approved', (float) $request->min_rating]);
        }

        $sort = $request->input('sort', 'featured');
        match ($sort) {
            'newest' => $query->orderByDesc('is_new_arrival')->latest(),
            'price_asc' => $query->orderByRaw('COALESCE(base_price, (SELECT MIN(price) FROM product_variants WHERE product_variants.product_id = products.id AND product_variants.status = ?)) ASC', ['active']),
            'price_desc' => $query->orderByRaw('COALESCE(base_price, (SELECT MIN(price) FROM product_variants WHERE product_variants.product_id = products.id AND product_variants.status = ?)) DESC', ['active']),
            'rating' => $query->orderByRaw('(SELECT AVG(rating) FROM product_reviews WHERE product_reviews.product_id = products.id AND product_reviews.status = ?) DESC', ['approved']),
            'best_selling' => $query->orderByDesc('sold_count'),
            default => $query->orderByDesc('is_featured')->orderByDesc('created_at'),
        };

        $page = $query->paginate(min(max($request->integer('per_page', 24), 1), 100));
        $data = $page->getCollection()->map(fn ($p) => $this->productPayload($p));
        return response()->json(['success' => true, 'message' => 'Products retrieved.', 'data' => $data, 'pagination' => [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
            'count' => $data->count(), 'from' => $page->firstItem(), 'to' => $page->lastItem(), 'has_previous' => $page->currentPage() > 1, 'has_next' => $page->hasMorePages(),
        ]]);
    }

    public function product(string $slug)
    {
        $product = $this->productQuery()->where('slug', $slug)->firstOrFail();
        return ApiResponse::successResponse($this->productPayload($product), 'Product retrieved.');
    }

    public function categories(Request $request)
    {
        $query = Category::where('status', 'active')->with(['children' => fn ($q) => $q->where('status', 'active')->orderBy('sort_order')])
            ->whereNull('parent_id')->orderBy('sort_order');
        if ($search = $request->string('search')->trim()->toString()) $query->where('name', 'like', "%{$search}%");
        $items = $query->get()->map(fn ($c) => [
            'id' => $c->id, 'name' => $c->name, 'slug' => $c->slug, 'description' => $c->description, 'imageUrl' => $c->image_url,
            'productCount' => $c->products()->where('status', 'active')->count(),
            'children' => $c->children->map(fn ($child) => [
                'id' => $child->id, 'name' => $child->name, 'slug' => $child->slug, 'description' => $child->description, 'imageUrl' => $child->image_url,
                'parentId' => $c->id, 'parentSlug' => $c->slug, 'productCount' => $child->products()->where('status', 'active')->count(),
            ])->values(),
        ]);
        return $this->paginatedPayload($items, $request, 100);
    }

    public function category(string $slug)
    {
        $c = Category::where('slug', $slug)->where('status', 'active')->with('parent')->firstOrFail();
        return ApiResponse::successResponse(['id' => $c->id, 'name' => $c->name, 'slug' => $c->slug, 'description' => $c->description, 'imageUrl' => $c->image_url,
            'parentId' => $c->parent_id, 'parentSlug' => $c->parent?->slug, 'productCount' => $c->products()->where('status', 'active')->count()], 'Category retrieved.');
    }

    public function brands(Request $request)
    {
        $query = Brand::where('status', 'active')->withCount(['products' => fn ($q) => $q->where('status', 'active')])->orderBy('name');
        if ($search = $request->string('search')->trim()->toString()) $query->where('name', 'like', "%{$search}%");
        $items = $query->get()->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'slug' => $b->slug, 'description' => $b->description, 'imageUrl' => $b->image_url, 'productCount' => $b->products_count]);
        return $this->paginatedPayload($items, $request, 100);
    }

    public function brand(string $slug)
    {
        $b = Brand::where('slug', $slug)->where('status', 'active')->withCount(['products' => fn ($q) => $q->where('status', 'active')])->firstOrFail();
        return ApiResponse::successResponse(['id' => $b->id, 'name' => $b->name, 'slug' => $b->slug, 'description' => $b->description, 'imageUrl' => $b->image_url, 'productCount' => $b->products_count], 'Brand retrieved.');
    }

    public function brandProducts(Request $request, string $slug)
    {
        $request->merge(['brand' => $slug]);
        return $this->products($request);
    }

    public function vendors(Request $request)
    {
        $query = \App\Models\User::role(Role::VENDOR)->whereHas('vendorProfile', fn ($q) => $q->where('status', 'active')->whereNull('deleted_at'))->with('vendorProfile')->orderBy('name');
        if ($search = $request->string('search')->trim()->toString()) $query->where('name', 'like', "%{$search}%");
        $items = $query->get()->map(function ($v) {
            $profile = $v->vendorProfile;
            return [
                'id' => $v->id, 'name' => $v->name, 'slug' => $profile?->slug ?? Str::slug($v->name),
                'description' => $profile?->description, 'imageUrl' => $profile?->image_url,
                'rating' => $profile?->rating ? (float) $profile->rating : null,
                'reviewCount' => $profile?->review_count ?? 0,
                'productCount' => DB::table('products')->where('vendor_id', $v->id)->where('status', 'active')->count(),
            ];
        });
        return $this->paginatedPayload($items, $request, 100);
    }

    public function vendor(string $slug)
    {
        $vendor = \App\Models\User::role(Role::VENDOR)->whereHas('vendorProfile', fn ($q) => $q->where('slug', $slug)->where('status', 'active')->whereNull('deleted_at'))->with('vendorProfile')->first();
        abort_unless($vendor, 404);
        $profile = $vendor->vendorProfile;
        return ApiResponse::successResponse([
            'id' => $vendor->id, 'name' => $vendor->name, 'slug' => $profile?->slug ?? Str::slug($vendor->name),
            'description' => $profile?->description, 'imageUrl' => $profile?->image_url,
            'rating' => $profile?->rating ? (float) $profile->rating : null,
            'reviewCount' => $profile?->review_count ?? 0,
            'productCount' => DB::table('products')->where('vendor_id', $vendor->id)->where('status', 'active')->count(),
        ], 'Vendor retrieved.');
    }

    public function vendorProducts(Request $request, string $slug)
    {
        $vendor = \App\Models\User::role(Role::VENDOR)->whereHas('vendorProfile', fn ($q) => $q->where('slug', $slug)->where('status', 'active')->whereNull('deleted_at'))->with('vendorProfile')->first();
        abort_unless($vendor, 404);
        $query = $this->productQuery()->where('vendor_id', $vendor->id);
        $page = $query->paginate(min(max($request->integer('per_page', 24), 1), 100));
        $data = $page->getCollection()->map(fn ($p) => $this->productPayload($p));
        return response()->json(['success' => true, 'message' => 'Vendor products retrieved.', 'data' => $data, 'pagination' => [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
            'count' => $data->count(), 'from' => $page->firstItem(), 'to' => $page->lastItem(), 'has_previous' => $page->currentPage() > 1, 'has_next' => $page->hasMorePages(),
        ]]);
    }

    public function collections()
    {
        $items = DB::table('collections')->where('is_active', true)->orderBy('sort_order')->get()
            ->map(fn ($c) => [
                'id' => $c->id, 'slug' => $c->slug, 'title' => $c->name, 'eyebrow' => $c->eyebrow,
                'description' => $c->description, 'heroImage' => $c->image_url,
                'categories' => json_decode($c->category_slugs, true) ?: [],
                'featuredStores' => json_decode($c->featured_stores, true) ?: [],
            ]);
        return ApiResponse::successResponse($items, 'Collections retrieved.');
    }

    public function collection(string $slug)
    {
        $c = DB::table('collections')->where('slug', $slug)->where('is_active', true)->first();
        abort_unless($c, 404);
        return ApiResponse::successResponse([
            'id' => $c->id, 'slug' => $c->slug, 'title' => $c->name, 'eyebrow' => $c->eyebrow,
            'description' => $c->description, 'heroImage' => $c->image_url,
            'categories' => json_decode($c->category_slugs, true) ?: [],
            'featuredStores' => json_decode($c->featured_stores, true) ?: [],
        ], 'Collection retrieved.');
    }

    public function collectionProducts(Request $request, string $slug)
    {
        $collection = DB::table('collections')->where('slug', $slug)->where('is_active', true)->first();
        abort_unless($collection, 404);
        $categorySlugs = json_decode($collection->category_slugs, true) ?: [];
        $query = $this->productQuery();
        if ($categorySlugs) $query->where(fn ($q) => $q->whereHas('categories', fn ($category) => $category->whereIn('slug', $categorySlugs))->orWhereHas('categories.parent', fn ($parent) => $parent->whereIn('slug', $categorySlugs)));
        else $query->whereRaw('1 = 0');
        $page = $query->paginate(min(max($request->integer('per_page', 24), 1), 100));
        $data = $page->getCollection()->map(fn ($p) => $this->productPayload($p));
        return response()->json(['success' => true, 'message' => 'Collection products retrieved.', 'data' => $data, 'pagination' => [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
            'count' => $data->count(), 'from' => $page->firstItem(), 'to' => $page->lastItem(), 'has_previous' => $page->currentPage() > 1, 'has_next' => $page->hasMorePages(),
        ]]);
    }

    public function addresses(Request $request)
    {
        $rows = DB::table('customer_addresses')->where('user_id', $request->user()->id)->orderByDesc('is_default')->orderByDesc('id')->get();
        return ApiResponse::successResponse($rows->map(fn ($a) => $this->addressPayload($a)), 'Addresses retrieved.');
    }

    private function addressPayload($a): array
    {
        return ['id' => $a->id, 'firstName' => $a->first_name, 'lastName' => $a->last_name, 'address1' => $a->address1, 'address2' => $a->address2, 'city' => $a->city, 'state' => $a->state, 'postalCode' => $a->postal_code, 'country' => $a->country, 'phone' => $a->phone, 'isDefault' => (bool) $a->is_default];
    }

    public function storeAddress(Request $request)
    {
        $data = $request->validate(['firstName' => 'required|string|max:100', 'lastName' => 'required|string|max:100', 'address1' => 'required|string|max:255', 'address2' => 'nullable|string|max:255', 'city' => 'required|string|max:120', 'state' => 'nullable|string|max:120', 'postalCode' => 'required|string|max:30', 'country' => 'required|string|size:2', 'phone' => 'nullable|string|max:40', 'isDefault' => 'sometimes|boolean']);
        $isDefault = ($data['isDefault'] ?? false) || !DB::table('customer_addresses')->where('user_id', $request->user()->id)->exists();
        $row = DB::table('customer_addresses')->insertGetId(['user_id' => $request->user()->id, 'first_name' => $data['firstName'], 'last_name' => $data['lastName'], 'address1' => $data['address1'], 'address2' => $data['address2'] ?? null, 'city' => $data['city'], 'state' => $data['state'] ?? null, 'postal_code' => $data['postalCode'], 'country' => strtoupper($data['country']), 'phone' => $data['phone'] ?? null, 'is_default' => $isDefault, 'created_at' => now(), 'updated_at' => now()]);
        if ($isDefault) DB::table('customer_addresses')->where('user_id', $request->user()->id)->where('id', '<>', $row)->update(['is_default' => false]);
        return ApiResponse::createdResponse($this->addressPayload(DB::table('customer_addresses')->find($row)), 'Address created.');
    }

    public function updateAddress(Request $request, int $id)
    {
        $a = DB::table('customer_addresses')->where('user_id', $request->user()->id)->where('id', $id)->first();
        abort_unless($a, 404);
        $data = $request->validate(['firstName' => 'sometimes|required|string|max:100', 'lastName' => 'sometimes|required|string|max:100', 'address1' => 'sometimes|required|string|max:255', 'address2' => 'nullable|string|max:255', 'city' => 'sometimes|required|string|max:120', 'state' => 'nullable|string|max:120', 'postalCode' => 'sometimes|required|string|max:30', 'country' => 'sometimes|required|string|size:2', 'phone' => 'nullable|string|max:40', 'isDefault' => 'sometimes|boolean']);
        $map = ['firstName' => 'first_name', 'lastName' => 'last_name', 'address1' => 'address1', 'address2' => 'address2', 'city' => 'city', 'state' => 'state', 'postalCode' => 'postal_code', 'country' => 'country', 'phone' => 'phone', 'isDefault' => 'is_default'];
        $update = [];
        foreach ($data as $key => $value) $update[$map[$key]] = $key === 'country' ? strtoupper($value) : $value;
        $update['updated_at'] = now();
        DB::table('customer_addresses')->where('id', $id)->update($update);
        if (($data['isDefault'] ?? false)) DB::table('customer_addresses')->where('user_id', $request->user()->id)->where('id', '<>', $id)->update(['is_default' => false]);
        return ApiResponse::successResponse($this->addressPayload(DB::table('customer_addresses')->find($id)), 'Address updated.');
    }

    public function deleteAddress(Request $request, int $id)
    {
        $address = DB::table('customer_addresses')->where('user_id', $request->user()->id)->where('id', $id)->first();
        abort_unless($address, 404);
        DB::table('customer_addresses')->where('id', $id)->delete();
        if ($address->is_default) {
            $next = DB::table('customer_addresses')->where('user_id', $request->user()->id)->orderBy('id')->first();
            if ($next) DB::table('customer_addresses')->where('id', $next->id)->update(['is_default' => true]);
        }
        return ApiResponse::deletedResponse('Address deleted.');
    }

    private function cartPayload($cart): array
    {
        $items = DB::table('cart_items as ci')->join('products as p', 'p.id', '=', 'ci.product_id')
            ->leftJoin('media as pm', 'pm.id', '=', 'p.feature_media_id')
            ->leftJoin('product_variants as v', 'v.id', '=', 'ci.variant_id')
            ->where('ci.cart_id', $cart->id)
            ->select('ci.*', 'p.name as product_name', 'p.slug', 'p.base_price', 'p.metadata',
                'pm.disk as image_disk', 'pm.file_path as image_path', 'v.name as variant_name',
                'v.sku as variant_sku', 'v.price as variant_price')->get()->map(function ($i) {
                $price = (float) ($i->variant_price ?? $i->base_price ?? 0);
                $metadata = is_array($i->metadata) ? $i->metadata : (json_decode($i->metadata ?? '[]', true) ?: []);
                $images = data_get($metadata, 'images', []);
                $imageUrl = $i->image_path ? \Illuminate\Support\Facades\Storage::disk($i->image_disk)->url($i->image_path) : (data_get($images, '0.url') ?: null);
                $inventory = DB::table('inventory_items')->where($i->variant_id ? 'variant_id' : 'product_id', $i->variant_id ?? $i->product_id)->first();
                $available = $inventory && $inventory->manage_inventory ? max(0, $inventory->stock_quantity - $inventory->reserved_quantity) : null;
                return [
                    'id' => $i->id, 'quantity' => $i->quantity, 'unitPrice' => $price, 'subtotal' => $price * $i->quantity,
                    'product' => ['id' => $i->product_id, 'name' => $i->product_name, 'slug' => $i->slug, 'imageUrl' => $imageUrl, 'stock' => $available, 'inStock' => $available === null ? true : $available > 0],
                    'variant' => $i->variant_id ? ['id' => $i->variant_id, 'name' => $i->variant_name, 'sku' => $i->variant_sku, 'stock' => $available] : null,
                ];
            })->values();
        $subtotal = $items->sum('subtotal');
        return ['id' => $cart->id, 'currency' => $cart->currency, 'items' => $items, 'subtotal' => $subtotal, 'discount' => 0, 'shipping' => 0, 'tax' => 0, 'total' => $subtotal];
    }

    private function currentCart(Request $request)
    {
        return DB::table('carts')->where('user_id', $request->user()->id)->first()
            ?? (function () use ($request) { $id = DB::table('carts')->insertGetId(['user_id' => $request->user()->id, 'currency' => 'USD', 'created_at' => now(), 'updated_at' => now()]); return DB::table('carts')->find($id); })();
    }

    public function validateInventory(Request $request)
    {
        $data = $request->validate([
            'items' => 'required|array|min:1|max:100',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.variant_id' => 'nullable|integer|exists:product_variants,id',
            'items.*.quantity' => 'required|integer|min:1|max:99',
        ]);

        $issues = [];
        foreach ($data['items'] as $index => $item) {
            $product = Product::where('id', $item['product_id'])->where('status', 'active')->first();
            if (!$product) {
                $issues[] = ['index' => $index, 'productId' => $item['product_id'], 'reason' => 'unavailable', 'message' => 'This product is unavailable.'];
                continue;
            }
            $variant = null;
            if (!empty($item['variant_id'])) {
                $variant = DB::table('product_variants')->where('id', $item['variant_id'])->where('product_id', $product->id)->where('status', 'active')->first();
                if (!$variant) {
                    $issues[] = ['index' => $index, 'productId' => $product->id, 'variantId' => $item['variant_id'], 'reason' => 'unavailable', 'message' => 'This product option is unavailable.'];
                    continue;
                }
            } elseif ($product->type === 'variable') {
                $issues[] = ['index' => $index, 'productId' => $product->id, 'reason' => 'variant_required', 'message' => 'Select a product option.'];
                continue;
            }
            $inventory = DB::table('inventory_items')->where($variant ? 'variant_id' : 'product_id', $variant->id ?? $product->id)->first();
            if (!$inventory) {
                $issues[] = ['index' => $index, 'productId' => $product->id, 'reason' => 'unavailable', 'message' => 'Inventory is not configured.'];
                continue;
            }
            $available = $inventory->manage_inventory ? max(0, $inventory->stock_quantity - $inventory->reserved_quantity) : null;
            if ($available === 0 || ($available !== null && $item['quantity'] > $available)) {
                $issues[] = ['index' => $index, 'productId' => $product->id, 'variantId' => $variant->id ?? null, 'requested' => $item['quantity'], 'available' => $available, 'reason' => $available === 0 ? 'out_of_stock' : 'insufficient_stock', 'message' => $available === 0 ? 'This item is out of stock.' : "Only {$available} available."];
            }
        }

        return ApiResponse::successResponse(['valid' => empty($issues), 'issues' => $issues, 'authoritative' => true, 'source' => 'server'], 'Inventory validated.');
    }

    public function cart(Request $request)
    {
        return ApiResponse::successResponse($this->cartPayload($this->currentCart($request)), 'Cart retrieved.');
    }

    public function mergeCart(Request $request)
    {
        $data = $request->validate([
            'items' => 'required|array|max:100',
            'items.*.product_slug' => 'required|string|max:255',
            'items.*.variant_sku' => 'nullable|string|max:100',
            'items.*.quantity' => 'required|integer|min:1|max:99',
        ]);
        $cart = $this->currentCart($request);
        DB::transaction(function () use ($data, $cart) {
            foreach ($data['items'] as $item) {
                $product = Product::where('slug', $item['product_slug'])->where('status', 'active')->first();
                if (!$product) continue;
                $variant = null;
                if (!empty($item['variant_sku'])) {
                    $variant = DB::table('product_variants')->where('product_id', $product->id)->where('sku', $item['variant_sku'])->where('status', 'active')->first();
                    if (!$variant) continue;
                } elseif ($product->type === 'variable') {
                    continue;
                }
                $inventory = DB::table('inventory_items')->where($variant ? 'variant_id' : 'product_id', $variant->id ?? $product->id)->first();
                if (!$inventory) continue;
                $existingQuery = DB::table('cart_items')->where('cart_id', $cart->id)->where('product_id', $product->id);
                $variant ? $existingQuery->where('variant_id', $variant->id) : $existingQuery->whereNull('variant_id');
                $existing = $existingQuery->lockForUpdate()->first();
                $quantity = min(99, (int) $item['quantity'] + (int) ($existing->quantity ?? 0));
                if ($inventory->manage_inventory) $quantity = min($quantity, max(0, $inventory->stock_quantity - $inventory->reserved_quantity));
                if ($quantity < 1) continue;
                if ($existing) DB::table('cart_items')->where('id', $existing->id)->update(['quantity' => $quantity, 'updated_at' => now()]);
                else DB::table('cart_items')->insert(['cart_id' => $cart->id, 'product_id' => $product->id, 'variant_id' => $variant->id ?? null, 'quantity' => $quantity, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        return ApiResponse::successResponse($this->cartPayload($cart), 'Guest cart merged with your account.');
    }

    public function addCartItem(Request $request)
    {
        $data = $request->validate(['product_id' => 'required|integer|exists:products,id', 'variant_id' => 'nullable|integer|exists:product_variants,id', 'quantity' => 'required|integer|min:1|max:99']);
        $cart = $this->currentCart($request);
        $product = Product::where('id', $data['product_id'])->where('status', 'active')->firstOrFail();
        if ($product->type === 'variable' && empty($data['variant_id'])) {
            throw ValidationException::withMessages(['variant_id' => ['Select a product variant before adding this item.']]);
        }
        $variant = null;
        if ($data['variant_id'] ?? null) {
            $variant = DB::table('product_variants')->where('id', $data['variant_id'])->where('product_id', $product->id)->where('status', 'active')->first();
            abort_unless($variant, 422, 'The selected variant is unavailable.');
        }
        $inventory = DB::table('inventory_items')->where($variant ? 'variant_id' : 'product_id', $variant->id ?? $product->id)->first();
        if (!$inventory) throw ValidationException::withMessages(['inventory' => ['Inventory is not configured for this product.']]);
        $existingQuantity = (int) DB::table('cart_items')->where('cart_id', $cart->id)->where('product_id', $product->id)->where('variant_id', $variant->id ?? null)->value('quantity');
        $requestedQuantity = $existingQuantity + $data['quantity'];
        if ($inventory->manage_inventory && ($inventory->stock_quantity - $inventory->reserved_quantity) < $requestedQuantity) {
            throw ValidationException::withMessages(['quantity' => ['The requested quantity is not available.']]);
        }
        DB::transaction(function () use ($cart, $data) {
            $existing = DB::table('cart_items')->where('cart_id', $cart->id)->where('product_id', $data['product_id'])->where('variant_id', $data['variant_id'] ?? null)->first();
            if ($existing) DB::table('cart_items')->where('id', $existing->id)->update(['quantity' => $existing->quantity + $data['quantity'], 'updated_at' => now()]);
            else DB::table('cart_items')->insert(['cart_id' => $cart->id, 'product_id' => $data['product_id'], 'variant_id' => $data['variant_id'] ?? null, 'quantity' => $data['quantity'], 'created_at' => now(), 'updated_at' => now()]);
        });
        return ApiResponse::successResponse($this->cartPayload($cart), 'Cart updated.');
    }

    public function updateCartItem(Request $request, int $id)
    {
        $data = $request->validate(['quantity' => 'required|integer|min:1|max:99']);
        $cart = $this->currentCart($request);
        $item = DB::table('cart_items')->where('cart_id', $cart->id)->where('id', $id)->first();
        abort_unless($item, 404);
        $inventory = DB::table('inventory_items')->where($item->variant_id ? 'variant_id' : 'product_id', $item->variant_id ?? $item->product_id)->first();
        if (!$inventory) throw ValidationException::withMessages(['inventory' => ['Inventory is not configured for this product.']]);
        if ($inventory->manage_inventory && ($inventory->stock_quantity - $inventory->reserved_quantity) < $data['quantity']) {
            throw ValidationException::withMessages(['quantity' => ['The requested quantity is not available.']]);
        }
        DB::table('cart_items')->where('id', $id)->update(['quantity' => $data['quantity'], 'updated_at' => now()]);
        return ApiResponse::successResponse($this->cartPayload($cart), 'Cart updated.');
    }

    public function deleteCartItem(Request $request, int $id)
    {
        $cart = $this->currentCart($request);
        DB::table('cart_items')->where('cart_id', $cart->id)->where('id', $id)->delete();
        return ApiResponse::successResponse($this->cartPayload($cart), 'Cart item removed.');
    }

    public function clearCart(Request $request)
    {
        $cart = $this->currentCart($request);
        DB::table('cart_items')->where('cart_id', $cart->id)->delete();
        return ApiResponse::successResponse($this->cartPayload($cart), 'Cart cleared.');
    }

    public function wishlist(Request $request)
    {
        $rows = DB::table('wishlists as w')->join('products as p', 'p.id', '=', 'w.product_id')
            ->where('w.user_id', $request->user()->id)->where('p.status', 'active')
            ->leftJoin('media as pm', 'pm.id', '=', 'p.feature_media_id')
            ->select('w.id', 'w.created_at', 'p.id as product_id', 'p.name', 'p.slug', 'p.base_price', 'p.metadata', 'pm.disk as image_disk', 'pm.file_path as image_path')
            ->paginate(min((int) $request->input('per_page', 24), 100));
        $data = $rows->getCollection()->map(function ($item) {
            $metadata = is_array($item->metadata) ? $item->metadata : (json_decode($item->metadata ?? '[]', true) ?: []);
            $imageUrl = $item->image_path
                ? \Illuminate\Support\Facades\Storage::disk($item->image_disk)->url($item->image_path)
                : (data_get($metadata, 'images.0.url') ?: null);
            return [
                'id' => $item->id, 'createdAt' => $item->created_at,
                'product' => ['id' => $item->product_id, 'name' => $item->name, 'slug' => $item->slug, 'imageUrl' => $imageUrl, 'price' => (float) $item->base_price, 'currency' => 'USD'],
            ];
        });
        return response()->json(['success' => true, 'message' => 'Wishlist retrieved.', 'data' => $data, 'pagination' => [
            'current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total(),
            'count' => $data->count(), 'from' => $rows->firstItem(), 'to' => $rows->lastItem(), 'has_previous' => $rows->currentPage() > 1, 'has_next' => $rows->hasMorePages(),
        ]]);
    }

    public function mergeWishlist(Request $request)
    {
        $data = $request->validate(['product_slugs' => 'required|array|max:200', 'product_slugs.*' => 'required|string|max:255']);
        $products = Product::whereIn('slug', array_unique($data['product_slugs']))->where('status', 'active')->pluck('id');
        foreach ($products as $productId) {
            DB::table('wishlists')->updateOrInsert(
                ['user_id' => $request->user()->id, 'product_id' => $productId],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }
        return ApiResponse::successResponse(['merged' => $products->count()], 'Guest wishlist merged with your account.');
    }

    public function addWishlist(Request $request)
    {
        $data = $request->validate(['product_id' => 'required|integer|exists:products,id']);
        $id = DB::table('wishlists')->where('user_id', $request->user()->id)->where('product_id', $data['product_id'])->value('id');
        if (!$id) $id = DB::table('wishlists')->insertGetId(['user_id' => $request->user()->id, 'product_id' => $data['product_id'], 'created_at' => now(), 'updated_at' => now()]);
        $row = DB::table('wishlists as w')->join('products as p', 'p.id', '=', 'w.product_id')->where('w.id', $id)
            ->leftJoin('media as pm', 'pm.id', '=', 'p.feature_media_id')
            ->select('w.id', 'p.id as product_id', 'p.name', 'p.slug', 'p.base_price', 'p.metadata', 'pm.disk as image_disk', 'pm.file_path as image_path')->first();
        $metadata = is_array($row->metadata) ? $row->metadata : (json_decode($row->metadata ?? '[]', true) ?: []);
        $imageUrl = $row->image_path
            ? \Illuminate\Support\Facades\Storage::disk($row->image_disk)->url($row->image_path)
            : (data_get($metadata, 'images.0.url') ?: null);
        return ApiResponse::createdResponse(['id' => $row->id, 'product' => ['id' => $row->product_id, 'name' => $row->name, 'slug' => $row->slug, 'imageUrl' => $imageUrl, 'price' => (float) $row->base_price, 'currency' => 'USD']], 'Wishlist item saved.');
    }

    public function deleteWishlist(Request $request, int $id)
    {
        DB::table('wishlists')->where('user_id', $request->user()->id)->where('id', $id)->delete();
        return ApiResponse::deletedResponse('Wishlist item removed.');
    }

    public function reviews(Request $request)
    {
        $q = DB::table('product_reviews as r')->join('users as u', 'u.id', '=', 'r.user_id')->where('r.status', 'approved');
        if ($request->filled('product_id')) $q->where('r.product_id', $request->integer('product_id'));
        $rows = $q->select('r.*', 'u.name as user_name')->latest('r.created_at')->paginate(min((int) $request->input('per_page', 10), 100));
        $data = $rows->getCollection()->map(fn ($r) => ['id' => $r->id, 'productId' => $r->product_id, 'rating' => $r->rating, 'title' => $r->title, 'body' => $r->body, 'user' => ['id' => $r->user_id, 'name' => $r->user_name], 'createdAt' => $r->created_at]);
        return response()->json(['success' => true, 'message' => 'Reviews retrieved.', 'data' => $data, 'pagination' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total(), 'count' => $data->count(), 'from' => $rows->firstItem(), 'to' => $rows->lastItem(), 'has_previous' => $rows->currentPage() > 1, 'has_next' => $rows->hasMorePages()]]);
    }

    public function createReview(Request $request)
    {
        $data = $request->validate(['product_id' => 'required|integer|exists:products,id', 'rating' => 'required|integer|min:1|max:5', 'title' => 'nullable|string|max:180', 'body' => 'required|string|max:5000']);
        DB::table('product_reviews')->updateOrInsert(['product_id' => $data['product_id'], 'user_id' => $request->user()->id], ['rating' => $data['rating'], 'title' => $data['title'] ?? null, 'body' => $data['body'], 'status' => 'pending', 'updated_at' => now(), 'created_at' => now()]);
        $review = DB::table('product_reviews')->where('product_id', $data['product_id'])->where('user_id', $request->user()->id)->first();
        return ApiResponse::createdResponse(['id' => $review->id, 'productId' => $review->product_id, 'rating' => $review->rating, 'title' => $review->title, 'body' => $review->body, 'user' => ['id' => $request->user()->id, 'name' => $request->user()->name], 'createdAt' => $review->created_at], 'Review submitted for moderation.');
    }


    public function updateProfile(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:255', 'email' => 'required|email|max:255|unique:users,email,' . $request->user()->id, 'phone' => 'nullable|string|max:40']);
        $user = $request->user();
        $user->update(['name' => $data['name'], 'email' => $data['email'], 'phone' => array_key_exists('phone', $data) ? $data['phone'] : $user->phone]);
        return ApiResponse::successResponse(new UserResource($user->fresh()), 'Profile updated.');
    }

    private function orderPayload($order): array
    {
        $items = DB::table('customer_order_items')->where('order_id', $order->id)->get()->map(fn ($i) => [
            'id' => $i->id, 'name' => $i->name, 'quantity' => $i->quantity, 'unitPrice' => (float) $i->unit_price,
            'subtotal' => (float) $i->subtotal, 'imageUrl' => $i->image_url, 'product' => $i->product_id ? ['id' => $i->product_id, 'slug' => $i->product_slug] : null,
            'variant' => $i->variant_id ? ['id' => $i->variant_id, 'sku' => $i->sku] : null,
        ])->values();
        $address = $order->shipping_address ? json_decode($order->shipping_address, true) : null;
        return ['id' => $order->id, 'number' => $order->number, 'status' => $order->status, 'paymentStatus' => $order->payment_status,
            'fulfillmentStatus' => $order->fulfillment_status, 'currency' => $order->currency, 'subtotal' => (float) $order->subtotal,
            'discount' => (float) $order->discount, 'shipping' => (float) $order->shipping, 'tax' => (float) $order->tax,
            'total' => (float) $order->total, 'createdAt' => $order->created_at, 'shippingAddress' => $address,
            'shippingMethod' => $order->shipping_method_code ? ['id' => $order->shipping_method_code, 'name' => $order->shipping_method_name, 'price' => (float) $order->shipping, 'estimatedDelivery' => $order->shipping_estimated_delivery] : null,
            'paymentMethod' => $order->payment_method, 'items' => $items, 'shipment' => $this->shipmentPayload($order->id)];
    }

    private function shipmentPayload(int $orderId): ?array
    {
        $shipment = DB::table('shipments')->where('order_id', $orderId)->first();
        if (!$shipment) return null;
        return [
            'id' => $shipment->id, 'carrier' => $shipment->carrier, 'trackingNumber' => $shipment->tracking_number,
            'trackingUrl' => $shipment->tracking_url, 'status' => $shipment->status,
            'estimatedDelivery' => $shipment->estimated_delivery, 'shippedAt' => $shipment->shipped_at,
            'deliveredAt' => $shipment->delivered_at,
        ];
    }

    public function orders(Request $request)
    {
        $orders = DB::table('customer_orders')->where('user_id', $request->user()->id)->latest()->paginate(20);
        $data = $orders->getCollection()->map(fn ($o) => $this->orderPayload($o));
        return response()->json(['success' => true, 'message' => 'Orders retrieved.', 'data' => $data, 'pagination' => [
            'current_page' => $orders->currentPage(), 'last_page' => $orders->lastPage(), 'per_page' => $orders->perPage(), 'total' => $orders->total(),
            'count' => $data->count(), 'from' => $orders->firstItem(), 'to' => $orders->lastItem(), 'has_previous' => $orders->currentPage() > 1, 'has_next' => $orders->hasMorePages()]]);
    }

    public function order(Request $request, int $id)
    {
        $order = DB::table('customer_orders')->where('user_id', $request->user()->id)->where('id', $id)->first();
        abort_unless($order, 404);
        return ApiResponse::successResponse($this->orderPayload($order), 'Order retrieved.');
    }

    public function createOrder(Request $request)
    {
        $data = $request->validate(['address_id' => 'required|integer', 'payment_method' => 'required|in:cod', 'shipping_method_id' => 'nullable|string|max:60', 'idempotency_key' => 'nullable|string|max:100', 'notes' => 'nullable|string|max:2000']);
        $userId = $request->user()->id;
        abort_unless(config('storefront.cash_on_delivery'), 422, 'Cash on delivery is not enabled.');
        $idempotencyKey = $request->header('Idempotency-Key') ?: ($data['idempotency_key'] ?? null);
        if ($idempotencyKey) {
            $existingOrder = DB::table('customer_orders')->where('user_id', $userId)->where('idempotency_key', $idempotencyKey)->first();
            if ($existingOrder) return ApiResponse::successResponse($this->orderPayload($existingOrder), 'Order already placed.');
        }
        $address = DB::table('customer_addresses')->where('id', $data['address_id'])->where('user_id', $userId)->first();
        abort_unless($address, 422, 'A valid shipping address is required.');
        $cart = $this->currentCart($request);
        $shippingConfigured = DB::table('shipping_methods')->where('is_active', true)->exists();
        if ($shippingConfigured && empty($data['shipping_method_id'])) {
            throw ValidationException::withMessages(['shipping_method_id' => ['Select an available shipping method.']]);
        }
        $shippingMethod = null;
        if (!empty($data['shipping_method_id'])) {
            $shippingMethod = DB::table('shipping_methods')->where('code', $data['shipping_method_id'])->where('is_active', true)->first();
            abort_unless($shippingMethod, 422, 'The selected shipping method is unavailable.');
            $allowedCountries = $shippingMethod->countries ? (json_decode($shippingMethod->countries, true) ?: []) : [];
            abort_if($allowedCountries && !in_array(strtoupper($address->country), $allowedCountries, true), 422, 'The selected shipping method is not available for this country.');
            abort_unless(strtoupper($shippingMethod->currency) === strtoupper($cart->currency), 422, 'The selected shipping method currency does not match the cart currency.');
        }
        $items = DB::table('cart_items')->where('cart_id', $cart->id)->get();
        if ($items->isEmpty()) throw ValidationException::withMessages(['cart' => ['Your cart is empty.']]);

        $order = DB::transaction(function () use ($items, $cart, $address, $data, $userId, $shippingMethod, $idempotencyKey) {
            $subtotal = 0;
            $lines = [];
            foreach ($items as $item) {
                $product = Product::where('id', $item->product_id)->where('status', 'active')->lockForUpdate()->first();
                if (!$product) throw ValidationException::withMessages(['cart' => ['A product is no longer available.']]);
                $variant = $item->variant_id ? DB::table('product_variants')->where('id', $item->variant_id)->where('product_id', $product->id)->where('status', 'active')->lockForUpdate()->first() : null;
                if ($item->variant_id && !$variant) throw ValidationException::withMessages(['cart' => ['A selected variant is no longer available.']]);
                $inventory = DB::table('inventory_items')->where($variant ? 'variant_id' : 'product_id', $variant?->id ?? $product->id)->lockForUpdate()->first();
                if (!$inventory) throw ValidationException::withMessages(['cart' => ["Inventory is not configured for {$product->name}."]]);
                $available = max(0, $inventory->stock_quantity - $inventory->reserved_quantity);
                if ($inventory->manage_inventory && $available < $item->quantity) throw ValidationException::withMessages(['cart' => ["Insufficient stock for {$product->name}."]]);
                $price = (float) ($variant->price ?? $product->base_price ?? 0);
                $line = $price * $item->quantity;
                $subtotal += $line;
                $lines[] = ['item' => $item, 'product' => $product, 'variant' => $variant, 'price' => $price, 'subtotal' => $line, 'inventory' => $inventory];
            }
            $number = 'BG-' . now()->format('Ymd') . '-' . Str::upper(Str::random(8));
            $addressData = ['firstName' => $address->first_name, 'lastName' => $address->last_name, 'address1' => $address->address1, 'address2' => $address->address2, 'city' => $address->city, 'state' => $address->state, 'postalCode' => $address->postal_code, 'country' => $address->country, 'phone' => $address->phone];
            $id = DB::table('customer_orders')->insertGetId(['number' => $number, 'idempotency_key' => $idempotencyKey, 'user_id' => $userId, 'address_id' => $address->id, 'status' => 'confirmed', 'payment_status' => 'pending', 'fulfillment_status' => 'pending', 'payment_method' => 'cod', 'shipping_method_code' => $shippingMethod->code ?? null, 'shipping_method_name' => $shippingMethod->name ?? null, 'shipping_estimated_delivery' => $shippingMethod->estimated_delivery ?? null, 'currency' => $cart->currency, 'subtotal' => $subtotal, 'discount' => 0, 'shipping' => (float) ($shippingMethod->price ?? 0), 'tax' => 0, 'total' => $subtotal + (float) ($shippingMethod->price ?? 0), 'notes' => $data['notes'] ?? null, 'shipping_address' => json_encode($addressData), 'created_at' => now(), 'updated_at' => now()]);
            foreach ($lines as $line) {
                $i = $line['item']; $p = $line['product']; $v = $line['variant']; $inventory = $line['inventory'];
                DB::table('customer_order_items')->insert(['order_id' => $id, 'product_id' => $p->id, 'variant_id' => $v?->id, 'name' => $p->name . ($v ? ' - ' . $v->name : ''), 'product_slug' => $p->slug, 'image_url' => data_get($p->metadata, 'images.0.url'), 'sku' => $v?->sku ?? $p->sku, 'quantity' => $i->quantity, 'unit_price' => $line['price'], 'subtotal' => $line['subtotal'], 'created_at' => now(), 'updated_at' => now()]);
                if ($inventory && $inventory->manage_inventory) DB::table('inventory_items')->where('id', $inventory->id)->update(['stock_quantity' => max(0, $inventory->stock_quantity - $i->quantity), 'updated_at' => now()]);
                DB::table('products')->where('id', $p->id)->increment('sold_count', $i->quantity);
            }
            DB::table('cart_items')->where('cart_id', $cart->id)->delete();
            return DB::table('customer_orders')->find($id);
        });
        return ApiResponse::createdResponse($this->orderPayload($order), 'Order placed successfully.');
    }

    public function upsertShipment(Request $request, int $orderId)
    {
        abort_unless($request->user()->hasRole(Role::ADMIN), 403);
        $order = DB::table('customer_orders')->where('id', $orderId)->first();
        abort_unless($order, 404);
        $data = $request->validate([
            'carrier' => 'nullable|string|max:120',
            'tracking_number' => 'nullable|string|max:160',
            'tracking_url' => ['nullable', 'url', 'regex:/^https:\\/\\//i'],
            'status' => 'required|in:pending,label_created,picked_up,in_transit,out_for_delivery,delivered,exception,returned',
            'estimated_delivery' => 'nullable|date',
            'shipped_at' => 'nullable|date',
            'delivered_at' => 'nullable|date',
            'description' => 'nullable|string|max:1000',
            'location' => 'nullable|string|max:180',
        ]);
        $existing = DB::table('shipments')->where('order_id', $orderId)->first();
        $values = [
            'carrier' => $data['carrier'] ?? null,
            'tracking_number' => $data['tracking_number'] ?? null,
            'tracking_url' => $data['tracking_url'] ?? null,
            'status' => $data['status'],
            'estimated_delivery' => $data['estimated_delivery'] ?? null,
            'shipped_at' => $data['shipped_at'] ?? null,
            'delivered_at' => $data['delivered_at'] ?? null,
            'updated_at' => now(),
        ];
        if ($existing) {
            DB::table('shipments')->where('id', $existing->id)->update($values);
            $shipmentId = $existing->id;
        } else {
            $shipmentId = DB::table('shipments')->insertGetId(array_merge($values, ['order_id' => $orderId, 'created_at' => now()]));
        }
        DB::table('shipment_events')->insert([
            'shipment_id' => $shipmentId, 'status' => $data['status'],
            'description' => $data['description'] ?? null, 'location' => $data['location'] ?? null,
            'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('customer_orders')->where('id', $orderId)->update([
            'fulfillment_status' => $data['status'] === 'delivered' ? 'delivered' : ($data['status'] === 'pending' ? 'pending' : 'shipped'),
            'status' => $data['status'] === 'delivered' ? 'delivered' : 'shipped',
            'updated_at' => now(),
        ]);
        return ApiResponse::successResponse($this->shipmentPayload($orderId), 'Shipment tracking updated.');
    }

    public function shippingMethods(Request $request)
    {
        $methods = DB::table('shipping_methods')->where('is_active', true)->orderBy('sort_order')->get();
        if ($methods->isEmpty()) return ApiResponse::errorResponse('Shipping is not configured yet.', null, 503);
        return ApiResponse::successResponse($methods->map(fn ($m) => [
            'id' => $m->code, 'name' => $m->name, 'description' => $m->description,
            'price' => (float) $m->price, 'estimatedDelivery' => $m->estimated_delivery,
        ])->values(), 'Shipping methods retrieved.');
    }

    public function shippingQuote(Request $request)
    {
        $data = $request->validate(['address_id' => 'required|integer', 'currency' => 'nullable|string|size:3']);
        $address = DB::table('customer_addresses')->where('id', $data['address_id'])->where('user_id', $request->user()->id)->first();
        abort_unless($address, 404);
        $methods = DB::table('shipping_methods')->where('is_active', true)->orderBy('sort_order')->get()->filter(function ($method) use ($address) {
            $countries = $method->countries ? (json_decode($method->countries, true) ?: []) : [];
            return !$countries || in_array(strtoupper($address->country), $countries, true);
        })->values();
        if ($methods->isEmpty()) return ApiResponse::errorResponse('No shipping methods are available for this address.', null, 503);
        $currency = strtoupper($data['currency'] ?? 'USD');
        $methods = $methods->where('currency', $currency)->values();
        if ($methods->isEmpty()) return ApiResponse::errorResponse('No shipping methods are configured for the requested currency.', null, 503);
        return ApiResponse::successResponse([
            'addressId' => $address->id,
            'methods' => $methods->map(fn ($m) => ['id' => $m->code, 'name' => $m->name, 'description' => $m->description, 'price' => (float) $m->price, 'estimatedDelivery' => $m->estimated_delivery])->values(),
            'rates' => $methods->map(fn ($m) => ['id' => 'rate-' . $m->code, 'methodId' => $m->code, 'amount' => (float) $m->price, 'currency' => $currency, 'source' => 'live', 'estimatedDelivery' => $m->estimated_delivery])->values(),
            'source' => 'live',
        ], 'Shipping quote retrieved.');
    }

    private function storePayload($profile, $user): array
    {
        return [
            'id' => $profile->id,
            'ownerId' => $user->id,
            'ownerUserId' => $user->id,
            'name' => $profile->name ?: $user->name,
            'slug' => $profile->slug,
            'description' => $profile->description,
            'logo' => $profile->image_url,
            'banner' => $profile->banner_url,
            'email' => $profile->email ?: $user->email,
            'phone' => $profile->phone ?: $user->phone,
            'status' => $profile->status,
            'commissionType' => $profile->commission_type,
            'commissionValue' => (float) $profile->commission_value,
            'rating' => $profile->rating ? (float) $profile->rating : null,
            'reviewCount' => (int) $profile->review_count,
            'productCount' => DB::table('products')->where('vendor_id', $user->id)->where('status', 'active')->count(),
            'createdAt' => $profile->created_at,
            'updatedAt' => $profile->updated_at,
            'owner' => ['id' => $user->id, 'firstName' => preg_split('/\\s+/', trim($user->name), 2)[0] ?? '', 'lastName' => preg_split('/\\s+/', trim($user->name), 2)[1] ?? '', 'email' => $user->email],
        ];
    }

    public function myStore(Request $request)
    {
        $profile = DB::table('vendor_profiles')->where('user_id', $request->user()->id)->whereNull('deleted_at')->first();
        if (!$profile) return ApiResponse::successResponse(null, 'No store has been created yet.');
        return ApiResponse::successResponse($this->storePayload($profile, $request->user()), 'Store retrieved.');
    }

    public function createStore(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|alpha_dash|unique:vendor_profiles,slug',
            'description' => 'nullable|string|max:5000',
            'logo' => 'nullable|url|max:500',
            'banner' => 'nullable|url|max:500',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
        ]);
        $user = $request->user();
        abort_if(DB::table('vendor_profiles')->where('user_id', $user->id)->whereNull('deleted_at')->exists(), 409, 'You already have a store.');
        $slug = $data['slug'] ?: Str::slug($data['name']);
        abort_if(DB::table('vendor_profiles')->where('slug', $slug)->exists(), 422, 'This store URL is already taken.');
        $id = DB::table('vendor_profiles')->insertGetId([
            'user_id' => $user->id, 'name' => $data['name'], 'slug' => $slug,
            'description' => $data['description'] ?? null, 'image_url' => $data['logo'] ?? null,
            'banner_url' => $data['banner'] ?? null, 'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null, 'status' => 'pending',
            'commission_type' => 'percentage', 'commission_value' => 10,
            'review_count' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return ApiResponse::createdResponse($this->storePayload(DB::table('vendor_profiles')->find($id), $user), 'Store created and submitted for approval.');
    }

    public function updateMyStore(Request $request)
    {
        $profile = DB::table('vendor_profiles')->where('user_id', $request->user()->id)->whereNull('deleted_at')->first();
        abort_unless($profile, 404, 'Store not found.');
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|alpha_dash|unique:vendor_profiles,slug,' . $profile->id,
            'description' => 'nullable|string|max:5000',
            'logo' => 'nullable|url|max:500',
            'banner' => 'nullable|url|max:500',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
        ]);
        DB::table('vendor_profiles')->where('id', $profile->id)->update([
            'name' => $data['name'], 'slug' => $data['slug'], 'description' => $data['description'] ?? null,
            'image_url' => $data['logo'] ?? null, 'banner_url' => $data['banner'] ?? null,
            'email' => $data['email'] ?? null, 'phone' => $data['phone'] ?? null, 'updated_at' => now(),
        ]);
        return ApiResponse::successResponse($this->storePayload(DB::table('vendor_profiles')->find($profile->id), $request->user()), 'Store updated.');
    }

    public function adminStores(Request $request)
    {
        $query = DB::table('vendor_profiles as vp')->join('users as u', 'u.id', '=', 'vp.user_id')->whereNull('vp.deleted_at')->select('vp.*', 'u.name as owner_name', 'u.email as owner_email', 'u.phone as owner_phone');
        if ($request->filled('status')) $query->where('vp.status', $request->input('status'));
        if ($request->filled('search')) $query->where(fn ($q) => $q->where('vp.name', 'like', '%' . $request->input('search') . '%')->orWhere('vp.slug', 'like', '%' . $request->input('search') . '%')->orWhere('u.email', 'like', '%' . $request->input('search') . '%'));
        $page = $query->orderByDesc('vp.created_at')->paginate(min(max($request->integer('per_page', 20), 1), 100));
        $data = $page->getCollection()->map(fn ($row) => $this->storePayload($row, (object) ['id' => $row->user_id, 'name' => $row->owner_name, 'email' => $row->owner_email, 'phone' => $row->owner_phone]));
        return response()->json(['success' => true, 'message' => 'Stores retrieved.', 'data' => $data, 'pagination' => [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
            'count' => $data->count(), 'from' => $page->firstItem(), 'to' => $page->lastItem(), 'has_previous' => $page->currentPage() > 1, 'has_next' => $page->hasMorePages(),
        ]]);
    }

    public function adminStore(int $id)
    {
        $row = DB::table('vendor_profiles as vp')->join('users as u', 'u.id', '=', 'vp.user_id')->where('vp.id', $id)->whereNull('vp.deleted_at')
            ->select('vp.*', 'u.name as owner_name', 'u.email as owner_email', 'u.phone as owner_phone')->first();
        abort_unless($row, 404);
        return ApiResponse::successResponse($this->storePayload($row, (object) ['id' => $row->user_id, 'name' => $row->owner_name, 'email' => $row->owner_email, 'phone' => $row->owner_phone]), 'Store retrieved.');
    }

    public function updateStoreStatus(Request $request, int $id)
    {
        $data = $request->validate(['status' => 'required|in:active,suspended,pending']);
        $profile = DB::table('vendor_profiles')->where('id', $id)->whereNull('deleted_at')->first();
        abort_unless($profile, 404);
        DB::table('vendor_profiles')->where('id', $id)->update([
            'status' => $data['status'],
            'approved_at' => $data['status'] === 'active' ? ($profile->approved_at ?: now()) : $profile->approved_at,
            'updated_at' => now(),
        ]);
        return ApiResponse::successResponse($this->adminStore($id)->getData(true)['data'], 'Store status updated.');
    }

    public function updateStoreCommission(Request $request, int $id)
    {
        $data = $request->validate([
            'commission_type' => 'required|in:percentage,fixed',
            'commission_value' => 'required|numeric|min:0|max:100000',
        ]);
        abort_if($data['commission_type'] === 'percentage' && $data['commission_value'] > 100, 422, 'Percentage commission cannot exceed 100.');
        $profile = DB::table('vendor_profiles')->where('id', $id)->whereNull('deleted_at')->first();
        abort_unless($profile, 404);
        DB::table('vendor_profiles')->where('id', $id)->update([
            'commission_type' => $data['commission_type'], 'commission_value' => $data['commission_value'], 'updated_at' => now(),
        ]);
        return ApiResponse::successResponse($this->adminStore($id)->getData(true)['data'], 'Store commission updated.');
    }

    public function paymentMethods()
    {
        $methods = [];
        if (config('storefront.cash_on_delivery')) {
            $methods[] = ['id' => 'cod', 'name' => 'Cash on delivery', 'description' => 'Pay when your order arrives.', 'available' => true, 'requiresProvider' => false];
        }
        return ApiResponse::successResponse($methods, 'Payment methods retrieved.');
    }
}
