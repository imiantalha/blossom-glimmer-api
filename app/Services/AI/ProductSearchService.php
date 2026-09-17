<?php

namespace App\Services\AI;

use App\Models\Product;
use Illuminate\Support\Collection;
class ProductSearchService
{
    public function search(string $query, array $filters = []): Collection
    {
        $search = trim($query);
        
        if (empty($search)) {
            return collect([]);
        }

        return Product::query()
            ->where('status', 'active')
            ->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('short_description', 'like', "%{$search}%")
                    ->orWhereHas('brand', function ($query) use ($search) {
                        $query->where('name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('categories', function ($query) use ($search) {
                        $query->where('name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('tags', function ($query) use ($search) {
                        $query->where('name', 'like', "%{$search}%");
                    });
            })
            ->with(['brand', 'categories', 'tags'])
            ->get();
    }
}
