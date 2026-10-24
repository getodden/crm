#!/usr/bin/env bash
#
# A throwaway MariaDB server on a RAM disk, for the MariaDB step of scripts/ci.sh.
#
#   eval "$(scripts/ci-ram-mariadb.sh start)"   starts it and sets CI_RAM_MY_PORT, CI_RAM_MY_DEVICE, CI_RAM_MY_DIR, CI_RAM_MY_PID
#   scripts/ci-ram-mariadb.sh stop <device> <dir> <pid>
#
# Why: on this Mac the MariaDB suite spends most of its time creating and dropping table files (migrating the schema takes about
# 30 seconds on disk and about 2 on a RAM disk), so the same tests take half as long. It is the same mariadbd binary and version as
# Laravel Herd's own MariaDB, started with its own empty data directory on a free port, so nothing of Herd's is touched and the
# tests run against the same engine. What differs is durability (nothing here has to survive a crash) and where the files live.
#
# On failure (not macOS, no mariadbd, the RAM disk or the server will not start) it prints a reason to stderr and exits 1, and
# ci.sh falls back to the MariaDB server named by CI_MY_* with a notice. CI_MY_RAM=0 skips this on purpose.
set -uo pipefail

SIZE_SECTORS="${CI_RAM_MY_SECTORS:-786432}"   # 384 MB
BASE="${CI_RAM_MY_BASEDIR:-/Users/Shared/Herd/services/mariadb/11.8.6}"

port_free() { ! (exec 3<>"/dev/tcp/127.0.0.1/$1") 2>/dev/null; }

stop() {
    local device="$1" dir="$2" pid="$3" i
    if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
        kill "$pid" 2>/dev/null
        for i in $(seq 1 30); do kill -0 "$pid" 2>/dev/null || break; sleep 0.5; done
        kill -9 "$pid" 2>/dev/null || true
    fi
    if [ -n "$device" ]; then hdiutil detach "$device" -force >/dev/null 2>&1 || diskutil unmount force "$device" >/dev/null 2>&1; fi
    : "$dir"
}

start() {
    [ "$(uname)" = Darwin ] || { echo "not macOS" >&2; return 1; }
    [ -x "$BASE/bin/mariadbd" ] && [ -x "$BASE/scripts/mariadb-install-db" ] || { echo "no mariadbd under $BASE" >&2; return 1; }

    local device name dir port pid i
    device="$(hdiutil attach -nomount "ram://$SIZE_SECTORS" 2>/dev/null | awk 'NR==1{print $1}')"
    [ -n "$device" ] || { echo "could not create a RAM disk" >&2; return 1; }
    name="ci-mariadb-$$"
    if ! diskutil erasevolume HFS+ "$name" "$device" >/dev/null 2>&1; then stop "$device" "" ""; echo "could not format the RAM disk" >&2; return 1; fi
    dir="/Volumes/$name/m"
    mkdir -p "$dir"

    if ! "$BASE/scripts/mariadb-install-db" --no-defaults --basedir="$BASE" --datadir="$dir/data" --auth-root-authentication-method=normal --skip-test-db >"$dir/install.log" 2>&1; then
        stop "$device" "$dir" ""; echo "mariadb-install-db failed" >&2; return 1
    fi

    for port in $(seq "${CI_RAM_MY_PORT_FROM:-3399}" "${CI_RAM_MY_PORT_TO:-3430}"); do port_free "$port" && break; done
    port_free "$port" || { stop "$device" "$dir" ""; echo "no free port" >&2; return 1; }

    "$BASE/bin/mariadbd" --no-defaults --basedir="$BASE" --datadir="$dir/data" --port="$port" --bind-address=127.0.0.1 \
        --socket="$dir/s.sock" --pid-file="$dir/p.pid" --skip-log-bin --skip-name-resolve \
        --innodb-flush-log-at-trx-commit=0 --innodb-doublewrite=0 --innodb-buffer-pool-size=128M --innodb-log-file-size=48M \
        --max-connections=200 >"$dir/server.log" 2>&1 &
    pid=$!

    for i in $(seq 1 60); do
        if ! kill -0 "$pid" 2>/dev/null; then stop "$device" "$dir" ""; echo "mariadbd exited: $(tail -n 3 "$dir/server.log" 2>/dev/null | tr '\n' ' ')" >&2; return 1; fi
        port_free "$port" || break
        sleep 0.5
    done
    port_free "$port" && { stop "$device" "$dir" "$pid"; echo "mariadbd did not start listening" >&2; return 1; }

    printf 'CI_RAM_MY_PORT=%q CI_RAM_MY_DEVICE=%q CI_RAM_MY_DIR=%q CI_RAM_MY_PID=%q\n' "$port" "$device" "$dir" "$pid"
}

case "${1:-}" in
    start) start ;;
    stop) stop "${2:-}" "${3:-}" "${4:-}" ;;
    *) echo "usage: $0 start | stop <device> <dir> <pid>" >&2; exit 2 ;;
esac
