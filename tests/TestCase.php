<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * SanctumはReferer/Originがsanctum.statefulに一致するリクエストだけを
     * 「frontend」として扱い、session middlewareを有効化する。login/logoutは
     * sessionを書き換えるため、SPAからのリクエストを模してヘッダーを付与する。
     */
    protected function fromFrontend(): static
    {
        return $this->withHeader('Referer', config('app.url'));
    }
}
