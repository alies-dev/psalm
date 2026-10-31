#!/usr/bin/env bash
# Measure every project of one shard against one Psalm checkout.
# See bin/perf/README.md. Exit 0 even when a project crashes (the status is
# recorded in <out>/<project>.json); non-zero for harness/usage errors, and after the last project when the
# result of some project could not be written at all (so the workflow's stub step runs).
#
# usage: run-shard.sh --shard <id> --psalm <psalm checkout> --sha <psalm sha> --out <dir>
#                     [--projects <csv>] [--work <dir>] [--threads4]
#
# Local testing overrides (never set in CI):
#   PERF_FORCE_MODE=native|cachegrind  ignore the shard's mode (cachegrind is unavailable on macOS)
#   PERF_CORPUS=<path>                 use another corpus.json; corpus/<config>/ directories live next to it
#
# Written for bash 3.2 (macOS): no mapfile, no empty-array expansion.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
CORPUS="${PERF_CORPUS:-$SCRIPT_DIR/corpus.json}"

die() { echo "run-shard: $*" >&2; exit 2; }

usage() {
    echo "usage: run-shard.sh --shard <id> --psalm <psalm checkout> --sha <psalm sha> --out <dir> [--projects <csv>] [--work <dir>] [--threads4]" >&2
    exit 2
}

SHARD='' PSALM_DIR='' PSALM_SHA='' OUT='' ONLY='' THREADS4=0 WORK="${RUNNER_TEMP:-/tmp}/perf-work"
while [ $# -gt 0 ]; do
    case "$1" in
        --shard)    [ $# -ge 2 ] || usage; SHARD=$2; shift 2 ;;
        --psalm)    [ $# -ge 2 ] || usage; PSALM_DIR=$2; shift 2 ;;
        --sha)      [ $# -ge 2 ] || usage; PSALM_SHA=$2; shift 2 ;;
        --out)      [ $# -ge 2 ] || usage; OUT=$2; shift 2 ;;
        --projects) [ $# -ge 2 ] || usage; ONLY=$2; shift 2 ;;
        --work)     [ $# -ge 2 ] || usage; WORK=$2; shift 2 ;;
        --threads4) THREADS4=1; shift ;;
        *) usage ;;
    esac
done
[ -n "$SHARD" ] && [ -n "$PSALM_DIR" ] && [ -n "$PSALM_SHA" ] && [ -n "$OUT" ] || usage

for bin in jq git php composer; do
    command -v "$bin" >/dev/null 2>&1 || die "missing required tool: $bin"
done
[ -f "$CORPUS" ] || die "missing $CORPUS"
[ -f "$PSALM_DIR/psalm" ] || die "not a Psalm checkout: $PSALM_DIR"
[ -f "$PSALM_DIR/vendor/autoload.php" ] || die "Psalm dependencies are not installed in $PSALM_DIR"
[ -x /usr/bin/time ] || die "/usr/bin/time is required"

CORPUS="$(cd "$(dirname "$CORPUS")" && pwd -P)/$(basename "$CORPUS")"
CORPUS_DIR="$(dirname "$CORPUS")/corpus"
PSALM_DIR="$(cd "$PSALM_DIR" && pwd -P)"
mkdir -p "$OUT" "$WORK"
OUT="$(cd "$OUT" && pwd -P)"
WORK="$(cd "$WORK" && pwd -P)"

PHP_BIN="$(command -v php)"
if [ -n "${PERF_FORCE_MODE:-}" ]; then
    case "$PERF_FORCE_MODE" in
        native|cachegrind) ;;
        *) die "PERF_FORCE_MODE must be native or cachegrind" ;;
    esac
fi

# Every sizing rule (mode, memory limit, timeouts, t4 eligibility, CPU calibration, project selection) is applied
# by lib/corpus.php; the shell only reads the answers.
INFO="$("$PHP_BIN" "$SCRIPT_DIR/project-info.php" --corpus "$CORPUS" --shard "$SHARD" --projects "$ONLY")" \
    || die "cannot read shard $SHARD from $CORPUS"
