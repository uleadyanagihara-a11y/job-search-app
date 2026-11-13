<?php

namespace App\Http\Requests;

use App\Enums\Prefecture;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /api/seeker-profile（docs/domain-api-contract.md M2.3）。
 * 全 field を送る置き換えで、送らなかった field は null にする。
 * 認可は controller の Gate::authorize に 1 本化しているため、ここでは判定しない
 * （controller は認可の後にこの request を解決する）。
 */
class SeekerProfileUpdateRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'desired_prefecture' => ['nullable', 'integer', Rule::enum(Prefecture::class)],
            'desired_job_type' => ['nullable', 'string', 'max:100'],
            'self_pr' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * 送らなかった field も null として含めた、保存する値。
     *
     * @return array{desired_prefecture: int|null, desired_job_type: string|null, self_pr: string|null}
     */
    public function profileAttributes(): array
    {
        $validated = $this->validated();

        return [
            'desired_prefecture' => $validated['desired_prefecture'] ?? null,
            'desired_job_type' => $validated['desired_job_type'] ?? null,
            'self_pr' => $validated['self_pr'] ?? null,
        ];
    }
}
