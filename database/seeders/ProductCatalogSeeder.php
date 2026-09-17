<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductCatalogSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $now = now();

            $brands = [];

            foreach ([
                ['name' => 'UrbanCraft', 'description' => 'Modern everyday products.'],
                ['name' => 'Nova Goods', 'description' => 'Practical products for modern living.'],
            ] as $brand) {
                $slug = Str::slug($brand['name']);

                DB::table('brands')->updateOrInsert(
                    ['slug' => $slug],
                    [
                        'name' => $brand['name'],
                        'description' => $brand['description'],
                        'status' => 'active',
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );

                $brands[$brand['name']] = DB::table('brands')
                    ->where('slug', $slug)
                    ->value('id');
            }

            $categories = [];

            foreach ([
                ['name' => 'Clothing', 'parent' => null],
                ['name' => 'Men', 'parent' => 'Clothing'],
                ['name' => 'Shirts', 'parent' => 'Men'],
                ['name' => 'Accessories', 'parent' => null],
                ['name' => 'Bags', 'parent' => 'Accessories'],
            ] as $category) {
                $slug = Str::slug($category['name']);
                $parentId = $category['parent']
                    ? $categories[$category['parent']]
                    : null;

                DB::table('categories')->updateOrInsert(
                    ['slug' => $slug],
                    [
                        'parent_id' => $parentId,
                        'name' => $category['name'],
                        'description' => $category['name'] . ' category',
                        'status' => 'active',
                        'sort_order' => 0,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );

                $categories[$category['name']] = DB::table('categories')
                    ->where('slug', $slug)
                    ->value('id');
            }

            $attributes = [];

            foreach ([
                ['name' => 'Color', 'type' => 'select', 'is_variant_attribute' => true],
                ['name' => 'Size', 'type' => 'select', 'is_variant_attribute' => true],
                ['name' => 'Material', 'type' => 'select', 'is_variant_attribute' => true],
                ['name' => 'Weight', 'type' => 'text', 'is_variant_attribute' => false],
            ] as $attribute) {
                $slug = Str::slug($attribute['name']);

                DB::table('attributes')->updateOrInsert(
                    ['slug' => $slug],
                    [
                        'name' => $attribute['name'],
                        'type' => $attribute['type'],
                        'is_variant_attribute' => $attribute['is_variant_attribute'],
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );

                $attributes[$attribute['name']] = DB::table('attributes')
                    ->where('slug', $slug)
                    ->value('id');
            }

            $attributeValues = [];

            foreach ([
                'Color' => ['Black', 'White', 'Blue'],
                'Size' => ['S', 'M', 'L'],
                'Material' => ['Cotton', 'Linen', 'Wool', 'Leather'],
                'Weight' => ['350g', '900g'],
            ] as $attributeName => $values) {
                foreach ($values as $value) {
                    $slug = Str::slug($value);

                    DB::table('attribute_values')->updateOrInsert(
                        [
                            'attribute_id' => $attributes[$attributeName],
                            'slug' => $slug,
                        ],
                        [
                            'value' => $value,
                            'updated_at' => $now,
                            'created_at' => $now,
                        ]
                    );

                    $attributeValues[$attributeName][$value] = DB::table('attribute_values')
                        ->where('attribute_id', $attributes[$attributeName])
                        ->where('slug', $slug)
                        ->value('id');
                }
            }

            $tags = [];

            foreach (['bestseller', 'new-arrival', 'cotton', 'office', 'casual'] as $tag) {
                DB::table('tags')->updateOrInsert(
                    ['slug' => $tag],
                    [
                        'name' => Str::title(str_replace('-', ' ', $tag)),
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );

                $tags[$tag] = DB::table('tags')
                    ->where('slug', $tag)
                    ->value('id');
            }

            DB::table('products')->updateOrInsert(
                ['slug' => 'classic-leather-backpack'],
                [
                    'brand_id' => $brands['Nova Goods'],
                    'name' => 'Classic Leather Backpack',
                    'sku' => 'BAG-CLASSIC-001',
                    'type' => 'simple',
                    'short_description' => 'A durable leather backpack for work and everyday use.',
                    'description' => 'Classic leather backpack with a practical office-friendly design.',
                    'base_price' => 89.99,
                    'compare_at_price' => 109.99,
                    'cost_price' => 48.00,
                    'status' => 'active',
                    'metadata' => json_encode(['material' => 'Leather']),
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );

            $simpleProductId = DB::table('products')
                ->where('slug', 'classic-leather-backpack')
                ->value('id');

            DB::table('products')->updateOrInsert(
                ['slug' => 'premium-cotton-t-shirt'],
                [
                    'brand_id' => $brands['UrbanCraft'],
                    'name' => 'Premium Cotton T-Shirt',
                    'sku' => null,
                    'type' => 'variable',
                    'short_description' => 'Premium everyday t-shirt with multiple options.',
                    'description' => 'Soft t-shirt available in different colors, sizes and materials.',
                    'base_price' => null,
                    'compare_at_price' => null,
                    'cost_price' => null,
                    'status' => 'active',
                    'metadata' => json_encode(['fit' => 'regular']),
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );

            $variableProductId = DB::table('products')
                ->where('slug', 'premium-cotton-t-shirt')
                ->value('id');

            foreach ([
                [$simpleProductId, $categories['Bags']],
                [$variableProductId, $categories['Shirts']],
            ] as [$productId, $categoryId]) {
                DB::table('product_category')->updateOrInsert([
                    'product_id' => $productId,
                    'category_id' => $categoryId,
                ]);
            }

            foreach ([
                [$simpleProductId, $tags['office']],
                [$simpleProductId, $tags['bestseller']],
                [$variableProductId, $tags['new-arrival']],
                [$variableProductId, $tags['cotton']],
                [$variableProductId, $tags['casual']],
            ] as [$productId, $tagId]) {
                DB::table('product_tag')->updateOrInsert([
                    'product_id' => $productId,
                    'tag_id' => $tagId,
                ]);
            }

            foreach ([
                [$simpleProductId, $attributeValues['Material']['Leather']],
                [$simpleProductId, $attributeValues['Weight']['900g']],
            ] as [$productId, $attributeValueId]) {
                DB::table('product_attribute_values')->updateOrInsert(
                    [
                        'product_id' => $productId,
                        'attribute_value_id' => $attributeValueId,
                    ],
                    [
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );
            }

            $productOptions = [];

            foreach (['Color', 'Size', 'Material'] as $attributeName) {
                DB::table('product_options')->updateOrInsert(
                    [
                        'product_id' => $variableProductId,
                        'attribute_id' => $attributes[$attributeName],
                    ],
                    [
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );

                $productOptions[$attributeName] = DB::table('product_options')
                    ->where('product_id', $variableProductId)
                    ->where('attribute_id', $attributes[$attributeName])
                    ->value('id');
            }

            foreach ([
                'Color' => ['Black', 'White'],
                'Size' => ['S', 'M'],
                'Material' => ['Cotton', 'Linen'],
            ] as $attributeName => $values) {
                foreach ($values as $value) {
                    DB::table('product_option_values')->updateOrInsert(
                        [
                            'product_option_id' => $productOptions[$attributeName],
                            'attribute_value_id' => $attributeValues[$attributeName][$value],
                        ],
                        [
                            'updated_at' => $now,
                            'created_at' => $now,
                        ]
                    );
                }
            }

            $variants = [
                ['Black', 'S', 'Cotton', 24.99, 18],
                ['Black', 'M', 'Cotton', 24.99, 22],
                ['White', 'S', 'Cotton', 24.99, 15],
                ['White', 'M', 'Cotton', 24.99, 20],
                ['Black', 'S', 'Linen', 29.99, 8],
                ['Black', 'M', 'Linen', 29.99, 12],
                ['White', 'S', 'Linen', 29.99, 7],
                ['White', 'M', 'Linen', 29.99, 10],
            ];

            foreach ($variants as [$color, $size, $material, $price, $stock]) {
                $valueIds = [
                    $attributeValues['Color'][$color],
                    $attributeValues['Size'][$size],
                    $attributeValues['Material'][$material],
                ];

                sort($valueIds);
                $combinationKey = implode('-', $valueIds);
                $sku = 'TSH-' . strtoupper($color[0] . $size . substr($material, 0, 2));

                DB::table('product_variants')->updateOrInsert(
                    [
                        'product_id' => $variableProductId,
                        'combination_key' => $combinationKey,
                    ],
                    [
                        'sku' => $sku,
                        'name' => $color . ' / ' . $size . ' / ' . $material,
                        'price' => $price,
                        'status' => 'active',
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );

                $variantId = DB::table('product_variants')
                    ->where('product_id', $variableProductId)
                    ->where('combination_key', $combinationKey)
                    ->value('id');

                foreach ([
                    ['Color', $color],
                    ['Size', $size],
                    ['Material', $material],
                ] as [$attributeName, $value]) {
                    DB::table('variant_option_values')->updateOrInsert(
                        [
                            'variant_id' => $variantId,
                            'product_option_id' => $productOptions[$attributeName],
                        ],
                        [
                            'attribute_value_id' => $attributeValues[$attributeName][$value],
                        ]
                    );
                }

                DB::table('inventory_items')->updateOrInsert(
                    ['variant_id' => $variantId],
                    [
                        'product_id' => null,
                        'manage_inventory' => true,
                        'stock_quantity' => $stock,
                        'reserved_quantity' => 0,
                        'low_stock_threshold' => 5,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );
            }

            DB::table('inventory_items')->updateOrInsert(
                ['product_id' => $simpleProductId],
                [
                    'variant_id' => null,
                    'manage_inventory' => true,
                    'stock_quantity' => 35,
                    'reserved_quantity' => 0,
                    'low_stock_threshold' => 5,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        });
    }
}
