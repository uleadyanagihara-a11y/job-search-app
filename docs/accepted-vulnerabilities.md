# 許容した脆弱性の記録

修正しないと決めた依存の脆弱性と、その理由・前提・再評価の条件を記録する。
npm の advisory は `frontend/audit-accepted.json` に ID を載せ、CI の `npm audit (frontend/)` job
（`frontend/scripts/check-npm-audit.mjs`）が照合する。リストと本書は必ず同時に更新する。

Composer（`composer audit`）には許容の仕組みを置いていない。advisory が出たら依存を更新して直す。

## 許容中

### 2026-11-05: ESLint・typescript-eslint・@vue/test-utils の依存（minimatch / glob）

#### 対象

| advisory | 深刻度 | package（現在の版） | 経路 | 修正版 |
| --- | --- | --- | --- | --- |
| [GHSA-g3hv-75wg-m249](https://github.com/advisories/GHSA-g3hv-75wg-m249)（`**/../` を含む glob pattern の前処理で globParts が増幅し、CPU・メモリを消費する DoS） | moderate | `minimatch@10.2.6` | `eslint` / `@eslint/config-array` / `@typescript-eslint/typescript-estree` → `minimatch`、`@vue/test-utils` → `js-beautify@2.0.3` → `editorconfig@3.0.2` / `glob` → `minimatch` | `11.0.1`（ただし依存元が `^10.2.x` / `~10.2.4` を要求） |
| [GHSA-3g3m-2fjr-6pw4](https://github.com/advisories/GHSA-3g3m-2fjr-6pw4)（`AbortSignal` で中断した walk が裏で走査を続ける） | moderate | `glob@13.0.6` | `@vue/test-utils` → `js-beautify@2.0.3` → `glob` | `14.0.2`（ただし `js-beautify` が `^13.0.6` を要求） |

`npm audit` では 15 件と表示されるが、上の 2 advisory が依存の連鎖で数えられているだけである。

2026-11-05 時点で、各依存元の最新版（`eslint@10.14.0`、`@eslint/config-array@0.23.5`、`typescript-eslint@8.72.1`、
`editorconfig@3.0.2`、`js-beautify@2.0.3`）も修正版を受け付けない。`npm audit` が示す修正手段（`fixAvailable`）は
`eslint@4.0.0`・`@vue/test-utils@2.4.11` など、今より古い版へのダウングレードだけである。

#### 決定

- 依存元が修正版に対応するのを待つ。ダウングレードはしない（ESLint 10 の flat config・Vue Test Utils の現行 API を使っている）。
- `package.json` の `overrides` で `minimatch` 11 系・`glob` 14 系を強制しない。依存元の要求範囲を外れ、lint やテストの動作が保証されなくなるため。

#### 許容する理由

- **devDependencies だけで、配信物に入らない。** `npm audit --omit=dev` は 0 件。lint（ESLint）と component テスト（Vue Test Utils の HTML 整形）でだけ動く。
- **pattern と走査対象は自前のもの。** `minimatch` に渡るのは `eslint.config.js` の `files` / `ignores`、`.editorconfig` の section 名、`js-beautify` の設定探索で、すべてリポジトリ内のファイルである。
  `glob` の `AbortSignal` は使っていない（`js-beautify` も中断しない）ので、中断後に走査が続く問題は起きない。
- **影響は DoS に限られる。** 悪意のある pattern を入れられるのはリポジトリにコードを書ける者だけで、その者は lint・テストの中で任意のコードを実行できるため、この脆弱性で新たにできることは増えない。

#### 前提（崩れたら再評価する）

1. これらの package を本番の実行時（Laravel や配信する JS）で使わない。
2. 外部・利用者由来の文字列を glob pattern として `minimatch` / `glob` に渡さない。
3. 下記「CI の実行制限」を守る。

## 解消済み

- **2026-10-22〜2026-11-05: [GHSA-rj75-hqrm-r3gf](https://github.com/advisories/GHSA-rj75-hqrm-r3gf)（`postcss-selector-parser@6.1.5`、`tailwindcss@3.4.19` 経由）。**
  flat selector の解析が二乗の計算量になる CPU exhaustion（moderate）。修正版は `7.1.6` だけで、`tailwindcss` / `postcss-nested` が `^6` を要求するため許容していた。
  2026-10-26 に advisory の影響範囲が `< 6.1.5` と `>= 7.0.0, < 7.1.6` に改められ、`6.1.5` が修正版になったため `npm audit` に出なくなった。版は変えていない。
  Tailwind 4 には上げず、3.4.19 を PostCSS で使う方針（`docs/inertia-removal-migration.md` の Phase 3 手順 4〜6）は変わらない。
- **2026-10-22〜2026-10-26: [GHSA-vfj7-8cjw-p6xm](https://github.com/advisories/GHSA-vfj7-8cjw-p6xm)（`braces@3.0.3`、`tailwindcss` → `chokidar` / `micromatch` 経由）。**
  high、stack exhaustion の DoS。2026-10-23 に GitHub が advisory を取り下げ（withdrawn）、`npm audit` に出なくなった。版は変えていない。

## CI の実行制限

許容している advisory はいずれも、PR のコード（設定ファイルや pattern）を書き換えれば lint・テスト・build を止められる種類のものである。
そのため `.github/workflows/ci.yml` で次を守り、CI で信頼できない PR のコードを無制限に実行しない。

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

## 再評価の条件と手順

CI の `npm audit (frontend/)` job（PR・main への push・毎週月曜 9:00 JST の schedule で実行）は次の場合に失敗する。

| 失敗の内容 | 意味 | 対応 |
| --- | --- | --- |
| `未許容の advisory` | 新しい脆弱性が公開された | 修正するか、本書に追記してから `audit-accepted.json` に載せる |
| `修正手段が公開された` | 今より古い版へのダウングレード以外で直せるようになった（例: `editorconfig` が `minimatch` 11 系に対応し、`npm update` で上がる。依存元の新しい major で直る） | 更新して解消を確認し、`audit-accepted.json` と本書の「許容中」から「解消済み」に移す |
| `許容済みの advisory が出なくなった` | 依存の更新や advisory の改訂で解消した | `audit-accepted.json` から削除し、本書の「許容中」から「解消済み」に移す |

`修正手段が公開された` は、`npm audit` の `fixAvailable` が「直接依存を lockfile の版より古い版に戻す major 変更」のときだけ無視する
（依存元が修正版に対応していないとき、npm は脆弱な依存を含まない大昔の版を提案してくるため）。

このほか、次のときにも本書の判断を見直す。

- advisory の内容（深刻度・影響範囲）が更新されたとき。
- 上の「前提」のどれかが崩れるとき。

ローカルでの確認:

```bash
docker compose run --rm --no-deps frontend npm run audit:check
docker compose run --rm --no-deps frontend npm audit
docker compose run --rm --no-deps frontend npm ls minimatch glob
```
