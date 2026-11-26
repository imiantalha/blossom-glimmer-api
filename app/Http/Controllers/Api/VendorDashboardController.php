<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Vendor-only operational dashboard.
 *
 * All order and inventory queries are scoped to the authenticated vendor. The
 * dashboard deliberately reports vendor line-item totals, never marketplace
 * order totals or another seller's catalogue/fulfilment data.
 */
class VendorDashboardController extends Controller
{
    public function index(Request $request)
    {
        $vendorId = (int) $request->user()->id;

        $products = DB::table('products')->where('vendor_id', $vendorId);
        $productCounts = [
            'total' => (clone $products)->whereNotIn('status', ['archived'])->count(),
            'active' => (clone $products)->where('status', 'active')->count(),
            'pending' => (clone $products)->where(function ($query) {
                $query->where('metadata', 'like', '%"approvalStatus":"pending"%')
                    ->orWhere(function ($legacy) {
                        $legacy->whereNotIn('status', ['active', 'archived'])
                            ->where(function ($metadata) {
                                $metadata->whereNull('metadata')
                                    ->orWhere('metadata', 'not like', '%"approvalStatus"%');
                            });
                    });
            })->count(),
            'rejected' => (clone $products)->where('metadata', 'like', '%"approvalStatus":"rejected"%')->count(),
        ];

        $inventory = DB::table('inventory_items as i')
            ->leftJoin('products as direct_product', 'direct_product.id', '=', 'i.product_id')
            ->leftJoin('product_variants as v', 'v.id', '=', 'i.variant_id')
            ->leftJoin('products as variant_product', 'variant_product.id', '=', 'v.product_id')
            ->where(function ($query) use ($vendorId) {
                $query->where('direct_product.vendor_id', $vendorId)
                    ->orWhere('variant_product.vendor_id', $vendorId);
            })
            ->where('i.manage_inventory', true);

        $lowStock = (clone $inventory)
            ->whereRaw('(i.stock_quantity - i.reserved_quantity) <= i.low_stock_threshold')
            ->selectRaw('COALESCE(direct_product.id, variant_product.id) as product_id')
            ->addSelect(DB::raw('COALESCE(direct_product.name, variant_product.name) as name'))
            ->addSelect(DB::raw('COALESCE(direct_product.sku, v.sku) as sku'))
            ->addSelect('i.stock_quantity', 'i.reserved_quantity', 'i.low_stock_threshold')
            ->orderByRaw('(i.stock_quantity - i.reserved_quantity) asc')
            ->limit(8)
            ->get()
            ->map(fn ($row) => [
                'productId' => (int) $row->product_id,
                'name' => $row->name,
                'sku' => $row->sku,
                'stockQuantity' => (int) $row->stock_quantity,
                'reservedQuantity' => (int) $row->reserved_quantity,
                'availableQuantity' => max(0, (int) $row->stock_quantity - (int) $row->reserved_quantity),
                'lowStockThreshold' => (int) $row->low_stock_threshold,
            ]);

        $vendorOrderIds = DB::table('customer_order_items as oi')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->where('p.vendor_id', $vendorId)
            ->select('oi.order_id')
            ->distinct();

        $orders = DB::table('customer_orders as o')
            ->whereIn('o.id', $vendorOrderIds);

        $orderCounts = [
            'total' => (clone $orders)->count(),
            'processing' => DB::table('vendor_order_fulfillments')->where('vendor_id', $vendorId)->whereIn('status', ['pending', 'processing', 'ready_to_ship'])->count(),
            'shipped' => DB::table('vendor_order_fulfillments')->where('vendor_id', $vendorId)->where('status', 'shipped')->count(),
            'delivered' => DB::table('vendor_order_fulfillments')->where('vendor_id', $vendorId)->where('status', 'delivered')->count(),
        ];

        $sales = DB::table('customer_order_items as oi')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->join('customer_orders as o', 'o.id', '=', 'oi.order_id')
            ->where('p.vendor_id', $vendorId)
            ->whereNotIn('o.status', ['cancelled', 'refunded'])
            ->sum('oi.subtotal');

        $recentOrders = (clone $orders)
            ->orderByDesc('o.created_at')
            ->limit(6)
            ->get(['o.id', 'o.number', 'o.status', 'o.created_at', 'o.currency'])
            ->map(function ($order) use ($vendorId) {
                $fulfillment = DB::table('vendor_order_fulfillments')
                    ->where('order_id', $order->id)
                    ->where('vendor_id', $vendorId)
                    ->first();
                $items = DB::table('customer_order_items as oi')
                    ->join('products as p', 'p.id', '=', 'oi.product_id')
                    ->where('oi.order_id', $order->id)
                    ->where('p.vendor_id', $vendorId);
                return [
                    'id' => (int) $order->id,
                    'number' => $order->number,
                    'status' => $fulfillment->status ?? $order->status,
                    'createdAt' => $order->created_at,
                    'currency' => $order->currency,
                    'itemCount' => (int) (clone $items)->sum('oi.quantity'),
                    'vendorSubtotal' => (float) (clone $items)->sum('oi.subtotal'),
                ];
            });

        $profile = DB::table('vendor_profiles')->where('user_id', $vendorId)->whereNull('deleted_at')->first();

        return ApiResponse::successResponse([
            'store' => $profile ? [
                'id' => (int) $profile->id,
                'name' => $profile->name,
                'slug' => $profile->slug,
                'status' => $profile->status,
                'commissionType' => $profile->commission_type,
                'commissionValue' => (float) $profile->commission_value,
            ] : null,
            'products' => $productCounts,
            'orders' => $orderCounts,
            'sales' => [
                'grossSubtotal' => (float) $sales,
                'currency' => 'USD',
            ],
            'lowStockProducts' => $lowStock,
            'recentOrders' => $recentOrders,
        ], 'Vendor dashboard retrieved.');
    }
}
