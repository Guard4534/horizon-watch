#!/bin/sh
# Stops supervisord when php-fpm or nginx gives up starting, so the restart policy of the container takes over.
printf 'READY\n'

while read -r header; do
    length="$(printf '%s\n' "$header" | sed -n 's/.*len:\([0-9]*\).*/\1/p')"
    payload="$(head -c "${length:-0}")"
    echo "horizon-watch: ${payload}: gave up starting, stopping the container" >&2
    kill -TERM "$PPID"
    printf 'RESULT 2\nOKREADY\n'
done
