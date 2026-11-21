#!/bin/bash
# Audit I7: N parallel POST /auth/refresh with the SAME refresh token (what a client does when several requests hit an
# expired access token at once). Server: php -S with PHP_CLI_SERVER_WORKERS=8 on MySQL (hl_audit_be_restore), :8011.
# Usage: i7-concurrent-refresh.sh <N> <email> <password>
B=http://127.0.0.1:8011/api/v1; N=$1; D=$(mktemp -d)
RT=$(curl -s -X POST $B/auth/login -H 'Content-Type: application/json' -H 'Accept: application/json' -d "{\"email\":\"$2\",\"password\":\"$3\"}" | python3 -c 'import sys,json;print(json.load(sys.stdin)["refresh_token"])')
for i in $(seq 1 $N); do curl -s -o $D/r$i -w "%{http_code}\n" -X POST $B/auth/refresh -H 'Content-Type: application/json' -H 'Accept: application/json' -d "{\"refresh_token\":\"$RT\"}" > $D/c$i & done; wait
echo "statuses:"; cat $D/c* | sort | uniq -c
echo "messages:"; for i in $(seq 1 $N); do python3 -c "import json;d=json.load(open('$D/r$i'));print(d.get('message','new pair'))"; done | sort | uniq -c
NEW=$(for i in $(seq 1 $N); do python3 -c "import json;print(json.load(open('$D/r$i')).get('refresh_token',''))"; done | grep . | tail -1)
[ -n "$NEW" ] && echo "one returned token refreshes again: $(curl -s -o /dev/null -w '%{http_code}' -X POST $B/auth/refresh -H 'Content-Type: application/json' -H 'Accept: application/json' -d "{\"refresh_token\":\"$NEW\"}")"
rm -rf $D
