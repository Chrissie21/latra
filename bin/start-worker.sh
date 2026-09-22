#!/usr/bin/env bash
set -euo pipefail

DIR="$(cd "$(dirname "$0")/.." && pwd)"
PIDFILE="$DIR/logs/worker.pid"
LOGFILE="$DIR/logs/worker.log"

mkdir -p "$DIR/logs"

if [ -f "$PIDFILE" ]; then
    OLD_PID=$(cat "$PIDFILE" 2>/dev/null || echo "")
    if [ -n "$OLD_PID" ] && kill -0 "$OLD_PID" 2>/dev/null; then
        echo "Worker already running (PID $OLD_PID). Stop it first with bin/stop-worker.sh"
        exit 1
    fi
    rm -f "$PIDFILE"
fi

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Starting worker..."
nohup /usr/bin/php "$DIR/bin/worker.php" >> "$LOGFILE" 2>&1 &
NEW_PID=$!
echo "$NEW_PID" > "$PIDFILE"
disown "$NEW_PID" 2>/dev/null || true
echo "Worker started (PID $NEW_PID). Log: $LOGFILE"
