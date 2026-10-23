# CLAUDE.md

## プロジェクト概要

求職情報サイト（Job Search App）。Laravel 13 の API（Sanctum の Cookie 認証）+ Vue 3 / TypeScript の SPA（`frontend/`）。
Inertia・Breeze の旧画面は撤去済み（2026-10-17）。Laravel は API とメール認証 callback だけを持ち、画面は持たない。
認証・profile の API はあるが SPA の画面は移植中（router は PlaceholderView）。求人・応募まわりのドメインは未実装。

## 開発環境（最重要）

このマシンには php / node / composer がホストに無い前提で運用する。
すべて **Laravel Sail**（Docker、サービス名 `laravel.test`、PHP 8.5 / Node 24）経由で実行する。

| 目的 | コマンド |
| --- | --- |
| 起動 | `./vendor/bin/sail up -d` |
| 停止 | `./vendor/bin/sail down` |
| artisan | `./vendor/bin/sail artisan ...` |
| composer | `./vendor/bin/sail composer ...` |
| PHP 整形 | `./vendor/bin/sail pint` |
| テスト | `./vendor/bin/sail test`（PHPUnit） |
| コンテナ状態確認 | `./vendor/bin/sail ps` |

git はホストでネイティブに動く（Sail は不要）。

### SPA（`frontend/`）

Vue SPA（経緯と API 契約は `docs/inertia-removal-migration.md`）。Sail の Node とは独立しており、
**Compose の `frontend` service（`node:${NODE_VERSION}-bookworm-slim`、現在 24.22.0 / npm 11.19.1）でだけ npm を実行する**。
`sail run` は `laravel.test` 内で実行されてしまうため、ここは `docker compose run` を使う。

| 目的 | コマンド |
| --- | --- |
| 依存インストール（初回・lock 更新後） | `docker compose run --rm --no-deps frontend npm ci` |
| lint / 型検査 / build | `docker compose run --rm --no-deps frontend npm run lint`（`typecheck` / `build` も同様） |
| unit/component/router テスト | `docker compose run --rm --no-deps frontend npm run test:run`（Vitest。`frontend/tests/**/*.test.ts`） |
| E2E | `docker compose run --rm frontend-e2e` → 後片付け `docker compose rm -sf laravel.e2e nginx-e2e`（Playwright。`frontend/e2e/**/*.spec.ts`） |
| Vite dev サーバ | `./vendor/bin/sail up -d` で `frontend` service として起動（`frontend:5173`、host には非公開） |
| ブラウザ確認 | http://localhost:8080（nginx。画面/HMR は Vite、`/api` `/sanctum` `/auth/email/verify` `/storage` `/up` は Laravel） |

| Nginx 振り分けの確認 | `./docker/nginx/smoke-test.sh`（ホストの curl で実行。path ごとの upstream/status、HMR WebSocket、Host 検査） |

- `frontend` が止まっていても nginx と API は動く（画面だけ 502）。
- E2E は `test` profile の `laravel.e2e`（DB は `testing_e2e`、起動時に `migrate:fresh`）・`nginx-e2e`・`frontend-e2e`（Playwright image）で動き、開発 DB は触らない。
  `frontend-e2e` は `nginx-e2e` の network namespace を共有し、入口は `http://localhost`（Vite の allowedHosts と Sanctum の stateful に一致させるため）。
  `laravel.e2e` の `artisan serve` は `--no-reload` 必須（無いと `.env` にある key の環境変数が外され、`DB_DATABASE` が開発 DB に戻る）。
  `sail stop` / `sail down` は test profile の container を止めないので、E2E 後は上の後片付けをする。
- `docker/nginx/default.conf` は nginx image の templates として mount し、`${LARAVEL_UPSTREAM}` を起動時に envsubst する（nginx 変数の `$http_host` 等は置換されない）。
- `@playwright/test` は `compose.yaml` の `frontend-e2e` image tag と version を一致させる。Dependabot は ignore しているので、両方を手動で同時に上げる。
- **branch 切替・pull で `frontend/` が変わったら `docker compose restart frontend`**。切替中に `vite.config.ts` が
  一時的に消えると Vite が config 無しで再起動し、復帰後も Vue plugin が読まれず `.vue` が 500 になる
  （Compose は unhealthy でも自動再起動しない）。smoke test の `GET /src/App.vue` で検出できる。
