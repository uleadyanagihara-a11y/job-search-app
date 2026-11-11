# 求人・応募ドメインの設計（M1）

求人・応募まわりの業務ドメインの MVP 設計。2026-11-07 に決定した。
M2 以降の実装はこの文書を基準にし、方針を変えるときはこの文書を先に直す。

- 認証・認可の層分け、403/404、409/422 の使い分け、middleware の実行順、feature test の観点は
  `docs/inertia-removal-migration.md` §5.5.5 に従う。本書はそれを各 resource に当てはめたもの。
- 画面は原則ログイン必須（同 §6.1）を維持する。求人一覧も未ログインには見せない。
- 本番公開（production 構成とリリース）は後回しにする（「11. スケジュール」）。

「決定」と書いたものは M1 で合意済み。「実装時に確定」と書いたものは、方針だけ決めて値や細部を実装する Milestone で決める。

## 1. ロール

**決定**: 1 アカウント 1 ロール。`users.role` に `seeker`（求職者）/ `company`（企業担当者）を持つ。

- ロールは作成時に決まり、変更しない。1 人が求職者と企業担当者を兼ねる場合は別アカウントにする。
- 求職者は既存の `POST /api/auth/register` で登録し、`role=seeker` になる。登録 API にロールの入力は持たせない（企業担当者は招待でしか作れない）。
- 企業担当者は `company_members` で `companies` に属する。`company_members.user_id` は unique（1 人は 1 社だけに属する）。
- 既存 user は migration で `seeker` にする。
- ロールの判定は Policy に書き、専用 middleware にしない（§5.5.5）。
- `role` と所属企業は利用者が変えられない（**決定**）。`User` の `#[Fillable]` にも、profile 更新などの FormRequest の validation にも入れない（`ProfileController` は `fill($request->validated())` のため、FormRequest に入れると書き換えられる）。`PATCH /api/profile` に `role` を送っても変わらないことを feature test で確認する。
- 企業担当者のメールアドレス変更には現在の password を必須にし、変更したら古いアドレスに通知メールを送る（**決定**。session を奪われたときにアカウントごと乗っ取られないようにするため。求職者にも同じ扱いにするかは M2 の API 契約で決める）。
- SPA がロールで画面を振り分けられるよう、M2 で `/api/user`（と `/api/profile`）の response に `role` と、企業担当者なら所属企業の id を足す。router guard の振り分けは表示のためだけで、データの保護は API の Policy が担う（§6.1）。

## 2. 企業アカウントの作成

**決定**: 運営が artisan command で企業と最初の担当者を作り、招待メールを送る（案 a）。企業が自分で登録する画面、申請フォーム、管理画面は作らない。

- 理由: 偽の企業による求人詐欺を防ぎ、応募者の個人情報を確認済みの企業にだけ渡すため。招待の仕組みは、後から担当者の追加（企業内での招待）や申請・承認制へ広げるときにも使う。
- 流れ:
  1. 運営が `company:create`（名前は実装時に確定）で `companies` と招待を作り、招待メールを送る。
  2. 担当者はメールの URL から SPA の招待受諾画面を開き、名前と password を設定する。
  3. 受諾すると `role=company` の user と `company_members` を作り、ログイン状態にする。メールの到達で本人確認できているので、`email_verified_at` は受諾した時刻にする。
- 招待（`company_invitations`）: token は hash にして保存し、期限付き・1 回だけ使える。同じ email に招待し直したら古い token は無効にする。受諾 API は guest 用で throttle を付ける。
- token を API の path に入れない（**決定**）。招待受諾画面は URL の token を読み、検証・受諾の API へは POST の body で送る（access log に残る箇所を、画面を開いたときの 1 回に減らすため）。外部への Referer による漏れは、nginx の `Referrer-Policy: same-origin` で防ぐ（M0 で入れる。既存のパスワード再設定 URL にも効く）。
- 受諾時の email は招待に保存したものだけを使い、request では受け取らない。受諾してログインさせるときは session を作り直す（session fixation 対策）。停止中の企業への招待は受諾できない。
  - 実装時に確定: 有効期限、既に登録済みの email を招待した場合の扱い（artisan の時点でエラーにし、受諾時にも 409）。
