# Inertia を通常動作から外すための移行計画

## 1. 目的と固定する前提

この文書は、現在の Laravel + Inertia + Vue 構成を、次の責務へ段階的に分離するための実装順序を固定する。

- Vue SPA: 画面描画、クライアント側ルーティング、フォーム状態、API 呼び出し、画面単位の認可表示
- Laravel API: 認証、認可、入力検証、業務処理、永続化、JSON 応答
- Nginx: ブラウザからの単一の入口、API と SPA の振り分け、SPA fallback
- Vite: Vue の開発サーバー、HMR、静的アセットの production build

採用する前提は以下とする。

1. 認証は、既に導入済みの Laravel Sanctum を利用した Cookie / Session ベースの SPA 認証とする。
2. ブラウザから見た SPA と API は同一オリジンに置く。API は `/api/*`、CSRF Cookie は `/sanctum/csrf-cookie` とする。
3. Laravel は Vue のページコンポーネントを選択しない。通常の画面 URL は Vue Router が解決する。
4. API は画面遷移を指示する redirect を原則返さず、JSON と適切な HTTP status を返す。例外として、署名付きメール認証 Web コールバックだけは Vue SPA への redirect を返す。
5. Inertia は新旧共存期間だけ残し、SPA/API の疎通と回帰テストが揃ってから一括して通常経路から外す。
6. Vue Router などの新規 npm package が必要になった場合は、プロジェクトルールに従い、追加前に明示的な承認を得る。
7. 新 SPA はリポジトリ直下の `frontend/` に置く。Laravel は現在のルート配置を維持し、`backend/` への移動は行わない。
8. 開発時のブラウザ公開入口は Nginx だけにする。Vite port は Docker network 内だけで公開し、Vite 自身には API proxy を持たせない。
9. SPAのfingerprinted assetはVite標準の`/assets/*`に置く。`/storage/*`はLaravel管理とし、assetとSPA routeを混在させない。
10. password reset mail は SPA の `/reset-password/{token}?email=...` へ直接遷移させる。token と email は Laravel Password Broker で検証し、Laravel の画面 route/Web callback は介在させない。
11. 新 SPA の CSS 基盤は現行と同じ Tailwind CSS 3 + PostCSS とする。Inertia 撤去と Tailwind CSS 4 化を同時に行わず、Tailwind CSS 4 と `@tailwindcss/vite` の採用可否は SPA 切替完了後の別変更で判断する。
12. Figtree font は新 SPA から自己ホストする。Bunny Fonts への runtime 接続は廃止し、font file は Vite の hashed asset として配信、license は `frontend/src/assets/fonts/` で font file と一緒に管理する。
13. SPA router は Vue Router 4、unit/component test は Vitest + Vue Test Utils 2 + jsdom、Nginx 経由の E2E/UI 回帰 test は Playwright を使う。package と Playwright browser image の追加は、正確な version/peer dependency/diff を提示して承認を得てから行う。
14. 初期 frontend CI は ESLint、`vue-tsc --noEmit`、Vitest、production build、Playwright をすべて必須とする。いずれかが失敗/未実行なら移行完了としない。
15. 新 SPA は最初から TypeScript strict mode で作る。旧 `resources/js` は JavaScript のまま維持し、`frontend/` へ画面/component を移植する単位で完全に型付けする。TypeScript 化と同時に Tailwind CSS 4 化等の別の技術移行は行わない。
16. frontend の Node image は `node:22.<patch>-bookworm-slim` で固定する。`node:22`, `node:22-bookworm-slim`, `node:22.<minor>-bookworm-slim` のような実行時に patch が変わる tag は使わない。正確な patch は frontend package の engine 確認後、依存追加承認時に固定する。
17. CI 製品は Phase 1–4 のローカル実装を妨げないため Phase 4 完了まで保留できるが、Phase 5 着手前に確定する。repository が GitHub 管理の場合は GitHub Actions を第一候補とし、それまでは CI 製品に依存しない command/artifact 契約を維持する。

## 2. 現状の構成

```text
Browser
   |
   v
Nginx :80
   |  全パスを proxy_pass
   v
Laravel (web.php / auth.php)
   |  Inertia::render(component, props)
   v
app.blade.php + createInertiaApp
   |
   v
Vue Pages
   |  Link / useForm / page props
   +---------------------------------> Laravel web route
```

現在の Nginx は全リクエストを `laravel.test:80` へ転送しており、Laravel が画面配信、画面ルート、認証、入力検証、データ更新をすべて担当している。`routes/api.php` は存在せず、`bootstrap/app.php` に API route の登録もない。

### 2.1 依存関係

| 種別 | 現在の直接依存 | 用途 | 移行完了時 |
| --- | --- | --- | --- |
| Composer | `inertiajs/inertia-laravel:^2.0`（lock: 2.0.26） | `Inertia::render`、middleware、Blade directive | 削除 |
| npm | `@inertiajs/vue3:^2.0.0`（lock: 2.3.27） | `createInertiaApp`、`Head`、`Link`、`useForm`、`usePage` | 削除 |
| Composer | `tightenco/ziggy:^2.0` | PHP の named route を Vue の `route()` から参照 | 原則削除。API URL はフロント側で管理 |
| npm | `laravel-vite-plugin:^3.1` | 現在の Laravel/Vite entry と Inertia page resolver | ルート Node manifest から削除。新 SPA の Vite は `frontend/` で独立管理 |
| npm | `tailwindcss:^3.2.1`, `postcss:^8.4.31`, `autoprefixer:^10.4.12`, `@tailwindcss/forms:^0.5.3` | 現在実際に使用している Tailwind CSS 3 + PostCSS pipeline | 同じ major/config を `frontend/` へ移し、新 lock file でバージョンを固定 |
| npm | `@tailwindcss/vite:^4.0.0` | lock に Tailwind CSS 4 を持つが、現在の `vite.config.js` では未使用 | `frontend/` には移さない。ルート Node 依存整理時に削除 |
| Composer | `laravel/sanctum:^4.0` | API 認証候補。現在は SPA API route が未設定 | 継続利用 |
| npm | `vue:^3.4.0` | UI | 継続利用 |
| npm（新規） | `vue-router` 4 | SPA route、history、route guard、not-found | `frontend/` の runtime dependency として追加。test は `createMemoryHistory()` を使う |
| npm（新規） | `vitest`, `@vue/test-utils` 2, `jsdom` | unit/component/DOM test | `frontend/` の dev dependency として追加 |
| npm（新規） | `@playwright/test` | Nginx 経由の E2E/UI screenshot test | `frontend/` の dev dependency として追加し、browser image と version を一致させる |
| npm（新規） | `typescript`, `vue-tsc`, `@types/node` | Vue SFC、app/config/test の静的型検査 | `frontend/` の dev dependency として追加し、`vue-tsc --noEmit` を CI 必須化 |
| npm（新規） | `eslint`, `@eslint/js`, `eslint-plugin-vue`, `typescript-eslint`, `globals` | TypeScript/Vue/test/config の lint | `frontend/` の dev dependency として追加。初期は type-aware lint を必須とせず `vue-tsc` と責務分離 |

現在は HTTP client と SPA router の直接依存がない。HTTP client は標準 `fetch` でも実装できる。Vue Router を採用する場合は依存追加の承認が必要になる。

## 3. Inertia 利用箇所の棚卸し

### 3.1 起動、middleware、root view

| 対象 | 現在の役割 | 置換先 |
| --- | --- | --- |
| `resources/js/app.js` | `createInertiaApp`、page resolver、Inertia plugin、Ziggy を登録 | 切替まで旧アプリとして維持。新 SPA は `frontend/src/main.ts` に独立作成 |
| `resources/views/app.blade.php` | `@inertia`、`@inertiaHead`、`$page`、`@routes` | 切替まで旧アプリとして維持。新 SPA は `frontend/index.html` を使用 |
| `app/Http/Middleware/HandleInertiaRequests.php` | root view と全ページ共通 `auth.user` props | `/api/user` または `/api/auth/session` の JSON に置換後、削除 |
| `bootstrap/app.php` | `HandleInertiaRequests` を web middleware に追加 | middleware を削除し、API route と Sanctum stateful middleware を登録 |
| `vite.config.js` | Laravel entry と Inertia page の解決を支援 | 切替まで旧アプリ用として維持し、最終削除。新設定は `frontend/vite.config.ts` に作成 |

### 3.2 `Inertia::render`（9 呼び出し）

| 現在の画面 GET | 呼び出し元 | 現在の props | 移行後 |
| --- | --- | --- | --- |
| `/` | `routes/web.php` | `canLogin`, `canRegister`, `laravelVersion`, `phpVersion` | Vue の `/` route。認証状態だけ API から取得。version 表示は削除または専用公開 API に限定 |
| `/dashboard` | `routes/web.php` | 共通 `auth.user` | Vue の `/dashboard` route + router guard |
| `/profile` | `ProfileController::edit` | `mustVerifyEmail`, `status`, 共通 `auth.user` | Vue の `/profile` route + `GET /api/profile` |
| `/register` | `RegisteredUserController::create` | 共通 props | Vue の `/register` route |
| `/login` | `AuthenticatedSessionController::create` | `canResetPassword`, `status` | Vue の `/login` route。reset 完了 message は router state/query または client state |
| `/forgot-password` | `PasswordResetLinkController::create` | `status` | Vue の `/forgot-password` route |
| `/reset-password/{token}` | `NewPasswordController::create` | URL token, query email | Vue が route param/query を直接読む |
| `/verify-email` | `EmailVerificationPromptController` | `status` | Vue の `/verify-email` route + auth state |
| `/confirm-password` | `ConfirmablePasswordController::show` | 共通 props | Vue の `/confirm-password` route |

