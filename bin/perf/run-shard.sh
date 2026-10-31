#!/usr/bin/env bash
# Measure every project of one corpus job with a base and a head Psalm checkout. See bin/perf/README.md.
#
# usage: run-shard.sh --job <id> --base <psalm base dir> --head <psalm head dir> --base-sha <sha> --head-sha <sha>
#                     --out <dir> [--work <dir>]
#
# Per project: clone and install once, run base then head, re-run in reverse order when the wall times differ
# by more than 10 %, then write <out>/<rev>/<project>.json and <project>.issues.json.
# Exit 0 whatever happens to a project (the status is in its result); 2 for usage errors and missing tools.
#
# PERF_CORPUS=<path> uses another corpus.json (its corpus/<config>/psalm.xml files live next to it).
# Written for bash 3.2 (macOS): no mapfile, no associative arrays, no empty-array expansion.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
CORPUS="${PERF_CORPUS:-$SCRIPT_DIR/corpus.json}"
INSTALL_TIMEOUT=180
RERUN_RATIO=0.10

die() { echo "run-shard: $*" >&2; exit 2; }

usage() {
    sed -n '2,/^$/p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//' >&2
    exit 2
}

JOB='' BASE_DIR='' HEAD_DIR='' BASE_SHA='' HEAD_SHA='' OUT='' WORK="${RUNNER_TEMP:-/tmp}/perf-work"
while [ $# -gt 0 ]; do
    [ $# -ge 2 ] || usage
    case "$1" in
        --job) JOB=$2 ;;
        --base) BASE_DIR=$2 ;;
        --head) HEAD_DIR=$2 ;;
        --base-sha) BASE_SHA=$2 ;;
        --head-sha) HEAD_SHA=$2 ;;
        --out) OUT=$2 ;;
        --work) WORK=$2 ;;
        *) usage ;;
    esac
    shift 2
done
[ -n "$JOB" ] && [ -n "$BASE_DIR" ] && [ -n "$HEAD_DIR" ] && [ -n "$BASE_SHA" ] && [ -n "$HEAD_SHA" ] \
    && [ -n "$OUT" ] || usage

for bin in jq git php composer; do
    command -v "$bin" >/dev/null 2>&1 || die "missing required tool: $bin"
done
[ -x /usr/bin/time ] || die "/usr/bin/time is required"
if command -v timeout >/dev/null 2>&1; then TIMEOUT_BIN=timeout
elif command -v gtimeout >/dev/null 2>&1; then TIMEOUT_BIN=gtimeout
else die "GNU timeout is required (gtimeout on macOS: brew install coreutils)"
fi
if [ "$(uname -s)" = Darwin ]; then TIME_FLAG=-l; else TIME_FLAG=-v; fi

[ -f "$CORPUS" ] || die "missing $CORPUS"
CORPUS="$(cd "$(dirname "$CORPUS")" && pwd -P)/$(basename "$CORPUS")"
CORPUS_DIR="$(dirname "$CORPUS")/corpus"
for dir in "$BASE_DIR" "$HEAD_DIR"; do
    [ -f "$dir/psalm" ] && [ -f "$dir/vendor/autoload.php" ] || die "not a Psalm checkout with dependencies: $dir"
done
BASE_DIR="$(cd "$BASE_DIR" && pwd -P)"
HEAD_DIR="$(cd "$HEAD_DIR" && pwd -P)"
mkdir -p "$OUT/base" "$OUT/head" "$WORK"
OUT="$(cd "$OUT" && pwd -P)"
WORK="$(cd "$WORK" && pwd -P)"

PROJECTS="$(jq -r --arg j "$JOB" '.jobs[$j] // empty | .[]' "$CORPUS")"
[ -n "$PROJECTS" ] || die "unknown job '$JOB' in $CORPUS"
MEMORY_LIMIT="$(jq -r '.memory_limit' "$CORPUS")"

