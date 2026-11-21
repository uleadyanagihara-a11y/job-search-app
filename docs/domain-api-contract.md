# 業務 API の契約

`docs/domain-design.md` の業務 API を Milestone ごとに契約にしたもの（同 11 章「進め方: API 契約を先に書く」）。
実装はこの文書をレビューしてから始め、確定後に endpoint・status・code を変えるときは先にこの文書を直す。

共通の規約は繰り返さず参照だけする。

- response の形、status と `code`、日時・nullable などのデータ規約: `docs/inertia-removal-migration.md` §5.1
- 認証・認可の層分け、403/404、409/422、middleware の実行順、feature test の最低限の観点: 同 §5.5.5
- 画面は原則ログイン必須、router は default deny: 同 §6.1

表の「middleware」は route に書く順、「失敗」は §5.5.5 の最低限の観点（未ログイン 401・未確認 403 など）に加える分だけを書く。

endpoint ごとに、使えるロール（求職者・企業担当者）を契約に書く。それ以外のロールは `403 forbidden`（空 body でも `422` より前）。
ロールの組み合わせは `ApiEndpointContractTest` の `ENDPOINTS` の `roles` に写し、全ロールについて機械的に検査する（2026-11-17 決定。M3 以降の業務 API から適用）。

path parameter（`{jobPosting}` 等）のある endpoint は、resource の見える範囲も契約に書く（2026-11-19 決定）。

- `owner`: 持ち主（企業担当者なら自社、求職者なら本人）だけが使える。同じロールでも持ち主以外は、公開中の resource でも存在しない id と同じ `404 not_found`（空 body でも `422` より前）。
- `public`: 公開中の resource で、`roles` のロールなら誰でも使える（求職者が見る公開中の求人など）。公開されていないものは `404 not_found`。

`ApiEndpointContractTest` の `RESOURCE_SCOPES` に写す（path parameter のある auth route は宣言が無いと落ちる）。`owner` の endpoint は、同じロールの別の user（担当者なら別の企業の担当者）が持ち主の resource を指定すると `404` で、存在しない id と同じ body で、DB が変わらないことを横断で検査する。

横断検査では保証されないこと（2026-11-21 追記）:

- path parameter の無い endpoint（一覧など）は対象外。他人の resource が含まれないことは、endpoint ごとの feature test で件数と id の両方を確かめる。
- path parameter が 2 つ以上ある route では、全部の parameter を持ち主の resource で埋め、request を送る user だけを替える。「親は自分の resource・子は他人の resource」の組み合わせは送らないため、`scopeBindings()` の付け忘れは検出できない。横断検査にこの組み合わせを足すかどうかは M5 の開始時に決める（**未決**）。
- `roles`・`RESOURCE_SCOPES` は契約を手で写したもの。契約と同じ誤り（`owner` にすべきものを `public` と書くなど）があっても test は通る。そのため契約のレビューでは、`public` の endpoint ごとに、公開中のものだけを返すか（下書き・終了・停止中の企業の求人を除く scope を通るか）を確かめる。実装後は、実装とは別のコンテキストのレビュー（API は `php-reviewer`、SPA の型・`code` の扱い・route の `meta.roles` は `frontend-reviewer`）で契約と実装を照合する。

## M2: ロール・企業の招待・求職者プロフィール

状態: **確定（2026-11-13）、実装済み（2026-11-13）**。実装で決めた細部は M2.9。

### M2.0 設計書から M2 に持ち越した判断

