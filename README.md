# Job Search App

Laravel、Inertia.js、Vue 3、Viteで構成した求人検索アプリケーションです。現在はLaravel Breezeによるユーザー登録・ログイン・メール確認・プロフィール編集と、Inertia/Vueの基本画面を備えています。

ローカル開発環境にはLaravel Sailを使用します。ホスト側にPHP、Composer、Node.js、npmを個別にインストールする必要はありません。Sailの準備完了後は、PHP、Artisan、Composer、npmを必ず`./vendor/bin/sail`経由で実行してください。

## 技術構成

- Laravel 13
- PHP 8.5（Sailランタイム）
- Inertia.js 2 / Vue 3
- Vite 8 / Tailwind CSS 3
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
APP_PORT=8080
VITE_PORT=5174

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
./vendor/bin/sail npm ci
./vendor/bin/sail npm run build
```

## 日常の開発

バックエンドと関連サービスを起動します。

```bash
./vendor/bin/sail up -d --wait
```

別のターミナルでVite開発サーバーを起動します。

```bash
./vendor/bin/sail npm run dev
```

終了時はコンテナを停止します。MySQL、Redis、Mailpitの名前付きボリュームは保持されます。

```bash
./vendor/bin/sail stop
```

> [!CAUTION]
> `./vendor/bin/sail down -v`はMySQL、Redis、Mailpitのローカルデータも削除します。保存済みデータが不要だと確認できた場合にだけ実行してください。

## Compose構成

`compose.yaml`は次の方針で構成しています。

- ホストの`APP_PORT`はNginxだけが公開し、Webアクセスの入口をNginxへ統一
- NginxはGit管理された[`docker/nginx/default.conf`](docker/nginx/default.conf)を使用し、`laravel.test:80`へリクエストを転送
- `nginx`、`laravel.test`、`mysql`、`redis`、`mailpit`を専用の`bridge`ネットワーク`sail`へ接続
- MySQL、Redis、Mailpitのデータを名前付きボリュームへ保存
- 接続先、認証情報、ホスト側ポート、イメージバージョンを環境変数で管理
- MySQL、Redis、Mailpitのヘルスチェックが成功してから`laravel.test`を起動し、Laravelのヘルスチェック成功後にNginxを起動
- Nginx、MySQL、Redis、Mailpitのイメージをバージョン固定

Webリクエストは次の経路でLaravelへ到達します。

```text
ブラウザ -> localhost:${APP_PORT} -> nginx:80 -> laravel.test:80
```

`laravel.test`のHTTPポートはホストへ公開していないため、ブラウザからLaravelへ直接アクセスする経路はありません。Viteの開発サーバーだけは、ホットリロードのため`VITE_PORT`をホストへ公開します。

## URLとポート

`.env.example`の設定を使用した場合は、次のURLとポートを使用します。

| サービス | URLまたはホスト側ポート |
| --- | --- |
| Webアプリ（Nginx経由） | http://localhost:8080 |
| Vite | http://localhost:5174 |
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
./vendor/bin/sail logs --tail=100 nginx laravel.test mysql redis mailpit

# 全サービスのログを継続表示（Ctrl+Cで表示だけを終了）
./vendor/bin/sail logs -f nginx laravel.test mysql redis mailpit

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

# フロントエンド
./vendor/bin/sail npm ci
./vendor/bin/sail npm run dev
./vendor/bin/sail npm run build

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

フロントエンドの本番ビルドも確認します。

```bash
./vendor/bin/sail npm run build
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
APP_URL=http://localhost:8081
APP_PORT=8081
VITE_PORT=5175
FORWARD_DB_PORT=3309
FORWARD_REDIS_PORT=6381
FORWARD_MAILPIT_PORT=1027
FORWARD_MAILPIT_DASHBOARD_PORT=8027
```

上記は変更例です。Nginxの公開ポートを変更する場合は、`APP_PORT`に合わせて`APP_URL`も同じURLへ変更してください。

### 設定変更が反映されない

```bash
./vendor/bin/sail artisan optimize:clear
```

## Git操作

このプロジェクトでよく使うGitコマンドと安全上の注意は、[`git_command.md`](git_command.md)を参照してください。
