<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    public function index(Request $request)
    {
        $today = now()->startOfDay();
        $lastThirtyDays = now()->subDays(29)->startOfDay();

        $roleCount = function (string $role): int {
            return (int) DB::table('model_has_roles as m')
                ->join('roles as r', 'r.id', '=', 'm.role_id')
                ->where('m.model_type', 'App\\Models\\User')
                ->where('r.name', $role)
                ->count();
        };

        $orders = DB::table('customer_orders');
        $stores = DB::table('vendor_profiles')->whereNull('deleted_at');

        return ApiResponse::successResponse([
            'users' => [
                'total' => DB::table('users')->count(),
                'customers' => $roleCount('Customer'),
                'vendors' => $roleCount('Vendor'),
                'admins' => $roleCount('Admin') + $roleCount('Super Admin'),
                'newToday' => DB::table('users')->where('created_at', '>=', $today)->count(),
            ],
            'stores' => [
                'total' => (clone $stores)->count(),
                'pending' => (clone $stores)->where('status', 'pending')->count(),
                'active' => (clone $stores)->where('status', 'active')->count(),
                'suspended' => (clone $stores)->where('status', 'suspended')->count(),
            ],
            'products' => [
                'total' => DB::table('products')->whereNotIn('status', ['archived'])->count(),
                'active' => DB::table('products')->where('status', 'active')->count(),
                'pending' => DB::table('products')->where('metadata', 'like', '%"approvalStatus":"pending"%')->count(),
                'rejected' => DB::table('products')->where('metadata', 'like', '%"approvalStatus":"rejected"%')->count(),
            ],
            'orders' => [
                'total' => (clone $orders)->count(),
                'pending' => (clone $orders)->whereIn('status', ['pending', 'confirmed'])->count(),
                'processing' => (clone $orders)->whereIn('status', ['processing', 'shipped'])->count(),
                'delivered' => (clone $orders)->where('status', 'delivered')->count(),
                'cancelled' => (clone $orders)->where('status', 'cancelled')->count(),
                'revenue' => (float) (clone $orders)->where('payment_status', 'paid')->whereNotIn('status', ['cancelled', 'refunded'])->sum('total'),
                'lastThirtyDays' => (clone $orders)->where('created_at', '>=', $lastThirtyDays)->count(),
            ],
            'finance' => [
                'pendingRefunds' => DB::table('refund_requests')->where('status', 'pending')->count(),
                'pendingPayouts' => DB::table('vendor_payout_requests')->where('status', 'pending')->count(),
            ],
            'support' => [
                'openTickets' => DB::table('support_tickets')->whereIn('status', ['open', 'in_progress', 'waiting_customer'])->count(),
                'highPriorityTickets' => DB::table('support_tickets')->whereIn('status', ['open', 'in_progress', 'waiting_customer'])->where('priority', 'high')->count(),
            ],
            'moderation' => [
                'pendingReviews' => DB::table('product_reviews')->where('status', 'pending')->count(),
                'pendingCatalogRequests' => DB::table('catalog_requests')->where('status', 'pending')->count(),
            ],
        ], 'Admin dashboard metrics retrieved.');
    }
}
