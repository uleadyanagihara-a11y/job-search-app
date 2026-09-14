<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CsrfCookieTest extends TestCase
{
    use RefreshDatabase;

    public function test_csrf_cookie_endpoint_issues_an_xsrf_token_cookie(): void
    {
        $response = $this->get('/sanctum/csrf-cookie');

        $response->assertNoContent();
        $response->assertCookie('XSRF-TOKEN');
    }
}
