#!/usr/bin/env bash
# Бэкап БД. Вызывать из crontab.
set -e
cd "$(dirname "$0")/.."

set -a; . ./.env; set +a

BACKUP_DIR="${BACKUP_DIR:-$HOME/backups}"
mkdir -p "$BACKUP_DIR"

FILE="$BACKUP_DIR/kese-$(date +%Y%m%d-%H%M).sql.gz"

docker compose exec -T -e MYSQL_PWD="$DB_ROOT_PASSWORD" db \
  mysqldump -uroot --single-transaction --quick --lock-tables=false "$DB_NAME" \
  | gzip > "$FILE"

echo "✅ $FILE"

# Удалить бэкапы старше 14 дней
find "$BACKUP_DIR" -name 'kese-*.sql.gz' -mtime +14 -delete