MODE="$(printf '%s' "$INFO" | jq -r '.mode')"
# Psalm ignores `php -d memory_limit`: only --memory-limit caps it. A cap below the runner's RAM turns a
# blow-up into a crash with the fatal error in log_tail instead of an OOM-killed job.
MEMORY_LIMIT="$(printf '%s' "$INFO" | jq -r '.memory_limit')"
CPU_MODEL="$(printf '%s' "$INFO" | jq -r '.cpu')"
CPU_MEASURED="$(printf '%s' "$INFO" | jq -r '.cpu_measured')"
if [ "$CPU_MEASURED" != true ]; then
    echo "::warning::runner CPU '$CPU_MODEL' is not in corpus.json measured_cpus: Ir is unaffected but wall times and timeouts are not calibrated for it"
fi

if [ "$MODE" = cachegrind ]; then
    command -v valgrind >/dev/null 2>&1 || die "valgrind is required for cachegrind shards"
fi
if [ "$(uname -s)" = Darwin ]; then TIME_FLAG=-l; else TIME_FLAG=-v; fi

# timeout (GNU coreutils) is gtimeout on macOS; `env` is a harmless stand-in.
if command -v timeout >/dev/null 2>&1; then TIMEOUT_BIN=timeout
elif command -v gtimeout >/dev/null 2>&1; then TIMEOUT_BIN=gtimeout
else TIMEOUT_BIN=''; echo "run-shard: no timeout binary found, timeouts are not enforced" >&2
fi

# Psalm re-execs itself (xdebug-handler) unless this is set; then the opcache settings must be passed explicitly.
export PSALM_ALLOW_XDEBUG=1
export COMPOSER_NO_INTERACTION=1
PHP_FLAGS=()
while IFS= read -r line; do
    [ -n "$line" ] || continue
    # shellcheck disable=SC2206 # one `-d key=value` pair per line, no globs
    PHP_FLAGS+=($line)
done < "$SCRIPT_DIR/php-flags.txt"

sha256() {
    if command -v sha256sum >/dev/null 2>&1; then sha256sum | cut -d' ' -f1; else shasum -a 256 | cut -d' ' -f1; fi
}

now() { "$PHP_BIN" -r 'echo microtime(true);'; }

# seconds between two microtime values, 2 decimals
elapsed() { awk -v a="$1" -v b="$2" 'BEGIN { printf "%.2f", b - a }'; }

# JSON number or null from a possibly empty string.
num() { if [ -n "${1:-}" ]; then printf '%s' "$1"; else printf 'null'; fi; }

# JSON string or null from a possibly empty string.
str() { if [ -n "${1:-}" ]; then jq -Rn --arg v "$1" '$v'; else printf 'null'; fi; }

# <filter> applied to the current project's entry of the shard info, raw
pf() { printf '%s' "$PJ" | jq -r "$1"; }

vm_ram_kb() {
    if [ -r /proc/meminfo ]; then awk '/^MemTotal:/ {print $2; exit}' /proc/meminfo
    elif command -v sysctl >/dev/null 2>&1; then echo $(($(sysctl -n hw.memsize 2>/dev/null || echo 0) / 1024))
    fi
}

# Kernel OOM kills so far: cgroup v2 counter of our own cgroup, else `dmesg` (Linux). Empty when unknown.
oom_count() {
    local cg file count
    cg="$(sed -n 's/^0:://p' /proc/self/cgroup 2>/dev/null | head -n 1)"
    file="/sys/fs/cgroup${cg}/memory.events"
    if [ -n "$cg" ] && [ -r "$file" ]; then
        count="$(awk '$1 == "oom_kill" {print $2}' "$file")"
        if [ -n "$count" ]; then echo "$count"; return 0; fi
    fi
    if [ "$(uname -s)" = Linux ] && command -v sudo >/dev/null 2>&1; then
        count="$(sudo -n dmesg 2>/dev/null)" || return 0
        printf '%s\n' "$count" | grep -c 'Killed process' || true
    fi
}

