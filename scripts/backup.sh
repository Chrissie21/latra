#!/bin/bash
# Database + critical-files backup for the LATRA–Wialon integration.
# Usage: scripts/backup.sh   (intended to run daily from cron)
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKUP_DIR="${APP_DIR}/backups"
MYSQLDUMP="$(command -v mysqldump || command -v /opt/lampp/bin/mysqldump || echo /opt/lampp/bin/mysqldump)"
MYSQL_SOCKET="${MYSQL_SOCKET:-/opt/lampp/var/mysql/mysql.sock}"
KEEP_DAYS="${KEEP_DAYS:-14}"
TSTAMP="$(date +%Y%m%d-%H%M%S)"

mkdir -p "${BACKUP_DIR}"

DB_HOST=""; DB_NAME=""; DB_USER=""; DB_PASS=""
# shellcheck disable=SC1090
if [[ -f "${APP_DIR}/.env" ]]; then
  while IFS='=' read -r key val; do
    case "${key}" in
      DB_HOST) DB_HOST="${val}" ;;
      DB_NAME) DB_NAME="${val}" ;;
      DB_USER) DB_USER="${val}" ;;
      DB_PASS) DB_PASS="${val}" ;;
    esac
  done < <(grep -E '^(DB_HOST|DB_NAME|DB_USER|DB_PASS)=' "${APP_DIR}/.env" || true)
fi
[[ -z "${DB_NAME}" ]] && { echo "DB_NAME missing from .env — aborting" >&2; exit 1; }

echo "==> Backing up database '${DB_NAME}'"
if [[ -n "${DB_HOST}" && "${DB_HOST}" != "127.0.0.1" && "${DB_HOST}" != "localhost" ]]; then
  "${MYSQLDUMP}" --skip-ssl --single-transaction --quick \
    -h "${DB_HOST}" -u "${DB_USER}" \
    ${DB_PASS:+-p"${DB_PASS}"} "${DB_NAME}" > "${BACKUP_DIR}/db-${TSTAMP}.sql"
else
  "${MYSQLDUMP}" --skip-ssl --single-transaction --quick \
    --protocol=SOCKET --socket="${MYSQL_SOCKET}" -u "${DB_USER}" \
    ${DB_PASS:+-p"${DB_PASS}"} "${DB_NAME}" > "${BACKUP_DIR}/db-${TSTAMP}.sql"
fi

echo "==> Backing up .env and configs"
cp "${APP_DIR}/.env" "${BACKUP_DIR}/env-${TSTAMP}.env" 2>/dev/null || true
cp "${APP_DIR}/config.php" "${BACKUP_DIR}/config-${TSTAMP}.php" 2>/dev/null || true

echo "==> Pruning backups older than ${KEEP_DAYS} days"
find "${BACKUP_DIR}" -type f -mtime "+${KEEP_DAYS}" -delete

echo "==> Done: $(ls -1 "${BACKUP_DIR}" | wc -l) backup file(s) in ${BACKUP_DIR}"