| 項目（設計書） | 決定 | 理由 |
| --- | --- | --- |
| 求職者もメールアドレス変更に現在の password を求めるか（1 章） | **求める**。ロールで分けず、`PATCH /api/profile` で email を変えるときは全員 `current_password` を必須にし、古いアドレスに通知する | 乗っ取りの被害は求職者でも大きい（応募・メッセージを読まれる）。ロールで分けると form と test が 2 通りになる |
| 停止中の企業の担当者の login を拒否するときの status・code（2 章） | **`403 company_suspended`**（§5.1 に追加）。password が正しいときだけ返し、誤りなら今までどおり `422` | `403 forbidden` だと固定文言「この操作は許可されていません」になり、利用者が理由を知れない。password が誤りのときに返すと、停止中かどうかを第三者に知らせてしまう |
| ログイン中の担当者の企業が停止されたとき（2 章。2026-11-16 変更） | `auth:sanctum` の全 API（logout を除く）に middleware `api.company.active` を付け、停止中なら session を無効にして **`403 company_suspended`**（login と同じ body）。停止の command は `sessions` の行を消さない | 行の削除は `SESSION_DRIVER=database` 前提で、他の driver だと何もせず成功する。login と停止が同時だと削除の後に session が書き込まれて残る。request ごとの確認ならどちらも起きない |
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
| `POST /api/auth/register` | 作る user は `role=seeker`。request に `role` があっても無視する（validation にも入れない）。validation の `unique` を通った後に同じ email の user ができていたら（同時の登録・招待の受諾）、`users.email` の unique 制約を正として `409 conflict`（§5.5.5。2026-11-19 追加） |
| `POST /api/auth/login` | 停止中の企業の担当者は、password が正しくても login させず `403 company_suspended`。password が誤りなら停止中でも今までどおり `422`（`errors.email`）で、`LoginRequest` の失敗回数に数える。停止中で拒否した request は失敗回数に数えない |
| `PATCH /api/profile` | email を変えるときだけ `current_password` を必須にする（誤りは `422 errors.current_password`）。変えたら古いアドレスへ通知メール（本文に新しいアドレスは載せない。設計書 7 章と同じく個人情報をメールに載せない）。`role`・`company_id` を送っても変わらない。route に `throttle:auth.confirm-password` を足す（現在 password を照合する endpoint の総当たり対策。§5.5.2）。validation の `unique` を通った後に同じ email の user ができていたら（同時の登録・招待の受諾・別の user の変更）、`users.email` の unique 制約を正として `409 conflict` で、何も変えず通知も送らない（§5.5.5。2026-11-21 追加） |
| `DELETE /api/profile` | 所属企業の最後の担当者は `409 conflict`（設計書 8 章）。求職者は `seeker_profiles` も cascade で消える |
| `auth:sanctum` の全 API（`POST /api/auth/logout` を除く。M2.3 の新しい API も含む） | middleware `api.company.active` を足す。停止中の企業の担当者なら、処理をせずに logout（session の無効化と CSRF token の再生成）して `403 company_suspended`。logout は停止中でも今までどおり `204`（logout できないと SPA が状態を戻せないため） |
| `GET /auth/email/verify/{id}/{hash}`（web の callback。2026-11-19 追加） | 停止中の企業の担当者なら、email を確認済みにせず、logout して `${FRONTEND_URL}/login?company_suspended=1` へ redirect する。判定は user・hash の照合より前（throttle・signed・auth の後）。web route のため `api.company.active`（JSON を返す）は使わない |

`403 company_suspended` の body の `message` は「この企業アカウントは現在利用できません。運営にお問い合わせください。」とする。SPA の login 画面は `message` に依存せず、`code` が `company_suspended` なら下の `?company_suspended=1` と同じ固定文言の alert を出す（2026-11-21 変更。以前は `error.message` をそのまま出していた）。
login 以外の API が `403 company_suspended` を返したら、SPA は auth store を破棄して `/login?company_suspended=1` へ `replace` し、login 画面が同じ文を固定文言で出す（`message` に依存しない）。起動時の `GET /api/user` でも同じ（`fetchUser()` は `null` を返す。遷移は handler ではなく最初の遷移の router guard が auth store の印を読んで行う。2 つの遷移を競わせると `?redirect=` 付きの login が勝つため）。処理は `frontend/src/router/apiErrorHandlers.ts`。

