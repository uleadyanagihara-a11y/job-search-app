<?php

namespace App\Http\Resources;

use App\Models\CompanyInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 招待の lookup（docs/domain-api-contract.md M2.3）。受諾画面に会社名と email（変更不可）を出すためのもの。
 * token・企業の id は返さない。
 *
 * @mixin CompanyInvitation
 */
class InvitationSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'company_name' => $this->company->name,
            'email' => $this->email,
            'expires_at' => $this->expires_at->clone()->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