# --------------------------------------------------------------- environment
VALGRIND_VERSION=''
if [ "$MODE" = cachegrind ]; then
    VALGRIND_VERSION="$(dpkg-query -W -f='${Version}' valgrind 2>/dev/null || true)"
    [ -n "$VALGRIND_VERSION" ] || VALGRIND_VERSION="$(valgrind --version | sed 's/^valgrind-//')"
fi

PROJECT_LIST="$(printf '%s' "$INFO" | jq -r '.projects[].name')"
if [ -z "$PROJECT_LIST" ]; then
    echo "run-shard: no projects selected in shard $SHARD" >&2
    exit 0
fi

FINGERPRINTS="$("$PHP_BIN" "$SCRIPT_DIR/fingerprint.php" --corpus "$CORPUS" \
    --projects "$(printf '%s' "$PROJECT_LIST" | tr '\n' ',')" --valgrind-version "${VALGRIND_VERSION:-none}")" \
    || die "fingerprint.php failed"

PSALM_PACKAGES="$(jq -c '[(.packages // .)[] | {key: .name, value: .version}] | from_entries' "$PSALM_DIR/vendor/composer/installed.json")"
LOCK_SHA=''
if [ -f "$PSALM_DIR/composer.lock" ]; then LOCK_SHA="$(sha256 < "$PSALM_DIR/composer.lock")"; fi
# write-stubs.php copies it into stubs so they carry the lock identity too.
printf '%s' "$LOCK_SHA" > "$OUT/.lock_sha256"
VALGRIND_REPORTED=''
if command -v valgrind >/dev/null 2>&1; then VALGRIND_REPORTED="$(valgrind --version | sed 's/^valgrind-//')"; fi
VM_RAM_KB="$(vm_ram_kb)"

# ------------------------------------------------------------ project helpers
# Per-project state, reset by measure_project.
NAME='' PROJ='' PJ='' STATUS='' REASON='' LOG_TAIL='' T4_JSON=''
IR='' MB='' WALL1='' CPU1='' RSS1='' WALL4='' RSS4='' MB4='' ISSUE_COUNT='' ISSUES_FILE=''
EXIT_CODE='' SIGNAL='' OOM_KILLED='' ELAPSED_INSTALL='' ELAPSED_ANALYSIS=''
RESULTS_UNWRITTEN=0

# Failure excerpt of a log (fatal-error lines first, then the tail; <= 50 lines): lib/result.php, the same
# function write-stubs.php uses.
log_tail_of() {
    "$PHP_BIN" -r 'require $argv[1]; echo perfLogTail($argv[2]) ?? "";' "$SCRIPT_DIR/lib/result.php" "$1"
}

# Psalm streams its output straight into $OUT/<project>.log, so write-stubs.php finds the live tail of a run
# the job never finished. Only afterwards is the log cut down to its last MiB.
trim_log() {
    local log="$OUT/$NAME.log"
    [ -f "$log" ] || return 0
    tail -c 1048576 "$log" > "$log.tmp" && mv "$log.tmp" "$log"
}

# Written before anything runs: write-stubs.php copies it into the stub of an interrupted project, so the stub
# keeps the environment identity (fingerprint, CPU) the comparator needs.
write_env_file() {
    printf '%s' "$FINGERPRINTS" | jq --arg n "$NAME" --arg cpu "$CPU_MODEL" \
        --argjson measured "$CPU_MEASURED" --argjson ram "$(num "$VM_RAM_KB")" \
        '{fingerprint: .env[$n], soft: .soft[$n], cpu: $cpu, cpu_measured: $measured, vm_ram_kb: $ram}' \
        > "$OUT/$NAME.env.json.tmp" && mv "$OUT/$NAME.env.json.tmp" "$OUT/$NAME.env.json"
}

