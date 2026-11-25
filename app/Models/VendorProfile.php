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
        'review_count', 'name', 'banner_url', 'email', 'phone', 'status', 'commission_type', 'commission_value', 'approved_at', 'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'decimal:2',
            'review_count' => 'integer',
            'commission_value' => 'decimal:2',
            'approved_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
