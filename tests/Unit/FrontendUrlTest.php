<?php

namespace Tests\Unit;

use App\Support\FrontendUrl;
use Tests\TestCase;

class FrontendUrlTest extends TestCase
{
    public function test_trailing_slash_of_frontend_url_is_not_duplicated(): void
    {
        config(['app.frontend_url' => 'https://spa.example.test/']);

        $this->assertSame(
            'https://spa.example.test/verify-email?error=invalid-or-expired',
            FrontendUrl::to('/verify-email', ['error' => 'invalid-or-expired']),
        );
    }

    public function test_query_is_omitted_when_empty(): void
    {
        config(['app.frontend_url' => 'https://spa.example.test']);

        $this->assertSame('https://spa.example.test/login', FrontendUrl::to('login'));
    }
}
