#!/usr/bin/env -S python3 -I
"""メインセッション用の PreToolUse hook（Bash）。Claude が `git commit` する前に、レビュアーの呼び忘れを確認させる。

CLAUDE.md の「契約に関わる実装の後はレビュアーを呼ぶ」を、Claude の記憶に頼らず機械的に確かめる。

- 会話の記録（transcript）で、領域ごとに「最後に編集した位置」と「最後にレビュアーを呼んだ位置」を比べ、
  編集のほうが後なら permissionDecision: "ask" を返す（拒否はしない。docs だけ・typo の commit 等もあるため）。
  - PHP 側（`.claude/rules/laravel.md` の paths と同じ）: app/ routes/ bootstrap/ config/ database/ lang/ tests/ → php-reviewer
  - SPA 側: frontend/ → frontend-reviewer
- 編集として数えるのは Edit・Write・MultiEdit・NotebookEdit だけ。Bash（sed -i 等）での変更は数えない。
- レビュアーの呼び出しは、エラーになったもの（agent が見つからない等）を数えない。呼んだかだけを見て、指摘を直したかは見ない。
- 見るのはこの会話の記録だけ。前の会話の編集・レビュー、ユーザーが自分で打つ commit は対象外。
- hook 自体が失敗したら ask を返す（fail-closed。止めはするが承認すれば進める）。
"""

import json
import os
import re
import sys

# guard-destructive.py と同じく、`git -C dir commit` 等も拾う
GIT_COMMIT = re.compile(r"\bgit\b(?:\s+[^\s;&|]+)*?\s+commit\b", re.IGNORECASE)

AREAS = {
    "php-reviewer": ("app/", "routes/", "bootstrap/", "config/", "database/", "lang/", "tests/"),
    "frontend-reviewer": ("frontend/",),
}
EDIT_TOOLS = ("Edit", "Write", "MultiEdit", "NotebookEdit")
AGENT_TOOLS = ("Agent", "Task")


def ask(reason: str) -> None:
    print(json.dumps({
        "hookSpecificOutput": {
            "hookEventName": "PreToolUse",
            "permissionDecision": "ask",
            "permissionDecisionReason": f"[require-review] {reason}",
        }
    }, ensure_ascii=False))
    sys.exit(0)


def area_of(path: str, project_dir: str) -> str | None:
    path = os.path.normpath(path)
    if os.path.isabs(path):
        rel = os.path.relpath(path, project_dir)
        if rel.startswith(".."):
            return None  # 別の worktree 等、このプロジェクトの外
    else:
        rel = path
    rel = rel.replace(os.sep, "/")
    for reviewer, prefixes in AREAS.items():
        if rel.startswith(prefixes):
            return reviewer
    return None


def read_transcript(path: str) -> list[dict]:
    entries = []
    with open(path, encoding="utf-8") as f:
        for line in f:
            try:
                entries.append(json.loads(line))
            except json.JSONDecodeError:
                continue  # 書き込み途中の行
    return entries


def blocks(entry: dict, kind: str):
    content = (entry.get("message") or {}).get("content")
    if isinstance(content, list):
        for b in content:
            if isinstance(b, dict) and b.get("type") == kind:
                yield b


def unreviewed(entries: list[dict], project_dir: str) -> list[str]:
    failed_ids = {
        b.get("tool_use_id")
        for e in entries if e.get("type") == "user"
        for b in blocks(e, "tool_result") if b.get("is_error")
    }
    last_edit: dict[str, int] = {}
    last_review: dict[str, int] = {}
    for i, e in enumerate(entries):
        if e.get("type") != "assistant" or e.get("isSidechain"):
            continue  # サブエージェントの中の操作は数えない
        for b in blocks(e, "tool_use"):
            name, inp = b.get("name"), b.get("input") or {}
            if name in EDIT_TOOLS:
                p = inp.get("file_path") or inp.get("notebook_path")
                area = area_of(p, project_dir) if isinstance(p, str) and p else None
                if area:
                    last_edit[area] = i
            elif name in AGENT_TOOLS and b.get("id") not in failed_ids:
                reviewer = inp.get("subagent_type")
                if reviewer in AREAS:
                    last_review[reviewer] = i
    return [r for r, i in last_edit.items() if last_review.get(r, -1) < i]


def main() -> None:
    try:
        payload = json.load(sys.stdin)
        if payload.get("tool_name") != "Bash":
            sys.exit(0)
        command = str((payload.get("tool_input") or {}).get("command", ""))
        if not GIT_COMMIT.search(command):
            sys.exit(0)
        project_dir = os.environ.get("CLAUDE_PROJECT_DIR") or payload.get("cwd") or os.getcwd()
        missing = unreviewed(read_transcript(payload["transcript_path"]), project_dir)
    except SystemExit:
        raise
    except Exception as e:  # noqa: BLE001
        ask(f"レビューの確認に失敗した（{type(e).__name__}）。レビュアーを呼んだか確かめてから承認する")
    if missing:
        names = "・".join(sorted(missing))
        ask(f"最後の編集の後に {names} が呼ばれていない（CLAUDE.md: 契約に関わる実装の後はレビュアーを呼ぶ）。"
            "レビュー不要な変更（docs・typo 等）なら承認してよい")
    sys.exit(0)


if __name__ == "__main__":
    main()