# Psalm re-execs itself (xdebug-handler) unless this is set; the php.ini settings are then passed explicitly.
# Psalm ignores `php -d memory_limit`: only --memory-limit caps it.
export PSALM_ALLOW_XDEBUG=1
export COMPOSER_NO_INTERACTION=1
PHP_FLAGS=(-d memory_limit=-1)
while IFS= read -r setting; do
    PHP_FLAGS+=(-d "$setting")
done < <(jq -r '.php_ini | to_entries[] | "\(.key)=\(.value)"' "$CORPUS")

cpu_model() {
    if [ "$(uname -s)" = Darwin ]; then sysctl -n machdep.cpu.brand_string
    else sed -n 's/^model name[[:space:]]*: //p' /proc/cpuinfo | sed -n '1p'
    fi
}
CPU_MODEL="$(cpu_model 2>/dev/null || true)"
CPU_MODEL="${CPU_MODEL:-unknown}"

now() { php -r 'echo microtime(true);'; }
elapsed() { awk -v a="$1" -v b="$2" 'BEGIN { printf "%.2f", b - a }'; }

# JSON number or null from a possibly empty string.
num() { if [ -n "${1:-}" ]; then printf '%s' "$1"; else printf 'null'; fi; }

# Per-project state.
NAME='' PROJ='' RUNDIR='' TIMEOUT='' REASON_FILE="$WORK/reason"

fail() { printf '%s' "$1" > "$REASON_FILE"; exit 1; }

field() { jq -r --arg p "$NAME" ".projects[\$p].$1 // empty" "$CORPUS"; }

# --------------------------------------------------------------------- install
# fetch_and_install <repo> <ref> <composer true|false> <setup snippet> <tag>
fetch_and_install() {
    local repo=$1 ref=$2 composer=$3 setup=$4 tag=$5 attempt=1
    git -C "$PROJ" init -q || return 1
    git -C "$PROJ" remote add origin "$repo" || return 1
    until git -C "$PROJ" fetch -q --depth 1 origin "$ref"; do
        [ "$attempt" -lt 3 ] || { echo "git fetch of $ref from $repo failed" >&2; return 1; }
        attempt=$((attempt + 1))
        sleep 5
    done
    git -C "$PROJ" -c advice.detachedHead=false checkout -q FETCH_HEAD || return 1
    if [ -n "$setup" ]; then (cd "$PROJ" && bash -c "$setup") || return 1; fi
    if [ "$composer" = true ] && [ -f "$PROJ/composer.json" ]; then
        # Some projects require self.version; a checkout has no tag to derive it from.
        (cd "$PROJ" && COMPOSER_ROOT_VERSION="${tag#v}" composer update --no-dev --no-scripts --no-plugins \
            --ignore-platform-reqs --no-interaction --quiet) || { echo "composer update failed" >&2; return 1; }
    fi
}
export -f fetch_and_install

install_project() {
    local repo ref tag composer setup log="$RUNDIR/install.log" rc=0
    repo="$(field repo)" ref="$(field ref)" tag="$(field tag)" setup="$(field setup)"
    composer="$(jq -r --arg p "$NAME" 'if .projects[$p].composer == false then "false" else "true" end' "$CORPUS")"
    [ -n "$repo" ] && [ -n "$ref" ] && [ -n "$TIMEOUT" ] || fail "corpus entry of $NAME lacks repo, ref or timeout"

    rm -rf "$PROJ"
    mkdir -p "$PROJ"
    export PROJ
    "$TIMEOUT_BIN" -k 20 "$INSTALL_TIMEOUT" bash -c 'fetch_and_install "$@"' _ "$repo" "$ref" "$composer" "$setup" "$tag" \
        > "$log" 2>&1 < /dev/null || rc=$?
    if [ "$rc" -eq 124 ]; then
        fail "install exceeded ${INSTALL_TIMEOUT}s"
    elif [ "$rc" -ne 0 ]; then
        fail "install failed: $(grep -v '^[[:space:]]*$' "$log" | tail -n 1 | cut -c1-300)"
    fi
}