guest 用の API の `409 already_authenticated` と、`api.verified` の API の `403 email_unverified` は、別の tab でログインした・メールアドレスを変えた等で、server の認証状態が SPA の auth store より新しいときに返る（2026-11-21 追加）。
SPA は画面ごとに `code` で分岐しない。auth store を取り直し（`GET /api/user`）、今の画面（path・query・hash）へ `replace`（`force`）で遷移し直して、遷移先は router guard に決めさせる（ログイン済みなら guest の画面から dashboard、メール未確認なら `verification.notice`）。
画面は他の失敗と同じく `message` を alert に出すため、遷移するまでの間（`GET /api/user` の 1 往復）と、取り直しに失敗して遷移しないときは「既にログインしています」等が見える（文言が状況に合っているため許容する）。
起動時の最初の遷移の途中では遷移しない（最初の遷移の guard が同じ判定をする）。取り直しの `GET /api/user` が `403 company_suspended` を返したら、遷移は `company_suspended` の処理に任せる（2 つの遷移を競わせない）。処理は `company_suspended` と同じ `frontend/src/router/apiErrorHandlers.ts`。
確認メールの再送 API の `409 conflict`（別の tab で確認済みになっていた）はこれとは別で、今までどおり画面の中で user を取り直す。

### M2.3 新しい API

| API | middleware | 成功 | 失敗 |
| --- | --- | --- | --- |
| `POST /api/company-invitations/lookup` | `throttle:auth.invitation`, `api.guest` | `200 { data: { company_name, email, expires_at } }` | token が無効・期限切れ・使用済み: `422 errors.token`（理由を区別しない）。企業が停止中・email が登録済み: `409 conflict` |
| `POST /api/company-invitations/accept` | `throttle:auth.invitation`, `api.guest`, `api.session` | `201 { data: user, meta: { redirect_to: "/dashboard" } }` | lookup と同じ。加えて `name`・`password` の `422` |
| `GET /api/seeker-profile` | `auth:sanctum`, `api.company.active`, `api.verified` | `200 { data: seeker_profile }`（行が無ければ全 field `null`） | 企業担当者: `403 forbidden` |
| `PUT /api/seeker-profile` | `auth:sanctum`, `api.company.active`, `api.verified` | `200 { data: seeker_profile }`（無ければ作る） | 企業担当者: `403 forbidden`。入力: `422` |

#### 招待の受諾

- request の body: lookup は `token`、accept は `token`, `name`, `password`, `password_confirmation`。`email` は受け取らず、招待に保存したものを使う（設計書 2 章）。
- validation は register と同じ（`name` required・max:255、`password` required・confirmed・`Password::defaults()`）。
- accept の成功時: 1 つの transaction で `role=company` の user（`email_verified_at` は受諾時刻）と `company_members` を作り、招待の `accepted_at` を入れる。その後 login して session を作り直す（`regenerate()`）。
- token の判定は `token_hash` の一致、`accepted_at` が null、`expires_at` が未来の 3 つ。同じ email に招待し直したら、同じ企業の未使用の招待を無効にする（`expires_at` を現在時刻にする）。
- 同時に 2 回 accept されても user が 2 人できないよう、`users.email` と `company_members.user_id` の unique 制約を正とし、`UniqueConstraintViolationException` は `409 conflict` にする（§5.5.5）。
- accept は transaction の中で招待の行を `lockForUpdate` し、token の判定（`accepted_at` が null、`expires_at` が未来）をもう一度行う。最初の判定の後に、同じ token の受諾・招待の送り直し・期限切れが起きていたら `422 errors.token`（2026-11-19 追加。送り直しで無効になった token が受諾されないように）。
- `throttle:auth.invitation` は IP 単位 6 回/分で、lookup と accept の合計で数える（画面を開くと lookup、送信で accept を呼ぶ）。
- 招待メールの URL は `${FRONTEND_URL}/invitations/{token}`。reset と違い email は URL に入れない（lookup が返すため、log に残る個人情報を減らす）。

#### 求職者プロフィール

`seeker_profile` の field:

| field | 型 | validation（PUT） |
| --- | --- | --- |
| `desired_prefecture` | integer \| null | nullable・`integer:strict`（JSON の数値だけ。`"13"`・`true`・小数は `422`。2026-11-21 追加）・`Prefecture` enum（1〜47） |
| `desired_job_type` | string \| null | nullable・string・max:100 |
| `self_pr` | string \| null | nullable・string・max:2000 |
| `updated_at` | string \| null | 返すだけ（行が無ければ `null`） |

