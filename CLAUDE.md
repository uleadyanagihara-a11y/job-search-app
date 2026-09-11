# CLAUDE.md

## プロジェクト概要

求職情報サイト（Job Search App）。Laravel 13 + Inertia v2 + Vue 3 の SPA。
認証は Laravel Breeze（Vue/Inertia stack, TypeScript/ESLint 無し）。
現時点ではまだ Breeze の初期スキャフォールドのみで、求人・応募まわりのドメインは未実装。

## 開発環境（最重要）

このマシンには php / node / composer がホストに無い前提で運用する。
すべて **Laravel Sail**（Docker、サービス名 `laravel.test`、PHP 8.5 / Node 24）経由で実行する。

| 目的 | コマンド |
| --- | --- |
| 起動 | `./vendor/bin/sail up -d` |
| 停止 | `./vendor/bin/sail down` |
| artisan | `./vendor/bin/sail artisan ...` |
| composer | `./vendor/bin/sail composer ...` |
| npm | `./vendor/bin/sail npm ...` |
| フロント dev サーバ | `./vendor/bin/sail npm run dev` |
| フロントビルド | `./vendor/bin/sail npm run build` |
| PHP 整形 | `./vendor/bin/sail pint` |
| テスト | `./vendor/bin/sail test`（PHPUnit） |
| コンテナ状態確認 | `./vendor/bin/sail ps` |

git はホストでネイティブに動く（Sail は不要）。

### ポート（他プロジェクトと同居する前提でずらしてある）

`.env` で以下をデフォルトから変更済み:

- `APP_PORT=8080`（http://localhost:8080）
- `VITE_PORT=5174`
- `FORWARD_DB_PORT=3308`
- `FORWARD_REDIS_PORT=6380`
- `FORWARD_MAILPIT_PORT=1026` / `FORWARD_MAILPIT_DASHBOARD_PORT=8026`（http://localhost:8026）

含まれるサービス: `mysql` / `redis` / `mailpit`。全文検索（Meilisearch/Scout）は未導入。
必要になったら `composer require laravel/scout meilisearch/meilisearch-php` の上で
`compose.yaml` に `meilisearch` サービスを追加する。

### やらないこと

- ローカル php / node / composer 前提のコマンドを書かない（必ず `./vendor/bin/sail` 経由）
- `npm install` 等をホストで直接叩かない（`node_modules` はコンテナ内 Node バージョン前提）

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

## 既知の注意点

- この Breeze バージョン（v2 系）が生成する `resources/js/app.js` には
  `import './bootstrap';` が残るが、対応する `resources/js/bootstrap.js` 自体は
  スタブに含まれておらず未使用の import になっていたため削除済み（axios は不使用）。
  Breeze を再インストール/上書きする際は再発する可能性がある。

## コーディング規約

まだ規約を固めるだけのドメインコードが無いため未記載。機能追加時に
コントローラ/フロントの型・命名パターンが固まった時点でここに追記する。
