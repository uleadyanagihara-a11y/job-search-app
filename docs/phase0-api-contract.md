# Phase 0: 契約確定（Inertia 撤去移行）

[inertia-removal-migration.md](./inertia-removal-migration.md) の「Phase 0: 契約を固定する」の完了記録。
このドキュメント確定後、Phase 1 以降で API path / status / error schema / 認証方式を変更しない。

## 0.1 前提として行った変更: `MustVerifyEmail` の有効化

契約確定にあたり、現状のコードを調査した結果、`app/Models/User.php` は
`Illuminate\Contracts\Auth\MustVerifyEmail` を実装しておらず（import がコメントアウト済み）、
次が実質無効化されていることが判明した。

- `routes/web.php` の `/dashboard` route に付与された `verified` middleware
- `EmailVerificationPromptController` / `ProfileController::edit` の `mustVerifyEmail` 判定

`Illuminate\Foundation\Auth\User`（親クラス）は `Illuminate\Auth\MustVerifyEmail` trait を
既に `use` しているため、`hasVerifiedEmail()` 等のメソッド自体は元から呼び出せていたが、
「未認証 user を実際に弾く」判定（`instanceof MustVerifyEmail`）だけが機能していなかった。

このズレを残したまま `api.verified` middleware や `403 email_unverified` を設計すると、
「仕様上は存在するが実運用では絶対に発火しない code」になり契約として不誠実なため、
ユーザー判断により **この移行の一部として `MustVerifyEmail` を有効化する** ことにした。

変更: [User.php](../app/Models/User.php) に `implements MustVerifyEmail` を追加。

影響確認: 既存 feature test は `/dashboard` への実際の GET を検証していない
（redirect 先 URL の一致だけを見ている）ため、`./vendor/bin/sail test` は
変更前後とも 28 tests / 76 assertions 全て pass。挙動としては今後
「未認証 user が `/dashboard` に到達すると `verify-email` へ誘導される」が
初めて実効化される（API 化後は `403 email_unverified` として表現）。

## 0.2 API 契約の確定

migration doc の §5.1〜§5.5 を最終契約として確定する。実装時に endpoint 名・status・code を
変更しない。特に以下を Phase 0 の追加決定事項として明記する。

### 5.2 の endpoint path はそのまま確定

`/api/user`, `/api/auth/register`, `/api/auth/login`, `/api/auth/logout`,
`/api/auth/forgot-password`, `/api/auth/reset-password`,
`/api/auth/email/verification-notification`, `/api/auth/confirm-password`,
`/api/profile`, `/api/profile/password` を最終 path とする。

### `email_unverified` / `api.verified` は実際に到達し得る分岐として確定

0.1 の変更により、`api.verified` middleware は Phase 1 以降で実装した時点から
実際に `403 email_unverified` を返し得る。ダミーの仕様ではなく実装必須の分岐として扱う。

### password 再確認まわり

`auth.password_confirmed_at` セッション値を使う既存の `ConfirmablePasswordController` の挙動を
そのまま `api.password.confirm` middleware（§5.5.1）の判定ロジックに移す。有効期間は
`config('auth.password_timeout')`（Laravel 標準デフォルト 10800 秒）に従う。

## 0.3 `UserResource` の公開フィールド確定

`GET /api/user`, `POST /api/auth/register`, `POST /api/auth/login`,
`PATCH /api/profile` が返す `data` オブジェクトの形を次の4フィールドに確定する。

| field | 型 | 例 | 備考 |
| --- | --- | --- | --- |
| `id` | integer | `1` | `users.id`。型を今後変えない |
| `name` | string | `"Taro Yamada"` | `users.name` |
| `email` | string | `"taro@example.com"` | `users.email` |
| `email_verified_at` | string \| null | `"2026-09-14T01:23:45Z"` | RFC3339 UTC。未認証時は明示的に `null`（§5.1 の nullable field 規約） |

