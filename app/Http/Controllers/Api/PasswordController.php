<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PasswordUpdateRequest;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;

class PasswordController extends Controller
{
    /**
     * PUT /api/profile/password (docs/inertia-removal-migration.md §5.2).
     * body の current_password で本人確認するため `api.password.confirm` は重ねない（§5.5.2）。
     */
    public function update(PasswordUpdateRequest $request): Response
    {
        $request->user()->update([
            'password' => Hash::make($request->validated('password')),
        ]);

        return response()->noContent();
    }
}