画面を返す action とデータ更新 action が同じ controller に混在している。API 化では画面 action（`create`, `show`, `edit`）を廃止し、更新 action は JSON response を返す API controller へ移す。

### 3.3 Vue 側の Inertia API

`@inertiajs/vue3` を import する Vue ファイルは 17、`useForm` 利用ファイルは 9、`Link` 利用ファイルは 10、`Head` 利用ファイルは 9、`$page.props` / `usePage` 利用ファイルは 3 ある。

| API | 利用箇所 | 置換 |
| --- | --- | --- |
| `Link` | `GuestLayout.vue`, `AuthenticatedLayout.vue`, `NavLink.vue`, `ResponsiveNavLink.vue`, `DropdownLink.vue`, `Welcome.vue`, `Login.vue`, `Register.vue`, `VerifyEmail.vue`, `UpdateProfileInformationForm.vue` | 画面リンクは router link、送信ボタンは API 呼び出し。logout や verification resend を link として表現しない |
| `useForm` | auth 6 画面、profile partial 3 画面 | SPA 共通 form composable。`data`, `errors`, `processing`, `recentlySuccessful`, `reset` を保持し API client を呼ぶ |
| `Head` | `Welcome`, `Dashboard`, auth 6 画面, `Profile/Edit` | router meta + `document.title`、または title composable |
| `$page.props.auth.user` | `Welcome.vue`, `AuthenticatedLayout.vue` | auth store/composable が保持する `/api/user` の結果 |
| `usePage().props.auth.user` | `UpdateProfileInformationForm.vue` | profile/auth store の user |
| component props | `Login`, `ForgotPassword`, `ResetPassword`, `VerifyEmail`, `Profile/Edit`, `Welcome` | route param、client state、API response、build-time config に分解 |
| Ziggy `route()` | layout と各 form | Vue router の named route、または API client 内の固定 endpoint |

### 3.4 現在の送信契約

| 現在の操作 | method/path | 入力 | 現在の成功動作 |
| --- | --- | --- | --- |
| 登録 | `POST /register` | `name`, `email`, `password`, `password_confirmation` | session login 後 `/dashboard` redirect |
| login | `POST /login` | `email`, `password`, `remember` | session 再生成後 intended/dashboard redirect |
| logout | `POST /logout` | なし | session 無効化後 `/` redirect |
| reset link 送信 | `POST /forgot-password` | `email` | back + flash status |
| password reset | `POST /reset-password` | `token`, `email`, `password`, `password_confirmation` | `/login` redirect + flash status |
| verification mail 再送 | `POST /email/verification-notification` | なし | back + flash status |
| email verification | `GET /verify-email/{id}/{hash}` | signed URL | verification 後 `/dashboard?verified=1` redirect |
| password 確認 | `POST /confirm-password` | `password` | intended/dashboard redirect |
| profile 更新 | `PATCH /profile` | `name`, `email` | `/profile` redirect |
| password 更新 | `PUT /password` | `current_password`, `password`, `password_confirmation` | back redirect |
| account 削除 | `DELETE /profile` | `password` | logout/session 無効化後 `/` redirect |

`routes/web.php` / `routes/auth.php` には、画面 GET、mutation、email verification callback を合わせて 20 本ある。移行後は、通常画面 GET を Laravel route として登録しない。

## 4. 新しい責務図

### 4.1 production

```text
Browser (single origin)
   |
   v
Nginx
   |-- /api/* --------------------------> Laravel API
   |-- /sanctum/csrf-cookie ------------> Laravel / Sanctum
   |-- /auth/email/verify/* ------------> Laravel signed Web callback
   |-- /storage/* ----------------------> Laravel managed files
   |-- /up ------------------------------> Laravel health check
   |-- /assets/*, other static assets ---> built SPA assets
   `-- all other GET paths --------------> SPA index.html
                                               |
                                               v
                                      Vue Router + Views
                                               |
                                      fetch/API client
                                               |
                                               `---- same-origin /api/*
```

### 4.2 development

```text
Browser (single origin)
   |
   v
Nginx
   |-- /api/*, /sanctum/*,
   |   /auth/email/verify/*, /up -------> Laravel
   `-- all other paths -----------------> frontend:5173 (Vite/HMR)
                                               |
                                               `--> frontend/src Vue SPA
```

Vite の host port へブラウザから直接アクセスする運用は標準にせず、開発時も Nginx を公開入口とする。これにより CORS、`SANCTUM_STATEFUL_DOMAINS`、Cookie domain/SameSite の追加調整を避け、production と同じ path 境界を検証する。HMR の WebSocket も Nginx が転送する。

#### 4.2.1 Nginx の振り分け

development Nginx の location は次の優先順位で固定する。

| path | upstream | 備考 |
| --- | --- | --- |
| `/api/*` | `laravel.test:80` | JSON API。SPA fallback より優先 |
| `/sanctum/csrf-cookie` | `laravel.test:80` | Sanctum CSRF Cookie 発行 |
| `/auth/email/verify/*` | `laravel.test:80` | 署名付き Web callback |
| `/storage/*` | `laravel.test:80` | upload等のLaravel管理ファイル。SPA fallback対象外 |
| `/up` | `laravel.test:80` | Laravel/Nginx healthcheck |
| その他 | `frontend:5173` | Vite HTML、asset、SPA fallback、HMR |

Laravel と Vite の両 upstream に `Host`, `X-Forwarded-For`, `X-Forwarded-Host`, `X-Forwarded-Proto` を渡す。Vite 向けには HTTP/1.1、`Upgrade`、`Connection` を渡し、proxy buffering を無効にする。Laravel の `Set-Cookie` は書き換えずそのままブラウザへ返す。CORS response header と `proxy_cookie_domain` は追加しない。

#### 4.2.2 Compose と Vite

`compose.yaml` に専用 `frontend` service を追加し、Vite 8 に対応する `node:22.<patch>-bookworm-slim` を使用する。正確な patch は Vue Router、TypeScript、ESLint、Vitest、Playwright 等の採用 version の `engines`/peer dependency を確認した上で固定する。

```dotenv
NODE_VERSION=22.<exact-patch>
```

```yaml
frontend:
    image: 'node:${NODE_VERSION}-bookworm-slim'
```

`NODE_VERSION` は `.env.example` と CI で同じ値を使い、`frontend/package.json` の `engines.node` は選定 version 以上 23 未満を許可する。image に同梱される npm の正確な version は `packageManager: "npm@<version>"` に記録する。`.npmrc` の `engine-strict=true` で Node 不一致をエラーにし、CI で `node --version` と `npm --version` を出力/検査する。container 起動時に npm を自動更新せず、dependency は `package-lock.json` + `npm ci` で再現する。

`bookworm-slim` に curl/wget が存在することに依存せず、frontend healthcheck は Node 22 の `fetch()` を使う。Playwright は browser 依存を含む専用 image を別途固定し、frontend の Node image へ browser/package を追加しない。

- working directory: `/app`
- source mount: `./frontend:/app`
- dependency volume: `frontend-node-modules:/app/node_modules`
- command: `npm run dev -- --host 0.0.0.0 --port 5173 --strictPort`
- network: `sail`
- port: `expose: 5173` のみ。host の `ports` は設定しない
- healthcheck: Node の `fetch('http://127.0.0.1:5173/')` で 2xx を確認

Nginx は `laravel.test` と `frontend` の healthcheck 成功後に起動する。現在 `laravel.test` に設定されている Vite host port 公開は新 SPA の切替後に削除する。`frontend/vite.config.ts` は API proxy を設定せず、通常の Vue plugin、`host`, `port`, `strictPort` だけを管理する。

HMR client は、まず browser が開いている Nginx の host/port を自動利用させる。WebSocket が Vite port へ直接 fallback する場合に限り、公開 Nginx port を `hmr.clientPort` として環境変数から設定し、固定値は埋め込まない。

#### 4.2.3 Sanctum と CSRF

標準の開発 URL は `http://localhost:${APP_PORT}` とし、`.env.example` の既定値では `http://localhost:8080` になる。設定は次で固定する。

```dotenv
APP_URL=http://localhost:8080
FRONTEND_URL=http://localhost:8080
APP_PORT=8080
SESSION_DOMAIN=null
SESSION_PATH=/
SESSION_SECURE_COOKIE=false
SESSION_SAME_SITE=lax
SANCTUM_STATEFUL_DOMAINS=localhost:8080
```

Vite が読む `frontend/.env.example` には、Laravel の root `.env` と混在させず次だけを置く。

```dotenv
VITE_API_BASE_URL=/api
```

`bootstrap/app.php` で `routes/api.php` を登録し、`$middleware->statefulApi()` を有効にする。frontend API client は URL に origin を埋め込まず、`/api` と `/sanctum/csrf-cookie` の相対 URL を使用する。mutation 前に CSRF Cookie を取得し、標準 `fetch` の場合は URL decode した `XSRF-TOKEN` Cookie を `X-XSRF-TOKEN` header に設定する。全 API request は `Accept: application/json` と `credentials: 'same-origin'` を使用する。

