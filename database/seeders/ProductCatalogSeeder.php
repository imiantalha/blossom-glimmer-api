<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $brands = [];

        foreach ([
            ['name' => 'UrbanCraft', 'description' => 'Modern everyday products.'],
            ['name' => 'Nova Goods', 'description' => 'Practical products for modern living.'],
        ] as $brand) {
            $brands[$brand['name']] = DB::table('brands')->insertGetId([
                'name' => $brand['name'],
                'slug' => Str::slug($brand['name']),
                'description' => $brand['description'],
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $categories = [];

        foreach ([
            ['name' => 'Clothing', 'parent_id' => null],
            ['name' => 'Men', 'parent_id' => 'Clothing'],
            ['name' => 'Shirts', 'parent_id' => 'Men'],
            ['name' => 'Accessories', 'parent_id' => null],
            ['name' => 'Bags', 'parent_id' => 'Accessories'],
        ] as $category) {
            $categories[$category['name']] = DB::table('categories')->insertGetId([
                'parent_id' => $category['parent_id'] ? $categories[$category['parent_id']] : null,
                'name' => $category['name'],
                'slug' => Str::slug($category['name']),
                'description' => $category['name'] . ' category',
                'status' => 'active',
                'sort_order' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $attributes = [];

        foreach ([
            ['name' => 'Color', 'type' => 'select', 'is_variant_attribute' => true],
            ['name' => 'Size', 'type' => 'select', 'is_variant_attribute' => true],
            ['name' => 'Material', 'type' => 'select', 'is_variant_attribute' => true],
            ['name' => 'Weight', 'type' => 'text', 'is_variant_attribute' => false],
        ] as $attribute) {
            $attributes[$attribute['name']] = DB::table('attributes')->insertGetId([
                'name' => $attribute['name'],
                'slug' => Str::slug($attribute['name']),
                'type' => $attribute['type'],
                'is_variant_attribute' => $attribute['is_variant_attribute'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $attributeValues = [];

        foreach ([
            'Color' => ['Black', 'White', 'Blue'],
            'Size' => ['S', 'M', 'L'],
            'Material' => ['Cotton', 'Linen', 'Wool', 'Leather'],
            'Weight' => ['350g', '900g'],
        ] as $attributeName => $values) {
            foreach ($values as $value) {
                $attributeValues[$attributeName][$value] = DB::table('attribute_values')->insertGetId([
                    'attribute_id' => $attributes[$attributeName],
                    'value' => $value,
                    'slug' => Str::slug($value),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $tags = [];

        foreach (['bestseller', 'new-arrival', 'cotton', 'office', 'casual'] as $tag) {
            $tags[$tag] = DB::table('tags')->insertGetId([
                'name' => Str::title(str_replace('-', ' ', $tag)),
                'slug' => $tag,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $simpleProductId = DB::table('products')->insertGetId([
            'brand_id' => $brands['Nova Goods'],
            'name' => 'Classic Leather Backpack',
            'slug' => 'classic-leather-backpack',
            'sku' => 'BAG-CLASSIC-001',
            'type' => 'simple',
            'short_description' => 'A durable leather backpack for work and everyday use.',
            'description' => 'Classic leather backpack with a practical office-friendly design.',
            'base_price' => 89.99,
            'compare_at_price' => 109.99,
            'cost_price' => 48.00,
            'status' => 'active',
            'metadata' => json_encode(['material' => 'Leather']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $variableProductId = DB::table('products')->insertGetId([
            'brand_id' => $brands['UrbanCraft'],
            'name' => 'Premium Cotton T-Shirt',
            'slug' => 'premium-cotton-t-shirt',
            'sku' => null,
            'type' => 'variable',
            'short_description' => 'Premium everyday t-shirt with multiple options.',
            'description' => 'Soft t-shirt available in different colors, sizes and materials.',
            'base_price' => null,
            'compare_at_price' => null,
            'cost_price' => null,
            'status' => 'active',
            'metadata' => json_encode(['fit' => 'regular']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('product_category')->insert([
            ['product_id' => $simpleProductId, 'category_id' => $categories['Bags']],
            ['product_id' => $variableProductId, 'category_id' => $categories['Shirts']],
        ]);

        foreach ([
            [$simpleProductId, $tags['office']],
            [$simpleProductId, $tags['bestseller']],
            [$variableProductId, $tags['new-arrival']],
            [$variableProductId, $tags['cotton']],
            [$variableProductId, $tags['casual']],
        ] as [$productId, $tagId]) {
            DB::table('product_tag')->insert([
                'product_id' => $productId,
                'tag_id' => $tagId,
            ]);
        }

        DB::table('product_attribute_values')->insert([
            [
                'product_id' => $simpleProductId,
                'attribute_value_id' => $attributeValues['Material']['Leather'],
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'product_id' => $simpleProductId,
                'attribute_value_id' => $attributeValues['Weight']['900g'],
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $productOptions = [];

        foreach (['Color', 'Size', 'Material'] as $attributeName) {
            $productOptions[$attributeName] = DB::table('product_options')->insertGetId([
                'product_id' => $variableProductId,
                'attribute_id' => $attributes[$attributeName],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ([
            'Color' => ['Black', 'White'],
            'Size' => ['S', 'M'],
            'Material' => ['Cotton', 'Linen'],
        ] as $attributeName => $values) {
            foreach ($values as $value) {
                DB::table('product_option_values')->insert([
                    'product_option_id' => $productOptions[$attributeName],
                    'attribute_value_id' => $attributeValues[$attributeName][$value],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
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

            $variantId = DB::table('product_variants')->insertGetId([
                'product_id' => $variableProductId,
                'sku' => 'TSH-' . strtoupper($color[0] . $size . substr($material, 0, 2)),
                'combination_key' => $combinationKey,
                'name' => $color . ' / ' . $size . ' / ' . $material,
                'price' => $price,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ([
                ['Color', $color],
                ['Size', $size],
                ['Material', $material],
            ] as [$attributeName, $value]) {
                DB::table('variant_option_values')->insert([
                    'variant_id' => $variantId,
                    'product_option_id' => $productOptions[$attributeName],
                    'attribute_value_id' => $attributeValues[$attributeName][$value],
                ]);
            }

            DB::table('inventory_items')->insert([
                'variant_id' => $variantId,
                'manage_inventory' => true,
                'stock_quantity' => $stock,
                'reserved_quantity' => 0,
                'low_stock_threshold' => 5,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('inventory_items')->insert([
            'product_id' => $simpleProductId,
            'manage_inventory' => true,
            'stock_quantity' => 35,
            'reserved_quantity' => 0,
            'low_stock_threshold' => 5,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