- PUT は全 field を送る置き換え。送らなかった field は `null` にする（部分更新の PATCH にしない。form は常に全欄を送るため）。
- 応募に必要な必須項目は M5 で決める（設計書 4 章）。M2 では全項目任意。
- 企業担当者が応募者のプロフィールを見る API は M5（応募と一緒に作る）。
- `lang/ja/validation.php` の `attributes` に `desired_prefecture`（希望勤務地）・`desired_job_type`（希望職種）・`self_pr`（自己 PR）を足す（`.claude/rules/laravel.md` の言語の規約）。`token` は lang では既に「再設定用のトークン」（password 再設定用）のため、招待では `InvitationLookupRequest::attributes()` で表示名を「招待のリンク」に上書きする（accept の FormRequest はこれを継承する。2026-11-21 に実装に合わせて修正）。

### M2.4 認可と middleware の実行順

- `GET/PUT /api/seeker-profile` が最初の `api.verified` 付きの業務 API になるため、§5.5.5「middleware の実行順」の `prependToPriorityList`（`EnsureApiEmailIsVerified` を `SubstituteBindings` の前へ）をここで入れる。順序は `EnsureApiSessionTest` と同じ方法で test する。
- `api.company.active`（`EnsureCompanyIsActive`）は priority で `EnsureApiEmailIsVerified` の直前に入れる（認証の後、メール確認・route binding の前）。停止中の担当者に、メール未確認や resource の有無を返さないため。停止の確認は「アカウントの状態」の確認で、ロールの判定ではないため Policy にしない（Policy を通らない `/api/user`・`/api/profile` も守るため）。
- 最初の Policy は `SeekerProfilePolicy`。ability `view` / `update` は「user が求職者か」だけを見る（自分の行しか扱わない API で、ID を受け取らないため 404 の判定は無い）。呼び出しは controller の `Gate::authorize('update', SeekerProfile::class)` に 1 本化する。
- 招待の lookup・accept は guest 用のため Policy を持たない。停止・登録済みの判定は業務ルール（`409`）として受諾の処理に書く。

### M2.5 artisan command

| command | 動作 |
| --- | --- |
| `company:create {name} {email}` | 企業と招待を作り、招待メールを送る。`email` が登録済みならエラーで終了（何も作らない） |
| `company:invite {company} {email}` | 既存の企業へ招待を送り直す（同じ email の未使用の招待は無効にする）。期限切れの再送もこれで行う |
| `company:suspend {company}` / `company:resume {company}` | `suspended_at` を入れる・消す。suspend は同じ transaction で担当者の `remember_token` を作り直す。`sessions` の行は消さない（ログイン中の担当者は `api.company.active` が締め出す。設計書 2 章「企業の停止」） |

`{company}` は企業の id。どれも成功時に作った・変えた企業の id と名前を出力する。

### M2.6 SPA の route（参考）

API ではないが、契約の確認に必要なものだけ書く。

| path | name | `meta` | 画面 |
| --- | --- | --- | --- |
| `/invitations/:token` | `invitation.accept` | `access: 'guest'` | 開いたら lookup。会社名と email（変更不可）を表示し、名前と password を入力して accept |
| `/seeker-profile` | `seeker-profile` | `roles: ['seeker']` | 求職者プロフィールの編集（nav にも出すのは求職者だけ） |

- `meta.roles` を持つ route にロールが合わない user が来たら dashboard へ `replace` する（表示の振り分けだけ。データは API の Policy が守る。§6.1）。判定は `guards.ts` の `authGuard` で、未ログイン・メール未確認の判定の後に行い、ロールが合わないときの redirect に `replace: true` を付ける（アプリ内の `push` から来ても履歴に残さない。2026-11-21 に実装に合わせて修正。以前は「`routeAccess()` に足す」と書いていた）。nav の項目の `roles` は `frontend/src/layouts/navigation.ts` に route の `meta.roles` と同じ値を書き、一致は `tests/router/navigation.test.ts` で確かめる。
- 招待受諾画面は §6.1 の「公開する画面」に足す。ログイン中に開いたら guest 用の guard で dashboard へ送られる（API も `409 already_authenticated`）。

### M2.7 feature test の観点

§5.5.5「テスト」の表（401・403 email_unverified・ロール違い 403 など）に加えて確認すること。

