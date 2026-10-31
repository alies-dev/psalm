# Psalm perf and issue-diff harness

Every non-draft PR to `master` runs Psalm on a pinned corpus of real projects, once with the merge-base (base) and once with the PR head (head), on the same runner. The check reports Psalm's peak memory, wall time, and the exact issues that were added or removed. Memory is the gate, timing is only informational.

What v1 does not do: no cachegrind or instruction counts, no history of past runs, no base cache, no push or release runs. A `perf:cachegrind` label mode and a history are planned separately.

## How it works

```
pull_request
   plan      merge-base, composer.json changed?, job matrix
   measure   one job per corpus job (laravel, symfony, orm-psl, phpunit-parser, small, drupal), never red itself
   report    compare.php, job summary, artifact perf-report (the only step that fails, on a regression)

perf-comment.yml (workflow_run, default branch) posts perf-report as the sticky PR comment
```

| File | Purpose |
|---|---|
| `corpus.json` | PHP version, php.ini settings, memory limit, jobs, projects pinned by commit SHA |
| `corpus/<name>/psalm.xml` | Our Psalm config for that project |
| `perf.php` | `plan`, `normalize` and `compare` (code in `lib/`) |
| `run-shard.sh` | Measures all projects of one job for base and head |

Per project, `run-shard.sh` clones the pinned commit once, runs `composer update --no-dev --no-scripts --no-plugins --ignore-platform-reqs` once (no lock files, base and head share the result), then runs Psalm for base and for head with `--no-cache --no-progress --ignore-baseline --threads=1` and the corpus memory limit, under `timeout`. If both runs are ok and the wall times differ by more than 10 %, both run again in reverse order (head, then base). Each side keeps the minimum wall and cpu of its runs.

Psalm's own dependencies are resolved once per job in the base checkout. The head reuses that `composer.lock`, unless the PR changes `composer.json`; then it resolves its own and memory and time deltas become informational.

## Verdicts

The first line of the report is the verdict, the head SHA and `N/M compared`.

| Verdict | Meaning | Job |
|---|---|---|
| ✅ pass | Nothing regressed | succeeds |
| ⚠️ warn | Something could not be judged, or time got worse | succeeds |
| ❌ fail | A regression | fails |

❌ when Psalm MB (threads=1, as Psalm reports it) is more than 5 % above base, or head crashed or timed out while base was ok:

| Head failure | ❌ only if | otherwise |
|---|---|---|
| memory crash | base used less than 50 % of the memory limit | ⚠️ |
| timeout | base wall was less than 50 % of the timeout | ⚠️ |
| any other crash | always | |

⚠️ also for a wall time more than 20 % above base after the re-run, a missing or invalid result (including a measure job that failed or timed out before writing results), and a harness or setup error (clone, install, normalisation). A base that crashed or timed out is "not comparable": listed, not counted. A memory crash is a log containing `Allowed memory size` or `Hard memory limit`.

Timing is informational: both sides share a runner, but shared runners still show about ±10 % noise. Do not read a single wall delta as a regression; the re-run exists to avoid one noisy sample.

Issues are compared as a multiset of file, line, column, type, severity and message (digit runs of 9 or more in messages are masked). The report lists at most 10 added and 10 removed issues per project, per-type counts, and stays under 60 000 bytes.

## Result files

`run-shard.sh` writes `<out>/<rev>/<project>.json` and, for an ok run, `<project>.issues.json`:

```json
{"schema": 1, "project": "psl", "rev": "head", "sha": "…", "status": "ok", "reason": null,
 "memory_crash": false, "psalm_mb": 664.2, "wall": 61.4, "cpu": 59.9, "max_rss_kb": 801234,
 "exit_code": 2, "runs": [{"wall": 61.4, "cpu": 59.9}], "cpu_model": "AMD EPYC 7763 64-Core Processor",
 "issue_count": 12, "log_tail": null}
```

| `status` | Meaning |
|---|---|
| `ok` | Exit code 0 or 2, Psalm printed its "Checks took" line, `report.json` exists |
| `crash` | Any other exit or signal (`memory_crash` tells the memory case apart) |
| `timeout` | `timeout` fired after the project's limit |
| `error` | The harness failed (clone, install, launcher, normalisation); `reason` says why |

## Add or bump a project

1. Pick a release tag and resolve its commit:

   ```bash
   gh api repos/doctrine/orm/commits/3.7.6 --jq .sha
   ```

2. Add the project to `projects` and to exactly one entry of `jobs` in `corpus.json`:

   ```json
   "doctrine-orm": {
     "repo": "https://github.com/doctrine/orm",
     "ref": "4487f850401c0e1f27891cce5897fa90b3055c2a",
     "tag": "3.7.6",
     "timeout": 120,
     "exercises": "deep class hierarchies, class-string<T> repositories"
   }
   ```

   Optional fields: `"composer": false` (skip the install), `"setup": "<shell, run in the project root before the install>"`, `"config": "<dir under corpus/>"` (default: the project name).

3. Create `corpus/<name>/psalm.xml`. Paths resolve against the project root. Do not set `loadLibraryPlugins`: it is injected as `false` when both Psalm checkouts know the attribute.

4. Set `timeout` to about twice the single-threaded analysis time on a GitHub runner. A bump of `ref` and `tag` needs nothing else, but check that the new version still installs without a lock.

## Run locally

Both Psalm checkouts need `composer install --no-dev` done. Base and head can be the same directory for a smoke test:

```bash
bin/perf/run-shard.sh --job small --base ../psalm-base --head "$PWD" \
  --base-sha "$(git -C ../psalm-base rev-parse HEAD)" --head-sha "$(git rev-parse HEAD)" \
  --out /tmp/perf-out --work /tmp/perf-work
jq '{project, status, psalm_mb, wall, issue_count}' /tmp/perf-out/*/*.json
```

macOS works with `gtimeout` (`brew install coreutils`). `PERF_CORPUS=<corpus.json>` uses another corpus, for example one tiny project with `"composer": false`; its `psalm.xml` lives in `corpus/<name>/` next to that file. Render a report from results with `php bin/perf/perf.php compare` (see `perf.php` for the flags).

## Security model

`plan`, `measure` and `report` run PR code with a read-only token, no secrets and no environment. `perf-comment.yml` runs from the default branch, never checks out PR code, and treats `perf-report` as untrusted: `pr_number` and `head_sha` must match GitHub's own metadata, mentions are neutralised, and an oversized report is replaced by a constant text.
