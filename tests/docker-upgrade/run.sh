#!/usr/bin/env bash
# Offline test suite for `./namingo upgrade`.
#
# It does not need Docker. A fake `docker` executable records every call and
# simulates the few Compose behaviours the upgrade relies on, while a local git
# repository with release tags plays the role of GitHub. The real
# docs/migrations/*.sh files are executed through the same stdin-fed
# `sh -s -- <phase>` path used in production, against a fake `mariadb` client.
#
# This checks orchestration (ordering, rollback, retry, locking, env merging,
# local-change protection). It cannot prove the images or SQL work on a real
# stack; run a real upgrade on a scratch host for that.
#
# Usage: tests/docker-upgrade/run.sh

set -Eeuo pipefail

repo_root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

pass=0
fail=0

ok() { pass=$((pass + 1)); printf '  ok   %s\n' "$1"; }
bad() { fail=$((fail + 1)); printf '  FAIL %s\n' "$1"; }

check() {
    local name=$1
    shift
    if "$@"; then ok "$name"; else bad "$name"; fi
}

# ---------------------------------------------------------------------------
# Fake tools
# ---------------------------------------------------------------------------

bin="$work/bin"
mkdir -p "$bin"

cat > "$bin/docker" <<'FAKE_DOCKER'
#!/usr/bin/env bash
# Fake docker for namingo upgrade tests. State lives in $FAKE_STATE.
set -u
log() { printf '%s\n' "$*" >> "$FAKE_STATE/calls.log"; }

[[ "${1:-}" == info ]] && exit 0
[[ "${1:-}" == compose ]] || exit 0
shift
[[ "${1:-}" == version ]] && { echo "Docker Compose version v2.99.0"; exit 0; }
if [[ "${1:-}" == --project-directory ]]; then shift 2; fi

sub=${1:-}
shift || true

case "$sub" in
    config)
        if [[ "${1:-}" == --services ]]; then
            cat "$FAKE_STATE/services"
            exit 0
        fi
        [[ -e "$FAKE_STATE/config_fail" ]] && { log "config FAIL"; exit 1; }
        exit 0
        ;;
    pull)
        log "pull"
        [[ -e "$FAKE_STATE/pull_fail" ]] && exit 1
        exit 0
        ;;
    up)
        if [[ "${1:-}" == --help ]]; then echo "      --wait"; exit 0; fi
        log "up"
        [[ -e "$FAKE_STATE/up_fail" ]] && exit 1
        exit 0
        ;;
    stop)
        log "stop $*"
        exit 0
        ;;
    ps)
        if [[ "${1:-}" == --all ]]; then
            log "ps-verify"
            cat "$FAKE_STATE/ps"
        else
            log "ps"
        fi
        exit 0
        ;;
    exec)
        [[ "${1:-}" == -T ]] && shift
        target=${1:-}
        shift || true
        case "$target" in
            database)
                case "${1:-}" in
                    healthcheck.sh)
                        log "db-healthcheck"
                        [[ -e "$FAKE_STATE/db_down" ]] && exit 1
                        exit 0
                        ;;
                    sh)
                        if [[ "${2:-}" == -c ]]; then
                            # Migration protocol: sh -c <script> name <version> <phase>,
                            # with the staged migrations directory as a tar stream on stdin.
                            version=${5:-}
                            phase=${6:-}
                            log "migration-start $phase $version"
                            sh -c "$3" "${4:-}" "$version" "$phase"
                            rc=$?
                            log "migration-end $phase $version rc=$rc"
                            exit $rc
                        fi
                        log "dump"
                        echo "-- fake dump"
                        [[ -e "$FAKE_STATE/dump_truncated" ]] || echo "-- Dump completed on 2026-01-01"
                        exit 0
                        ;;
                esac
                ;;
            automation|caddy)
                log "tar-$target"
                tar -czf - --files-from /dev/null
                exit 0
                ;;
            runtime)
                if [[ "${1:-}" == redis-cli && "${2:-}" == ping ]]; then log "redis-ping"; exit 0; fi
                log "redis-snapshot"
                tar -czf - --files-from /dev/null
                exit 0
                ;;
            rdap|panel)
                log "doctor-$target"
                exit 0
                ;;
        esac
        ;;
esac
exit 0
FAKE_DOCKER

