#!/bin/bash
# Auto-clean for the LATRA-Wialon integration (cron-safe, no hardcoded paths).
#
# Does, on each run:
#   1. Size-rotate error.log / cron.log / cleanup.log (> ROTATE_MB, gzip, keep KEEP_GZ)
#   2. Age-prune app-*.log / automation-*.log / *.old / logs gz (LOG_DAYS)
#   3. Remove stale lock/pid files
#   4. Enforce backup retention + gzip old dumps (BACKUP_DAYS / GZIP_DAYS)
#   5. Run database retention (scripts/maintain.php) -- DB down is not fatal
#
# Overridable via env: CLEAN_LOG_DAYS, CLEAN_ROTATE_MB, CLEAN_KEEP_GZ,
# CLEAN_BACKUP_DAYS, CLEAN_GZIP_DAYS, PHP_BIN
set -u

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LOG_DIR="${APP_DIR}/logs"
BACKUP_DIR="${APP_DIR}/backups"
SCRIPT_DIR="${APP_DIR}/scripts"

LOG_DAYS="${CLEAN_LOG_DAYS:-14}"
ROTATE_MB="${CLEAN_ROTATE_MB:-20}"
KEEP_GZ="${CLEAN_KEEP_GZ:-5}"
BACKUP_DAYS="${CLEAN_BACKUP_DAYS:-14}"
GZIP_DAYS="${CLEAN_GZIP_DAYS:-3}"

PHP_BIN="${PHP_BIN:-$(command -v php || echo php)}"

log()  { echo "cleanup: $*"; }

# --- 1. size-based rotation ------------------------------------------------
ROTATE_BYTES=$((ROTATE_MB * 1024 * 1024))
SHORT_SLEEP=0
rotate_large() {
    local file="$1"
    local keep="$2"
    [[ -f "${file}" ]] || return 0
    local size
    size=$(wc -c < "${file}" 2>/dev/null || echo 0)
    (( size < ROTATE_BYTES )) && return 0
    local stamp
    stamp=$(date +%Y%m%d-%H%M%S)
    local tmp="${file}.${stamp}.tmp"
    cp "${file}" "${tmp}" 2>/dev/null || return 0
    if gzip -f "${tmp}" 2>/dev/null; then
        : > "${file}" 2>/dev/null
        log "rotated $(basename "${file}") (${size} bytes) -> ${tmp}.gz"
    else
        rm -f "${tmp}"
        return 0
    fi
    # keep only the KEEP_GZ newest gz for this base name.
    local base
    base="${LOG_DIR}/$(basename "${file}")"
    local old
    i=0
    while IFS= read -r old; do
        i=$((i + 1))
        (( i > keep )) && rm -f "${old}"
    done < <(ls -1t "${base}."*.gz 2>/dev/null)
}

rotate_large "${LOG_DIR}/error.log" "${KEEP_GZ}"
rotate_large "${LOG_DIR}/cron.log"  "${KEEP_GZ}"
rotate_large "${LOG_DIR}/cleanup.log" "${KEEP_GZ}"
rotate_large "${LOG_DIR}/login.log" "${KEEP_GZ}"

# --- 2. age-based prune of log files ----------------------------------------
# shellcheck disable=SC2012,SC2035
NLOG=0
while IFS= read -r f; do
    rm -f "${f}" && NLOG=$((NLOG + 1))
done < <(find "${LOG_DIR}" -maxdepth 1 -type f \( -name 'app-*.log' -o -name 'automation-*.log' -o -name '*.old' -o -name '*.gz' \) -mtime "+${LOG_DAYS}" -print 2>/dev/null)
(( NLOG > 0 )) && log "pruned ${NLOG} log file(s) older than ${LOG_DAYS} day(s)"

# --- 3. stale lock / pid files ----------------------------------------------
STALE=0
for lock in cron.pid cron.pid.pid; do
    if [[ -f "${LOG_DIR}/${lock}" ]] && [[ -n "$(find "${LOG_DIR}/" -maxdepth 1 -name "${lock}" -mmin "+3" 2>/dev/null)" ]]; then
        rm -f "${LOG_DIR}/${lock}" && STALE=$((STALE + 1))
    fi
done
for lock in worker.pid worker.log last_tick.txt; do
    if [[ -f "${LOG_DIR}/${lock}" ]] && [[ -n "$(find "${LOG_DIR}/" -maxdepth 1 -name "${lock}" -mtime "+1" 2>/dev/null)" ]]; then
        rm -f "${LOG_DIR}/${lock}" && STALE=$((STALE + 1))
    fi
done
(( STALE > 0 )) && log "removed ${STALE} stale lock/pid file(s)"

# --- 4. backups: retention + gzip old snapshots -----------------------------
mkdir -p "${BACKUP_DIR}"
NPR=0
while IFS= read -r f; do
    rm -f "${f}" && NPR=$((NPR + 1))
done < <(find "${BACKUP_DIR}" -maxdepth 1 -type f \( -name 'db-*.sql' -o -name 'config-*.php' -o -name 'env-*.env' \) -mtime "+${BACKUP_DAYS}" -print 2>/dev/null)
(( NPR > 0 )) && log "removed ${NPR} backup file(s) older than ${BACKUP_DAYS} day(s)"

NGZ=0
while IFS= read -r f; do
    if gzip -f "${f}" 2>/dev/null; then
        NGZ=$((NGZ + 1))
    fi
done < <(find "${BACKUP_DIR}" -maxdepth 1 -type f \( -name 'db-*.sql' -o -name 'config-*.php' -o -name 'env-*.env' \) -mtime "+$((GZIP_DAYS - 1))" ! -name '*.gz' -print 2>/dev/null)
(( NGZ > 0 )) && log "gzipped ${NGZ} backup file(s) older than ${GZIP_DAYS} day(s)"

# --- 5. database retention (not fatal if DB is down) ------------------------
if [[ -x "${PHP_BIN}" ]] || command -v "${PHP_BIN}" >/dev/null 2>&1; then
    "${PHP_BIN}" "${SCRIPT_DIR}/maintain.php" >> "${LOG_DIR}/cleanup.log" 2>&1
else
    log "php not found at ${PHP_BIN}; skipped DB retention"
fi

log "complete (logs ${LOG_DAYS}d / backups ${BACKUP_DAYS}d / rotate ${ROTATE_MB}MB keep ${KEEP_GZ})"