# Our config, with loadLibraryPlugins="false" injected iff both Psalm checkouts know the attribute (a config with
# an attribute one side rejects, or one that loads plugins on one side only, would not be comparable).
write_config() {
    local config src inject=0
    config="$(field config)"
    src="$CORPUS_DIR/${config:-$NAME}/psalm.xml"
    [ -f "$src" ] || fail "missing corpus config $src"
    if grep -q loadLibraryPlugins "$BASE_DIR/config.xsd" 2>/dev/null \
        && grep -q loadLibraryPlugins "$HEAD_DIR/config.xsd" 2>/dev/null; then
        inject=1
    fi
    php -r '
        [, $src, $dst, $inject] = $argv;
        $xml = file_get_contents($src);
        if ($inject === "1" && !str_contains($xml, "loadLibraryPlugins=")) {
            $xml = preg_replace("/<psalm(?=[\s>\/])/", "<psalm loadLibraryPlugins=\"false\"", $xml, 1);
        }
        file_put_contents($dst, $xml);
    ' "$src" "$PROJ/psalm-perf.xml" "$inject" || fail "cannot write psalm-perf.xml"
}

# ------------------------------------------------------------------- one run
# Psalm's "Checks took X seconds and used Y MB of memory" line: Y.
parse_psalm_mb() {
    { grep -E 'Checks took .* seconds and used .*MB of memory' "$1" || true; } | tail -n 1 \
        | sed -E 's/.*used ([0-9.,]+)MB.*/\1/' | tr -d ','
}

# /usr/bin/time output (GNU -v or BSD -l): TIME_CPU (user + sys seconds), TIME_RSS_KB, TIME_SIGNAL.
TIME_CPU='' TIME_RSS_KB='' TIME_SIGNAL=''
parse_time() {
    TIME_CPU='' TIME_RSS_KB='' TIME_SIGNAL=''
    [ -s "$1" ] || return 0
    if grep -q 'Maximum resident set size' "$1"; then
        TIME_CPU="$(awk -F': ' '/User time|System time/ {s += $2} END {if (NR) printf "%.2f", s}' "$1")"
        TIME_RSS_KB="$(awk -F': ' '/Maximum resident set size/ {print $2; exit}' "$1")"
        TIME_SIGNAL="$(sed -n 's/.*Command terminated by signal \([0-9][0-9]*\).*/\1/p' "$1" | sed -n '1p')"
    else
        TIME_CPU="$(awk '/ real .* user .* sys/ {printf "%.2f", $3 + $5; exit}' "$1")"
        TIME_RSS_KB="$(awk '/maximum resident set size/ {printf "%d", $1 / 1024; exit}' "$1")"
    fi
}

# Fatal-error lines first, then the end of the log; ANSI colours stripped; at most 4000 bytes.
log_tail() {
    {
        { grep -E 'Fatal error|Allowed memory size|Hard memory limit' "$1" || true; } | sed -n '1,5p'
        tail -n 30 "$1"
    } | sed $'s/\033\\[[0-9;]*m//g' | cut -c1-300 | head -c 4000 || true
}

