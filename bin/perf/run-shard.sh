#!/usr/bin/env bash
# Measure every project of one shard against one Psalm checkout.
# See bin/perf/README.md. Exit 0 even when a project crashes (the status is
# recorded in <out>/<project>.json); non-zero only for harness/usage errors.
#
# Written for bash 3.2 (macOS): no mapfile, no empty-array expansion.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
CORPUS="$SCRIPT_DIR/corpus.json"

die() { echo "run-shard: $*" >&2; exit 2; }

usage() {
    echo "usage: run-shard.sh --shard <id> --psalm <psalm checkout> --out <dir> [--work <dir>]" >&2
    exit 2
}

SHARD='' PSALM_DIR='' OUT='' WORK="${RUNNER_TEMP:-/tmp}/perf-work"
while [ $# -gt 0 ]; do
    case "$1" in
        --shard) [ $# -ge 2 ] || usage; SHARD=$2; shift 2 ;;
        --psalm) [ $# -ge 2 ] || usage; PSALM_DIR=$2; shift 2 ;;
        --out)   [ $# -ge 2 ] || usage; OUT=$2; shift 2 ;;
        --work)  [ $# -ge 2 ] || usage; WORK=$2; shift 2 ;;
        *) usage ;;
    esac
done
[ -n "$SHARD" ] && [ -n "$PSALM_DIR" ] && [ -n "$OUT" ] || usage

for bin in jq git php composer; do
    command -v "$bin" >/dev/null 2>&1 || die "missing required tool: $bin"
done
[ -f "$CORPUS" ] || die "missing $CORPUS"
[ -f "$PSALM_DIR/psalm" ] || die "not a Psalm checkout: $PSALM_DIR"
[ -f "$PSALM_DIR/vendor/autoload.php" ] || die "Psalm dependencies are not installed in $PSALM_DIR"

PSALM_DIR="$(cd "$PSALM_DIR" && pwd -P)"
mkdir -p "$OUT" "$WORK"
OUT="$(cd "$OUT" && pwd -P)"
WORK="$(cd "$WORK" && pwd -P)"

MODE="$(jq -r --arg s "$SHARD" '.shards[$s].mode // empty' "$CORPUS")"
[ -n "$MODE" ] || die "unknown shard: $SHARD"
# Local testing only: cachegrind is unavailable on macOS.
if [ -n "${PERF_FORCE_MODE:-}" ]; then
    case "$PERF_FORCE_MODE" in
        native|cachegrind) MODE=$PERF_FORCE_MODE ;;
        *) die "PERF_FORCE_MODE must be native or cachegrind" ;;
    esac
fi

# Psalm ignores `php -d memory_limit`: only --memory-limit caps it. A cap below the runner's RAM turns a
# blow-up into a crash with the fatal error in log_tail instead of an OOM-killed job.
MEMORY_LIMIT="$(jq -r '.memory_limit // "12G"' "$CORPUS")"

PHP_BIN="$(command -v php)"
if [ "$MODE" = cachegrind ]; then
    command -v valgrind >/dev/null 2>&1 || die "valgrind is required for cachegrind shards"
else
    [ -x /usr/bin/time ] || die "/usr/bin/time is required for native shards"
    if [ "$(uname -s)" = Darwin ]; then TIME_FLAG=-l; else TIME_FLAG=-v; fi
fi

# timeout (GNU coreutils) is gtimeout on macOS; `env` is a harmless stand-in.
if command -v timeout >/dev/null 2>&1; then TIMEOUT_BIN=timeout
elif command -v gtimeout >/dev/null 2>&1; then TIMEOUT_BIN=gtimeout
else TIMEOUT_BIN=''; echo "run-shard: no timeout binary found, per-project timeouts are not enforced" >&2
fi