`password`, `remember_token` は含めない（Model 側で既に `#[Hidden]` 済みだが、
`JsonResource` 側でも明示的に許可リスト化し、Model の隠しフィールド設定に依存しない）。
`created_at` / `updated_at` は現時点でどの画面も参照していないため含めない。将来必要になった
時点で追加する（既存 field の型・意味を変えない限り追加は破壊的変更にならない）。

`mustVerifyEmail`（旧 Inertia の `ProfileController::edit` props）は user resource には含めず、
`email_verified_at === null` から SPA 側で導出する。これにより `mustVerifyEmail` という
Model 非依存の概念フラグを API 契約に持ち込まずに済む。

## 0.4 既存 feature test の振る舞い一覧（Inertia 撤去後も担保すべき内容）

Phase 2 以降で API test に置き換える際、ここに列挙した「振る舞い」を落とさないことを
完了条件とする（file:line は置換元の参照であり、置換後も同じ file である必要はない）。

### [tests/Feature/Auth/AuthenticationTest.php](../tests/Feature/Auth/AuthenticationTest.php)

| test | 現在の振る舞い | 置換後の API 契約 |
| --- | --- | --- |
| `test_login_screen_can_be_rendered` | `GET /login` が 200 | 画面配信は Laravel の責務でなくなるため廃止。SPA E2E の `GET /login` が SPA `index.html` を返すことで代替（§10.5） |
| `test_users_can_authenticate_using_the_login_screen` | 正しい credential で `POST /login` → 認証成立 + dashboard へ redirect | `POST /api/auth/login` → `200 { data: user, meta: { redirect_to } }` + `assertAuthenticated()` |
| `test_users_can_not_authenticate_with_invalid_password` | 誤 password で `POST /login` → guest のまま | `POST /api/auth/login` → `422 validation_failed`（`email` field error）+ `assertGuest()` |
| `test_users_can_logout` | `POST /logout` → guest 化 + `/` へ redirect | `POST /api/auth/logout` → `204` + `assertGuest()`。redirect は SPA 側の責務 |

### [tests/Feature/Auth/RegistrationTest.php](../tests/Feature/Auth/RegistrationTest.php)

| test | 現在の振る舞い | 置換後の API 契約 |
| --- | --- | --- |
| `test_registration_screen_can_be_rendered` | `GET /register` が 200 | 廃止。SPA E2E で代替 |
| `test_new_users_can_register` | 有効な入力で `POST /register` → 即ログイン状態 + dashboard へ redirect | `POST /api/auth/register` → `201 { data: user, meta: { redirect_to } }` + `assertAuthenticated()`。email 重複等の検証エラーは `422` |

### [tests/Feature/Auth/PasswordResetTest.php](../tests/Feature/Auth/PasswordResetTest.php)

| test | 現在の振る舞い | 置換後の API 契約 |
| --- | --- | --- |
| `test_reset_password_link_screen_can_be_rendered` | `GET /forgot-password` が 200 | 廃止。SPA E2E で代替 |
| `test_reset_password_link_can_be_requested` | `POST /forgot-password` で `ResetPassword` 通知が送信される | `POST /api/auth/forgot-password` → `200 { code: "password_reset_link_sent", message }`。通知送信自体は不変。存在しない email でも同一 response（enumeration 対策、§5.2 補足） |
| `test_reset_password_screen_can_be_rendered` | 通知に含まれる token で `GET /reset-password/{token}` が 200 | 廃止。Laravel は screen を持たない。SPA の `/reset-password/:token?email=...` が §5.3 の直接遷移 URL で開けることを SPA E2E で確認 |
| `test_password_can_be_reset_with_valid_token` | 有効な token で `POST /reset-password` → password 更新 + `/login` へ redirect | `POST /api/auth/reset-password` → `200 { code: "password_reset", message }`。無効/期限切れ/使用済みは `422 validation_failed` + `errors.token`（理由を区別しない） |

### [tests/Feature/Auth/PasswordUpdateTest.php](../tests/Feature/Auth/PasswordUpdateTest.php)

