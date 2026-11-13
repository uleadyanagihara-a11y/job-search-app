# 業務 API の契約

`docs/domain-design.md` の業務 API を Milestone ごとに契約にしたもの（同 11 章「進め方: API 契約を先に書く」）。
実装はこの文書をレビューしてから始め、確定後に endpoint・status・code を変えるときは先にこの文書を直す。

共通の規約は繰り返さず参照だけする。

- response の形、status と `code`、日時・nullable などのデータ規約: `docs/inertia-removal-migration.md` §5.1
- 認証・認可の層分け、403/404、409/422、middleware の実行順、feature test の最低限の観点: 同 §5.5.5
- 画面は原則ログイン必須、router は default deny: 同 §6.1

表の「middleware」は route に書く順、「失敗」は §5.5.5 の最低限の観点（未ログイン 401・未確認 403 など）に加える分だけを書く。

## M2: ロール・企業の招待・求職者プロフィール

状態: **確定（2026-11-13）**。

### M2.0 設計書から M2 に持ち越した判断

| 項目（設計書） | 決定 | 理由 |
| --- | --- | --- |
| 求職者もメールアドレス変更に現在の password を求めるか（1 章） | **求める**。ロールで分けず、`PATCH /api/profile` で email を変えるときは全員 `current_password` を必須にし、古いアドレスに通知する | 乗っ取りの被害は求職者でも大きい（応募・メッセージを読まれる）。ロールで分けると form と test が 2 通りになる |
| 停止中の企業の担当者の login を拒否するときの status・code（2 章） | **`403 company_suspended`**（§5.1 に追加）。password が正しいときだけ返し、誤りなら今までどおり `422` | `403 forbidden` だと固定文言「この操作は許可されていません」になり、利用者が理由を知れない。password が誤りのときに返すと、停止中かどうかを第三者に知らせてしまう |
| 招待の有効期限（2 章） | **7 日**（`config/auth.php` に `invitations.expire` として置く） | 運営が送ってから担当者が開くまでに日が空くことがある。token は 1 回限り・hash 保存で、期限を短くする利点が小さい |
| 登録済みの email を招待した場合（2 章） | artisan はエラーで終了し、受諾時は `409 conflict`（設計書どおり。招待後に同じ email で求職者登録された場合など） | 1 アカウント 1 ロールのため、既存 user を企業担当者に変えない |
| `/api/user` に足す field の形（1 章） | `role` と `company_id` を常に返す（求職者は `company_id: null`） | §5.1 の「field の有無を変えない」 |
| 希望勤務地の形（3 章） | 都道府県 1 つ（JIS X 0401 の番号 `1`〜`47` の integer、未入力は `null`） | 複数選択は検索（M4）の条件と一緒に考える。番号は JIS で固定のため、SPA は同じ 47 件の一覧を定数で持つ（enum の一覧 API は作らない） |

### M2.1 データ

列の型・index は M2 で確定する（設計書 3 章）。M2 で作るのは次のテーブルだけ。

| テーブル | 列 | 備考 |
| --- | --- | --- |
| `users` | `role` varchar(20) not null | PHP の backed enum `Role`（`seeker` / `company`）。既存 user は migration で `seeker`。`#[Fillable]` に入れない |
| `companies` | `id`, `name` varchar(100), `description` text null, `website_url` varchar(255) null, `prefecture` tinyint null, `suspended_at` datetime null, timestamps | 説明・Web サイト・所在地の編集は M3。M2 は artisan で `name` だけ入れる |
| `company_members` | `id`, `company_id`（FK, cascade）, `user_id`（FK, cascade, **unique**）, timestamps | |
| `company_invitations` | `id`, `company_id`（FK, cascade）, `email` varchar(255), `token_hash` char(64) **unique**, `expires_at`, `accepted_at` null, timestamps | token は `Str::random(64)`、保存は SHA-256。password と違い token 自体が十分長いため、検索できる sha256 にする（bcrypt だと hash で探せない） |
| `seeker_profiles` | `user_id`（PK, FK, cascade）, `desired_prefecture` tinyint null, `desired_job_type` varchar(100) null, `self_pr` text null, timestamps | 行は最初の保存で作る |

### M2.2 変更する既存 API

