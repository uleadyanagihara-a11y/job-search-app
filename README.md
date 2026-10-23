# Job Search App

Laravel（API）とVue 3 + TypeScriptのSPA（`frontend/`）で構成した求人検索アプリケーションです。現在はユーザー登録・ログイン・メール確認・プロフィール編集のAPIと、SPAのrouter・認証まわりの土台を備えています（画面は移植中）。

ローカル開発環境にはLaravel Sailを使用します。ホスト側にPHP、Composer、Node.js、npmを個別にインストールする必要はありません。Sailの準備完了後は、PHP、Artisan、Composerを必ず`./vendor/bin/sail`経由で、`frontend/`のnpmをComposeの`frontend` service経由で実行してください。

## 技術構成

- Laravel 13
- PHP 8.5（Sailランタイム）
- Laravel Sanctum（Cookie + CSRFによるSPA認証）
- Vue 3 / TypeScript / Vue Router / Vite 8（`frontend/`、Node 24.22.0）
- Nginx 1.28.0
- MySQL 8.4.11
- Redis 8.10.0
- Mailpit 1.30.6

## 前提条件

- WSL 2
- Docker Desktop（対象のWSLディストリビューションとの連携を有効化）
- Git

Docker Desktopを起動してから、WSLのターミナルでこのプロジェクトを操作します。

## 初回セットアップ

### 1. Composer依存関係の取得

新しくクローンした直後は`vendor/bin/sail`自体がまだありません。その場合に限り、Docker上のComposerを使ってSailを取得します。ホスト側のPHPやComposerは使用しません。

```bash
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$(pwd):/var/www/html" \
    -w /var/www/html \
    laravelsail/php85-composer:latest \
    composer install --ignore-platform-reqs
```

`vendor/bin/sail`が存在する場合、この手順は不要です。以降のComposer操作には`./vendor/bin/sail composer ...`を使用します。

### 2. 環境設定

```bash
cp .env.example .env
```

`.env.example`には、このプロジェクトのSail環境で使用する接続先とホスト側ポートが設定されています。コピー後の主な設定は次のとおりです。

```dotenv
APP_URL=http://localhost:8080
FRONTEND_URL=http://localhost:8080
APP_PORT=8080
SANCTUM_STATEFUL_DOMAINS=localhost:8080
NODE_VERSION=24.22.0
APP_TIMEZONE=Asia/Tokyo

WWWUSER=1000
WWWGROUP=1000

MYSQL_VERSION=8.4.11
REDIS_VERSION=8.10.0-alpine
MAILPIT_VERSION=v1.30.6
NGINX_VERSION=1.28.0-alpine

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=job_search_app
DB_USERNAME=sail
DB_PASSWORD=password
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_0900_ai_ci
FORWARD_DB_PORT=3308

REDIS_HOST=redis
FORWARD_REDIS_PORT=6380

MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
FORWARD_MAILPIT_PORT=1026
FORWARD_MAILPIT_DASHBOARD_PORT=8026
```

ほかのプロジェクトとのポート競合などで変更が必要な場合は、`.env`の各ポートを調整してください。コンテナ間の接続ポート（`DB_PORT=3306`、`REDIS_PORT=6379`、`MAIL_PORT=1025`）は変更しません。

通常、WSLユーザーのUID/GIDはどちらも`1000`です。異なる場合は、`.env`の`WWWUSER`と`WWWGROUP`を次の結果に合わせます。

```bash
id -u
id -g
```

### 3. Sailの起動とアプリケーションの準備

```bash
./vendor/bin/sail up -d --wait
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
docker compose run --rm --no-deps frontend npm ci
docker compose restart frontend
```

`frontend/`のnpmは`laravel.test`ではなく`frontend` service（固定したNode image）で実行します。`./vendor/bin/sail npm`や`sail run`は`laravel.test`内で実行されるため使いません。

### データベースの文字コードとタイムゾーン

- 文字コード / 照合順序は `utf8mb4` / `utf8mb4_0900_ai_ci`（MySQL 8.4 のデフォルトと一致）に統一。
  アプリ側は `.env` の `DB_CHARSET` / `DB_COLLATION`、サーバ側は `compose.yaml` の mysql
  `command`（`--character-set-server` / `--collation-server`）で指定します。
- タイムゾーンは日本時間に統一しています。アプリ側は `.env` の `APP_TIMEZONE=Asia/Tokyo`、
  MySQL 側は `compose.yaml` の mysql `command` の `--default-time-zone=+09:00` です。
- `command` の変更を反映するにはコンテナの再作成（`./vendor/bin/sail up -d`）が必要です。
  `./vendor/bin/sail restart` では反映されません（既存コンテナをそのまま再起動するだけのため）。
- 照合順序の設定（`DB_COLLATION`）を変更しても既存テーブルは遡って変わりません。
  変更後は `./vendor/bin/sail artisan migrate:fresh` を実行します（初回セットアップ時は不要）。
