<?php

namespace Tests;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

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

    /**
     * `$sqlFragment` を含む SQL が最初に実行された直後に、`$otherRequest` を 1 回だけ実行する。
     *
     * 同時に届いた別の request が、事前チェックの後・書き込みの前に commit した状態を作るために使う。
     * RefreshDatabase が test 全体を 1 つの transaction で包むため、本当に並列な 2 つの接続は作れない。
     * そこで競合が起きる時点に相手の書き込みを差し込み、lock の確かめ直しや unique 制約で守れているかを毎回同じ形で確かめる。
     * 実行されなかった（SQL が変わって一致しなくなった）ら test を落とす。
     */
    protected function interleaveAfterQuery(string $sqlFragment, Closure $otherRequest): void
    {
        $fired = false;

        DB::listen(function (QueryExecuted $query) use ($sqlFragment, $otherRequest, &$fired) {
            if ($fired || ! str_contains($query->sql, $sqlFragment)) {
                return;
            }

            // $otherRequest の中の query でもう一度呼ばれないよう、実行の前に印を付ける。
            $fired = true;
            $otherRequest();
        });

        $this->beforeApplicationDestroyed(function () use (&$fired, $sqlFragment) {
            $this->assertTrue($fired, "割り込む SQL が実行されなかった: {$sqlFragment}");
        });
    }
}
