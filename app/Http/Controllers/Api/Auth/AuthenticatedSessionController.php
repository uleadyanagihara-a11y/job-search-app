<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class AuthenticatedSessionController extends Controller
{
    /**
     * POST /api/auth/login (docs/inertia-removal-migration.md §5.2).
     */
    public function store(LoginRequest $request): JsonResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        return (new UserResource($request->user()))
            ->additional(['meta' => ['redirect_to' => $this->redirectTo($request)]])
            ->response();
    }

    /**
     * session の intended URL がメール認証 callback なら、それを redirect_to として返す（§5.4）。
     *
     * host は捨てて path + query だけを返すため、外部 URL への open redirect にならない。
     * intended は一度使ったら（許可しない URL でも）消す。
     */
    private function redirectTo(Request $request): string
    {
        $intended = (string) $request->session()->pull('url.intended');

        $path = (string) parse_url($intended, PHP_URL_PATH);
        $query = parse_url($intended, PHP_URL_QUERY);

        if (! str_starts_with($path, '/auth/email/verify/')) {
            return '/dashboard';
        }

        return $query ? $path.'?'.$query : $path;
    }

    /**
     * POST /api/auth/logout (docs/inertia-removal-migration.md §5.2).
     */
    public function destroy(Request $request): Response
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
