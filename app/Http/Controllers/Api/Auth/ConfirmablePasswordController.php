<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ConfirmablePasswordController extends Controller
{
    /**
     * POST /api/auth/confirm-password (docs/inertia-removal-migration.md §5.2).
     * 既存の web 版と同じ session 値 `auth.password_confirmed_at` を書き込む。
     */
    public function store(Request $request): Response
    {
        $request->validate(['password' => ['required', 'string']]);

        if (! Auth::guard('web')->validate([
            'email' => $request->user()->email,
            'password' => $request->input('password'),
        ])) {
            throw ValidationException::withMessages([
                'password' => __('auth.password'),
            ]);
        }

        $request->session()->put('auth.password_confirmed_at', time());

        return response()->noContent();
    }
}
