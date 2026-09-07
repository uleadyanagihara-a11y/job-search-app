<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Throwable;

class VerifyRedis extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'redis:verify
                            {--ttl=2 : TTL キーが期限切れになるまでの秒数（1〜300）}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Redis の set / get / delete と TTL による期限切れを確認する';

    public function handle(): int
    {
        $ttl = filter_var($this->option('ttl'), FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 1,
                'max_range' => 300,
            ],
        ]);

        if ($ttl === false) {
            $this->error('--ttl には 1〜300 の整数を指定してください。');

            return self::FAILURE;
        }

        $identifier = (string) Str::uuid();
        $key = "redis-verification:crud:{$identifier}";
        $ttlKey = "redis-verification:ttl:{$identifier}";
        $value = 'Laravel to Redis';
        $ttlValue = 'expires automatically';
        $redis = Redis::connection('default');

        try {
            $redis->command('ping');
            $this->info('Redis 接続: OK');

            $redis->set($key, $value);
            $this->line("SET: {$key} = {$value}");

            if ($redis->get($key) !== $value) {
                throw new \RuntimeException('SET した値を GET できませんでした。');
            }

            $this->line("GET: {$value}");

            if ((int) $redis->del($key) !== 1) {
                throw new \RuntimeException('キーを DELETE できませんでした。');
            }

            $this->line('DELETE: OK');

            if (! $this->isMissing($redis->get($key))) {
                throw new \RuntimeException('DELETE 後もキーが存在しています。');
            }

            $this->line('DELETE 後の GET: nil');

            $redis->setex($ttlKey, $ttl, $ttlValue);

            if ($redis->get($ttlKey) !== $ttlValue) {
                throw new \RuntimeException('TTL 付きキーを GET できませんでした。');
            }

            $remainingTtl = (int) $redis->ttl($ttlKey);

            if ($remainingTtl < 1 || $remainingTtl > $ttl) {
                throw new \RuntimeException("TTL が想定範囲外です: {$remainingTtl}");
            }

            $this->line("TTL SET: {$ttlKey}（{$ttl} 秒）");
            $this->line("TTL: {$remainingTtl} 秒");
            $this->line("{$ttl} 秒間、期限切れを待機します...");

            sleep($ttl);
            $this->waitUntilExpired($redis, $ttlKey);

            if (! $this->isMissing($redis->get($ttlKey)) || (int) $redis->ttl($ttlKey) !== -2) {
                throw new \RuntimeException('指定時間後も TTL 付きキーが存在しています。');
            }

            $this->line('期限切れ後の GET: nil');
            $this->info('Redis の set / get / delete / TTL をすべて確認しました。');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Redis の確認に失敗しました: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            try {
                $redis->del($key, $ttlKey);
            } catch (Throwable) {
                // 接続障害時は、元のエラーを後片付けの失敗で上書きしない。
            }
        }
    }

    private function waitUntilExpired(Connection $redis, string $key): void
    {
        $deadline = microtime(true) + 1;

        while (! $this->isMissing($redis->get($key)) && microtime(true) < $deadline) {
            usleep(50_000);
        }
    }

    private function isMissing(mixed $value): bool
    {
        return $value === null || $value === false;
    }
}
