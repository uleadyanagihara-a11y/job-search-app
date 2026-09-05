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

## 既知の注意点

- この Breeze バージョン（v2 系）が生成する `resources/js/app.js` には
  `import './bootstrap';` が残るが、対応する `resources/js/bootstrap.js` 自体は
  スタブに含まれておらず未使用の import になっていたため削除済み（axios は不使用）。
  Breeze を再インストール/上書きする際は再発する可能性がある。

## コーディング規約

まだ規約を固めるだけのドメインコードが無いため未記載。機能追加時に
コントローラ/フロントの型・命名パターンが固まった時点でここに追記する。