| API | 変更 |
| --- | --- |
| `GET /api/user`、`GET/PATCH /api/profile`、login・register の `data` | `UserResource` に `role`（`"seeker"` / `"company"`）と `company_id`（integer \| null）を足す。既存 field は変えない |
| `POST /api/auth/register` | 作る user は `role=seeker`。request に `role` があっても無視する（validation にも入れない） |
| `POST /api/auth/login` | 停止中の企業の担当者は、password が正しくても login させず `403 company_suspended`。password が誤りなら停止中でも今までどおり `422`（`errors.email`）で、`LoginRequest` の失敗回数に数える。停止中で拒否した request は失敗回数に数えない |
| `PATCH /api/profile` | email を変えるときだけ `current_password` を必須にする（誤りは `422 errors.current_password`）。変えたら古いアドレスへ通知メール（本文に新しいアドレスは載せない。設計書 7 章と同じく個人情報をメールに載せない）。`role`・`company_id` を送っても変わらない。route に `throttle:auth.confirm-password` を足す（現在 password を照合する endpoint の総当たり対策。§5.5.2） |
| `DELETE /api/profile` | 所属企業の最後の担当者は `409 conflict`（設計書 8 章）。求職者は `seeker_profiles` も cascade で消える |

`403 company_suspended` の body の `message` は「この企業アカウントは現在利用できません。運営にお問い合わせください。」とし、SPA の login 画面は既存の `error.message` の表示（alert）でそのまま出す。

### M2.3 新しい API

| API | middleware | 成功 | 失敗 |
| --- | --- | --- | --- |
| `POST /api/company-invitations/lookup` | `throttle:auth.invitation`, `api.guest` | `200 { data: { company_name, email, expires_at } }` | token が無効・期限切れ・使用済み: `422 errors.token`（理由を区別しない）。企業が停止中・email が登録済み: `409 conflict` |
| `POST /api/company-invitations/accept` | `throttle:auth.invitation`, `api.guest`, `api.session` | `201 { data: user, meta: { redirect_to: "/dashboard" } }` | lookup と同じ。加えて `name`・`password` の `422` |
| `GET /api/seeker-profile` | `auth:sanctum`, `api.verified` | `200 { data: seeker_profile }`（行が無ければ全 field `null`） | 企業担当者: `403 forbidden` |
| `PUT /api/seeker-profile` | `auth:sanctum`, `api.verified` | `200 { data: seeker_profile }`（無ければ作る） | 企業担当者: `403 forbidden`。入力: `422` |

#### 招待の受諾

- request の body: lookup は `token`、accept は `token`, `name`, `password`, `password_confirmation`。`email` は受け取らず、招待に保存したものを使う（設計書 2 章）。
- validation は register と同じ（`name` required・max:255、`password` required・confirmed・`Password::defaults()`）。
- accept の成功時: 1 つの transaction で `role=company` の user（`email_verified_at` は受諾時刻）と `company_members` を作り、招待の `accepted_at` を入れる。その後 login して session を作り直す（`regenerate()`）。
- token の判定は `token_hash` の一致、`accepted_at` が null、`expires_at` が未来の 3 つ。同じ email に招待し直したら、同じ企業の未使用の招待を無効にする（`expires_at` を現在時刻にする）。
- 同時に 2 回 accept されても user が 2 人できないよう、`users.email` と `company_members.user_id` の unique 制約を正とし、`UniqueConstraintViolationException` は `409 conflict` にする（§5.5.5）。
- `throttle:auth.invitation` は IP 単位 6 回/分で、lookup と accept の合計で数える（画面を開くと lookup、送信で accept を呼ぶ）。
- 招待メールの URL は `${FRONTEND_URL}/invitations/{token}`。reset と違い email は URL に入れない（lookup が返すため、log に残る個人情報を減らす）。

#### 求職者プロフィール

`seeker_profile` の field:

| field | 型 | validation（PUT） |
| --- | --- | --- |
| `desired_prefecture` | integer \| null | nullable・`Prefecture` enum（1〜47） |
| `desired_job_type` | string \| null | nullable・string・max:100 |
| `self_pr` | string \| null | nullable・string・max:2000 |
| `updated_at` | string \| null | 返すだけ（行が無ければ `null`） |

- PUT は全 field を送る置き換え。送らなかった field は `null` にする（部分更新の PATCH にしない。form は常に全欄を送るため）。
- 応募に必要な必須項目は M5 で決める（設計書 4 章）。M2 では全項目任意。
- 企業担当者が応募者のプロフィールを見る API は M5（応募と一緒に作る）。
- `lang/ja/validation.php` の `attributes` に `desired_prefecture`（希望勤務地）・`desired_job_type`（希望職種）・`self_pr`（自己 PR）・`token`（招待のリンク）を足す（CLAUDE.md の言語の規約）。`token` は既に「再設定用のトークン」のため、招待では `errors.token` の文を FormRequest の `messages()` で上書きする。