write_result() {
    jq -n \
        --arg project "$NAME" --arg shard "$SHARD" --arg mode "$MODE" \
        --arg psalm_sha "$PSALM_SHA" --arg status "$STATUS" --argjson reason "$(str "$REASON")" \
        --argjson ir "$(num "$IR")" --argjson mb "$(num "$MB")" \
        --argjson wall1 "$(num "$WALL1")" --argjson cpu1 "$(num "$CPU1")" --argjson rss1 "$(num "$RSS1")" \
        --argjson wall4 "$(num "$WALL4")" --argjson rss4 "$(num "$RSS4")" --argjson mb4 "$(num "$MB4")" \
        --argjson issue_count "$(num "$ISSUE_COUNT")" --argjson issues_file "$(str "$ISSUES_FILE")" \
        --argjson exit_code "$(num "$EXIT_CODE")" --argjson signal "$(str "$SIGNAL")" \
        --argjson max_rss "$(num "$RSS1")" --argjson oom "${OOM_KILLED:-null}" \
        --argjson lock_sha "$(str "$LOCK_SHA")" --argjson t4 "${T4_JSON:-null}" \
        --arg cpu "$CPU_MODEL" --argjson cpu_measured "$CPU_MEASURED" \
        --argjson fingerprints "$FINGERPRINTS" --argjson packages "$PSALM_PACKAGES" \
        --argjson php "$(printf '%s' "$("$PHP_BIN" -r 'echo PHP_VERSION;')" | jq -Rs .)" \
        --argjson valgrind "$(str "$VALGRIND_REPORTED")" --argjson vm_ram "$(num "$VM_RAM_KB")" \
        --argjson elapsed_analysis "$(num "$ELAPSED_ANALYSIS")" --argjson elapsed_install "$(num "$ELAPSED_INSTALL")" \
        --argjson log_tail "$(str "$LOG_TAIL")" \
        '{
            schema: 2, project: $project, shard: $shard, mode: $mode,
            psalm_sha: $psalm_sha, status: $status, reason: $reason,
            metrics: {ir: $ir, psalm_mb: $mb, wall_t1: $wall1, cpu_t1: $cpu1, rss_t1_kb: $rss1,
                      wall_t4: $wall4, rss_t4_kb: $rss4, psalm_mb_t4: $mb4},
            issue_count: $issue_count, issues_file: $issues_file,
            exit_code: $exit_code, signal: $signal, max_rss_kb: $max_rss, oom_killed: $oom,
            lock_sha256: $lock_sha, t4: $t4,
            env: {fingerprint: $fingerprints.env[$project], soft: $fingerprints.soft[$project],
                  cpu: $cpu, cpu_measured: $cpu_measured,
                  php: $php, valgrind: $valgrind, psalm_packages: $packages, vm_ram_kb: $vm_ram},
            elapsed_analysis: $elapsed_analysis, elapsed_install: $elapsed_install,
            log_tail: $log_tail
        }' > "$OUT/$NAME.json.tmp" && mv "$OUT/$NAME.json.tmp" "$OUT/$NAME.json"
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

# ensure_installed <fetch_and_install args...> <stamp>
# A checkout made earlier with the same inputs (the base run of the same job) is reused.
ensure_installed() {
    local stamp=${7}
    if [ -f "$PROJ.ready" ] && [ "$(cat "$PROJ.ready")" = "$stamp" ]; then
        echo "reusing $PROJ"
        return 0
    fi
    rm -rf "$PROJ" "$PROJ.ready"
    mkdir -p "$PROJ"
    fetch_and_install "$1" "$2" "$3" "$4" "$5" "$6" || return 1
    printf '%s' "$stamp" > "$PROJ.ready"
}
export -f fetch_and_install ensure_installed

