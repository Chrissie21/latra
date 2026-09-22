#!/usr/bin/env bash
set -euo pipefail

DIR="$(cd "$(dirname "$0")/.." && pwd)"
PIDFILE="$DIR/logs/worker.pid"

if [ ! -f "$PIDFILE" ]; then
    echo "No PID file found. Worker may not be running."
    OLD_PID=$(pgrep -f 'php.*worker\.php' 2>/dev/null || echo "")
    if [ -n "$OLD_PID" ]; then
        echo "Found orphan worker processes: $OLD_PID"
        echo "Killing..."
        kill $OLD_PID 2>/dev/null || true
        sleep 1
        kill -9 $OLD_PID 2>/dev/null || true
        echo "Killed."
    fi
    exit 0
fi

PID=$(cat "$PIDFILE")
if kill -0 "$PID" 2>/dev/null; then
    echo "Stopping worker (PID $PID)..."
    kill "$PID"
    sleep 2
    if kill -0 "$PID" 2>/dev/null; then
        echo "Force killing..."
        kill -9 "$PID" 2>/dev/null || true
    fi
    echo "Worker stopped."
else
    echo "Worker PID $PID not running (stale PID file)."
fi
rm -f "$PIDFILE"
