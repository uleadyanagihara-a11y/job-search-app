# Docker / Laravel Sail コマンドメモ

Job Search App のローカル開発環境を Docker と Laravel Sail で操作するためのコマンド一覧です。コマンドはプロジェクトのルートディレクトリで実行します。

このプロジェクトでは、通常の開発操作に `docker compose` を直接使う代わりに `./vendor/bin/sail` を使用します。Sail は、このプロジェクトの `compose.yaml` を扱いやすくするためのコマンドラッパーです。

> [!TIP]
> Docker Desktop を起動し、対象の WSL ディストリビューションとの連携を有効にしてから操作してください。

## サービス構成

| サービス | 役割 | ホスト側の接続先 |
| --- | --- | --- |
| `nginx` | Web アプリへの入口 | http://localhost:8080 |
| `laravel.test` | Laravel、PHP、Node.js の実行環境 | Vite: http://localhost:5174 |
| `mysql` | データベース | `127.0.0.1:3308` |
| `redis` | キャッシュ、セッションなど | `127.0.0.1:6380` |
| `mailpit` | 開発用メールサーバー | Web UI: http://localhost:8026 |

上記は `.env.example` のポート設定を使用した場合の値です。実際の割り当ては次のコマンドでも確認できます。

```bash
./vendor/bin/sail ps
```

## 基本的な起動と終了

### 起動

全サービスをバックグラウンドで起動し、ヘルスチェックの完了まで待機します。

```bash
./vendor/bin/sail up -d --wait
```

初回起動や `compose.yaml` の変更後は、イメージ作成やコンテナ再作成のため時間がかかる場合があります。

### 状態確認

```bash
./vendor/bin/sail ps
```

`STATUS` が `Up` または `healthy` になっていることを確認します。終了済みのコンテナも含めて確認する場合は、次を使用します。

```bash
docker compose ps --all
```

### 停止と再開

コンテナと名前付きボリュームを残したまま停止します。

```bash
./vendor/bin/sail stop
```

停止したコンテナを再開します。

```bash
./vendor/bin/sail start
```

### コンテナの終了・削除

コンテナとネットワークを削除しますが、MySQL、Redis、Mailpit の名前付きボリュームは保持します。

```bash
./vendor/bin/sail down
```

次回は `./vendor/bin/sail up -d --wait` でコンテナを作り直せます。

> [!CAUTION]
> `./vendor/bin/sail down -v` は名前付きボリュームも削除し、MySQL、Redis、Mailpit のローカルデータを失います。データ削除が必要だと確認できた場合にだけ実行してください。

## Vite 開発サーバーと HMR

Sail を起動した後、別のターミナルで Vite を起動します。

```bash
./vendor/bin/sail npm run dev -- --host 0.0.0.0
```

ターミナルに次の URL が表示されたら、ブラウザで Web アプリを開きます。

```text
Webアプリ: http://localhost:8080
Vite:      http://localhost:5174
```

Vue ファイルを保存すると、Vite のログに次のような表示が出て、ブラウザへ HMR で反映されます。

```text
hmr update /resources/js/Pages/Welcome.vue
```

Vite だけを終了する場合は、Vite を実行しているターミナルで `Ctrl+C` を押します。Sail のほかのサービスは停止しません。

## ログ確認

### 直近のログ

```bash
./vendor/bin/sail logs --tail=100 nginx laravel.test mysql redis mailpit
```

特定サービスだけ確認する場合は、サービス名を一つ指定します。

```bash
./vendor/bin/sail logs --tail=100 nginx
./vendor/bin/sail logs --tail=100 laravel.test
./vendor/bin/sail logs --tail=100 mysql
```

### ログを継続表示

```bash
./vendor/bin/sail logs -f nginx laravel.test
```

`Ctrl+C` でログ表示だけを終了できます。コンテナは停止しません。

## コンテナ内でコマンドを実行する

PHP、Artisan、Composer、Node.js、npm はホスト側で直接実行せず、Sail を経由します。

```bash
# Laravel / Artisan
./vendor/bin/sail artisan about
./vendor/bin/sail artisan route:list
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan test

# PHP
./vendor/bin/sail php --version
./vendor/bin/sail php path/to/script.php

# Composer
./vendor/bin/sail composer install
./vendor/bin/sail composer validate

# Node.js / npm
./vendor/bin/sail node --version
./vendor/bin/sail npm --version
./vendor/bin/sail npm ci
./vendor/bin/sail npm run dev -- --host 0.0.0.0
./vendor/bin/sail npm run build
```