# install_project: sets REASON on failure. Returns 1 on failure. The install output goes to $OUT/<project>.log
# (the analysis log replaces it), the kill limit is the project's INSTALL_TIMEOUT.
install_project() {
    local repo ref tag composer setup config lock_dir lock stamp rc=0 guard
    repo="$(pf '.repo')" ref="$(pf '.ref')" tag="$(pf '.tag')" composer="$(pf '.composer')"
    setup="$(pf '.setup')" config="$(pf '.config')" lock_dir="$(pf '.lock')"
    if [ ! -f "$CORPUS_DIR/$config/psalm.xml" ]; then
        REASON="missing corpus config $CORPUS_DIR/$config/psalm.xml"
        return 1
    fi
    lock=''
    if [ -f "$CORPUS_DIR/$lock_dir/composer.lock" ]; then lock="$CORPUS_DIR/$lock_dir/composer.lock"; fi

    stamp="$repo $ref $tag $composer $(printf '%s' "$setup" | sha256) $([ -n "$lock" ] && sha256 < "$lock" || echo nolock)"
    guard=(env)
    if [ -n "$TIMEOUT_BIN" ]; then guard=("$TIMEOUT_BIN" -k 20 "$INSTALL_TIMEOUT"); fi
    export PROJ
    "${guard[@]}" bash -c 'ensure_installed "$@"' _ "$repo" "$ref" "$composer" "$setup" "$lock" "$tag" "$stamp" \
        > "$OUT/$NAME.log" 2>&1 < /dev/null || rc=$?
    if [ "$rc" -eq 124 ]; then
        REASON="install exceeded ${INSTALL_TIMEOUT}s"
        return 1
    elif [ "$rc" -ne 0 ]; then
        REASON="install failed: $(grep -v '^[[:space:]]*$' "$OUT/$NAME.log" | tail -n 1 | cut -c1-300)"
        return 1
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
    ' "$CORPUS_DIR/$config/psalm.xml" "$PROJ/psalm-perf.xml" "$inject" || { REASON="cannot write psalm-perf.xml"; return 1; }
}

# run_psalm <threads> <report> <log> <time file> <cachegrind out or ''> <memory limit> <kill after seconds>
# Sets RUN_RC, RUN_WALL (seconds), RUN_OOM (true|false|null).
RUN_RC=0 RUN_WALL='' RUN_OOM=null
run_psalm() {
    local threads=$1 report=$2 log=$3 timefile=$4 cgout=$5 limit=$6 kill_after=$7 start end oom_before oom_after
    local guard=(env)
    if [ -n "$TIMEOUT_BIN" ]; then guard=("$TIMEOUT_BIN" -k 20 "$kill_after"); fi

    local psalm=("$PHP_BIN" "${PHP_FLAGS[@]}" "$PSALM_DIR/psalm" --root="$PROJ" --config="$PROJ/psalm-perf.xml"
        --no-cache --no-progress --ignore-baseline --threads="$threads" --output-format=console
        --report="$report" --memory-limit="$limit")

    local full
    if [ "$MODE" = cachegrind ]; then
        full=("${guard[@]}" valgrind --tool=cachegrind --cache-sim=no --cachegrind-out-file="$cgout" "${psalm[@]}")
    else
        full=("${guard[@]}" "${psalm[@]}")
    fi

    oom_before="$(oom_count)"
    RUN_RC=0
    start="$(now)"
    (cd "$PROJ" && /usr/bin/time "$TIME_FLAG" -o "$timefile" "${full[@]}") > "$log" 2>&1 < /dev/null || RUN_RC=$?
    end="$(now)"
    RUN_WALL="$(elapsed "$start" "$end")"
    oom_after="$(oom_count)"
    RUN_OOM=null
    if [ -n "$oom_before" ] && [ -n "$oom_after" ]; then
        if [ "$oom_after" -gt "$oom_before" ]; then RUN_OOM=true; else RUN_OOM=false; fi
    fi
}

