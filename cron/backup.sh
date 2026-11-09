#!/usr/bin/env bash
#
# SHEHITA EMS - nightly database backup
#
# With one database per client company, a single dump of "the" database is no
# longer enough: losing shems_<slug> loses that whole company. --all-databases
# captures every company plus the control database in one consistent file.
#
# Install on the VPS:
#   chmod +x cron/backup.sh
#   crontab -e
#   30 2 * * * /opt/shehita/cron/backup.sh >> /var/log/shehita-backup.log 2>&1

set -euo pipefail

COMPOSE_DIR="${COMPOSE_DIR:-/opt/shehita}"
BACKUP_DIR="${BACKUP_DIR:-$COMPOSE_DIR/backups}"
RETAIN_DAYS="${RETAIN_DAYS:-14}"

cd "$COMPOSE_DIR"

# Read MYSQL_ROOT_PASSWORD without echoing it or putting it on a command line
# (a password in argv is visible to every user via `ps`).
set -a
# shellcheck disable=SC1091
source .env
set +a

mkdir -p "$BACKUP_DIR"
STAMP="$(date +%Y%m%d-%H%M%S)"
TARGET="$BACKUP_DIR/shehita-all-$STAMP.sql.gz"

echo "[$(date -Is)] dumping all databases -> $TARGET"

docker compose -f docker-compose.prod.yml exec -T \
  -e MYSQL_PWD="$MYSQL_ROOT_PASSWORD" db \
  mysqldump -u root \
    --all-databases \
    --single-transaction \
    --quick \
    --routines \
    --events \
  | gzip -9 > "$TARGET"

# A dump that failed mid-stream still creates a file, so check it decompresses
# and is not trivially small before we trust it enough to prune older ones.
if ! gzip -t "$TARGET" 2>/dev/null; then
    echo "[$(date -Is)] ERROR: dump is corrupt, keeping all older backups"
    exit 1
fi

SIZE=$(stat -c%s "$TARGET")
if [ "$SIZE" -lt 10240 ]; then
    echo "[$(date -Is)] ERROR: dump is only ${SIZE} bytes, keeping all older backups"
    exit 1
fi

echo "[$(date -Is)] ok, ${SIZE} bytes"

# Uploaded company logos are not in MySQL; back them up too.
tar -czf "$BACKUP_DIR/shehita-uploads-$STAMP.tar.gz" -C "$COMPOSE_DIR" uploads 2>/dev/null || true

find "$BACKUP_DIR" -name 'shehita-*.gz' -mtime "+$RETAIN_DAYS" -delete
echo "[$(date -Is)] pruned backups older than $RETAIN_DAYS days"
