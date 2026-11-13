<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/company-invitations/lookup（docs/domain-api-contract.md M2.3）。
 * token は path ではなく body で受け取る（access log に残さないため。docs/domain-design.md 2 章）。
 */
class InvitationLookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
        ];
    }

    /**
     * lang/ja/validation.php の `token` は「再設定用のトークン」のため、招待では上書きする。
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'token' => '招待のリンク',
        ];
    }
}
