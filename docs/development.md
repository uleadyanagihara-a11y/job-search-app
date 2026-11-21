# 開発環境と運用

Docker の起動・復旧・環境更新・CI・依存更新の詳細。日々使うコマンドは `CLAUDE.md`、コードの規約は `.claude/rules/`（`laravel.md`・`frontend.md`）。

## 1. 前提

このマシンには php / node / composer がホストに無い前提で運用する。
PHP 系はすべて **Laravel Sail**（Docker、サービス名 `laravel.test`、PHP 8.5 / Node 24）経由で、npm は Compose の `frontend` service で実行する。
git はホストでネイティブに動く（Sail は不要）。

| 目的 | コマンド |
| --- | --- |
| 起動 | `./vendor/bin/sail up -d` |
| 停止 | `./vendor/bin/sail down` |
| artisan | `./vendor/bin/sail artisan ...` |
| composer | `./vendor/bin/sail composer ...` |
| コンテナ状態確認 | `./vendor/bin/sail ps` |

## 2. SPA（`frontend/`）の環境

Vue SPA（経緯と API 契約は `docs/inertia-removal-migration.md`）。Sail の Node とは独立しており、
**Compose の `frontend` service（`node:${NODE_VERSION}-bookworm-slim`、現在 24.22.0 / npm 11.19.1）でだけ npm を実行する**。
`sail run` は `laravel.test` 内で実行されてしまうため、ここは `docker compose run` を使う。

| 目的 | コマンド |
| --- | --- |
| 依存インストール（初回・lock 更新後） | `docker compose run --rm --no-deps frontend npm ci` |
| Vite dev サーバ | `./vendor/bin/sail up -d` で `frontend` service として起動（`frontend:5173`、host には非公開） |
| ブラウザ確認 | http://localhost:8080（nginx。画面/HMR は Vite、`/api` `/sanctum` `/auth/email/verify` `/storage` `/up` は Laravel） |
| Nginx 振り分けの確認 | `./docker/nginx/smoke-test.sh`（ホストの curl で実行。path ごとの upstream/status、HMR WebSocket、Host 検査） |

- `frontend` が止まっていても nginx と API は動く（画面だけ 502）。
- `node_modules` は bind mount（`frontend/node_modules`）なので、ホストの VSCode の Vue 拡張・ESLint がそのまま使える。
- ブラウザは `localhost` か `127.0.0.1` で開く。WSL の IP 等の別名だと Vite が Host を拒否（403）し、Sanctum の stateful 判定にも一致しない。

### 復旧

- **branch 切替・pull で `frontend/` が変わったら `docker compose restart frontend`**。切替中に `vite.config.ts` が
  一時的に消えると Vite が config 無しで再起動し、復帰後も Vue plugin が読まれず `.vue` が 500 になる
  （Compose は unhealthy でも自動再起動しない）。smoke test の `GET /src/App.vue` で検出できる。
- `docker/nginx/default.conf` は nginx image の templates として mount し、`${LARAVEL_UPSTREAM}` を起動時に envsubst する（nginx 変数の `$http_host` 等は置換されない）。
  編集後は `docker compose up -d --force-recreate nginx`（`restart` だと WSL2 で単一ファイルの bind mount が壊れたままになり起動しない）。
- 全 response に `Referrer-Policy: same-origin` を付けている（password 再設定・招待の token の URL が外部へ Referer で漏れないように。`docs/domain-design.md`）。smoke test と E2E の `smoke.spec.ts` で確認する。

### E2E の環境

- E2E は `test` profile の `laravel.e2e`（DB は `testing_e2e`、起動時に `migrate:fresh`）・`nginx-e2e`・`frontend-e2e`（Playwright image）で動き、開発 DB は触らない。
- `frontend-e2e` は `nginx-e2e` の network namespace を共有し、入口は `http://localhost`（Vite の allowedHosts と Sanctum の stateful に一致させるため）。
- `laravel.e2e` の `artisan serve` は `--no-reload` 必須（無いと `.env` にある key の環境変数が外され、`DB_DATABASE` が開発 DB に戻る）。
- `sail stop` / `sail down` は test profile の container を止めないので、E2E 後は `docker compose rm -sf laravel.e2e nginx-e2e` で後片付けをする。

