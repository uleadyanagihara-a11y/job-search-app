<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 企業担当者の所属。`user_id` は unique（1 人は 1 社だけ）。企業内の役割は MVP では持たない。
 * 行は招待の受諾（と seeder）だけが作るため、fillable を持たない。
 */
class CompanyMember extends Model
{
    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
