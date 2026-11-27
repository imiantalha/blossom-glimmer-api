<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogRequest extends Model
{
    protected $fillable = [
        'vendor_id',
        'reviewed_by',
        'type',
        'status',
        'name',
        'slug',
        'details',
        'rejection_reason',
        'entity_id',
    ];

    protected function casts(): array
    {
        return ['details' => 'array'];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vendor_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
