<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PasswordUpdateRequest;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PasswordController extends Controller
{
    /**
     * PUT /api/profile/password (docs/inertia-removal-migration.md §5.2).
     * body の current_password で本人確認するため `api.password.confirm` は重ねない（§5.5.2）。
     * reset と同様に remember_token を再生成し、他端末の remember cookie を無効化する。
     */
    public function update(PasswordUpdateRequest $request): Response
    {
        $request->user()->forceFill([
            'password' => Hash::make($request->validated('password')),
            'remember_token' => Str::random(60),
        ])->save();

        return response()->noContent();
    }
}
