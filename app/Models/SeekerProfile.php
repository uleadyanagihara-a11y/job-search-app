<?php

namespace App\Models;

use App\Enums\Prefecture;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 求職者プロフィール（docs/domain-api-contract.md M2.3）。主キーは `user_id`。
 * 集めない情報（生年月日・性別・本籍など）の項目は作らない（docs/domain-design.md 3 章）。
 */
#[Fillable(['desired_prefecture', 'desired_job_type', 'self_pr'])]
class SeekerProfile extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'desired_prefecture' => Prefecture::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
