<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorProfile extends Model
{
    protected $fillable = [
        'user_id',
        'slug',
        'description',
        'image_url',
        'rating',
        'review_count',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'decimal:2',
            'review_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