- `sail down` ではデータは消えません（名前付きボリュームに残る）。消えるのは `sail down -v` のみです。
  ただし `.env` の `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` は MySQL の初回初期化時
  （ボリュームが空のとき）にしか反映されません。既存ボリュームがある状態で変更しても
  MySQL 側には反映されないため、変更する場合は `./vendor/bin/sail down -v` でボリュームごと
  作り直すか、SQL で手動反映してください。

## 日常の開発

すべてのサービスを起動します。SPAのVite開発サーバーも`frontend` serviceとして起動します。

```bash
./vendor/bin/sail up -d --wait
```

ブラウザでは http://localhost:8080 を開きます。branch切替やpullで`frontend/`が変わった場合は`docker compose restart frontend`を実行します。

終了時はコンテナを停止します。MySQL、Redis、Mailpitの名前付きボリュームは保持されます。

```bash
./vendor/bin/sail stop
```

> [!CAUTION]
> `./vendor/bin/sail down -v`はMySQL、Redis、Mailpitのローカルデータも削除します。保存済みデータが不要だと確認できた場合にだけ実行してください。

## Compose構成

`compose.yaml`は次の方針で構成しています。

- ホストの`APP_PORT`はNginxだけが公開し、Webアクセスの入口をNginxへ統一
- NginxはGit管理された[`docker/nginx/default.conf`](docker/nginx/default.conf)を使用し、画面とHMRを`frontend:5173`（Vite）へ、API系を`laravel.test:80`へ転送（Laravel の転送先は`LARAVEL_UPSTREAM`で、nginx imageのtemplatesとして起動時に置換。E2E用の`nginx-e2e`は`laravel.e2e:80`）
- `nginx`、`laravel.test`、`frontend`、`mysql`、`redis`、`mailpit`を専用の`bridge`ネットワーク`sail`へ接続
- MySQL、Redis、Mailpitのデータを名前付きボリュームへ保存
- 接続先、認証情報、ホスト側ポート、イメージバージョンを環境変数で管理
- MySQL、Redis、Mailpitのヘルスチェックが成功してから`laravel.test`を起動し、Laravelのヘルスチェック成功後にNginxを起動
- Nginx、MySQL、Redis、Mailpit、Playwrightのイメージをバージョン固定
- E2E用の`laravel.e2e`、`nginx-e2e`、`frontend-e2e`は`test` profileで、`sail up`では起動しない

Webリクエストはpathごとに次の経路で振り分けます。

```text
ブラウザ -> localhost:${APP_PORT} -> nginx:80 -+-> /api/* /sanctum/* /auth/email/verify/* /storage/* /up -> laravel.test:80
                                               +-> それ以外（画面、/@vite/*、HMR WebSocket）           -> frontend:5173
```

`laravel.test`と`frontend`のポートはホストへ公開していないため、ブラウザからはNginxを経由してだけアクセスします。振り分けは`./docker/nginx/smoke-test.sh`（ホストのcurlで実行）で確認できます。

## URLとポート

`.env.example`の設定を使用した場合は、次のURLとポートを使用します。

| サービス | URLまたはホスト側ポート |
| --- | --- |
| Webアプリ（Nginx経由） | http://localhost:8080 |
| MySQL | `127.0.0.1:3308` |
| Redis | `127.0.0.1:6380` |
| Mailpit SMTP | `127.0.0.1:1026` |
| Mailpit Web UI | http://localhost:8026 |

Laravelコンテナから各サービスへ接続するときは、MySQLに`mysql:3306`、Redisに`redis:6379`、Mailpitに`mailpit:1025`を使用します。

## よく使うコマンド

```bash
# 起動（依存サービスのヘルスチェック完了まで待機）
./vendor/bin/sail up -d --wait

# コンテナ状態
./vendor/bin/sail ps

# 全サービスの直近100行のログ
./vendor/bin/sail logs --tail=100 nginx laravel.test frontend mysql redis mailpit

# 全サービスのログを継続表示（Ctrl+Cで表示だけを終了）
./vendor/bin/sail logs -f nginx laravel.test frontend mysql redis mailpit

# 停止（コンテナと名前付きボリュームは保持）
./vendor/bin/sail stop

# Laravel CLI
./vendor/bin/sail artisan about
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan route:list

# PHPテストとコード整形
./vendor/bin/sail artisan test
./vendor/bin/sail pint

# Composer
./vendor/bin/sail composer install
./vendor/bin/sail composer validate

# フロントエンド（frontend/）
docker compose run --rm --no-deps frontend npm ci
docker compose run --rm --no-deps frontend npm run lint
docker compose run --rm --no-deps frontend npm run typecheck
docker compose run --rm --no-deps frontend npm run test:run
docker compose run --rm --no-deps frontend npm run build

# E2E（Playwright。開発環境を起動した状態で実行し、終わったら E2E 用 container を消す）
docker compose run --rm frontend-e2e
docker compose rm -sf laravel.e2e nginx-e2e
```

