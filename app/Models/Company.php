<?php

namespace App\Models;

use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `suspended_at` は運営の artisan command だけが変える（docs/domain-design.md 2 章）。
 */
#[Fillable(['name', 'description', 'website_url', 'prefecture'])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'suspended_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<CompanyMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(CompanyMember::class);
    }

    /**
     * @return HasMany<CompanyInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(CompanyInvitation::class);
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }
}
