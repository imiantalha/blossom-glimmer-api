<?php

namespace App\\Http\\Resources;

use Illuminate\\Http\\Request;
use Illuminate\\Http\\Resources\\Json\\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $roles = $this->getRoleNames()->values();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $roles->first() ?? 'customer',
            'roles' => $roles,
            'permissions' => $this->getAllPermissions()->pluck('name')->values(),
            'emailVerifiedAt' => $this->email_verified_at?->toISOString(),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
