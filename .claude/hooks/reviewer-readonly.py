#!/usr/bin/env -S python3 -I
"""レビュー用サブエージェント（php-reviewer・frontend-reviewer）専用の PreToolUse hook。

- Bash で実行してよいのは、読み取り専用の git コマンド 1 つだけにする。
  それ以外（パイプ・リダイレクト・連結・展開・テスト実行・sail・docker・npm 等）は exit 2 で拒否する。
  親セッションの permissions.allow（`git add *` 等）を継承しても、ここで止まる。
- Read・Grep・Glob でも `.env` 系（`.env.example` を除く）は読ませない（AGENTS.md。reviewer には不要）。
- hook 自体の失敗（想定外の入力・例外）も exit 2 で拒否する（fail-closed）。
  exit 2 以外の非 0 は Claude Code では「拒否」にならず、ツールがそのまま動くため。
  script を起動できない場合は、agent 定義の `|| exit 2` で拒否する。
- 変更したら、少なくとも次が拒否されることを確かめる:
  `git grep --no-ind APP_KEY`（省略形）・`git show HEAD:.env.local`・`git diff --output=x`・
  `git -c core.pager=x log`・`git log | cat`・Grep の glob `.env*`・Write。
"""

import json
import os
import re
import shlex
import sys

ALLOWED_SUBCOMMANDS = {
    "status",
    "diff",
    "log",
    "show",
    "ls-files",
    "blame",
    "rev-parse",
    "merge-base",
    "for-each-ref",
    "grep",
}

# 使ってよい文字の許可リスト。bash が展開・解釈する文字（{} * ? [] 引用符 \ $ ; & | < > ` 改行 等）を含めない。
# hook は shlex で、実行は bash なので、両者の解釈がずれる文字を残すと検査をすり抜けられる
# （例: `git diff --{output=/x,stat}` は shlex では 1 引数、bash では `--output=/x --stat`）。
ALLOWED_COMMAND = re.compile(r"[A-Za-z0-9 _./:@=^~%,+-]+")

# 拒否する long option。git grep などは long option の途中までの省略形（`--no-ind`・`--open=rm`）も
# 受け付けるため、省略形も拒否する（`opt.startswith(name)`。git 2.53 で確認）。
# 省略形で拒否しても `--no-color` 等は巻き込まない（"no-index" は "no-color" で始まらない）。
FORBIDDEN_LONG_OPTIONS = (
    "output",                # diff/log/show: 結果をファイルに書く
    "ext-diff",              # 外部 diff プログラムを起動する
    "textconv",              # textconv フィルタ（外部コマンド）を起動する
    "open-files-in-pager",   # grep: 結果を任意のコマンドで開く
    "exec",
    "no-index",              # diff/grep: リポジトリ外・ignore されたファイル（.env 等）を読む
    "no-exclude-standard",   # grep: ignore されたファイル（.env 等）も検索する
)


def deny(reason: str) -> None:
    print(f"reviewer: 実行を拒否しました（{reason}）。Bash は読み取り専用の git コマンドだけ使えます: "
          + ", ".join(sorted(ALLOWED_SUBCOMMANDS)), file=sys.stderr)
    sys.exit(2)


def is_env_file(path: str) -> bool:
    """`.env`・`.env.local` 等（`.gitignore` の `/.env`・`/.env.*`）を指すか。`.env.example` は除く。"""
    name = os.path.basename(path.rstrip("/"))
    return re.fullmatch(r"\.env(\..+)?", name) is not None and name != ".env.example"


def check_bash(tool_input: dict) -> None:
    command = str(tool_input.get("command", "")).strip()
    if not command:
        deny("空のコマンド")

    if not ALLOWED_COMMAND.fullmatch(command):
        deny("使えない文字を含む（使えるのは英数字・空白と _ . / : @ = ^ ~ % , + - だけ）")

    try:
        args = shlex.split(command)
    except ValueError:
        deny("コマンドを解析できません")

    # `git -c ...` / `git -C ...` / `git --git-dir=...` などの全体 option は使わせない
    if len(args) < 2 or args[0] != "git":
        deny("git 以外のコマンド")
    subcommand = args[1]
    if subcommand not in ALLOWED_SUBCOMMANDS:
        deny(f"許可していない git サブコマンド {subcommand!r}")

    after_double_dash = False
    for arg in args[2:]:
        # `HEAD:.env`・`--contents=.env`・`-f.env` 等も見る
        for part in re.split(r"[:=]", arg):
            if is_env_file(part) or (part.startswith("-") and not part.startswith("--") and is_env_file(part[2:])):
                deny(".env は読めません")
        if after_double_dash:
            continue
        if arg == "--":
            after_double_dash = True
            continue

        if arg.startswith("--"):
            name = arg[2:].split("=", 1)[0]
            for opt in FORBIDDEN_LONG_OPTIONS:
                if name and opt.startswith(name):
                    deny(f"許可していない option {arg!r}（--{opt} またはその省略形）")
        elif arg.startswith("-") and len(arg) > 1:
            # 短い option。`-O<cmd>`（grep のページャ・diff の orderfile）は束ねた形（`-lOrm`）も含めて拒否する
            if "O" in arg[1:]:
                deny(f"許可していない option {arg!r}")


def is_env_glob(glob: str) -> bool:
    """`.env*`・`**/.env.*`・`{.env,x}` 等、`.env` 系に当たりうる glob か。`.env.example` だけを指すものは許す。"""
    return ".env" in glob and not glob.endswith(".env.example")


def check_file_tool(tool_name: str, tool_input: dict) -> None:
    for key in ("file_path", "path"):
        value = tool_input.get(key)
        if isinstance(value, str) and value and is_env_file(value):
            deny(".env は読めません（AGENTS.md）")
    # Glob は pattern が glob、Grep は glob が glob（Grep の pattern は正規表現なので見ない）
    glob = tool_input.get("pattern" if tool_name == "Glob" else "glob")
    if isinstance(glob, str) and is_env_glob(glob):
        deny(".env を検索対象にしている（AGENTS.md）")


def main() -> None:
    try:
        payload = json.load(sys.stdin)
    except Exception:
        deny("hook の入力を読めません")
    if not isinstance(payload, dict):
        deny("hook の入力が想定と違います")

    tool_name = payload.get("tool_name")
    tool_input = payload.get("tool_input")
    if not isinstance(tool_input, dict):
        deny("tool_input が想定と違います")

    if tool_name == "Bash":
        check_bash(tool_input)
    elif tool_name in ("Read", "Grep", "Glob"):
        check_file_tool(tool_name, tool_input)
    else:
        # agent の tools は Read・Grep・Glob・Bash だけ。それ以外が来たら設定の変更を疑って止める
        deny(f"想定していないツール {tool_name!r}")

    sys.exit(0)


if __name__ == "__main__":
    try:
        main()
    except SystemExit:
        raise
    except BaseException as e:  # noqa: BLE001 — 想定外の例外でもツールを動かさない
        deny(f"hook の内部エラー: {type(e).__name__}")
