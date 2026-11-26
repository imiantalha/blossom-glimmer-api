<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AuditLog::query()->with('actor:id,name,email');

        if ($request->filled('actor_id')) {
            $query->where('actor_user_id', $request->integer('actor_id'));
        }
        if ($request->filled('role')) {
            $query->where('actor_role', $request->string('role')->toString());
        }
        if ($request->filled('action')) {
            $query->where('action', 'like', '%' . $request->string('action')->toString() . '%');
        }
        if ($request->filled('status')) {
            $query->where('status_code', $request->integer('status'));
        }
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->date('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->date('to'));
        }
        if ($request->filled('search')) {
            $term = '%' . addcslashes($request->string('search')->toString(), '%_') . '%';
            $query->where(function ($builder) use ($term) {
                $builder->where('path', 'like', $term)
                    ->orWhere('subject_type', 'like', $term)
                    ->orWhere('subject_id', 'like', $term)
                    ->orWhereHas('actor', fn ($actor) => $actor
                        ->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term));
            });
        }

        $logs = $query->latest('id')->paginate(min(max($request->integer('per_page', 30), 1), 100));

        return response()->json([
            'success' => true,
            'message' => 'Audit activity retrieved successfully.',
            'data' => $logs->getCollection()->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'actor' => $log->actor ? [
                    'id' => $log->actor->id,
                    'name' => $log->actor->name,
                    'email' => $log->actor->email,
                ] : null,
                'actorRole' => $log->actor_role,
                'action' => $log->action,
                'method' => $log->method,
                'path' => $log->path,
                'subjectType' => $log->subject_type,
                'subjectId' => $log->subject_id,
                'statusCode' => $log->status_code,
                'ipAddress' => $log->ip_address,
                'metadata' => $log->metadata,
                'createdAt' => $log->created_at?->toISOString(),
            ])->values(),
            'pagination' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'count' => $logs->count(),
                'from' => $logs->firstItem(),
                'to' => $logs->lastItem(),
                'has_previous' => $logs->currentPage() > 1,
                'has_next' => $logs->hasMorePages(),
            ],
        ]);
    }
}