#### 4.2.4 新旧共存期間

Phase 3–4 で旧 Inertia app と新 SPA をブラウザ確認する期間だけ、Nginx に一時的な SPA preview listener を追加できる。

| URL | Nginx の転送先 |
| --- | --- |
| `http://localhost:8080` | 旧 Laravel/Inertia |
| `http://localhost:8081` | 通常画面は Vite、API/CSRF/callback は Laravel |

この場合もブラウザは Vite や Laravel container へ直接接続しない。`SANCTUM_STATEFUL_DOMAINS` には `localhost:8080,localhost:8081` を設定する。Cookie は port では分離されないため session は共有される。Phase 5 で 8080 を新 SPA 構成へ切り替えたら、preview listener と 8081 の host port 公開を削除する。

email verification の absolute signed callback URL の host/port は途中で 8081 に書き換えない。preview 期間の verification link は `APP_URL` の 8080 で Laravel callback を実行し、成功後だけ `FRONTEND_URL` の 8081 へ redirect して確認する。password reset link は署名付き callback ではないため、`FRONTEND_URL` の 8081 へ直接向ける。最終切替時は両方を 8080 に戻す。

### 4.3 担当境界

| 関心事 | Vue SPA | Laravel API | Nginx | Vite |
| --- | --- | --- | --- | --- |
| 画面遷移 | Vue Router が URL、guard、not-found を処理 | 画面 redirect を返さない | direct access を `index.html` へ fallback | dev 時の history fallback |
| データ取得 | API client、loading/error state、必要なら cache | 認証・認可後に JSON Resource/DTO を返す | `/api` を Laravel へ転送 | 担当しない |
| データ送信 | submit、二重送信防止、成功後の route 遷移 | FormRequest/validator、業務処理、transaction、JSON status | body/header/cookie を透過 | 担当しない |
| 認証 | 起動時 `/api/user`、router guard、401 時 login へ | Sanctum + session、login/logout、session 再生成/破棄、authorization | 同一 origin と secure headers を維持 | 担当しない |
| CSRF | 初回 mutation 前に `/sanctum/csrf-cookie`、cookie を付けて送信 | CSRF token 検証 | cookie/header を透過 | 担当しない |
| validation error | 422 の `errors` を form field に表示 | 常に JSON `{ message, errors: { field: string[] } }` を 422 で返す | 応答を改変しない | 応答を改変しない |
| title/meta | route meta / composable | 担当しない | 担当しない | HTML template/build |
| 静的 asset | 参照する | 担当しない | production で配信/cache | build、hash、HMR |

### 4.4 SPA の配置

新 SPA は既存 `resources/js` を移動して作らず、`frontend/` に新規構築する。旧 Inertia app と新 SPA を切替完了まで共存させる。

```text
job-search-app/
├── app/                         # Laravel application
├── bootstrap/
├── config/
├── routes/
│   ├── api.php                  # JSON API
│   └── web.php                  # signed email callback
├── tests/                       # Laravel API tests
├── resources/js/                # 旧 Inertia app。Phase 6 で撤去
├── frontend/
│   ├── index.html
│   ├── package.json
│   ├── package-lock.json
│   ├── vite.config.ts
│   ├── vitest.config.ts
│   ├── playwright.config.ts
│   ├── tsconfig.json
│   ├── tsconfig.app.json
│   ├── tsconfig.node.json
│   ├── env.d.ts
│   ├── eslint.config.js
│   ├── postcss.config.js
│   ├── tailwind.config.js
│   ├── public/
│   ├── src/
│   │   ├── main.ts
│   │   ├── App.vue
│   │   ├── assets/
│   │   ├── types/
│   │   ├── router/
│   │   ├── api/
│   │   ├── stores/
│   │   ├── composables/
│   │   ├── layouts/
│   │   ├── components/
│   │   └── views/
│   ├── tests/
│   └── dist/                    # build artifact。Git 管理しない
└── docker/nginx/
    ├── development.conf
    └── production.conf
```

Node dependency、script、test runner、Vite config は `frontend/` 内で完結させる。現在ルートにある `package.json`、`package-lock.json`、`vite.config.js` は旧 Inertia app 専用として移行中だけ残し、Phase 6 で不要性を確認して撤去する。Laravel を `backend/` へ移す変更は Sail、Composer、volume mount、既存 command への影響が大きいため本移行の対象外とする。

### 4.5 assetパス

SPAのbuild assetは`/assets/*`へ統一する。`/build/*`は旧Laravel Vite/Inertia appだけが移行中に使用し、新SPAから参照しない。

| path | 所有者 | 用途/cache |
| --- | --- | --- |
| `/assets/*` | Vue/Vite/Nginx | hash付きJS/CSS/image/font。1年`immutable` |
| `/favicon.ico` | Vue SPA | `frontend/public`からrootへcopy。短期cache |
| `/robots.txt` | Vue SPA | `frontend/public`からrootへcopy。no-cache |
| `/manifest.webmanifest` | Vue SPA | 必要な場合のみ`frontend/public`へ配置 |
| `/storage/*` | Laravel | upload等。Laravelまたは専用storageへ転送 |
| `/api/*` | Laravel | JSON API。assetではない |
| その他 | Vue Router | SPA route。`index.html`へfallback |

`frontend/vite.config.ts`の配信契約は次で固定する。Laravel Bladeから参照しないためVite manifestは生成しない。

```js
export default defineConfig({
    plugins: [vue()],
    base: '/',
    build: {
        outDir: 'dist',
        assetsDir: 'assets',
        emptyOutDir: true,
        manifest: false,
        sourcemap: false,
    },
});
```

component固有のimage/SVG/font/CSS backgroundは`frontend/src/assets`からimportし、Viteにhashを付けさせる。固定URLが必要な`favicon.ico`, `robots.txt`等だけを`frontend/public`へ置く。hash対象を`/assets/file.ext`としてsourceへ直書きしない。

production Nginxは次の順で処理する。

1. `/api/*`, `/sanctum/*`, `/auth/email/verify/*`, `/storage/*`, `/up`をLaravel側へ送る。
2. `/assets/*`はproduction image内の`frontend/dist/assets`から配信し、存在しなければ404にする。`index.html`へfallbackさせない。
3. `/index.html`は`no-cache, no-store, must-revalidate`とする。
4. その他は`try_files $uri $uri/ /index.html`でSPAへfallbackする。

developmentでは`/assets/*`, `/@vite/*`, `/@id/*`, `/src/*`, `/node_modules/.vite/*`を含む通常のfrontend requestを個別制限せずViteへ送る。production用の長期cache headerをdevelopmentには付けない。

deploy中に旧`index.html`が参照するhash付きchunkを消さないことが理想だが、初期構成は単一Nginx imageでassetとHTMLを同時に切り替える。既に開いているSPAのdynamic importが旧chunkの404になった場合に備え、`vite:preloadError`を検知して一度だけreloadする。rolling deploymentや利用規模が増えた場合は、直前世代assetの一定期間保持またはCDN/object storageへの追記型配信へ変更する。

## 5. API 契約案

### 5.1 レスポンス規約

API response は Laravel 標準形式を維持し、SPA が安定して分岐するための `code` だけを追加する。HTTP status を成功/失敗の一次判定とし、`success`, `status`, `data: null` のような重複フィールドは追加しない。

| 用途 | status/body |
| --- | --- |
| 単一 resource | `200` または `201` + `{ "data": { ... } }` |
| 更新済み resource | `200` + `{ "data": { ... } }` |
| collection | `200` + `{ "data": [...], "links": {...}, "meta": {...} }` |
| message のみ | `200` + `{ "code": "...", "message": "..." }` |
| body 不要 | `204 No Content`。JSON body を付けない |
| 非同期受付 | 実際に queue へ投入して処理完了を待たない場合だけ `202` |

単一/collection response は Laravel `JsonResource` / Resource Collection を使い、Eloquent Model を直接 JSON 化しない。公開フィールドを Resource で明示する。profile 更新のように SPA の store 更新が必要な mutation は更新済み resource を返す。

validation error は Laravel 標準の `message`, `errors` を維持する。

```json
{
  "code": "validation_failed",
  "message": "入力内容を確認してください。",
  "errors": {
    "email": [
      "このメールアドレスは既に使用されています。"
    ]
  }
}
```

validation 以外の error は次の形式とする。production response に例外クラス、stack trace、SQL、内部パスを含めない。

```json
{
  "code": "unauthenticated",
  "message": "認証が必要です。"
}
```

`code` は英小文字の `snake_case` で固定し、SPA の条件分岐に使う。`message` は表示用であり、文言による条件分岐は禁止する。

| status | code | 用途 |
| --- | --- | --- |
| `200` | 成功内容に応じた code または省略 | 取得、同期処理、message のある成功 |
| `201` | 省略 | resource 作成 |
| `204` | body なし | 更新/削除等で返却データ不要 |
| `400` | `bad_request` | JSON 不正など validation 以外の不正 request |
| `401` | `unauthenticated` | session なし、期限切れ |
| `403` | `forbidden` | 認可不足 |
| `403` | `email_unverified` | email verification が必要 |
| `423` | `password_confirmation_required` | password 再確認が必要。Laravel標準`RequirePassword`に合わせる |
| `404` | `not_found` | resource なし |
| `405` | `method_not_allowed` | 許可されないHTTPメソッド。`Allow` header も返す |
| `409` | `conflict` | 状態競合、重複操作 |
| `409` | `already_authenticated` | guest専用APIを認証済みuserが実行 |
| `419` | `csrf_token_mismatch` | CSRF token 不正/期限切れ |
| `422` | `validation_failed` | FormRequest/validator failure |
| `429` | `rate_limited` | throttle。`Retry-After` header も返す |
| 上記以外の `4xx` | `client_error` | 表にない client error。元の status を保つ |
| `500` | `internal_error` | 想定外 error |
| `503` | `service_unavailable` | 一時的な利用不能 |

