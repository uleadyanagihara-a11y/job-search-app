---
paths:
  - "app/**"
  - "routes/**"
  - "bootstrap/**"
  - "config/**"
  - "database/**"
  - "lang/**"
  - "tests/**"
---

# Laravel（API）の規約

業務の契約は `docs/domain-api-contract.md`、業務ルールは `docs/domain-design.md`。共通の API 規約（response の形・status と `code`・403/404・409/422）は `docs/inertia-removal-migration.md` §5.1・§5.5.5。

## 契約と route

- 業務 API は実装の前に `docs/domain-api-contract.md` に契約を書く。endpoint・status・code を変えるときは先に契約を直す。
- 業務 API の route は `routes/api.php` の `Route::middleware(['auth:sanctum', 'api.company.active', 'api.verified'])->group()` に入れる。`api.company.active` → `api.verified` の順に、priority で route binding より前に動く（`SeekerProfileTest` で順序を検査）。
  `auth:sanctum` を付ける route には、logout 以外すべてに `api.company.active`（停止中の企業の担当者を毎回締め出す）も付ける。付け忘れは `ApiEndpointContractTest` が検出する。

## `ApiEndpointContractTest`（`tests/Feature/Api/ApiEndpointContractTest.php`）

- 新しい API route を足したら `ENDPOINTS` にも足す（無いと落ちる）。
- `auth:sanctum` の route は `roles` に使えるロールを書く。無いロールは `403 forbidden`、あるロールは 401・403 にならないことを全ロールで検査する
  （`UserFactory` の既定が seeker のため、endpoint ごとの test だけだと企業担当者の場合を書き忘れやすい）。
- path parameter（`{job}` 等）のある route を足したら、`resourceFor()` にその resource の作り方を足す（無いと落ちる）。
- ロール違いを `404 not_found` で拒否する endpoint（他人の非公開 resource）は `ROLE_DENIED_AS_NOT_FOUND` に書く。
- path parameter のある route は、`RESOURCE_SCOPES` に `owner`（持ち主以外は公開中でも `404`。他社の求人の編集など）か `public` を書く（無いと落ちる）。
  `owner` は、同じロールの別の user（担当者なら別の企業）からの request が `404`・存在しない id と同じ body・DB 不変になることを横断で検査する。

## 認可

- 認可は Policy（`app/Policies/`）にまとめ、呼び出しは controller の `Gate::authorize()` か route の `can:` の 1 か所。ロールの判定も Policy に書き、middleware にしない。
- SPA でボタンを隠すのは認可にならない。持ち主の判定は Policy の 1 つの private method にまとめ、ability ごとに書かない。
- FormRequest を引数で受けると認可より先に validation が動くため、ロール違いを `403` にしたい API では `Gate::authorize()` の後に `app(XxxRequest::class)` で解決する（`SeekerProfileController`）。

## 企業の停止

- 企業の停止中に各操作をどう扱うかは、`docs/domain-design.md` 2 章「企業の停止」の表に従う。M3 以降の契約にはその status・code を写す。
- 求職者に見せる求人の条件（停止中の企業を除く）は `JobPosting` の 1 つの scope にまとめる。
- `api.company.active` が効かない web route（メール確認の callback 等）では、controller で停止を確認する。

## controller・response・業務ルール

- controller は `app/Http/Controllers/Api/`（認証まわりは `Api/Auth/`）、action 名は `show`/`update`/`store`/`destroy` 等の resource 名。
- response は `JsonResource`（`app/Http/Resources/`）で返し、日時は `->clone()->utc()->format('Y-m-d\TH:i:s\Z')`、nullable は `null` で返して field の有無を変えない。
- 業務ルール違反は `abort(409)`（`conflict`）。一意性は DB の unique 制約を正とし、`UniqueConstraintViolationException` を `409` にする。
- `role` と所属企業は利用者が変えられない。`#[Fillable]` にも FormRequest の rules にも入れず、作る側が `forceFill` で入れる。FormRequest の値は `validated()` から必要な key だけ取り出して `fill` する。
- PHP の enum は `app/Enums/`（`Role`、`Prefecture`）。都道府県のような固定の一覧は enum にし、SPA は同じ値を `frontend/src/constants/` に持つ（一覧 API は作らない）。

## 言語（日本語のみ。`docs/domain-design.md` 14 章）

- Laravel の locale は `ja`。validation の文は `lang/ja/validation.php`、通知メールの文は `lang/ja.json`。
- request に field を足したら、`lang/ja/validation.php` の `attributes` に表示名も足す（無いと field 名がそのまま出る）。
- API の `message` は日本語で書くが、SPA は `code` で分岐し `message` の文言に依存しない。

## test・seeder

- user・企業を作る test は factory の state を使う（`User::factory()->companyMember($company)`、`Company::factory()->suspended()`）。
- 全 field の validation は Feature test（`assertJsonValidationErrors`）で確かめる（E2E には重ねない）。
- 固定の password の user を作る seeder は `RunsOnlyInLocalEnvironments` を使い、`local`・`testing` 以外では例外にする。
- E2E で使う user は `E2eSeeder` に足し、`frontend/e2e/helpers.ts` の `E2E_USERS` と揃える。
- テストは実 Redis に接続する（`RedisVerificationTest`）。
