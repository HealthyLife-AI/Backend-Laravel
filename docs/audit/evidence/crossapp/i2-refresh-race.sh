#!/bin/bash
# Two truly concurrent POST /auth/refresh with the same refresh token, then reuse of the token each response returned.
. /etc/profile.d/zz-prod-block.sh
B=http://127.0.0.1:8002/api/v1; D=/tmp/claude-0/rr; mkdir -p $D
login() { curl -s $B/auth/login -H 'Content-Type: application/json' -H 'Accept: application/json' -d '{"username":"demo.samer","password":"AuditPass-123"}'; }
R0=$(login | python3 -c 'import json,sys;print(json.load(sys.stdin)["refresh_token"])')
for i in 1 2; do curl -s -o $D/r$i.json -w "refresh#$i %{http_code}\n" $B/auth/refresh -H 'Content-Type: application/json' -H 'Accept: application/json' -d "{\"refresh_token\":\"$R0\"}" & done; wait
RA=$(python3 -c "import json;print(json.load(open('$D/r1.json')).get('refresh_token',''))"); RB=$(python3 -c "import json;print(json.load(open('$D/r2.json')).get('refresh_token',''))")
[ "$RA" != "$RB" ] && echo "two different refresh tokens issued (chain R0->X->Y)"
echo "waiting ${WAIT:-35}s (> 30 s grace) then the device uses the token from the response that arrived last..."
sleep ${WAIT:-35}
for t in "$RA" "$RB"; do curl -s -w " -> %{http_code}\n" $B/auth/refresh -H 'Content-Type: application/json' -H 'Accept: application/json' -d "{\"refresh_token\":\"$t\"}" | python3 -c 'import sys;s=sys.stdin.read();print(s[-120:] if "message" in s else "ok token pair"+s[-8:])'; done
