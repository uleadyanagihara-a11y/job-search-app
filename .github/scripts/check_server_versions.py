"""サーバーソフト本体の版を endoflife.date と比べ、更新があれば Issue の下書きを書き出す。

使い方: python3 -I .github/scripts/check_server_versions.py <出力 directory>
リポジトリの root で実行する。出力は <製品>.title（1 行）と <製品>.md（本文）。
標準ライブラリだけを使う（runner に pip install しない）。
"""

import json
import re
import sys
import urllib.request
from pathlib import Path

API = "https://endoflife.date/api/v1/products/{}/"
VERSION_RE = re.compile(r"^\d+(\.\d+){0,3}$")

# 製品ごとに: endoflife.date の名前、使っている版の読み方、サポートの単位（cycle）の桁数、参考リンク
PRODUCTS = [
    {
        "name": "nginx",
        "eol_id": "nginx",
        "source": ("compose.yaml", r"\$\{NGINX_VERSION:-([\d.]+)"),
        "cycle_parts": 2,
        "links": [
            ("変更履歴", "https://nginx.org/en/CHANGES-{cycle}"),
            ("security advisories", "https://nginx.org/en/security_advisories.html"),
        ],
    },
    {
        "name": "PHP",
        "eol_id": "php",
        "source": (".github/workflows/ci.yml", r"PHP_MIN_VERSION:\s*'([\d.]+)'"),
        "cycle_parts": 2,
        "links": [
            ("ChangeLog", "https://www.php.net/ChangeLog-{major}.php#{latest}"),
            ("リリースの告知", "https://www.php.net/"),
        ],
    },
    {
        "name": "Node.js",
        "eol_id": "nodejs",
        "source": ("compose.yaml", r"\$\{NODE_VERSION:-([\d.]+)"),
        "cycle_parts": 1,
        "links": [
            ("リリースノート", "https://nodejs.org/en/blog/release/v{latest}"),
            ("security releases", "https://nodejs.org/en/blog/vulnerability"),
        ],
    },
    {
        "name": "Redis",
        "eol_id": "redis",
        "source": ("compose.yaml", r"\$\{REDIS_VERSION:-([\d.]+)"),
        "cycle_parts": 2,
        "links": [
            ("リリースノート", "https://github.com/redis/redis/releases/tag/{latest}"),
            ("security advisories", "https://github.com/redis/redis/security/advisories"),
        ],
    },
    {
        "name": "MySQL",
        "eol_id": "mysql",
        "source": ("compose.yaml", r"\$\{MYSQL_VERSION:-([\d.]+)"),
        "cycle_parts": 2,
        "links": [
            ("リリースノート", "https://dev.mysql.com/doc/relnotes/mysql/{cycle}/en/"),
            ("Oracle Security Alerts", "https://www.oracle.com/security-alerts/"),
        ],
    },
]


def as_tuple(version: str) -> tuple[int, ...]:
    return tuple(int(p) for p in version.split("."))


def current_version(path: str, pattern: str) -> str:
    match = re.search(pattern, Path(path).read_text(encoding="utf-8"))
    if not match or not VERSION_RE.match(match.group(1)):
        raise SystemExit(f"{path}: 版が読めない（{pattern}）")
    return match.group(1)


def fetch_releases(eol_id: str) -> list[dict]:
    request = urllib.request.Request(API.format(eol_id), headers={"User-Agent": "server-software-updates"})
    with urllib.request.urlopen(request, timeout=20) as response:
        return json.load(response)["result"]["releases"]


def check(product: dict) -> tuple[str, str] | None:
    current = current_version(*product["source"])
    cycle = ".".join(current.split(".")[: product["cycle_parts"]])
    releases = fetch_releases(product["eol_id"])

    own = next((r for r in releases if r["name"] == cycle), None)
    if own is None:
        raise SystemExit(f"{product['name']}: endoflife.date に {cycle} が無い")

    latest = own["latest"]["name"]
    if not VERSION_RE.match(latest):
        raise SystemExit(f"{product['name']}: 想定外の版 {latest!r}")

    has_patch = as_tuple(latest) > as_tuple(current)
    is_eol = bool(own.get("isEol"))
    if not has_patch and not is_eol:
        return None

    # 今の cycle より新しく、サポート中の cycle（上げ先の候補）
    newer = [
        r for r in releases
        if not r.get("isEol") and as_tuple(r["name"]) > as_tuple(cycle) and VERSION_RE.match(r["latest"]["name"])
    ]

    reasons = []
    if has_patch:
        reasons.append(f"{current} → {latest}")
    if is_eol:
        reasons.append(f"{cycle} はサポート終了")
    title = f"[サーバーソフト更新] {product['name']} {current}: " + "、".join(reasons)

    fmt = {"cycle": cycle, "latest": latest, "major": current.split(".")[0]}
    lines = [
        f"## {product['name']}",
        "",
        "| | 版 |",
        "| --- | --- |",
        f"| 使っている版（`{product['source'][0]}`） | {current} |",
        f"| {cycle} 系の最新 | {latest}（{own['latest'].get('date') or '日付不明'}） |",
        f"| {cycle} 系のサポート | {'**終了**（' + str(own.get('eolFrom')) + '）' if is_eol else '継続中'} |",
    ]
    if newer:
        lines += ["", "サポート中の新しい系列: " + "、".join(f"{r['name']}（最新 {r['latest']['name']}）" for r in newer)]
    lines += ["", "### 何が変わったか", ""]
    lines += [f"- [{label}]({url.format(**fmt)})" for label, url in product["links"]]
    lines += [
        "",
        "### メモ",
        "",
        "- 最新版の情報は endoflife.date から取得している（公式の公開から 1 日ほど遅れることがある）。",
        "- 上げる手順は CLAUDE.md（Node は 4 か所を同時に、PHP は Sail image の build と `PHP_MIN_VERSION`）。",
        "- 修正版がまだ無い脆弱性は、この Issue では知らせられない。",
    ]
    return title, "\n".join(lines) + "\n"


def main() -> None:
    out = Path(sys.argv[1])
    out.mkdir(parents=True, exist_ok=True)
    for product in PRODUCTS:
        result = check(product)
        if result is None:
            print(f"ok   {product['name']}")
            continue
        title, body = result
        print(f"new  {title}")
        stem = out / product["eol_id"]
        stem.with_suffix(".title").write_text(title + "\n", encoding="utf-8")
        stem.with_suffix(".md").write_text(body, encoding="utf-8")


if __name__ == "__main__":
    main()