# Psalm re-execs itself (xdebug-handler) unless this is set; then the opcache
# settings must be passed explicitly.
export PSALM_ALLOW_XDEBUG=1
export COMPOSER_NO_INTERACTION=1
PHP_FLAGS=(
    -d opcache.enable=1 -d opcache.enable_cli=1 -d opcache.jit=0
    -d opcache.validate_timestamps=0 -d opcache.file_update_protection=0
    -d opcache.max_accelerated_files=1000000 -d opcache.interned_strings_buffer=64
    -d opcache.memory_consumption=512 -d opcache.optimization_level=0x7FFEBFFF
    -d opcache.save_comments=1 -d memory_limit=-1
)

sha256() {
    if command -v sha256sum >/dev/null 2>&1; then sha256sum | cut -d' ' -f1; else shasum -a 256 | cut -d' ' -f1; fi
}

now() { "$PHP_BIN" -r 'echo microtime(true);'; }

# JSON number or null from a possibly empty string.
num() { if [ -n "${1:-}" ]; then printf '%s' "$1"; else printf 'null'; fi; }

# ---------------------------------------------------------------- environment
harness_hash() {
    (
        cd "$SCRIPT_DIR"
        find . -type f ! -name README.md ! -name .DS_Store | LC_ALL=C sort | while IFS= read -r f; do
            printf '%s\0' "$f"
            cat "$f"
        done | sha256
    )
}

cpu_model() {
    local model=''
    if [ -r /proc/cpuinfo ]; then
        model="$(awk -F': ' '/^model name/ {print $2; exit}' /proc/cpuinfo)"
    elif command -v sysctl >/dev/null 2>&1; then
        model="$(sysctl -n machdep.cpu.brand_string 2>/dev/null || true)"
    fi
    printf '%s' "${model:-unknown}"
}

PSALM_SHA="$(git -C "$PSALM_DIR" rev-parse HEAD 2>/dev/null || echo unknown)"
VALGRIND_VERSION=''
if command -v valgrind >/dev/null 2>&1; then VALGRIND_VERSION="$(valgrind --version | sed 's/^valgrind-//')"; fi
PSALM_DEPS="$(jq -r '(.packages // .)[] | "\(.name)@\(.version)"' "$PSALM_DIR/vendor/composer/installed.json" | LC_ALL=C sort | sha256)"
PSALM_PACKAGES="$(jq -c '[(.packages // .)[] | {key: .name, value: .version}] | from_entries' "$PSALM_DIR/vendor/composer/installed.json")"
ENV_JSON="$(jq -n \
    --argjson packages "$PSALM_PACKAGES" \
    --arg php "$("$PHP_BIN" -r 'echo PHP_VERSION;')" \
    --arg valgrind "$VALGRIND_VERSION" \
    --arg cpu "$(cpu_model)" \
    --arg harness "$(harness_hash)" \
    --arg deps "$PSALM_DEPS" \
    '{php: $php, valgrind: (if $valgrind == "" then null else $valgrind end), cpu: $cpu, harness: $harness, psalm_deps: $deps, psalm_packages: $packages}')"

# ------------------------------------------------------------ project helpers
# Per-project state, reset by measure_project.
NAME='' PROJ='' STATUS='' LOG_TAIL=''
IR='' MB='' WALL1='' CPU1='' RSS1='' WALL4='' RSS4='' MB4='' ISSUE_COUNT='' ISSUES_FILE=''

# Last 50 lines of a log, each clipped, as the failure excerpt.
set_log_tail() { LOG_TAIL="$(tail -n 50 "$1" 2>/dev/null | cut -c1-500 || true)"; }