共通データ規約は以下とする。

- JSON property は `snake_case`。
- 日時は RFC 3339 の UTC（例: `2026-09-11T10:00:00Z`）。
- nullable field は値がない場合も `null` を返し、同じ resource 内で field の有無を変えない。
- 金額は浮動小数点ではなく、最小通貨単位の整数または仕様で定めた decimal 文字列を使う。
- ID の型を endpoint ごとに変えない。
- request 追跡情報は body ではなく `X-Request-ID` header を使う。
- 認証済み response は原則 `Cache-Control: no-store`。
- JSON response は `Content-Type: application/json` とし、SPA は `Accept: application/json` を送る。
- API version は外部 client を持たない間は `/api` のままとし、`/v1` は追加しない。

### 5.2 エンドポイント

既存 URL と名前をそのまま API に露出せず、`/api/auth/*` と `/api/profile` に整理する。最終的な path は API テストで固定する。

| API | middleware | 成功 response | Vue 側の次動作 |
| --- | --- | --- | --- |
| `GET /api/user` | `auth:sanctum` | `200 { data: user }` | auth store を初期化。401 は guest として扱う |
| `POST /api/auth/register` | `api.guest`, throttle | `201 { data: user, meta: { redirect_to } }` | auth store 更新後 dashboard へ |
| `POST /api/auth/login` | `api.guest`, LoginRequest limiter | `200 { data: user, meta: { redirect_to } }` | allowlist 済み intended SPA URL または dashboard へ |
| `POST /api/auth/logout` | `auth:sanctum` | `204` | auth store 破棄後 `/` へ |
| `POST /api/auth/forgot-password` | `api.guest`, throttle | `200 { code, message }` | 同じ画面に message を表示 |
| `POST /api/auth/reset-password` | `api.guest`, throttle | `200 { code, message }` | login 画面へ移動し message を渡す |
| `POST /api/auth/email/verification-notification` | `auth:sanctum`/throttle | `200 { code, message }` | 同じ画面に message を表示 |
| `POST /api/auth/confirm-password` | `auth:sanctum` | `204` | 保存した intended SPA URL へ |
| `GET /api/profile` | `auth:sanctum` | `200 { data, meta }` | profile form 初期化 |
| `PATCH /api/profile` | `auth:sanctum` | `200 { data: user }` | auth store も同じ user で更新 |
| `PUT /api/profile/password` | `auth:sanctum` | `204` | form reset + success 表示 |
| `DELETE /api/profile` | `auth:sanctum` | `204` | auth store 破棄後 `/` へ |

補足事項:

- message response の成功 code は `password_reset_link_sent`, `password_reset`, `verification_link_sent` のようにユースケース単位で固定する。
- password reset mail の URL は Laravel の画面 route に依存させず、SPA の `/reset-password/{token}?email=...` を生成する。
- email verification は API に含めず、後述する署名付き Web コールバックを利用する。
- password reset mail request は user の存在有無にかかわらず同じ `200`, `code`, 汎用 `message` を返し、account enumeration を防ぐ。
- 未認証は `401`、認証済みだが権限不足は `403` とする。email 未確認や password 再確認が必要な場合は、Vue が遷移先を判断できる安定した error code も返す。
- flash session props は API response の `message` と Vue の local/router state に置き換える。
- Laravel の `shouldRenderJsonWhen` は既に `api/*` または `expectsJson()` を JSON と判定する。SPA の API client は常に `Accept: application/json` を送る。

### 5.3 password reset URL

password reset mail の URL は token と email を含む SPA 直接遷移方式で固定する。

| 項目 | 契約 |
| --- | --- |
| mail URL | `${FRONTEND_URL}/reset-password/{token}?email={URL encoded email}` |
| URL 生成 | Laravel 標準の `ResetPassword::createUrlUsing()` で差し替える |
| URL 設定 | `FRONTEND_URL` を config 経由で参照する。provider から `env()` を直接呼び出さない |
| GET の担当 | Nginx が SPA `index.html` を返し、Vue Router が `/reset-password/:token` を表示する |
| Vue の担当 | route param の token と query の email を取得し、password/password confirmation と一緒に API へ送る |
| submit | `POST /api/auth/reset-password` へ `token`, `email`, `password`, `password_confirmation` を送る |
| Laravel の担当 | Password Broker で email/token/有効期限を検証し、password と remember token を更新する |
| 成功 | `200 { code: "password_reset", message }`。Vue が token/email を含まない `/login` へ移動する |
| 無効・期限切れ・使用済み | `422`, `code: "validation_failed"`, `errors.token`。email の存在有無や失敗理由は区別しない |

URL に email を含めるのは、Laravel 標準の Password Broker が email を基準に reset token を検証すること、SPA で email の再入力と入力ミスを避けられることを採用理由とする。token から email を逆引きする独自機構は追加しない。GET 時は token の有効性を確定せずフォームを表示し、submit 時の Laravel 検証を正とする。

password reset URL は署名付き email verification callback とは別物とする。reset token 自体が一時的な secret であり、Laravel の Web callback、signed URL、画面 route を追加しない。`GET /reset-password/{token}` は常に SPA 側の route とし、Laravel には POST API だけを置く。

token/email の漏洩を抑えるため、production は HTTPS を必須とし、reset URL の path/query を Nginx/application log、analytics、error tracking に記録しない。reset 画面には外部リソースを置かず、`Referrer-Policy: no-referrer` と認証情報に適した cache policy を設定する。成功後は browser history 上の reset URL に戻る必要がないよう `router.replace()` を使う。

### 5.4 メール認証 Web コールバック

メール認証方式は、Laravel の署名付き Web コールバックを残し、認証後に Vue SPA へ redirect する方式で固定する。

| 項目 | 契約 |
| --- | --- |
| callback | `GET /auth/email/verify/{id}/{hash}?expires=...&signature=...` |
| route group | `web` |
| middleware | `signed`, `auth`, `throttle:6,1`。署名検証後に session 認証を要求し、対象 user との一致も検証 |
| 成功 | email を verified に更新後、`${FRONTEND_URL}/dashboard?verified=1` へ 302 |
| 期限切れ/署名不正 | `${FRONTEND_URL}/verify-email?error=invalid-or-expired` へ redirect。更新しない |
| 別 user でログイン済み | `${FRONTEND_URL}/verify-email?error=user-mismatch` へ redirect。更新しない |
| 未ログイン | callback の同一 origin path を session の intended URL に保存し、`${FRONTEND_URL}/login?verification_required=1` へ redirect |
| login 後 | login API が許可済みの `redirect_to` を返し、Vue が `window.location.assign()` で callback を再実行 |

未ログイン判定を通常の `auth` middleware の redirect に任せる場合も、redirect 先は削除予定の Laravel `login` route ではなく SPA login URL に設定する。intended URL は任意の外部 URL を許可せず、同一 origin の `/auth/email/verify/` 配下だけを受け付ける。

```text
認証メール
  -> Nginx
  -> Laravel signed Web callback
       |-- 未ログイン: intendedをsessionへ保存 -> Vue /login
       |                                      -> API login
       |                                      -> callback再実行
       `-- ログイン済み: signed/user/hash検証 -> verified更新
  -> Vue /dashboard?verified=1