## 3. ポート（他プロジェクトと同居する前提でずらしてある）

`.env` で以下をデフォルトから変更済み:

- `APP_PORT=8080`（http://localhost:8080）
- `FORWARD_DB_PORT=3308`
- `FORWARD_REDIS_PORT=6380`
- `FORWARD_MAILPIT_PORT=1026` / `FORWARD_MAILPIT_DASHBOARD_PORT=8026`（http://localhost:8026）

含まれるサービス: `mysql` / `redis` / `mailpit`。全文検索（Meilisearch/Scout）は未導入。
必要になったら `composer require laravel/scout meilisearch/meilisearch-php` の上で
`compose.yaml` に `meilisearch` サービスを追加する。

## 4. DB / タイムゾーン

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

## 5. 環境の更新（PHP・Node）

- **PHP**: CI は `setup-php` の `8.5`（8.5 系最新）だが、パッチ版を固定できないため workflow の `env.PHP_MIN_VERSION` で下限を検査する。
  PHP 本体のセキュリティリリースが出たら、ここを上げ、ローカルは `./vendor/bin/sail build --no-cache laravel.test` → `./vendor/bin/sail up -d` で Sail イメージを作り直す。
- **Node**: 上げるときは `.env.example` の `NODE_VERSION`、`compose.yaml` の既定値、`frontend/package.json` の `engines`/`packageManager`/`@types/node`（major を Node に合わせる。Dependabot は major を ignore）、CI の `node-version` を同時に変える。
- **runner の OS**: `ubuntu-latest` ではなく `ubuntu-26.04` に固定している（`latest` は GitHub 側の移行で黙って OS が変わるため）。
  次の Ubuntu LTS の runner が GA になったら、各 job の `runs-on` をまとめて上げる。

## 6. CI（GitHub Actions）

`.github/workflows/ci.yml` で Pint / Composer audit / npm audit（`frontend/`、許容リスト照合）/ Laravel テスト（MySQL 8.4 + Redis）/ `frontend/` の lint・typecheck・unit test・build / E2E（Playwright）を実行する。
PR・main への push に加え、毎週月曜 9:00 JST の schedule でも走る（コード変更なしで新しい advisory・修正版の公開を検出するため）。

- PR のコードは信頼しない前提で動かす: `pull_request_target` は使わない、token は `contents: read`、checkout は `persist-credentials: false`、secrets を参照しない、全 job に `timeout-minutes` を付ける。job を足すときもこれを守る。
- CI は **git 管理外のファイルが無いクリーンな checkout** で動くため、ローカルで通っても落ちることがある。
  テストやビルド手順を足すときは、`vendor/` `frontend/node_modules/` `frontend/dist/` などが無い状態（`git worktree add` 等）でも通るか確認する。
- Composer audit は `composer.lock` に対して実行するため、新しい advisory が公開されるとコード変更なしでも落ちる（意図どおり）。
- テストは実 Redis に接続する（`RedisVerificationTest`）。CI では `services:` で Redis を起動している。
- MySQL は `services:` だと起動フラグを渡せないため `docker run` で起動し、compose.yaml と同じ
  文字コード・照合順序・タイムゾーンを指定している。

### main の保護（GitHub の ruleset）

CI が落ちた変更を main に入れないのは、CI ではなく GitHub の ruleset `protect-main`（Settings → Rules → Rulesets）が担う。CI は結果を出すだけで、ruleset が無いと赤のままマージできる。

