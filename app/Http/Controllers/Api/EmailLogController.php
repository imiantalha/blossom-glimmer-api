<?php

namespace App\Http\Controllers\Api;

use App\Models\EmailLog;
use App\Http\Resources\EmailLogResource;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use App\Jobs\SendEmailJob;
use App\Http\Controllers\Controller;

class EmailLogController extends Controller
{
    public function index(Request $request)
    {
        $logs = EmailLog::query()
            ->when($request->filled('status'), function ($query) use ($request) {
                $query->where('status', $request->status);
            })
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->search;

                $query->where(function ($query) use ($search) {
                    $query->where('to', 'like', "%{$search}%")
                        ->orWhere('subject', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return ApiResponse::paginatedResponse(
            EmailLogResource::collection($logs),
            'Email logs retrieved successfully.'
        );
    }

    public function show(EmailLog $emailLog)
    {
        return ApiResponse::successResponse(
            new EmailLogResource($emailLog),
            'Email log retrieved successfully.'
        );
    }

    public function destroy(EmailLog $emailLog)
    {
        $emailLog->delete();

        return ApiResponse::successResponse(
            null,
            'Email log deleted successfully.'
        );
    }

    public function retry(EmailLog $emailLog)
    {
        if ($emailLog->status !== 'failed') {
            return ApiResponse::errorResponse(
                'Only failed emails can be retried.',
                422
            );
        }

        $emailLog->update([
            'status' => 'queued',
            'error_message' => null,
            'failed_at' => null,
        ]);

        SendEmailJob::dispatch($emailLog->id);

        return ApiResponse::successResponse(
            new EmailLogResource($emailLog->fresh()),
            'Email queued for retry successfully.'
        );
    }
}