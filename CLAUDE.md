# CLAUDE.md

## プロジェクト概要

求職情報サイト（Job Search App）。Laravel 13 の API（Sanctum の Cookie 認証）+ Vue 3 / TypeScript の SPA（`frontend/`）。
Laravel は API とメール認証 callback だけを持ち、画面は持たない（Inertia・Breeze の旧画面は 2026-10-17 に撤去済み）。
認証・profile・ロール・企業の招待と停止・求職者プロフィール（M2）は実装済み。求人・応募まわりは未実装。

## 文書の置き場所

| ファイル | 内容 |
| --- | --- |
| `docs/domain-design.md` | MVP の業務設計とスケジュール（Milestone） |
| `docs/domain-api-contract.md` | Milestone ごとの業務 API 契約 |
| `docs/inertia-removal-migration.md` | 共通の API 規約（§5.1 response・status・code、§5.5.5 認証・認可・テストの観点、§6.1 router） |
| `docs/development.md` | Docker の起動・復旧・環境更新、ポート、DB/タイムゾーン、CI・Dependabot・サーバーソフトの更新検出、Claude Code の権限（allow・deny・破壊的操作の hook。9 節） |
| `.claude/rules/laravel.md` | Laravel（API）の規約。`app/` `routes/` `tests/` 等のファイルを扱うときに読み込まれる |
| `.claude/rules/frontend.md` | SPA（Vue・TypeScript・UI）の規約。`frontend/` のファイルを扱うときに読み込まれる |
| `AGENTS.md` | 承認が必要な操作（commit・push・削除・依存の追加・`.env`） |
| `.claude/agents/` | 読み取り専用のレビュアー（`php-reviewer`・`frontend-reviewer`）。hook は `.claude/hooks/reviewer-readonly.py` を共用 |

## 最重要ルール

- **ホストに php / node / composer は無い**。PHP 系は `./vendor/bin/sail`、npm は Compose の `frontend` service（`docker compose run --rm --no-deps frontend ...`）でだけ実行する。
  `npm install` 等をホストや `laravel.test`（`sail npm` / `sail run`）で叩かない（`node_modules` は `frontend` service の Node バージョン前提）。
- **業務 API は契約を先に書く**。実装の前に `docs/domain-api-contract.md` に書き、endpoint・status・code を変えるときも先に契約を直す。
- **認可は API の Policy が担う**。SPA で隠すのは認可にならない。新しい API route は `ApiEndpointContractTest` に登録する（詳細は `.claude/rules/laravel.md`）。
- **契約に関わる実装の後はレビュアーを呼ぶ**。`docs/domain-api-contract.md` を変えた（または契約の endpoint を実装した）機能は、テストが通った後・commit の提案の前に、
  変更が `app/` `routes/` `database/` 等にあれば `php-reviewer`、`frontend/` にあれば `frontend-reviewer` を Agent で呼ぶ（両方あれば両方を並列で）。
  指摘は確認してから直し、直さないものは理由をユーザーに伝える。呼ばずに commit を提案しない
  （呼び忘れは `.claude/hooks/require-review-before-commit.py` が Claude の `git commit` の前に確認を出す）。
  テスト専用の `test-reviewer` は作らない。M5 の開始時に、それまでのレビューでテストの見落としの指摘が多ければ検討する（**未決**）。
- **日本語のみ**（多言語化しない。`docs/domain-design.md` 14 章）。
- CI の job を足すときは、PR のコードを信頼しない設定（`contents: read`・secrets 不使用・`timeout-minutes` 等。`docs/development.md` 6 節）を守る。

## コマンド

| 目的 | コマンド |
| --- | --- |
| 起動 / 停止 | `./vendor/bin/sail up -d` / `./vendor/bin/sail down` |
| PHP 整形 | `./vendor/bin/sail pint` |
| Laravel テスト | `./vendor/bin/sail test`（絞るなら `--filter=XxxTest`） |
| SPA の依存（初回・lock 更新後） | `docker compose run --rm --no-deps frontend npm ci` |
| SPA の lint / 型検査 / build | `docker compose run --rm --no-deps frontend npm run lint`（`typecheck` / `build` も同様） |
| SPA の unit/component/router テスト | `docker compose run --rm --no-deps frontend npm run test:run`（Vitest。`frontend/tests/**/*.test.ts`） |
| E2E | `docker compose run --rm frontend-e2e` → 後片付け `docker compose rm -sf laravel.e2e nginx-e2e`（Playwright。`frontend/e2e/**/*.spec.ts`） |
| Nginx 振り分けの確認 | `./docker/nginx/smoke-test.sh` |
| ブラウザ確認 | http://localhost:8080（`localhost` か `127.0.0.1` で開く） |

- branch 切替・pull で `frontend/` が変わったら `docker compose restart frontend`（`.vue` が 500 になる。`docs/development.md` 2 節）。
- 環境が動かないときの復旧・設定変更の反映方法は `docs/development.md`。
