#!/bin/sh
PIDFILE="/var/run/devicemonitor.pid"
if [ -f "$PIDFILE" ]; then
    PID=$(cat "$PIDFILE")
    if kill -0 "$PID" 2>/dev/null; then
        echo "running"
        exit 0
    fi
    # Pidfile existuje ale proces neběží - vyčisti
    rm -f "$PIDFILE"
fi
echo "stopped"
exit 0