- 招待受諾画面は未ログインで開く guest route（`meta.access: 'guest'`）にする。§6.1 で公開する画面にこれを追加する。
- 開発用・E2E 用の seeder は固定の password の user を作るため、`local`・`testing` 以外の環境で実行したら例外にする（本番で誤って実行しても user ができないようにする）。
- 開発・E2E: artisan は Playwright から呼べないため、`laravel.e2e` の `migrate:fresh` に E2E 用 seeder（企業と担当者）を付ける。招待の受諾は Mailpit からメールの URL を取って E2E で確認する。
- E2E 用 seeder は、テストで使う求職者・企業担当者もまとめて作る。各テストが register API で user を作ると rate limit の待ち（最大約 1 分）が spec の数だけ積み上がるため、register 自体の確認は 1 つの spec に絞る。
- 企業が自分で登録する方式は、偽の企業への審査・停止・通報の仕組みが最初から必要になるため採らない。

### 企業の停止

**決定**: 確認済みの企業でも後から問題が起きた場合に備え、運営が artisan command で企業を停止・再開できるようにする（`companies.suspended_at`）。

- 停止中の企業の求人は検索・詳細に出さず、新しい応募を受け付けない。
- 停止中の企業の担当者はログインできない。既存の session は、停止の artisan command がその企業の担当者の `sessions` の行を削除して切る（`SESSION_DRIVER=database` 前提。driver を変えるときはここも見直す）。request ごとに停止状態を確認する middleware は作らない。
- 同じ command で担当者の `remember_token` も作り直す（**必須**）。「ログイン状態を保持する」の cookie を持つ user は、session を消されても cookie から login API を通らずに再認証されるため、これを無効にしないと停止中の担当者が求職者の個人情報を見続けられる（`NewPasswordController` が password 再設定時に行っているのと同じ方法）。停止した担当者の session と remember cookie が無効になることを feature test で確認する。login を拒否するときの status・code は M2 の API 契約で決める。
- 既存の応募とメッセージは消さない。求職者は自分の応募を引き続き読める。

## 3. エンティティ

```text
users ─┬─ seeker_profiles (1:1)
       └─ company_members (n:1) ── companies
companies ─┬─ company_invitations (1:n)
           └─ job_postings (1:n) ── applications (1:n) ─┬─ application_status_changes (1:n)
                                        │               ├─ application_messages (1:n)
                                        users(seeker)   └─ application_message_reads (1:n)
```

| テーブル | 主な列 | 備考 |
| --- | --- | --- |
| `users` | 既存 + `role` | |
| `seeker_profiles` | `user_id`（PK）, 希望勤務地, 希望職種（自由入力）, 自己 PR | 履歴書ファイルは持たない。集めない情報は下記 |
| `companies` | 名前, 説明, Web サイト, 所在地（都道府県）, `suspended_at` | |
| `company_members` | `company_id`, `user_id`（unique） | 企業内の役割（owner 等）は MVP では持たない |
| `company_invitations` | `company_id`, `email`, token の hash, `expires_at`, `accepted_at` | |
| `job_postings` | `company_id`, タイトル, 本文, 雇用形態, 勤務地（都道府県）, 給与の単位（`salary_type`）, 給与の下限・上限, `status`, `published_at`, `deadline_at` | |
| `applications` | `job_posting_id`, `user_id`, `status`, 応募時のメッセージ, `applied_at` | unique(`job_posting_id`, `user_id`) |
| `application_status_changes` | `application_id`, 変更前後の status, 変更した user, `created_at` | 記録だけで、画面は MVP 後 |
| `application_messages` / `application_message_reads` | 「6. メッセージ」 | |

- 都道府県、雇用形態、給与の単位（月給・年収・時給）は PHP の enum にし、マスタテーブルにしない。
- **集めない情報（決定）**: 厚生労働省の「公正な採用選考」や職業安定法の指針で収集が不適切とされる情報（本籍・出生地、家族構成、思想・信条・宗教など）の項目は作らない。年齢・性別による募集の制限は原則禁止されているため、生年月日・性別も項目にせず、求人の条件や検索条件にもしない。項目を足すときはこの方針に照らして判断する。
- 職種マスタは持たない（キーワード検索で代用する）。
- 列の型・長さ・index は実装する Milestone で確定する。
- **命名（決定）**: 求人の model は `JobPosting`（テーブルは `job_postings`）にし、`Job` とは呼ばない。Laravel の queue が `jobs` テーブルと `App\Jobs` 名前空間を使うため。
- **本文の書式（決定）**: 求人の本文、企業の説明、自己 PR、応募時のメッセージ、メッセージは**プレーンテキスト**にし、表示時は改行だけを反映する（CSS の `white-space: pre-wrap` 等）。`v-html` は使わない。Markdown 等の書式を入れる場合は、sanitize の方針を先に決める。

