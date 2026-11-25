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
    private function productQuery()
    {
        return Product::query()->where('status', 'active')
            ->with(['brand', 'categories', 'tags', 'featureMedia', 'media', 'variants.options.attribute', 'variants.attributeValues', 'variants.inventoryItems', 'inventoryItems']);
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
            'currency' => 'USD', 'status' => $p->status, 'stock' => $stock, 'inStock' => $stock > 0,
            'images' => $images, 'variants' => $p->variants->map(fn ($v) => [
                'id' => $v->id, 'sku' => $v->sku, 'name' => $v->name, 'price' => (float) $v->price,
                'compareAtPrice' => $v->compare_at_price ? (float) $v->compare_at_price : null,
                'stock' => $v->inventoryItems->sum(fn ($i) => max(0, $i->stock_quantity - $i->reserved_quantity)),
                'attributes' => $v->attributeValues->mapWithKeys(fn ($a) => [$a->attribute->name => $a->value]),
            ])->values(),
            'tags' => $p->tags->pluck('slug')->values(),
            'category' => $category ? ['id' => $category->id, 'name' => $category->name, 'slug' => $category->slug] : null,
            'brand' => $p->brand ? ['id' => $p->brand->id, 'name' => $p->brand->name, 'slug' => $p->brand->slug] : null,
            'rating' => null, 'reviewCount' => 0, 'featured' => (bool) data_get($p->metadata, 'featured', false),
            'newArrival' => (bool) data_get($p->metadata, 'new_arrival', false),
        ];
    }

    public function products(Request $request)
    {
        $query = $this->productQuery();
        if ($search = $request->string('search')->trim()->toString()) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('description', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%"));
        }
        if ($slug = $request->input('category')) $query->whereHas('categories', fn ($q) => $q->where('slug', $slug));
        if ($slug = $request->input('brand')) $query->whereHas('brand', fn ($q) => $q->where('slug', $slug));
        if ($request->filled('min_price')) $query->whereRaw('COALESCE(base_price, (SELECT MIN(price) FROM product_variants WHERE product_variants.product_id = products.id)) >= ?', [(float) $request->min_price]);
        if ($request->filled('max_price')) $query->whereRaw('COALESCE(base_price, (SELECT MIN(price) FROM product_variants WHERE product_variants.product_id = products.id)) <= ?', [(float) $request->max_price]);
        if ($request->boolean('in_stock')) $query->whereHas('inventoryItems', fn ($q) => $q->whereRaw('stock_quantity > reserved_quantity'));
        match ($request->input('sort', 'featured')) {
            'newest' => $query->latest(),
            'price_asc' => $query->orderByRaw('COALESCE(base_price, (SELECT MIN(price) FROM product_variants WHERE product_variants.product_id = products.id)) ASC'),
            'price_desc' => $query->orderByRaw('COALESCE(base_price, (SELECT MIN(price) FROM product_variants WHERE product_variants.product_id = products.id)) DESC'),
            default => $query->orderByDesc('created_at'),
        };
        $page = $query->paginate(min(max((int) $request->input('per_page', 24), 1), 100));
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

    public function categories()
    {
        $items = Category::where('status', 'active')->with(['children' => fn ($q) => $q->where('status', 'active')->orderBy('sort_order')])
            ->whereNull('parent_id')->orderBy('sort_order')->get();
        $map = fn ($c) => ['id' => $c->id, 'name' => $c->name, 'slug' => $c->slug, 'description' => $c->description,
            'productCount' => $c->products()->where('status', 'active')->count(),
            'children' => $c->children->map(fn ($child) => ['id' => $child->id, 'name' => $child->name, 'slug' => $child->slug, 'description' => $child->description, 'parentId' => $c->id, 'parentSlug' => $c->slug, 'productCount' => $child->products()->where('status', 'active')->count()])->values()];
        return ApiResponse::successResponse($items->map($map)->values(), 'Categories retrieved.');
    }

    public function category(string $slug)
    {
        $c = Category::where('slug', $slug)->where('status', 'active')->with('parent')->firstOrFail();
        return ApiResponse::successResponse(['id' => $c->id, 'name' => $c->name, 'slug' => $c->slug, 'description' => $c->description,
            'parentId' => $c->parent_id, 'parentSlug' => $c->parent?->slug, 'productCount' => $c->products()->where('status', 'active')->count()], 'Category retrieved.');
    }

    public function brands()
    {
        $items = Brand::where('status', 'active')->withCount(['products' => fn ($q) => $q->where('status', 'active')])->orderBy('name')->get()
            ->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'slug' => $b->slug, 'description' => $b->description, 'productCount' => $b->products_count]);
        return ApiResponse::successResponse($items, 'Brands retrieved.');
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

    public function vendors()
    {
        $vendors = \App\Models\User::role('vendor')->withCount(['' => fn ($q) => $q])->get();
        return ApiResponse::successResponse($vendors->map(fn ($v) => ['id' => $v->id, 'name' => $v->name, 'slug' => Str::slug($v->name), 'description' => null, 'rating' => null, 'reviewCount' => 0, 'productCount' => 0]), 'Vendors retrieved.');
    }

    public function vendor(string $slug)
    {
        $vendor = \App\Models\User::role('vendor')->get()->first(fn ($v) => Str::slug($v->name) === $slug);
        abort_unless($vendor, 404);
        return ApiResponse::successResponse(['id' => $vendor->id, 'name' => $vendor->name, 'slug' => $slug, 'description' => null, 'rating' => null, 'reviewCount' => 0, 'productCount' => 0], 'Vendor retrieved.');
    }

    public function vendorProducts(Request $request, string $slug)
    {
        return ApiResponse::successResponse([], 'Vendor products are not available until product ownership is added.');
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

    public function shippingMethods()
    {
        return ApiResponse::successResponse([], 'No shipping methods are configured yet.');
    }

    public function paymentMethods()
    {
        return ApiResponse::successResponse([['id' => 'cod', 'name' => 'Cash on delivery', 'description' => 'Pay when your order arrives.', 'available' => true, 'requiresProvider' => false]], 'Payment methods retrieved.');
    }
}