| test | 現在の振る舞い | 置換後の API 契約 |
| --- | --- | --- |
| `test_password_can_be_updated` | 正しい `current_password` で `PUT /password` → password 更新 | `PUT /api/profile/password` → `204`。`current_password` 検証ロジックは不変 |
| `test_correct_password_must_be_provided_to_update_password` | 誤 `current_password` で `PUT /password` → `current_password` field error | `422 validation_failed` + `errors.current_password` |

### [tests/Feature/Auth/PasswordConfirmationTest.php](../tests/Feature/Auth/PasswordConfirmationTest.php)

| test | 現在の振る舞い | 置換後の API 契約 |
| --- | --- | --- |
| `test_confirm_password_screen_can_be_rendered` | `GET /confirm-password` が 200 | 廃止。SPA E2E で代替 |
| `test_password_can_be_confirmed` | 正しい password で `POST /confirm-password` → session に `auth.password_confirmed_at` | `POST /api/auth/confirm-password` → `204`。session 書き込みロジックは不変 |
| `test_password_is_not_confirmed_with_invalid_password` | 誤 password → session error | `422 validation_failed` + `errors.password` |

### [tests/Feature/Auth/EmailVerificationTest.php](../tests/Feature/Auth/EmailVerificationTest.php)

| test | 現在の振る舞い | 置換後の API 契約 |
| --- | --- | --- |
| `test_email_verification_screen_can_be_rendered` | 未認証 user で `GET /verify-email` が 200 | 廃止。SPA `/verify-email` route + `GET /api/user` の `email_verified_at: null` で SPA が判定 |
| `test_email_can_be_verified` | 正しい signed URL で `GET /verify-email/{id}/{hash}` → `Verified` event 発火 + `email_verified_at` 更新 + dashboard へ redirect | §5.4 の Web callback を維持。成功時 `${FRONTEND_URL}/dashboard?verified=1` へ 302。0.1 の変更後は再検証必須 |
| `test_email_is_not_verified_with_invalid_hash` | 不正な hash → 更新されない | callback は §5.4 の `error=invalid-or-expired` へ redirect し、`email_verified_at` は更新しない |

### [tests/Feature/ProfileTest.php](../tests/Feature/ProfileTest.php)

| test | 現在の振る舞い | 置換後の API 契約 |
| --- | --- | --- |
| `test_profile_page_is_displayed` | `GET /profile` が 200 | 廃止。`GET /api/profile` → `200 { data: user }` に置換 |
| `test_profile_information_can_be_updated` | `PATCH /profile` で name/email 更新 + email 変更時 `email_verified_at` を `null` に戻す | `PATCH /api/profile` → `200 { data: user }`。`email_verified_at` リセットの業務ロジックは不変。auth store 側の同期は SPA の責務（§9 リスク） |
| `test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged` | email 未変更なら `email_verified_at` を保持 | 同上ロジックを維持したまま resource 化 |
| `test_user_can_delete_their_account` | 正しい password で `DELETE /profile` → logout + user 削除 + `/` へ redirect | `DELETE /api/profile` → `204` + `assertGuest()`。redirect は SPA の責務 |
| `test_correct_password_must_be_provided_to_delete_account` | 誤 password → `password` field error + user 削除されない | `422 validation_failed` + `errors.password` |

### [tests/Feature/ExampleTest.php](../tests/Feature/ExampleTest.php)

| test | 現在の振る舞い | 置換後の API 契約 |
| --- | --- | --- |
| `test_the_application_returns_a_successful_response` | `GET /` が 200（Inertia Welcome） | §7 テスト表のとおり分割。Laravel 側は `GET /up` の healthcheck、SPA 側は Nginx 経由の `GET /` smoke test に分離 |

上記に加え、Phase 2 で新規に必要になる test（Gate 3/6 相当）を明記する。

- 旧画面 GET route が Laravel に存在しないことを検証する architecture test（§10.3）
- 署名付き email 認証 callback の「未ログイン → intended 復元 → login 後再実行」の分岐
- password reset の無効/期限切れ/使用済み token を区別しない `422` 挙動
- `api.guest` による認証済み user の guest 専用 API 拒否（`409 already_authenticated`）

