<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReviewManagementController extends Controller
{
    public function index(Request $request)
    {
        $query = DB::table('product_reviews as r')
            ->join('users as u', 'u.id', '=', 'r.user_id')
            ->join('products as p', 'p.id', '=', 'r.product_id')
            ->select('r.*', 'u.name as user_name', 'u.email as user_email', 'p.name as product_name', 'p.slug as product_slug');

        if ($request->filled('status')) {
            $query->where('r.status', strtolower($request->input('status')));
        }
        if ($request->filled('search')) {
            $search = $request->string('search')->trim()->toString();
            $query->where(function ($builder) use ($search) {
                $builder->where('p.name', 'like', "%{$search}%")
                    ->orWhere('r.title', 'like', "%{$search}%")
                    ->orWhere('r.body', 'like', "%{$search}%")
                    ->orWhere('u.name', 'like', "%{$search}%")
                    ->orWhere('u.email', 'like', "%{$search}%");
            });
        }

        $page = $query->orderByDesc('r.created_at')->paginate(min(max($request->integer('per_page', 20), 1), 100));
        $data = $page->getCollection()->map(fn ($review) => $this->payload($review))->values();

        return response()->json([
            'success' => true,
            'message' => 'Product reviews retrieved.',
            'data' => $data,
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'count' => $data->count(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
                'has_previous' => $page->currentPage() > 1,
                'has_next' => $page->hasMorePages(),
            ],
        ]);
    }

    public function review(Request $request, int $id)
    {
        $data = $request->validate([
            'decision' => 'required|in:approve,reject',
            'rejection_reason' => 'required_if:decision,reject|nullable|string|min:5|max:2000',
        ]);

        $review = DB::transaction(function () use ($request, $id, $data) {
            $review = DB::table('product_reviews')->where('id', $id)->lockForUpdate()->first();
            abort_unless($review, 404);
            if ($review->status !== 'pending') {
                abort(409, 'This review has already been moderated.');
            }

            DB::table('product_reviews')->where('id', $id)->update([
                'status' => $data['decision'] === 'approve' ? 'approved' : 'rejected',
                'reviewed_by' => $request->user()->id,
                'rejection_reason' => $data['decision'] === 'reject' ? $data['rejection_reason'] : null,
                'updated_at' => now(),
            ]);

            return DB::table('product_reviews as r')
                ->join('users as u', 'u.id', '=', 'r.user_id')
                ->join('products as p', 'p.id', '=', 'r.product_id')
                ->select('r.*', 'u.name as user_name', 'u.email as user_email', 'p.name as product_name', 'p.slug as product_slug')
                ->where('r.id', $id)
                ->first();
        });

        DB::table('marketplace_notifications')->insert([
            'user_id' => $review->user_id,
            'type' => 'review.' . $review->status,
            'title' => $review->status === 'approved' ? 'Your product review was published' : 'Your product review was not approved',
            'message' => $review->status === 'approved'
                ? 'Your review of "' . $review->product_name . '" is now visible.'
                : 'Your review of "' . $review->product_name . '" was not approved. ' . ($review->rejection_reason ?? ''),
            'action_url' => '/product/' . $review->product_slug,
            'data' => json_encode(['review_id' => $review->id, 'product_id' => $review->product_id]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return ApiResponse::successResponse($this->payload($review), 'Product review ' . $review->status . '.');
    }

    private function payload(object $review): array
    {
        return [
            'id' => (string) $review->id,
            'product' => ['id' => (string) $review->product_id, 'name' => $review->product_name, 'slug' => $review->product_slug],
            'rating' => (int) $review->rating,
            'title' => $review->title,
            'body' => $review->body,
            'status' => $review->status,
            'rejectionReason' => $review->rejection_reason,
            'customer' => ['id' => (string) $review->user_id, 'name' => $review->user_name, 'email' => $review->user_email],
            'createdAt' => $review->created_at,
            'updatedAt' => $review->updated_at,
        ];
    }
}
