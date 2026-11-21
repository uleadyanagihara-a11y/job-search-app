#!/usr/bin/env -S python3 -I
"""メインセッション（と全サブエージェント）用の PreToolUse hook。

開発データを消す・作業中の変更を捨てる・秘密を読むコマンドに、
permissions.allow に入っていても確認（permissionDecision: "ask"）を出させる。

- deny ではなく ask にする。開発 DB の作り直しなど、承認すれば実行してよい操作のため。
- permissions の Bash ルールは前方一致のため、`docker compose exec laravel.test php artisan migrate:fresh`
  のように経路を変えるとすり抜ける。ここではコマンド文字列の全体を見る。
- 目的は「うっかり実行」を止めること。わざと迂回するもの（base64 で隠す等）は防げない。
- テストは影響を受けない。`sail test` は DB `testing` を RefreshDatabase で作り直し、
  E2E の `migrate:fresh` は compose.yaml の `laravel.e2e` の起動コマンドがコンテナ内で実行する（Bash を通らない）。
- hook 自体が失敗したら ask を返す（fail-closed。止めはするが承認すれば進める）。
"""

import json
import os
import re
import sys

GIT = r"\bgit\b(?:\s+[^\s;&|]+)*?\s+"

# (正規表現, 理由)。コマンド全体に IGNORECASE で当てる。
BASH_RULES = [
    (r"\b(migrate:(fresh|reset|rollback|refresh)|db:wipe)\b", "開発 DB のテーブル・データを消す artisan コマンド"),
    (r"\bschema:dump\b[^\n;&|]*--prune", "migration ファイルを消す artisan コマンド"),
    (r"\bdown\b[^\n;&|]*(\s-[a-z]*v[a-z]*\b|--volumes|--rmi)", "Docker のボリューム（開発 DB 等）を消す down"),
    (r"\bvolume\s+(rm|remove|prune)\b", "Docker のボリュームを消す"),
    (r"\b(system|container|image|builder)\s+prune\b", "Docker の資源をまとめて消す"),
    (r"\bcompose\b[^\n;&|]*\brm\b[^\n;&|]*\s-[a-z]*v", "コンテナと一緒に匿名ボリュームを消す"),
    # `git -C dir reset` 等も拾うため、git の後の同じ単純コマンド内（; & | 改行の手前まで）を見る。
    (rf"{GIT}reset\b[^\n;&|]*--(hard|merge|keep)\b", "作業中の変更を捨てる git reset"),
    (rf"{GIT}clean\b", "未追跡ファイルを消す git clean"),
    (rf"{GIT}restore\b", "作業中の変更を捨てうる git restore"),
    (rf"{GIT}checkout\b[^\n;&|]*\s(--|\.|-f|--force)(?=\s|$)", "作業中の変更を捨てる git checkout"),
    (rf"{GIT}(switch|checkout)\b[^\n;&|]*\s(--discard-changes|-f|--force)(?=\s|$)", "作業中の変更を捨てる branch 切替"),
    (rf"{GIT}branch\b[^\n;&|]*\s((?-i:-D)|--force)(?=\s|$)", "branch の強制削除"),
    (rf"{GIT}stash\s+(drop|clear)\b", "stash を消す"),
    (rf"{GIT}push\b", "push（AGENTS.md: Claude は push しない）"),
    (r"(^|[\s;&|(])(sudo\s+)?rm\s+(-[a-z]*r[a-z]*|-[a-z]*\s+-[a-z]*r|--recursive)", "ディレクトリの再帰削除"),
]

# DB クライアント・tinker を経由するときだけ見る SQL（grep の検索語などでの誤検知を減らすため）。
DB_CONTEXT = re.compile(r"\b(mysql|mariadb|mysqladmin|tinker|db:table|DB::)", re.IGNORECASE)
SQL_RULES = [
    (r"\bdrop\s+(database|schema|table|user)\b", "DB・テーブルを消す SQL"),
    (r"\btruncate\b", "テーブルを空にする SQL"),
    (r"\bdelete\s+from\b", "行を消す SQL"),
    (r"\bmysqladmin\b[^\n;&|]*\bdrop\b", "DB を消す mysqladmin"),
]

# `.env` `.env.local` `/path/.env.production` など。`.env.example` と `process.env` `import.meta.env` は除く。
ENV_IN_COMMAND = re.compile(r"(?<![\w$.-])\.env(?!\.example\b)(\.[\w.-]+)?(?![\w-])")

PROTECTED_CONFIG = (
    ".claude/settings.json",
    ".claude/settings.local.json",
    ".claude/hooks/",
)


def ask(reason: str) -> None:
    print(json.dumps({
        "hookSpecificOutput": {
            "hookEventName": "PreToolUse",
            "permissionDecision": "ask",
            "permissionDecisionReason": f"[guard-destructive] {reason}",
        }
    }, ensure_ascii=False))
    sys.exit(0)


def is_env_file(path: str) -> bool:
    name = os.path.basename(path.rstrip("/"))
    return re.fullmatch(r"\.env(\..+)?", name) is not None and name != ".env.example"


def check_bash(command: str) -> None:
    for pattern, reason in BASH_RULES:
        if re.search(pattern, command, re.IGNORECASE):
            ask(reason)
    if DB_CONTEXT.search(command):
        for pattern, reason in SQL_RULES:
            if re.search(pattern, command, re.IGNORECASE):
                ask(reason)
    if ENV_IN_COMMAND.search(command):
        ask(".env（秘密情報）を扱うコマンド（AGENTS.md: 必要なとき以外読まない）")


def check_file_tool(tool_name: str, tool_input: dict) -> None:
    paths = [tool_input.get(k) for k in ("file_path", "path", "notebook_path")]
    paths = [p for p in paths if isinstance(p, str) and p]
    glob = tool_input.get("glob")
    if isinstance(glob, str) and ".env" in glob and ".env.example" not in glob:
        ask(".env（秘密情報）を検索対象にしている")
    for p in paths:
        if is_env_file(p):
            ask(".env（秘密情報）のファイル")
        if tool_name in ("Edit", "Write", "MultiEdit", "NotebookEdit"):
            normalized = p.replace("\\", "/")
            if any(c in normalized for c in PROTECTED_CONFIG):
                ask("Claude Code の権限設定・hook の変更")


def main() -> None:
    try:
        payload = json.load(sys.stdin)
        tool_name = payload.get("tool_name", "")
        tool_input = payload.get("tool_input") or {}
        if tool_name == "Bash":
            check_bash(str(tool_input.get("command", "")))
        else:
            check_file_tool(tool_name, tool_input)
    except SystemExit:
        raise
    except Exception as e:  # noqa: BLE001
        ask(f"hook の検査に失敗した（{type(e).__name__}）")
    sys.exit(0)


if __name__ == "__main__":
    main()