Laravel コンテナのシェルへ入る場合は、次を使用します。

```bash
./vendor/bin/sail shell
```

シェルから戻るには `exit` を実行します。

## データベースと Redis

MySQL クライアントを開きます。

```bash
./vendor/bin/sail mysql
```

Redis CLI を開きます。

```bash
./vendor/bin/sail redis
```

Laravel から Redis の接続をまとめて検証する場合は、プロジェクト用コマンドを使用できます。

```bash
./vendor/bin/sail artisan redis:verify --ttl=2
```

## サービス単位の操作

特定サービスの再起動には `docker compose` を使用できます。

```bash
docker compose restart nginx
docker compose restart laravel.test
docker compose restart mysql
```

サービスのコンテナ内で直接コマンドを実行する場合は、次の形式です。

```bash
docker compose exec laravel.test php -v
docker compose exec mysql mysqladmin ping -h 127.0.0.1
docker compose exec redis redis-cli ping
```

通常の Laravel 開発コマンドには、短く書ける Sail の使用を優先します。

## イメージとコンテナの再構築

Sail ランタイムや Dockerfile、ビルド設定を変更した場合は、イメージを再構築します。

```bash
./vendor/bin/sail build
./vendor/bin/sail up -d --wait
```

キャッシュを使わずに再構築する必要がある場合だけ、次を使用します。通常の再起動では不要です。

```bash
./vendor/bin/sail build --no-cache
```

## Compose 設定の確認

利用可能なサービス名を確認します。

```bash
docker compose config --services
```

Compose が解釈した設定全体を確認します。

```bash
docker compose config
```

実際に公開されているポートは、次のコマンドでサービスごとに確認できます。

```bash
docker compose port nginx 80
docker compose port laravel.test 5174
docker compose port mysql 3306
```

## トラブルシューティング

### Docker に接続できない

次のようなエラーが出た場合は、Docker Desktop と WSL 連携を確認します。

```text
Cannot connect to the Docker daemon
permission denied while trying to connect to the Docker API
```

確認用コマンドです。

```bash
docker version
docker compose version
```

### ポートが使用中になっている

起動時に `port is already allocated` や `address already in use` が出た場合は、使用中のコンテナと割り当てポートを確認します。

```bash
docker compose ps
docker ps
```

別プロジェクトと競合している場合は、このプロジェクトの `.env` にある `APP_PORT`、`VITE_PORT`、`FORWARD_DB_PORT` などのホスト側ポートを変更します。コンテナ間の接続ポートは変更しません。

### コンテナが healthy にならない

状態と対象サービスのログを確認します。

```bash
./vendor/bin/sail ps
./vendor/bin/sail logs --tail=200 laravel.test
./vendor/bin/sail logs --tail=200 nginx
./vendor/bin/sail logs --tail=200 mysql
```

設定を直した後、対象サービスだけ再起動できます。

```bash
docker compose restart サービス名
```

### Vite や HMR が動作しない

1. `./vendor/bin/sail ps` で `laravel.test` が起動していることを確認します。
2. `./vendor/bin/sail npm run dev -- --host 0.0.0.0` のターミナルにエラーがないか確認します。
3. http://localhost:8080 を再読み込みします。
4. `.env` の `VITE_PORT` と、Vite に表示されるポートが一致しているか確認します。
5. Vue ファイル保存時に Vite ログへ `hmr update` が出るか確認します。

## 注意が必要な操作

次の操作は、データや開発環境を削除する可能性があります。対象と影響を確認せずに実行しないでください。

```bash
# 名前付きボリュームを含めて削除する
./vendor/bin/sail down -v

# 未使用の Docker リソースを一括削除する
docker system prune

# 未使用ボリュームを削除する
docker volume prune
```

特にボリューム削除後は、MySQL などに保存したローカルデータを通常は復元できません。

## 日常作業の流れ

```bash
# 1. Sailを起動
./vendor/bin/sail up -d --wait

# 2. 状態確認
./vendor/bin/sail ps

# 3. 別ターミナルでViteを起動
./vendor/bin/sail npm run dev -- --host 0.0.0.0

# 4. 必要に応じてテスト
./vendor/bin/sail artisan test
./vendor/bin/sail npm run build

# 5. 開発終了時に停止
./vendor/bin/sail stop
```