ホスト側では`php artisan`、`composer`、`npm`、`php`を直接実行しません。PHPスクリプトを実行する場合も、次のようにSailを経由します。

```bash
./vendor/bin/sail php path/to/script.php
```

## テスト

バックエンドのテストを実行します。

```bash
./vendor/bin/sail artisan test
```

フロントエンドのlint、型検査、unit/component/router テスト（Vitest）、本番ビルドも確認します。

```bash
docker compose run --rm --no-deps frontend npm run lint
docker compose run --rm --no-deps frontend npm run typecheck
docker compose run --rm --no-deps frontend npm run test:run
docker compose run --rm --no-deps frontend npm run build
```

テストの置き場所は次のとおりです。Vitest は`*.test.ts`、Playwright は`*.spec.ts`だけを収集します。

| 種類 | 置き場所 | 実行 |
| --- | --- | --- |
| unit（API client、composable 等） | `frontend/tests/unit/` | Vitest（jsdom） |
| component | `frontend/tests/component/` | Vitest + Vue Test Utils |
| router guard | `frontend/tests/router/` | Vitest + `createMemoryHistory()` |
| E2E | `frontend/e2e/` | Playwright（Chromium） |

### E2E（Playwright）

`./vendor/bin/sail up -d --wait`で開発環境を起動した状態で実行します。

```bash
docker compose run --rm frontend-e2e
docker compose rm -sf laravel.e2e nginx-e2e
```

- `test` profile の`frontend-e2e`（`mcr.microsoft.com/playwright`、`@playwright/test`と同じ version）が、E2E 専用の`nginx-e2e`経由で`http://localhost`を開きます。Vite は開発用の`frontend` serviceを共有します。
- Laravel は E2E 専用の`laravel.e2e`で、DB は`testing_e2e`です。開発用 DB（`job_search_app`）とPHPUnit用の`testing`は更新しません。`laravel.e2e`は起動のたびに`migrate:fresh`するため、実行後に`docker compose rm -sf laravel.e2e nginx-e2e`で消しておくと、次回は空のDBから始まります。
- `laravel.e2e`と`nginx-e2e`は`sail stop` / `sail down`の対象外です（`test` profileのため）。残っていると`sail down`がnetworkを削除できません。
- 失敗時の trace / screenshot は`frontend/test-results/`、HTML レポートは`frontend/playwright-report/`に出力されます。
- Playwright を上げるときは、`frontend/package.json`の`@playwright/test`と`compose.yaml`の`frontend-e2e`の image tag を同時に変えます（Dependabot は対象外にしています）。

## Redis動作確認

Laravel から Redis へ接続し、通常キーの書き込み・取得・削除と、TTL 付きキーの期限切れをまとめて確認できます。`--ttl`には期限切れまでの秒数（1〜300）を指定します。

```bash
./vendor/bin/sail artisan redis:verify --ttl=2
```

コマンドは次の順で検証し、途中で期待と異なる結果になった場合は終了コード`1`で失敗します。

1. Redis へ接続してキーを`SET`
2. 同じキーを`GET`し、値が一致することを確認
3. キーを`DELETE`し、その後の`GET`が`nil`になることを確認
4. `SETEX`でTTL付きキーを作成し、残りTTLが設定されていることを確認
5. 指定秒数の経過後、キーの取得結果が`nil`、TTLが`-2`（キーなし）になることを確認

この動作を実 Redis に対する結合テストでも確認します。

```bash
./vendor/bin/sail artisan test --filter=RedisVerificationTest
```

## トラブルシューティング

### `Docker or Podman is not running`と表示される

Docker Desktopを起動し、対象のWSLディストリビューションとの連携が有効になっていることを確認してください。その後、もう一度Sailを起動します。

```bash
./vendor/bin/sail up -d --wait
```

### ポートが使用済みになっている

`.env`でホスト側ポートを変更します。次は設定例です。

```dotenv
APP_URL=http://localhost:8090
FRONTEND_URL=http://localhost:8090
APP_PORT=8090
SANCTUM_STATEFUL_DOMAINS=localhost:8090
FORWARD_DB_PORT=3309
FORWARD_REDIS_PORT=6381
FORWARD_MAILPIT_PORT=1027
FORWARD_MAILPIT_DASHBOARD_PORT=8027
```

上記は変更例です。Nginxの公開ポートを変更する場合は、`APP_PORT`に合わせて`APP_URL`・`FRONTEND_URL`・`SANCTUM_STATEFUL_DOMAINS`も同じURLへ変更してください。

### 設定変更が反映されない

```bash
./vendor/bin/sail artisan optimize:clear
```

## Git操作

このプロジェクトでよく使うGitコマンドと安全上の注意は、[`git_command.md`](git_command.md)を参照してください。
