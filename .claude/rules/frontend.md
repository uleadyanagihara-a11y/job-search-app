---
paths:
  - "frontend/**"
---

# SPA（`frontend/`）の規約

npm は Compose の `frontend` service でだけ実行する（コマンドは `CLAUDE.md`、環境の詳細は `docs/development.md`）。
この文書の path（`src/...`・`e2e/...`・`.npmrc`・`audit-accepted.json`・`vite.config.ts`）は `frontend/` からの相対。

## API とフォーム

- API の呼び出しは `src/api/<resource>.ts` に関数で置き、response は `src/types/api.ts` の型 guard（`isXxxResponse`）を `expectShape()` に通してから型を付ける。field を足したら guard も直す。
- form は `useApiForm`。入力欄は文字列で持ち、API の型（`null`・数値）への変換は送る直前に行う。
  422 の key は data の key と一致したものだけ field の下に出し、それ以外（field 名のずれ、配列の `items.0` 等）は `error`（form 上部の alert）に回す。
  data にあっても field の下に error を出さない key（Login の `remember`）は `fieldsWithoutError` に書く。
- 取得してから form を初期化する画面は、view が読み込み・失敗・再読み込みを持ち、form は取得した値を props で受ける子 component にする（`ProfileView`・`SeekerProfileView`）。

## route と画面の構成

- route の `meta.roles` で表示するロールを絞る（合わなければ guard が dashboard へ送る）。nav の項目にも同じ `roles` を書く。データの保護は API の Policy が担う。
- view・component の名前は 2 語以上（ESLint の `vue/multi-word-component-names`）。画面は `src/views/<画面>/XxxView.vue`、画面専用の部品は同じ directory に置く。

## 言語（日本語のみ。`docs/domain-design.md` 14 章）

- 多言語化はしない。文言は vue-i18n を使わず、`.vue`・route の `meta.title` に直接日本語で書く。
- API の `message` に依存せず、`code` で分岐する。

## 見た目（デザインを後から差し替えるため。`docs/domain-design.md` 12 章）

- 色は `src/assets/app.css` の token（`canvas`・`surface`・`fg-*`・`line-*`・`emphasis`・`primary-*`・`danger-*`・`success-*`）だけを使う。
  Tailwind の palette（`gray-*`・`indigo-*`・`red-*` 等）を部品・画面に直接書かない。足りない色は、先に token を足す（意味の名前を付ける）。
- 新しい画面（M2 以降）は、カード・ページ見出し・バッジ・表・空の状態などを `src/components/` の部品にし、画面ファイル（`views/`）には配置（grid・余白）以外の見た目を書かない。
  既存の画面（Breeze 由来）は、触るときに部品へ寄せる。
- 開閉などの動きは既存の部品（`Dropdown`・`Modal` 等）に任せる。部品が付けている `aria-*` を外さない。
- Figtree は `src/assets/fonts/` に self-host している（`@font-face` は `src/assets/app.css`）。外部 font CDN は使わない。

### Tailwind CSS 4

- `@tailwindcss/vite`（`vite.config.ts` の plugin）で処理する。`tailwind.config.js` / `postcss.config.js` は無く、
  theme・plugin（`@tailwindcss/forms`）・class を探す範囲（`@source`。`index.html` と `src/` だけ）は `src/assets/app.css` に書く。
- `app.css` には v3 と同じ見た目にする互換 style（border の既定色 gray-200、button の cursor: pointer）がある。
- v4 で見た目が変わりやすい点: `space-y-*` / `space-x-*` は最後以外の子に margin を付けるため、最後の子が閉じた Modal（dialog）等だと余白が残る。
  `transform` class 単体では transform を指定しないため、重なり順を `transform` に頼らず `relative` / `z-*` で決める。`*-opacity-*` は無く `ring-black/5` のように書く。

## test

- class を検査しない。状態は `aria-current`・`aria-expanded`・`aria-invalid` などの属性、表示されているか（`isVisible()`、E2E は `toBeVisible()`）、role・label で確かめる。
  表示の切り替えに Tailwind の `hidden` class を使うと jsdom で表示状態を検査できないため、`v-show` や `hidden` 属性を使う。
- 表示している日本語の label・role の name で探す。Playwright の `getByLabel` / `getByRole` の name は部分一致なので、
  一方が他方を含む label（「パスワード」と「新しいパスワード（確認）」等）は `exact: true` を付ける。
- component test の 422 は `validationError()` で key を手で書くため、本物の Laravel と key がずれても通る。
  新しい form には、実サーバで 422 を 1 件以上出して field の下に表示されることを確かめる E2E を書く（配列の field があれば、その key でも 1 件）。
  全 field の validation は Laravel の Feature test で確かめ、E2E には重ねない。
- E2E の user は `e2e/helpers.ts` の `E2E_USERS` と Laravel の `E2eSeeder` で揃える。

## 依存

- `typescript` は `typescript-eslint` の peer 上限（`<6.1.0`）のため `~6.0.3` に固定している（Dependabot も ignore）。
- `@playwright/test` は `compose.yaml` の `frontend-e2e` image tag と version を一致させる。Dependabot は ignore しているので、両方を手動で同時に上げる。
- devDependencies の推移的依存に修正版が無い npm audit の advisory は、記録して許容することがある（現在は Vue Test Utils 経由の `minimatch` / `glob`。理由・前提・再評価は `docs/accepted-vulnerabilities.md`）。
  `overrides` で推移的依存を強制更新しない。許容する advisory は `audit-accepted.json` に載せ、CI の `npm audit` job が照合する。
- `.npmrc` の `engine-strict=true` により、Sail の Node（24.18 / npm 12）で `npm ci` すると `EBADENGINE` で失敗する（意図どおり）。
- Node を上げるときの手順は `docs/development.md`。
