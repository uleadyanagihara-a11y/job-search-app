<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rules\Password;

/**
 * POST /api/company-invitations/accept（docs/domain-api-contract.md M2.3）。
 * name・password は register と同じ validation。email は受け取らず、招待に保存したものを使う。
 */
class InvitationAcceptRequest extends InvitationLookupRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
