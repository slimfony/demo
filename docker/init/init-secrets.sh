#!/bin/sh
# Generates the random secrets of this stack once, into the shared "secrets" volume. Nothing secret is in the repository.
set -eu
cd /secrets
if [ -f .ready ]; then
  echo "secrets already exist"
  exit 0
fi
umask 022
generate() { head -c 48 /dev/urandom | base64 | tr -d '=+/\n' | head -c 40; }
for name in db_password metrics_token grafana_password; do
  printf '%s' "$(generate)" > "$name"
done
printf '"demo" "%s"\n' "$(cat db_password)" > pgbouncer_userlist.txt
touch .ready
echo "secrets generated"
