<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\CatalogRequest;
use App\Models\Category;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CatalogRequestController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'type' => 'required|in:brand,category',
            'name' => 'required|string|min:2|max:255',
            'slug' => 'nullable|string|max:255|alpha_dash',
            'description' => 'nullable|string|max:5000',
            'logo' => 'nullable|url|max:500',
            'image' => 'nullable|url|max:500',
            'parent_id' => 'nullable|integer|exists:categories,id',
        ]);

        if ($data['type'] === 'brand' && !empty($data['parent_id'])) {
            throw ValidationException::withMessages(['parent_id' => ['Brands cannot have a parent category.']]);
        }

        if ($data['type'] === 'category' && !empty($data['parent_id'])) {
            $parent = Category::findOrFail($data['parent_id']);
            if ($parent->parent_id !== null || $parent->status !== 'active') {
                throw ValidationException::withMessages(['parent_id' => ['Choose an active root category.']]);
            }
        }

        $slug = $data['slug'] ?? Str::slug($data['name']);
        $table = $data['type'] === 'brand' ? 'brands' : 'categories';
        if (DB::table($table)->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages(['slug' => ['This catalog URL is already in use.']]);
        }

        $pendingDuplicate = CatalogRequest::query()
            ->where('vendor_id', $request->user()->id)
            ->where('type', $data['type'])
            ->where('status', 'pending')
            ->where('slug', $slug)
            ->exists();
        if ($pendingDuplicate) {
            throw ValidationException::withMessages(['slug' => ['You already have a pending request for this item.']]);
        }

        $catalogRequest = CatalogRequest::create([
            'vendor_id' => $request->user()->id,
            'type' => $data['type'],
            'status' => 'pending',
            'name' => $data['name'],
            'slug' => $slug,
            'details' => [
                'description' => $data['description'] ?? null,
                'logo' => $data['logo'] ?? null,
                'image' => $data['image'] ?? null,
                'parent_id' => $data['parent_id'] ?? null,
            ],
        ]);

        return ApiResponse::createdResponse($this->payload($catalogRequest->load('vendor')), 'Catalog request submitted for admin approval.');
    }

    public function vendorIndex(Request $request)
    {
        $requests = CatalogRequest::query()
            ->where('vendor_id', $request->user()->id)
            ->latest()
            ->paginate(min(max($request->integer('per_page', 20), 1), 100));

        return response()->json([
            'success' => true,
            'message' => 'Your catalog requests were retrieved.',
            'data' => $requests->getCollection()->map(fn (CatalogRequest $item) => $this->payload($item))->values(),
            'pagination' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'per_page' => $requests->perPage(),
                'total' => $requests->total(),
                'count' => $requests->count(),
                'from' => $requests->firstItem(),
                'to' => $requests->lastItem(),
                'has_previous' => $requests->currentPage() > 1,
                'has_next' => $requests->hasMorePages(),
            ],
        ]);
    }

    public function adminIndex(Request $request)
    {
        $query = CatalogRequest::query()->with('vendor');
        if ($request->filled('type')) $query->where('type', $request->input('type'));
        if ($request->filled('status')) $query->where('status', $request->input('status'));
        if ($request->filled('search')) {
            $search = $request->string('search')->trim()->toString();
            $query->where(fn ($builder) => $builder->where('name', 'like', "%{$search}%")
                ->orWhere('slug', 'like', "%{$search}%")
                ->orWhereHas('vendor', fn ($vendor) => $vendor->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")));
        }
        $requests = $query->latest()->paginate(min(max($request->integer('per_page', 20), 1), 100));

        return response()->json([
            'success' => true,
            'message' => 'Catalog requests retrieved.',
            'data' => $requests->getCollection()->map(fn (CatalogRequest $item) => $this->payload($item))->values(),
            'pagination' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'per_page' => $requests->perPage(),
                'total' => $requests->total(),
                'count' => $requests->count(),
                'from' => $requests->firstItem(),
                'to' => $requests->lastItem(),
                'has_previous' => $requests->currentPage() > 1,
                'has_next' => $requests->hasMorePages(),
            ],
        ]);
    }

    public function review(Request $request, int $id)
    {
        $data = $request->validate([
            'decision' => 'required|in:approve,reject',
            'rejection_reason' => 'required_if:decision,reject|nullable|string|min:5|max:2000',
        ]);

        $result = DB::transaction(function () use ($request, $id, $data) {
            $catalogRequest = CatalogRequest::query()->lockForUpdate()->findOrFail($id);
            if ($catalogRequest->status !== 'pending') {
                abort(409, 'This catalog request has already been reviewed.');
            }

            $entity = null;
            if ($data['decision'] === 'approve') {
                $details = $catalogRequest->details ?? [];
                $table = $catalogRequest->type === 'brand' ? 'brands' : 'categories';
                if (DB::table($table)->where('slug', $catalogRequest->slug)->exists()) {
                    abort(409, 'The requested catalog URL is already in use. Edit the request before approving it.');
                }

                if ($catalogRequest->type === 'brand') {
                    $entity = Brand::create([
                        'name' => $catalogRequest->name,
                        'slug' => $catalogRequest->slug,
                        'description' => $details['description'] ?? null,
                        'image_url' => $details['logo'] ?? null,
                        'status' => 'active',
                    ]);
                } else {
                    $parentId = $details['parent_id'] ?? null;
                    if ($parentId) {
                        $parent = Category::findOrFail($parentId);
                        if ($parent->parent_id !== null || $parent->status !== 'active') {
                            abort(409, 'The requested parent category is no longer available.');
                        }
                    }
                    $entity = Category::create([
                        'name' => $catalogRequest->name,
                        'slug' => $catalogRequest->slug,
                        'parent_id' => $parentId,
                        'description' => $details['description'] ?? null,
                        'image_url' => $details['image'] ?? null,
                        'status' => 'active',
                    ]);
                }
            }

            $catalogRequest->update([
                'status' => $data['decision'] === 'approve' ? 'approved' : 'rejected',
                'reviewed_by' => $request->user()->id,
                'rejection_reason' => $data['decision'] === 'reject' ? $data['rejection_reason'] : null,
                'entity_id' => $entity?->id,
            ]);

            return $catalogRequest->fresh(['vendor', 'reviewer']);
        });

        return ApiResponse::successResponse($this->payload($result, true), 'Catalog request ' . $result->status . '.');
    }

    private function payload(CatalogRequest $item, bool $includeEntity = false): array
    {
        $details = $item->details ?? [];
        $entity = null;
        if ($includeEntity && $item->entity_id) {
            $entity = [
                'id' => (string) $item->entity_id,
                'type' => $item->type,
                'slug' => $item->slug,
            ];
        }

        return [
            'id' => (string) $item->id,
            'type' => $item->type,
            'status' => $item->status,
            'name' => $item->name,
            'slug' => $item->slug,
            'details' => $details,
            'rejectionReason' => $item->rejection_reason,
            'entity' => $entity,
            'vendor' => $item->vendor ? ['id' => (string) $item->vendor->id, 'name' => $item->vendor->name, 'email' => $item->vendor->email] : null,
            'reviewer' => $item->reviewer ? ['id' => (string) $item->reviewer->id, 'name' => $item->reviewer->name] : null,
            'createdAt' => $item->created_at?->toISOString(),
            'updatedAt' => $item->updated_at?->toISOString(),
        ];
    }
}
