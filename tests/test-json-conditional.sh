#!/usr/bin/env bash
# WHAT:   Do the JSON endpoints answer a conditional request with 304?
# WHY:    They carried `Cache-Control: public, max-age=86400` and NO validator at all
#         — no ETag, no Last-Modified — so a client that wanted to revalidate could
#         not, and a far-future If-Modified-Since still returned a full 200 body.
#         Measured over 14 days: 3,455 successful JSON fetches, and Cloudflare reports
#         cf-cache-status: DYNAMIC on them, so the edge absorbs none of the repeats.
#         Every app launch re-downloaded chapters it already had.
# HOW:    Fetch, keep the validator, ask again with it, and require a 304 with an
#         empty body — then require that a WRONG validator still gets the full 200,
#         because a 304 that is always returned is worse than none.
# INPUT:  BASE (default https://latinprayer.local). Pass a prod URL to check live.
# OUTPUT: exit 0 when every endpoint validates correctly.
# RUN:    tests/test-json-conditional.sh [BASE]
# TESTED BY: itself — it is the guard.

set -uo pipefail
BASE="${1:-${BASE:-https://latinprayer.local}}"
CURL=(curl -sk --max-time 20)
pass=0; fail=0

ok() { if [ "$1" = "1" ]; then pass=$((pass+1)); echo "  ok   $2"; else fail=$((fail+1)); echo "  FAIL $2"; fi; }

check_endpoint() {
  local path="$1" label="$2"
  local url="${BASE}${path}"

  local hdrs; hdrs=$("${CURL[@]}" -D- -o /dev/null "$url")
  local code; code=$(printf '%s' "$hdrs" | head -1 | awk '{print $2}')
  local etag; etag=$(printf '%s' "$hdrs" | grep -i '^etag:' | tr -d '\r' | cut -d' ' -f2-)
  local lastmod; lastmod=$(printf '%s' "$hdrs" | grep -ic '^last-modified:')

  echo "${label}:"
  ok "$([ "$code" = "200" ] && echo 1 || echo 0)" "answers 200 ($code)"
  ok "$([ -n "$etag" ] && echo 1 || echo 0)" "sends an ETag (${etag:-none})"

  # The full body, for the size comparison below.
  local full; full=$("${CURL[@]}" -o /dev/null -w '%{size_download}' "$url")
  ok "$([ "$full" -gt 100 ] && echo 1 || echo 0)" "unconditional fetch returns the body (${full} bytes)"

  # The point of the whole exercise.
  local c304; c304=$("${CURL[@]}" -o /dev/null -w '%{http_code}:%{size_download}' -H "If-None-Match: ${etag}" "$url")
  ok "$([ "$c304" = "304:0" ] && echo 1 || echo 0)" "matching If-None-Match -> 304 with no body (${c304})"

  # A weak validator must still match: these bodies are byte-identical when they do.
  local weak; weak=$("${CURL[@]}" -o /dev/null -w '%{http_code}' -H "If-None-Match: W/${etag}" "$url")
  ok "$([ "$weak" = "304" ] && echo 1 || echo 0)" "weak W/ validator also matches (${weak})"

  # ...and a WRONG one must NOT. A 304 that is always returned is worse than none:
  # the client would be pinned to whatever it cached first, forever.
  local stale; stale=$("${CURL[@]}" -o /dev/null -w '%{http_code}:%{size_download}' -H 'If-None-Match: "not-the-etag"' "$url")
  ok "$([ "${stale%%:*}" = "200" ] && [ "${stale##*:}" = "$full" ] && echo 1 || echo 0)" \
     "stale If-None-Match -> full 200 (${stale})"

  # If-Modified-Since is the older mechanism; a client that only speaks it is served
  # too. Only file-backed responses carry Last-Modified.
  if [ "$lastmod" -gt 0 ]; then
    local ims; ims=$("${CURL[@]}" -o /dev/null -w '%{http_code}' -H 'If-Modified-Since: Wed, 01 Jan 2036 00:00:00 GMT' "$url")
    ok "$([ "$ims" = "304" ] && echo 1 || echo 0)" "future If-Modified-Since -> 304 (${ims})"
    local past; past=$("${CURL[@]}" -o /dev/null -w '%{http_code}' -H 'If-Modified-Since: Thu, 01 Jan 1990 00:00:00 GMT' "$url")
    ok "$([ "$past" = "200" ] && echo 1 || echo 0)" "past If-Modified-Since -> 200 (${past})"
  fi
}

echo "conditional requests against ${BASE}"
echo
check_endpoint "/bible/genesis/1.json"      "chapter (file-backed, the busiest path)"
check_endpoint "/bible/index.json"          "translation index (file-backed)"
check_endpoint "/bible/genesis/1/1.json"    "single verse (built per request)"

echo
echo "${pass} passed / ${fail} failed"
[ "$fail" -eq 0 ]