```

Laravel callback は画面 HTML や Inertia response を返さないため、Laravel と Vue の画面責務分離には反しない。reverse proxy 下で署名を安定させるため、`APP_URL`、`FRONTEND_URL`、trusted proxy、forwarded host/proto を環境ごとに一致させる。

この方式は Laravel 標準の session、`EmailVerificationRequest`、signed URL を活用でき、ログイン user と認証対象を照合できる点を採用理由とする。また、未認証のメールクライアントによるリンクスキャンだけで verification が完了しにくい。代わりに、Nginx の例外 location、未ログイン時の intended 復元、Laravel から SPA への redirect が必要になる。この例外はメール認証 callback のみに限定する。

### 5.5 API middleware方針

初期実装は「API専用middlewareが統一形式のJSON responseを返す方式」とする。規模拡大時は「middlewareが型付き例外をthrowし、例外ハンドラが同じJSON responseを生成する方式」へ移行できる構造にする。移行前後でHTTP status、`code`, `message`, `errors`, headerを変更しない。

#### 5.5.1 共通pipeline

`bootstrap/app.php`で`routes/api.php`を登録し、Sanctumの`$middleware->statefulApi()`を有効にする。route middleware aliasは次で固定する。

| alias | 初期実装の役割 | failure |
| --- | --- | --- |
| `api.guest` | 認証済みuserによるguest専用操作を拒否 | `409 already_authenticated` |
| `auth:sanctum` | Sanctumによるsession認証 | `401 unauthenticated` |
| `api.verified` | `MustVerifyEmail`対象userのverificationを要求 | `403 email_unverified` |
| `api.password.confirm` | sessionのpassword確認時刻を検証 | `423 password_confirmation_required` |

Laravel標準の`guest`は認証済みuserへ302 redirectするためAPI routeでは使用しない。標準`verified`と`password.confirm`はstatusの考え方を踏襲するが、安定した`code`を返すAPI専用middlewareに置き換える。認可はPolicy/Gate、入力検証はFormRequestに置き、controllerへ同じ判定を重複させない。

API routeの基本順序は、Sanctum stateful/session/CSRF、route binding、route固有のguest/auth/verified/password-confirm/throttle、FormRequest、controllerとする。署名付きメール認証callbackだけはAPI group外の`web` routeで、`signed`をauth redirectより前に評価する。

#### 5.5.2 endpoint別middleware

| endpoint | middleware |
| --- | --- |
| `POST /api/auth/register` | `api.guest`, `throttle:6,1` |
| `POST /api/auth/login` | `api.guest` + `LoginRequest`のrate limit |
| `POST /api/auth/forgot-password` | `api.guest`, `throttle:6,1` |
| `POST /api/auth/reset-password` | `api.guest`, `throttle:6,1` |
| `GET /api/user` | `auth:sanctum` |
| `POST /api/auth/logout` | `auth:sanctum` |
| `POST /api/auth/email/verification-notification` | `auth:sanctum`, `throttle:6,1` |
| `POST /api/auth/confirm-password` | `auth:sanctum`, `throttle:6,1` |
| `GET/PATCH /api/profile` | `auth:sanctum` |
| `PUT /api/profile/password` | `auth:sanctum`。bodyの`current_password`を検証 |
| `DELETE /api/profile` | `auth:sanctum`。bodyの`password`を検証 |
| verification必須の業務API | `auth:sanctum`, `api.verified` |
| password再確認が必要な業務API | `auth:sanctum`, `api.verified`, `api.password.confirm` |

password更新とaccount削除はrequest bodyで現在のpasswordを検証するため、`api.password.confirm`を重ねない。

#### 5.5.3 エラー形式の一元化

API専用middleware、`withExceptions()`、将来の型付き例外rendererは、同じ`ApiErrorResponse`生成クラスを利用する。各middleware内で`response()->json()`の配列を個別に組み立てない。

```text
ApiErrorResponse
  make(code, message, httpStatus, errors = [], headers = [])
    -> JsonResponse

初期: middleware ---------------------> ApiErrorResponse
      framework exception renderer ---> ApiErrorResponse

将来: middleware -> typed exception
                    -> renderer ------> ApiErrorResponse
