<?php

namespace App\Support\Api;

use Illuminate\Http\JsonResponse;

class ApiErrorResponse
{
    /**
     * Build the JSON error response contract shared by API middleware and
     * the framework exception renderer (docs/inertia-removal-migration.md §5.5.3).
     *
     * @param  array<string, mixed>  $errors
     * @param  array<string, string>  $headers
     */
    public static function make(string $code, string $message, int $status, array $errors = [], array $headers = []): JsonResponse
    {
        $body = [
            'code' => $code,
            'message' => $message,
        ];

        if ($errors !== []) {
            $body['errors'] = $errors;
        }

        return response()->json($body, $status, $headers);
    }
}
