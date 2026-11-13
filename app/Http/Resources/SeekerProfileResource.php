<?php

namespace App\Http\Resources;

use App\Models\SeekerProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * docs/domain-api-contract.md M2.3「求職者プロフィール」。
 * 行がまだ無い求職者には、保存していない新しい model を渡す（全 field が null になる）。
 *
 * @mixin SeekerProfile
 */
class SeekerProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'desired_prefecture' => $this->desired_prefecture?->value,
            'desired_job_type' => $this->desired_job_type,
            'self_pr' => $this->self_pr,
            'updated_at' => $this->updated_at?->clone()->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