| ルール | 設定 |
| --- | --- |
| Restrict deletions / Block force pushes | 有効 |
| Require a pull request before merging | 有効。承認数は 0（1 人開発で自分の PR をマージするため。直接 push を止めるのが目的） |
| Require status checks to pass | `Pint`・`Composer audit`・`npm audit (frontend/)`・`Laravel tests (MySQL)`・`Frontend (frontend/)`・`E2E (Playwright)` |
| Bypass list | 空（admin も CI を迂回しない） |

- 必須チェックは `ci.yml` の job の `name` で照合する。**job の `name` を変える・job を足すときは、同じ PR で ruleset の必須チェックも直す**（古い名前を待ち続けてマージできなくなる。新しい job は足さないと必須にならない）。
- 必須にする job に `if:` や `paths:` を付けない（skip されると必須チェックが pending のまま残る）。
- audit も必須にしている。新しい advisory で無関係の PR が止まるが、上の Composer audit と同じく意図どおり（直すか、npm audit なら許容リストに理由を書いて足す）。
- 設定は公開 API で確認できる: `curl -s https://api.github.com/repos/uleadyanagihara-a11y/job-search-app/rules/branches/main`（`pull_request` と `required_status_checks` が出ること）。

### E2E job

- ローカルと同じ Compose の test profile で動かす（`composer install` → `.env.example` から `.env` を作り APP_KEY を生成 → Sail image を build → `frontend` で `npm ci` → `docker compose run --rm frontend-e2e`）。
- runner の uid（1001）に合わせて `WWWUSER` / `WWWGROUP` を渡すため、image に無い uid でも動くよう `frontend` / `frontend-e2e` は `HOME=/tmp` にしている。
- Sail image は cache なしだと build に約 15 分かかるため、`docker/build-push-action` で GitHub Actions の cache（scope `sail-8.5`）を使う。
  cache に書くのは main への push と schedule だけで、PR は読むだけ。schedule では cache を使わずに作り直す（apt の更新を取り込むため）。
  そのため main に一度 push されるまでは、PR でも 15 分かかる。
- 失敗時は container の log を出し、`playwright-report/` と `test-results/` を artifact（7 日）に残す。
- `compose.yaml` の Playwright image tag と lock の `@playwright/test` が違うと落ちる。

## 7. 依存更新（Dependabot）

`.github/dependabot.yml` で composer（ルート）/ npm（`frontend/`）/ GitHub Actions を毎週月曜 9:00（JST）に確認し、更新 PR を作る。
minor・patch はエコシステムごとに 1 PR にまとめ、major は個別 PR。

- PR では CI（Laravel テスト・frontend の build 等）が走る。**自動マージはしない。CI が通ったことを確認して手動でマージする**。
- マージ後、ローカルは `./vendor/bin/sail composer install` / `docker compose run --rm --no-deps frontend npm ci` で反映する（npm は `docker compose restart frontend` も）。
- `typescript` の minor/major は ignore している（`typescript-eslint` の peer 上限のため）。外すときは dependabot.yml も直す。
- `@playwright/test` は ignore している（`compose.yaml` の image tag と手動で同時に上げる）。
- PHP 本体・Node・Docker イメージ（`compose.yaml` の各タグ）・CI の runner OS は Dependabot の対象外。手動で上げる（新しい版の検出は次の節、手順は 5 節）。
- GitHub Actions の major 更新（例: `actions/checkout`）は、action の実行 Node が廃止される前に入れる。
  古いままだと CI の Annotations に「Node.js NN is deprecated」の warning が出る。

## 8. サーバーソフト本体の更新検出

`.github/workflows/server-software-updates.yml` が毎週月曜 9:00 JST（手動実行も可）に、nginx・PHP・Node.js・Redis・MySQL の版を確かめ、
更新があれば label `server-software-update` の Issue を作る。更新そのものはしない（上げるのは手動）。

