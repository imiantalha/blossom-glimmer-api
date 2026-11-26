<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CouponManagementController extends Controller
{
    public function index(Request $request)
    {
        $query = DB::table('coupons');
        if ($request->filled('search')) {
            $term = '%' . strtoupper(trim((string) $request->input('search'))) . '%';
            $query->where(fn ($q) => $q->where('code', 'like', $term)->orWhere('name', 'like', $term));
        }
        if ($request->has('is_active')) $query->where('is_active', $request->boolean('is_active'));
        $page = $query->orderByDesc('created_at')->paginate(min(max($request->integer('per_page', 20), 1), 100));
        return ApiResponse::successResponse([
            'data' => $page->items(),
            'pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ], 'Coupons retrieved.');
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $id = DB::table('coupons')->insertGetId($this->values($data) + ['usage_count' => 0, 'created_at' => now(), 'updated_at' => now()]);
        return ApiResponse::createdResponse(DB::table('coupons')->where('id', $id)->first(), 'Coupon created.');
    }

    public function update(Request $request, int $id)
    {
        $coupon = DB::table('coupons')->where('id', $id)->first();
        abort_unless($coupon, 404, 'Coupon not found.');
        $data = $this->validated($request, $id);
        DB::table('coupons')->where('id', $id)->update($this->values($data) + ['updated_at' => now()]);
        return ApiResponse::successResponse(DB::table('coupons')->where('id', $id)->first(), 'Coupon updated.');
    }

    public function updateStatus(Request $request, int $id)
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $updated = DB::table('coupons')->where('id', $id)->update(['is_active' => $data['is_active'], 'updated_at' => now()]);
        abort_unless($updated, 404, 'Coupon not found.');
        return ApiResponse::successResponse(DB::table('coupons')->where('id', $id)->first(), 'Coupon status updated.');
    }

    public function destroy(int $id)
    {
        $coupon = DB::table('coupons')->where('id', $id)->first();
        abort_unless($coupon, 404, 'Coupon not found.');
        if ((int) $coupon->usage_count > 0) {
            DB::table('coupons')->where('id', $id)->update(['is_active' => false, 'updated_at' => now()]);
            return ApiResponse::successResponse(null, 'Coupon has redemption history and was disabled instead of deleted.');
        }
        DB::table('coupons')->where('id', $id)->delete();
        return ApiResponse::deletedResponse('Coupon deleted.');
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:60', 'alpha_dash', Rule::unique('coupons', 'code')->ignore($ignoreId)],
            'name' => ['required', 'string', 'max:180'],
            'discount_type' => ['required', Rule::in(['percentage', 'fixed'])],
            'discount_value' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'minimum_subtotal' => ['nullable', 'numeric', 'min:0'],
            'maximum_discount' => ['nullable', 'numeric', 'gt:0'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'per_user_limit' => ['required', 'integer', 'min:1', 'max:100'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['required', 'boolean'],
        ]);
        if ($data['discount_type'] === 'percentage' && (float) $data['discount_value'] > 100) {
            throw ValidationException::withMessages(['discount_value' => ['Percentage discounts cannot exceed 100%.']]);
        }
        return $data;
    }

    private function values(array $data): array
    {
        return [
            'code' => strtoupper(trim($data['code'])),
            'name' => trim($data['name']),
            'discount_type' => $data['discount_type'],
            'discount_value' => $data['discount_value'],
            'minimum_subtotal' => $data['minimum_subtotal'] ?? 0,
            'maximum_discount' => $data['maximum_discount'] ?? null,
            'usage_limit' => $data['usage_limit'] ?? null,
            'per_user_limit' => $data['per_user_limit'],
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'is_active' => $data['is_active'],
        ];
    }
}