| 対象 | 確認すること |
| --- | --- |
| `UserResource` | 求職者は `role: "seeker"`, `company_id: null`、担当者は `role: "company"` と所属企業の id |
| register | `role` を送っても `seeker` になる。validation の後に同じ email の user ができていたら `409` で、ログインしない |
| `PATCH /api/profile` | `role`・`company_id` を送っても変わらない。email を変えるとき `current_password` が無い・誤りなら `422` で何も変わらない。変えたら古いアドレスに通知が送られる。email を変えないなら `current_password` は不要。validation の後に同じ email の user ができていたら `409` で、email は変わらず通知も送られない |
| login | 停止中の担当者は正しい password で `403 company_suspended`・ログインしていない。誤った password では `422`（停止中かどうか分からない） |
| `company:suspend` | 担当者の session cookie で `GET /api/user` が `403 company_suspended`、その後は同じ cookie で `401`（logout 済み）。remember cookie でも再認証されない（`remember_token` が変わる）。`sessions` の行は消さない（driver に依存しない） |
| `api.company.active` | `auth:sanctum` の route は logout 以外すべてに付いている。停止中の担当者はどの API でも `403 company_suspended`（空 body でも `422` より前）で、logout される。メール未確認でも `403 company_suspended`。logout は停止中でも `204`。求職者・停止されていない担当者は変わらない |
| メール確認 callback | 停止中の企業の担当者は、自分の有効なリンクでも確認済みにならず、`/login?company_suspended=1` へ redirect されて logout される。別の user のリンクでも同じ（`user-mismatch` より前）。停止されていない担当者は今までどおり確認できる |
| 招待 lookup・accept | 有効な token で会社名と email が返る。無効・期限切れ・使用済み・送り直しで無効になった token は同じ `422`。停止中の企業・登録済みの email は `409`。accept で user（`role=company`、`email_verified_at` あり）と `company_members` ができ、ログイン状態で session id が変わる。body の `email` は無視される。同じ token で 2 回目は `422`。最初の判定の後に別の request が commit した場合（同じ token の受諾・送り直しなら `422`、同じ email の user の作成なら `409`）も、user・`company_members` は増えず招待は未使用のまま |
| `DELETE /api/profile` | 最後の担当者は `409` で削除されない。求職者は `seeker_profiles` も消える |
| seeker-profile | 担当者は GET・PUT とも `403`。行が無い求職者の GET は全 field `null`。PUT は送らなかった field を `null` にする。`self_pr` 2001 文字は `422` |
| 実行順 | seeker-profile の route で `EnsureApiEmailIsVerified` が `SubstituteBindings` より前に動く |

### M2.8 レビューで決めたその他のこと

- 企業担当者の dashboard: M2 では求職者と同じ空の dashboard を出す。ロール別の中身は M5.5 で作る（設計書 11 章）。
- `company:create` は企業名と email だけを受ける。説明・Web サイト・所在地は M3 の企業情報の編集画面で担当者が入れる。

### M2.9 実装で決めた細部

契約に書いていなかったもので、実装時に決めたもの。