write_result() {
    jq -n \
        --arg project "$NAME" --arg shard "$SHARD" --arg mode "$MODE" \
        --arg psalm_sha "$PSALM_SHA" --arg status "$STATUS" \
        --argjson ir "$(num "$IR")" --argjson mb "$(num "$MB")" \
        --argjson wall1 "$(num "$WALL1")" --argjson cpu1 "$(num "$CPU1")" --argjson rss1 "$(num "$RSS1")" \
        --argjson wall4 "$(num "$WALL4")" --argjson rss4 "$(num "$RSS4")" --argjson mb4 "$(num "$MB4")" \
        --argjson issue_count "$(num "$ISSUE_COUNT")" --arg issues_file "$ISSUES_FILE" \
        --argjson env "$ENV_JSON" --arg log "$LOG_TAIL" \
        '{
            schema: 1, project: $project, shard: $shard, mode: $mode,
            psalm_sha: $psalm_sha, status: $status,
            metrics: {ir: $ir, psalm_mb: $mb, wall_t1: $wall1, cpu_t1: $cpu1, rss_t1_kb: $rss1,
                      wall_t4: $wall4, rss_t4_kb: $rss4, psalm_mb_t4: $mb4},
            issue_count: $issue_count,
            issues_file: (if $issues_file == "" then null else $issues_file end),
            env: $env,
            log_tail: (if $log == "" then null else $log end)
        }' > "$OUT/$NAME.json"
}

# fetch_and_install <repo> <ref> <composer true|false> <setup snippet> <lock file or empty> <tag>
# Explicit `|| return 1`: this runs in a `||` context where `set -e` is inert.
fetch_and_install() {
    local repo=$1 ref=$2 composer=$3 setup=$4 lock=$5 tag=$6 attempt=1
    git -C "$PROJ" init -q || return 1
    git -C "$PROJ" remote add origin "$repo" || return 1
    until git -C "$PROJ" fetch -q --depth 1 origin "$ref"; do
        [ "$attempt" -lt 3 ] || { echo "git fetch of $ref from $repo failed" >&2; return 1; }
        attempt=$((attempt + 1))
        sleep 5
    done
    git -C "$PROJ" -c advice.detachedHead=false checkout -q FETCH_HEAD || return 1
    if [ -n "$lock" ]; then cp "$lock" "$PROJ/composer.lock" || return 1; fi
    if [ -n "$setup" ]; then (cd "$PROJ" && bash -c "$setup") || return 1; fi
    if [ "$composer" = true ] && [ -f "$PROJ/composer.json" ]; then
        # Locks of projects that depend on self.version (e.g. psl) need a root version; checkouts have no tag.
        (cd "$PROJ" && env ${tag:+COMPOSER_ROOT_VERSION="${tag#v}"} composer install --no-dev --no-scripts --no-plugins --ignore-platform-reqs --no-interaction --quiet) \
            || { echo "composer install failed in $PROJ" >&2; return 1; }
    fi
}

