#!/usr/bin/env bash
set -euo pipefail
BASE="http://localhost:3000"
JAR="$(mktemp)"
TMP="$(mktemp -d)"
trap 'rm -f "$JAR"; rm -rf "$TMP"' EXIT
EMAIL="crud-test-$(date +%s%N)@example.test"

curl -fsS -c "$JAR" -b "$JAR" "$BASE/?action=proxy_get" > "$TMP/proxy-before.json"
SCOPE="$(awk '$6 == "legacy_scope" { print $7 }' "$JAR")"
if [[ -z "$SCOPE" ]]; then echo "legacy_scope cookie was not created" >&2; exit 1; fi
curl -fsS -c "$JAR" -b "$JAR" -X POST -H 'Content-Type: application/json' \
  -d '{"flags":{"temp_email":false},"proxy_us":["198.51.100.10:8080"],"proxy_vn":[]}' \
  "$BASE/?action=proxy_save" > "$TMP/proxy-save.json"
curl -fsS -c "$JAR" -b "$JAR" "$BASE/?action=proxy_get" > "$TMP/proxy-after.json"
grep -q '"ok":true' "$TMP/proxy-save.json"
grep -q '"enabled":false' "$TMP/proxy-after.json"
grep -q '198.51.100.10:8080' "$TMP/proxy-after.json"

curl -fsS -c "$JAR" -b "$JAR" -X POST -H 'Content-Type: application/json' \
  -d "{\"email\":\"$EMAIL\",\"password\":\"test-only\",\"type\":\"ldplayer\",\"uid\":\"test-uid\",\"token\":\"test-token\"}" \
  "$BASE/?action=acc_save" > "$TMP/account-save.json"
curl -fsS -c "$JAR" -b "$JAR" "$BASE/?action=acc_list" > "$TMP/account-list.json"
grep -q "$EMAIL" "$TMP/account-list.json"
curl -fsS -c "$JAR" -b "$JAR" -X POST -H 'Content-Type: application/json' \
  -d '{"idx":0}' "$BASE/?action=acc_delete" > "$TMP/account-delete.json"
grep -q '"ok":true' "$TMP/account-delete.json"

curl -fsS -c "$JAR" -b "$JAR" -X POST -H 'Content-Type: application/json' \
  -d '{"messages":[{"role":"user","content":"database smoke test"}]}' \
  "$BASE/?action=mimi_memory_save" > "$TMP/memory-save.json"
curl -fsS -c "$JAR" -b "$JAR" "$BASE/?action=mimi_memory_get" > "$TMP/memory-get.json"
grep -q 'database smoke test' "$TMP/memory-get.json"

printf 'legacy CRUD smoke test passed: proxy, account, mimi memory\n'
printf 'legacy_scope=%s\n' "$SCOPE"
