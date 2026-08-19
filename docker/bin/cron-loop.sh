#!/bin/sh
set -u

stopping=false
sleeper=

shutdown() {
    stopping=true
    if [ -n "$sleeper" ]; then
        kill "$sleeper" 2>/dev/null || true
    fi
}

trap shutdown INT TERM HUP

while [ "$stopping" = false ]; do
    php /opt/registry/automation/cron.php || \
        echo "Namingo automation scheduler returned a failure." >&2

    now=$(date +%s)
    delay=$((60 - (now % 60)))
    sleep "$delay" &
    sleeper=$!
    wait "$sleeper" 2>/dev/null || true
    sleeper=
done