- `403 company_suspended` は `App\Exceptions\CompanySuspendedException`（`render()` を持ち、log に残さない `ShouldntReport`）で返す。login の処理（FormRequest）と `api.company.active` の両方から同じ body を返すため、middleware も `ApiErrorResponse` を直接作らずこの例外を投げる。判定は `Auth::attemptWhen()` の callback で行い、password が一致したときだけ停止を見る。
- `PUT /api/seeker-profile` は FormRequest を引数で受けず、`Gate::authorize()` の後に解決する。引数で受けると validation が認可より先に動き、企業担当者に `403` ではなく `422` を返すため。行を作ったときも `201` ではなく `200`。
- `PATCH /api/profile` は email が空のときは `current_password` を求めない（`email` の required だけを返す）。email を変えないときに送られた `current_password` は照合しない。SPA の「現在のパスワード」欄は email を変えたときだけ出し、`required` 属性は付けない（server の `422` で日本語の message を出すため）。
- `DELETE /api/profile` の最後の担当者の判定は、同じ企業の `company_members` の行を lock して数える（2 人が同時に削除して 0 人にならないように）。
- `company:create`・`company:invite` は email を小文字にそろえる。`company:invite` は停止中の企業には送らずエラーで終了する（受諾できない招待を送らないため）。`company:create` は招待メールを送れなかったら企業も作らない。`company:invite` も招待の発行（古い招待の無効化を含む）とメールの送信を 1 つの transaction で行い、送れなかったら古い招待を有効なまま残す（2026-11-21 追加）。
- 招待の受諾は、企業が停止中かどうかを transaction の外（最初の判定）でだけ見て、lock の中では確かめ直さない（2026-11-21 決定）。受諾と `company:suspend` が同時に起きると、停止中の企業の担当者ができて `201` が返り得るが、次の API で `api.company.active` が `403 company_suspended` にして logout させ、login も `403` になる（accept は remember cookie を発行しないため、再認証の経路も無い）。設計書 2 章「企業の停止」の、応募と停止の競合を lock しない決定と同じ考え方で、企業の行を lock して `company:suspend` と順序を決める処理は入れない。
- 開発用の user は `DatabaseSeeder`（`seeker@example.com`・`company@example.com`、password は `password`）、E2E 用は `E2eSeeder`（`laravel.e2e` の起動時。求職者・企業担当者・停止中の企業の担当者と、`e2e-invited@example.com` への招待メール）。E2E の user の email は `frontend/e2e/helpers.ts` の `E2E_USERS` と揃える。
- SPA の共通部品として `PageHeading`・`ContentCard`・`AlertMessage`・`MutedText`・`SavedStatus`・`SelectInput`・`TextareaInput` を `frontend/src/components/` に足した。

## M3: 企業の求人管理と企業情報の編集

状態: **下書き**（2026-11-19 に認可の方針だけ先に書いた。endpoint・field・validation は M3 の開始時に書き、レビューで確定する）。

### M3.x 他社の求人への操作の拒否（2026-11-19 決定）

`PATCH /api/company/job-postings/123` のように、他社の担当者が自分の cookie で他社の求人の id を指定した request を、API が拒否する。SPA で編集ボタンや route を隠すのは表示のためだけで、拒否にはならない。

- route: 企業側の求人の管理は `/api/company/job-postings/...`、求職者が見る公開中の求人は `/api/job-postings/...` に分ける（同じ path をロールで分岐させない）。path の名前は M3 で確定する。
- 企業側の求人の endpoint（一覧以外）は全部 `RESOURCE_SCOPES` が `owner`、`roles` は `['company']`。求職者は `403 forbidden`、他社の担当者は求人が公開中でも `404 not_found`。
- Policy（`JobPostingPolicy`）: 企業側の ability（`view`・`update`・公開・終了・削除など）は、private method `belongsToCompanyOf(User, JobPosting)` で「担当者の所属企業の id と求人の `company_id` が一致するか」を判定し、違えば `Response::denyAsNotFound()`。ability ごとに条件を書かない。呼び出しは controller の `Gate::authorize()` の 1 か所で、FormRequest はその後に `app()` で解決する（validation より前に 404 を返すため。M2.9）。
- 一覧: Policy の `viewAny` で「企業担当者か」を見て、取得は担当者の所属企業で `whereBelongsTo()` して絞る。詳細・更新も一覧と同じ範囲になる。
- `company_id` は `#[Fillable]` にも FormRequest の rules にも入れない。作成時に担当者の所属企業から `forceFill` で入れ、更新では変えない（自社の求人を他社へ移せない）。
- 求人の下にぶら下がる resource（M5 の応募など）の route は `scopeBindings()` にし、自社の求人の id と他社の応募の id を組み合わせても読めないようにする（この組み合わせは横断検査では送らない。冒頭の「横断検査では保証されないこと」）。

feature test の観点（§5.5.5 の表に加えて）:

| 対象 | 確認すること |
| --- | --- |
| 企業側の求人の各 endpoint | 他社の担当者は、求人が下書き・公開中・終了のどれでも `404`、存在しない id と同じ body、DB が変わらない。空 body でも `422` より前に `404` |
| 作成・編集 | body の `company_id`・`status`・`published_at` は無視される（自社の求人を他社に移せない） |
| 一覧 | 他社の求人を用意しても、response に自社の求人だけが含まれる（件数と id の両方）。path parameter が無く `RESOURCE_SCOPES` の横断検査の対象外のため、他社の求人の漏れを見つけるのはこの test だけ |
