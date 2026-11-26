<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VendorReviewController extends Controller
{
    public function index(Request $request)
    {
        $query = DB::table('product_reviews as r')
            ->join('products as p', 'p.id', '=', 'r.product_id')
            ->join('users as u', 'u.id', '=', 'r.user_id')
            ->where('p.vendor_id', $request->user()->id)
            ->select('r.id', 'r.product_id', 'r.user_id', 'r.rating', 'r.title', 'r.body', 'r.status', 'r.vendor_reply', 'r.vendor_replied_at', 'r.created_at', 'p.name as product_name', 'p.slug as product_slug', 'u.name as customer_name');
        if ($request->filled('status')) $query->where('r.status', $request->string('status')->toString());
        if ($request->filled('product_id')) $query->where('r.product_id', $request->integer('product_id'));
        $page = $query->orderByDesc('r.created_at')->paginate(min(max($request->integer('per_page', 20), 1), 100));
        return ApiResponse::successResponse([
            'data' => $page->items(),
            'pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ], 'Vendor product reviews retrieved.');
    }

    public function reply(Request $request, int $id)
    {
        $data = $request->validate(['reply' => ['required', 'string', 'min:3', 'max:3000']]);
        $review = DB::table('product_reviews as r')
            ->join('products as p', 'p.id', '=', 'r.product_id')
            ->where('r.id', $id)
            ->where('p.vendor_id', $request->user()->id)
            ->select('r.id', 'r.user_id', 'r.status', 'r.vendor_reply', 'p.name as product_name', 'p.slug as product_slug')
            ->first();
        abort_unless($review, 404, 'Review not found.');
        abort_unless($review->status === 'approved', 422, 'Only approved customer reviews can receive a public vendor reply.');

        DB::transaction(function () use ($review, $data) {
            DB::table('product_reviews')->where('id', $review->id)->update([
                'vendor_reply' => trim($data['reply']), 'vendor_replied_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('marketplace_notifications')->insert([
                'user_id' => $review->user_id, 'type' => 'review.vendor_reply',
                'title' => 'A store replied to your review',
                'message' => 'The store replied to your review of ' . $review->product_name . '.',
                'action_url' => '/product/' . $review->product_slug, 'data' => json_encode(['review_id' => $review->id]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return ApiResponse::successResponse(['review_id' => $review->id, 'reply' => trim($data['reply'])], 'Your reply has been published.');
    }
}
