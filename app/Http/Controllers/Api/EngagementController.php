<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EngagementController extends Controller
{
    public function notifications(Request $request)
    {
        $userId = $request->user()->id;
        $disabled = DB::table('marketplace_notification_preferences')->where('user_id', $userId)->where('in_app_enabled', false)->pluck('category')->all();
        $patterns = [];
        foreach ($disabled as $category) {
            foreach ($this->notificationPatterns($category) as $pattern) $patterns[] = $pattern;
        }
        $query = DB::table('marketplace_notifications')->where('user_id', $userId)->orderByDesc('created_at');
        if ($patterns) $query->where(function ($q) use ($patterns) { foreach ($patterns as $pattern) $q->where('type', 'not like', $pattern); });
        if ($request->boolean('unread')) $query->whereNull('read_at');
        $page = $query->paginate(min(max($request->integer('per_page', 20), 1), 100));
        $unreadQuery = DB::table('marketplace_notifications')->where('user_id', $userId)->whereNull('read_at');
        if ($patterns) $unreadQuery->where(function ($q) use ($patterns) { foreach ($patterns as $pattern) $q->where('type', 'not like', $pattern); });
        return ApiResponse::successResponse([
            'data' => $page->items(),
            'unreadCount' => $unreadQuery->count(),
            'pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ], 'Notifications retrieved.');
    }

    public function notificationPreferences(Request $request)
    {
        $categories = ['orders', 'payments', 'shipping', 'refunds', 'returns', 'reviews', 'support', 'finance', 'catalog', 'marketing'];
        $saved = DB::table('marketplace_notification_preferences')->where('user_id', $request->user()->id)->get()->keyBy('category');
        $preferences = [];
        foreach ($categories as $category) {
            $preference = $saved->get($category);
            $preferences[$category] = $preference ? (bool) $preference->in_app_enabled : true;
        }
        return ApiResponse::successResponse($preferences, 'Notification preferences retrieved.');
    }

    public function updateNotificationPreferences(Request $request)
    {
        $data = $request->validate([
            'preferences' => ['required', 'array', 'min:1'],
            'preferences.*' => ['required', 'boolean'],
        ]);
        $allowed = ['orders', 'payments', 'shipping', 'refunds', 'returns', 'reviews', 'support', 'finance', 'catalog', 'marketing'];
        $unknown = array_diff(array_keys($data['preferences']), $allowed);
        abort_if($unknown, 422, 'One or more notification categories are not supported.');

        DB::transaction(function () use ($request, $data) {
            foreach ($data['preferences'] as $category => $enabled) {
                DB::table('marketplace_notification_preferences')->updateOrInsert(
                    ['user_id' => $request->user()->id, 'category' => $category],
                    ['in_app_enabled' => (bool) $enabled, 'updated_at' => now(), 'created_at' => now()]
                );
            }
        });
        return $this->notificationPreferences($request);
    }

    private function notificationPatterns(string $category): array
    {
        return match ($category) {
            'orders' => ['order.%'],
            'payments' => ['payment.%'],
            'shipping' => ['shipment.%', 'order.fulfilment.%'],
            'refunds' => ['refund.%'],
            'returns' => ['return.%'],
            'reviews' => ['review.%'],
            'support' => ['support.%'],
            'finance' => ['payout.%'],
            'catalog' => ['catalog_request.%'],
            'marketing' => ['marketing.%'],
            default => [],
        };
    }

    public function markNotificationRead(Request $request, int $id)
    {
        $updated = DB::table('marketplace_notifications')->where('id', $id)->where('user_id', $request->user()->id)->update(['read_at' => now(), 'updated_at' => now()]);
        abort_unless($updated, 404, 'Notification not found.');
        return ApiResponse::successResponse(['id' => $id, 'read' => true], 'Notification marked as read.');
    }

    public function markAllNotificationsRead(Request $request)
    {
        $count = DB::table('marketplace_notifications')->where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]);
        return ApiResponse::successResponse(['updated' => $count], 'Notifications marked as read.');
    }

    public function tickets(Request $request)
    {
        $page = DB::table('support_tickets')->where('user_id', $request->user()->id)->orderByDesc('created_at')->paginate(min(max($request->integer('per_page', 20), 1), 100));
        return ApiResponse::successResponse(['data' => $page->items(), 'pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]], 'Support tickets retrieved.');
    }

    public function createTicket(Request $request)
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:180'],
            'category' => ['nullable', 'in:general,order,payment,shipping,returns,account,store,product,other'],
            'priority' => ['nullable', 'in:low,normal,high'],
            'order_id' => ['nullable', 'integer'],
            'message' => ['required', 'string', 'min:10', 'max:10000'],
        ]);

        if (!empty($data['order_id'])) {
            $ownsOrder = DB::table('customer_orders')->where('id', $data['order_id'])->where('user_id', $request->user()->id)->exists();
            abort_unless($ownsOrder, 404, 'Order not found.');
        }

        $id = DB::table('support_tickets')->insertGetId([
            'number' => 'BG-SUP-' . Str::upper(Str::random(10)),
            'user_id' => $request->user()->id,
            'order_id' => $data['order_id'] ?? null,
            'subject' => $data['subject'],
            'category' => $data['category'] ?? 'general',
            'priority' => $data['priority'] ?? 'normal',
            'status' => 'open',
            'message' => $data['message'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('support_ticket_messages')->insert([
            'ticket_id' => $id, 'user_id' => $request->user()->id, 'message' => $data['message'],
            'internal_note' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return ApiResponse::successResponse($this->ticketPayload($id, $request->user()->id), 'Support ticket created.');
    }

    public function showTicket(Request $request, int $id)
    {
        $ticket = DB::table('support_tickets')->where('id', $id)->where('user_id', $request->user()->id)->first();
        abort_unless($ticket, 404, 'Support ticket not found.');
        return ApiResponse::successResponse([
            'ticket' => $ticket,
            'messages' => DB::table('support_ticket_messages as m')->join('users as u', 'u.id', '=', 'm.user_id')
                ->where('m.ticket_id', $id)->where('m.internal_note', false)
                ->orderBy('m.created_at')->get(['m.id', 'm.user_id', 'u.name as author', 'm.message', 'm.created_at']),
        ], 'Support ticket retrieved.');
    }

    public function replyTicket(Request $request, int $id)
    {
        $data = $request->validate(['message' => ['required', 'string', 'min:2', 'max:10000']]);
        $ticket = DB::table('support_tickets')->where('id', $id)->where('user_id', $request->user()->id)->first();
        abort_unless($ticket, 404, 'Support ticket not found.');
        abort_if(in_array($ticket->status, ['resolved', 'closed'], true), 409, 'This ticket is closed.');
        DB::table('support_ticket_messages')->insert([
            'ticket_id' => $id, 'user_id' => $request->user()->id, 'message' => $data['message'],
            'internal_note' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('support_tickets')->where('id', $id)->update(['status' => 'open', 'updated_at' => now()]);
        return ApiResponse::successResponse($this->ticketPayload($id, $request->user()->id), 'Reply added to support ticket.');
    }

    public function adminTickets(Request $request)
    {
        $query = DB::table('support_tickets as t')->join('users as u', 'u.id', '=', 't.user_id')
            ->leftJoin('users as a', 'a.id', '=', 't.assigned_to')
            ->select('t.*', 'u.name as requester_name', 'u.email as requester_email', 'a.name as assignee_name');
        if ($request->filled('status')) $query->where('t.status', $request->string('status')->toString());
        if ($request->filled('priority')) $query->where('t.priority', $request->string('priority')->toString());
        if ($request->filled('search')) {
            $term = '%' . trim((string) $request->input('search')) . '%';
            $query->where(fn ($q) => $q->where('t.number', 'like', $term)->orWhere('t.subject', 'like', $term)->orWhere('u.email', 'like', $term));
        }
        $page = $query->orderByRaw("CASE t.priority WHEN 'high' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END")->orderByDesc('t.created_at')->paginate(min(max($request->integer('per_page', 20), 1), 100));
        return ApiResponse::successResponse(['data' => $page->items(), 'pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]], 'Marketplace support tickets retrieved.');
    }

    public function adminShowTicket(int $id)
    {
        $ticket = DB::table('support_tickets as t')->join('users as u', 'u.id', '=', 't.user_id')->where('t.id', $id)
            ->first(['t.*', 'u.name as requester_name', 'u.email as requester_email']);
        abort_unless($ticket, 404, 'Support ticket not found.');
        return ApiResponse::successResponse([
            'ticket' => $ticket,
            'messages' => DB::table('support_ticket_messages as m')->join('users as u', 'u.id', '=', 'm.user_id')
                ->where('m.ticket_id', $id)->orderBy('m.created_at')->get(['m.id', 'm.user_id', 'u.name as author', 'm.message', 'm.internal_note', 'm.created_at']),
        ], 'Support ticket retrieved.');
    }

    public function adminReplyTicket(Request $request, int $id)
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'min:2', 'max:10000'],
            'internal_note' => ['nullable', 'boolean'],
        ]);
        $ticket = DB::table('support_tickets')->where('id', $id)->first();
        abort_unless($ticket, 404, 'Support ticket not found.');
        DB::table('support_ticket_messages')->insert([
            'ticket_id' => $id, 'user_id' => $request->user()->id, 'message' => $data['message'],
            'internal_note' => (bool) ($data['internal_note'] ?? false), 'created_at' => now(), 'updated_at' => now(),
        ]);
        if (empty($data['internal_note'])) {
            $requesterRole = DB::table('model_has_roles as m')
                ->join('roles as r', 'r.id', '=', 'm.role_id')
                ->where('m.model_type', 'App\\Models\\User')
                ->where('m.model_id', $ticket->user_id)
                ->value('r.name');
            DB::table('marketplace_notifications')->insert([
                'user_id' => $ticket->user_id, 'type' => 'support.reply', 'title' => 'Support replied to your ticket',
                'message' => 'There is a new reply to ticket ' . $ticket->number . '.',
                'action_url' => $requesterRole === 'Vendor' ? '/vendor/support' : '/account/support', 'data' => json_encode(['ticket_id' => $id]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('support_tickets')->where('id', $id)->update(['status' => 'waiting_customer', 'updated_at' => now()]);
        }
        return ApiResponse::successResponse(['ticket_id' => $id], 'Support reply recorded.');
    }

    public function updateTicket(Request $request, int $id)
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:open,in_progress,waiting_customer,resolved,closed'],
            'priority' => ['nullable', 'in:low,normal,high'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'resolution' => ['nullable', 'string', 'max:5000'],
        ]);
        $ticket = DB::table('support_tickets')->where('id', $id)->first();
        abort_unless($ticket, 404, 'Support ticket not found.');
        if (array_key_exists('assigned_to', $data) && $data['assigned_to'] !== null) {
            $isAdministrator = DB::table('model_has_roles as m')
                ->join('roles as r', 'r.id', '=', 'm.role_id')
                ->where('m.model_type', 'App\\Models\\User')
                ->where('m.model_id', $data['assigned_to'])
                ->whereIn('r.name', ['Admin', 'Super Admin'])
                ->exists();
            abort_unless($isAdministrator, 422, 'Support tickets can only be assigned to an Admin or Super Admin.');
        }
        $updates = array_filter($data, fn ($value) => $value !== null);
        if (isset($updates['status']) && $updates['status'] === 'resolved') $updates['resolved_at'] = now();
        if ($updates) { $updates['updated_at'] = now(); DB::table('support_tickets')->where('id', $id)->update($updates); }
        return ApiResponse::successResponse(DB::table('support_tickets')->where('id', $id)->first(), 'Support ticket updated.');
    }

    private function ticketPayload(int $id, int $userId): array
    {
        $ticket = DB::table('support_tickets')->where('id', $id)->where('user_id', $userId)->first();
        return ['ticket' => $ticket, 'messages' => DB::table('support_ticket_messages')->where('ticket_id', $id)->where('internal_note', false)->orderBy('created_at')->get()];
    }
}