# Psalm's own "Checks took X seconds and used Y MB of memory" line → MB (MiB).
parse_psalm_mb() {
    { grep -E 'Checks took .* seconds and used .*MB of memory' "$1" || true; } | tail -n 1 \
        | sed -E 's/.*used ([0-9.,]+)MB.*/\1/' | tr -d ','
}

# /usr/bin/time output (GNU -v or BSD -l) → TIME_CPU (user+sys seconds), TIME_RSS_KB, TIME_SIGNAL (number).
TIME_CPU='' TIME_RSS_KB='' TIME_SIGNAL=''
parse_time() {
    TIME_CPU='' TIME_RSS_KB='' TIME_SIGNAL=''
    [ -s "$1" ] || return 0
    if grep -q 'Maximum resident set size' "$1"; then
        TIME_CPU="$(awk -F': ' '/User time|System time/ {s += $2} END {if (NR) printf "%.2f", s}' "$1")"
        TIME_RSS_KB="$(awk -F': ' '/Maximum resident set size/ {print $2; exit}' "$1")"
        TIME_SIGNAL="$(sed -n 's/.*Command terminated by signal \([0-9][0-9]*\).*/\1/p' "$1" | head -n 1)"
    else
        TIME_CPU="$(awk '/ real .* user .* sys/ {printf "%.2f", $3 + $5; exit}' "$1")"
        TIME_RSS_KB="$(awk '/maximum resident set size/ {printf "%d", $1 / 1024; exit}' "$1")"
    fi
}

# Exit status of the run just made in the shell convention (128 + signal when /usr/bin/time saw one). Needs parse_time.
run_exit_code() {
    if [ -n "$TIME_SIGNAL" ]; then echo $((128 + TIME_SIGNAL)); else echo "$RUN_RC"; fi
}

# classify_run <log> <report> <limit seconds>: sets RUN_STATUS (ok|crash|timeout) for the run just made (parse_time first).
# ok = Psalm exited 0 (no issues) or 2 (issues found) AND printed its "Checks took" line AND wrote a report.
# Any other exit or signal, even after the report was written, is a crash.
# A KILLed process after timeout's grace period exits 137, the same as an OOM kill: tell them apart by wall time.
RUN_STATUS=''
classify_run() {
    local rc
    rc="$(run_exit_code)"
    if [ "$RUN_RC" -eq 124 ] || { [ "$RUN_RC" -eq 137 ] && awk -v w="$RUN_WALL" -v l="$3" 'BEGIN { exit !(w >= l) }'; }; then
        RUN_STATUS=timeout
    elif { [ "$rc" -eq 0 ] || [ "$rc" -eq 2 ]; } && grep -q 'Checks took' "$1" && [ -s "$2" ]; then
        RUN_STATUS=ok
    else
        RUN_STATUS=crash
    fi
}

# EXIT_CODE and SIGNAL of the t1 run just made.
set_exit_info() {
    EXIT_CODE="$(run_exit_code)" SIGNAL=''
    if [ "$EXIT_CODE" -gt 128 ] && [ "$EXIT_CODE" -le 192 ]; then SIGNAL="SIG$(kill -l $((EXIT_CODE - 128)))"; fi
}

# crash_reason <log> <report>
crash_reason() {
    if [ "$RUN_OOM" = true ]; then REASON="killed by the kernel OOM killer"
    elif grep -q 'Allowed memory size' "$1"; then REASON="PHP memory limit exceeded"
    elif [ -n "$SIGNAL" ]; then REASON="killed by $SIGNAL"
    elif grep -q 'Checks took' "$1" && [ -s "$2" ]; then REASON="Psalm exited with code $EXIT_CODE after finishing the analysis"
    else REASON="Psalm exited with code $EXIT_CODE without a report"
    fi
}