cat > "$bin/mariadb" <<'FAKE_MARIADB'
#!/usr/bin/env bash
sql=$(cat)
{
    printf 'mariadb args: %s\n' "$*"
    printf 'MYSQL_PWD matches: %s\n' "$([[ "${MYSQL_PWD:-}" == "$FAKE_DB_PASSWORD" ]] && echo yes || echo no)"
    printf 'sql: %s\n' "$sql"
} >> "$FAKE_STATE/mariadb.log"
[[ -e "$FAKE_STATE/mariadb_fail" ]] && exit 1
exit 0
FAKE_MARIADB
chmod +x "$bin/docker" "$bin/mariadb"

export PATH="$bin:$PATH"
export FAKE_DB_PASSWORD="s3cr3t'pass \$word"

# ---------------------------------------------------------------------------
# Fake upstream: releases v1.0.33, v1.0.35 and v1.0.36
# ---------------------------------------------------------------------------

upstream="$work/upstream.git"
build="$work/upstream-build"
git init -q --bare "$upstream"
git init -q "$build"
git -C "$build" config user.email test@example.invalid
git -C "$build" config user.name Test

write_release() {
    local version=$1
    mkdir -p "$build/docs/migrations" "$build/docker/certs" "$build/docker/secrets" "$build/docker/config"
    cp "$repo_root/namingo" "$build/namingo"
    chmod 644 "$build/namingo"   # real tags store it as 100644
    cp "$repo_root/.gitignore" "$build/.gitignore"
    printf '%s\n' "$version" > "$build/VERSION"
    printf 'services: {}\n# release %s\n' "$version" > "$build/compose.yaml"
    : > "$build/docker/certs/.gitignore"
    : > "$build/docker/secrets/.gitignore"
    printf '<?php\nreturn [];\n' > "$build/docker/config/automation.override.php"
    {
        printf 'NAMINGO_DOMAIN=\n'
        printf 'NAMINGO_DB_USER=namingo\n'
        printf 'NAMINGO_IMAGE=ghcr.io/getnamingo/registry:latest\n'
        printf 'NAMINGO_WEB_IMAGE=ghcr.io/getnamingo/registry-web:latest\n'
        printf 'NAMINGO_MARIADB_IMAGE=mariadb:11.8\n'
        printf 'NAMINGO_REDIS_IMAGE=redis:8.2-alpine\n'
    } > "$build/.env.example"
}

commit_release() {
    local version=$1
    git -C "$build" add -A
    git -C "$build" commit -q -m "release $version"
    git -C "$build" tag "v$version"
}

write_release 1.0.33
commit_release 1.0.33

