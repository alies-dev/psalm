# Psalm perf and issue-diff harness

CI measures Psalm against a pinned corpus of real projects on every PR to master and compares the result with the base commit. It reports CPU cost (cachegrind instructions, `Ir`), Psalm's own peak memory, wall time, and the exact issues that were added, removed or changed. A PR fails the gate on a clear regression.

## How it works

```
corpus.json              registry: shards and projects (pinned by commit SHA)
corpus/<name>/           our psalm.xml and composer.lock for that project
select.php               builds the CI job matrix (which shard, for which commit)
run-shard.sh             measures all projects of one shard for one Psalm checkout
normalize-issues.php     Psalm report.json into a stable, sorted issue list
compare.php              base vs head into report.md and summary.json (gate verdict)
```

Per project, `run-shard.sh` clones the pinned SHA, drops in our `composer.lock`, runs `composer install --no-dev`, copies our `psalm.xml` to `psalm-perf.xml` and runs Psalm with `--no-cache --no-progress --ignore-baseline --output-format=console --report=<json>`. Psalm re-exec is disabled (`PSALM_ALLOW_XDEBUG=1`) and the opcache settings are passed explicitly. `--threads` is always passed because `CI=true` would otherwise force one thread. `--memory-limit` comes from the top level `memory_limit` of `corpus.json` (default `12G`; Psalm ignores `php -d memory_limit`), so a blow-up ends as status `crash` with the fatal error in `log_tail`. `COMPOSER_ROOT_VERSION` is set to the project `tag` (without a leading `v`) for the composer install, for locks that depend on `self.version`.

Two modes, chosen per shard in `corpus.json`:

* `cachegrind`: one run under `valgrind --tool=cachegrind` with `--threads=1`. Records `Ir`, Psalm MB and the issues. Ir is stable to about 0.001 %.
* `native`: `--threads=1` (wall, user+sys, max RSS, Psalm MB, issues) and `--threads=4` (wall, RSS, Psalm MB). No repeats.

Output per project in `--out`: `<project>.json` (metrics, `status` of `ok|crash|timeout|setup-failed`, `env`, `log_tail`) and `<project>.issues.json`. The exit code of `run-shard.sh` stays 0 when a project crashes.

Issues are normalized so two runs of the same Psalm are byte identical: paths relative to the project root, digit runs of 9 or more characters in messages replaced by `#` (runtime values like `REQUEST_TIME`), sorted. Issue identity is file, lines, columns, type and message. An added and a removed issue at the same file, line and type with different messages are shown as "changed".

## Gate

`compare.php` marks the PR as failed when, for any project and compared with base:

| Metric | Fails when |
|---|---|
| `Ir` (cachegrind shards) | more than +3 % |
| Psalm peak MB at threads=1 | more than +5 % |
| status | head is `crash` or `timeout` where base was `ok` |

Deltas below 0.5 % are shown as `≈`. Wall time and threads=4 numbers are informational. If base and head were measured with a different PHP, valgrind or harness (`bin/perf` content, except `README.md`), Ir and MB comparisons are marked invalid and not gated. Psalm's own dependency versions are not part of that check, so a dependency change made by a PR is gated like any other change.

Exit code of `compare.php` is 0 even when the gate fails; read `gate` in `summary.json`.

## Add a project

1. Pick a release tag and resolve its commit:

   ```bash
   gh api repos/doctrine/orm/commits/3.7.5 --jq .sha
   ```

2. Add the project to `corpus.json`. Optional fields: `"composer": false` (skip install, e.g. WordPress), `"setup": "<shell run in the project root before install>"`, `"config": "<other corpus dir>"` (take `psalm.xml` from another dir, for slices sharing a repo).

   ```json
   "doctrine-orm": {
     "repo": "https://github.com/doctrine/orm",
     "ref": "00c2fd70da291cca1ec75f4dae5344fe3df588fa",
     "tag": "3.7.5",
     "shards": ["cg-1"],
     "timeout": 900,
     "exercises": "deep class hierarchies, class-string<T> repositories, attributes metadata"
   }
   ```

3. Create `corpus/<name>/psalm.xml`. Paths resolve against the project checkout root. Do not set `loadLibraryPlugins`; the harness injects `loadLibraryPlugins="false"` when the tested Psalm supports it.

4. Generate the lock (see below) and commit it as `corpus/<name>/composer.lock`.

5. Size cachegrind shards at about 75G Ir in total (roughly 8 minutes on the slowest runner CPU). Check with a local native run and the Ir of an earlier CI run.

## Bump a project, regenerate a lock

Change `ref` and `tag`, then regenerate the lock against PHP 8.5 with the same flags the harness uses:

```bash
git clone https://github.com/doctrine/orm /tmp/lock-orm
git -C /tmp/lock-orm checkout 00c2fd70da291cca1ec75f4dae5344fe3df588fa
cd /tmp/lock-orm
rm -f composer.lock
composer update --no-dev --no-scripts --no-plugins --ignore-platform-reqs --no-interaction
cp composer.lock <psalm checkout>/bin/perf/corpus/doctrine-orm/composer.lock
```

Any change under `bin/perf` changes the harness hash, so cached base results are measured again on the next PR.

## Run a shard locally

```bash
bin/perf/run-shard.sh --shard cg-1 --psalm "$PWD" --out /tmp/perf-out/head
bin/perf/run-shard.sh --shard cg-1 --psalm "$PWD" --out /tmp/perf-out/base   # another checkout for base
php bin/perf/compare.php --base /tmp/perf-out/base --head /tmp/perf-out/head \
    --out-md /tmp/perf-out/report.md --out-json /tmp/perf-out/summary.json
```

`--psalm` is a Psalm checkout with its dependencies installed. `--work` (default `$RUNNER_TEMP/perf-work` or `/tmp/perf-work`) holds the project clones; an unchanged clone is reused on the next run.

Cachegrind needs Linux and valgrind. On macOS, force native mode for testing only:

```bash
PERF_FORCE_MODE=native bin/perf/run-shard.sh --shard cg-1 --psalm "$PWD" --out /tmp/perf-out/head
```

The result then has `mode: native` and no `Ir`, so it must never be compared against cachegrind results. `PERF_FORCE_MODE` is not used by CI.

Build a CI matrix by hand:

```bash
php bin/perf/select.php --event pull_request --head-sha <sha> --base-sha <sha> --cached-shards cg-1
```
