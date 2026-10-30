# Psalm perf and issue-diff harness

CI measures Psalm against a pinned corpus of real projects on every non-draft PR to `master` and compares it with the PR's merge-base. It reports CPU cost (cachegrind instructions, `Ir`), Psalm's own peak memory, wall time, and the exact issues that were added, removed or changed. A clear regression fails the check; anything that could not be measured reliably is flagged, never silently ignored.

## How it works

```
pull_request / push / workflow_dispatch
        |
   prepare ── lint corpus, find the base bundle, resolve Psalm's composer locks,
        |     fingerprint the environment, plan the matrix  ──> artifact perf-plan
        |
   measure (one job per shard; base first, then head, on the same runner)
        |                                               ──> artifacts perf-<shard>
   measure-extra (push/dispatch only: post-merge shards, threads=4 runs)
        |                                               ──> artifacts perf-extra-<shard>
   report ── compare.php, job summary, verdict          ──> artifact perf-report
        |     (push only: save the base bundle)
   publish (push/dispatch only) ── results/<branch>/<sha>.json in psalm/perf-data

perf-comment.yml (workflow_run, default branch) ── sticky PR comment from perf-report
```

| File | Purpose |
|---|---|
| `corpus.json` | Registry: shards, projects (pinned by commit SHA), sizing |
| `corpus/<name>/` | Our `psalm.xml` and `composer.lock` for that project |
| `php-flags.txt` | Every `-d` flag of a Psalm run (also part of the environment fingerprint) |
| `select.php` | `--lint` enforces the sizing rules; `plan` builds the job matrix and `expected.json` |
| `fingerprint.php` | Environment fingerprints (hard) and soft fingerprints |
| `resolve-lock.php` | Psalm's own `composer.lock` for base and head |
| `run-shard.sh` | Measures all projects of one shard for one Psalm checkout |
| `project-info.php` | Sizing answers for one shard (mode, memory limit, timeouts, `t4_eligible`, CPU), printed as JSON |
| `write-stubs.php` | Result stubs for shards that were interrupted or failed |
| `bundle.php` | Builds and validates the base bundle |
| `normalize-issues.php` | Psalm's `report.json` to a stable, sorted issue list |
| `compare.php`, `lib/` | Base vs head into `report.md`, `report-short.md`, `summary.json` |

### What is measured

Per project, `run-shard.sh` clones the pinned SHA, installs it from our `composer.lock` (bounded by `install_timeout`), then runs Psalm with `--no-cache --no-progress --ignore-baseline --threads=1 --output-format=console --report=<json>` and a memory limit from `corpus.json`. Analysis is bounded by `timeout` (1.5 times `expected_seconds`). The `loadLibraryPlugins="false"` attribute is injected into the project's config only when the tested Psalm's `config.xsd` declares it.

Two modes, chosen per shard:

* `cachegrind`: one run under `valgrind --tool=cachegrind`. Records `Ir` (stable to about 0.001 %), Psalm MiB, issues.
* `native`: wall time, CPU time, max RSS, Psalm MiB, issues. Post-merge only: a second run with `--threads=4`.

`run-shard.sh` asks `project-info.php --corpus <corpus.json> --shard <id> [--projects <csv>]` once per shard instead of re-implementing the sizing rules in shell. It prints `mode`, `memory_limit`, `cpu`, `cpu_measured` and, per selected project, the pinned repo and ref, `timeout`, `install_timeout`, `expected_mb`, `t4_eligible` and `t4_limit_mb`. It exits 2 on an unknown shard or project, or when the mode has no memory limit.

Before analysing a project, `run-shard.sh` writes `<project>.env.json` (`fingerprint`, `soft`, `cpu`, `cpu_measured`, `vm_ram_kb`) next to the result. `write-stubs.php` copies it into the stub of a project that was interrupted, so the stub keeps the environment identity the comparator needs.

The result file `<project>.json` carries a `t4` field for the threads=4 run of native projects:

| `t4` | Meaning |
|---|---|
| `null` | No threads=4 result to explain: the run succeeded (its metrics are `wall_t4`, `rss_t4_kb`, `psalm_mb_t4`) or was not requested |
| `{"skipped": "memory budget"}` | `run-shard.sh --threads4` met a project that is not `t4_eligible` and did not attempt the run |
| `{"failed": "<status>"}` | The threads=4 run ended with that status; its metrics stay empty and the threads=1 result stands |

Natives that are not eligible are listed in the plan's `t4_skipped` (`{"project", "reason"}`) and published in the `t4_skipped` field of the `perf-data` record.

### Which base is used

The base of a PR is its merge-base with the target branch. Measuring it on every PR would double the cost, so a push run on the branch saves a base bundle (all PR-tier head results plus the Psalm lock) under the cache key `perf-base-<branch>-<sha>`. Prepare walks up to 30 first-parent commits from the merge-base and uses the nearest bundle.

* A bundle from an ancestor A of the merge-base provides results only if nothing outside `docs`, `*.md`, `.github`, `.circleci`, `tests` and `bin/perf` changed between A and the merge-base. Its lock is always the starting point for locking.
* A cached result is reused per project when its status is `ok`, `crash` or `timeout` and its environment fingerprint equals the one prepare computed for the head. Everything else is measured fresh. A shard measures its base only if at least one of its projects needs it, and then only those projects.
* Only push runs on protected branches (`master`) save bundles. Pull request and dispatch runs never do.
* Every project's base has exactly one source (bundle or fresh), shown in `summary.json` as `base_source`.

Psalm's own dependencies are pinned the same way: the base lock is derived from the bundle lock with `composer update --minimal-changes`, the head lock from the base lock (strict: the PR must not move unrelated packages unless its `composer.json` forces it). Packagist drift therefore never shows up as a perf difference, and packages that differ between the two locks are listed in the report footer.

## Verdicts

The first line of the report is the verdict, the head SHA and `N/M compared`.

| Verdict | Meaning | Check |
|---|---|---|
| ✅ pass | Nothing regressed | succeeds |
| ⚠️ inconclusive | At least one project could not be compared reliably | succeeds |
| ❌ fail | At least one regression | fails |

Per project:

| Outcome | When |
|---|---|
| regression | `Ir` more than +3 % or Psalm MiB more than +5 % (both runs `ok`, same environment); head `crash` where base was `ok`; head `timeout` where base was `ok` and comfortably inside its timeout |
| compared | both `ok` and the environment fingerprints are equal |
| not comparable | base `crash` or `timeout`; listed, excluded from `N` |
| inconclusive | `setup-failed`, `inconclusive`, missing or invalid result on either side; environment fingerprint mismatch; a timeout on a CPU model that was never measured; dependency resolution failed |

Downgrades to inconclusive, so that noise never fails a PR:

* A memory crash (PHP "Allowed memory size" fatal, or an OOM kill reported by the cgroup) when the base already used more than 80 % of the limit.
* An `Ir` regression while the soft fingerprints (shared library hashes) differ between base and head. Memory regressions are never downgraded.
* A timeout that the base's predicted time on the slowest CPU does not explain.

Deltas below 0.5 % print as `≈`. Rows are marked `env differs` whenever shared libraries differ, and when a result is within 20 % of its timeout.

Issues are compared as a multiset of file, lines, columns, type, severity and message (digit runs of 9 or more in messages are masked). "Changed" means same file, line and type with a different message or severity. Details are capped at 10 entries per list per project and 40 000 bytes in total.

## Latency

| Scenario | Time from push to report |
|---|---|
| Warm PR (bundle reusable, only head measured) | about 7 to 9 minutes plus queue |
| Cold PR (no bundle or env changed, base and head share one runner) | about 13 to 16 minutes plus queue |

Queueing is outside the workflow's control: `ci.yml` starts about 45 jobs and the organization has 20 slots. A PR shows `pending` until slots free up. The first PR after a harness change (`bin/perf`) or a runner image change is always cold for the affected projects.

## Add a project

1. Pick a release tag and resolve its commit:

   ```bash
   gh api repos/doctrine/orm/commits/3.7.6 --jq .sha
   ```