- ブラウザは `localhost` か `127.0.0.1` で開く。WSL の IP 等の別名だと Vite が Host を拒否（403）し、Sanctum の stateful 判定にも一致しない。

- `frontend/.npmrc` の `engine-strict=true` により、Sail の Node（24.18 / npm 12）で `npm ci` すると `EBADENGINE` で失敗する（意図どおり）。
- Node を上げるときは `.env.example` の `NODE_VERSION`、`compose.yaml` の既定値、`frontend/package.json` の `engines`/`packageManager`/`@types/node`（major を Node に合わせる。Dependabot は major を ignore）、CI の `node-version` を同時に変える。
- `node_modules` は bind mount（`frontend/node_modules`）なので、ホストの VSCode の Vue 拡張・ESLint がそのまま使える。
- `typescript` は `typescript-eslint` の peer 上限（`<6.1.0`）のため `~6.0.3` に固定している。
- CSS は Tailwind CSS 3（`frontend/tailwind.config.js`）を PostCSS（`frontend/postcss.config.js`）で処理する。`@tailwindcss/vite` は入れない。Tailwind 4 への major は Dependabot で ignore している。
  3 系の最終版（3.4.19）は依存の `braces` 等に npm audit の advisory が残るが、許容している（理由・前提・再評価は `docs/accepted-vulnerabilities.md`）。
  `overrides` で推移的依存を強制更新しない。許容する advisory は `frontend/audit-accepted.json` に載せ、CI の `npm audit` job が照合する。
- Figtree は `frontend/src/assets/fonts/` に self-host している（`@font-face` は `frontend/src/assets/app.css`）。外部 font CDN は使わない。

### ポート（他プロジェクトと同居する前提でずらしてある）

`.env` で以下をデフォルトから変更済み:

- `APP_PORT=8080`（http://localhost:8080）
- `FORWARD_DB_PORT=3308`
- `FORWARD_REDIS_PORT=6380`
- `FORWARD_MAILPIT_PORT=1026` / `FORWARD_MAILPIT_DASHBOARD_PORT=8026`（http://localhost:8026）

含まれるサービス: `mysql` / `redis` / `mailpit`。全文検索（Meilisearch/Scout）は未導入。
必要になったら `composer require laravel/scout meilisearch/meilisearch-php` の上で
`compose.yaml` に `meilisearch` サービスを追加する。

### やらないこと

- ローカル php / node / composer 前提のコマンドを書かない（PHP 系は `./vendor/bin/sail`、npm は `frontend` service 経由）
- `npm install` 等をホストや `laravel.test` で叩かない（`node_modules` は `frontend` service の Node バージョン前提）

## DB / タイムゾーン方針

- 文字コード・照合順序は **`utf8mb4` / `utf8mb4_0900_ai_ci`** に統一。
  - アプリ側: `.env` の `DB_CHARSET` / `DB_COLLATION`、`config/database.php` の
    mysql 接続デフォルトも `utf8mb4_0900_ai_ci`（元は `utf8mb4_unicode_ci`）。
  - サーバ側: `compose.yaml` の mysql `command` に
    `--character-set-server=utf8mb4 --collation-server=utf8mb4_0900_ai_ci`。
  - MySQL 8.4 のデフォルトと一致させ、JOIN 時の "illegal mix of collations" を回避する。
- タイムゾーンは **日本時間（`Asia/Tokyo` / MySQL は `+09:00`）** に統一。
  - アプリ: `.env` の `APP_TIMEZONE=Asia/Tokyo`（`config/app.php` は `env('APP_TIMEZONE', 'UTC')`）。
  - MySQL: `compose.yaml` の mysql `command` に `--default-time-zone=+09:00`
    （named zone は tz テーブル未ロードのためオフセット指定）。
- **`command` の変更反映には `./vendor/bin/sail up -d`（コンテナ再作成）が必要。`sail restart` では不可**。
  これは Docker Compose の仕様（`restart` は既存コンテナをそのままの設定で再起動するだけ）。
  設定ファイルの bind mount 方式は Docker Desktop + WSL2 だと編集時にマウントが壊れるため採用しない。
- **既存テーブルの照合順序は `DB_COLLATION` を変えても遡って変わらない**。
  変更時は `./vendor/bin/sail artisan migrate:fresh`（実施済み）か `ALTER TABLE ... CONVERT TO` が必要。
  テスト用 `testing` DB は次回 `./vendor/bin/sail test`（`RefreshDatabase`）で再作成され揃う。
