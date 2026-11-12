# Upstream watch rows

Each `*.json` file here is one row for [`watch-upstream.yml`](../workflows/watch-upstream.yml). The workflow loads them all with [`scripts/load-upstream-watches.mjs`](../../scripts/load-upstream-watches.mjs), which fails the run when a row is malformed. With no rows, the workflow exits at its first step with a notice.

Rows are separate files so that two pull requests that each add a row never edit the same line.

## Adding a row

Add one file named `<tracker>-<owner>-<repo>-<number>.json`, holding one object:

```json
{
    "tracker": 1,
    "repo": "pestphp/pest-plugin-mutate",
    "kind": "pr",
    "number": 38,
    "composer": "pestphp/pest-plugin-mutate"
}
```

- `tracker` is the issue in this repository that records the work; closing it ends the watch.
- `repo`, `kind` (`issue` or `pr`) and `number` name the upstream thread.
- `composer` is optional: name the package when a patch listed in `patches.json` depends on it, and the watch also reports a Packagist release past the installed version.
- `released: true` is optional and only valid on a `pr` row: the watch also reports when a release on the pull request's base-branch line contains the merge commit.

A row earns its place only if a terminal signal would make someone act. Each row also needs a note below, saying why it exists and what its signal changes.

## Retiring a row

Delete the row file in the same pull request that removes the patch it served, per the `upstream-patch-lifecycle` skill. Closing the tracking issue also ends the watch.

## Rows

Each row's tracker is the removal ticket for one local `pestphp/pest-plugin-mutate` patch. The originating report-upstream chain lives in `UAMS-Web/uams-statamic`, per the mirrored-patch rule in the `upstream-patch-lifecycle` skill, so this repository carries only the removal tickets and these rows. `pestphp/pest-plugin-mutate` has issues disabled, so every thread watched here is a pull request. The patches with no row (launch, Windows mutant child, run filter, mutated-source write) have no upstream thread to watch yet; their removal tickets are blocked by the originating repository's open tickets instead.

- **`4-pestphp-pest-plugin-mutate-46.json`** tracks `pestphp/pest-plugin-mutate#46`, which fixes the bare-marker defects the `pest-plugin-mutate-annotation-parsing` patch fixes locally. It carries `released`, so the watch also comments once when a stable tag contains the merge. That comment is the signal to remove the patch (ticket 4).
- **`7-pestphp-pest-plugin-mutate-43.json`** tracks `pestphp/pest-plugin-mutate#43`, an open pull request that rewrites the same `--filter` construction as the `pest-plugin-mutate-windows-filter-length` patch. It carries a `composer` key so the watch also reports a Packagist release past the installed version: several patches edit `MutationTest::start()`, and a release touching it can stop them applying, which fails `composer install` under `composer-exit-on-patch-failure`.
- **`9-pestphp-pest-plugin-mutate-45.json`** tracks `pestphp/pest-plugin-mutate#45`, which stops a mutant that never ran a test counting as killed by dropping the parallel runner's options from the child, the defect the `pest-plugin-mutate-drop-parallel-runner-options` patch fixes locally.
