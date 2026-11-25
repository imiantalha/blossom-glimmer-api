<?php

namespace Database\Seeders;

use App\Constants\Role as RoleConstant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class StorefrontDemoSeeder extends Seeder
{
    private array $categoryIds = [];
    private array $brandIds = [];
    private array $vendorIds = [];
    private array $productIds = [];

    public function run(): void
    {
        if (!config('storefront.seed_demo_catalog')) {
            $this->command?->info('Storefront demo catalog seeding is disabled.');
            return;
        }

        DB::transaction(function (): void {
            Role::firstOrCreate(['name' => RoleConstant::VENDOR, 'guard_name' => 'web']);
            Role::firstOrCreate(['name' => RoleConstant::CUSTOMER, 'guard_name' => 'web']);

            $categories = $this->loadData('categories.json');
            $brands = $this->loadData('brands.json');
            $vendors = $this->loadData('vendors.json');
            $collections = $this->loadData('collections.json');
            $products = $this->loadBatches('products-*.json');
            $reviews = $this->loadBatches('reviews-*.json');

            $this->seedCategories($categories);
            $this->seedBrands($brands);
            $this->seedVendors($vendors);
            $this->seedCollections($collections);
            $this->seedProducts($products);
            $this->seedReviews($reviews);
        });

        $this->command?->info(sprintf(
            'Storefront demo catalog seeded: %d products, %d categories, %d brands, %d vendors, %d reviews.',
            count($this->productIds),
            count($this->categoryIds),
            count($this->brandIds),
            count($this->vendorIds),
            count($this->loadBatches('reviews-*.json'))
        ));
    }

    private function loadData(string $file): array
    {
        $path = database_path('seeders/data/' . $file);
        if (!is_file($path)) return [];
        $decoded = json_decode(file_get_contents($path), true);
        return is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
    }

    private function loadBatches(string $pattern): array
    {
        $files = glob(database_path('seeders/data/' . $pattern)) ?: [];
        sort($files);
        $items = [];
        foreach ($files as $file) {
            $decoded = json_decode(file_get_contents($file), true);
            if (is_array($decoded['data'] ?? null)) {
                $items = array_merge($items, $decoded['data']);
            }
        }
        return $items;
    }

    private function seedCategories(array $categories): void
    {
        foreach ($categories as $category) {
            DB::table('categories')->updateOrInsert(
                ['slug' => $category['slug']],
                [
                    'parent_id' => null,
                    'name' => $category['name'],
                    'description' => $category['description'] ?? null,
                    'image_url' => $category['imageUrl'] ?? null,
                    'status' => 'active',
                    'sort_order' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
            $this->categoryIds[$category['slug']] = DB::table('categories')->where('slug', $category['slug'])->value('id');
        }

        foreach ($categories as $category) {
            foreach ($category['children'] ?? [] as $child) {
                DB::table('categories')->updateOrInsert(
                    ['slug' => $child['slug']],
                    [
                        'parent_id' => $this->categoryIds[$category['slug']],
                        'name' => $child['name'],
                        'description' => $child['description'] ?? null,
                        'image_url' => $child['imageUrl'] ?? null,
                        'status' => 'active',
                        'sort_order' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
                $this->categoryIds[$child['slug']] = DB::table('categories')->where('slug', $child['slug'])->value('id');
            }
        }
    }

    private function seedBrands(array $brands): void
    {
        foreach ($brands as $brand) {
            DB::table('brands')->updateOrInsert(
                ['slug' => $brand['slug']],
                [
                    'name' => $brand['name'],
                    'description' => $brand['description'] ?? null,
                    'image_url' => $brand['imageUrl'] ?? null,
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
            $this->brandIds[$brand['slug']] = DB::table('brands')->where('slug', $brand['slug'])->value('id');
        }
    }

    private function seedVendors(array $vendors): void
    {
        foreach ($vendors as $vendor) {
            $email = 'vendor+' . $vendor['slug'] . '@demo.blossomglimmer.local';
            $user = User::firstOrCreate(
                ['email' => $email],
                ['name' => $vendor['name'], 'password' => Str::random(48)]
            );
            if (!$user->hasRole(RoleConstant::VENDOR)) {
                $user->assignRole(RoleConstant::VENDOR);
            }

            DB::table('vendor_profiles')->updateOrInsert(
                ['user_id' => $user->id],
                [
                    'slug' => $vendor['slug'],
                    'description' => $vendor['description'] ?? null,
                    'image_url' => $vendor['imageUrl'] ?? null,
                    'rating' => $vendor['rating'] ?? null,
                    'review_count' => $vendor['reviewCount'] ?? 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
            $this->vendorIds[$vendor['slug']] = $user->id;
        }
    }

    private function seedCollections(array $collections): void
    {
        foreach ($collections as $collection) {
            DB::table('collections')->updateOrInsert(
                ['slug' => $collection['slug']],
                [
                    'name' => $collection['title'],
                    'eyebrow' => $collection['eyebrow'] ?? null,
                    'description' => $collection['description'] ?? null,
                    'image_url' => $collection['heroImage'] ?? null,
                    'category_slugs' => json_encode($collection['categories'] ?? []),
                    'featured_stores' => json_encode($collection['featuredStores'] ?? []),
                    'is_active' => true,
                    'sort_order' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    private function seedProducts(array $products): void
    {
        foreach ($products as $product) {
            $brandId = $this->brandIds[$product['brand']['slug'] ?? ''] ?? null;
            $vendorId = $this->vendorIds[$product['vendor']['slug'] ?? ''] ?? null;
            $variants = $product['variants'] ?? [];
            $metadata = [
                'source_id' => $product['id'],
                'featured' => (bool) ($product['featured'] ?? false),
                'new_arrival' => (bool) ($product['newArrival'] ?? false),
                'sold_count' => (int) ($product['soldCount'] ?? 0),
                'rating' => $product['rating'] ?? null,
                'review_count' => (int) ($product['reviewCount'] ?? 0),
                'badges' => $product['badges'] ?? [],
                'season' => $product['season'] ?? [],
                'occasion' => $product['occasion'] ?? [],
                'collections' => $product['collections'] ?? [],
                'specifications' => $product['specifications'] ?? [],
                'images' => $product['images'] ?? [],
                'tags' => $product['tags'] ?? [],
            ];

            DB::table('products')->updateOrInsert(
                ['slug' => $product['slug']],
                [
                    'brand_id' => $brandId,
                    'vendor_id' => $vendorId,
                    'name' => $product['name'],
                    'sku' => $product['sku'] ?? null,
                    'type' => count($variants) ? 'variable' : 'simple',
                    'short_description' => Str::limit($product['description'] ?? '', 180),
                    'description' => $product['description'] ?? null,
                    'base_price' => $product['price'] ?? null,
                    'compare_at_price' => $product['compareAtPrice'] ?? null,
                    'cost_price' => null,
                    'status' => ($product['status'] ?? 'published') === 'published' ? 'active' : 'draft',
                    'metadata' => json_encode($metadata),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
            $productId = DB::table('products')->where('slug', $product['slug'])->value('id');
            $this->productIds[$product['id']] = $productId;

            DB::table('product_category')->where('product_id', $productId)->delete();
            $categorySlug = $product['subcategory']['slug'] ?? $product['category']['slug'] ?? null;
            if ($categorySlug && isset($this->categoryIds[$categorySlug])) {
                DB::table('product_category')->insert([
                    'product_id' => $productId,
                    'category_id' => $this->categoryIds[$categorySlug],
                ]);
            }

            DB::table('product_tag')->where('product_id', $productId)->delete();
            foreach ($product['tags'] ?? [] as $tagName) {
                $tagSlug = Str::slug($tagName);
                DB::table('tags')->updateOrInsert(
                    ['slug' => $tagSlug],
                    ['name' => Str::title(str_replace('-', ' ', $tagSlug)), 'created_at' => now(), 'updated_at' => now()]
                );
                $tagId = DB::table('tags')->where('slug', $tagSlug)->value('id');
                DB::table('product_tag')->insertOrIgnore(['product_id' => $productId, 'tag_id' => $tagId]);
            }

            foreach ($variants as $variant) {
                $attributes = $variant['attributes'] ?? [];
                ksort($attributes);
                $combinationKey = sha1(json_encode($attributes));
                $variantSku = $variant['sku'] ?? ($product['slug'] . '-' . substr($combinationKey, 0, 8));
                DB::table('product_variants')->updateOrInsert(
                    ['product_id' => $productId, 'combination_key' => $combinationKey],
                    [
                        'sku' => $variantSku,
                        'name' => $variant['name'] ?? null,
                        'price' => $variant['price'] ?? $product['price'] ?? 0,
                        'compare_at_price' => $variant['compareAtPrice'] ?? null,
                        'cost_price' => null,
                        'status' => 'active',
                        'metadata' => json_encode(['attributes' => $attributes, 'source_id' => $variant['id'] ?? null]),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
                $variantId = DB::table('product_variants')->where('product_id', $productId)->where('combination_key', $combinationKey)->value('id');
                DB::table('inventory_items')->updateOrInsert(
                    ['variant_id' => $variantId],
                    [
                        'product_id' => null,
                        'manage_inventory' => true,
                        'stock_quantity' => max(0, (int) ($variant['stock'] ?? 0)),
                        'reserved_quantity' => 0,
                        'low_stock_threshold' => 5,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }

            if (!count($variants)) {
                DB::table('inventory_items')->updateOrInsert(
                    ['product_id' => $productId],
                    [
                        'variant_id' => null,
                        'manage_inventory' => true,
                        'stock_quantity' => max(0, (int) ($product['stock'] ?? 0)),
                        'reserved_quantity' => 0,
                        'low_stock_threshold' => 5,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }

    private function seedReviews(array $reviews): void
    {
        foreach ($reviews as $review) {
            $productId = $this->productIds[$review['productId'] ?? ''] ?? null;
            if (!$productId) continue;

            $reviewerId = (string) ($review['user']['id'] ?? Str::uuid());
            $email = 'reviewer+' . Str::slug($reviewerId) . '@demo.blossomglimmer.local';
            $reviewer = User::firstOrCreate(
                ['email' => $email],
                ['name' => $review['user']['name'] ?? 'Verified Customer', 'password' => Str::random(48)]
            );

            DB::table('product_reviews')->updateOrInsert(
                ['product_id' => $productId, 'user_id' => $reviewer->id],
                [
                    'rating' => max(1, min(5, (int) $review['rating'])),
                    'title' => $review['title'] ?? null,
                    'body' => $review['body'] ?? '',
                    'status' => 'approved',
                    'created_at' => isset($review['createdAt']) ? Carbon::parse($review['createdAt']) : now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
