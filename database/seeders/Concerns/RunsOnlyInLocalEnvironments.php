<?php

namespace Database\Seeders\Concerns;

use RuntimeException;

/**
 * 固定の password の user を作る seeder は、local・testing 以外では例外にする
 * （本番で誤って実行しても user ができないようにする。docs/domain-design.md 2 章）。
 */
trait RunsOnlyInLocalEnvironments
{
    protected function ensureLocalEnvironment(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(static::class.' は local・testing 環境でしか実行できません（現在: '.app()->environment().'）。');
        }
    }
}
