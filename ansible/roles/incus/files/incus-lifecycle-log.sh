#!/bin/sh
# Prints Incus instance state changes as JSON lines. Exec, file and log events fire on every
# command Yak runs in a sandbox, so only the state changes are kept.
set -eu
incus monitor --type=lifecycle --format=json \
    | grep --line-buffered -E '"action":"instance-(created|started|stopped|shutdown|restarted|paused|resumed|deleted|renamed|restored)"'