- 接続ユーザーは root ではなく `sail`（`sail@%`）。永続化は named volume `sail-mysql`。

### 永続ボリュームと初期化タイミングの注意

- `sail down` ではデータは消えない（named volume に残る）。消えるのは `sail down -v` のみ。
- `command`（タイムゾーン等のサーバ起動フラグ）はボリュームの有無に関係なく毎回適用される
  ので、`down` → `up` で問題は起きない。
- 一方 **`MYSQL_DATABASE` / `MYSQL_USER` / `MYSQL_PASSWORD` / `MYSQL_ROOT_PASSWORD` と
  `docker-entrypoint-initdb.d/` のスクリプト（`create-testing-database.sh`）は
  datadir が空のとき（初回のみ）しか効かない**。
  既存ボリュームがある状態で `.env` の DB 名・ユーザー・パスワードを変えて `up` しても
  MySQL 側には反映されず、アプリが認証エラーになる。
  反映するには `sail down -v`（全データ削除）→ `up`、または SQL で手動変更する。

## CI（GitHub Actions）

`.github/workflows/ci.yml` で Pint / Composer audit / npm audit（`frontend/`、許容リスト照合）/ Laravel テスト（MySQL 8.4 + Redis）/ `frontend/` の lint・typecheck・build を実行する。
PR・main への push に加え、毎週月曜 9:00 JST の schedule でも走る（コード変更なしで新しい advisory・修正版の公開を検出するため）。

- PR のコードは信頼しない前提で動かす: `pull_request_target` は使わない、token は `contents: read`、checkout は `persist-credentials: false`、secrets を参照しない、全 job に `timeout-minutes` を付ける。job を足すときもこれを守る。
CI は **git 管理外のファイルが無いクリーンな checkout** で動くため、ローカルで通っても落ちることがある。

- PHP は `setup-php` の `8.5`（8.5 系最新）だが、パッチ版を固定できないため workflow の `env.PHP_MIN_VERSION` で下限を検査する。
  PHP 本体のセキュリティリリースが出たら、ここを上げ、ローカルは `./vendor/bin/sail build --no-cache laravel.test` → `./vendor/bin/sail up -d` で Sail イメージを作り直す。
- Composer audit は `composer.lock` に対して実行するため、新しい advisory が公開されるとコード変更なしでも落ちる（意図どおり）。
- テストは実 Redis に接続する（`RedisVerificationTest`）。CI では `services:` で Redis を起動している。
- MySQL は `services:` だと起動フラグを渡せないため `docker run` で起動し、compose.yaml と同じ
  文字コード・照合順序・タイムゾーンを指定している。
- runner の OS は `ubuntu-latest` ではなく `ubuntu-26.04` に固定している（`latest` は GitHub 側の移行で黙って OS が変わるため）。
  次の Ubuntu LTS の runner が GA になったら、各 job の `runs-on` をまとめて上げる。
- テストやビルド手順を足すときは、`vendor/` `frontend/node_modules/` `frontend/dist/` などが無い状態
  （`git worktree add` 等）でも通るか確認する。

### 依存更新（Dependabot）

`.github/dependabot.yml` で composer（ルート）/ npm（`frontend/`）/ GitHub Actions を毎週月曜 9:00（JST）に確認し、更新 PR を作る。
minor・patch はエコシステムごとに 1 PR にまとめ、major は個別 PR。

- PR では上記 CI（Laravel テスト・frontend の build 等）が走る。**自動マージはしない。CI が通ったことを確認して手動でマージする**。
- マージ後、ローカルは `./vendor/bin/sail composer install` / `docker compose run --rm --no-deps frontend npm ci` で反映する（npm は `docker compose restart frontend` も）。
- `typescript` の minor/major は ignore している（上記の peer 上限のため）。外すときは dependabot.yml も直す。
- PHP 本体・Node・Docker イメージ（`compose.yaml` の各タグ）・CI の runner OS は Dependabot の対象外。手動で上げる。
- GitHub Actions の major 更新（例: `actions/checkout`）は、action の実行 Node が廃止される前に入れる。
  古いままだと CI の Annotations に「Node.js NN is deprecated」の warning が出る。

## コーディング規約

まだ規約を固めるだけのドメインコードが無いため未記載。機能追加時に
コントローラ/フロントの型・命名パターンが固まった時点でここに追記する。
