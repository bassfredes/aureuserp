<?php

namespace Webkul\Security\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvitationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'email'       => $this->email,
            'company_id'  => $this->company_id,
            'role_id'     => $this->role_id,
            'invited_by'  => $this->invited_by,
            'expires_at'  => $this->expires_at,
            'accepted_at' => $this->accepted_at,
            'created_at'  => $this->created_at,
            'updated_at'  => $this->updated_at,
        ];
    }
}
