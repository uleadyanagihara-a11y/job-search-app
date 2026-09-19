<?php

namespace App\Support\Api;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Maps framework exceptions to the API error contract (docs/inertia-removal-migration.md §5.5.3).
 * middleware は ApiErrorResponse を直接呼び、ここでは例外だけを同じ形に変換する。
 */
class ApiExceptionRenderer
{
    public static function render(Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof AuthenticationException => ApiErrorResponse::make(
                'unauthenticated',
                '認証が必要です。',
                Response::HTTP_UNAUTHORIZED,
            ),
            $e instanceof ValidationException => ApiErrorResponse::make(
                'validation_failed',
                '入力内容を確認してください。',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $e->errors(),
            ),
            $e instanceof HttpExceptionInterface => static::renderHttpException($e),
            default => ApiErrorResponse::make(
                'internal_error',
                '予期しないエラーが発生しました。',
                Response::HTTP_INTERNAL_SERVER_ERROR,
            ),
        };
    }

    private static function renderHttpException(HttpExceptionInterface $e): JsonResponse
    {
        return match ($e->getStatusCode()) {
            403 => ApiErrorResponse::make('forbidden', 'この操作は許可されていません。', 403),
            404 => ApiErrorResponse::make('not_found', '対象のリソースが見つかりません。', 404),
            419 => ApiErrorResponse::make('csrf_token_mismatch', 'CSRFトークンが不正か期限切れです。再読み込みしてください。', 419),
            429 => ApiErrorResponse::make(
                'rate_limited',
                'しばらく時間をおいて再度お試しください。',
                429,
                [],
                array_filter(['Retry-After' => $e->getHeaders()['Retry-After'] ?? null]),
            ),
            default => ApiErrorResponse::make('internal_error', '予期しないエラーが発生しました。', 500),
        };
    }
}
