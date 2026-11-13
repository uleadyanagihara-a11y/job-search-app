<?php

namespace App\Policies;

use App\Models\User;

/**
 * 求職者プロフィール（docs/domain-api-contract.md M2.4）。
 *
 * 自分の行しか扱わない API（ID を受け取らない）のため、見るのは「user が求職者か」だけで、
 * 企業担当者は `403 forbidden`（404 にして隠す対象が無い）。
 * 企業担当者が応募者のプロフィールを見る ability は M5 で足す。
 */
class SeekerProfilePolicy
{
    public function view(User $user): bool
    {
        return $user->isSeeker();
    }

    public function update(User $user): bool
    {
        return $user->isSeeker();
    }
}
