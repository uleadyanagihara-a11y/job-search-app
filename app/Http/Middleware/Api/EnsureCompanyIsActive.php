<?php

namespace App\Http\Middleware\Api;

use App\Exceptions\CompanySuspendedException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * `api.company.active` middleware alias（docs/domain-design.md 2 章「企業の停止」、docs/domain-api-contract.md M2.0・M2.2）。
 *
 * 停止中の企業の担当者を request ごとに締め出す。停止の command は session を消さないため、
 * ログイン中の担当者はここで logout して `403 company_suspended` にする。
 * `auth:sanctum` の後に動く前提（priority で `EnsureApiEmailIsVerified` の直前）。未ログインは auth に任せて素通しする。
 * 求職者は role だけで判定を終え、query を増やさない。
 */
class EnsureCompanyIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->belongsToSuspendedCompany()) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw new CompanySuspendedException;
        }

        return $next($request);
    }
}