# t4 (push/dispatch, native only): only projects that pass the J2 memory rule (lib/corpus.php) run; the others
# record the skip.
run_t4() {
    local res=$1
    if [ "$(pf '.t4_eligible')" != true ]; then
        T4_JSON='{"skipped":"memory budget"}'
        return 0
    fi
    run_psalm 4 "$res/report-t4.json" "$res/t4.log" "$res/t4.time" '' "$(pf '.t4_limit_mb')M" "$TIMEOUT"
    parse_time "$res/t4.time"
    classify_run "$res/t4.log" "$res/report-t4.json" "$TIMEOUT"
    if [ "$RUN_STATUS" = ok ]; then
        WALL4=$RUN_WALL
        MB4="$(parse_psalm_mb "$res/t4.log")"
        RSS4=$TIME_RSS_KB
    else
        # Informational run: the t1 result stands.
        T4_JSON="$(jq -nc --arg s "$RUN_STATUS" '{failed: $s}')"
        echo "run-shard: $NAME threads=4 run failed ($RUN_STATUS), metrics left empty" >&2
    fi
}

# Finish a project whose analysis did not complete.
fail_run() {
    STATUS=$RUN_STATUS
    LOG_TAIL="$(log_tail_of "$1")"
}

# The work of one project: setup of its variables, its env file, install, analysis, result. Returns 0 once a result
# is recorded; a failure of the harness itself (jq, a full disk, ...) is a non-zero exit under `set -e` that
# measure_guarded turns into a setup-failed/inconclusive record for this project.
measure_project_body() {
    local t0 t1 log="$OUT/$NAME.log"
    PJ="$(printf '%s' "$INFO" | jq -c --arg n "$NAME" '.projects[] | select(.name == $n)')"
    TIMEOUT="$(pf '.timeout')" INSTALL_TIMEOUT="$(pf '.install_timeout')"
    write_env_file

    t0="$(now)"
    if ! install_project; then
        STATUS=setup-failed
        ELAPSED_INSTALL="$(elapsed "$t0" "$(now)")"
        LOG_TAIL="$(log_tail_of "$log")"
        write_result
        return 0
    fi
    t1="$(now)"
    ELAPSED_INSTALL="$(elapsed "$t0" "$t1")"
    date +%s > "$OUT/$NAME.started"

    local res="$WORK/$NAME.results"
    rm -rf "$res"
    mkdir -p "$res"
    # The log replaces the install output and is written live (see trim_log).
    run_psalm 1 "$res/report.json" "$log" "$res/t1.time" "$res/cachegrind.out" "$MEMORY_LIMIT" "$TIMEOUT"
    OOM_KILLED=$RUN_OOM
    ELAPSED_ANALYSIS=$RUN_WALL WALL1=$RUN_WALL
    parse_time "$res/t1.time"
    CPU1=$TIME_CPU RSS1=$TIME_RSS_KB
    set_exit_info
    classify_run "$log" "$res/report.json" "$TIMEOUT"

    if [ "$RUN_STATUS" = timeout ]; then
        fail_run "$log"
        REASON="analysis exceeded ${TIMEOUT}s"
        if [ "$CPU_MEASURED" != true ]; then
            STATUS=inconclusive
            REASON="timeout on unmeasured CPU $CPU_MODEL"
        fi
        write_result
        return 0
    elif [ "$RUN_STATUS" = crash ]; then
        fail_run "$log"
        crash_reason "$log" "$res/report.json"
        write_result
        return 0
    fi

    MB="$(parse_psalm_mb "$log")"
    if [ "$MODE" = cachegrind ]; then
        IR="$(awk '/^summary:/ {print $2; exit}' "$res/cachegrind.out" 2>/dev/null || true)"
        if [ -z "$IR" ]; then
            # Valgrind ran but left no usable output: a harness problem, not a Psalm failure.
            STATUS=inconclusive REASON="harness error: cachegrind produced no summary"
            LOG_TAIL="$(log_tail_of "$log")"
        fi
    fi

    if [ "$STATUS" = ok ]; then
        if ISSUE_COUNT="$("$PHP_BIN" "$SCRIPT_DIR/normalize-issues.php" "$res/report.json" "$PROJ" "$OUT/$NAME.issues.json" 2> "$res/normalize.log")"; then
            ISSUES_FILE="$NAME.issues.json"
        else
            STATUS=inconclusive ISSUE_COUNT='' REASON="harness error: normalize-issues failed"
            LOG_TAIL="$(log_tail_of "$res/normalize.log")"
        fi
    fi

    write_result
    if [ "$STATUS" = ok ] && [ "$MODE" = native ] && [ "$THREADS4" -eq 1 ]; then
        run_t4 "$res"
        write_result
    fi
    rm -rf "$res"
}

