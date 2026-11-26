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
        abort_if(in_array($order->status, ['cancelled', 'refunded'], true), 422, 'This order cannot be paid.');
        abort_unless($order->payment_method === $data['method'], 422, 'The payment method does not match the selected checkout method.');

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

            if (in_array($status, ['succeeded', 'failed'], true)) {
                $order = DB::table('customer_orders')->where('id', $transaction->order_id)->first();
                DB::table('marketplace_notifications')->insert([
                    'user_id' => $transaction->user_id,
                    'type' => 'payment.' . $status,
                    'title' => $status === 'succeeded' ? 'Payment confirmed' : 'Payment failed',
                    'message' => $status === 'succeeded'
                        ? 'Payment for order ' . ($order->number ?? $transaction->order_id) . ' was confirmed.'
                        : 'Payment for order ' . ($order->number ?? $transaction->order_id) . ' failed. You can retry from your order details.',
                    'action_url' => '/account/orders/' . $transaction->order_id,
                    'data' => json_encode(['order_id' => $transaction->order_id, 'transaction_id' => $id]),
                    'created_at' => now(), 'updated_at' => now(),
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

            DB::table('marketplace_notifications')->insert([
                'user_id' => $request->user()->id, 'type' => 'refund.requested',
                'title' => 'Refund request received',
                'message' => 'Your refund request for order ' . $order->number . ' has been submitted for review.',
                'action_url' => '/account/refunds', 'data' => json_encode(['refund_id' => $id, 'order_id' => $order->id]),
                'created_at' => now(), 'updated_at' => now(),
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

        return DB::transaction(function () use ($request, $id, $data) {
            $refund = DB::table('refund_requests')->where('id', $id)->lockForUpdate()->first();
            abort_unless($refund, 404, 'Refund request not found.');
            abort_unless($refund->status === 'pending', 409, 'Only pending refund requests can be reviewed.');

            DB::table('refund_requests')->where('id', $id)->update([
                'status' => $data['decision'] === 'approve' ? 'approved' : 'rejected',
                'reviewed_by' => $request->user()->id,
                'admin_note' => $data['admin_note'] ?? null,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('marketplace_notifications')->insert([
                'user_id' => $refund->user_id, 'type' => 'refund.' . $data['decision'],
                'title' => $data['decision'] === 'approve' ? 'Refund approved' : 'Refund request declined',
                'message' => $data['decision'] === 'approve' ? 'Your refund request has been approved and is awaiting simulated processing.' : 'Your refund request was declined. Please review your order or contact support.',
                'action_url' => '/account/refunds', 'data' => json_encode(['refund_id' => $id, 'order_id' => $refund->order_id]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            return ApiResponse::successResponse(DB::table('refund_requests')->where('id', $id)->first(), 'Refund decision recorded.');
        });
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
                ->sum('amount');
            $order = DB::table('customer_orders')->where('id', $refund->order_id)->lockForUpdate()->first();
            $paymentStatus = $processedTotal >= (float) $order->total ? 'refunded' : 'partially_refunded';
            DB::table('customer_orders')->where('id', $refund->order_id)->update([
                'payment_status' => $paymentStatus,
                'status' => $paymentStatus === 'refunded' ? 'refunded' : $order->status,
                'updated_at' => now(),
            ]);

            DB::table('marketplace_notifications')->insert([
                'user_id' => $refund->user_id, 'type' => 'refund.processed',
                'title' => 'Refund processed',
                'message' => 'Your simulated refund of ' . number_format((float) $refund->amount, 2) . ' ' . $order->currency . ' has been recorded. No real funds were transferred.',
                'action_url' => '/account/refunds', 'data' => json_encode(['refund_id' => $id, 'order_id' => $refund->order_id, 'reference' => $reference]),
                'created_at' => now(), 'updated_at' => now(),
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

    /**
     * Admin-only local webhook simulator. Event IDs are unique so retries cannot apply twice.
     */
    public function simulateWebhook(Request $request)
    {
        $data = $request->validate([
            'transaction_id' => ['required', 'integer', 'exists:payment_transactions,id'],
            'event_id' => ['required', 'string', 'max:120'],
            'outcome' => ['required', 'in:success,failure'],
        ]);

        return DB::transaction(function () use ($request, $data) {
            $existing = DB::table('payment_webhook_events')->where('event_id', $data['event_id'])->first();
            if ($existing) {
                return ApiResponse::successResponse([
                    'event' => $existing,
                    'duplicate' => true,
                    'notice' => 'This event ID was already received; no second state change was applied.',
                ], 'Previously received payment event returned.');
            }

            $transaction = DB::table('payment_transactions')->where('id', $data['transaction_id'])->lockForUpdate()->first();
            abort_unless($transaction, 404, 'Payment transaction not found.');

            $eventStatus = 'ignored';
            $processedAt = now();
            if ($transaction->status === 'pending') {
                $status = $data['outcome'] === 'success' ? 'succeeded' : 'failed';
                DB::table('payment_transactions')->where('id', $transaction->id)->update([
                    'status' => $status,
                    'metadata' => json_encode(['simulation' => true, 'webhook_event_id' => $data['event_id'], 'outcome' => $data['outcome'], 'notice' => 'No real payment was collected.']),
                    'updated_at' => now(),
                ]);
                DB::table('customer_orders')->where('id', $transaction->order_id)->update([
                    'payment_status' => $status === 'succeeded' ? 'paid' : 'failed',
                    'status' => $status === 'succeeded' ? 'confirmed' : 'payment_failed',
                    'updated_at' => now(),
                ]);
                $eventStatus = 'processed';

                DB::table('marketplace_notifications')->insert([
                    'user_id' => $transaction->user_id,
                    'type' => 'payment.' . $status,
                    'title' => $status === 'succeeded' ? 'Payment confirmed' : 'Payment failed',
                    'message' => 'Payment for order ' . $transaction->order_id . ($status === 'succeeded' ? ' was confirmed.' : ' failed. You can retry from your order details.'),
                    'action_url' => '/account/orders/' . $transaction->order_id,
                    'data' => json_encode(['order_id' => $transaction->order_id, 'transaction_id' => $transaction->id, 'event_id' => $data['event_id']]),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $eventId = DB::table('payment_webhook_events')->insertGetId([
                'payment_transaction_id' => $transaction->id,
                'event_id' => $data['event_id'],
                'event_type' => 'payment.' . ($data['outcome'] === 'success' ? 'succeeded' : 'failed'),
                'status' => $eventStatus,
                'payload' => json_encode(['simulated' => true, 'outcome' => $data['outcome'], 'actor_id' => $request->user()->id]),
                'processed_at' => $processedAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ApiResponse::successResponse([
                'event' => DB::table('payment_webhook_events')->where('id', $eventId)->first(),
                'transaction' => DB::table('payment_transactions')->where('id', $transaction->id)->first(),
                'duplicate' => false,
                'simulated' => true,
                'notice' => 'No real payment was collected.',
            ], $eventStatus === 'processed' ? 'Simulated payment event applied.' : 'Event recorded but ignored because the transaction was already finalized.');
        });
    }

    public function adminTransactions(Request $request)
    {
        $query = DB::table('payment_transactions as p')
            ->join('customer_orders as o', 'o.id', '=', 'p.order_id')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->select('p.*', 'o.number as order_number', 'o.status as order_status', 'u.name as customer_name', 'u.email as customer_email');
        if ($request->filled('status')) $query->where('p.status', $request->string('status')->toString());
        if ($request->filled('search')) {
            $term = '%' . trim((string) $request->input('search')) . '%';
            $query->where(fn ($q) => $q->where('o.number', 'like', $term)->orWhere('p.reference', 'like', $term)->orWhere('u.email', 'like', $term));
        }
        $page = $query->orderByDesc('p.created_at')->paginate(min(max($request->integer('per_page', 20), 1), 100));
        return ApiResponse::successResponse([
            'data' => $page->items(),
            'pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ], 'Payment transactions retrieved.');
    }

    public function webhookEvents(Request $request)
    {
        $query = DB::table('payment_webhook_events as e')
            ->join('payment_transactions as p', 'p.id', '=', 'e.payment_transaction_id')
            ->join('customer_orders as o', 'o.id', '=', 'p.order_id')
            ->select('e.*', 'p.reference as payment_reference', 'p.status as payment_status', 'o.number as order_number');
        if ($request->filled('status')) $query->where('e.status', $request->string('status')->toString());
        $page = $query->orderByDesc('e.created_at')->paginate(min(max($request->integer('per_page', 20), 1), 100));
        return ApiResponse::successResponse([
            'data' => $page->items(),
            'pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ], 'Payment event history retrieved.');
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
