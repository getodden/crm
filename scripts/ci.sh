#!/usr/bin/env bash
#
# The project's CI, run on this machine (GitHub Actions is not relied on: see .github/workflows/tests.yml for what it ran).
#
#   scripts/ci.sh                 everything below, in order, stopping at the first failure
#   scripts/ci.sh --only pint     one step (pint, docs, phpstan, workbench, sqlite, pgsql, mariadb)
#   scripts/ci.sh --keep-going    run every step and report all failures
#   scripts/ci.sh --quick         while you work: Pint, PHPStan, the Workbench tests and the package suites on SQLite. Never the gate:
#                                 it cannot --report.
#   scripts/ci.sh --report        after a full pass, set the "local-ci" commit status on HEAD (needs gh, a clean tree)
#   scripts/ci.sh --fresh         with --report: run everything even if this exact tree already passed (see below)
#
# A full pass is remembered per git TREE (the files, not the commit) and PHP version, in .git/local-ci-green. --report on a commit whose
# tree already passed here sets the status without running anything: a squash merge of an up-to-date branch has the same tree as the
# branch head, and so does a plain re-run. A different tree (the branch was behind, or anything changed) runs in full. The record is
# written only by a complete run (no --quick, no --only) that passed on a clean tree. It is a local file: delete it, or use --fresh,
# after changing the machine's PHP extensions, a database server or the vendor folder in a way the tree does not show.
#
# Steps: Pint, the docs check (pages, navigation and links), PHPStan and the Workbench tests at the root, then the suites of packages/core, packages/sales and packages/filament on
# SQLite, on PostgreSQL and on MariaDB/MySQL. The two server databases use their own throwaway databases, dropped and created each
# run; point them at your servers with the variables below. Nothing else on those servers is touched.
#
#   CI_PG_HOST=127.0.0.1 CI_PG_PORT=5432 CI_PG_USER=postgres CI_PG_PASSWORD= CI_PG_DATABASE=odden_crm_pg_test
#   CI_MY_HOST=127.0.0.1 CI_MY_PORT=3306 CI_MY_USER=root     CI_MY_PASSWORD= CI_MY_DATABASE=odden_crm_my_test
#   CI_MY_CONNECTION=mariadb   (or mysql, for a MySQL server)
#
# By default the MariaDB step does not use your server at all: it starts a throwaway MariaDB (the same binary as Herd's) on a RAM disk
# and a free port, runs the suite there, and shuts it down and frees the disk afterwards (scripts/ci-ram-mariadb.sh; the suite takes
# about half as long, because creating table files is slow on this Mac's disk). It is used unless CI_MY_RAM=0 or you set CI_MY_HOST or
# CI_MY_PORT yourself, and if it cannot start the step says why and uses the server named by the variables above.
#
# Each package has its own vendor/ (composer update inside packages/<name>); a missing one fails the run, it is not skipped.
#
# Not covered here, and covered by the old workflow's matrix: PHP 8.3 and the Laravel 12 / lowest-dependency combinations. This runs
# the PHP and dependency versions installed in the vendor directories.
#
# A step that cannot run (no server, no client) fails; it is never skipped silently. Use --only to leave one out on purpose.

set -uo pipefail

cd "$(dirname "$0")/.."

PACKAGES=(core sales filament)

only=""
keep_going=0
report=0
quick=0
fresh=0

while [ $# -gt 0 ]; do
    case "$1" in
        --only) only="${2:-}"; shift 2 ;;
        --keep-going) keep_going=1; shift ;;
        --report) report=1; shift ;;
        --quick) quick=1; shift ;;
        --fresh) fresh=1; shift ;;
        -h|--help) sed -n '2,/^[^#]/{/^#/p;}' "$0"; exit 0 ;;
        *) echo "Unknown option: $1" >&2; exit 2 ;;
    esac
done

# --report is refused up front, not after the suites have run: a run that cannot be reported is a wasted run.
if [ "$report" -eq 1 ]; then
    if [ "$quick" -eq 1 ]; then echo "Not reporting: --quick is not the gate." >&2; exit 1; fi
    if [ -n "$only" ]; then echo "Not reporting: --only ran part of the steps." >&2; exit 1; fi
    if [ -n "$(git status --porcelain --untracked-files=no)" ]; then echo "Cannot --report: the working tree has uncommitted changes, so HEAD is not what is tested. Commit or stash them first." >&2; exit 1; fi
    command -v gh >/dev/null || { echo "Cannot --report: gh is not installed." >&2; exit 1; }
    report_repo="$(gh repo view --json nameWithOwner --jq .nameWithOwner)" || { echo "Cannot --report: gh cannot tell which repository this is." >&2; exit 1; }
    gh api "repos/$report_repo/commits/$(git rev-parse HEAD)" --silent 2>/dev/null || { echo "Cannot --report: HEAD $(git rev-parse --short HEAD) is not on GitHub yet, so it cannot carry a status. Push it first." >&2; exit 1; }