- 使っている版は `compose.yaml` の既定値（`NGINX_VERSION:-…` 等）と `ci.yml` の `PHP_MIN_VERSION` から読む。書式を変えたら `.github/scripts/check_server_versions.py` の正規表現も直す（読めないと job が落ちる）。
- 最新版とサポート期限は endoflife.date の API から取る。有志の管理で、公式の公開から 1 日ほど遅れることがある。
- Issue を作るのは、同じ系列に新しい patch が出たときか、系列のサポートが終わったとき。同じタイトルの open な Issue があれば作らない。上げ終えたら Issue を閉じる。
- 修正版がまだ無い脆弱性は検出できない。中身（何が直ったか）は Issue のリンク先で読む。
- token は `contents: read` と `issues: write`。CI の「`contents: read` だけ」の例外なので、PR では動かさない（`pull_request` を trigger に足さない）。
- script は標準ライブラリだけの Python で、ローカルでは `python3 -I .github/scripts/check_server_versions.py <出力 dir>` で試せる（API を読むだけで Issue は作らない）。

## 9. Claude Code の権限（秘密情報と破壊的な操作）

ルール（何に承認が要るか）は `AGENTS.md`。ここではそれを機械的に支える設定を書く（2026-11-21）。

| 層 | 置き場所 | 役割 |
| --- | --- | --- |
| allow | `.claude/settings.local.json`（個人、git 管理外）だけ。共有の `.claude/settings.json` には置かない（承認したコマンドの蓄積や、この PC 固有の path が混ざるため） | 確認なしで動かしてよいものだけ。読み取り・`sail test`・`pint`・frontend の `npm run`・E2E など。commit・checkout・`docker compose` 全般・`sail php`・`sail mysql` などは入れない（中身を問わず何でも通るため） |
| deny | `.claude/settings.json` | `.env` の Read・Edit、`~/.ssh` 等、`git push`（Claude は push しない） |
| hook | `.claude/hooks/guard-destructive.py`（PreToolUse） | コマンド全体を見て、`migrate:fresh|reset|rollback|refresh`・`db:wipe`・`down -v`・volume 削除・prune・`git reset --hard`・`git clean`・`git restore`・`rm -r`・DB クライアント経由の `DROP`/`TRUNCATE`/`DELETE`・`.env` を含むコマンド・権限設定の編集に確認（ask）を出す。allow に入っていても出る |
| hook | `.claude/hooks/require-review-before-commit.py`（PreToolUse・Bash） | Claude の `git commit` の前に、PHP・SPA を最後に編集した後でレビュアー（`php-reviewer`・`frontend-reviewer`）を呼んでいなければ確認（ask）を出す |

個人用のもの（`settings.local.json`・`.claude/learning/`・`.claude/skills/`・個人のメモの ignore）は commit しない。個人のファイルの ignore は `.gitignore` ではなく `.git/info/exclude` に書く。

- allow を足すときは、`*` で危険な操作まで通らないかを確かめる（例: `docker compose *` は `down -v` も通す）。Bash のルールは前方一致なので、deny も `docker compose exec laravel.test php artisan …` のように経路を変えるとすり抜ける。hook はそれを補うもの。
- hook は deny ではなく ask にしている。開発 DB の作り直し（`migrate:fresh --seed`）などは、承認すれば実行してよいため。
- テストは影響を受けない。`sail test` は DB `testing` を RefreshDatabase で作り直し、E2E の `migrate:fresh` は `laravel.e2e` の起動コマンドがコンテナの中で実行する（Claude の Bash を通らない）。
- 防げるのは「うっかり実行」まで。コンテナはプロジェクトを mount しているので、`sail artisan tinker` などで `.env` を読むことはできる。そのため、本番の認証情報はこのマシンの `.env` に置かない。
- Sandbox（Bash の隔離）は使っていない。作業がほぼ docker・sail 経由で、docker socket に届かせるには対象外（`excludedCommands`）にするしかなく、守れる範囲がほとんど残らないため。
- hook を変えたら、典型的なコマンドで ask / 素通りを確かめる（hook に JSON を標準入力で渡し、出力が ask の JSON か空かを見る）。
