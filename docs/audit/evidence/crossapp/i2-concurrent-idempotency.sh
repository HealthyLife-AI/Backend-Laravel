#!/bin/bash
# X- I2: N truly concurrent POST /me/meal-logs with ONE idempotency key against the audit API (:8002, MySQL).
# Usage: i2-concurrent-idempotency.sh <access_token> [N]
. /etc/profile.d/zz-prod-block.sh
B=http://127.0.0.1:8002/api/v1; T=$1; N=${2:-8}
KEY=$(python3 -c 'import uuid;print(uuid.uuid4())')
BODY="{\"food_id\":30,\"meal_item_id\":1,\"quantity_grams\":180,\"idempotency_key\":\"$KEY\",\"logged_at\":\"$(date -u -d '-5 min' +%Y-%m-%dT%H:%M:%SZ)\"}"
for i in $(seq 1 $N); do
  curl -s -o /tmp/claude-0/i2-$i.out -w "%{http_code}\n" $B/me/meal-logs -H "Authorization: Bearer $T" -H 'Content-Type: application/json' -H 'Accept: application/json' -d "$BODY" &
done | sort | uniq -c
wait
echo "key=$KEY"
for i in $(seq 1 $N); do head -c 160 /tmp/claude-0/i2-$i.out | grep -v '"id"' ; done | sort | uniq -c | head