# prepare_project: clone, lock, setup, composer install, config. Returns 1 on failure.
prepare_project() {
    local repo ref tag composer setup config lock stamp
    repo="$(jq -r --arg n "$NAME" '.projects[$n].repo' "$CORPUS")"
    ref="$(jq -r --arg n "$NAME" '.projects[$n].ref' "$CORPUS")"
    tag="$(jq -r --arg n "$NAME" '.projects[$n].tag // ""' "$CORPUS")"
    composer="$(jq -r --arg n "$NAME" '.projects[$n] | if .composer == false then "false" else "true" end' "$CORPUS")"
    setup="$(jq -r --arg n "$NAME" '.projects[$n].setup // ""' "$CORPUS")"
    config="$(jq -r --arg n "$NAME" '.projects[$n].config // $n' "$CORPUS")"

    lock=''
    if [ -f "$SCRIPT_DIR/corpus/$NAME/composer.lock" ]; then lock="$SCRIPT_DIR/corpus/$NAME/composer.lock"
    elif [ -f "$SCRIPT_DIR/corpus/$config/composer.lock" ]; then lock="$SCRIPT_DIR/corpus/$config/composer.lock"
    fi
    [ -f "$SCRIPT_DIR/corpus/$config/psalm.xml" ] || { echo "missing bin/perf/corpus/$config/psalm.xml" > "$WORK/$NAME.setup.log"; return 1; }

    # A previous run in the same work dir (local runs) is reused when nothing changed.
    stamp="$ref $tag $composer $(printf '%s' "$setup" | sha256) $([ -n "$lock" ] && sha256 < "$lock" || echo nolock)"
    if [ ! -f "$WORK/$NAME.ready" ] || [ "$(cat "$WORK/$NAME.ready")" != "$stamp" ]; then
        rm -rf "$PROJ" "$WORK/$NAME.ready"
        mkdir -p "$PROJ"
        fetch_and_install "$repo" "$ref" "$composer" "$setup" "$lock" "$tag" > "$WORK/$NAME.setup.log" 2>&1 < /dev/null || return 1
        printf '%s' "$stamp" > "$WORK/$NAME.ready"
    fi

    # Our config, with loadLibraryPlugins=false iff the tested Psalm knows the attribute.
    local inject=0
    if grep -q loadLibraryPlugins "$PSALM_DIR/config.xsd" 2>/dev/null; then inject=1; fi
    "$PHP_BIN" -r '
        [, $src, $dst, $inject] = $argv;
        $xml = file_get_contents($src);
        if ($inject === "1" && !str_contains($xml, "loadLibraryPlugins=")) {
            $xml = preg_replace("/<psalm(?=[\s>\/])/", "<psalm loadLibraryPlugins=\"false\"", $xml, 1);
        }
        file_put_contents($dst, $xml);
    ' "$SCRIPT_DIR/corpus/$config/psalm.xml" "$PROJ/psalm-perf.xml" "$inject" || return 1
}

# run_psalm <threads> <report> <log> <time file> <cachegrind out>
# Sets RUN_RC and RUN_WALL (seconds).
RUN_RC=0 RUN_WALL=''
run_psalm() {
    local threads=$1 report=$2 log=$3 timefile=$4 cgout=$5 limit start end
    limit="$(jq -r --arg n "$NAME" '.projects[$n].timeout // 900' "$CORPUS")"

    local guard=(env)
    if [ -n "$TIMEOUT_BIN" ]; then guard=("$TIMEOUT_BIN" -k 20 "$limit"); fi

    local psalm=("$PHP_BIN" "${PHP_FLAGS[@]}" "$PSALM_DIR/psalm" --root="$PROJ" --config="$PROJ/psalm-perf.xml"
        --no-cache --no-progress --ignore-baseline --output-format=console --report="$report" --threads="$threads" --memory-limit="$MEMORY_LIMIT")

    local full
    if [ "$MODE" = cachegrind ]; then
        full=("${guard[@]}" valgrind --tool=cachegrind --cache-sim=no --cachegrind-out-file="$cgout" "${psalm[@]}")
    else
        full=(/usr/bin/time "$TIME_FLAG" -o "$timefile" "${guard[@]}" "${psalm[@]}")
    fi

    RUN_RC=0
    start="$(now)"
    (cd "$PROJ" && "${full[@]}") > "$log" 2>&1 < /dev/null || RUN_RC=$?
    end="$(now)"
    RUN_WALL="$(awk -v a="$start" -v b="$end" 'BEGIN { printf "%.2f", b - a }')"
}

# Psalm's own "Checks took X seconds and used Y MB of memory" line → MB.
parse_psalm_mb() {
    grep -E 'Checks took .* seconds and used .*MB of memory' "$1" | tail -n 1 \
        | sed -E 's/.*used ([0-9.,]+)MB.*/\1/' | tr -d ','
}