# Why the body of the current project died: the failing command (its first word) of the ERR trap.
harness_trap() {
    local rc=$1 cmd=${2//$'\n'/ }
    cmd=${cmd%% *}
    printf '%s failed with exit code %s' "${cmd:0:60}" "$rc" > "$WORK/$NAME.harness-error"
}

# Last resort for a project whose measurement died of a harness error: a setup-failed record when the analysis had
# not started yet (the install phase never completed), else inconclusive; `crash` is reserved for the measured Psalm
# process. A result the body had already written stands. The next project still runs. When not even this record
# can be written, RESULTS_UNWRITTEN makes the shard exit non-zero at the end.
record_harness_error() {
    local detail
    detail="$(cat "$WORK/$NAME.harness-error" 2>/dev/null)" || detail="exit $1"
    echo "run-shard: $NAME: harness error: $detail" >&2
    rm -rf "$WORK/$NAME.results"
    if [ -f "$OUT/$NAME.json" ]; then
        echo "run-shard: $NAME: the result was already written, kept" >&2
        return 0
    fi

    rm -f "$OUT/$NAME.issues.json" || true
    if [ -f "$OUT/$NAME.started" ]; then STATUS=inconclusive; else STATUS=setup-failed; fi
    REASON="harness error: $detail"
    LOG_TAIL="$(log_tail_of "$OUT/$NAME.log" 2>/dev/null)" || LOG_TAIL=''
    write_result || {
        RESULTS_UNWRITTEN=1
        rm -f "$OUT/$NAME.json" "$OUT/$NAME.json.tmp" || true
        echo "run-shard: $NAME: cannot record the harness error either; write-stubs.php will stub it" >&2
    }
}

# `set -e` is inert inside anything called from `||` or `if`, so the body runs as a plain command of a subshell
# between `set +e` and `set -e`: its own `set -e` stays effective and its exit status is read afterwards.
measure_guarded() {
    local rc=0
    rm -f "$WORK/$NAME.harness-error"
    set +e
    (
        set -eE
        trap 'harness_trap "$?" "$BASH_COMMAND"' ERR
        measure_project_body
    )
    rc=$?
    set -e
    if [ "$rc" -ne 0 ]; then record_harness_error "$rc"; fi
}

measure_project() {
    NAME=$1
    PROJ="$WORK/$NAME"
    STATUS=ok REASON='' LOG_TAIL='' T4_JSON=''
    IR='' MB='' WALL1='' CPU1='' RSS1='' WALL4='' RSS4='' MB4='' ISSUE_COUNT='' ISSUES_FILE=''
    EXIT_CODE='' SIGNAL='' OOM_KILLED='' ELAPSED_INSTALL='' ELAPSED_ANALYSIS=''
    rm -f "$OUT/$NAME.json" "$OUT/$NAME.issues.json" "$OUT/$NAME.started" "$OUT/$NAME.log" "$OUT/$NAME.env.json" || true
    echo "== $NAME ($MODE)" >&2

    measure_guarded
    trim_log || true
}

# ----------------------------------------------------------------------- main
while IFS= read -r project <&3; do
    measure_project "$project"
done 3<<< "$PROJECT_LIST"

if [ "$RESULTS_UNWRITTEN" -ne 0 ]; then
    echo "run-shard: shard $SHARD finished, but the result of at least one project could not be written" >&2
    exit 1
fi
echo "run-shard: shard $SHARD done" >&2