# classify <exit code> <wall> <log> <report>: RUN_STATUS (ok|crash|timeout|error), RUN_REASON, RUN_MEMORY_CRASH.
RUN_STATUS='' RUN_REASON='' RUN_MEMORY_CRASH=false
classify() {
    local rc=$1 wall=$2 log=$3 report=$4 code=$1
    RUN_REASON='' RUN_MEMORY_CRASH=false
    if [ -n "$TIME_SIGNAL" ]; then code=$((128 + TIME_SIGNAL)); fi
    if [ "$rc" -ge 125 ] && [ "$rc" -le 127 ]; then
        RUN_STATUS=error
        RUN_REASON="launcher failed with exit code $rc: $(grep -v '^[[:space:]]*$' "$log" | tail -n 1 | cut -c1-200)"
    elif [ "$rc" -eq 124 ] || { [ "$rc" -eq 137 ] && awk -v w="$wall" -v l="$TIMEOUT" 'BEGIN { exit !(w >= l) }'; }; then
        RUN_STATUS=timeout
        RUN_REASON="exceeded ${TIMEOUT}s"
    elif { [ "$code" -eq 0 ] || [ "$code" -eq 2 ]; } && grep -q 'Checks took' "$log" && [ -s "$report" ]; then
        RUN_STATUS=ok
    else
        RUN_STATUS=crash
        if grep -qE 'Allowed memory size|Hard memory limit' "$log"; then
            RUN_MEMORY_CRASH=true
            RUN_REASON="PHP memory limit exceeded"
        else
            RUN_REASON="Psalm exited with code $code"
        fi
    fi
}

# run_once <base|head> <n>: one analysis, recorded as $RUNDIR/<rev>-<n>.json (+ .log, .report.json).
run_once() {
    local rev=$1 n=$2 psalm_dir prefix rc=0 start end wall mb='' tail_text=''
    psalm_dir=$BASE_DIR
    if [ "$rev" = head ]; then psalm_dir=$HEAD_DIR; fi
    prefix="$RUNDIR/$rev-$n"

    start="$(now)"
    (cd "$PROJ" && /usr/bin/time "$TIME_FLAG" -o "$prefix.time" "$TIMEOUT_BIN" -k 20 "$TIMEOUT" \
        php "${PHP_FLAGS[@]}" "$psalm_dir/psalm" --root="$PROJ" -c "$PROJ/psalm-perf.xml" --no-cache --no-progress \
        --ignore-baseline --threads=1 --output-format=console --report="$prefix.report.json" \
        --memory-limit="$MEMORY_LIMIT") > "$prefix.log" 2>&1 < /dev/null || rc=$?
    end="$(now)"
    wall="$(elapsed "$start" "$end")"

    parse_time "$prefix.time"
    classify "$rc" "$wall" "$prefix.log" "$prefix.report.json"
    if [ "$RUN_STATUS" = ok ]; then mb="$(parse_psalm_mb "$prefix.log")"; else tail_text="$(log_tail "$prefix.log")"; fi

    jq -n --arg status "$RUN_STATUS" --arg reason "$RUN_REASON" --argjson memory_crash "$RUN_MEMORY_CRASH" \
        --argjson exit_code "$rc" --argjson wall "$wall" --argjson cpu "$(num "$TIME_CPU")" \
        --argjson rss "$(num "$TIME_RSS_KB")" --argjson mb "$(num "$mb")" --arg tail "$tail_text" '{
            status: $status, reason: (if $reason == "" then null else $reason end), memory_crash: $memory_crash,
            exit_code: $exit_code, wall: $wall, cpu: $cpu, max_rss_kb: $rss, psalm_mb: $mb,
            log_tail: (if $tail == "" then null else $tail end)
        }' > "$prefix.json"
}

# run_field <base|head> <n> <jq path>
run_field() { jq -r ".$3" "$RUNDIR/$1-$2.json"; }

# Both first runs ok and their wall times differ by more than RERUN_RATIO of the base wall.
needs_rerun() {
    [ "$(run_field base 1 status)" = ok ] && [ "$(run_field head 1 status)" = ok ] || return 1
    awk -v b="$(run_field base 1 wall)" -v h="$(run_field head 1 wall)" -v r="$RERUN_RATIO" \
        'BEGIN { d = h - b; if (d < 0) d = -d; exit !(d > r * b) }'
}

