<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/profile。
 *
 * `role`・所属企業は利用者が変えられないため rules に入れない（docs/domain-design.md 1 章）。
 * controller は profileAttributes() だけを fill する。
 */
class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
            // email を変えるときだけ現在の password で本人確認する（docs/domain-api-contract.md M2.2）。
            // 変えないときは送られても照合しない（誤った値でも name の更新を妨げない）。
            'current_password' => $this->changesEmail()
                ? ['required', 'string', 'current_password']
                : ['exclude'],
        ];
    }

    /**
     * email が空のときは email の required だけを返し、current_password の error を重ねない。
     */
    public function changesEmail(): bool
    {
        return $this->filled('email') && $this->input('email') !== $this->user()->email;
    }

    /**
     * @return array{name: string, email: string}
     */
    public function profileAttributes(): array
    {
        /** @var array{name: string, email: string} */
        return $this->safe()->only(['name', 'email']);
    }
}
