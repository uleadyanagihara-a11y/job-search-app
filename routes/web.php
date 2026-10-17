<?php

use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Middleware\ThrottleEmailVerificationCallback;
use Illuminate\Support\Facades\Route;

// 画面は SPA（frontend/）が持ち、Laravel は API と次の Web callback だけを担当する。

// メール認証 Web callback（docs/inertia-removal-migration.md §5.4）。
// 実行順は throttle → signed → auth。throttle を先頭に置くのは、signed/auth で弾かれる
// リクエスト（署名総当たり・未ログインアクセス）もレート制限の対象にするため。
// 実行順はここに書いた順ではなく bootstrap/app.php の priority 設定で決まる。
// - throttle: 標準の ThrottleRequests は $middlewarePriority で auth の後ろへ並べ替えられる
//   ため、専用 middleware を使う（失敗時は SPA へ redirect）。
// - signed: 失敗時は bootstrap/app.php で SPA へ redirect する。
// - auth: 未ログイン時は bootstrap/app.php の redirectGuestsTo で SPA の login へ。
// path は SPA の /verify-email 画面と prefix が重ならないよう /auth/email/verify/ 配下に置く
// （Nginx が prefix で Laravel へ proxy する）。
Route::get('auth/email/verify/{id}/{hash}', VerifyEmailController::class)
    ->middleware([ThrottleEmailVerificationCallback::class, 'signed', 'auth'])
    ->name('verification.verify');
