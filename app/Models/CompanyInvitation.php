<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 企業担当者の招待（docs/domain-design.md 2 章、docs/domain-api-contract.md M2.1・M2.3）。
 *
 * token は平文を保存せず SHA-256 の hash で持つ。token 自体が 64 文字のランダム値で十分長いため、
 * password のような遅い hash ではなく、検索できる hash にする。
 */
class CompanyInvitation extends Model
{
    public const TOKEN_LENGTH = 64;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * 招待を作り、平文の token を返す（メールの URL にだけ使い、保存しない）。
     * 同じ企業・同じ email の未使用の招待は無効にする。
     */
    public static function issue(Company $company, string $email): string
    {
        $token = Str::random(self::TOKEN_LENGTH);

        DB::transaction(function () use ($company, $email, $token) {
            $company->invitations()
                ->where('email', $email)
                ->whereNull('accepted_at')
                ->where('expires_at', '>', now())
                ->update(['expires_at' => now()]);

            $invitation = new self;
            $invitation->company()->associate($company);
            $invitation->email = $email;
            $invitation->token_hash = self::hashToken($token);
            $invitation->expires_at = now()->addMinutes((int) config('auth.invitations.expire'));
            $invitation->save();
        });

        return $token;
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * 有効（未使用・期限内）な招待。無効・期限切れ・使用済みを区別しない（呼び出し側は同じ 422 にする）。
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUsable(Builder $query, string $token): Builder
    {
        return $query
            ->where('token_hash', self::hashToken($token))
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now());
    }
}