## 0.5 URL 契約の確定（password reset / email verification）

migration doc の §5.3（password reset）と §5.4（email verification）を、変更なしで最終契約として
確定する。現在の実装との差分と、Phase 2 で必要になる変更点は次のとおり。

### password reset（§5.3 準拠）

| 項目 | 現状 | 確定した契約 |
| --- | --- | --- |
| mail 内 URL | `NewPasswordController::create` の Blade/Inertia route（`/reset-password/{token}?email=...`、Laravel 画面） | `${FRONTEND_URL}/reset-password/{token}?email=...`。`ResetPassword::createUrlUsing()` で差し替え、Laravel の画面 route は経由させない |
| token 有効期限 | `config/auth.php` `passwords.users.expire = 60`（分） | 変更なし。Password Broker が検証する |
| resend throttle | `config/auth.php` `passwords.users.throttle = 60`（秒） | 変更なし |
| submit 先 | `POST /reset-password`（web route） | `POST /api/auth/reset-password` |
| GET 側の担当 | Laravel `NewPasswordController::create` | Nginx が SPA `index.html` を返し、Vue Router が token/email を読む。Laravel に対応する GET route を残さない |

`FRONTEND_URL` 環境変数と `ResetPassword::createUrlUsing()` の実装は Phase 2 のタスクであり、
現時点（Phase 0）では未実装（`.env` / `.env.example` に `FRONTEND_URL` 未定義）。契約としては
確定済みなので、Phase 2 着手時に迷わず実装できる状態とする。

### email verification（§5.4 準拠）

| 項目 | 現状 | 確定した契約 |
| --- | --- | --- |
| callback URL | `GET /verify-email/{id}/{hash}` + `signed`, `auth`, `throttle:6,1` | 変更なし。`web` route として維持する唯一の画面系 route |
| 成功時 | `redirect()->intended(route('dashboard', absolute: false).'?verified=1')` | `${FRONTEND_URL}/dashboard?verified=1` へ 302 に変更（Phase 2） |
| 未ログイン時 | 現状は `auth` middleware が Laravel の `login` route（Inertia 画面）へ redirect | intended URL を session に保存した上で `${FRONTEND_URL}/login?verification_required=1` へ redirect するよう変更（Phase 2） |
| 署名不正/期限切れ | 現状は Laravel 標準の 403（`InvalidSignatureException`） | `${FRONTEND_URL}/verify-email?error=invalid-or-expired` へ redirect するよう変更（Phase 2） |
| 別 user ログイン中 | 未実装（既存テストに該当ケースなし） | `${FRONTEND_URL}/verify-email?error=user-mismatch` へ redirect を新規実装（Phase 2） |
| 認証必須化 | 0.1 の変更により `verified` middleware が実効化された | callback 自体の `signed`/`auth`/throttle は変更なし。dashboard 側の `verified` 判定が実際に機能するようになったのみ |

0.1 の `MustVerifyEmail` 有効化により、この callback で `markEmailAsVerified()` した後の
`/dashboard` 到達が「本当に意味のある遷移」になる（以前は verified でなくても dashboard に
到達できていた）。既存の `EmailVerificationTest` は 2 ケースとも pass 済み（0.1 参照）。

## 0.6 Phase 0 完了条件チェック

- [x] API path、status、error schema、認証方式をレビューし、変更なしで確定（差分は 0.1〜0.3 のみ、いずれも contract 自体の追加確定であり §5 の既存記述を覆さない）
- [x] `UserResource` の公開フィールドを確定（0.3）
- [x] 既存 feature test が担保する認証・profile の振る舞いを一覧化（0.4）
- [x] password reset mail は §5.3、email verification は §5.4 の URL 契約を使用することを確認（0.5）
- [x] 契約変更に伴うコード差分（`MustVerifyEmail` 有効化）を実施し、既存 test 28 件 / 76 assertions が pass することを確認済み（`./vendor/bin/sail test`）
