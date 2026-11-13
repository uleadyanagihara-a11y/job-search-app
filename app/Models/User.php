<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * `role` と所属企業は利用者が変えられないため、`#[Fillable]` に入れない（docs/domain-design.md 1 章）。
 * 作る側（register・招待の受諾）が forceFill で明示する。
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
        ];
    }

    /**
     * @return HasOne<CompanyMember, $this>
     */
    public function companyMember(): HasOne
    {
        return $this->hasOne(CompanyMember::class);
    }

    /**
     * @return HasOne<SeekerProfile, $this>
     */
    public function seekerProfile(): HasOne
    {
        return $this->hasOne(SeekerProfile::class);
    }

    public function isSeeker(): bool
    {
        return $this->role === Role::Seeker;
    }

    public function isCompanyMember(): bool
    {
        return $this->role === Role::Company;
    }

    /**
     * 所属企業が停止中か（docs/domain-design.md 2 章「企業の停止」）。
     */
    public function belongsToSuspendedCompany(): bool
    {
        return $this->isCompanyMember() && $this->companyMember?->company->isSuspended() === true;
    }
}
