# Development loop against a multisite host

The gate (`composer ci`) boots a minimal Statamic through Testbench. A per-site forms add-on is about behavior that needs a real multisite: site-aware form assignment, per-site overrides and flat-file submissions on disk. This page is the loop for exercising the add-on in a real host, and the setup the `host-only-behavior-needs-a-host-run` rule and the `host-app-tests` skill assume.

## The host

A persistent Statamic 6 host exists for this purpose on the maintainer's Windows machine, in the Herd directory, named for the add-on it serves:

```
statamic-multisite-forms-host
```

Herd serves it as `statamic-multisite-forms-host.test`. It is a stock `statamic/statamic` project with three changes, all recorded in the host's own files so it can be rebuilt from this page:

1. `config/statamic/system.php` sets `'multisite' => true`, and `resources/sites.yaml` defines two sites, `en` (`/`) and `es` (`/es/`).
2. The starter content is in the multisite layout, because the single-site layout breaks the Stache in a multisite host (`Undefined array key 1` in `CollectionEntriesStore`): the home entry is `content/collections/pages/en/home.md`, the tree is `content/trees/collections/en/pages.yaml`, and `content/collections/pages.yaml` lists `sites: [en, es]`.
3. `composer.json` carries a path repository pointing at the add-on checkout, and requires `uams-web/aether-multisite-forms:@dev`.

`php please multisite` is the supported conversion, but it waits for input and hangs when run without a terminal, so the host was converted by hand.

## Rebuilding the host

Run these in the Herd directory. `<path-to-addon-checkout>` is the absolute path of the primary checkout or the slot being tested; it differs per machine, so it is written as a placeholder here.

```bash
composer create-project statamic/statamic statamic-multisite-forms-host
cd statamic-multisite-forms-host
# multisite: set 'multisite' => true in config/statamic/system.php, add resources/sites.yaml,
# then move the starter content into the per-site layout described above
composer config repositories.addon path "<path-to-addon-checkout>"
composer config minimum-stability dev
composer config prefer-stable true
composer require uams-web/aether-multisite-forms:@dev
```

## Picking up a change

A path repository installs by symlink where the filesystem allows it, so an edit in the checkout is live in the host with no further step. Measured on Windows 11, Composer 2.10.2: `vendor/uams-web/aether-multisite-forms` is a symlink to the checkout. Where a symlink cannot be created Composer copies instead, and a change then needs `composer reinstall uams-web/aether-multisite-forms`.

To run the host against a worktree slot rather than the primary checkout, repoint that symlink and set it back afterwards. In PowerShell, from the host root, with the slot's absolute path in place of `<slot-path>`:

```powershell
$link = 'vendor\uams-web\aether-multisite-forms'
[System.IO.Directory]::Delete($link, $false)                  # removes the link, not its target
New-Item -ItemType SymbolicLink -Path $link -Target '<slot-path>'
```

The autoloader maps the add-on's namespace to that path, so a class added on the slot's branch loads with no `composer dump-autoload`. Measured on Windows 11 for #70. A change to `composer.json` still needs the `composer update` below.

A change to `composer.json` (a new provider, a new `extra.statamic` key) needs `composer update uams-web/aether-multisite-forms` from the host root, and the `Discovered Addon:` line at the end of the output confirms discovery.

## Confirming the add-on loaded

From the host root:

```bash
php artisan package:discover                      # lists uams-web/aether-multisite-forms
php artisan tinker --execute='echo \Statamic\Facades\Addon::all()->map->package()->implode(",");'
```

The Control Panel page `/cp/addons` was requested in process as a temporary super user against this host: HTTP 200, and the page data lists one add-on, `Multisite Forms`, with the version of the checked-out branch. A browser run of the same page has not been done.

## Control Panel assets

`npm run build` writes the production build to `resources/dist`, which is committed. In the host, `php artisan vendor:publish --tag=aether-multisite-forms --force` copies `resources/dist/build` to `public/vendor/aether-multisite-forms/build`, and `statamic:install` does the same after a `composer update`. Measured on Windows 11: after publishing, the `/cp/addons` page (requested in process) references both `cp-<hash>.js` and `cp-<hash>.css` under `/vendor/aether-multisite-forms/build/assets/`. `npm ci` needs `vendor/statamic/cms`, because `@statamic/cms` is a `file:` link into it, so run `composer install` first.

