# Git コマンドメモ

Job Search AppをVS Codeのターミナルから管理するための、基本的なGitコマンド一覧です。Gitコマンドはプロジェクトのルートディレクトリで実行します。

> [!TIP]
> 操作の前後に`git status`を実行すると、変更ファイル、ステージ状況、現在のブランチを確認できます。

## Git管理を始める

まだGitリポジトリではない場合は、最初に一度だけ初期化します。

```bash
git init -b main
git status
```

このプロジェクトの`.gitignore`では、`.env`、`vendor`、`node_modules`、`public/build`などが除外されています。初回コミットの前にも、秘密情報や生成物が含まれていないことを`git status`で確認してください。

## 基本操作

| VS Codeでの操作 | Gitコマンド | 意味 |
| --- | --- | --- |
| ファイル横の`＋` | `git add ファイル名` | 指定したファイルをステージする |
| 「変更」の横の`＋` | `git add .` | 現在のディレクトリ以下の変更をまとめてステージする |
| ステージ解除 | `git restore --staged ファイル名` | ファイルをステージから外す |
| 状態確認 | `git status` | 変更とステージの状態を表示する |
| コミット | `git commit -m "変更内容"` | ステージした変更を履歴として保存する |

変更をまとめて追加する前に、対象が意図どおりか確認します。

```bash
git status
git diff
git add README.md git_command.md
git diff --staged
```

## ブランチと履歴

| 操作 | Gitコマンド | 意味 |
| --- | --- | --- |
| ブランチ確認 | `git branch --show-current` | 現在のブランチ名を表示する |
| ブランチ作成・切り替え | `git switch -c feature/ブランチ名` | 新しいブランチを作って移動する |
| ブランチ切り替え | `git switch ブランチ名` | 既存のブランチへ移動する |
| マージ | `git merge ブランチ名` | 指定したブランチを現在のブランチへ統合する |
| 履歴確認 | `git log --oneline --decorate -10` | 最近のコミット履歴を表示する |

ブランチ名の例です。

```text
feature/job-search
feature/company-bookmark
fix/login-validation
docs/setup-guide
```

## 変更内容の確認

| コマンド | 表示する内容 |
| --- | --- |
| `git diff` | まだステージしていない変更 |
| `git diff --staged` | ステージ済みの変更 |
| `git diff --name-only` | 未ステージの変更ファイル名 |
| `git diff --staged --name-only` | ステージ済みの変更ファイル名 |

コミット前には、テストとビルドもSail経由で確認します。

```bash
./vendor/bin/sail artisan test
./vendor/bin/sail npm run build
git status
git diff --staged
```

## コミット

コミットメッセージは、変更内容が分かる短い命令形または要約にします。

```bash
git commit -m "求人検索画面を追加"
git commit -m "ログイン時の入力検証を修正"
git commit -m "Sailのセットアップ手順を更新"
```

コミット対象に`.env`、認証情報、APIキー、個人情報が含まれていないことを必ず確認してください。

## リモートとの同期

| 操作 | Gitコマンド | 意味 |
| --- | --- | --- |
| クローン | `git clone URL` | リモートリポジトリをPCへコピーする |
| リモート確認 | `git remote -v` | リモート名と取得・送信先を表示する |
| リモート追加 | `git remote add origin URL` | `origin`という名前でリモートを登録する |
| フェッチ | `git fetch origin` | 作業中ファイルを変えずにリモートの最新情報を取得する |
| プル | `git pull --ff-only` | 早送りできる場合だけ変更を取り込む |
| プッシュ | `git push` | 現在のブランチのコミットを送信する |
| 初回プッシュ | `git push -u origin ブランチ名` | 追跡先を登録してプッシュする |

`origin`は一般的なリモート名です。実際の名前とURLは`git remote -v`で確認できます。

### ローカルの`main`を最新にする

```bash
git status
git switch main
git pull --ff-only origin main
```

`--ff-only`を付けると、ローカルとリモートの履歴が分岐している場合は停止します。意図しないマージコミットを避け、状況を確認してから対応できます。

### 作業ブランチに最新の`main`を取り込む

```bash
git branch --show-current
git status
git fetch origin
git merge origin/main
```

競合が発生した場合は、競合箇所を修正し、テスト後に対象ファイルをステージしてコミットします。

### プッシュ前の確認

```bash
git status
git branch --show-current
git log --oneline --decorate -5
```

> [!CAUTION]
> プッシュすると変更がリモートへ共有されます。ブランチ名、コミット内容、秘密情報が含まれていないことを確認してから実行してください。

- 先にリモートが更新されている場合、プッシュが拒否されることがあります。`git fetch origin`で最新情報を取得し、差分を確認してから変更を取り込みます。
- `git push --force`はリモートの履歴を書き換えるため、共有ブランチでは使用しません。
- 履歴修正後に強制プッシュが必要な場合でも、対象を確認したうえで`git push --force-with-lease`を検討します。

## 変更を一時退避する

ブランチを切り替える前など、一時的に未コミットの変更を退避できます。

```bash
git stash push -m "作業途中"
git stash list
git stash pop
```

未追跡ファイルも退避する必要がある場合は`git stash push -u`を使用します。適用前に`git stash show --stat`で内容を確認してください。

## 変更や履歴を元に戻す

### 未コミットの変更

```bash
# 対象ファイルを確認
git status
git diff -- ファイル名

# ステージだけ解除（ファイル内容は残す）
git restore --staged ファイル名

# ファイルの未コミット変更を破棄
git restore ファイル名
```

> [!CAUTION]
> `git restore ファイル名`で破棄した未コミットの変更は、通常はGitから復元できません。対象と差分を確認してから実行してください。

### コミット済みの変更

共有済みのコミットを打ち消す場合は、新しい履歴を残す`git revert`を使用します。

```bash
git log --oneline -10
git revert コミットID
```

`git reset`はコミット位置や作業ファイルを変更するため、履歴を書き換えてよいローカル作業に限って慎重に使用します。

| コマンド | 動作 |
| --- | --- |
| `git reset --soft HEAD~1` | 直前のコミットを取り消し、変更はステージしたまま残す |
| `git reset HEAD~1` | 直前のコミットを取り消し、変更は未ステージで残す |
| `git reset --hard HEAD~1` | 直前のコミットと作業内容を破棄する |

> [!CAUTION]
> `git reset --hard`は未コミットの変更も消します。実行前に`git status`と`git log --oneline`で対象を確認してください。

### 未追跡ファイルの削除

```bash
# 削除候補だけを確認
git clean -nd

# 未追跡ファイルとディレクトリを削除
git clean -fd
```

> [!CAUTION]
> `git clean`で削除したファイルはGitの履歴にないため、通常は復元できません。特に`git clean -fdx`は`.env`や無視対象の生成物も削除するため、使用しないでください。

## 安全確認の基本手順

迷った場合は、変更を加えない次のコマンドで現在の状態を確認します。

```bash
git status
git branch --show-current
git log --oneline --decorate -5
git diff
git diff --staged
```