## 4. 状態遷移

### 求人（`job_postings.status`）

```text
draft ──▶ published ──▶ closed
              ▲            │
              └────────────┘ (再公開)
```

- 締切（`deadline_at`）を過ぎた求人は、バッチで `closed` にせず、query の条件で受付終了として扱う。
- 許可しない遷移は `409 conflict`。
- `company_id` は request で受け取らず、操作する担当者の所属企業から決める。status の変更（公開・終了・再公開）は専用の endpoint にし、作成・編集の request では受け取らない。`published_at` はサーバー側で入れる。
- 応募があった後も求人は編集できる（**決定**）。応募者の画面には最新の求人を表示し、応募時点の求人の保存や編集履歴は MVP 後にする。

### 応募（`applications.status`）

```text
applied ──▶ screening ──▶ interview ──▶ offered
   │            │             │            │
   └────────────┴─────────────┴────────────┴──▶ rejected（企業） / withdrawn（求職者）
```

- 終了状態は `rejected` / `withdrawn`。内定の承諾・辞退は MVP 後。
- 誰がどの遷移をできるかは分ける（**決定**）。辞退（`withdrawn` にする）は求職者だけ、それ以外の status の変更は応募先企業の担当者だけができる。endpoint と Policy の ability を分け（例: 辞退と選考ステータスの変更）、求職者が自分の応募を `offered` 等にできないことを feature test で確認する。
- 締切後の応募、重複応募、許可しない遷移は `409 conflict`。重複応募は DB の unique 制約を正とし、`UniqueConstraintViolationException` を 409 に変換する（§5.5.5）。
- status を変えるたびに `application_status_changes` に 1 行足す（後から足すと過去の履歴を取り戻せないため、記録は MVP から行う）。
- 辞退（`withdrawn`）した求人には再応募できない（**決定**。unique 制約のまま）。辞退の確認画面に「辞退すると同じ求人には応募できません」と表示する。
- 応募には `seeker_profiles` の必須項目（自己 PR など。項目は M5 で確定）が入力済みであることを求める（**決定**）。足りなければ `422 validation_failed` を返し、SPA はプロフィール画面へ案内する。
- 応募 API には throttle を付ける（値は M5 で確定）。

## 5. 見える範囲と認可

§5.5.5 に従う。業務 API はすべて `auth:sanctum` と `api.verified` を付け、最初の業務 API を入れる M2 で `api.verified` を `SubstituteBindings` の前へ移す。

| resource | 見られる・操作できる人 | 権限が無いとき |
| --- | --- | --- |
| 公開中の求人（検索・詳細） | 求職者 | 企業担当者なら `403 forbidden` |
| 自社の求人（下書き・終了を含む）の閲覧・編集 | 自社の担当者 | 他社の担当者なら `404 not_found` |
| 応募 | 応募した求職者本人、応募先企業の担当者 | `404 not_found` |
| 求職者プロフィール | 本人、その求職者から応募を受けた企業の担当者（求職者が辞退した応募しか無い企業は見られない） | `404 not_found` |
| メッセージ | その応募の求職者本人、応募先企業の担当者 | `404 not_found` |

- 企業に見せる求職者プロフィールは**最新の内容**にする（**決定**）。応募時点のスナップショットは持たない。
- 企業に求職者の連絡先（メールアドレス等）は見せない（**決定**）。やり取りは「6. メッセージ」で行い、連絡先を伝えたい求職者は自分でメッセージに書く。

## 6. メッセージ

**決定**: 応募 1 件につきスレッド 1 つ。やり取りできるのは、その応募の求職者と、応募先企業の担当者だけ。

