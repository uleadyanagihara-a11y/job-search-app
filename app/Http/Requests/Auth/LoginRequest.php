<?php

namespace App\Http\Requests\Auth;

use App\Exceptions\CompanySuspendedException;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * 停止中の企業の担当者は password が正しいときだけ 403 company_suspended にする
     * （docs/domain-api-contract.md M2.2）。password が誤りなら停止中でも 422 にし、停止中かどうかを
     * 第三者に知らせない。停止で拒否した request は失敗回数に数えない。
     *
     * @throws ValidationException
     * @throws CompanySuspendedException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        // attemptWhen の callback は password が一致した user にだけ呼ばれる。
        $suspended = false;

        $authenticated = Auth::attemptWhen(
            $this->only('email', 'password'),
            function (User $user) use (&$suspended): bool {
                $suspended = $user->belongsToSuspendedCompany();

                return ! $suspended;
            },
            $this->boolean('remember'),
        );

        if ($suspended) {
            throw new CompanySuspendedException;
        }

        if (! $authenticated) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * §5.5.3 どおり 429 rate_limited（Retry-After 付き）を返す。
     *
     * @throws ThrottleRequestsException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        throw new ThrottleRequestsException(headers: [
            'Retry-After' => RateLimiter::availableIn($this->throttleKey()),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
