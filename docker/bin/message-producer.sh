#!/bin/sh
set -u

pid_file=/run/msg_producer.pid
producer_pid=
stopping=false

shutdown() {
    stopping=true
    if [ -n "$producer_pid" ] && kill -0 "$producer_pid" 2>/dev/null; then
        kill -INT "$producer_pid" 2>/dev/null || true
    fi
}

trap shutdown INT TERM HUP
rm -f "$pid_file"

php /opt/registry/automation/msg_producer.php

attempt=0
while [ "$attempt" -lt 50 ]; do
    if [ -s "$pid_file" ]; then
        producer_pid=$(cat "$pid_file")
        if kill -0 "$producer_pid" 2>/dev/null; then
            break
        fi
    fi
    attempt=$((attempt + 1))
    sleep 0.1
done

if [ -z "$producer_pid" ] || ! kill -0 "$producer_pid" 2>/dev/null; then
    echo "Message producer failed to start." >&2
    exit 1
fi

while kill -0 "$producer_pid" 2>/dev/null; do
    sleep 2 &
    wait $! 2>/dev/null || true
    [ "$stopping" = true ] && shutdown
done

[ "$stopping" = true ] && exit 0
echo "Message producer stopped unexpectedly." >&2
exit 1