write_release 1.0.35
cp "$repo_root"/docs/migrations/*.sh "$build/docs/migrations/"
printf '\n# changed in 1.0.35\n' >> "$build/namingo"
printf 'NAMINGO_NEW_FEATURE=on\n' >> "$build/.env.example"
sed -i 's|mariadb:11.8|mariadb:11.9|' "$build/.env.example"
commit_release 1.0.35

write_release 1.0.36
cp "$repo_root"/docs/migrations/*.sh "$build/docs/migrations/"
printf '<?php\n// changed upstream\nreturn [];\n' > "$build/docker/config/automation.override.php"
commit_release 1.0.36

git -C "$build" -c protocol.version=0 push -q "$upstream" --tags HEAD:refs/heads/main 2>/dev/null

# ---------------------------------------------------------------------------
# Per-test installation, mirroring what docker-install.sh produces
# ---------------------------------------------------------------------------

new_install() {
    local name=$1 tag=${2:-1.0.33}
    inst="$work/$name"
    export FAKE_STATE="$work/$name-state"
    rm -rf "$inst" "$FAKE_STATE"
    mkdir -p "$FAKE_STATE"
    printf 'database\nruntime\npanel\nepp\nrdap\n' > "$FAKE_STATE/services"
    printf 'panel|running|healthy|0\nepp|running|healthy|0\ndatabase|running|healthy|0\ninit|exited||0\n' > "$FAKE_STATE/ps"
    git -c advice.detachedHead=false clone -q --depth 1 --branch "v$tag" "file://$upstream" "$inst"
    git -C "$inst" config user.email test@example.invalid
    git -C "$inst" config user.name Test
    # docker-install.sh rewrites the image tags in the tracked .env.example.
    sed -i "s|registry:latest|registry:v$tag|; s|registry-web:latest|registry-web:v$tag|" "$inst/.env.example"
    cp "$inst/.env.example" "$inst/.env"
    printf 'CUSTOM_OPERATOR_SETTING=keep-me\n' >> "$inst/.env"
    chmod 0600 "$inst/.env"
    chmod +x "$inst/namingo"   # what docker-install.sh does; git now reports a mode change
    printf '%s\n' "$FAKE_DB_PASSWORD" > "$inst/docker/secrets/db_password.txt"
    export NAMINGO_REPOSITORY="file://$upstream"
}

run() {
    # run <args...>: captures output and exit status without aborting the suite.
    out=$work/out.txt
    set +e
    (cd "$inst" && ./namingo "$@" </dev/null >"$out" 2>&1)
    status=$?
    set -e
    if [[ -n "${NAMINGO_TEST_VERBOSE:-}" ]]; then
        printf '    --- ./namingo %s (exit %s)\n' "$*" "$status"
        sed 's/^/    | /' "$out"
    fi
}

calls() { cat "$FAKE_STATE/calls.log" 2>/dev/null || true; }
calls_have() { calls | grep -q -- "$1"; }
calls_lack() { ! calls_have "$1"; }
head_tag() { git -C "$inst" describe --tags --exact-match 2>/dev/null || echo none; }
installed() { tr -d '[:space:]' < "$inst/.namingo/installed-version" 2>/dev/null || echo none; }

# True when $1 appears before $2 in the call log (first occurrences).
before() {
    local a b
    a=$(calls | grep -n -m1 -- "$1" | cut -d: -f1 || true)
    b=$(calls | grep -n -m1 -- "$2" | cut -d: -f1 || true)
    [[ -n "$a" && -n "$b" && "$a" -lt "$b" ]]
}

# ---------------------------------------------------------------------------
# Tests
# ---------------------------------------------------------------------------

printf '\n[syntax]\n'
check "namingo passes bash -n" bash -n "$repo_root/namingo"
check "all migrations pass sh -n" bash -c 'for f in "$0"/docs/migrations/*.sh; do sh -n "$f" || exit 1; done' "$repo_root"
if command -v shellcheck >/dev/null 2>&1; then
    check "shellcheck (errors only)" shellcheck -S error "$repo_root/namingo"
fi

printf '\n[dry run]\n'
new_install dry
before_env=$(cat "$inst/.env")
run upgrade --target 1.0.35 --dry-run
check "exit 0" test "$status" -eq 0
check "lists both migrations" grep -q 'Migrations: 1.0.34 1.0.35' "$out"
check "lists the new .env setting" grep -q 'NAMINGO_NEW_FEATURE' "$out"
check "reports the MariaDB default without applying it" grep -q 'NAMINGO_MARIADB_IMAGE: yours is mariadb:11.8, this release defaults to mariadb:11.9' "$out"
check "stays on v1.0.33" test "$(head_tag)" = v1.0.33
check ".env untouched" test "$(cat "$inst/.env")" = "$before_env"
check "no backup, pull or stop happened" bash -c '! grep -Eq "dump|pull|stop|up$" "$0/calls.log" 2>/dev/null' "$FAKE_STATE"
check "no lock or backup directory created" test ! -d "$inst/backups"

run upgrade --dry-run
check "without --target the newest release tag is chosen" grep -q 'Target:     v1.0.36' "$out"

printf '\n[successful upgrade 1.0.33 -> 1.0.35]\n'
new_install ok
run upgrade --target 1.0.35 --yes
check "exit 0" test "$status" -eq 0
check "checkout is v1.0.35" test "$(head_tag)" = v1.0.35
check "installed-version recorded" test "$(installed)" = 1.0.35
check "image tags pinned to the new release" grep -q '^NAMINGO_IMAGE=ghcr.io/getnamingo/registry:v1.0.35$' "$inst/.env"
check "web image pinned" grep -q '^NAMINGO_WEB_IMAGE=ghcr.io/getnamingo/registry-web:v1.0.35$' "$inst/.env"
check "script replaced by the release while it was running (upgrade still completed)" grep -q 'changed in 1.0.35' "$inst/namingo"
check "namingo stays executable after the upgrade" test -x "$inst/namingo"
check "operator setting preserved" grep -q '^CUSTOM_OPERATOR_SETTING=keep-me$' "$inst/.env"
check "new setting appended" grep -q '^NAMINGO_NEW_FEATURE=on$' "$inst/.env"
check "MariaDB image not changed" grep -q '^NAMINGO_MARIADB_IMAGE=mariadb:11.8$' "$inst/.env"
check "order: backup before pull" before dump pull
check "order: pull before stop" before pull 'stop '
check "pre migrations are not run in Docker" calls_lack 'migration-start pre'
check "order: stop before post migration" before 'stop ' 'migration-start post'
check "order: post migration before up" before 'migration-start post' '^up$'
check "order: up before verification" before '^up$' ps-verify
check "database and runtime are not stopped" bash -c '! grep -E "^stop .*(database|runtime)" "$0/calls.log"' "$FAKE_STATE"
check "application services are stopped" calls_have 'stop panel epp rdap'
check "operator is told pre steps are skipped" grep -q 'skipped in Docker' "$out"
check "host-only phpBU download never attempted" bash -c '! grep -qi phpbu "$0"' "$out"
check "migration finds its sibling audit-sync.sh helper (v1.0.34 and v1.0.35 both finish)" bash -c 'grep -q "migration-end post 1.0.34 rc=0" "$0/calls.log" && grep -q "migration-end post 1.0.35 rc=0" "$0/calls.log"' "$FAKE_STATE"
check "post migrations ran SQL (secdns)" grep -q 'ALTER TABLE secdns' "$FAKE_STATE/mariadb.log"
check "post migrations ran SQL (domain_tld)" grep -q 'ALTER TABLE domain_tld' "$FAKE_STATE/mariadb.log"
check "migration saw the right DB password (quoting round-trip)" bash -c '! grep -q "MYSQL_PWD matches: no" "$0/mariadb.log"' "$FAKE_STATE"
check "password never on a docker command line" bash -c '! grep -q "s3cr3t" "$0/calls.log"' "$FAKE_STATE"
check "backup contains env.before" test -f "$(ls -d "$inst"/backups/*/ | head -n1)env.before"
check "lock released" test ! -d "$inst/.namingo/upgrade.lock"