- 応募していない相手には送れない（企業から先に連絡する手段、つまりスカウトに相当するものは作らない）。
- 送れるのは文章だけ（2000 文字まで、超えたら `422`）。添付ファイル、送信後の編集・削除はできない。
- 送信 API には throttle を付ける（値は M5.5 で確定）。
- 本文中の URL は自動でリンクにしない（企業を装ったフィッシングへの誘導を防ぐため）。
- 応募が `rejected` / `withdrawn` になったら、読めるが送れない（`409 conflict`）。
- WebSocket も定期的な取り直し（polling）も使わない。スレッド画面を開いたとき・送信したとき・「更新」ボタンを押したときだけ取得する。新着は通知メールとナビゲーションの未読件数で知らせる。
- ナビゲーションの未読件数は画面遷移のときに取得し、定期的には取り直さない。集計のため `application_messages(application_id, id)` に index を張る。
- 既読はユーザーごと（`application_message_reads`: `application_id`, `user_id`, `last_read_message_id`）。MVP では 1 社の担当者は実質 1 人だが、後から担当者を複数にするときに作り直さないため。
- 通報・ブロックは作らない（企業は運営が確認済みのため、問題があれば運営が DB を見て対応する）。

API:

| method / path | 用途 |
| --- | --- |
| `GET /api/applications/{application}/messages` | 新しい順に最大 100 件程度を取得（pagination は件数が増えてから入れる。上限は M5.5 で確定） |
| `POST /api/applications/{application}/messages` | 送信 |
| `PUT /api/applications/{application}/messages/read` | 既読にする（GET に副作用を持たせない） |
| `GET /api/unread-counts` | ナビゲーションの未読件数 |

## 7. 通知メール

| 契機 | 宛先 |
| --- | --- |
| 応募があった | 応募先企業の担当者全員 |
| 応募の status が変わった | 求職者 |
| メッセージを受け取った | 相手側（求職者、または企業の担当者全員） |

- メッセージの通知は、宛先の user の未読が 0 件から 1 件になったときだけ送る（連続して送られても 1 通で済む）。
- メールには本文を載せず、「新着メッセージがあります」と画面へのリンクだけにする（メールに個人情報を載せないため）。
- 送信は queue で行う。開発では Mailpit で確認する。
- 現在 `QUEUE_CONNECTION=database` だが `compose.yaml` に worker が無く、このままでは開発環境でメールが送られない。最初に queue でメールを送る M5 で `queue:work` の service を足す。E2E（`laravel.e2e`）は `QUEUE_CONNECTION=sync` にする。

## 8. アカウント削除

既存の `DELETE /api/profile` を、関連データに合わせて次のように変える（**決定**）。

- 求職者: 応募と、その応募のメッセージ・選考履歴・既読を cascade で消す（個人情報を残さないため）。
- 企業担当者: その企業の最後の担当者は削除できない（`409 conflict`）。削除した担当者が送ったメッセージは残し、`sender_user_id` を null にして「退会した担当者」と表示する。
- 求人の削除（物理削除するか、`closed` にするだけか）は M3 で確定する。

## 9. 求人検索（M4）

