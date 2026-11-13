<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * lang/ja に Laravel 同梱の英語と同じ key があることを確かめる（docs/domain-design.md 14 章）。
 * Laravel の更新で rule が増えると、key の無い文だけ英語の既定文になるため、Dependabot の PR の CI で気づけるようにする。
 * 足りない key が出たら `sail artisan lang:publish` の出力（lang/en）を見て訳を足し、lang/en は消す。
 */
class TranslationKeysTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function translationGroups(): array
    {
        return array_combine(
            $names = ['auth', 'pagination', 'passwords', 'validation'],
            array_map(fn (string $name) => [$name], $names),
        );
    }

    #[DataProvider('translationGroups')]
    public function test_japanese_lines_cover_every_framework_key(string $group): void
    {
        $base = dirname(__DIR__, 2);
        $en = require $base."/vendor/laravel/framework/src/Illuminate/Translation/lang/en/{$group}.php";
        $ja = require $base."/lang/ja/{$group}.php";

        $this->assertSame([], array_values(array_diff(self::keys($en), self::keys($ja))), "lang/ja/{$group}.php に無い key");
    }

    /**
     * `between.string` のような dot 区切りの key の一覧。custom・attributes はアプリ側で決めるため比べない。
     *
     * @param  array<string, mixed>  $lines
     * @return list<string>
     */
    private static function keys(array $lines, string $prefix = ''): array
    {
        $keys = [];

        foreach ($lines as $key => $value) {
            if ($prefix === '' && in_array($key, ['custom', 'attributes'], true)) {
                continue;
            }

            $keys = [...$keys, ...(is_array($value) ? self::keys($value, "{$prefix}{$key}.") : ["{$prefix}{$key}"])];
        }

        return $keys;
    }
}