### M2.4 認可と middleware の実行順

- `GET/PUT /api/seeker-profile` が最初の `api.verified` 付きの業務 API になるため、§5.5.5「middleware の実行順」の `prependToPriorityList`（`EnsureApiEmailIsVerified` を `SubstituteBindings` の前へ）をここで入れる。順序は `EnsureApiSessionTest` と同じ方法で test する。
- 最初の Policy は `SeekerProfilePolicy`。ability `view` / `update` は「user が求職者か」だけを見る（自分の行しか扱わない API で、ID を受け取らないため 404 の判定は無い）。呼び出しは controller の `Gate::authorize('update', SeekerProfile::class)` に 1 本化する。
- 招待の lookup・accept は guest 用のため Policy を持たない。停止・登録済みの判定は業務ルール（`409`）として受諾の処理に書く。

### M2.5 artisan command

| command | 動作 |
| --- | --- |
| `company:create {name} {email}` | 企業と招待を作り、招待メールを送る。`email` が登録済みならエラーで終了（何も作らない） |
| `company:invite {company} {email}` | 既存の企業へ招待を送り直す（同じ email の未使用の招待は無効にする）。期限切れの再送もこれで行う |
| `company:suspend {company}` / `company:resume {company}` | `suspended_at` を入れる・消す。suspend は同じ transaction で担当者の `sessions` の行を消し、`remember_token` を作り直す（設計書 2 章「企業の停止」） |

`{company}` は企業の id。どれも成功時に作った・変えた企業の id と名前を出力する。

### M2.6 SPA の route（参考）

API ではないが、契約の確認に必要なものだけ書く。

| path | name | `meta` | 画面 |
| --- | --- | --- | --- |
| `/invitations/:token` | `invitation.accept` | `access: 'guest'` | 開いたら lookup。会社名と email（変更不可）を表示し、名前と password を入力して accept |
| `/seeker-profile` | `seeker-profile` | `roles: ['seeker']` | 求職者プロフィールの編集（nav にも出すのは求職者だけ） |

- `meta.roles` を持つ route にロールが合わない user が来たら dashboard へ `replace` する（表示の振り分けだけ。データは API の Policy が守る。§6.1）。判定は `guards.ts` の `routeAccess()` に足す。
- 招待受諾画面は §6.1 の「公開する画面」に足す。ログイン中に開いたら guest 用の guard で dashboard へ送られる（API も `409 already_authenticated`）。

### M2.7 feature test の観点

§5.5.5「テスト」の表（401・403 email_unverified・ロール違い 403 など）に加えて確認すること。

| 対象 | 確認すること |
| --- | --- |
| `UserResource` | 求職者は `role: "seeker"`, `company_id: null`、担当者は `role: "company"` と所属企業の id |
| register | `role` を送っても `seeker` になる |
| `PATCH /api/profile` | `role`・`company_id` を送っても変わらない。email を変えるとき `current_password` が無い・誤りなら `422` で何も変わらない。変えたら古いアドレスに通知が送られる。email を変えないなら `current_password` は不要 |
| login | 停止中の担当者は正しい password で `403 company_suspended`・ログインしていない。誤った password では `422`（停止中かどうか分からない） |
| `company:suspend` | 担当者の session の行が消え、その cookie で `GET /api/user` が `401`。remember cookie でも再認証されない（`remember_token` が変わる） |
| 招待 lookup・accept | 有効な token で会社名と email が返る。無効・期限切れ・使用済み・送り直しで無効になった token は同じ `422`。停止中の企業・登録済みの email は `409`。accept で user（`role=company`、`email_verified_at` あり）と `company_members` ができ、ログイン状態で session id が変わる。body の `email` は無視される。同じ token で 2 回目は `422` |
| `DELETE /api/profile` | 最後の担当者は `409` で削除されない。求職者は `seeker_profiles` も消える |
| seeker-profile | 担当者は GET・PUT とも `403`。行が無い求職者の GET は全 field `null`。PUT は送らなかった field を `null` にする。`self_pr` 2001 文字は `422` |
| 実行順 | seeker-profile の route で `EnsureApiEmailIsVerified` が `SubstituteBindings` より前に動く |

### M2.8 レビューで決めたその他のこと

- 企業担当者の dashboard: M2 では求職者と同じ空の dashboard を出す。ロール別の中身は M5.5 で作る（設計書 11 章）。
- `company:create` は企業名と email だけを受ける。説明・Web サイト・所在地は M3 の企業情報の編集画面で担当者が入れる。
