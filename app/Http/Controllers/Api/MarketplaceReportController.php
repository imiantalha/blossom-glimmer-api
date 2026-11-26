<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MarketplaceReportController extends Controller
{
    private function dateRange(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        if (!empty($data['from']) && !empty($data['to']) && $data['to'] < $data['from']) {
            throw ValidationException::withMessages(['to' => ['The end date must be on or after the start date.']]);
        }
        return [
            $data['from'] ?? now()->subDays(29)->toDateString(),
            $data['to'] ?? now()->toDateString(),
        ];
    }

    public function admin(Request $request)
    {
        [$from, $to] = $this->dateRange($request);
        $orders = DB::table('customer_orders')->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to);
        $paid = (clone $orders)->where('payment_status', 'paid')->whereNotIn('status', ['cancelled', 'refunded']);
        $salesByCurrency = (clone $paid)->select('currency', DB::raw('COUNT(*) as order_count'), DB::raw('SUM(total) as gross_revenue'))
            ->groupBy('currency')->get()->map(fn ($r) => ['currency' => $r->currency, 'orders' => (int) $r->order_count, 'grossRevenue' => (float) $r->gross_revenue])->values();

        $dailySales = (clone $paid)->select(DB::raw('CAST(created_at AS DATE) as sale_date'), 'currency', DB::raw('COUNT(*) as order_count'), DB::raw('SUM(total) as gross_revenue'))
            ->groupBy(DB::raw('CAST(created_at AS DATE)'), 'currency')->orderBy('sale_date')->get()
            ->map(fn ($r) => ['date' => (string) $r->sale_date, 'currency' => $r->currency, 'orders' => (int) $r->order_count, 'revenue' => (float) $r->gross_revenue])->values();

        $orderStatuses = (clone $orders)->select('status', DB::raw('COUNT(*) as total'))->groupBy('status')->get()
            ->map(fn ($r) => ['status' => $r->status, 'count' => (int) $r->total])->values();

        $topProducts = DB::table('customer_order_items as i')
            ->join('customer_orders as o', 'o.id', '=', 'i.order_id')
            ->leftJoin('products as p', 'p.id', '=', 'i.product_id')
            ->whereDate('o.created_at', '>=', $from)->whereDate('o.created_at', '<=', $to)
            ->where('o.payment_status', 'paid')->whereNotIn('o.status', ['cancelled', 'refunded'])
            ->select('i.product_id', 'i.name', 'o.currency', DB::raw('SUM(i.quantity) as units_sold'), DB::raw('SUM(i.subtotal) as revenue'))
            ->groupBy('i.product_id', 'i.name', 'o.currency')->orderByDesc('revenue')->limit(20)->get()
            ->map(fn ($r) => ['productId' => $r->product_id, 'name' => $r->name, 'currency' => $r->currency, 'unitsSold' => (int) $r->units_sold, 'revenue' => (float) $r->revenue])->values();

        $refunds = DB::table('refund_requests as r')->join('customer_orders as o', 'o.id', '=', 'r.order_id')
            ->where('r.status', 'processed')->whereDate('r.processed_at', '>=', $from)->whereDate('r.processed_at', '<=', $to)
            ->select('o.currency', DB::raw('COUNT(*) as refund_count'), DB::raw('SUM(r.amount) as total_refunded'))
            ->groupBy('o.currency')->get()->map(fn ($r) => ['currency' => $r->currency, 'count' => (int) $r->refund_count, 'amount' => (float) $r->total_refunded])->values();

        $payouts = DB::table('vendor_payout_requests')->where('status', 'processed')->whereDate('processed_at', '>=', $from)->whereDate('processed_at', '<=', $to)
            ->select('currency', DB::raw('COUNT(*) as payout_count'), DB::raw('SUM(amount) as total_paid'))
            ->groupBy('currency')->get()->map(fn ($r) => ['currency' => $r->currency, 'count' => (int) $r->payout_count, 'amount' => (float) $r->total_paid])->values();

        return ApiResponse::successResponse([
            'period' => ['from' => $from, 'to' => $to],
            'salesByCurrency' => $salesByCurrency,
            'dailySales' => $dailySales,
            'orderStatuses' => $orderStatuses,
            'topProducts' => $topProducts,
            'refundsByCurrency' => $refunds,
            'payoutsByCurrency' => $payouts,
        ], 'Marketplace reports retrieved.');
    }

    public function vendor(Request $request)
    {
        [$from, $to] = $this->dateRange($request);
        $vendorId = (int) $request->user()->id;
        $items = DB::table('customer_order_items as i')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->join('customer_orders as o', 'o.id', '=', 'i.order_id')
            ->where('p.vendor_id', $vendorId)
            ->whereDate('o.created_at', '>=', $from)->whereDate('o.created_at', '<=', $to)
            ->where('o.payment_status', 'paid')->whereNotIn('o.status', ['cancelled', 'refunded']);

        $salesByCurrency = (clone $items)->select('o.currency', DB::raw('COUNT(DISTINCT o.id) as order_count'), DB::raw('SUM(i.subtotal) as gross_revenue'), DB::raw('SUM(i.quantity) as units_sold'))
            ->groupBy('o.currency')->get()->map(fn ($r) => ['currency' => $r->currency, 'orders' => (int) $r->order_count, 'grossRevenue' => (float) $r->gross_revenue, 'unitsSold' => (int) $r->units_sold])->values();

        $dailySales = (clone $items)->select(DB::raw('CAST(o.created_at AS DATE) as sale_date'), 'o.currency', DB::raw('SUM(i.subtotal) as revenue'))
            ->groupBy(DB::raw('CAST(o.created_at AS DATE)'), 'o.currency')->orderBy('sale_date')->get()
            ->map(fn ($r) => ['date' => (string) $r->sale_date, 'currency' => $r->currency, 'revenue' => (float) $r->revenue])->values();

        $topProducts = (clone $items)->select('p.id as product_id', 'p.name', 'o.currency', DB::raw('SUM(i.quantity) as units_sold'), DB::raw('SUM(i.subtotal) as revenue'))
            ->groupBy('p.id', 'p.name', 'o.currency')->orderByDesc('revenue')->limit(20)->get()
            ->map(fn ($r) => ['productId' => (int) $r->product_id, 'name' => $r->name, 'currency' => $r->currency, 'unitsSold' => (int) $r->units_sold, 'revenue' => (float) $r->revenue])->values();

        $orderStatuses = DB::table('vendor_order_fulfillments')->where('vendor_id', $vendorId)
            ->join('customer_orders as o', 'o.id', '=', 'vendor_order_fulfillments.order_id')
            ->whereDate('o.created_at', '>=', $from)->whereDate('o.created_at', '<=', $to)
            ->select('vendor_order_fulfillments.status', DB::raw('COUNT(*) as total'))->groupBy('vendor_order_fulfillments.status')->get()
            ->map(fn ($r) => ['status' => $r->status, 'count' => (int) $r->total])->values();

        return ApiResponse::successResponse([
            'period' => ['from' => $from, 'to' => $to],
            'salesByCurrency' => $salesByCurrency,
            'dailySales' => $dailySales,
            'topProducts' => $topProducts,
            'fulfillmentStatuses' => $orderStatuses,
        ], 'Vendor analytics retrieved.');
    }
}
