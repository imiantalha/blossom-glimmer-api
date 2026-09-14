<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Media extends Model
{
    use HasFactory;

    protected $fillable = [
        'file_path',
        'disk',
        'mime_type',
        'alt_text',
        'width',
        'height',
    ];

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)->withPivot('sort_order');
    }

    public function variants(): BelongsToMany
    {
        return $this->belongsToMany(ProductVariant::class, 'variant_media')->withPivot('sort_order');
    }
}
