<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VendorFinanceController extends Controller
{
    public function summary(Request $request)
    {
        $vendorId = (int) $request->user()->id;
        $this->syncEarnedLedger($vendorId);

        $currencies = DB::table('vendor_ledger_entries')->where('vendor_id', $vendorId)->distinct()->pluck('currency')
            ->merge(DB::table('vendor_payout_requests')->where('vendor_id', $vendorId)->distinct()->pluck('currency'))
            ->filter()->unique()->values();
        if ($currencies->isEmpty()) $currencies = collect(['USD']);

        $balances = $currencies->map(function ($currency) use ($vendorId) {
            $credits = (float) DB::table('vendor_ledger_entries')->where('vendor_id', $vendorId)->where('currency', $currency)->where('type', 'sale_credit')->sum('net_amount');
            $debits = abs((float) DB::table('vendor_ledger_entries')->where('vendor_id', $vendorId)->where('currency', $currency)->where('type', 'payout_debit')->sum('net_amount'));
            $refundDebits = abs((float) DB::table('vendor_ledger_entries')->where('vendor_id', $vendorId)->where('currency', $currency)->where('type', 'refund_debit')->sum('net_amount'));
            $reserved = (float) DB::table('vendor_payout_requests')->where('vendor_id', $vendorId)->where('currency', $currency)->whereIn('status', ['pending', 'approved'])->sum('amount');
            $gross = (float) DB::table('vendor_ledger_entries')->where('vendor_id', $vendorId)->where('currency', $currency)->where('type', 'sale_credit')->sum('gross_amount');
            $commission = (float) DB::table('vendor_ledger_entries')->where('vendor_id', $vendorId)->where('currency', $currency)->where('type', 'sale_credit')->sum('commission_amount');
            return [
                'currency' => $currency,
                'grossEarned' => $gross,
                'commissionDeducted' => $commission,
                'netEarned' => $credits,
                'paidOut' => $debits,
                'refundedToCustomers' => $refundDebits,
                'reserved' => $reserved,
                'availableBalance' => max(0, $credits - $debits - $refundDebits - $reserved),
                'balanceDue' => max(0, $debits + $refundDebits - $credits),
            ];
        })->values();
        $defaultCurrency = $balances->firstWhere('currency', 'USD') ?? $balances->first();
        $profile = DB::table('vendor_profiles')->where('user_id', $vendorId)->whereNull('deleted_at')->first();

        return ApiResponse::successResponse([
            ...$defaultCurrency,
            'currency' => $defaultCurrency['currency'],
            'balances' => $balances,
            'commission' => $profile ? ['type' => $profile->commission_type, 'value' => (float) $profile->commission_value] : null,
            'ledger' => DB::table('vendor_ledger_entries')->where('vendor_id', $vendorId)->orderByDesc('id')->limit(100)->get(),
        ], 'Vendor finance summary retrieved.');
    }

    public function ledger(Request $request)
    {
        $page = DB::table('vendor_ledger_entries')->where('vendor_id', $request->user()->id)
            ->orderByDesc('id')->paginate(min(max($request->integer('per_page', 25), 1), 100));
        return ApiResponse::successResponse([
            'data' => $page->items(),
            'pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ], 'Vendor earnings ledger retrieved.');
    }

    public function payouts(Request $request)
    {
        $items = DB::table('vendor_payout_requests')->where('vendor_id', $request->user()->id)->orderByDesc('id')->paginate(min(max($request->integer('per_page', 20), 1), 100));
        return ApiResponse::successResponse([
            'data' => $items->items(),
            'pagination' => ['current_page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'per_page' => $items->perPage(), 'total' => $items->total()],
        ], 'Vendor payout requests retrieved.');
    }

    public function requestPayout(Request $request)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency' => ['required', 'string', 'size:3'],
            'vendor_note' => ['nullable', 'string', 'max:1000'],
        ]);
        $vendorId = (int) $request->user()->id;

        return DB::transaction(function () use ($vendorId, $data) {
            DB::table('users')->where('id', $vendorId)->lockForUpdate()->first();
            $currency = strtoupper($data['currency']);
            $this->syncEarnedLedger($vendorId);
            $credits = (float) DB::table('vendor_ledger_entries')->where('vendor_id', $vendorId)->where('currency', $currency)->where('type', 'sale_credit')->sum('net_amount');
            $debits = abs((float) DB::table('vendor_ledger_entries')->where('vendor_id', $vendorId)->where('currency', $currency)->where('type', 'payout_debit')->sum('net_amount'));
            $refundDebits = abs((float) DB::table('vendor_ledger_entries')->where('vendor_id', $vendorId)->where('currency', $currency)->where('type', 'refund_debit')->sum('net_amount'));
            $reserved = (float) DB::table('vendor_payout_requests')->where('vendor_id', $vendorId)->where('currency', $currency)->whereIn('status', ['pending', 'approved'])->sum('amount');
            $available = max(0, $credits - $debits - $refundDebits - $reserved);
            if ((float) $data['amount'] > $available) {
                throw ValidationException::withMessages(['amount' => ['The requested payout exceeds your available balance.']]);
            }

            $id = DB::table('vendor_payout_requests')->insertGetId([
                'vendor_id' => $vendorId,
                'amount' => $data['amount'],
                'currency' => $currency,
                'status' => 'pending',
                'payout_method' => 'manual_simulation',
                'vendor_note' => $data['vendor_note'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            return ApiResponse::successResponse(DB::table('vendor_payout_requests')->where('id', $id)->first(), 'Payout request submitted for administrator review.');
        });
    }

    public function adminPayouts(Request $request)
    {
        $query = DB::table('vendor_payout_requests as p')
            ->join('users as u', 'u.id', '=', 'p.vendor_id')
            ->select('p.*', 'u.name as vendor_name', 'u.email as vendor_email');
        if ($request->filled('status')) $query->where('p.status', $request->string('status')->toString());
        if ($request->filled('search')) {
            $term = '%' . trim((string) $request->input('search')) . '%';
            $query->where(fn ($q) => $q->where('u.name', 'like', $term)->orWhere('u.email', 'like', $term));
        }
        $page = $query->orderByDesc('p.id')->paginate(min(max($request->integer('per_page', 20), 1), 100));
        return ApiResponse::successResponse([
            'data' => $page->items(),
            'pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ], 'Marketplace payout requests retrieved.');
    }

    public function reviewPayout(Request $request, int $id)
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'admin_note' => ['nullable', 'string', 'max:2000'],
        ]);

        return DB::transaction(function () use ($request, $id, $data) {
            $payout = DB::table('vendor_payout_requests')->where('id', $id)->lockForUpdate()->first();
            abort_unless($payout, 404, 'Payout request not found.');
            abort_unless($payout->status === 'pending', 409, 'Only pending payout requests can be reviewed.');

            DB::table('vendor_payout_requests')->where('id', $id)->update([
                'status' => $data['decision'] === 'approve' ? 'approved' : 'rejected',
                'reviewed_by' => $request->user()->id,
                'admin_note' => $data['admin_note'] ?? null,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('marketplace_notifications')->insert([
                'user_id' => $payout->vendor_id,
                'type' => 'payout.' . $data['decision'],
                'title' => $data['decision'] === 'approve' ? 'Payout request approved' : 'Payout request declined',
                'message' => $data['decision'] === 'approve'
                    ? 'Your payout request has been approved and is awaiting simulated processing.'
                    : 'Your payout request was declined. Please contact marketplace support if you need details.',
                'action_url' => '/vendor/finance',
                'data' => json_encode(['payout_id' => $id]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            return ApiResponse::successResponse(DB::table('vendor_payout_requests')->where('id', $id)->first(), 'Payout decision recorded.');
        });
    }

    public function processPayout(int $id)
    {
        return DB::transaction(function () use ($id) {
            $payout = DB::table('vendor_payout_requests')->where('id', $id)->lockForUpdate()->first();
            abort_unless($payout, 404, 'Payout request not found.');
            abort_unless($payout->status === 'approved', 409, 'Only approved payouts can be processed.');
            DB::table('users')->where('id', $payout->vendor_id)->lockForUpdate()->first();

            $credits = (float) DB::table('vendor_ledger_entries')->where('vendor_id', $payout->vendor_id)->where('currency', $payout->currency)->where('type', 'sale_credit')->sum('net_amount');
            $debits = abs((float) DB::table('vendor_ledger_entries')->where('vendor_id', $payout->vendor_id)->where('currency', $payout->currency)->where('type', 'payout_debit')->sum('net_amount'));
            $refundDebits = abs((float) DB::table('vendor_ledger_entries')->where('vendor_id', $payout->vendor_id)->where('currency', $payout->currency)->where('type', 'refund_debit')->sum('net_amount'));
            $otherReserved = (float) DB::table('vendor_payout_requests')->where('vendor_id', $payout->vendor_id)->where('currency', $payout->currency)
                ->whereIn('status', ['pending', 'approved'])->where('id', '<>', $id)->sum('amount');
            $available = max(0, $credits - $debits - $refundDebits - $otherReserved);
            abort_if((float) $payout->amount > $available, 409, 'Vendor available balance changed after approval. Review or reject this payout before processing.');

            $reference = 'BG-PAYOUT-' . Str::upper(Str::random(14));
            DB::table('vendor_ledger_entries')->insert([
                'vendor_id' => $payout->vendor_id,
                'type' => 'payout_debit',
                'reference' => $reference,
                'gross_amount' => 0,
                'commission_amount' => 0,
                'net_amount' => -abs((float) $payout->amount),
                'currency' => $payout->currency,
                'metadata' => json_encode(['simulated' => true, 'payout_request_id' => $id, 'notice' => 'No bank transfer was made.']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('vendor_payout_requests')->where('id', $id)->update([
                'status' => 'processed',
                'reference' => $reference,
                'processed_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('marketplace_notifications')->insert([
                'user_id' => $payout->vendor_id, 'type' => 'payout.processed',
                'title' => 'Payout recorded',
                'message' => 'Your simulated payout of ' . number_format((float) $payout->amount, 2) . ' ' . $payout->currency . ' was recorded. No real funds were transferred.',
                'action_url' => '/vendor/finance',
                'data' => json_encode(['payout_id' => $id, 'reference' => $reference]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            return ApiResponse::successResponse([
                'id' => $id,
                'reference' => $reference,
                'status' => 'processed',
                'simulated' => true,
                'notice' => 'No real funds were transferred.',
            ], 'Simulated vendor payout recorded.');
        });
    }

    private function syncEarnedLedger(int $vendorId): void
    {
        $profile = DB::table('vendor_profiles')->where('user_id', $vendorId)->whereNull('deleted_at')->first();
        $type = $profile->commission_type ?? 'percentage';
        $value = max(0, (float) ($profile->commission_value ?? 0));

        $items = DB::table('customer_order_items as oi')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->join('customer_orders as o', 'o.id', '=', 'oi.order_id')
            ->where('p.vendor_id', $vendorId)
            ->whereIn('o.status', ['delivered', 'refunded'])
            ->where(function ($query) {
                $query->whereIn('o.payment_status', ['paid', 'partially_refunded', 'refunded'])
                    ->orWhere('o.payment_method', 'cod');
            })
            ->select('oi.id', 'oi.order_id', 'oi.subtotal', 'o.subtotal as order_subtotal', 'o.discount as order_discount', 'o.currency')
            ->get();

        foreach ($items as $item) {
            $orderSubtotal = max(0, (float) $item->order_subtotal);
            $discountRatio = $orderSubtotal > 0 ? max(0, $orderSubtotal - (float) $item->order_discount) / $orderSubtotal : 0;
            $gross = max(0, (float) $item->subtotal * $discountRatio);
            $commission = $type === 'fixed'
                ? min($gross, $value)
                : min($gross, $gross * min($value, 100) / 100);
            $reference = 'BG-SALE-' . $vendorId . '-' . $item->id;
            DB::table('vendor_ledger_entries')->insertOrIgnore([
                'vendor_id' => $vendorId,
                'order_id' => $item->order_id,
                'order_item_id' => $item->id,
                'type' => 'sale_credit',
                'reference' => $reference,
                'gross_amount' => $gross,
                'commission_amount' => $commission,
                'net_amount' => $gross - $commission,
                'currency' => $item->currency ?: 'USD',
                'metadata' => json_encode(['commission_type' => $type, 'commission_value' => $value]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
