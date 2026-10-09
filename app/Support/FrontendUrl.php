<?php

namespace App\Support;

class FrontendUrl
{
    /**
     * SPA（FRONTEND_URL）上の絶対 URL を生成する。
     *
     * @param  array<string, string|int>  $query
     */
    public static function to(string $path, array $query = []): string
    {
        $url = rtrim(config('app.frontend_url'), '/').'/'.ltrim($path, '/');

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }
}