```

共通生成クラスは次だけを担当する。

- 5.1の`code`, `message`, optional `errors`形式を生成する。
- 204では呼び出さない。
- 429の`Retry-After`など、受け取ったheaderを失わず返す。
- productionで内部例外情報を公開しない。

HTTP statusとcodeの対応は定数またはenum相当の一か所で管理し、middlewareと例外ハンドラに文字列を重複させない。SPAはHTTP statusとcodeだけに依存し、Laravelのmiddleware/例外クラス名には依存しない。

framework exceptionは`bootstrap/app.php`の`withExceptions()`から同じ生成クラスへ変換する。

| exception | status/code |
| --- | --- |
| `AuthenticationException` | `401 unauthenticated` |
| `AuthorizationException` | `403 forbidden` |
| `TokenMismatchException` | `419 csrf_token_mismatch` |
| `ValidationException` | `422 validation_failed` + Laravel標準`errors` |
| `ModelNotFoundException` | `404 not_found` |
| `ThrottleRequestsException` | `429 rate_limited` + `Retry-After` |
| その他の非公開例外 | `500 internal_error` |

#### 5.5.4 型付き例外方式への移行条件

次のいずれかが発生するまでは初期方式を維持する。

- 同じ状態エラーをmiddleware以外のservice/domain層からも返す必要がある。
- 業務固有error codeが増え、複数controller/middlewareで共有される。
- API以外のconsumerや非同期処理でも同じ失敗型を扱う。

移行時は`AlreadyAuthenticatedException`, `EmailUnverifiedException`, `PasswordConfirmationRequiredException`等を追加し、middlewareの`return ApiErrorResponse...`を`throw`へ置き換える。既存renderer、response contract test、Vue側処理は維持する。型付き例外への移行をInertia撤去と同時には行わない。

### 5.6 frontend test toolchain

| layer | tool | 主な責務 |
| --- | --- | --- |
| unit | Vitest | API client、auth store、form composable、error code mapping |
| component | Vue Test Utils 2 + jsdom + Vitest | form入力、processing、422 field error、focus、component event |
| router | Vue Router 4 `createMemoryHistory()` + Vitest | guest/auth/verified/password-confirmed guard、route param/query、not-found、intended URL |
| E2E | Playwright | Nginx 単一入口、Cookie/CSRF、direct access/reload、mail link、主要業務フロー |
| UI 回帰 | Playwright screenshot | 主要画面の responsive/state/font 差分 |

Playwright は Vite/Laravel container に直接接続せず、必ず Nginx の公開入口を `baseURL` とする。初期 CI は version 固定した Chromium だけを必須とし、Firefox/WebKit は必要になった時点で追加する。失敗時の trace/screenshot は CI artifact として保存する。E2E は専用 test database を使い、開発データを更新しない。

unit/component test では必要に応じて `fetch` を Vitest で stub し、初期移行では MSW 等の追加 mock package を増やさない。UI screenshot は自己ホスト Figtree、browser image、viewport、locale/timezone、animation 制御を固定して差分のぶれを抑える。

frontend script は少なくとも `test` = `vitest`, `test:run` = `vitest run`, `test:e2e` = `playwright test` を持つ。Vitest と Playwright の test 配置/命名規則を分け、互いの test file を重複収集しないようにする。

### 5.7 frontend TypeScript/ESLint 方針

新 SPA は TypeScript を初期状態とし、`frontend/src` に JavaScript の未型付け source を残さない。entry/router/API/store/composable/test は `.ts`、Vue SFC は `<script setup lang="ts">` を使う。旧 Inertia component は `resources/js` にある間は変更せず、新 SPA へ移植するときに props、emits、model、DOM ref/event、API data を型付けする。

TypeScript 設定は `frontend/tsconfig.json` を入口とし、browser app と Node 上の Vite/Vitest/Playwright config を `tsconfig.app.json`, `tsconfig.node.json` で分離する。`strict: true`, `noEmit: true`, `moduleResolution: "Bundler"`, `skipLibCheck: true` を初期契約とし、`@/*` を `frontend/src/*` に向ける。`noUncheckedIndexedAccess` と `exactOptionalPropertyTypes` は移行完了後に別途評価する。`allowJs` で新 SPA の未型付け JavaScript を恒久的に許容しない。

API response は `unknown` から扱い、user、API error、validation errors、pagination/meta 等の共通型を `frontend/src/types` に集約する。TypeScript の type assertion は runtime validation の代替にならないため、Laravel response contract test、API client test、必要最小限の runtime guard を併用する。Zod 等の追加 schema package は初期対象に含めない。

ESLint は flat config の `frontend/eslint.config.js` を使い、JavaScript/TypeScript/Vue の recommended rule を基本とする。型の正しさは `vue-tsc`、構文・Vue template・未使用コード等は ESLint を正とし、同じ検査を重複設定しない。初期は type-aware ESLint rule、Prettier、フォーマット全面変更を追加しない。generated output、coverage、Playwright artifact は lint 対象から外す。

frontend script は `lint` = `eslint . --max-warnings=0`, `typecheck` = `vue-tsc --noEmit` を持つ。CI では lint/typecheck/Vitest/build/Playwright を別 command とし、build script の内部に typecheck を隠蔽しない。

## 6. 固定する移行順序

順序の入れ替えは禁止する。特に、Inertia の依存削除と Nginx の SPA fallback 切替は、API と SPA が完成する前に行わない。

### Phase 0: 契約を固定する

1. 本文書の API path、status、error schema、認証方式をレビューして確定する。
2. 現在の feature test が担保している認証・profile の振る舞いを一覧化する。
3. password reset mail は 5.3、email verification は 5.4 の URL 契約を使用する。

完了条件: API 契約と URL 対応がレビュー済みで、実装中に endpoint 名を変更しない状態。

### Phase 1: Laravel API の土台を追加する（Inertia は維持）

1. `routes/api.php` を作り、`bootstrap/app.php` から読み込む。
2. Sanctum の stateful SPA middleware と `auth:sanctum` を設定する。
3. 5.1 の JSON response/resource、例外、422 error schema と error codeを実装する。
4. 5.5の`ApiErrorResponse`、API専用middleware、framework exception mappingを実装する。
5. `/api/user` と CSRF/login/logout の API feature testを先に作る。

完了条件: 既存 Inertia 画面を壊さず、Cookie + CSRF で API login、`/api/user`、logout が通る。

### Phase 2: mutation とデータ取得を API 化する（Inertia は維持）

1. register、password reset、verification mail 再送、password confirmation を API 化する。password reset notification は `ResetPassword::createUrlUsing()` で 5.3 の SPA URL を生成する。
2. `/auth/email/verify/{id}/{hash}` の署名付き Web コールバックと、login API の安全な `redirect_to` 復元を実装する。
3. profile 取得/更新、password 更新、account 削除を API 化する。
4. controller から redirect/back/flash 依存を除いた JSON action を分離する。ただし 5.4 の callback redirect は例外とする。
5. 全 endpoint と callback に成功、422、401/403、署名不正/期限切れ/user mismatch、throttle の feature test を追加する。

完了条件: Inertia client を使わず HTTP request だけで全ユースケースを完了できる。既存テストもまだ成功する。

### Phase 3: Vue SPA の shell を追加する（まだ本番入口を切り替えない）

1. `frontend/` に Node manifest、静的 `index.html`、TypeScript の Vue entry/Vite config、SPA router、API client、auth store/form composable を作る。
2. `/`, `/login`, `/register`, `/forgot-password`, `/reset-password/:token`, `/verify-email`, `/confirm-password`, `/dashboard`, `/profile` を router に登録する。
3. `frontend/tsconfig.json`, `frontend/tsconfig.app.json`, `frontend/tsconfig.node.json`, `frontend/env.d.ts` を追加する。`@/*` は `frontend/src/*` に向け、ルート `jsconfig.json` の `ziggy-js` alias は移植しない。`frontend/vite.config.ts` の runtime alias も同じ `frontend/src` に合わせる。
4. `frontend/postcss.config.js`, `frontend/tailwind.config.js`, `frontend/src/assets/app.css` を追加し、Tailwind CSS 3、PostCSS、Autoprefixer、`@tailwindcss/forms` を移植する。content scan は `frontend/index.html` と `frontend/src/**/*.{ts,vue}` に限定する。
5. Figtree の WOFF2（400/500/600、または同範囲の variable font）と license を `frontend/src/assets/fonts/` に置く。`app.css` に `@font-face` と `font-display: swap` を定義し、`frontend/index.html` の body に `font-sans antialiased` を適用する。Bunny Fonts の `link`/`preconnect` は移植しない。
6. `@tailwindcss/vite` は `frontend/package.json` と `frontend/vite.config.ts` に入れない。CSS は `@tailwind base;`, `@tailwind components;`, `@tailwind utilities;` を PostCSS 経由で処理する。
7. `frontend/eslint.config.js` を追加し、`npm run lint` と `npm run typecheck` が空の shell/entry の段階から成功するようにする。
8. `Link`, `Head`, Ziggy `route()`, page props を使わない TypeScript の shell と navigation を作る。
9. `compose.yaml` に専用 `frontend` service を追加し、engine 確認後の正確な `node:22.<patch>-bookworm-slim` で Vite を Docker network 内の `frontend:5173` に起動する。`NODE_VERSION` に major/minor だけの値を設定せず、Vite port は host へ公開しない。
10. development Nginx から通常画面と HMR WebSocket を Vite へ、API/CSRF/callback/healthcheck を Laravel へ転送する。Vite に API proxy は設定しない。
11. `frontend/vitest.config.ts`、component test setup、`frontend/playwright.config.ts`、TypeScript の unit/component/router/E2E test の格納先を作る。
12. version を `@playwright/test` と一致させた専用 `frontend-e2e` service を Compose test profile に追加し、Nginx と専用 test database の ready 後に実行する。

完了条件: Nginx の公開 URL から新 SPA を開け、reload/direct access、HMR、title、404、guest/auth guard が動作する。Laravel service で npm process は動作していない。

### Phase 4: 画面を新 SPA へ移植する

移植順も固定する。

1. public/static: Welcome
2. guest auth: Login、Register、ForgotPassword、ResetPassword
3. authenticated shell: Dashboard、navigation、Logout
4. auth state: VerifyEmail、ConfirmPassword
5. profile: profile 更新、password 更新、account 削除

各画面で `useForm` を型付き API form に、`Link` を router link/action に、props を route/API/auth store に置換する。移植する Vue SFC はその単位で `<script setup lang="ts">` にし、implicit `any`、無検証の API cast、恒久的な `@ts-ignore` を残さない。

完了条件: 3.4 の全操作について SPA の lint/typecheck/component/E2E test と Laravel API test が成功し、ブラウザ上で 422 error が各 field に表示される。加えて、旧 Inertia 画面と新 SPA の UI 回帰確認が完了し、responsive、hover/focus/disabled、form control、dynamic class に実用上の差分がない。この条件を満たすまで Phase 5 へ進まない。

### Phase 5: Nginx の入口を SPA/API に切り替える

着手条件: CI 製品を確定し、frontend artifact の job 間受け渡し、Docker layer/dependency cache、Playwright artifact、Nginx image build の実装先を決める。GitHub 管理の repository では、特段の制約がない限り GitHub Actions を選ぶ。

1. `/api/*`, `/sanctum/*`, `/auth/email/verify/*`, `/storage/*`, `/up` を Laravel に proxy する。
2. `frontend/dist` を build artifact として生成し、production Nginx image にコピーして配信する。`dist` は Git に commit しない。
3. `/assets/*`を長期immutable cacheで配信し、missing assetは404にする。
4. `index.html`をno-cacheとし、その他の画面GETを`index.html`にfallbackする。
5. forwarded headers、Cookie、request body、upload limit、cache policyを確認する。

完了条件: Nginx の公開 URL だけで、direct access/reload、login、logout、CSRF、全フォーム、reset/verification mail link が動く。Laravel の Inertia 画面へ通常アクセスが流れない。

### Phase 6: Inertia と旧画面 route を撤去する

この Phase で行うファイル削除と package 削除は、対象 diff を提示して明示的な承認を得てから実施する。

1. `routes/web.php` と `routes/auth.php` から通常画面 GET と旧 mutation route を削除する。5.4 の署名付き Web コールバックは `web` route として残す。
2. controller の `Inertia::render` action/import を削除する。
3. `HandleInertiaRequests` の登録とファイル、`app.blade.php` を削除する。
4. Vue の `@inertiajs/vue3` import、`createInertiaApp`, `resolvePageComponent`, `useForm`, `usePage`, `$page.props`, Inertia `Link/Head` をなくす。
5. 用途がなくなったルート `jsconfig.json`, `tailwind.config.js`, `postcss.config.js`, `resources/css/app.css` を撤去する。新 SPA 側の対応設定/CSS/font が build と UI 回帰 test で確認済みであることを先に確認する。
6. 用途がないルート `@tailwindcss/vite` 依存を削除する。これは Tailwind CSS 4 化ではなく、未使用依存の整理とする。
7. `@routes` と frontend の Ziggy `route()` をなくす。
8. 明示的な承認を得てから、ルート Node manifest と Composer から `@inertiajs/vue3`、`inertiajs/inertia-laravel`、不要になった Ziggy/Laravel Vite plugin 等を削除し lock file を更新する。ルート Node manifest に用途が残らなければファイル自体を撤去する。
9. Inertia 前提の旧 test を API/SPA test に置換する。

完了条件: 10.1 の runtime source gate、10.2 の dependency gate、10.3 の Laravel route gate がすべて成功する。

### Phase 7: 回帰確認と整理

1. Laravel API test、Vue component test、SPA build を実行する。
2. production 相当 Nginx で route reload と認証フローを確認する。
3. `composer show`、`npm --prefix frontend ls`、bundle を確認し Inertia/Ziggy の残存を検査する。
4. CI で Laravel API test と frontend install/test/build を独立 job にし、Nginx image job が frontend artifact を受け取る構成にする。
5. README の起動 URL、Vite/Nginx/Compose の説明、test command を更新する。

完了条件: 10 章の全 Gate が成功し、「画面配信なしの Laravel API」と「Laravel view に依存しない Vue SPA」を個別に検証できる。

### 移行完了後の後続変更: Tailwind CSS 4

Tailwind CSS 4 化は Phase 0–7 と 10 章の完了判定に含めない。SPA 切替完了後に別 branch/PR で行い、Tailwind CSS 3 時点の build、UI 回帰 test、主要画面の表示を baseline にする。その際に v4 の CSS/config/content detection 方式を評価し、`@tailwindcss/vite` を使うか PostCSS 構成を継続するかを改めて決める。

## 7. 変更対象一覧

### Laravel API

| 種類 | 対象 |
| --- | --- |
| 変更 | `bootstrap/app.php`, `app/Http/Controllers/Auth/*`, `app/Http/Controllers/ProfileController.php`, auth/profile FormRequest、認証関連 provider/config |
| 新規 | `routes/api.php`, API controller/resource、`ApiErrorResponse`、`api.guest`/`api.verified`/`api.password.confirm` middleware、API feature/contract tests |
| 最終削除 | `app/Http/Middleware/HandleInertiaRequests.php`, `resources/views/app.blade.php`, Inertia 専用 controller action/import |
| 整理 | `routes/web.php`, `routes/auth.php`。通常画面 route は削除し、`/auth/email/verify/{id}/{hash}` の署名付き Web コールバックを残す |

### Vue SPA / Vite

| 種類 | 対象 |
| --- | --- |
| 新規 | `frontend/index.html`, `frontend/public`, `frontend/src/assets`, `frontend/src/assets/fonts`, `frontend/vite.config.ts`, `frontend/vitest.config.ts`, `frontend/playwright.config.ts`, `frontend/tsconfig.json`, `frontend/tsconfig.app.json`, `frontend/tsconfig.node.json`, `frontend/env.d.ts`, `frontend/eslint.config.js`, `frontend/postcss.config.js`, `frontend/tailwind.config.js`, `frontend/src/main.ts`, router, API client, auth store/composable, API form composable, route guard, unit/component/router/E2E tests |
| package | `frontend/package.json`, `frontend/package-lock.json`。Vue Router 4、TypeScript/`vue-tsc`、ESLint/Vue/TypeScript plugin、Vitest、Vue Test Utils 2、jsdom、Playwright、Tailwind CSS 3/PostCSS/Autoprefixer/forms plugin を含め、`@tailwindcss/vite` は含めない。新規 package の追加は承認後に実施 |
| Node runtime | `node:22.<patch>-bookworm-slim`。正確な patch を `.env.example` の `NODE_VERSION`、Compose、CI、`package.json` engines で一致させ、起動後に Node/npm version を検証 |
| 設定移管 | ルート `jsconfig.json` の alias を新 SPA の `tsconfig*.json` と `vite.config.ts` に再定義し、`ziggy-js` は移さない。ルート `tailwind.config.js`, `postcss.config.js` も新 SPA 用に再定義する。`@/*` は `frontend/src/*`、Tailwind content は `frontend/index.html` と `frontend/src` だけを対象とする |
| CSS/font 移管 | `resources/css/app.css` を `frontend/src/assets/app.css` へ移植し、`frontend/src/main.ts` から import。`app.blade.php` の Bunny Fonts 参照は移さず、Figtree WOFF2/license と `@font-face` に置き換える |
| TypeScript 移植 | `resources/js/Layouts`, `resources/js/Components`, `resources/js/Pages` は旧配置では JavaScript のまま維持。`frontend/src` へ画面単位で移植するときに `.ts`/`<script setup lang="ts">` 化し、Inertia API を外す |
| 最終削除 | 旧 `resources/js`, `resources/css/app.css`、ルート `vite.config.js`, `jsconfig.json`, `tailwind.config.js`, `postcss.config.js`、用途がなくなったルート Node manifest。Inertia `Link/Head/useForm/usePage`、Ziggy `route()`、page props 前提を残さない |

### 配信/運用

| 種類 | 対象 |
| --- | --- |
| 新規 | `docker/nginx/development.conf`, `docker/nginx/production.conf`, production Nginx image/build定義 |
| 変更 | `compose.yaml`, `.env.example`, `.gitignore`, `README.md`。Vite process を専用 `frontend` service へ移し、Vite host port 公開を廃止する |
| 確認 | Nginx 単一入口、Sanctum stateful/CSRF Cookie、HMR WebSocket、`node:22.<patch>-bookworm-slim`、`frontend/dist` artifact、dev/prod の Nginx config、healthcheck、cache header |

### CI

現時点ではリポジトリに CI 設定ファイルは存在しない。CI 製品は Phase 4 完了まで保留できるが、Phase 5 着手前に確定する。repository が GitHub 管理なら GitHub Actions を第一候補とする。導入先が決まってから製品固有の設定ファイルを追加するが、job の責務とローカル実行 command は次で固定する。

| job | 入力/command | 成果物 |
| --- | --- | --- |
| backend | Composer install、Laravel API test、static analysis/format check | test result |
| frontend-test | `npm ci`、`npm run lint`、`npm run typecheck`、`npm run test:run`（すべて `frontend/` が working directory） | lint/typecheck/Vitest result |
| frontend-build | `npm ci`, `npm run build`（`frontend/` が working directory） | `frontend/dist` |
| frontend-e2e | Nginx + Laravel + dedicated test database 起動後に `npm run test:e2e`。初期は Chromium | E2E result、失敗時 trace/screenshot |
| nginx-image/integration | frontend build artifact と production Nginx config を使用 | deployable Nginx image、route smoke test result |

backend と frontend の dependency cache key、working directory、失敗判定を分離する。production Nginx image は CI で生成された `frontend/dist` だけを受け取り、CI 外で再度 Node dependency を解決しない。Phase 1–4 では product-specific context、secret syntax、artifact API を application/Compose/package script に埋め込まない。

### テスト

| 現在 | 移行 |
| --- | --- |
| `tests/Feature/Auth/*` の画面 render/redirect/session error assertion | API の JSON/status/error assertion と、SPA の route/component test に分割 |
| `tests/Feature/ProfileTest.php` の `/profile` redirect/session error assertion | `/api/profile` の resource/204/422 assertion |
| `tests/Feature/ExampleTest.php` の Laravel `/` 200 | Nginx/SPA smoke test と Laravel `/up`/API smoke testに分割 |
| なし | Laravel旧画面routeの不存在を完全一致で検査するarchitecture testを追加 |
| なし | Nginx経由のSPA/API境界と主要業務フローを検査するintegration/E2E testを追加 |

## 8. 機能別の移管内容

### 画面遷移

`Inertia Link` と server redirect から Vue Router に移す。認証成功後の intended URL は Vue 側で保持し、Laravel は user JSON だけを返す。ブラウザから直接 `/profile` を開いた場合、Nginx が `index.html` を返し、Vue Router が auth 初期化完了後に profile を表示または `/login` へ送る。

### データ取得

page props を廃止し、画面に必要な時点で API を呼ぶ。global `auth.user` は auth store、profile 固有の `mustVerifyEmail` 等は profile response の `meta` に置く。`canLogin`/`canRegister` のような UI feature flag は frontend config または公開 config endpoint に移す。

### 送信

Inertia `useForm` が暗黙に行っていた method、processing、error、成功検知を SPA の共通 form composable で明示する。Laravel は redirect/back を返さず、作成は 201、更新結果ありは 200、body なしは 204 を使う。202 は実際に queue へ投入し処理完了を待たない場合だけ使用する。成功後の画面移動と success message は Vue が担当する。

### 認証

SPA 起動時に `/api/user` を呼び、200 なら authenticated、401 なら guest とする。状態変更前には Sanctum の CSRF Cookie を取得し、すべての API request で `Accept: application/json` と credential を送る。標準 `fetch` を使う場合は、URL decode した `XSRF-TOKEN` Cookie の値を `X-XSRF-TOKEN` header に明示的に設定する。Laravel は session fixation 対策の session regeneration、logout 時の invalidation/token regeneration を引き続き担当する。

### validation error

Laravel の validator/FormRequest が唯一の正規ルールを持つ。SPA の検証は操作性向上に限り、Laravel の検証を代替しない。422 response の `code` は `validation_failed` とし、`errors[field][0]` を各 `InputError` に渡し、入力変更時に該当 field error を消す。401、403、419、429、500 は field error と混ぜず、auth 回復、CSRF 再取得、rate-limit message、global error として別処理する。

## 9. 切替時の主なリスク

- `routes/web.php` の GET を先に消すと、password reset/verification mail の既存 URL が失効する。reset notification が 5.3 の SPA URL、verification notification が 5.4 の callback を使うことを確認してから旧 route を消す。
- password reset URL の token/email が access log、Referer、analytics、error tracking へ流れると、有効期限内の account takeover と個人情報漏洩につながる。reset route はログ除外/マスクし、外部リソースと URL 自動収集を禁止する。
- Nginx が `/auth/email/verify/*` を SPA fallback へ送ると署名検証が実行されない。API と同様、SPA fallback より優先する。
- callback の intended URL を無制限に login API から返すと open redirect になる。同一 origin と path prefix を検証する。
- `APP_URL`、proxy header、実際の scheme/host が一致しないと absolute signed URL が無効になる。
- Nginx の SPA fallback を `/api` より広く先に評価すると、API の 404/401 が `index.html` の 200 に化ける。
- missing `/assets/*`や`/storage/*`をSPA fallbackへ送ると、画像/JSの404が`index.html`の200に化ける。それぞれ専用locationをfallbackより優先する。
- API client が `Accept: application/json` を付けないと、未認証や validation failure が login/back redirect になり得る。
- Laravel標準`guest`をAPIに使うと認証済みrequestが302になる。API routeでは`api.guest`だけを使用する。
- middlewareごとにerror JSONを直書きするとcode/message/headerがずれ、型付き例外方式へ移行しにくくなる。必ず共通`ApiErrorResponse`を使用する。
- session cookie と CSRF を維持せず token auth へ同時変更すると、責務分離と認証方式変更が重なり回帰範囲が広がる。
- profile 更新後に auth store を更新しないと、layout の user name/email が古いまま残る。
- `useForm` 削除時に `processing`, `reset`, `recentlySuccessful`, focus 制御を落とすと、機能は通っても UI 回帰になる。
- 旧 Inertia component を先に TypeScript 化すると、旧画面の回帰と新 SPA 移植の差分が混在する。旧配置は JavaScript のまま固定し、新配置へ移す単位だけ型付けする。
- DOM ref、event、Vue Router params/query、dynamic validation error は strict mode で null/union/index error になりやすい。型無視で回避せず、共通正規化関数と型付き composable に集約する。
- API response を type assertion だけで信頼すると Laravel の実 response と乖離しても `vue-tsc` で検出できない。contract test と runtime guard を残す。
- ESLint と TypeScript で同じ検査を二重化すると設定/エラーが増える。初期は non-type-aware ESLint + `vue-tsc` に責務を分ける。
- `node:22` や `node:22-bookworm-slim` を使うと pull 時期で Node patch/image が変わり、local/CI の結果がずれる。`NODE_VERSION` は必ず正確な patch を持たせる。
- Node 22 は 2027 年4月に EOL 予定のため、SPA 移行完了後に Node 24 LTS 更新を別変更として計画する。TypeScript/Inertia/Tailwind 移行と Node major 更新を同時に行わない。
- direct access と browser reload を component 内 navigation だけで試すと、Nginx fallback の不備を見落とす。

このため、API の並行追加、SPA の並行構築、入口の切替、旧経路/依存の撤去という順序を維持する。

## 10. 移行完了判定（Definition of Done）

単一の文字列検索だけでは移行完了としない。次の6 Gateをすべて成功させ、CIでも同じ判定を再現できることを完了条件とする。

| Gate | 確認対象 | 合格条件 |
| --- | --- | --- |
| 1 | runtime source | Inertia固有APIとfrontend Ziggy参照が0件 |
| 2 | dependency | 全manifest/lockからInertia、Ziggy、旧Laravel Vite plugin、未使用Tailwind Vite pluginが消え、新frontend toolchainが解決できる |
| 3 | Laravel route | 旧画面/mutation routeがなく、署名付きcallbackとAPIだけが残る |
| 4 | automated test/build | Laravel API、ESLint、`vue-tsc`、Vitest、production build、Playwright、Nginx構文検査がすべて成功 |
| 5 | Nginx integration | SPA fallback、API境界、CSRF、callback、HMRが正しい |
| 6 | business flow | 認証、メール、profile、error処理の主要フローが成功 |

### 10.1 Gate 1: runtime source

設計書やlock fileではなく、実行コードだけを対象にする。`useForm(` 単独は独自composableを誤検出するため検索せず、置換後のcomposableは`useApiForm`と命名する。

```bash
if [ ! -d frontend ]; then
  echo 'frontend directory is missing'
  exit 1
fi

rg -n \
  -g '!vendor/**' \
  -g '!node_modules/**' \
  -g '!storage/**' \
  -g '!bootstrap/cache/**' \
  -e '@inertiajs' \
  -e 'createInertiaApp' \
  -e 'resolvePageComponent' \
  -e 'laravel-vite-plugin/inertia-helpers' \
  -e 'Inertia::' \
  -e 'use Inertia\\' \
  -e 'HandleInertiaRequests' \
  -e '@inertiaHead' \
  -e '@inertia\b' \
  -e '\$page\.props' \
  -e 'usePage\(' \
  app bootstrap config routes resources frontend && {
  echo 'Inertia runtime references remain'
  exit 1
}

rg_status=$?
if [ "$rg_status" -ne 1 ]; then
  exit "$rg_status"
fi
```

frontendのZiggy参照は別に確認する。

```bash
rg -n \
  -e '@routes' \
  -e 'ziggy-js' \
  -e 'ZiggyVue' \
  -e '\broute\(' \
  resources frontend/src && {
  echo 'Frontend Ziggy references remain'
  exit 1
}

rg_status=$?
if [ "$rg_status" -ne 1 ]; then
  exit "$rg_status"
fi
```

外部 font 参照も runtime source から除去する。

```bash
rg -n \
  -e 'fonts\.bunny\.net' \
  -e 'fonts\.googleapis\.com' \
  resources/views frontend/index.html frontend/src && {
  echo 'External font references remain'
  exit 1
}

rg_status=$?
if [ "$rg_status" -ne 1 ]; then
  exit "$rg_status"
fi
```

### 10.2 Gate 2: dependency

lock fileは除外せず、残存確認の対象にする。root Node manifestを撤去した場合も、file globで存在するmanifest/lockだけを検索する。

```bash
rg -n \
  -g '!vendor/**' \
  -g '!node_modules/**' \
  -g 'composer.json' \
  -g 'composer.lock' \
  -g 'package.json' \
  -g 'package-lock.json' \
  -e 'inertiajs/inertia-laravel' \
  -e '@inertiajs/vue3' \
  -e 'tightenco/ziggy' \
  -e 'laravel-vite-plugin' \
  -e '@tailwindcss/vite' \
  . && {
  echo 'Legacy dependencies remain'
  exit 1
}

rg_status=$?
if [ "$rg_status" -ne 1 ]; then
  exit "$rg_status"
fi
```

各検索は`rg`の終了code `1`（matchなし）だけを成功とする。`2`以上のfile/regex/permission errorをmatchなしとして扱わない。

### 10.3 Gate 3: Laravel route

Laravel route collectionをarchitecture testから検査する。URIは部分一致ではなくmethodとURIの完全一致で判定し、`/profile`と`/api/profile`を区別する。

Laravelから削除されている必要があるrouteは次のとおり。

- `GET /`, `/dashboard`, `/profile`, `/login`, `/register`, `/forgot-password`, `/reset-password/{token}`, `/verify-email`, `/confirm-password`
- 3.4に記載した旧`POST`, `PATCH`, `PUT`, `DELETE` route

Laravelに残すbrowser向けWeb routeは`GET /auth/email/verify/{id}/{hash}`だけとする。このほかに`/api/*`, `/sanctum/csrf-cookie`, `/up`が登録されていることを検査する。`artisan route:list --except-vendor`はレビュー用にも保存する。

### 10.4 Gate 4: automated test/build

最低限、次をすべて成功させる。

```bash
./vendor/bin/sail artisan test
docker compose exec frontend node --version
docker compose exec frontend npm --version
docker compose exec frontend npm run lint
docker compose exec frontend npm run typecheck
docker compose exec frontend npm run test:run
docker compose exec frontend npm run build
docker compose run --rm frontend-e2e npm run test:e2e
docker compose exec nginx nginx -t
```

テスト対象にはLaravel API feature/response contract/route boundary、Vue component/router guard/API client/422 mappingを含める。`node --version` は `.env.example` の正確な `NODE_VERSION`、`npm --version` は固定 image に含まれる期待 version と一致させる。build後に`frontend/dist/index.html`と`frontend/dist/assets/*`が存在することも検査する。また、`npm --prefix frontend ls tailwindcss` で 3.x が解決され、`@tailwindcss/vite` が frontend dependency/bundle に入っていないことを確認する。`@/` alias の component import が lint/typecheck/test/build のすべてで解決でき、`frontend/src` に `.js` と `lang="ts"` のない script setup を残さない。build 後の `frontend/dist/assets` に hashed WOFF2 が存在し、生成 CSS がその URL を参照することも検査する。

### 10.5 Gate 5: Nginx integration

開発時とproduction相当構成の両方で、Nginx公開URLから次を検査する。

| request | 期待結果 |
| --- | --- |
| `GET /` | SPA `index.html`, `200`, HTML content type |
| `GET /profile` | SPA `index.html`, `200`。direct access/reload可能 |
| `GET /reset-password/{token}?email=...` | SPA `index.html`, `200`。Laravel 画面/callback へ転送しない |
| `GET /存在しない画面` | SPAがVue側のnot-foundを表示 |
| 未認証`GET /api/user` | JSON `401`, code=`unauthenticated` |
| `GET /api/存在しないAPI` | JSON `404`, code=`not_found`。`index.html`を返さない |
| `GET /sanctum/csrf-cookie` | CSRF Cookie発行 |
| 存在する`GET /assets/{hashed-file}` | assetを200で返し、immutable cache headerあり |
| 存在する`GET /assets/{hashed-font}.woff2` | Figtree を200で返し、正しい font content type と immutable cache headerあり |
| 存在しない`GET /assets/{file}` | 404。`index.html`を返さない |
| 存在しない`GET /storage/{file}` | Laravel/storage側の404。`index.html`を返さない |
| 不正/期限切れ署名callback | 規定のSPA error URLへredirectし、verificationしない |
| development HMR | Nginx経由でWebSocket接続 |

Nginx healthcheckはLaravelの`/up`だけでなく、SPA rootまたは専用Nginx health endpointも確認し、backendだけが正常な状態を全体正常と判定しない。

### 10.6 Gate 6: business flow

component/API testだけでなく、Nginx公開URLを使うintegration/E2E testで次を確認する。

- register、login成功/失敗、session維持、`GET /api/user`、logout
- guest/auth/verified/password-confirmedのrouter guardとAPI error code
- verification mail再送、署名付きverification、未ログイン時のlogin後callback復帰
- password reset mail の host/path/token/email encode、SPA reset URL の direct access/reload、正常・不正・期限切れ・使用済みtoken、password reset、password確認
- profile/password更新、account削除、更新後のauth store同期
- 422 field error、419 CSRF再取得、429 rate-limit、500 global errorの表示
- 各SPA routeのdirect accessとbrowser reload

### 10.7 最終承認条件

6 Gateの結果、変更対象diff、残存route/dependency一覧を提示し、次をすべて満たした状態で移行完了とする。

- Inertia runtime参照0件、Inertia/Ziggy/旧Laravel Vite plugin依存0件
- Laravel通常画面route0件、旧mutation route0件
- 署名付きメール認証Web callbackを維持
- Laravel/Vue test、frontend build、Nginx構文/経路testが成功
- frontend の ESLint、`vue-tsc --noEmit`、Vitest、Playwright が成功し、新 SPA source は TypeScript strict mode で型付け済み
- frontend は正確な `node:22.<patch>-bookworm-slim` で実行され、local/CI の Node/npm version が一致
- frontend は Tailwind CSS 3 + PostCSS で build され、UI 回帰確認が成功、`@tailwindcss/vite` 依存は残存しない
- Figtree は license とともに自己ホストされ、hashed `/assets/*` から配信、SPA の外部 font 通信は 0 件
- 認証、メール、profileの主要フローが成功
- README、Compose、CIが新構成と一致
- 旧Inertia画面へ戻すfallbackが通常経路に存在しない