# /usr/bin/time output (GNU -v or BSD -l) → TIME_CPU (user+sys seconds), TIME_RSS_KB.
TIME_CPU='' TIME_RSS_KB=''
parse_time() {
    TIME_CPU='' TIME_RSS_KB=''
    [ -s "$1" ] || return 0
    if grep -q 'Maximum resident set size' "$1"; then
        TIME_CPU="$(awk -F': ' '/User time|System time/ {s += $2} END {if (NR) printf "%.2f", s}' "$1")"
        TIME_RSS_KB="$(awk -F': ' '/Maximum resident set size/ {print $2; exit}' "$1")"
    else
        TIME_CPU="$(awk '/ real .* user .* sys/ {printf "%.2f", $3 + $5; exit}' "$1")"
        TIME_RSS_KB="$(awk '/maximum resident set size/ {printf "%d", $1 / 1024; exit}' "$1")"
    fi
}

# classify_run <log> <report>: sets STATUS (ok|crash|timeout) for the run just made.
classify_run() {
    if [ "$RUN_RC" -eq 124 ]; then
        STATUS=timeout
    elif grep -q 'Checks took' "$1" && [ -s "$2" ]; then
        STATUS=ok
    else
        STATUS=crash
    fi
}

measure_project() {
    NAME=$1
    PROJ="$WORK/$NAME"
    STATUS=ok LOG_TAIL=''
    IR='' MB='' WALL1='' CPU1='' RSS1='' WALL4='' RSS4='' MB4='' ISSUE_COUNT='' ISSUES_FILE=''
    echo "== $NAME ($MODE)" >&2

    if ! prepare_project; then
        STATUS=setup-failed
        set_log_tail "$WORK/$NAME.setup.log"
        write_result
        return 0
    fi

    local res="$WORK/$NAME.results"
    rm -rf "$res"
    mkdir -p "$res"
    run_psalm 1 "$res/report.json" "$res/t1.log" "$res/t1.time" "$res/cachegrind.out"
    classify_run "$res/t1.log" "$res/report.json"
    WALL1=$RUN_WALL
    if [ "$STATUS" != ok ]; then
        set_log_tail "$res/t1.log"
        write_result
        return 0
    fi

    MB="$(parse_psalm_mb "$res/t1.log")"
    if [ "$MODE" = cachegrind ]; then
        IR="$(awk '/^summary:/ {print $2; exit}' "$res/cachegrind.out" 2>/dev/null || true)"
        if [ -z "$IR" ]; then
            STATUS=crash
            set_log_tail "$res/t1.log"
        fi
    else
        parse_time "$res/t1.time"
        CPU1=$TIME_CPU RSS1=$TIME_RSS_KB
    fi

    if [ "$STATUS" = ok ]; then
        if ISSUE_COUNT="$("$PHP_BIN" "$SCRIPT_DIR/normalize-issues.php" "$res/report.json" "$PROJ" "$OUT/$NAME.issues.json" 2> "$res/normalize.log")"; then
            ISSUES_FILE="$NAME.issues.json"
        else
            STATUS=crash ISSUE_COUNT=''
            set_log_tail "$res/normalize.log"
        fi
    fi

    if [ "$STATUS" = ok ] && [ "$MODE" = native ]; then
        run_psalm 4 "$res/report-t4.json" "$res/t4.log" "$res/t4.time" ''
        classify_run "$res/t4.log" "$res/report-t4.json"
        if [ "$STATUS" = ok ]; then
            WALL4=$RUN_WALL
            MB4="$(parse_psalm_mb "$res/t4.log")"
            parse_time "$res/t4.time"
            RSS4=$TIME_RSS_KB
        else
            # The threads=4 run is informational: keep the threads=1 result.
            echo "run-shard: $NAME threads=4 run failed ($STATUS), metrics left empty" >&2
            STATUS=ok
        fi
    fi

    write_result
    rm -rf "$res"
}

# ----------------------------------------------------------------------- main
while IFS= read -r project <&3; do
    measure_project "$project"
done 3< <(jq -r --arg s "$SHARD" '.projects | to_entries[] | select(.value.shards | index($s)) | .key' "$CORPUS")

echo "run-shard: shard $SHARD done" >&2