printf '\n[already current]\n'
new_install current 1.0.36
run upgrade --yes
check "upgrade says already installed" grep -q 'already installed' "$out"
check "upgrade does nothing when current" bash -c '! grep -Eq "dump|pull|stop|up$" "$0/calls.log" 2>/dev/null' "$FAKE_STATE"
run update
check "update refreshes images when current" calls_have '^pull$'
check "update reconciles when current" calls_have '^up$'
check "update takes no backup when current" calls_lack dump

printf '\n[pull failure restores everything]\n'
new_install pullfail
before_env=$(cat "$inst/.env")
touch "$FAKE_STATE/pull_fail"
run upgrade --target 1.0.35 --yes
check "exit non-zero" test "$status" -ne 0
check "back on v1.0.33" test "$(head_tag)" = v1.0.33
check ".env restored byte for byte" test "$(cat "$inst/.env")" = "$before_env"
check "running stack untouched (no stop, no up)" bash -c '! grep -Eq "^(stop|up)" "$0/calls.log"' "$FAKE_STATE"
check "installed version unchanged" test "$(installed)" = 1.0.33
check "says previous files were restored" grep -q 'previous release files were restored' "$out"

printf '\n[post-migration failure, then retry]\n'
new_install retry
touch "$FAKE_STATE/mariadb_fail"
run upgrade --target 1.0.35 --yes
check "exit non-zero" test "$status" -ne 0
check "installed version stays 1.0.33" test "$(installed)" = 1.0.33
check "new release never started" calls_lack '^up$'
check "recovery instructions printed" grep -q 'Then retry' "$out"
check "restore instructions name the backup" grep -q 'env.before' "$out"
rm -f "$FAKE_STATE/mariadb_fail"
: > "$FAKE_STATE/calls.log"
run upgrade --target 1.0.35 --yes
check "retry succeeds" test "$status" -eq 0
check "retry reaches v1.0.35" test "$(installed)" = 1.0.35
check "retry re-runs migrations" calls_have 'migration-start post'
check "retry takes a fresh backup" calls_have dump
check "retry pins images once, not twice" test "$(grep -c '^NAMINGO_IMAGE=' "$inst/.env")" -eq 1

