<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentSimulationController extends Controller
{
    /**
     * Create a local-only payment attempt. No card or bank details are accepted.
     */
    public function createIntent(Request $request)
    {
        $data = $request->validate([
            'order_id' => ['required', 'integer'],
            'method' => ['required', 'in:mock_card,mock_wallet'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ]);

        $order = DB::table('customer_orders')
            ->where('id', $data['order_id'])
            ->where('user_id', $request->user()->id)
            ->first();

        abort_unless($order, 404, 'Order not found.');
        abort_if(in_array($order->status, ['cancelled', 'refunded'], true), 422, 'This order cannot be paid.');\n        abort_unless($order->payment_method === $data['method'], 422, 'The payment method does not match the selected checkout method.');

        $activeAttempt = DB::table('payment_transactions')
            ->where('order_id', $order->id)
            ->where('user_id', $request->user()->id)
            ->whereIn('status', ['pending', 'succeeded'])
            ->orderByDesc('id')
            ->first();
        if ($activeAttempt) return ApiResponse::successResponse($this->transactionPayload($activeAttempt), 'An active simulated payment attempt already exists.');

        $key = $request->header('Idempotency-Key') ?: ($data['idempotency_key'] ?? null);
        if ($key) {
            $existing = DB::table('payment_transactions')
                ->where('user_id', $request->user()->id)
                ->where('idempotency_key', $key)
                ->first();
            if ($existing) return ApiResponse::successResponse($this->transactionPayload($existing), 'Existing simulated payment attempt returned.');
        }

        $transactionId = DB::table('payment_transactions')->insertGetId([
            'order_id' => $order->id,
            'user_id' => $request->user()->id,
            'provider' => 'simulation',
            'method' => $data['method'],
            'reference' => 'BG-SIM-' . Str::upper(Str::random(16)),
            'idempotency_key' => $key,
            'amount' => $order->total,
            'currency' => $order->currency,
            'status' => 'pending',
            'metadata' => json_encode(['simulation' => true, 'notice' => 'No real payment was collected.']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $transaction = DB::table('payment_transactions')->where('id', $transactionId)->first();
        return ApiResponse::successResponse($this->transactionPayload($transaction), 'Simulated payment attempt created.');
    }

    /**
     * Deterministic simulator outcome; callers cannot supply payment credentials.
     */
    public function confirmIntent(Request $request, int $id)
    {
        $data = $request->validate(['outcome' => ['required', 'in:success,failure,pending']]);
        return DB::transaction(function () use ($request, $id, $data) {
            $transaction = DB::table('payment_transactions')
                ->where('id', $id)
                ->where('user_id', $request->user()->id)
                ->lockForUpdate()
                ->first();
            abort_unless($transaction, 404, 'Payment attempt not found.');

            if ($transaction->status !== 'pending') {
                return ApiResponse::successResponse($this->transactionPayload($transaction), 'Payment attempt was already finalized.');
            }

            $status = match ($data['outcome']) {
                'success' => 'succeeded',
                'failure' => 'failed',
                default => 'pending',
            };

            DB::table('payment_transactions')->where('id', $id)->update([
                'status' => $status,
                'updated_at' => now(),
                'metadata' => json_encode([
                    'simulation' => true,
                    'outcome' => $data['outcome'],
                    'notice' => 'This is a simulated result; no real payment was collected.',
                ]),
            ]);

            if ($status === 'succeeded') {
                DB::table('customer_orders')->where('id', $transaction->order_id)->update([
                    'payment_method' => $transaction->method,
                    'payment_status' => 'paid',
                    'status' => 'confirmed',
                    'updated_at' => now(),
                ]);
            } elseif ($status === 'failed') {
                DB::table('customer_orders')->where('id', $transaction->order_id)->update([
                    'payment_status' => 'failed',
                    'status' => 'payment_failed',
                    'updated_at' => now(),
                ]);
            }

            $updated = DB::table('payment_transactions')->where('id', $id)->first();
            return ApiResponse::successResponse($this->transactionPayload($updated), 'Simulated payment outcome recorded.');
        });
    }

    public function customerTransactions(Request $request)
    {
        $items = DB::table('payment_transactions')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->paginate(min(max($request->integer('per_page', 20), 1), 100));

        return ApiResponse::successResponse([
            'data' => $items->getCollection()->map(fn ($item) => $this->transactionPayload($item)),
            'pagination' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ], 'Payment transactions retrieved.');
    }

    public function requestRefund(Request $request)
    {
        $data = $request->validate([
            'order_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        return DB::transaction(function () use ($request, $data) {
            $order = DB::table('customer_orders')
                ->where('id', $data['order_id'])
                ->where('user_id', $request->user()->id)
                ->lockForUpdate()
                ->first();
            abort_unless($order, 404, 'Order not found.');
            abort_if(in_array($order->status, ['cancelled', 'refunded'], true), 422, 'This order is not eligible for a refund request.');
            abort_unless(in_array($order->payment_status, ['paid', 'partially_refunded'], true) || ($order->payment_method === 'cod' && $order->status === 'delivered'), 422, 'Only paid orders or delivered cash-on-delivery orders can be refunded.');

            $alreadyRequested = (float) DB::table('refund_requests')
                ->where('order_id', $order->id)
                ->whereIn('status', ['pending', 'approved', 'processed'])
                ->sum('amount');
            $remaining = max(0, (float) $order->total - $alreadyRequested);
            if ((float) $data['amount'] > $remaining) {
                throw ValidationException::withMessages(['amount' => ['The requested amount exceeds the refundable balance.']]);
            }

            $id = DB::table('refund_requests')->insertGetId([
                'order_id' => $order->id,
                'user_id' => $request->user()->id,
                'amount' => $data['amount'],
                'reason' => $data['reason'],
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ApiResponse::successResponse(DB::table('refund_requests')->where('id', $id)->first(), 'Refund request submitted for administrator review.');
        });
    }

    public function customerRefunds(Request $request)
    {
        $items = DB::table('refund_requests')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->paginate(min(max($request->integer('per_page', 20), 1), 100));

        return ApiResponse::successResponse([
            'data' => $items->items(),
            'pagination' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ], 'Refund requests retrieved.');
    }

    public function adminRefunds(Request $request)
    {
        $query = DB::table('refund_requests as r')
            ->join('customer_orders as o', 'o.id', '=', 'r.order_id')
            ->join('users as u', 'u.id', '=', 'r.user_id')
            ->select('r.*', 'o.number as order_number', 'o.total as order_total', 'o.currency', 'u.name as customer_name', 'u.email as customer_email');

        if ($request->filled('status')) $query->where('r.status', $request->string('status')->toString());
        if ($request->filled('search')) {
            $search = '%' . trim((string) $request->input('search')) . '%';
            $query->where(fn ($q) => $q->where('o.number', 'like', $search)->orWhere('u.email', 'like', $search));
        }

        $page = $query->orderByDesc('r.created_at')->paginate(min(max($request->integer('per_page', 20), 1), 100));
        return ApiResponse::successResponse([
            'data' => $page->items(),
            'pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ], 'Refund requests retrieved.');
    }

    public function reviewRefund(Request $request, int $id)
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'admin_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $refund = DB::table('refund_requests')->where('id', $id)->first();
        abort_unless($refund, 404, 'Refund request not found.');
        abort_unless($refund->status === 'pending', 409, 'Only pending refund requests can be reviewed.');

        DB::table('refund_requests')->where('id', $id)->update([
            'status' => $data['decision'] === 'approve' ? 'approved' : 'rejected',
            'reviewed_by' => $request->user()->id,
            'admin_note' => $data['admin_note'] ?? null,
            'reviewed_at' => now(),
            'updated_at' => now(),
        ]);

        return ApiResponse::successResponse(DB::table('refund_requests')->where('id', $id)->first(), 'Refund decision recorded.');
    }

    public function processRefund(int $id)
    {
        return DB::transaction(function () use ($id) {
            $refund = DB::table('refund_requests')->where('id', $id)->lockForUpdate()->first();
            abort_unless($refund, 404, 'Refund request not found.');
            abort_unless($refund->status === 'approved', 409, 'Only approved refunds can be processed.');

            $reference = 'BG-REF-' . Str::upper(Str::random(14));
            DB::table('refund_requests')->where('id', $id)->update([
                'status' => 'processed',
                'reference' => $reference,
                'processed_at' => now(),
                'updated_at' => now(),
            ]);

            $processedTotal = (float) DB::table('refund_requests')
                ->where('order_id', $refund->order_id)
                ->where('status', 'processed')
                ->sum('amount') + (float) $refund->amount;
            $order = DB::table('customer_orders')->where('id', $refund->order_id)->lockForUpdate()->first();
            $paymentStatus = $processedTotal >= (float) $order->total ? 'refunded' : 'partially_refunded';
            DB::table('customer_orders')->where('id', $refund->order_id)->update([
                'payment_status' => $paymentStatus,
                'status' => $paymentStatus === 'refunded' ? 'refunded' : $order->status,
                'updated_at' => now(),
            ]);

            return ApiResponse::successResponse([
                'id' => $id,
                'reference' => $reference,
                'status' => 'processed',
                'simulated' => true,
                'notice' => 'No real funds were transferred.',
            ], 'Simulated refund recorded.');
        });
    }

    private function transactionPayload(object $transaction): array
    {
        return [
            'id' => (int) $transaction->id,
            'orderId' => (int) $transaction->order_id,
            'reference' => $transaction->reference,
            'provider' => 'simulation',
            'method' => $transaction->method,
            'amount' => (float) $transaction->amount,
            'currency' => $transaction->currency,
            'status' => $transaction->status,
            'simulated' => true,
            'notice' => 'No real payment was collected.',
            'createdAt' => $transaction->created_at,
        ];
    }
}