fi

PG_HOST="${CI_PG_HOST:-127.0.0.1}"; PG_PORT="${CI_PG_PORT:-5432}"; PG_USER="${CI_PG_USER:-postgres}"
PG_PASSWORD="${CI_PG_PASSWORD:-}"; PG_DATABASE="${CI_PG_DATABASE:-odden_crm_pg_test}"
MY_HOST="${CI_MY_HOST:-127.0.0.1}"; MY_PORT="${CI_MY_PORT:-3306}"; MY_USER="${CI_MY_USER:-root}"
MY_PASSWORD="${CI_MY_PASSWORD:-}"; MY_DATABASE="${CI_MY_DATABASE:-odden_crm_my_test}"; MY_CONNECTION="${CI_MY_CONNECTION:-mariadb}"

results=()
failed=0
log="$(mktemp -d)"

# A database name that is not a plain identifier is refused: it is put into DROP DATABASE.
for name in "$PG_DATABASE" "$MY_DATABASE"; do
    case "$name" in
        *[!A-Za-z0-9_]*|"") echo "Refusing database name '$name': letters, digits and underscores only." >&2; exit 2 ;;
    esac
done

find_client() {
    # $1 = names to try, in order; also looks in Laravel Herd's bin directory.
    local name
    for name in $1; do
        if command -v "$name" >/dev/null 2>&1; then command -v "$name"; return 0; fi
        if [ -x "$HOME/Library/Application Support/Herd/bin/$name" ]; then echo "$HOME/Library/Application Support/Herd/bin/$name"; return 0; fi
    done
    return 1
}

run_step() {
    local name="$1"; shift
    if [ -n "$only" ] && [ "$only" != "$name" ]; then return 0; fi
    if [ "$failed" -ne 0 ] && [ "$keep_going" -eq 0 ]; then results+=("$name: not run"); return 0; fi

    printf '\n==> %s\n' "$name"
    local started=$SECONDS
    if "$@" >"$log/$name.log" 2>&1; then
        results+=("$name: passed ($((SECONDS - started))s)")
        tail -n 3 "$log/$name.log"
    else
        results+=("$name: FAILED ($((SECONDS - started))s), log: $log/$name.log")
        failed=1
        tail -n 40 "$log/$name.log"
    fi
}

# The suites of every package, each from its own directory (the path repositories link the packages to one another there).
suites() {
    local package
    for package in "${PACKAGES[@]}"; do
        echo "== packages/$package"
        (cd "packages/$package" && vendor/bin/pest) || return 1
    done
}

# Shuts down the RAM-disk MariaDB, if this run started one, and frees its disk. Safe to call twice.
stop_ram_mariadb() {
    local CI_RAM_MY_PORT CI_RAM_MY_DEVICE CI_RAM_MY_DIR CI_RAM_MY_PID
    [ -f "$log/ram-mariadb.env" ] || return 0
    eval "$(cat "$log/ram-mariadb.env")"
    rm -f "$log/ram-mariadb.env"
    scripts/ci-ram-mariadb.sh stop "$CI_RAM_MY_DEVICE" "$CI_RAM_MY_DIR" "$CI_RAM_MY_PID"
}
trap stop_ram_mariadb EXIT INT TERM

step_setup() {
    [ -d vendor ] || { echo "vendor/ is missing: run composer install."; return 1; }
    local package
    for package in "${PACKAGES[@]}"; do
        [ -x "packages/$package/vendor/bin/pest" ] || { echo "packages/$package/vendor is missing: run composer update inside it."; return 1; }
    done
    [ -f .env ] || { cp .env.example .env && php artisan key:generate --ansi; }
}

step_pint() { vendor/bin/pint --test; }

# The check .github/workflows/docs.yml ran on pull requests: every page in the navigation exists, every page is listed, every link resolves.
step_docs() { php .github/scripts/check-docs.php docs; }

step_phpstan() { vendor/bin/phpstan analyse --no-progress --memory-limit=2G; }

step_workbench() { php artisan test; }

step_sqlite() { suites; }

