<?php

namespace Tests\Feature\Api;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Tests\TestCase;

/**
 * docs/inertia-removal-migration.md §5.1/§5.5 の error schema を、
 * フレームワーク例外 → ApiExceptionRenderer の変換として直接検証する。
 * ルーティング経由で自然に発生させにくい 404/419/429/500 はハンドラーを直接呼ぶ。
 */
class ApiExceptionRenderingTest extends TestCase
{
    private function apiRequest(): Request
    {
        return Request::create('/api/probe', 'GET');
    }

    public function test_undefined_api_route_returns_not_found_contract(): void
    {
        $response = $this->getJson('/api/this-route-does-not-exist');

        $response->assertStatus(404);
        $response->assertJson(['code' => 'not_found']);
    }

    public function test_authentication_exception_is_rendered_with_unauthenticated_code(): void
    {
        $handler = app(ExceptionHandler::class);

        $response = $handler->render($this->apiRequest(), new AuthenticationException);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('unauthenticated', json_decode($response->getContent(), true)['code']);
    }

    public function test_validation_exception_is_rendered_with_field_errors(): void
    {
        $handler = app(ExceptionHandler::class);

        $exception = ValidationException::withMessages(['email' => ['must be valid']]);

        $response = $handler->render($this->apiRequest(), $exception);
        $body = json_decode($response->getContent(), true);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('validation_failed', $body['code']);
        $this->assertSame(['must be valid'], $body['errors']['email']);
    }

    public function test_token_mismatch_is_rendered_as_csrf_token_mismatch(): void
    {
        $handler = app(ExceptionHandler::class);

        $response = $handler->render($this->apiRequest(), new TokenMismatchException('CSRF token mismatch.'));
        $body = json_decode($response->getContent(), true);

        $this->assertSame(419, $response->getStatusCode());
        $this->assertSame('csrf_token_mismatch', $body['code']);
    }

    public function test_not_found_http_exception_is_rendered_as_not_found(): void
    {
        $handler = app(ExceptionHandler::class);

        $response = $handler->render($this->apiRequest(), new NotFoundHttpException);
        $body = json_decode($response->getContent(), true);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('not_found', $body['code']);
    }

    public function test_throttle_exception_is_rendered_with_retry_after_header(): void
    {
        $handler = app(ExceptionHandler::class);

        $exception = new TooManyRequestsHttpException(30, 'Too Many Attempts.');

        $response = $handler->render($this->apiRequest(), $exception);
        $body = json_decode($response->getContent(), true);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('rate_limited', $body['code']);
        $this->assertEquals('30', $response->headers->get('Retry-After'));
    }

    public function test_unexpected_exception_is_rendered_without_leaking_internal_details(): void
    {
        $handler = app(ExceptionHandler::class);

        $response = $handler->render($this->apiRequest(), new RuntimeException('secret internal detail'));
        $body = json_decode($response->getContent(), true);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('internal_error', $body['code']);
        $this->assertStringNotContainsString('secret internal detail', $response->getContent());
    }

    public function test_web_routes_are_not_affected_by_the_api_exception_renderer(): void
    {
        $response = $this->get('/this-route-does-not-exist-either');

        $response->assertStatus(404);
        $this->assertStringNotContainsString(
            'application/json',
            $response->headers->get('content-type') ?? '',
        );
    }
}