printf '\n[verification failure]\n'
new_install verify
printf 'panel|running|unhealthy|0\n' > "$FAKE_STATE/ps"
run upgrade --target 1.0.35 --yes
check "exit non-zero" test "$status" -ne 0
check "installed version not advanced" test "$(installed)" = 1.0.33
check "tells the operator the stack started but failed" grep -q 'did not pass verification' "$out"

printf '\n[unreadable service listing]\n'
new_install nolist
: > "$FAKE_STATE/ps"
run upgrade --target 1.0.35 --yes
check "an empty listing does not count as healthy" test "$status" -ne 0
check "says it could not read service states" grep -q 'Could not read any service state' "$out"
check "installed version not advanced" test "$(installed)" = 1.0.33

printf '\n[backup safety]\n'
new_install trunc
touch "$FAKE_STATE/dump_truncated"
run upgrade --target 1.0.35 --yes
check "truncated dump aborts" test "$status" -ne 0
check "release files never switched" test "$(head_tag)" = v1.0.33
check "nothing stopped" calls_lack '^stop'

new_install dbdown
touch "$FAKE_STATE/db_down"
run upgrade --target 1.0.35 --yes
check "unhealthy database aborts" test "$status" -ne 0
check "no backup attempted" calls_lack dump

printf '\n[local changes]\n'
new_install local
printf '<?php\nreturn ["mine" => true];\n' > "$inst/docker/config/automation.override.php"
run upgrade --target 1.0.35 --yes
check "override unchanged upstream: upgrade succeeds" test "$status" -eq 0
check "operator override preserved" grep -q 'mine' "$inst/docker/config/automation.override.php"

new_install conflict
printf '<?php\nreturn ["mine" => true];\n' > "$inst/docker/config/automation.override.php"
run upgrade --target 1.0.36 --yes
check "override changed upstream: refused" test "$status" -ne 0
check "conflicting file is named" grep -q 'docker/config/automation.override.php' "$out"
check "refused before any backup" calls_lack dump
check "still on v1.0.33" test "$(head_tag)" = v1.0.33

printf '\n[locking]\n'
new_install lock
mkdir -p "$inst/.namingo/upgrade.lock"
sleep 30 &
holder=$!
printf '%s\n' "$holder" > "$inst/.namingo/upgrade.lock/pid"
run upgrade --target 1.0.35 --yes
kill "$holder" 2>/dev/null || true
check "live lock refuses" test "$status" -ne 0
check "message mentions another upgrade" grep -q 'Another upgrade' "$out"
printf '999999\n' > "$inst/.namingo/upgrade.lock/pid"
run upgrade --target 1.0.35 --yes
check "stale lock is cleaned up" test "$status" -eq 0

printf '\n[argument and state handling]\n'
new_install args
run upgrade --target 1.0.20 --yes
check "downgrade refused" test "$status" -ne 0
run upgrade --target banana --yes
check "invalid version refused" test "$status" -ne 0
run upgrade
check "no tty and no --yes refused" test "$status" -ne 0
check "tells the operator to pass --yes" grep -q -- '--yes' "$out"
run upgrade --bogus
check "unknown option refused" test "$status" -ne 0

new_install legacy
rm -rf "$inst/.namingo"
run upgrade --target 1.0.35 --yes
check "install without marker falls back to VERSION" test "$status" -eq 0
check "marker written after upgrade" test "$(installed)" = 1.0.35

printf '\n[bootstrap from an old installation]\n'
new_install boot
cp "$inst/namingo" "$inst/namingo-upgrade"
set +e
(cd "$inst" && bash ./namingo-upgrade upgrade --target 1.0.35 --yes </dev/null >"$out" 2>&1)
status=$?
set -e
check "side-loaded script upgrades the checkout" test "$status" -eq 0
check "reaches v1.0.35" test "$(installed)" = 1.0.35
check "untracked side-loaded copy did not block the checkout" test "$(head_tag)" = v1.0.35

printf '\nResult: %d passed, %d failed\n' "$pass" "$fail"
[[ "$fail" -eq 0 ]]