step_pgsql() {
    local psql
    psql="$(find_client "psql")" || { echo "psql not found."; return 1; }
    export PGPASSWORD="$PG_PASSWORD"
    "$psql" -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d postgres -v ON_ERROR_STOP=1 -q \
        -c "DROP DATABASE IF EXISTS $PG_DATABASE" -c "CREATE DATABASE $PG_DATABASE" || return 1
    DB_CONNECTION=pgsql DB_HOST="$PG_HOST" DB_PORT="$PG_PORT" DB_DATABASE="$PG_DATABASE" DB_USERNAME="$PG_USER" DB_PASSWORD="$PG_PASSWORD" \
        suites
}

step_mariadb() {
    local client args rc ram_env
    if [ "${CI_MY_RAM:-1}" != 0 ] && [ -z "${CI_MY_HOST:-}${CI_MY_PORT:-}" ]; then
        if ram_env="$(scripts/ci-ram-mariadb.sh start 2>"$log/ram-mariadb.err")"; then
            echo "$ram_env" >"$log/ram-mariadb.env"
            eval "$ram_env"
            MY_HOST=127.0.0.1; MY_PORT="$CI_RAM_MY_PORT"; MY_USER=root; MY_PASSWORD=""
            echo "MariaDB on a RAM disk, port $MY_PORT"
        else
            echo "A RAM-disk MariaDB could not be started ($(cat "$log/ram-mariadb.err")), so the MariaDB at $MY_HOST:$MY_PORT is used."
        fi
    fi
    step_mariadb_run
    rc=$?
    stop_ram_mariadb
    return "$rc"
}

step_mariadb_run() {
    local client args
    client="$(find_client "mariadb mysql")" || { echo "mariadb/mysql client not found."; return 1; }
    args=(--protocol=tcp -h "$MY_HOST" -P "$MY_PORT" -u "$MY_USER")
    [ -n "$MY_PASSWORD" ] && args+=("-p$MY_PASSWORD")
    "$client" "${args[@]}" -e "DROP DATABASE IF EXISTS $MY_DATABASE; CREATE DATABASE $MY_DATABASE CHARACTER SET utf8mb4" || return 1
    DB_CONNECTION="$MY_CONNECTION" DB_HOST="$MY_HOST" DB_PORT="$MY_PORT" DB_DATABASE="$MY_DATABASE" DB_USERNAME="$MY_USER" DB_PASSWORD="$MY_PASSWORD" \
        suites
}

if ! step_setup >"$log/setup.log" 2>&1; then cat "$log/setup.log"; exit 1; fi

green_file="$(git rev-parse --git-path local-ci-green)"
green_key="$(git rev-parse 'HEAD^{tree}') php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
reused=""
reuse_note=""
if [ "$report" -eq 1 ] && [ "$fresh" -eq 0 ] && [ -f "$green_file" ]; then
    reused="$(grep -F -- "$green_key " "$green_file" | tail -n 1 || true)"
fi

if [ -n "$reused" ]; then
    read -r _ _ reused_sha reused_at <<<"$reused"
    results+=("all steps: reused, this tree passed in full at $reused_at (commit ${reused_sha:0:7}); use --fresh to run again")
    reuse_note="Reused: the same tree passed all steps locally ($reused_at, commit ${reused_sha:0:7})"
else
    run_step pint step_pint
    run_step docs step_docs
    run_step phpstan step_phpstan
    run_step workbench step_workbench
    run_step sqlite step_sqlite
    if [ "$quick" -eq 1 ]; then
        results+=("pgsql: skipped (--quick)" "mariadb: skipped (--quick)")
    else
        run_step pgsql step_pgsql
        run_step mariadb step_mariadb
    fi
fi

printf '\n== Local CI ==\n'
printf '%s\n' "${results[@]}"

if [ "$failed" -ne 0 ]; then exit 1; fi

# Only a complete pass of a clean tree is remembered.
if [ -z "$reused" ] && [ "$quick" -eq 0 ] && [ -z "$only" ] && [ -z "$(git status --porcelain --untracked-files=no)" ]; then
    echo "$green_key $(git rev-parse HEAD) $(date -u +%Y-%m-%dT%H:%MZ)" >> "$green_file"
fi

if [ "$report" -eq 1 ]; then
    if [ -n "$(git status --porcelain --untracked-files=no)" ]; then echo "Not reporting: the working tree has uncommitted changes, so HEAD is not what was tested (the run changed the tree)." >&2; exit 1; fi
    sha="$(git rev-parse HEAD)"
    repo="$report_repo"
    gh api "repos/$repo/statuses/$sha" -f state=success -f context=local-ci \
        -f description="${reuse_note:-Pint, PHPStan, Workbench, three package suites on SQLite, PostgreSQL, MariaDB passed locally ($(date -u +%Y-%m-%dT%H:%MZ))}" >/dev/null || exit 1
    echo "Set the local-ci status on $sha"
fi