## Editions can be added later

The add-on is free with no editions (decision log, #17). Whether it can gain `["free", "pro"]` after a public tag without disturbing installs was measured in this host (#27), by pointing the path repository at a copy of the add-on without editions and then at one with `extra.statamic.editions` set to `["free", "pro"]`, each followed by `composer update` and a read of `Addon::get('uams-web/aether-multisite-forms')`.

| State | `editions()` | `edition()` |
| --- | --- | --- |
| No editions declared | empty | `null` |
| `["free", "pro"]` declared, nothing configured in the host | `["free", "pro"]` | `"free"` |
| `["free", "pro"]` declared, host config names `pro` | `["free", "pro"]` | `"pro"` |
| No editions declared, host config names `pro` | empty | throws `Invalid edition [pro]` |

So declaring editions later, with `free` first, leaves an install that never chose an edition on `free`: adding them is not breaking. The one failure is the host that names an edition for an add-on that declares none, which only a deliberate `config/statamic/editions.php` entry produces. `Addon::edition()` returns the configured edition, else the first declared one, which is why `free` must stay first. Measured on Windows 11 with `statamic/cms` v6.35.1 and PHP 8.4.20. The Marketplace side (whether the product's edition list must match at the first public tag) was not measured, since no product exists. macOS is not yet measured.

## Running the host-bound tests

`tests/Feature/` holds tests that extend the host's `Tests\TestCase`. They are not part of `composer ci`: the package has no `Feature` suite and PHPStan excludes the directory, because `Tests\TestCase` does not exist in this package. From the host root:

```bash
php artisan test vendor/uams-web/aether-multisite-forms/tests/Feature/HostRegistersAddonTest.php
```

`HostRegistersAddonTest` asserts that the host lists the add-on, registers its service provider and is multisite. `SitesConfigSectionTest` requests the form settings screen and its save endpoint as a super user, with form files in a temporary directory, so the host's own forms are never written. `SubmitGateTest` posts to core's form submit route from a page on each site and checks the response and the stored submissions, with submissions in a temporary directory as well. `FormTagsTest` serves an Antlers page carrying `{{ form:create }}` on each site's URL and checks what it renders under each `not_assigned` setting, including that `404` reaches the response as a 404 rather than a view error. `RecordSubmitSiteTest` submits from a page on each site, including with a forged `_site` in the request, and reads `_site` from the stored submission files. `FormAssignedTest` serves an Antlers page and a Blade page on each site that show a heading and an introduction only when `form:assigned` is true, for an assigned form, a global form, and under `404`. `SiteOverridesTest` submits from each site of a form shared by both, whose `es` row overrides the recipients and turns off storing, and reads the email config core hands to each mailable and the stored count. `PhaseOneExitTest` is Phase 1's exit criterion end to end: a form assigned to `en` renders on the `en` page and not the `es` page, answers 404 to a submit from `es`, and stores a submit from `en` with `_site: en`. Run the whole directory with `php artisan test vendor/uams-web/aether-multisite-forms/tests/Feature/`. The `host-only-behavior-needs-a-host-run` rule says when a run is required, and the `host-app-tests` skill covers claiming a slot and reading results.

## Patch leakage

The add-on's dev patches for `pestphp/pest-plugin-mutate` must not touch the host, so they are declared in a root-only `patches.json`, never in `extra.patches` (#178). Composer copies a package's `extra` block into the host's `composer.lock`, and `cweagans/composer-patches` reads `extra.patches` back out of it for every package the host requires, so a host running that plugin alongside `pest-plugin-mutate` would try to apply them. The plugin has no such path for a dependency's `patches.json`, and the file is left out of the dist archive. The Herd host above is unaffected either way, since it installs neither package.

## Platforms

Verified on Windows 11 with PHP 8.4.20 and Laravel 13.35.0. macOS is not yet measured: the host would be created the same way under Herd on a Mac, and whether the path repository symlinks there is expected but unmeasured.
