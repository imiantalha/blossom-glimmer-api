<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReturnRequestController extends Controller
{
    public function customerIndex(Request $request)
    {
        $page = DB::table('return_requests as r')
            ->join('customer_orders as o', 'o.id', '=', 'r.order_id')
            ->join('customer_order_items as i', 'i.id', '=', 'r.order_item_id')
            ->where('r.user_id', $request->user()->id)
            ->orderByDesc('r.created_at')
            ->select('r.*', 'o.number as order_number', 'i.name as product_name', 'i.image_url as product_image')
            ->paginate(min(max($request->integer('per_page', 20), 1), 100));

        return ApiResponse::successResponse([
            'data' => $page->items(),
            'pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ], 'Return requests retrieved.');
    }

    public function create(Request $request)
    {
        $data = $request->validate([
            'order_id' => ['required', 'integer'],
            'order_item_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'customer_note' => ['nullable', 'string', 'max:2000'],
        ]);

        return DB::transaction(function () use ($request, $data) {
            $order = DB::table('customer_orders')
                ->where('id', $data['order_id'])
                ->where('user_id', $request->user()->id)
                ->lockForUpdate()
                ->first();
            abort_unless($order, 404, 'Order not found.');
            abort_unless($order->status === 'delivered' || $order->fulfillment_status === 'delivered', 422, 'Only delivered orders can be returned.');
            $deliveredAt = $order->delivered_at ?: $order->updated_at;
            abort_if(now()->greaterThan(\Illuminate\Support\Carbon::parse($deliveredAt)->addDays(30)), 422, 'The 30-day return window has expired.');

            $item = DB::table('customer_order_items')
                ->where('id', $data['order_item_id'])
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->first();
            abort_unless($item, 404, 'Order item not found.');

            $reservedQuantity = (int) DB::table('return_requests')
                ->where('order_item_id', $item->id)
                ->whereIn('status', ['pending', 'approved', 'received', 'completed'])
                ->sum('quantity');
            $remaining = max(0, (int) $item->quantity - $reservedQuantity);
            if ($data['quantity'] > $remaining) {
                throw ValidationException::withMessages(['quantity' => ['The requested quantity exceeds the remaining returnable quantity.']]);
            }

            $id = DB::table('return_requests')->insertGetId([
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'user_id' => $request->user()->id,
                'quantity' => $data['quantity'],
                'reason' => $data['reason'],
                'customer_note' => $data['customer_note'] ?? null,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('marketplace_notifications')->insert([
                'user_id' => $request->user()->id,
                'type' => 'return.requested',
                'title' => 'Return request submitted',
                'message' => 'Your return request for ' . $item->name . ' is awaiting marketplace review.',
                'action_url' => '/account/returns',
                'data' => json_encode(['return_id' => $id, 'order_id' => $order->id]),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return ApiResponse::createdResponse(DB::table('return_requests')->where('id', $id)->first(), 'Return request submitted for review.');
        });
    }

    public function adminIndex(Request $request)
    {
        $query = DB::table('return_requests as r')
            ->join('customer_orders as o', 'o.id', '=', 'r.order_id')
            ->join('customer_order_items as i', 'i.id', '=', 'r.order_item_id')
            ->join('users as u', 'u.id', '=', 'r.user_id')
            ->leftJoin('users as a', 'a.id', '=', 'r.reviewed_by')
            ->select('r.*', 'o.number as order_number', 'o.currency', 'i.name as product_name', 'i.image_url as product_image', 'u.name as customer_name', 'u.email as customer_email', 'a.name as reviewer_name');

        if ($request->filled('status')) $query->where('r.status', $request->string('status')->toString());
        if ($request->filled('search')) {
            $term = '%' . trim((string) $request->input('search')) . '%';
            $query->where(fn ($q) => $q->where('o.number', 'like', $term)->orWhere('i.name', 'like', $term)->orWhere('u.email', 'like', $term));
        }
        $page = $query->orderByDesc('r.created_at')->paginate(min(max($request->integer('per_page', 20), 1), 100));

        return ApiResponse::successResponse([
            'data' => $page->items(),
            'pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ], 'Marketplace return requests retrieved.');
    }

    public function review(Request $request, int $id)
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'admin_note' => ['nullable', 'string', 'max:2000'],
        ]);

        return DB::transaction(function () use ($request, $id, $data) {
            $return = DB::table('return_requests')->where('id', $id)->lockForUpdate()->first();
            abort_unless($return, 404, 'Return request not found.');
            abort_unless($return->status === 'pending', 409, 'Only pending return requests can be reviewed.');

            $status = $data['decision'] === 'approve' ? 'approved' : 'rejected';
            DB::table('return_requests')->where('id', $id)->update([
                'status' => $status,
                'reviewed_by' => $request->user()->id,
                'admin_note' => $data['admin_note'] ?? null,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('marketplace_notifications')->insert([
                'user_id' => $return->user_id,
                'type' => 'return.' . $status,
                'title' => $status === 'approved' ? 'Return request approved' : 'Return request declined',
                'message' => $status === 'approved' ? 'Your return request was approved. Follow the return instructions provided by support.' : 'Your return request was declined. Review the decision note or contact support.',
                'action_url' => '/account/returns',
                'data' => json_encode(['return_id' => $id, 'order_id' => $return->order_id]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            return ApiResponse::successResponse(DB::table('return_requests')->where('id', $id)->first(), 'Return decision recorded.');
        });
    }

    public function updateStatus(Request $request, int $id)
    {
        $data = $request->validate(['status' => ['required', 'in:received,completed']]);

        return DB::transaction(function () use ($request, $id, $data) {
            $return = DB::table('return_requests')->where('id', $id)->lockForUpdate()->first();
            abort_unless($return, 404, 'Return request not found.');
            $allowed = $data['status'] === 'received' ? $return->status === 'approved' : $return->status === 'received';
            abort_unless($allowed, 409, $data['status'] === 'received' ? 'Only approved returns can be marked received.' : 'Only received returns can be completed.');

            $updates = ['status' => $data['status'], 'updated_at' => now()];
            if ($data['status'] === 'received') $updates['received_at'] = now();
            if ($data['status'] === 'completed') $updates['completed_at'] = now();

            $refundId = null;
            if ($data['status'] === 'completed') {
                $order = DB::table('customer_orders')->where('id', $return->order_id)->lockForUpdate()->first();
                $item = DB::table('customer_order_items')->where('id', $return->order_item_id)->first();
                $refundAmount = round((float) $item->unit_price * (int) $return->quantity, 2);
                $alreadyRequested = (float) DB::table('refund_requests')->where('order_id', $order->id)
                    ->whereIn('status', ['pending', 'approved', 'processed'])->sum('amount');
                abort_if($refundAmount > max(0, (float) $order->total - $alreadyRequested), 422, 'The remaining refundable order balance is lower than the value of this return.');
                $refundId = DB::table('refund_requests')->insertGetId([
                    'order_id' => $order->id,
                    'user_id' => $return->user_id,
                    'return_request_id' => $id,
                    'amount' => $refundAmount,
                    'reason' => 'Return completed: ' . $return->reason,
                    'status' => 'pending',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('marketplace_notifications')->insert([
                    'user_id' => $return->user_id,
                    'type' => 'refund.requested',
                    'title' => 'Return refund submitted for review',
                    'message' => 'A refund request of ' . number_format($refundAmount, 2) . ' ' . $order->currency . ' was created after your return was completed.',
                    'action_url' => '/account/refunds',
                    'data' => json_encode(['refund_id' => $refundId, 'return_id' => $id, 'order_id' => $order->id]),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            DB::table('return_requests')->where('id', $id)->update($updates);

            DB::table('marketplace_notifications')->insert([
                'user_id' => $return->user_id,
                'type' => 'return.' . $data['status'],
                'title' => $data['status'] === 'received' ? 'Returned item received' : 'Return completed',
                'message' => $data['status'] === 'received' ? 'The marketplace has recorded receipt of your returned item.' : 'Your return request has been completed. Any applicable refund is handled separately.',
                'action_url' => '/account/returns',
                'data' => json_encode(['return_id' => $id, 'order_id' => $return->order_id]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            return ApiResponse::successResponse([
                'return' => DB::table('return_requests')->where('id', $id)->first(),
                'refund_request_id' => $refundId,
            ], 'Return status updated.');
        });
    }
}
