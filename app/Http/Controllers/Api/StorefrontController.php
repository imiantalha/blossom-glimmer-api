<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Category;
use App\Models\Brand;
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
            ->with(['brand', 'vendor', 'categories.parent', 'tags', 'featureMedia', 'media', 'variants.options.attribute', 'variants.attributeValues', 'variants.inventoryItems', 'inventoryItems']);
    }

    private function productPayload(Product $p): array
    {
        $images = collect([$p->featureMedia])->merge($p->media)->filter()->unique('id')->values()->map(fn ($m) => [
            'id' => $m->id, 'url' => \Illuminate\Support\Facades\Storage::disk($m->disk)->url($m->file_path), 'alt' => $m->alt_text,
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
            'compareAtPrice' => $p->compare_at_price ? (float) $p->compare_at_price : null,
            'currency' => 'USD', 'status' => 'published', 'stock' => $stock, 'inStock' => $stock > 0,
            'images' => $images, 'variants' => $p->variants->map(fn ($v) => [
                'id' => $v->id, 'sku' => $v->sku, 'name' => $v->name, 'price' => (float) $v->price,
                'compareAtPrice' => $v->compare_at_price ? (float) $v->compare_at_price : null,
                'stock' => $v->inventoryItems->sum(fn ($i) => max(0, $i->stock_quantity - $i->reserved_quantity)),
                'attributes' => $v->attributeValues->mapWithKeys(fn ($a) => [$a->attribute->name => $a->value]),
            ])->values(),
            'tags' => $p->tags->pluck('slug')->values(),
            'category' => $category ? ['id' => $category->parent?->id ?? $category->id, 'name' => $category->parent?->name ?? $category->name, 'slug' => $category->parent?->slug ?? $category->slug] : null,
            'subcategory' => ($category && $category->parent) ? ['id' => $category->id, 'name' => $category->name, 'slug' => $category->slug] : null,
            'brand' => $p->brand ? ['id' => $p->brand->id, 'name' => $p->brand->name, 'slug' => $p->brand->slug] : null,
            'vendor' => $p->vendor ? ['id' => $p->vendor->id, 'name' => $p->vendor->name, 'slug' => Str::slug($p->vendor->name)] : null,
            'rating' => (float) DB::table('product_reviews')->where('product_id', $p->id)->where('status', 'approved')->avg('rating'),
            'reviewCount' => DB::table('product_reviews')->where('product_id', $p->id)->where('status', 'approved')->count(),
            'soldCount' => (int) data_get($p->metadata, 'sold_count', 0),
            'badges' => data_get($p->metadata, 'badges', []),
            'season' => data_get($p->metadata, 'season', []), 'occasion' => data_get($p->metadata, 'occasion', []),
            'collections' => data_get($p->metadata, 'collections', []), 'specifications' => data_get($p->metadata, 'specifications', []),
            'featured' => (bool) data_get($p->metadata, 'featured', false),
            'newArrival' => (bool) data_get($p->metadata, 'new_arrival', false),
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
            $query->whereHas('categories', fn ($q) => $q->where('slug', $slug));
        }
        if ($slug = $request->input('subcategory')) {
            $query->whereHas('categories', fn ($q) => $q->where('slug', $slug));
        }
        if ($slug = $request->input('brand')) {
            $query->whereHas('brand', fn ($q) => $q->where('slug', $slug));
        }
        if ($slug = $request->input('vendor')) {
            $query->whereHas('vendor', fn ($q) => $q->whereRaw('LOWER(name) = ?', [str_replace('-', ' ', strtolower($slug))]));
        }
        if ($request->filled('min_price')) {
            $query->whereRaw('COALESCE(base_price, (SELECT MIN(price) FROM product_variants WHERE product_variants.product_id = products.id AND product_variants.status = ?)) >= ?', ['active', (float) $request->min_price]);
        }
        if ($request->filled('max_price')) {
            $query->whereRaw('COALESCE(base_price, (SELECT MIN(price) FROM product_variants WHERE product_variants.product_id = products.id AND product_variants.status = ?)) <= ?', ['active', (float) $request->max_price]);
        }
        if ($request->boolean('discounted')) {
            $query->whereNotNull('compare_at_price')->whereColumn('compare_at_price', '>', 'base_price');
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
                if ($categorySlugs) $query->whereHas('categories', fn ($q) => $q->whereIn('slug', $categorySlugs));
            }
        }
        foreach ($request->query() as $key => $value) {
            if (str_starts_with($key, 'attribute_') && is_scalar($value)) {
                $attributeName = substr($key, 10);
                $query->whereHas('variants.attributeValues', fn ($q) => $q->whereHas('attribute', fn ($a) => $a->where('slug', Str::slug($attributeName)))->where('value', (string) $value));
            }
        }
        if ($request->filled('min_rating')) {
            $query->whereRaw('(SELECT AVG(rating) FROM product_reviews WHERE product_reviews.product_id = products.id AND product_reviews.status = ?) >= ?', ['approved', (float) $request->min_rating]);
        }

        $sort = $request->input('sort', 'featured');
        match ($sort) {
            'newest' => $query->latest(),
            'price_asc' => $query->orderByRaw('COALESCE(base_price, (SELECT MIN(price) FROM product_variants WHERE product_variants.product_id = products.id AND product_variants.status = ?)) ASC', ['active']),
            'price_desc' => $query->orderByRaw('COALESCE(base_price, (SELECT MIN(price) FROM product_variants WHERE product_variants.product_id = products.id AND product_variants.status = ?)) DESC', ['active']),
            'rating' => $query->orderByRaw('(SELECT AVG(rating) FROM product_reviews WHERE product_reviews.product_id = products.id AND product_reviews.status = ?) DESC', ['approved']),
            'best_selling' => $query->orderByDesc('created_at'),
            default => $query->orderByDesc('created_at'),
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
            'id' => $c->id, 'name' => $c->name, 'slug' => $c->slug, 'description' => $c->description,
            'productCount' => $c->products()->where('status', 'active')->count(),
            'children' => $c->children->map(fn ($child) => [
                'id' => $child->id, 'name' => $child->name, 'slug' => $child->slug, 'description' => $child->description,
                'parentId' => $c->id, 'parentSlug' => $c->slug, 'productCount' => $child->products()->where('status', 'active')->count(),
            ])->values(),
        ]);
        return $this->paginatedPayload($items, $request, 100);
    }

    public function category(string $slug)
    {
        $c = Category::where('slug', $slug)->where('status', 'active')->with('parent')->firstOrFail();
        return ApiResponse::successResponse(['id' => $c->id, 'name' => $c->name, 'slug' => $c->slug, 'description' => $c->description,
            'parentId' => $c->parent_id, 'parentSlug' => $c->parent?->slug, 'productCount' => $c->products()->where('status', 'active')->count()], 'Category retrieved.');
    }

    public function brands(Request $request)
    {
        $query = Brand::where('status', 'active')->withCount(['products' => fn ($q) => $q->where('status', 'active')])->orderBy('name');
        if ($search = $request->string('search')->trim()->toString()) $query->where('name', 'like', "%{$search}%");
        $items = $query->get()->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'slug' => $b->slug, 'description' => $b->description, 'productCount' => $b->products_count]);
        return $this->paginatedPayload($items, $request, 100);
    }

    public function brand(string $slug)
    {
        $b = Brand::where('slug', $slug)->where('status', 'active')->withCount(['products' => fn ($q) => $q->where('status', 'active')])->firstOrFail();
        return ApiResponse::successResponse(['id' => $b->id, 'name' => $b->name, 'slug' => $b->slug, 'description' => $b->description, 'productCount' => $b->products_count], 'Brand retrieved.');
    }

    public function brandProducts(Request $request, string $slug)
    {
        $request->merge(['brand' => $slug]);
        return $this->products($request);
    }

    public function vendors(Request $request)
    {
        $query = \App\Models\User::role('vendor')->orderBy('name');
        if ($search = $request->string('search')->trim()->toString()) $query->where('name', 'like', "%{$search}%");
        $items = $query->get()->map(fn ($v) => [
            'id' => $v->id, 'name' => $v->name, 'slug' => Str::slug($v->name), 'description' => null,
            'rating' => null, 'reviewCount' => 0,
            'productCount' => DB::table('products')->where('vendor_id', $v->id)->where('status', 'active')->count(),
        ]);
        return $this->paginatedPayload($items, $request, 100);
    }

    public function vendor(string $slug)
    {
        $vendor = \App\Models\User::role('vendor')->get()->first(fn ($v) => Str::slug($v->name) === $slug);
        abort_unless($vendor, 404);
        return ApiResponse::successResponse(['id' => $vendor->id, 'name' => $vendor->name, 'slug' => $slug, 'description' => null, 'rating' => null, 'reviewCount' => 0, 'productCount' => 0], 'Vendor retrieved.');
    }

    public function vendorProducts(Request $request, string $slug)
    {
        $vendor = \App\Models\User::role('vendor')->get()->first(fn ($v) => Str::slug($v->name) === $slug);
        abort_unless($vendor, 404);
        return ApiResponse::successResponse([], 'No vendor products are currently assigned.');
    }

    public function collections()
    {
        $items = DB::table('collections')->where('is_active', true)->orderBy('sort_order')->get()
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'slug' => $c->slug, 'description' => $c->description, 'imageUrl' => $c->image_url, 'categories' => json_decode($c->category_slugs, true) ?: []]);
        return ApiResponse::successResponse($items, 'Collections retrieved.');
    }

    public function collection(string $slug)
    {
        $c = DB::table('collections')->where('slug', $slug)->where('is_active', true)->first();
        abort_unless($c, 404);
        return ApiResponse::successResponse(['id' => $c->id, 'name' => $c->name, 'slug' => $c->slug, 'description' => $c->description, 'imageUrl' => $c->image_url, 'categories' => json_decode($c->category_slugs, true) ?: []], 'Collection retrieved.');
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
        $row = DB::table('customer_addresses')->insertGetId(['user_id' => $request->user()->id, 'first_name' => $data['firstName'], 'last_name' => $data['lastName'], 'address1' => $data['address1'], 'address2' => $data['address2'] ?? null, 'city' => $data['city'], 'state' => $data['state'] ?? null, 'postal_code' => $data['postalCode'], 'country' => strtoupper($data['country']), 'phone' => $data['phone'] ?? null, 'is_default' => $data['isDefault'] ?? false, 'created_at' => now(), 'updated_at' => now()]);
        if (($data['isDefault'] ?? false)) DB::table('customer_addresses')->where('user_id', $request->user()->id)->where('id', '<>', $row)->update(['is_default' => false]);
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
        DB::table('customer_addresses')->where('user_id', $request->user()->id)->where('id', $id)->delete();
        return ApiResponse::deletedResponse('Address deleted.');
    }

    private function cartPayload($cart): array
    {
        $items = DB::table('cart_items as ci')->join('products as p', 'p.id', '=', 'ci.product_id')->leftJoin('product_variants as v', 'v.id', '=', 'ci.variant_id')->where('ci.cart_id', $cart->id)->select('ci.*', 'p.name as product_name', 'p.slug', 'p.base_price', 'v.name as variant_name', 'v.price as variant_price')->get()->map(function ($i) {
            $price = (float) ($i->variant_price ?? $i->base_price ?? 0);
            return ['id' => $i->id, 'quantity' => $i->quantity, 'unitPrice' => $price, 'subtotal' => $price * $i->quantity, 'product' => ['id' => $i->product_id, 'name' => $i->product_name, 'slug' => $i->slug], 'variant' => $i->variant_id ? ['id' => $i->variant_id, 'name' => $i->variant_name] : null];
        })->values();
        $subtotal = $items->sum('subtotal');
        return ['id' => $cart->id, 'currency' => $cart->currency, 'items' => $items, 'subtotal' => $subtotal, 'discount' => 0, 'shipping' => 0, 'tax' => 0, 'total' => $subtotal];
    }

    private function currentCart(Request $request)
    {
        return DB::table('carts')->where('user_id', $request->user()->id)->first()
            ?? (function () use ($request) { $id = DB::table('carts')->insertGetId(['user_id' => $request->user()->id, 'currency' => 'USD', 'created_at' => now(), 'updated_at' => now()]); return DB::table('carts')->find($id); })();
    }

    public function cart(Request $request)
    {
        return ApiResponse::successResponse($this->cartPayload($this->currentCart($request)), 'Cart retrieved.');
    }

    public function addCartItem(Request $request)
    {
        $data = $request->validate(['product_id' => 'required|integer|exists:products,id', 'variant_id' => 'nullable|integer|exists:product_variants,id', 'quantity' => 'required|integer|min:1|max:99']);
        $cart = $this->currentCart($request);
        $product = Product::where('id', $data['product_id'])->where('status', 'active')->firstOrFail();
        if ($data['variant_id'] ?? null) abort_unless(DB::table('product_variants')->where('id', $data['variant_id'])->where('product_id', $product->id)->where('status', 'active')->exists(), 422);
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
        $rows = DB::table('wishlists as w')->join('products as p', 'p.id', '=', 'w.product_id')->where('w.user_id', $request->user()->id)->where('p.status', 'active')->select('w.id', 'w.created_at', 'p.id as product_id', 'p.name', 'p.slug', 'p.base_price')->paginate(min((int) $request->input('per_page', 24), 100));
        $data = $rows->getCollection()->map(fn ($i) => ['id' => $i->id, 'createdAt' => $i->created_at, 'product' => ['id' => $i->product_id, 'name' => $i->name, 'slug' => $i->slug, 'price' => (float) $i->base_price, 'currency' => 'USD']]);
        return response()->json(['success' => true, 'message' => 'Wishlist retrieved.', 'data' => $data, 'pagination' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total(), 'count' => $data->count(), 'from' => $rows->firstItem(), 'to' => $rows->lastItem(), 'has_previous' => $rows->currentPage() > 1, 'has_next' => $rows->hasMorePages()]]);
    }

    public function addWishlist(Request $request)
    {
        $data = $request->validate(['product_id' => 'required|integer|exists:products,id']);
        $id = DB::table('wishlists')->where('user_id', $request->user()->id)->where('product_id', $data['product_id'])->value('id');
        if (!$id) $id = DB::table('wishlists')->insertGetId(['user_id' => $request->user()->id, 'product_id' => $data['product_id'], 'created_at' => now(), 'updated_at' => now()]);
        $row = DB::table('wishlists as w')->join('products as p', 'p.id', '=', 'w.product_id')->where('w.id', $id)->select('w.id', 'p.id as product_id', 'p.name', 'p.slug', 'p.base_price')->first();
        return ApiResponse::createdResponse(['id' => $row->id, 'product' => ['id' => $row->product_id, 'name' => $row->name, 'slug' => $row->slug, 'price' => (float) $row->base_price, 'currency' => 'USD']], 'Wishlist item saved.');
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
        $id = DB::table('product_reviews')->updateOrInsert(['product_id' => $data['product_id'], 'user_id' => $request->user()->id], ['rating' => $data['rating'], 'title' => $data['title'] ?? null, 'body' => $data['body'], 'status' => 'pending', 'updated_at' => now(), 'created_at' => now()]);
        return ApiResponse::createdResponse(null, 'Review submitted for moderation.');
    }


    public function updateProfile(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:255', 'email' => 'required|email|max:255|unique:users,email,' . $request->user()->id, 'phone' => 'nullable|string|max:40']);
        $request->user()->update(['name' => $data['name'], 'email' => $data['email']]);
        return ApiResponse::successResponse(array_merge($request->user()->fresh()->only('id', 'name', 'email'), ['phone' => $data['phone'] ?? null]), 'Profile updated.');
    }

    private function orderPayload($order): array
    {
        $items = DB::table('customer_order_items')->where('order_id', $order->id)->get()->map(fn ($i) => [
            'id' => $i->id, 'name' => $i->name, 'quantity' => $i->quantity, 'unitPrice' => (float) $i->unit_price,
            'subtotal' => (float) $i->subtotal, 'product' => $i->product_id ? ['id' => $i->product_id] : null,
            'variant' => $i->variant_id ? ['id' => $i->variant_id, 'sku' => $i->sku] : null,
        ])->values();
        $address = $order->shipping_address ? json_decode($order->shipping_address, true) : null;
        return ['id' => $order->id, 'number' => $order->number, 'status' => $order->status, 'paymentStatus' => $order->payment_status,
            'fulfillmentStatus' => $order->fulfillment_status, 'currency' => $order->currency, 'subtotal' => (float) $order->subtotal,
            'discount' => (float) $order->discount, 'shipping' => (float) $order->shipping, 'tax' => (float) $order->tax,
            'total' => (float) $order->total, 'createdAt' => $order->created_at, 'shippingAddress' => $address,
            'paymentMethod' => $order->payment_method, 'items' => $items, 'shipment' => null];
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
        $data = $request->validate(['address_id' => 'required|integer', 'payment_method' => 'required|in:cod', 'notes' => 'nullable|string|max:2000']);
        $userId = $request->user()->id;
        $address = DB::table('customer_addresses')->where('id', $data['address_id'])->where('user_id', $userId)->first();
        abort_unless($address, 422, 'A valid shipping address is required.');
        $cart = $this->currentCart($request);
        $items = DB::table('cart_items')->where('cart_id', $cart->id)->get();
        if ($items->isEmpty()) throw ValidationException::withMessages(['cart' => ['Your cart is empty.']]);

        $order = DB::transaction(function () use ($items, $cart, $address, $data, $userId) {
            $subtotal = 0;
            $lines = [];
            foreach ($items as $item) {
                $product = Product::where('id', $item->product_id)->where('status', 'active')->lockForUpdate()->first();
                if (!$product) throw ValidationException::withMessages(['cart' => ['A product is no longer available.']]);
                $variant = $item->variant_id ? DB::table('product_variants')->where('id', $item->variant_id)->where('product_id', $product->id)->where('status', 'active')->lockForUpdate()->first() : null;
                if ($item->variant_id && !$variant) throw ValidationException::withMessages(['cart' => ['A selected variant is no longer available.']]);
                $inventory = DB::table('inventory_items')->where($variant ? 'variant_id' : 'product_id', $variant?->id ?? $product->id)->lockForUpdate()->first();
                $available = $inventory ? max(0, $inventory->stock_quantity - $inventory->reserved_quantity) : 0;
                if ($inventory && $inventory->manage_inventory && $available < $item->quantity) throw ValidationException::withMessages(['cart' => ["Insufficient stock for {$product->name}."]]);
                $price = (float) ($variant->price ?? $product->base_price ?? 0);
                $line = $price * $item->quantity;
                $subtotal += $line;
                $lines[] = ['item' => $item, 'product' => $product, 'variant' => $variant, 'price' => $price, 'subtotal' => $line, 'inventory' => $inventory];
            }
            $number = 'BG-' . now()->format('Ymd') . '-' . Str::upper(Str::random(8));
            $addressData = ['firstName' => $address->first_name, 'lastName' => $address->last_name, 'address1' => $address->address1, 'address2' => $address->address2, 'city' => $address->city, 'state' => $address->state, 'postalCode' => $address->postal_code, 'country' => $address->country, 'phone' => $address->phone];
            $id = DB::table('customer_orders')->insertGetId(['number' => $number, 'user_id' => $userId, 'address_id' => $address->id, 'status' => 'confirmed', 'payment_status' => 'pending', 'fulfillment_status' => 'pending', 'payment_method' => 'cod', 'currency' => $cart->currency, 'subtotal' => $subtotal, 'discount' => 0, 'shipping' => 0, 'tax' => 0, 'total' => $subtotal, 'notes' => $data['notes'] ?? null, 'shipping_address' => json_encode($addressData), 'created_at' => now(), 'updated_at' => now()]);
            foreach ($lines as $line) {
                $i = $line['item']; $p = $line['product']; $v = $line['variant']; $inventory = $line['inventory'];
                DB::table('customer_order_items')->insert(['order_id' => $id, 'product_id' => $p->id, 'variant_id' => $v?->id, 'name' => $p->name . ($v ? ' - ' . $v->name : ''), 'sku' => $v?->sku ?? $p->sku, 'quantity' => $i->quantity, 'unit_price' => $line['price'], 'subtotal' => $line['subtotal'], 'created_at' => now(), 'updated_at' => now()]);
                if ($inventory && $inventory->manage_inventory) DB::table('inventory_items')->where('id', $inventory->id)->update(['stock_quantity' => max(0, $inventory->stock_quantity - $i->quantity), 'updated_at' => now()]);
            }
            DB::table('cart_items')->where('cart_id', $cart->id)->delete();
            return DB::table('customer_orders')->find($id);
        });
        return ApiResponse::createdResponse($this->orderPayload($order), 'Order placed successfully.');
    }

    public function shippingMethods()
    {
        return ApiResponse::successResponse([], 'No shipping methods are configured yet.');
    }

    public function paymentMethods()
    {
        return ApiResponse::successResponse([['id' => 'cod', 'name' => 'Cash on delivery', 'description' => 'Pay when your order arrives.', 'available' => true, 'requiresProvider' => false]], 'Payment methods retrieved.');
    }
}
