<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'sku',
        'barcode',
        'name',
        'price',
        'compare_at_price',
        'cost_price',
        'status',
        'metadata',
        'combination_key',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function options(): BelongsToMany
    {
        return $this->belongsToMany(ProductOption::class, 'variant_option_values', 'variant_id', 'product_option_id')
            ->withPivot('attribute_value_id');
    }

    public function attributeValues(): BelongsToMany
    {
        return $this->belongsToMany(AttributeValue::class, 'variant_option_values', 'variant_id', 'attribute_value_id');
    }

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'variant_media', 'variant_id', 'media_id')
            ->withPivot('sort_order');
    }

    public function inventoryItems(): HasMany
    {
        return $this->hasMany(InventoryItem::class, 'variant_id');
    }
}