- 条件: キーワード（タイトル・本文の LIKE）、都道府県、雇用形態、給与（単位と下限の組。異なる単位どうしは換算せず、同じ単位の求人だけを比べる）。
- 停止中の企業の求人は出さない。
- キーワードの LIKE（`%…%`）は index が効かないが、MVP の規模では許容する。公開状態・締切・停止の条件を同じ query の where で先に絞り、遅くなったら Meilisearch へ移す。
- 並び順: 新着順。
- pagination あり。一覧と詳細で見える範囲を揃える（§5.5.5）。件数（`per_page`）には上限を設ける（業務 API の一覧すべて同じ）。
- キーワードの `%`・`_`・`\` はエスケープしてから LIKE に渡す（ワイルドカードとして効かないようにする）。
- Meilisearch（Scout）は入れない。検索を強化するときに、職種マスタと一緒に検討する。

## 10. MVP の範囲

| 入れる | 入れない（MVP 後） |
| --- | --- |
| ロール、企業の招待（artisan） | 企業内での担当者の招待画面（テーブルは対応済み） |
| 求人管理、求人検索 | 職種マスタ、Meilisearch |
| 応募、選考ステータス、選考履歴の記録 | 選考履歴の画面、内定の承諾・辞退 |
| アプリ内メッセージ | 添付ファイル、WebSocket、通報・ブロック |
| 通知メール | お気に入り |
| | 履歴書のアップロード、スカウト、管理画面、企業の自己登録・申請 |

MVP 後の優先度は、お気に入り → 担当者の招待画面 → 選考履歴の画面の順を想定する。

## 11. スケジュール

| # | 期間（目安） | 内容 |
| --- | --- | --- |
| M0 | 11/09〜11/13 | profile 移植の PR、本書の PR、E2E の CI job、README、nginx に `Referrer-Policy: same-origin`（password 再設定・招待の URL の token が Referer で外部に漏れないようにする。`docker/nginx/smoke-test.sh` でヘッダを確認） |
| M1 | 〜11/07 | 本書（完了） |
| M1.5 | 11/16〜11/20 | Tailwind CSS 4 への移行と、デザインを差し替えやすくする土台（「12. デザイン」） |
| M1.6 | 11/23〜11/27 | 日本語化（「14. 日本語化」） |
| M2 | 11/30〜12/11 | 求職者プロフィールの編集画面、複数ロールの E2E helper、M2 の最後に CLAUDE.md のコーディング規約の追記、`users.role`、`companies`・`company_members`・招待（artisan、招待受諾画面）、`seeker_profiles`、最初の Policy、`api.verified` の priority 変更、ロールによる画面の振り分け、`/api/user` への `role` の追加、企業の停止、E2E 用 seeder、開発用 seeder（`DatabaseSeeder`。以降の Milestone で求人・応募・メッセージを足していく） |
| M3 | 12/14〜12/25 | 企業の求人管理と企業情報の編集（API・画面） |
| — | 12/28〜1/04 | 年末年始 |
| M4 | 1/05〜1/15 | 求職者の求人検索・一覧・詳細 |
| M5 | 1/18〜2/05 | 応募、選考ステータスと履歴、企業の応募者一覧、求職者の応募履歴と辞退、応募・status 変更の通知メール、queue worker の service（E2E は `sync`） |
| M5.5 | 2/08〜2/19 | メッセージ、未読件数、メッセージの通知メール、最後にロール別の dashboard |
| M6 | 未定（後回し） | production 構成（`docs/inertia-removal-migration.md` Phase 5・7）と公開（「13. 公開前に確認すること」） |

ロール別の dashboard の中身（例: 求職者には応募中の件数と未読、企業には新しい応募と公開中の求人）は M5.5 で決める。

デザイン素材の選定と適用は M2 で作る画面が揃った後に行い、時期は M3 以降で決める（「12. デザイン」）。

### 進め方: API 契約を先に書く

**決定**: M2〜M5.5 の各 Milestone は、実装の前にその Milestone の業務 API の契約を文書にし、レビューしてから実装する（認証まわりで `docs/phase0-api-contract.md` を先に固めたのと同じ進め方）。

- 書く先は `docs/domain-api-contract.md`（Milestone ごとの節）。最初の M2 で作る。
- 書く内容: method / path、middleware（`auth:sanctum`・`api.verified`・throttle・Policy の ability）、request の field と validation、response の body（`docs/inertia-removal-migration.md` §5.1 の規約に従う）、status と error code、403 と 404 のどちらにするか（§5.5.5）、feature test の観点。
- 書き方は表を中心にし、共通の規約（§5.1・§5.5.5）は参照するだけで繰り返さない。経緯や理由は判断が分かれたものだけ 1〜2 行で書く。実装より文書のほうに時間がかかる状態にしない。
- 契約を確定した後に endpoint 名や status を変えるときは、先に契約の文書を直す。

## 12. デザイン

**決定**: 見た目は後から無料のデザイン素材（Tailwind の部品集、daisyUI、shadcn-vue など）に差し替える前提で作る。素材はまだ選ばない。

### Tailwind CSS 4 への前倒し（M1.5）

現在メンテされている無料素材の多くは Tailwind CSS 4 前提のため、Tailwind CSS 4 への移行を M2 の前に行う（画面が少ない今のほうが移行範囲が小さい）。これは `docs/inertia-removal-migration.md` の「移行完了後の後続変更: Tailwind CSS 4」を前倒しするもの。

- Tailwind CSS 3 時点の build・component/E2E test・主要画面の表示を baseline にし、移行後も同じであることを確認する。
- `@tailwindcss/vite` を使うか PostCSS 構成を続けるかは、移行時に v4 の方式を評価して決める。
- `.github/dependabot.yml` の Tailwind CSS major の ignore、CLAUDE.md の Tailwind CSS 3 の記述、`@tailwindcss/forms` の扱いを同じ変更で直す。

### 差し替えやすくする土台（M1.5）

- 色は意味で名前を付けた token（`primary`、`danger`、`surface` など）を CSS 変数で定義し、画面・部品はそれを使う。既存の部品・画面にある Breeze 由来の `indigo-*`・`gray-*` を置き換える。
- class を検査している component test（4 箇所）は、`aria-current` などの属性や表示状態を見る形に直す。以後、class を検査する test は書かない。
- M2 以降の新しい画面は、カード、ページ見出し、バッジ、表、空の状態などを部品にし、画面ファイルに見た目を直接書かない（この規約は M1.5 で CLAUDE.md のコーディング規約に追記する）。

### 素材の選び方と取り込み方

- M2 の画面が揃った後、候補 2〜3 個で Login と求人一覧の 2 画面を試作して比べる。
- 開閉などの動きは既存の Vue 部品（`Dropdown`、`Modal` など）に任せ、素材の独自 JS（Flowbite・Preline の素の JS など）は入れない。素材の HTML を移すときも、既存の部品が付けている `aria` 属性を残す（role/label で探す test が確認する）。
- 独自の CSS を持ち Tailwind の preflight とぶつかりやすい UI ライブラリ（Vuetify、Quasar など）は採らない。
- 取り込んだ素材は、出典・version・ライセンスを記録する（記録先は採用時に決める）。有料版の無料サンプル、Figma Community のファイル、写真・イラストは、ライセンスを個別に確認する。
- 日本語は web フォントを使わず、OS 標準の日本語フォント（`system-ui`、Hiragino Sans、Meiryo 等の fallback の指定）で表示する（**決定**）。日本語の web フォントは数 MB になり初回表示が遅くなるため。素材の見た目のためにどうしても使う場合は、`unicode-range` で分割したものに限る。

## 13. 公開前に確認すること

- 求職者の情報を集めて企業に渡すサービスは、改正職業安定法（2022 年 10 月施行）の「特定募集情報等提供事業者」として届出が必要になる可能性がある。的確な表示や苦情処理の体制などの義務もある。公開前に確認する。
- 利用規約・プライバシーポリシー（メッセージで求職者が伝えた連絡先は、企業が控えている可能性があり取り消せないこと等）。
- 応募・メッセージなど個人情報の保存期間（MVP では消さない）。職業安定法の指針では、個人情報は目的に必要な範囲で管理し、不要になったら消すことが求められているため、選考終了から何日で消すか等を決める。
- 運用（M6 で用意する）:
  - DB のバックアップと復元手順
  - 死活・性能の監視、エラー通知（Sentry 等）、ログの保存先と保存期間
  - 企業アカウントの二要素認証（求職者の個人情報を見られるため）
  - メール送信サービスと、送信ドメインの認証（SPF・DKIM・DMARC）
  - queue worker の常駐と再起動（deploy 時の `queue:restart`）
- セキュリティ（M6 で用意する）:
  - nginx のセキュリティヘッダ: CSP、`X-Content-Type-Options: nosniff`、`frame-ancestors`（または `X-Frame-Options`）、HSTS。`Referrer-Policy` は M0 で入れ済みのものを production 構成にも入れる
  - cookie: `SESSION_SECURE_COOKIE=true`。`SESSION_ENCRYPT`（現在 `false`）をどうするか決める

## 14. 日本語化（M1.6）

**決定**: 画面・API のメッセージ・メールを日本語にする。多言語化はしない。

現状は `APP_LOCALE=en`・`APP_FAKER_LOCALE=en_US` で `lang/` も無く、validation のメッセージ、認証・パスワード再設定のメール、SPA の文言（Breeze 由来）がすべて英語。画面が増える前、かつデザイン素材の試作より前に日本語にする。

- Laravel: `lang/ja`（validation・auth・passwords・pagination）を用意し、`APP_LOCALE=ja`・`APP_FALLBACK_LOCALE=ja`・`APP_FAKER_LOCALE=ja_JP` にする（`.env.example`・CI・`laravel.e2e` も揃える）。validation の属性名（`attributes`）を日本語にする。認証メール・パスワード再設定メールの件名と本文を日本語にする。
- API の error の `message`（`docs/inertia-removal-migration.md` §5.1）も日本語にする。SPA は `code` で分岐し、`message` の文言には依存しない。
- SPA: 多言語化しないため vue-i18n は入れず、文言を直接日本語で書く（`frontend/index.html` は既に `<html lang="ja">`）。
- テスト: 英語の文言で要素を探している component test・E2E（`getByRole('button', { name: 'Log in' })` 等）と、英語の message を検査している Laravel の feature test を書き換える。
- 日本語フォントは web フォントを使わず OS 標準のものにする（「12. デザイン」）。`font-sans` の fallback に日本語フォントを明示するのはここで行う。
