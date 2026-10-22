#!/usr/bin/env bash
# development Nginx（http://localhost:${APP_PORT}）の振り分けを curl で確認する。
# ホストで実行する: ./docker/nginx/smoke-test.sh
# 前提: ./vendor/bin/sail up -d 済みで、frontend が healthy。
set -uo pipefail

cd "$(dirname "$0")/../.."

env_value() {
    sed -n "s/^$1=//p" .env 2>/dev/null | tail -1
}

# compose.yaml と同じく、環境変数 → .env → 既定値の順に port を決める
app_port="${APP_PORT:-$(env_value APP_PORT)}"
SPA="http://localhost:${app_port:-80}"

failures=0
body="$(mktemp)"
trap 'rm -f "$body"' EXIT

pass() { printf '  ok   %s\n' "$1"; }
fail() { printf '  FAIL %s\n' "$1"; failures=$((failures + 1)); }

# expect <説明> <status> <content-type の先頭 | -> <URL> [curl options...]
expect() {
    local label="$1" status="$2" type="$3" url="$4"
    shift 4
    local result
    result="$(curl -s -o "$body" -w '%{http_code} %{content_type}' "$@" "$url")"
    local got_status="${result%% *}" got_type="${result#* }"
    if [ "$got_status" != "$status" ]; then
        fail "$label: status $got_status (expected $status)"
    elif [ "$type" != "-" ] && [[ "$got_type" != "$type"* ]]; then
        fail "$label: content-type '$got_type' (expected $type)"
    else
        pass "$label"
    fi
}

# 直前の expect の body に SPA の HTML が含まれていないこと（API/asset が index.html に化けていない）
expect_not_spa() {
    if grep -q '<div id="app">' "$body"; then
        fail "$1: SPA の index.html が返っている"
    else
        pass "$1: SPA の index.html ではない"
    fi
}

echo "SPA ($SPA) -> Vite"
expect 'GET /' 200 text/html "$SPA/"
grep -q '<div id="app">' "$body" && pass 'GET /: SPA の index.html' || fail 'GET /: SPA の index.html ではない'
expect 'GET /profile (direct access)' 200 text/html "$SPA/profile"
# 旧 Inertia の画面 route だった path も SPA が返す
expect 'GET /login' 200 text/html "$SPA/login"
grep -q '<div id="app">' "$body" && pass 'GET /login: SPA の index.html' || fail 'GET /login: SPA の index.html ではない'
expect 'GET /reset-password/{token}?email=' 200 text/html "$SPA/reset-password/token?email=user%40example.com"
expect 'GET /@vite/client' 200 text/javascript "$SPA/@vite/client"
# Vue plugin が読まれていないと（vite.config.ts 無しで Vite が再起動した等）500 になる
expect 'GET /src/App.vue (Vue plugin)' 200 text/javascript "$SPA/src/App.vue"

echo "SPA ($SPA) -> Laravel"
expect 'GET /api/user (guest)' 401 application/json "$SPA/api/user" -H 'Accept: application/json'
expect 'GET /api/存在しない' 404 application/json "$SPA/api/__smoke_not_found" -H 'Accept: application/json'
expect_not_spa 'GET /api/存在しない'
expect 'GET /sanctum/csrf-cookie' 204 - "$SPA/sanctum/csrf-cookie" -H "Referer: $SPA/" -D "$body.headers"
grep -qi '^set-cookie: XSRF-TOKEN=' "$body.headers" && pass 'XSRF-TOKEN Cookie' || fail 'XSRF-TOKEN Cookie が無い'
rm -f "$body.headers"
expect 'GET /up' 200 text/html "$SPA/up"
expect_not_spa 'GET /up'
# local disk の serve（署名付き URL）が担当する。署名なしは 403
expect 'GET /storage/存在しない (署名なし)' 403 text/html "$SPA/storage/__smoke_not_found.png"
expect_not_spa 'GET /storage/存在しない'

echo "HMR WebSocket"
token="$(curl -s "$SPA/@vite/client" | sed -n 's/^const wsToken = "\([^"]*\)";/\1/p')"
if [ -z "$token" ]; then
    fail 'wsToken を /@vite/client から取得できない'
else
    ws_status="$(curl -s -o /dev/null -w '%{http_code}' --max-time 2 \
        -H 'Connection: Upgrade' -H 'Upgrade: websocket' -H 'Sec-WebSocket-Version: 13' \
        -H 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==' -H 'Sec-WebSocket-Protocol: vite-hmr' \
        "$SPA/?token=$token")"
    [ "$ws_status" = 101 ] && pass 'WebSocket upgrade 101' || fail "WebSocket upgrade: status $ws_status (expected 101)"
fi

echo "Host header"
# Host を upstream 名に書き換えると Vite は 403 を返す（nginx は $http_host をそのまま渡す必要がある）
rewritten="$(docker compose exec -T nginx wget -S -q -O /dev/null http://frontend:5173/ 2>&1 | sed -n 's/^ *HTTP\/1.1 \([0-9]*\).*/\1/p' | head -1)"
[ "$rewritten" = 403 ] && pass 'Host: frontend:5173 は 403' || fail "Host: frontend:5173: status '${rewritten}' (expected 403)"

echo
if [ "$failures" -gt 0 ]; then
    echo "$failures 件失敗"
    exit 1
fi
echo "すべて成功"
