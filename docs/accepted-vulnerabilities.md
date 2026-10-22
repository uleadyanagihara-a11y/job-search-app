# 許容した脆弱性の記録

修正しないと決めた依存の脆弱性と、その理由・前提・再評価の条件を記録する。
npm の advisory は `frontend/audit-accepted.json` に ID を載せ、CI の `npm audit (frontend/)` job
（`frontend/scripts/check-npm-audit.mjs`）が照合する。リストと本書は必ず同時に更新する。

## 2026-10-22: Tailwind CSS 3.4.19 の依存（braces / postcss-selector-parser）

### 対象

| advisory | 深刻度 | package（現在の版） | 経路 | 修正版 |
| --- | --- | --- | --- | --- |
| [GHSA-vfj7-8cjw-p6xm](https://github.com/advisories/GHSA-vfj7-8cjw-p6xm)（深くネストした pattern による stack exhaustion の DoS） | high | `braces@3.0.3` | `tailwindcss@3.4.19` → `chokidar@3.6.0` / `micromatch@4.0.8`（`fast-glob@3.3.3` 経由も）→ `braces` | 無し（影響範囲 `<=3.0.3`、2026-10-22 時点で未公開） |
| [GHSA-rj75-hqrm-r3gf](https://github.com/advisories/GHSA-rj75-hqrm-r3gf)（flat selector の解析が二乗の計算量になる CPU exhaustion） | moderate | `postcss-selector-parser@6.1.5` | `tailwindcss@3.4.19` → `postcss-selector-parser`、`tailwindcss` → `postcss-nested@6.2.0` → `postcss-selector-parser` | `7.1.6`（ただし依存元が `^6` を要求） |

`npm audit` では 7 件（high 5・moderate 2）と表示されるが、上の 2 advisory が依存の連鎖で数えられているだけである。

### 決定

- `tailwindcss` は 3.4.19（3 系の最終版）を維持する。Tailwind 4 には上げない（移行計画 `docs/inertia-removal-migration.md` の Phase 3 手順 4〜6 で 3 系を PostCSS で使うと決めており、4 系は設定と CSS の書き方が変わる）。
- `package.json` の `overrides` で推移的依存を強制的に更新しない。`braces` には修正版が無く、`postcss-selector-parser` を 7 系にすると `postcss-nested@6.2.0` の要求範囲（`^6`）を外れ、Tailwind の nesting 処理が保証されなくなるため。

### 許容する理由

- **devDependencies だけで、配信物に入らない。** `npm audit --omit=dev` は 0 件。ブラウザに配信する bundle（`frontend/dist`）にこれらの package のコードは含まれない。
- **build と dev サーバでだけ動き、入力は自前のもの。** `braces` / `micromatch` が展開するのは `frontend/tailwind.config.js` の `content` の glob、`postcss-selector-parser` が解析するのは `frontend/src` の CSS とクラス名である。利用者の入力や外部のデータは渡らない。
- **影響は DoS（処理が止まる・遅くなる）に限られる。** 情報漏えいや任意コード実行ではない。悪意のある入力を入れられるのはリポジトリにコードを書ける者だけで、その者は build の中で任意のコードを実行できるため、この脆弱性で新たにできることは増えない。

### 前提（崩れたら再評価する）

1. これらの package を本番の実行時（Laravel や配信する JS）で使わない。
2. Tailwind の `content` や CSS に、外部・利用者由来の文字列を入れない。
3. **CI が信頼できない PR のコードを無制限に実行しない**（下記）。PR は `content` の glob や CSS を書き換えて build を止めることができるので、CI 側で実行時間と権限を制限する。

### CI の実行制限

`.github/workflows/ci.yml` で次を守る。

- trigger は `pull_request` を使い、`pull_request_target` は使わない（PR のコードを base branch の権限・secrets で動かさない）。
- token の権限は `contents: read` だけ。`actions/checkout` は `persist-credentials: false` で、PR のコードから token を読めないようにする。
- workflow から secrets を参照しない。
- すべての job に `timeout-minutes` を付ける（DoS で runner を占有させない）。
- `npm-audit` job は lockfile だけを検査し、`npm ci` しない（依存の install script を動かさない）。

GitHub のリポジトリ設定でも、外部からの PR の workflow 実行に承認を要求する（ファイルでは管理できないため手動で確認する）。

- Settings → Actions → General → **Approval for running fork pull request workflows from contributors** を
  **Require approval for all external contributors** にする。
- private repository の場合は、同じ画面の **Fork pull request workflows** で fork からの PR の実行を許可しないか、
  **Send write tokens to workflows from pull requests** と **Send secrets to workflows from pull requests** を無効のままにする。

### 再評価の条件と手順

CI の `npm audit (frontend/)` job（PR・main への push・毎週月曜 9:00 JST の schedule で実行）は次の場合に失敗する。

| 失敗の内容 | 意味 | 対応 |
| --- | --- | --- |
| `未許容の advisory` | 新しい脆弱性が公開された | 修正するか、本書に追記してから `audit-accepted.json` に載せる |
| `修正手段が公開された` | Tailwind 4 への major 更新以外で直せるようになった（例: `braces` の修正版が公開され、`chokidar` の範囲内で更新できる） | `npm update` 等で更新して解消を確認し、`audit-accepted.json` と本書から削除する |
| `許容済みの advisory が出なくなった` | 依存の更新で解消した | `audit-accepted.json` と本書から削除する |

このほか、次のときにも本書の判断を見直す。

- Tailwind 4 への移行を決めたとき（`.github/dependabot.yml` の `tailwindcss` major の ignore も外す）。
- advisory の内容（深刻度・影響範囲）が更新されたとき。
- 上の「前提」のどれかが崩れるとき。

ローカルでの確認:

```bash
docker compose run --rm --no-deps frontend npm run audit:check
docker compose run --rm --no-deps frontend npm audit
docker compose run --rm --no-deps frontend npm ls braces micromatch postcss-selector-parser
```
