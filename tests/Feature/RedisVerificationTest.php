<?php

namespace Tests\Feature;

use Tests\TestCase;

class RedisVerificationTest extends TestCase
{
    public function test_laravel_can_set_get_delete_and_expire_redis_keys(): void
    {
        $this->artisan('redis:verify', ['--ttl' => 1])
            ->expectsOutput('Redis 接続: OK')
            ->expectsOutput('DELETE: OK')
            ->expectsOutput('DELETE 後の GET: nil')
            ->expectsOutput('期限切れ後の GET: nil')
            ->expectsOutput('Redis の set / get / delete / TTL をすべて確認しました。')
            ->assertSuccessful();
    }

    public function test_ttl_must_be_an_integer_between_one_and_three_hundred(): void
    {
        $this->artisan('redis:verify', ['--ttl' => 0])
            ->expectsOutput('--ttl には 1〜300 の整数を指定してください。')
            ->assertFailed();
    }
}