2. Add it to `corpus.json` with a shard (a project belongs to exactly one) and its sizing:

   ```json
   "doctrine-orm": {
     "repo": "https://github.com/doctrine/orm",
     "ref": "4487f850401c0e1f27891cce5897fa90b3055c2a",
     "tag": "3.7.6",
     "shards": ["cg-orm"],
     "expected_seconds": 300,
     "expected_ir": 50000000000,
     "expected_install_seconds": 20,
     "expected_mb": 440,
     "exercises": "deep class hierarchies, class-string<T> repositories"
   }
   ```

   Optional fields: `"composer": false` (skip install), `"setup": "<shell run in the project root before install>"`, `"config": "<corpus dir>"` (take `psalm.xml` from another directory), `"lock": "<project>"` (reuse another project's `composer.lock`), `"install_timeout": <seconds>` (overrides the global install timeout of `corpus.json` for a project whose install is slower than the default; size it above `expected_install_seconds`).

3. Create `corpus/<name>/psalm.xml`. Paths resolve against the project root. Do not set `loadLibraryPlugins`.

4. Generate the lock (below) into `corpus/<name>/composer.lock`.

5. Measure it (see "Re-size") and fill in the `expected_*` numbers, then run `php bin/perf/select.php --lint`. Lint enforces the whole sizing contract and fails the CI run with a message instead of dropping a project:

   * every enabled project has `expected_mb`, and the mode's memory limit is at least 1.25 times that
   * a shard's projects fit the PR budget (`pr_budget_seconds`) on the slowest measured CPU
   * no project is in two shards, and every shard exists
   * `disabled`, if present, is a non-empty string

   `expected_*` values come from the slowest CPU in `measured_cpus` and from the largest Psalm MiB seen in any measured run, including the master base.

## Bump a project, regenerate a lock

Change `ref` and `tag`, then regenerate the lock with the harness' PHP version:

```bash
git clone https://github.com/doctrine/orm /tmp/lock-orm
git -C /tmp/lock-orm checkout 4487f850401c0e1f27891cce5897fa90b3055c2a
cd /tmp/lock-orm
rm -f composer.lock
composer update --no-dev --no-scripts --no-plugins --ignore-platform-reqs --no-interaction
cp composer.lock <psalm checkout>/bin/perf/corpus/doctrine-orm/composer.lock
```

Project fields, `psalm.xml` and the lock are part of the environment fingerprint, so bumping a project makes only that project's cached base results stale. Any change to `run-shard.sh`, `normalize-issues.php` or `php-flags.txt` makes all of them stale.

## Slice a project

Slices are separate projects that share the full project's `repo`, `ref` and lock, with a `psalm.xml` whose `projectFiles` cover a subset:

```json
"orm-slice": {
  "repo": "https://github.com/doctrine/orm", "ref": "4487f850401c0e1f27891cce5897fa90b3055c2a", "tag": "3.7.6",
  "config": "orm-slice", "lock": "doctrine-orm", "shards": ["cg-orm"],
  "expected_seconds": 300, "expected_ir": 50000000000, "expected_mb": 440
}
```

Aim for 35 to 55 G `Ir` per PR-tier cachegrind project so the slowest CPU stays within the budget. Estimate the share from the native time share of the directories, then confirm with a cachegrind run. The full project runs in the post-merge tier.

## Re-size

Run the shard on a push or dispatch run (`measure-extra` and the PR tier both upload artifacts) and read `ir`, `psalm_mb`, `wall_t1` from `perf-<shard>/head/<project>.json`; take the maximum over a few runs on the slowest CPU model. For a cachegrind project `expected_seconds` is `expected_ir` divided by the slowest throughput (about 167 M `Ir`/s on the slowest CPU seen so far) plus overhead. When the report warns that a push run predicts more than 1.2 times `expected_seconds`, re-size.

To size a disabled project without enabling it, dispatch the workflow with `include_disabled`:

```bash
gh workflow run perf.yml --ref master -f include_disabled=wordpress
```

The project runs in the extra tier (memory rule skipped) and never saves a base. Its measured MiB appear in the job summary of the `publish` job only: disabled projects are not written to `psalm/perf-data`. A project with a slow install needs its own `install_timeout` first.

A dispatch may only measure commits that are already part of the selected branch (`ref` defaults to its tip). `prepare` rejects any other ref, including `refs/pull/<n>/head`, because dispatched code runs with the branch's cache scope. A dispatch never overwrites a `results/<branch>/<sha>.json` that already exists in `psalm/perf-data`.

## Run locally

```bash
bin/perf/run-shard.sh --shard cg-orm --psalm "$PWD" --sha "$(git rev-parse HEAD)" --out /tmp/perf-out/head
bin/perf/run-shard.sh --shard cg-orm --psalm ../psalm-base --sha <base sha> --out /tmp/perf-out/base
jq '{project, status, metrics}' /tmp/perf-out/head/*.json
```

`--psalm` is a Psalm checkout with its dependencies installed. `--projects a,b` restricts the shard, `--work` holds the project clones (an unchanged clone is reused), `--threads4` adds the threads=4 run of native projects.

Cachegrind needs Linux and valgrind. On macOS force native mode for a smoke test (the result has no `Ir` and must never be compared with cachegrind results; CI never sets this):

```bash
PERF_FORCE_MODE=native bin/perf/run-shard.sh --shard cg-orm --psalm "$PWD" --sha "$(git rev-parse HEAD)" --out /tmp/perf-out/head
```

Check the sizing rules and the fingerprints without running anything:

```bash
php bin/perf/select.php --lint
php bin/perf/fingerprint.php --corpus bin/perf/corpus.json --projects amphp-amp
```

## Projects that are not in the PR tier

`wordpress` carries a `disabled` reason in `corpus.json`: on master its Psalm peak is about 11.7 GiB because of the taint bug (vimeo/psalm#12618, #12561), which leaves no headroom under the 12G native limit. Re-enable it after those fixes land: re-measure `expected_mb` (about 2.8 GiB with the fix) and remove `disabled`. It is already assigned to the post-merge shard `nat-wordpress`; to measure it on pull requests, move it to a PR-tier shard, or leave it post-merge.

The post-merge tier (`measure-extra`, push and dispatch only) runs the full `doctrine-orm`, `psl`, `phpunit` and `php-parser` under cachegrind, and the threads=4 runs. Only PR-tier native projects are considered for a threads=4 run, and only those for which 5 processes at 1.25 times `expected_mb` fit in 12 GB get one; today that qualifies only `laravel`. `select.php plan` prints a warning when no native project qualifies. The other PR-tier native projects never reach `measure-extra`; the plan lists them as `t4_skipped` (memory budget), and `publish` stores that list in the `t4_skipped` field of the `perf-data` record.

## Security model

* `prepare`, `measure` and `report` run PR code with a read-only token and no secrets. Fork PRs work the same way.
* The deploy key for `psalm/perf-data` exists only in `publish`, which never runs on `pull_request` and publishes only commits that are ancestors of the branch.
* `perf-comment.yml` runs from the default branch, never checks out PR code, and treats the `perf-report` artifact as untrusted: `pr_number` and `head_sha` must match GitHub's own metadata for the run and the PR, mentions are neutralised, oversized reports fall back to a short report and then to a constant text built by the poster.
* Base bundles are saved only by the `report` job of a push run on a protected branch, with a key built from `github.sha`. Caches written by PR runs live in the PR's own ref scope and are never found by branch runs or by other PRs.

## Enable another branch (6.x)

1. Add the branch to `branches` in `corpus.json`.
2. Add it to the `branches` of the `pull_request` and `push` triggers in `.github/workflows/perf.yml`. Trigger filters cannot read variables, so this stays a literal list.
3. Make sure the branch contains `bin/perf` and `perf.yml` (pull request runs use the workflow file of the PR's merge ref). `perf-comment.yml` only needs to exist on the default branch.
4. Merge or push once: the push run saves the first bundle for `6.x`, and the first PR before that is cold. `COMPOSER_ROOT_VERSION` becomes `6.x-dev` automatically (any `^\d+(\.\d+)*\.x$` branch; other branches use `dev-<branch>`).
5. `publish` creates `results/6.x/` in `psalm/perf-data` by itself; check it after the first push run.
6. Re-check the sizing once: 6.x may be slower or faster than master, and `expected_*` are shared by all branches.
