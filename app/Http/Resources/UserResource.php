<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 公開フィールドは docs/phase0-api-contract.md §0.3 で確定済み。
 * `role`・`company_id` は docs/domain-api-contract.md M2.2 で追加した（求職者は `company_id: null`）。
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at?->clone()->utc()->format('Y-m-d\TH:i:s\Z'),
            'role' => $this->role->value,
            'company_id' => $this->isCompanyMember() ? $this->companyMember?->company_id : null,
        ];
    }
}
