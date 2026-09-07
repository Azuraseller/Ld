#!/usr/bin/env bash
set -euo pipefail
ATTACH=/home/ubuntu/upload/api3.php
ORIG=/home/ubuntu/projects/ld-tool-770cf429/api3.php
HTML=/home/ubuntu/ld-tool-web/client/src/legacy.html
CHAT=/home/ubuntu/projects/ld-tool-770cf429/chatgpt.js

printf '%s\n' '=== file identity ==='
sha256sum "$ATTACH" "$ORIG"
if cmp -s "$ATTACH" "$ORIG"; then echo 'api3.php attached == shared original'; else echo 'api3.php attached != shared original'; fi

printf '%s\n' '=== PHP action cases ==='
grep -oE "case '[^']+'" "$ORIG" | sed "s/case '//;s/'$//" | sort -u > /tmp/php_actions.txt
cat /tmp/php_actions.txt
printf 'PHP action count: '; wc -l < /tmp/php_actions.txt

printf '%s\n' '=== frontend allowed actions ==='
grep -oE "ALLOWED_ACTIONS = new Set\(\[[^]]+\]" "$HTML" | sed 's/.*\[//;s/\]$//' | tr ',' '\n' | sed "s/[ '\"]//g" | sed '/^$/d' | sort -u > /tmp/frontend_actions.txt
cat /tmp/frontend_actions.txt
printf 'Frontend action count: '; wc -l < /tmp/frontend_actions.txt

printf '%s\n' '=== PHP cases missing from frontend allow-list ==='
comm -23 /tmp/php_actions.txt /tmp/frontend_actions.txt || true
printf '%s\n' '=== frontend allow-list actions without PHP case ==='
comm -13 /tmp/php_actions.txt /tmp/frontend_actions.txt || true

printf '%s\n' '=== critical source markers ==='
for pattern in 'function call' 'const apiUrl' 'function setMood' 'function petHtml' 'askServer' 'assetFiles' '624.gif' '042.gif' 'api3.php'; do
  echo "-- $pattern"
  grep -nF "$pattern" "$HTML" "$CHAT" 2>/dev/null | head -n 10 || true
done
