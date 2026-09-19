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
        // §5.1 の status/code 表にあるものだけを公開し、未定義の status は renderUnlistedStatus() で扱う。
        return match ($e->getStatusCode()) {
            400 => ApiErrorResponse::make('bad_request', 'リクエストが不正です。', 400),
            401 => ApiErrorResponse::make('unauthenticated', '認証が必要です。', 401),
            403 => ApiErrorResponse::make('forbidden', 'この操作は許可されていません。', 403),
            404 => ApiErrorResponse::make('not_found', '対象のリソースが見つかりません。', 404),
            409 => ApiErrorResponse::make('conflict', '現在の状態ではこの操作を実行できません。', 409),
            405 => ApiErrorResponse::make(
                'method_not_allowed',
                'このHTTPメソッドは許可されていません。',
                405,
                [],
                array_filter(['Allow' => $e->getHeaders()['Allow'] ?? null]),
            ),
            419 => ApiErrorResponse::make('csrf_token_mismatch', 'CSRFトークンが不正か期限切れです。再読み込みしてください。', 419),
            429 => ApiErrorResponse::make(
                'rate_limited',
                'しばらく時間をおいて再度お試しください。',
                429,
                [],
                array_filter(['Retry-After' => $e->getHeaders()['Retry-After'] ?? null]),
            ),
            503 => ApiErrorResponse::make(
                'service_unavailable',
                'ただいま利用できません。しばらくしてから再度お試しください。',
                503,
                [],
                array_filter(['Retry-After' => $e->getHeaders()['Retry-After'] ?? null]),
            ),
            default => static::renderUnlistedStatus($e),
        };
    }

    /** 表にない 4xx は status を保った汎用 client_error にし、それ以外は 500 に丸める。 */
    private static function renderUnlistedStatus(HttpExceptionInterface $e): JsonResponse
    {
        $status = $e->getStatusCode();

        if ($status >= 400 && $status < 500) {
            return ApiErrorResponse::make('client_error', 'リクエストを処理できません。', $status);
        }

        return ApiErrorResponse::make('internal_error', '予期しないエラーが発生しました。', 500);
    }
}
