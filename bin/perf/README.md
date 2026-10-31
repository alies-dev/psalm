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
| `corpus.yml` | PHP version, php.ini settings, memory limit, jobs, projects pinned by commit SHA, the fields their Psalm config is generated from |
| `perf.php` | `plan`, `config`, `normalize` and `compare` (code in `lib/`) |
| `run-shard.sh` | Measures all projects of one job for base and head |

Per project, `run-shard.sh` clones the pinned commit once, runs `before_install` if set, runs `composer update --no-dev --no-scripts --no-plugins --ignore-platform-reqs` once (no lock files, base and head share the result), then generates `psalm-perf.xml` (`perf.php config`), runs `after_install` if set and runs Psalm for base and for head with `--no-cache --no-progress --ignore-baseline --threads=1` and the corpus memory limit, under `timeout`. If both runs are ok and the wall times differ by more than 10 %, both run again in reverse order (head, then base). Each side keeps the minimum wall and cpu of its runs.

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

## Weighted issues

Informational only, it never changes a verdict (a rise is not necessarily a regression). Per project and side, weighted issues = sum over all issues of a weight taken from the issue type's `error_level` (kept in `<project>.issues.json`, not part of the diff identity): the level itself for 1 to 8, 10 for always-error issues (-1), 0 for feature-specific ones (-2: unused code, opinionated checks). The corpus code is pinned, so a change means Psalm reports more, fewer or differently serious issues.

The table cell is `86 226 → 86 726 (+500, +0.58 %)` (`=` when unchanged, `<0.01 %` for a tiny change). Under the verdict line the pooled corpus figure (Σhead against Σbase of the projects compared on both sides) is followed by how many projects changed and the largest relative change. The details of a project list the per-level issue count deltas (`L1 +100 · L6 −11 · always +2 · feature −3`, non-zero levels only). A `drift` note marks a project whose issue count is unchanged but whose weighted issues changed, i.e. issues were reclassified. `summary.json` holds `weighted_issues` per project (`base`, `head`, `delta`, `delta_pct`, `by_level`, `drift`).

Read +Δ as more or more serious issues reported, so new false positives or new true positives; −Δ as fewer or less serious ones, so fixed false positives or lost detection. The per-type table and examples tell which.

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

2. Add the project to `projects` and to exactly one entry of `jobs` in `corpus.yml` (the file header documents every field):

   ```yaml
   laravel:
     repo: https://github.com/laravel/framework
     ref: "d52c3b3129e4c8e213c97cdc68f94b0b7d27f181" # v13.39.0
     tag: v13.39.0
     timeout: 300
     exercises: magic __call/macros, Eloquent generics, large Collection type surface
     psalm:
       dirs: [src]            # projectFiles directories, globs allowed
       # ignore: [src/*/Tests]  # `vendor` is always ignored
       # level: 1             # errorLevel, default 1
   ```

   Optional project fields: `composer: false` (skip the install), `before_install` (shell, run in the project root before the install), `after_install` (shell, run once in the project root after `composer update` and after `psalm-perf.xml` is generated, before the base and head runs; may patch `psalm-perf.xml`).

3. `psalm-perf.xml` is generated from `psalm.dirs`, `psalm.ignore` and `psalm.level` (default 1: the level only changes error/info labels, not what is analysed or reported, and level 1 keeps severities uniform so a reclassified issue type does not flip thousands of issues), with fixed attributes (`resolveFromConfigFile`, `findUnusedCode`, `findUnusedBaselineEntry` and `findUnusedIssueHandlerSuppression` false). `loadLibraryPlugins="false"` is injected when both Psalm checkouts know the attribute. Anything else is patched in `after_install`; Drupal adds its file extensions:

   ```yaml
   after_install: |
     exts=''
     for e in php module inc install theme profile engine; do exts="$exts<extension name=\".$e\"/>"; done
     sed -i.bak "s#</psalm>#<fileExtensions>$exts</fileExtensions></psalm>#" psalm-perf.xml
   ```

   The final config of every project is saved as `<out>/<project>.psalm.xml` and ends up in the job artifact.

4. Set `timeout` to about twice the single-threaded analysis time on a GitHub runner. A bump of `ref` and `tag` needs nothing else, but check that the new version still installs without a lock.

## Run locally

Both Psalm checkouts need `composer install --no-dev` done. Base and head can be the same directory for a smoke test:

```bash
bin/perf/run-shard.sh --job small --base ../psalm-base --head "$PWD" \
  --base-sha "$(git -C ../psalm-base rev-parse HEAD)" --head-sha "$(git rev-parse HEAD)" \
  --out /tmp/perf-out --work /tmp/perf-work
jq '{project, status, psalm_mb, wall, issue_count}' /tmp/perf-out/*/*.json
```

macOS needs `gtimeout` (`brew install coreutils`) and `yq` (`brew install yq`, mikefarah v4). `PERF_CORPUS=<corpus.yml or corpus.json>` uses another corpus, for example one tiny project with `composer: false`; a `.yml` is converted with `yq -o=json`, PHP and `jq` only ever read the JSON. Render a report from results with `php bin/perf/perf.php compare` (see `perf.php` for the flags).

## Security model

`plan`, `measure` and `report` run PR code with a read-only token, no secrets and no environment. `perf-comment.yml` runs from the default branch, never checks out PR code, and treats `perf-report` as untrusted: `pr_number` and `head_sha` must match GitHub's own metadata, mentions are neutralised, and an oversized report is replaced by a constant text.
