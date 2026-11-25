<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmailLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'to' => $this->to,
            'subject' => $this->subject,
            'status' => $this->status,
            'errorMessage' => $this->error_message,
            'sentAt' => $this->sent_at?->toISOString(),
            'failedAt' => $this->failed_at?->toISOString(),
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