# ---------------------------------------------------------------------- result
write_error() {
    local rev=$1 sha=$2 reason=$3
    jq -n --arg project "$NAME" --arg rev "$rev" --arg sha "$sha" --arg reason "$reason" --arg cpu "$CPU_MODEL" '{
        schema: 1, project: $project, rev: $rev, sha: $sha, status: "error", reason: $reason, memory_crash: false,
        psalm_mb: null, wall: null, cpu: null, max_rss_kb: null, exit_code: null, runs: [], cpu_model: $cpu,
        issue_count: null, log_tail: null
    }' > "$OUT/$rev/$NAME.json"
}

# Result of one side from its run records: the first failed run decides (else the first run); wall and cpu are
# the minimum over the runs when all are ok.
aggregate() {
    jq -s --arg project "$NAME" --arg rev "$1" --arg sha "$2" --arg cpu "$CPU_MODEL" '
        (map(select(.status != "ok"))[0] // .[0]) as $d
        | ($d.status == "ok") as $ok
        | {
            schema: 1, project: $project, rev: $rev, sha: $sha, status: $d.status, reason: $d.reason,
            memory_crash: $d.memory_crash, psalm_mb: $d.psalm_mb,
            wall: (if $ok then map(.wall) | min else $d.wall end),
            cpu: (if $ok then map(.cpu) | min else $d.cpu end),
            max_rss_kb: $d.max_rss_kb, exit_code: $d.exit_code,
            runs: map({wall, cpu}), cpu_model: $cpu, issue_count: null, log_tail: $d.log_tail
        }' "$RUNDIR/$1"-[0-9].json
}

# write_result <base|head> <sha>: <out>/<rev>/<project>.json and, for an ok run, .issues.json.
write_result() {
    local rev=$1 sha=$2 result report count
    result="$(aggregate "$rev" "$sha")"
    report="$RUNDIR/$rev-1.report.json"
    if [ "$(jq -r '.status' <<< "$result")" = ok ]; then
        if count="$(jq 'length' "$report")" \
            && php "$SCRIPT_DIR/perf.php" normalize "$report" "$PROJ" "$OUT/$rev/$NAME.issues.json" > /dev/null; then
            result="$(jq --argjson c "$count" '.issue_count = $c' <<< "$result")"
        else
            rm -f "$OUT/$rev/$NAME.issues.json"
            result="$(jq '.status = "error" | .reason = "issue normalisation failed" | .psalm_mb = null' <<< "$result")"
        fi
    fi
    printf '%s\n' "$result" > "$OUT/$rev/$NAME.json"
}

measure_project() {
    install_project
    write_config
    run_once base 1
    run_once head 1
    if needs_rerun; then
        echo "run-shard: $NAME wall times differ by more than 10 %, re-running in reverse order" >&2
        run_once head 2
        run_once base 2
    fi
    write_result base "$BASE_SHA"
    write_result head "$HEAD_SHA"
}

for NAME in $PROJECTS; do
    PROJ="$WORK/$NAME"
    RUNDIR="$WORK/runs/$NAME"
    TIMEOUT="$(field timeout)"
    rm -rf "$RUNDIR" "$REASON_FILE" "$OUT/base/$NAME".* "$OUT/head/$NAME".*
    mkdir -p "$RUNDIR"
    echo "run-shard: $NAME" >&2

    # A subshell called as a plain command keeps `set -e`: any harness failure aborts only this project.
    set +e
    (set -e; measure_project)
    status=$?
    set -e
    if [ "$status" -ne 0 ]; then
        reason="harness error (exit $status)"
        if [ -s "$REASON_FILE" ]; then reason="$(cat "$REASON_FILE")"; fi
        echo "run-shard: $NAME failed: $reason" >&2
        [ -f "$OUT/base/$NAME.json" ] || write_error base "$BASE_SHA" "$reason"
        [ -f "$OUT/head/$NAME.json" ] || write_error head "$HEAD_SHA" "$reason"
    fi
done
echo "run-shard: job $JOB done" >&2
