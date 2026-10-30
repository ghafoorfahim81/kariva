#!/usr/bin/env bash
# Restore a database backup into a NEW database, so it can be checked before anything is replaced.
#   deploy/restore.sh /var/www/kariva/backend/storage/app/backups/db-20261101-023000.dump kariva_restore_check
set -euo pipefail
DUMP="${1:?path to a db-*.dump file}"
TARGET="${2:?name for the new database}"

createdb "$TARGET"
pg_restore --no-owner --dbname "$TARGET" "$DUMP"
psql "$TARGET" -Atc "select 'users: ' || count(*) from users; select 'orders: ' || count(*) from orders;"
echo "Restored into '$TARGET'. Check it, then point DB_DATABASE at it when you are sure."
