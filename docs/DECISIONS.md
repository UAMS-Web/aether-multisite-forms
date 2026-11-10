# Decision log

The canonical record of **why** this repository is the way it is: the reasoning and the **rejected alternatives** behind decisions that have closed. It is the guard against silently relitigating a settled decision.

**Consult it before acting.** Before proposing a change, reopening a question, or picking an approach, check here first: the path under consideration may already be a decided-and-recorded one, or an explicitly rejected one. If it is, follow the record (or make the case to revisit it); do not quietly re-decide.

**When a seam closes, record the verdict in both places.** Capture the *what*, *why* and rejected alternatives **here** (the cross-ticket index) **and** in the GitHub issue's `## Decision` section (the per-ticket record), keeping the two in sync. This file does not replace the issue tracker, which stays the system of record for a single ticket's status.

See the [`design-decision-forks`](../.claude/rules/design-decision-forks.md) rule for how a fork is surfaced and driven to resolution before its verdict lands here.

## How to add an entry

Oldest first, one entry per closed decision, so **a new entry is appended to the end of the file**. Keep it terse; the issue carries the depth. Each entry is preceded by exactly one blank line, which `scripts/check-decision-log-order.mjs` enforces along with the date order. The file is marked `merge=union` in `.gitattributes`, so two branches that each append an entry merge without a conflict.

```markdown
## YYYY-MM-DD: <short decision title> (<#issue or area>)

**Decision.** <what was decided, in a sentence or two>

**Why.** <the reasoning that settled it>

**Rejected.** <the alternatives considered, and why each lost>

**Companion.** <link to the GitHub issue holding the full reasoning>
```

---

## 2026-10-11: PHP floor is ^8.4 (repository setup)

**Decision.** `require.php` is `^8.4`, and Rector, PHPStan and CI target 8.4.

**Why.** The Pest 5 line requires PHP 8.4, and the mutation-testing instrument depends on it. A lower floor would have meant a second, older test toolchain.

**Rejected.** Keeping `^8.3` with Pest 4: it forfeits the Pest 5 mutation tooling the shared rules assume.

**Companion.** Commit history on `set-up-dev-tooling`.

## 2026-10-11: CI runs as chained jobs, not one job (repository setup)

**Decision.** `.github/workflows/ci.yml` runs one job per `composer ci` step, each `needs:` the previous one, in cost order.

**Why.** A failure names its step, and a cheap failure skips every more expensive job after it. The cost is that a green run takes the sum of the jobs, which is accepted for a library this small.

**Rejected.** A single job running `composer ci`: one opaque failure and no early exit between steps. A parallel matrix: faster but spends the expensive jobs on a branch that already failed a cheap one.

**Companion.** Commit history on `set-up-dev-tooling`.

**Superseded** by "CI jobs run in parallel" below.

## 2026-10-11: Local patches to pest-plugin-mutate are temporary (repository setup)

**Decision.** Seven patches under `patches/` are applied to `pestphp/pest-plugin-mutate` through `cweagans/composer-patches`, so mutation runs are trustworthy on Windows.

**Why.** Unpatched, a mutant child that cannot launch is scored as killed, so a run can report 100% having executed nothing. The patches are carried only until upstream fixes land, per `upstream-patch-lifecycle`.

**Rejected.** Running mutation testing on macOS only: both platforms are supported. Skipping mutation testing: it removes the check for false-green tests.

**Companion.** The `upstream-patch-lifecycle` and `adversarial-review` rules.

## 2026-10-12: MIT license and a statamic/cms compatibility matrix (#13)

**Decision.** The add-on is MIT licensed (`LICENSE`, `"license": "MIT"`), and CI runs Larastan and Pest on a lowest-`statamic/cms` leg and a PHP 8.5 leg after the main chain.

**Why.** A public Packagist and Marketplace package needs a declared license. The package promises `statamic/cms` at its `composer.json` constraint, and one tested combination cannot back that promise. The lowest leg derives its bound from the constraint with `--prefer-lowest`, so it follows the floor as code starts to need newer APIs, and its check name carries no version number so the required-checks list stays stable.

**Rejected.** A hard-coded lowest version in CI: it would drift from `composer.json`. A full `--prefer-lowest` over every dependency: it would downgrade the dev tooling and the patched mutate plugin, testing the tools instead of the add-on. A proprietary license: it can be revisited with editions.

**Companion.** #13.

## 2026-10-12: CI jobs run in parallel (#20)

**Decision.** No job in `ci.yml` declares `needs:`; every job, the `Compatibility` legs included, starts at once. Job names are unchanged, so the required-checks ruleset is unaffected. `composer ci` keeps its cost order locally.

**Why.** The chain exists in `UAMS-Web/uams-statamic` to protect a private repository's Actions minutes budget, and the entry above never recorded that reason. This repository is public, where standard runners are free, so the early exit saves nothing and the chain costs wall-clock time and shows failures one job at a time.

**Rejected.** Keeping the chain: it spends time to save a budget that does not exist here. Chaining only the cheap gates: a smaller version of the same cost for a saving of free minutes. Reverting to a chain is cheap if the repository ever goes private.

**Trade.** More concurrent runner use per push, and a pull request that fails an early job still runs the expensive ones.

**Companion.** #20.

## 2026-10-12: A check compares the required-checks ruleset with the job names (#11)

**Decision.** `check-required-checks.yml` runs `scripts/check-required-checks.mjs` on every pull request, every push to `main` and weekly. A name the ruleset requires and no job reports is always an error. A job the ruleset does not require is a warning on a pull request and an error on `main` and on the weekly run. The required list comes from `GET repos/{o}/{r}/rules/branches/main`.

**Why.** Nothing failed at the moment of a rename: the first symptom was a pull request waiting on a check no job reported. The effective-rules endpoint is readable without a credential on a public repository (measured: HTTP 200 and 15 contexts, unauthenticated), so no token decision was needed. The two directions are not equally harmful, and a pull request that adds a job cannot also pass a check demanding it, because requiring the name first blocks every other pull request. That is why only the blocking direction fails a pull request.

**Rejected.** Failing both directions on a pull request: adding or renaming a job could never merge. An allow-list of optional jobs: nothing here needs one yet, and an unrequired job already surfaces as a warning. Reading the organization ruleset itself: it needs the `admin:org` scope, which no workflow token has. A regex over the YAML in place of the `yaml` package: a matrix name such as `Compatibility (${{ matrix.name }})` needs real parsing.

**Companion.** #11. The shared version is `UAMS-Web/uams-claude-skills#170`, blocked by #169.

## 2026-10-12: A persistent multisite host serves the development loop (#16)

**Decision.** A Statamic 6 host named `statamic-multisite-forms-host` lives in the maintainer's Herd directory and installs the add-on from a local checkout through a path repository. `tests/Feature/` holds host-bound tests that extend the host's `Tests\TestCase`; PHPStan excludes it and the package has no `Feature` suite. The procedure is `docs/DEVELOPMENT.md`.

**Why.** Per-site forms behavior needs a real multisite, and Testbench boots a minimal one. A persistent, clearly named host means the host-app run the `host-only-behavior-needs-a-host-run` rule requires has somewhere to happen, and the existing `uams-statamic` host stays unmodified.

**Rejected.** Pointing an existing project's `composer.json` at the add-on: it edits a real site and leaves a path repository behind. A throwaway host per run: a Composer create-project and a multisite conversion every time. Running `php please multisite` to convert: it hangs without a terminal.

**Companion.** #16.

## 2026-10-12: The Control Panel assets build with Vite and the output is committed (#15)

**Decision.** `vite.config.js` builds `resources/js/cp.js` and `resources/css/cp.css` into `resources/dist`, and the provider registers the build through `$vite`. The built files are committed and stay in release archives; the tooling (`package.json`, the lockfile, `vite.config.js`) is export-ignored. The `Built assets (resources/dist matches source)` job rebuilds and fails when anything under `resources/dist` differs. `vite` is `^6.4.4` and `laravel-vite-plugin` is `^1.3.0`, the lines Statamic's guide names; `laravel-vite-plugin` 3 needs Vite 8.

**Why.** A Composer install does not run npm, so a release that does not carry the build ships nothing the Control Panel can load. A stale committed build is the failure that does not announce itself, so a job compares it with a fresh one.

**`.npmrc` policy.** No change was needed. With `ignore-scripts`, `min-release-age=3` and `engine-strict` in force, `npm install` resolved the Vite toolchain and `npm run build` produced the manifest on Windows 11 (npm 11.19.0). `@statamic/cms` is a `file:` link, so it is not subject to the release-age floor, and every job that runs `npm ci` now runs the Composer setup first.

**Rejected.** Building in release tooling only and leaving `resources/dist` out of git: a path-repository or VCS install would then have no assets. Ignoring the dev build: Statamic's guide says not to commit it, and this commits only the production build.

**Companion.** #15.

## 2026-10-12: The add-on is free with no editions (#17)

**Decision.** The add-on is free, declares no `extra.statamic.editions`, and stays MIT (#13). Nothing is submitted to Packagist or the Statamic Marketplace by this decision; submitting remains a separate operator decision.

**Why.** `src/` has no logic yet, so there is nothing to put behind a paid edition. Declaring `["free", "pro"]` now would commit edition handles to the Marketplace, where they must match and the first one is the default, for a Pro edition with nothing in it. Going free first is expected to leave a later move to editions non-breaking, since `free` listed first stays the default for installs that never chose one; that is an inference and is checked in #27.

**Rejected.** Declaring `free` and `pro` now: handles committed before any Pro feature exists. Paid from the start: needs bank details, a license rework away from MIT and an institutional sign-off. A free tier plus paid Pro: the costs of paid, and needs features to put behind Pro.

**Companion.** #17. Follow-ups: #25 (README), #26 (submission checklist), #27 (editions can be added later).

## 2026-10-13: Submissions stay in core's layout and record their site in a `_site` key (#43)

**Decision.** A submission is stored where core stores it, `<submissions dir>/<form handle>/<id>.yaml`, and the add-on records the originating site in a reserved `_site` data key. Submissions saved before the add-on carry no key and are unattributed. No per-site folder is introduced.

**Why.** It replaces nothing in core. Core's `FormSubmissionsStore` reads only files directly inside a form's folder and takes the handle from that folder name, so a per-site folder would need its own store, handle parsing and `Submission::path()`, all Stache internals that move between versions. The `_site` key is measured to work: it filters correctly, including `whereNull` for unattributed submissions, and is indexed on first use (spike 2, #35), and a site-resolved form keeps its handle, so its submissions land in the form's own folder (spike 5, #38). Removing the add-on leaves every submission visible to core. Per-site Control Panel isolation comes from the query scope, not the directory.

**Rejected.** A folder per site (`<handle>/<site>/<id>.yaml`): it replaces core's store, needs a migration command for existing data, and hides submissions from core if the add-on is removed. Its benefit, per-site directories visible in Git, stays reachable later by a one-way, file-by-file move, which is why choosing the flat layout first is the reversible order.

**Companion.** #43. Spec: `docs/SPEC.md`.

## 2026-10-13: Submissions are scoped by site by decorating the query builder contract (#49)

**Decision.** The add-on scopes Control Panel submission reads by site with `app()->extend()` on the `SubmissionQueryBuilder` contract: whatever builder the container produces gets a `_site` where-clause (the user's accessible sites, plus unattributed submissions as #40 decides). The scope applies only to Control Panel requests by non-super users. No core class or controller is replaced.

**Why.** Every Control Panel read (listing, detail view, delete, export and the forms index count) resolves that contract. Decorating it was measured to scope each path while core's builder stays bound (spike 4, #37, and the follow-up on #49), and it uses only the public query API, so it should still apply on top of a different submissions driver.

**Rejected.** Binding a subclass of core's Stache builder: replaces the bound class and would replace another driver's builder. Overriding the Control Panel controllers: a larger surface that misses any read it does not override. A filter parameter only: a convenience, not access control.

**Risk accepted.** The same builder serves the API and the `{{ form:submissions }}` tag, so the request-context condition is load-bearing: tests must show it scopes Control Panel requests and leaves the others alone.

**Companion.** #49. Spec: `docs/SPEC.md`.

## 2026-10-13: Unattributed submissions are visible only to users who cover every assigned site (#40)

**Decision.** A submission with no `_site` is shown to a non-super user only when that user can access every site the form is assigned to; for a global form (no `sites` list) that means every site. The `multisite-forms:attribute` command assigns a site to unattributed submissions for installs that want them visible to single-site editors.

**Why.** Unattributed is an ongoing category, not only pre-install history: any submission saved without the submit route has no `_site`. Showing those to anyone who can view the form would let a single-site editor read another site's data whenever the key is missing, which is the leak the add-on exists to prevent and the wrong default for form submissions that may hold personal information. Users trusted with every site the form serves keep full visibility.

**Rejected.** Visible to anyone who can view the form: no change on install, but it leaks across sites indefinitely. Super users only until attributed: safe, but hides ordinary history from everyone with broad access.

**Cost accepted.** Single-site editors lose sight of pre-install submissions until they are attributed. The release that ships the scope says so in its notes.

**Companion.** #40. Spec: `docs/SPEC.md`. Related: #49 (the scope mechanism).

## 2026-10-13: `configure forms` does not bypass the site scope (#54)

**Decision.** A non-super user holding `configure forms` is scoped to their accessible sites like any other non-super user, for both forms and submissions. Only super users see every site. The form-level denial is a global `Gate::before()` callback, because core's form policies return true from their own `before()` for `configure forms`, and Laravel lets a global `before` callback decide ahead of any policy while an `after` callback can change only a null result.

**Why.** In a multisite install the people who build forms for one site are the ones given `configure forms`. If that permission bypassed the scope, per-site isolation would protect only plain viewers, and every site's form builders would read every site's submissions.

**Rejected.** Letting `configure forms` bypass the scope, as core's policies do today: no surprise for an existing form admin, but it leaves isolation to the least-privileged users only.

**Cost and residual.** This narrows what core grants `configure forms`; the release that ships the scope says so. A scoped `configure forms` user can still edit another site's `site_overrides` and redirect its future email; who may edit a site's settings is #42.

**Companion.** #54. Spec: `docs/SPEC.md`. Related: #40, #49.

## 2026-10-13: Only users who cover every assigned site may edit a form (#42)

**Decision.** A non-super user may edit a form's settings (its sites, base values and `site_overrides`) only when they can access every site the form is assigned to; for a global form, every site. The denial is the global `Gate::before()` already chosen in #54, applied to the `edit` ability. A form created by a user who does not cover every site is assigned to the sites they can access.

**Why.** Base `title`, `email` and `store` reach every assigned site without an override row, so editing them changes other sites, and a scoped admin could otherwise reroute another site's email. Reusing the `Gate::before()` adds no new mechanism and avoids a save-time check, which would meet a trap in core: `FormsController::update()` reports "Saved" without checking whether `save()` succeeded. Single-site forms stay fully editable by their site's admins.

**Rejected.** Any form admin edits everything (core's behavior): allows cross-site email rerouting. Per-site editing (own sites' rows and membership; base values only when covering all): the right refinement if shared-form friction proves real, but it needs per-field diffing at save time and a rejection the Control Panel can show, neither verified.

**Cost and residual.** On a shared form a per-site admin cannot edit even their own site's row and needs a cross-site admin. A scoped admin can still view a shared form's settings; whether to deny viewing too is not decided. How the creation default is set is unmeasured.

**Companion.** #42. Spec: `docs/SPEC.md`. Related: #54.

## 2026-10-13: The first release scopes by site access only; a Site filter follows (#41)

**Decision.** The first release offers no site selector: editors see forms and submissions for the sites they can access, and nothing narrows that further. The next step is a Site filter on the Control Panel submissions listing (#58).

**Why.** Isolation is complete by access, so a selector is a convenience for users with several sites, not part of the protection. The cheapest selector is a core filter class declared `visibleTo('form-submissions')`, which core's listing and export already apply through `queryFilters()`; it needs no Vue change. That was read in `statamic/cms` v6.35.1 and not exercised, so it follows as its own ticket rather than gating the first release.

**Rejected.** Shipping the Site filter in the first release: reasonable, but unexercised. A switcher that follows the Control Panel's selected site (`Site::selected()`): it needs changes to core's Vue pages, the upgrade-fragile surface the spec avoids.

**Cost.** Until #58 ships, a user with access to several sites sees their submissions mixed in one listing.

**Companion.** #41. Follow-up: #58. Spec: `docs/SPEC.md`.

## 2026-10-13: A form tag renders nothing on a site the form is not assigned to (#39)

**Decision.** When a form is not assigned to the current site, the `{{ form:create }}` family renders nothing and logs a warning naming the form and the site. The `not_assigned` config value can be set to `404` to abort the request instead; `empty` is the default. A submit to an unassigned site is always a 404.

**Why.** In a multisite install the common way to reach this case is a shared template, such as a footer or layout partial, rendered on every site with a form meant for some of them. On the other sites that is intended, so a 404 or an error would break working pages. The warning leaves a trace for a genuine authoring slip.

**Rejected.** A 404 as the default: the whole page fails on every non-assigned site that shares the template. Throwing, as core does for a missing form (`Tags::getForm()`): a 500 is never what an editor wants.

**Residual.** The warning's level and frequency are settled at build time. A template check of assignment, to hide surrounding content, is #60.

**Companion.** #39. Follow-up: #60. Spec: `docs/SPEC.md`.

## 2026-10-13: Viewing a shared form's settings needs no rule of its own (#57)

**Decision.** No separate rule. Core authorizes the form settings screen on the `edit` ability (`FormsController::edit()` calls `authorize('edit', $form)`), and the #42 denial of `edit` to users who do not cover every assigned site therefore also hides the settings from them. The submissions page (`show()`, authorized on `view`) carries no email configuration.

**Correction.** The #42 entry's residual says a scoped admin can still view a shared form's settings. That was wrong: denying `edit` already denies the settings screen.

**Related finding.** The front-end `{{ form:create }}` tag exposes added config fields to templates as `form_config`, which would include every site's `site_overrides`; keeping them out is #62.

**Companion.** #57. Related: #42, #62.


## 2026-10-13: The form tags are gated by a subclass of core's tags, not by a Blink seed alone (#73)

**Decision.** The add-on registers `FormTags`, a subclass of core's `Statamic\Forms\Tags`, under core's `form` handle. Each tag that reads a form resolves it for the page's site; an unassigned form renders nothing or aborts with 404, per `not_assigned`. Under `empty`, `create` returns an empty string, `errors` and `success` return `false`, and `submission` returns null, as core's does before a submit. `submissions` returns an empty list, which a tag pair renders as it renders a form with no submissions, since that is the value a template already handles. `form:fields` is left to core, because it reads only the fields `form:create` hands it. Under `404` the tag throws Statamic's `NotFoundHttpException`, as core's `{{ 404 }}` tag does, so the response is the site's 404 page in its layout; once the request is aborting, a further gated tag (in a layout the error page shares) renders nothing instead of throwing again, which would make the response a 500. The warning for `empty` is logged at `warning` level, once per form and site per request.

**Why.** The spec's mechanism, seeding core's `form-{handle}` Blink key with the site's view (spike 3), cannot meet the ticket alone. Core's `formHandle()` puts a form passed in from context straight into that key, replacing the seed, so a form handed to the tag as an object would render on every site. And a missing form makes core throw (`getForm()`), so nothing seeded can make the tag render empty. Resolving inside the tag closes both and needs no early-request hook. Once per request at `warning` keeps a shared layout to one line per page view while still surfacing an authoring slip.

**Rejected.** Seeding Blink from a middleware: misses the context case and cannot render empty. Seeding every form on every front-end request: the same gaps, plus a lookup of every form per request. Logging every render: a layout with the form in several places floods the log. Logging at `debug`: hides the authoring slip the warning exists to show.

**Residual.** Another add-on, or an app-level `app/Tags` class, that also registers the `form` handle conflicts with this one: core registers app tags before add-ons boot, so this add-on silently replaces an app's, and between two add-ons whichever registers last wins. An invalid `not_assigned` value is reported only when a page meets an unassigned form. When the tag is fetched with no parser (`Statamic::tag('form:create')->fetch()`), core returns the form's data as an array, and a gated tag returns an empty string instead, which a template indexing into the result does not expect. Decided in #89: the gated tag returns an empty array on that path (#100).

**Companion.** #73. Earlier decision: #39. Spec: `docs/SPEC.md`.


## 2026-10-13: The submit site is recorded on FormSubmitted, with a SubmissionCreating backstop on the submit route (#74)

**Decision.** `RecordSubmitSite` sets `_site` on the submission from `FormSubmitted`, which core dispatches from its submit route after building the data and before saving it or queuing its emails, using the shared submit-site helper. It also listens to `SubmissionCreating`: on the submit route, a new submission of the submitted form that reaches its first save without `_site` gets it then. Both are registered explicitly in the service provider.

**Why.** Core dispatches `FormSubmitted` halting on the first listener that returns anything other than null, and Laravel runs listeners in registration order, so an app's listener registered before the add-on's that returns `true` would skip the recording and the submission would save unattributed. The backstop closes that for stored submissions, and is limited to the submit route and the submitted form, so a submission saved any other way (including another form's, saved by a listener during the submit request) stays unattributed, as the spec defines.

**Rejected.** Recording only on `SubmissionCreating`: it fires only when submissions are stored, so the queued email would never carry the key. Recording only on `FormSubmitted`: the halting gap above. Reordering the dispatcher's listeners so the add-on's runs first: it reaches into the dispatcher's internals. Discovering the listener from a `Listeners` folder: explicit registration keeps the two events in one visible place.

**Residual.** `SubmissionCreating` halts the same way, so an app that halts both events with listeners registered before the add-on's still saves the submission unattributed. When `FormSubmitted` is halted and submissions are not stored, the email job receives the submission without `_site`; nothing reads `_site` from an email. The site comes from the referrer, which the visitor controls, so a visitor can attribute a submission to any site the form is assigned to, as visiting that site's page would; core's email site follows the same header.

**Companion.** #74. Spec: `docs/SPEC.md` (Recording the site).


## 2026-10-13: A gated `form:create` fetched with no parser returns an empty array (#89)

**Decision.** When `form:create` is gated for an unassigned form and the tag is fetched with no parser (`Statamic::tag('form:create')->fetch()`), it returns an empty array. With a parser, in Antlers and Blade tag pairs, it keeps returning an empty string.

**Why.** Core returns an array on that path, so an empty array keeps the type a template expects, and it is the value core itself returns there when it has nothing: `user:passkeys` returns `[]` when it cannot parse contents. It is falsy, so the guard a template author naturally writes, `@if ($form)`, skips it, and `foreach` and `count()` over the result work. Measured against the ways a template reads the result, with warnings raised as exceptions as Laravel raises them: an empty string errors on `foreach ($form['fields'])` and on `foreach ($form)`; an empty array errors only on an unguarded index.

**Rejected.** Keeping the empty string: it costs nothing to build and leaves the return type depending on assignment. An array with the keys a template reads (`attrs`, `params`, `fields`, `sections`), all empty: it is truthy, so `@if ($form)` passes and the template renders a form with no `action`, which then errors. `null`: falsy like an empty array, but not the type core returns.

**Residual.** A template that reads `$form['attrs']['action']` without a guard errors on an unassigned form whatever the tag returns: only a real form satisfies it, and rendering one is what the gate prevents. The README documents the guard. Under the `404` setting the tag aborts on every path, so this applies to `empty` only.

**Companion.** #89; build in #100. Earlier decisions: #39, #73.


## 2026-10-13: The Sites field does not check site access, as core's collection sites do not (#85)

**Decision.** The Sites field on a form's settings screen accepts any configured site, whether or not the signed-in editor may view it. Its dropdown still lists only the sites the editor may view, as core's `sites` fieldtype filters it.

**Why.** Only a super user or a user with `configure forms` reaches the screen (`FormPolicy::before`), a structural permission that already lets them change the form's blueprint and settings or delete it. Core treats the matching collection setting the same way: `CollectionsController::update` saves a collection's `sites` without checking site access, and core checks site access for content within a site (entries, terms, globals, navigation), not for structure configuration. Refusing a site would make forms stricter than collections for no gain over what the permission already grants.

**Rejected.** Refusing any handle the editor cannot view, and merging an existing assignment to such a site back in on save: stricter than core, and it needs a rule that reads the current user's permissions. Refusing only additions: the same cost, with the same small gain.

**Residual.** Saving the screen keeps an existing assignment to a site the editor cannot view (measured in #101): the field lists that site by its handle rather than its name, and posts it back with the rest. An editor can still remove it from the field on purpose, as with any selected site.

**Companion.** #85; measurement in #101. Earlier decision: #70. Superseded for forms by #118, once #77 removed the `configure forms` bypass.


## 2026-10-13: An invalid `not_assigned` value is logged at boot, not thrown (#90)

**Decision.** The add-on checks `not_assigned` each time it boots and logs an invalid value at `error` level, with the setting's key and the value. A page that meets an unassigned form still throws, as before.

**Why.** Before this, an invalid value, most likely `null` from an unset `env()` variable, surfaced only as a 500 on a page that met an unassigned form, possibly long after deployment and on some sites only. Logging at boot reports it on the first request or `artisan` command after the change, and leaves every page that renders no unassigned form working.

**Rejected.** Throwing at boot: it stops every `artisan` command, `package:discover` included, so a deploy fails at `composer install`, and it takes down every page for a value that affects only some. Throwing outside the console only: the same site-wide outage on the web. A doctor-style command: it reports only when someone runs it, which is the gap the ticket describes. Falling back to `empty` for an invalid value: it would hide a configuration error behind a guess at what was meant.

**Residual.** The error is logged on every boot until the value is fixed, which is one line per request. A log nobody reads still reports nothing, so the page that meets an unassigned form keeps failing loudly.

**Companion.** #90. Earlier decision: #73.


## 2026-10-13: Templates check a form's site with a `form:assigned` tag (#60)

**Decision.** `{{ form:assigned in="contact" }}` answers whether a form renders on the page's site: true for a form assigned to it or one with no sites, false for a form assigned elsewhere or a missing one. As a single tag it returns that boolean, for an Antlers `if` or `Statamic::tag('form:assigned')->fetch()` in Blade; as a tag pair it renders its contents only when the form is assigned. It never aborts or logs, whatever `not_assigned` says.

**Why.** It follows core's own `form:errors`, which is a boolean as a single tag and a conditional block as a pair, so it reads like the tags it sits beside and works in Antlers, Blade components and fetched form. It resolves the form exactly as the gated tags do, through the same resolver and `Site::current()`, so it cannot disagree with what `form:create` renders.

**Rejected.** A variable injected into every view: it needs a form handle to mean anything, so it would be one variable per form, computed on every page whether or not a template reads it. A single tag only: an Antlers condition covers it, but a pair is the shorter form for wrapping markup and costs one branch. Throwing for a missing form, as `form:create` does: the check is for deciding what to show, and a template asking about a form that does not exist wants the answer "no".

**Residual.** The name takes precedence over a form whose handle is `assigned` in the `{{ form:{handle} }}` shorthand, as core's tag names already do for `create`, `errors` and the rest; such a form renders with `{{ form:create in="assigned" }}`.

**Companion.** #60. Earlier decisions: #39, #73.


## 2026-10-13: A resolved view stays a core `Form`; driver-specific code reads `base()` (#107)

**Decision.** A site-resolved view (`SiteForm`) stays a subclass of core's `Statamic\Forms\Form` whatever class the form contract is bound to. Where a driver binds its own class, as `statamic/eloquent-driver` does with `Statamic\Eloquent\Forms\Form`, that class's own methods are reached through `SiteForm::base()`, which returns the stored form. The README documents it (#109).

**Why.** Measured in #83, a view carries the same values as its base under the Eloquent forms driver; the only difference is type identity, so `model()` throws on a view and `instanceof` the driver's class is false. Neither the add-on nor the driver calls either on a view: the driver uses `model()` only in its own `save()` and `delete()`, which a view refuses, and its submissions read only the form's handle. The case that can meet it is outside code holding `$submission->form()`, and `base()` already serves it for any binding, not only this driver. Adding forwarding later is not breaking, so nothing is closed off.

**Rejected.** Forwarding the methods a view does not declare to its base through `__call`: `model()` would work but `instanceof` still would not, and a blanket forward lets a setter such as `model($model)` write to the base form, which the read-only view exists to prevent, so it would need an allow-list kept in step with each driver, for no caller known to need it. A view class per driver, built from a shared trait and interface: it gives `instanceof` for one driver only, misses any other binding, and changes `FormResolver::resolve()`'s return type, which breaks code type-hinting `SiteForm`.

**Residual.** Any app that binds its own form class has the same gap and the same answer. Control Panel form screens and the Eloquent submissions driver were not measured with the forms driver on (spike 7).

**Companion.** #107; measurement in #83; documentation in #109.

## 2026-10-13: Deleting a form and editing its fields need every site, as editing does (#77)

**Decision.** The site rules on form access are one global `Gate::before()` callback (`UAMSWeb\MultisiteForms\Access\FormGate`). For a form, or a submission through its form, it denies a non-super user who can access none of the form's sites. For the abilities that change a form (`edit`, and also `delete` and `configure form fields`) it requires every site the form is assigned to, or every configured site for a global form. Site access is asked of core's site policy (`can('view', $site)`), and a handle naming no configured site is never accessible, so a form still listing a removed site is open to super users only. The callback never grants: everything it does not deny is left to core's policies.

**Why.** #42 decided that editing needs every site because a change to the base values reaches each one. Deleting a form removes it from every site at once, and its fields are its blueprint, shared by every site, so both reach further than editing and earn the same rule. Asking core's site policy keeps one source of truth for site access, including core's answer on a single-site install, where every user can access the one site and the rules change nothing. Failing closed on an unknown handle keeps a stale assignment from opening a form to everyone.

**Rejected.** Applying the every-site rule to `edit` only, as #77's summary words it: a user who may not edit a shared form could still delete it for every site. Treating a removed site's handle as absent from the list: the form would turn global for every user, the opposite of what its assignment says.

**Residual.** Core's form blueprint editor authorizes `configure form fields` without naming the form, so the callback cannot reach it; the route stays open to such a user and is tracked in #116. Core's Duplicate action authorizes `create` on the form class without naming the form it copies, tracked in #117. #79 then assigns a new form to its creator's sites, so a form created by a user who does not cover every site stays within that user's edit. Whether saving `sites` should refuse a site the editor cannot access, now that `configure forms` is no bypass, is the question in #118.

**Companion.** #77. Earlier decisions: #42, #54, #57.


## 2026-10-13: A new form takes its creator's sites on `FormCreating`, whatever created it (#79)

**Decision.** A listener on core's `FormCreating` event sets `sites` on a new form to the sites the signed-in user can access, when that user is not a super user and cannot access every configured site. It leaves the form as core made it when the form already lists sites, when no user is signed in, when the user covers every site (always the case on a single-site install), and when the user can access no site.

**Why.** #42 decided that editing needs every site and that a scoped creator's form is assigned to their sites, so they can still edit it; #77 put the edit rule in place. `FormCreating` fires once, before a new form is first written, for every way a form is made (the create screen, the Duplicate action, code), so no controller is replaced and an existing form is never reassigned by a later save. Keeping sites a form already lists respects an assignment made in code and a duplicate's copy of its source's.

**Rejected.** Hooking the create screen's request (a middleware on `forms.store`): it misses the Duplicate action and forms made in code by a signed-in user. Assigning a creator who can access no site to an empty list: an empty list already means every site, so it would change nothing while reading as a decision. Refusing the creation for such a user: a listener that cancels the save leaves core's create screen redirecting to a form that does not exist.

**Residual.** Not measured with `statamic/eloquent-driver`'s forms; whether their `save()` dispatches `FormCreating` as core's does was not checked, and #83 measured their views, not creation.

**Companion.** #79. Earlier decisions: #42, #77.

## 2026-10-13: Control Panel submission reads keep unattributed ones by id when the user covers only some forms (#78)

**Decision.** `UAMSWeb\MultisiteForms\Access\SubmissionScope` decorates the bound `SubmissionQueryBuilder` with `app()->extend()`, as #49 decided, and adds one nested condition on a Control Panel request by a signed-in non-super user. When the user covers every form (can access every site of each), it keeps `_site` in the user's sites or `_site` missing (null). Otherwise it reads, with a separate unscoped query, the ids of the submissions with no `_site` of the forms the user covers, drops any id a submission of another form also carries, and keeps `_site` in the user's sites or `id` in that list. A `_site` of an empty string counts as neither, so it is hidden from every non-super user. Between core's `FormDeleting` and `FormDeleted` events the request reads unscoped, marked on the request rather than in a static, so deleting a form deletes all of its submissions. A bound builder that extends neither of core's query builders is refused with a `LogicException` rather than read unscoped, and a form that is not core's form class counts as one the user does not cover.

**Why.** #40 makes an unattributed submission's visibility depend on its form, so the condition has to say "this form and no site". Core's Stache builder takes `form` out of the conditions and applies it to the whole query, and ignores it inside a nested group, so the per-form rule cannot be written as a group; reading the ids first is the form of it that runs through the public query API. Plucking `id` from the Stache builder returns its `form::id` keys, not the submission ids, so the ids are read from the submissions. An id is a submission's file name and is not unique across forms, while the id rule names no form, so an id shared with a form the user does not cover is dropped rather than shown. `Form::delete()` deletes its submissions through the same builder, so without the delete exception a user allowed to delete a form left behind the submissions they could not see, and a new form with the handle would show them; core dispatches `FormDeleting` without halting, so once it fires the delete runs. Failing loudly on an unknown builder, and closed on an unknown form, keeps a driver nobody tested from showing another site's submissions.

**Rejected.** One nested group per covered form (`form` and an empty `_site`): the Stache builder ignores the `form` inside it, so every unattributed submission would show. Caching the id list per request: a delete in the same request would leave it stale, and the read is one query per scoped builder (#123 later adopted it, dropping the list on the events of any write that could change it, once the forms index was measured). Hiding every unattributed submission from a user who does not cover every form: it breaks #40 for the forms the user does cover.

**Residual.** Measured against core's Stache builder, in the Testbench app and in the host (`tests/Feature/SubmissionSiteScopeTest.php`). Not measured with `statamic/eloquent-driver`'s submissions, which this repository does not install: core's `Statamic\Query\EloquentQueryBuilder::whereNested`, which a driver's builder inherits unless it overrides it, builds the inner query through `app(static::class)`, which resolves the concrete class rather than the contract and so should not carry the scope a second time; that was read from the code, not run, and #53's measurements covered views, not this scope. Every scoped builder for a user covering only some forms reads those forms' unattributed submissions, and the forms index resolves one builder per form, so that page's cost grew with forms times unattributed submissions; #123 measured it and reads them once per request. A form deleted with `deleteQuietly()` fires neither event and is still scoped; core's Control Panel does not do that. On a Control Panel request, saving an existing submission the user cannot see makes core treat it as new (`Submission::save()` looks it up through the scoped builder), so `SubmissionCreating` fires; core's Control Panel never saves a submission. `Statamic::isCpRoute()` compares the path with the raw `statamic.cp.route` value; a value with a leading slash serves the Control Panel while it is false, and #122 replaced it.

**Companion.** #78. Earlier decisions: #40, #49, #54.

## 2026-10-13: The form fields editor is guarded by a route middleware in Statamic's Control Panel group (#116)

**Decision.** `UAMSWeb\MultisiteForms\Http\Middleware\AuthorizeFormBlueprint` is pushed onto the router's `statamic.cp.authenticated` middleware group. On the `statamic.cp.blueprints.forms.edit` and `statamic.cp.blueprints.forms.update` routes only, it authorizes `configure form fields` with the form, so the add-on's gate applies the rule for changing a form (every site it is assigned to, or every site for a form with no sites). Every other route, and a handle naming no form, passes through to core.

**Why.** Core's `FormBlueprintController` authorizes the ability in its constructor without the form, so the gate cannot see which form is asked for; a second authorization that names the form closes the route without replacing the controller. The group is registered on the router by Statamic rather than owned by the HTTP kernel, so pushing onto it survives the kernel's later group copies, and it travels with the routes whether or not they are cached.

**Rejected.** Replacing or extending core's controller: it ties the add-on to that class's internals. A middleware on the front-end `web` group, as the submit gate uses: it would run on every request for two Control Panel routes. Checking the route's URI rather than its name: the Control Panel prefix is configurable.

**Residual.** Three places in core still list every form by title, each through `Form::all()`, so a user sees the titles of forms on sites they cannot access, though following a link to one is now refused: the editor's breadcrumb (`FormBlueprintController::edit()`), the blueprints index for a user with `configure fields` and `configure form fields` (`BlueprintController::forms()`), and the command palette for a user with `configure fields` (`Palette::buildFields()`). The breadcrumb is #124; the blueprints index and the palette are #133. A user with `configure fields` can also edit a global fieldset that a form's blueprint imports; fieldsets carry no sites, and that permission is not a form permission.

**Companion.** #116. Earlier decisions: #77.

## 2026-10-13: The Duplicate action is replaced under core's handle by one that also asks `view` (#117)

**Decision.** `UAMSWeb\MultisiteForms\Actions\DuplicateForm` extends core's `Statamic\Actions\DuplicateForm` and adds `view` on the form to its `authorize()`. Statamic registers the actions under an add-on's `src/Actions` by handle while booting the add-on, after core has registered its own, and the class's short name gives it core's handle, `duplicate_form`, so it replaces core's entry. Running the action and listing it, for one form or several, both go through `authorize()`.

**Why.** Core's action asks only `create` on the form class, so the gate never sees the form being copied, and a user with `configure forms` could copy a form of a site they cannot access, with its recipients, data and blueprint. Measured: with core's class registered again, the refusal tests answer 200 and copy the form.

**Rejected.** A middleware on the form action route: it would have to read the posted action name and selections, which core's controller already parses. Binding core's class to the add-on's in the container: Statamic's registration by handle already replaces it, and two mechanisms for one replacement leave a reader unsure which one acts. Asking `edit` rather than `view`: the copy is a new form, and the ticket asks for `view`.

**Residual.** A copy keeps its source's sites, so a user who may view a form shared with a site they cannot access, such as an `es`-only user on an `en` and `es` form, can create a form assigned to that site too. They cannot change the copy afterwards, since changing a form needs every one of its sites. Another add-on, or the host, registering its own `duplicate_form` after this one would replace it in turn.

**Companion.** #117. Earlier decisions: #77, #79.

## 2026-10-13: The submissions Site filter offers the user's sites the form is assigned to (#58)

**Decision.** `UAMSWeb\MultisiteForms\Query\Scopes\Filters\SubmissionSite`, registered by Statamic from `src/Query/Scopes/Filters`, is a filter on the `form-submissions` listing with the handle `submission_site`. It narrows the query by `_site` to one site. It offers the sites the user can access that the form is assigned to (every site, for a form with no sites), and is visible only when that leaves at least two. A chosen site leaves out submissions with no recorded site; with none chosen, the submission scope (#78) decides as before. A site handle the filter does not offer is applied as given, so it finds no submission the user can see rather than lifting the filter. Its badge names only an offered site, and shows any other by its handle, since core returns the badge for whatever handle a request names; site names are translated, as core's entries filter translates them.

**Why.** Core's listing and export both apply the filters a request names, so one filter class narrows both and needs no change to core's pages. Offering only sites both reachable by the user and covered by the form keeps the choice to sites that can hold visible submissions.

**Rejected.** The handle `site`, which a class named `Site` would take: it is core's entries site filter, and Statamic registers filters by handle, so it would replace core's. Offering every site the user can access: a form assigned to one site would show a choice with nothing behind it.

**Residual.** A submission recorded for a site the form is no longer assigned to cannot be chosen in the filter, though it still shows unfiltered to a user who can access that site.

**Companion.** #58. Earlier decisions: #41, #78.

## 2026-10-13: The attribute command refuses a site the form is not assigned to, and saves quietly (#81)

**Decision.** `multisite-forms:attribute {form} {site}` records `_site` on every submission of the form that has none, read with `whereNull('_site')` through the submission query builder. An unknown form or site, and a site the form is not assigned to, is an error with a non-zero exit. The form is matched by its exact handle, not with `Form::find()`, which on a filesystem that ignores case returns a form under the handle as typed and saves its submissions through another Stache store. Submissions are saved with `saveQuietly()`. Each change is logged at `info` level with the form, the submission's id and the site; `--dry-run` reports the same list and writes and logs nothing.

**Why.** A submission recorded for a site its form does not cover is one the Site filter cannot offer (#58), so the command would create the case that decision leaves as a residual. Core's own data migrations (`statamic:migrate-dates-to-utc`) save quietly: a backfill is not a new submission, so it should fire no event a listener, such as a Git integration committing each save, would act on. Core sends a form's email only from its submit route, so no save sends one. Reading through the query builder serves the Stache and the Eloquent driver alike, both measured.

**Rejected.** Allowing any configured site: it would let a backfill hide submissions from the form's own editors. Saving with events: a large backfill would trigger a listener per submission for a change no listener needs to see.

**Residual.** A search index that indexes submissions is not told of the change, as with core's data migrations. The unattributed submissions are read at once, which on the Eloquent driver holds them all in memory; not measured at volume. A submission whose `_site` is an empty string is neither read as unattributed nor shown by the Control Panel scope to anyone but super users; the add-on never writes one, and #130 decides it.

**Companion.** #81. Earlier decisions: #40, #58.

## 2026-10-13: The API form listings take a `site` filter through bound subclasses of core's (#80)

**Decision.** `UAMSWeb\MultisiteForms\Api\FormsController` and `UAMSWeb\MultisiteForms\GraphQL\FormsQuery` extend core's REST forms controller and GraphQL `forms` query, and are bound in the container in their place. The REST listing reads a `site` query parameter, as core's entries endpoints do; the GraphQL query takes a `site` argument. Either lists the forms the API exposes that are assigned to the site, or have no sites. An unknown site is a validation error: 422 with a message from REST, a `validation` error from GraphQL. Without the filter, or with a null GraphQL argument, the listings are core's.

**Why.** Laravel builds a route's controller, and the GraphQL library each query class, through the container, so a binding adds the filter without touching core's routes or the schema Statamic registers. Filtering after core's allowed-resources check keeps the API's own configuration in charge of what is exposed.

**Rejected.** A 404 for an unknown site, as core's entries endpoints answer: the ticket asks for a validation error, which says what was wrong. `nullable` on the GraphQL argument: Laravel skips other rules for an empty string, so `site: ""` would have listed only the forms with no sites; the rules apply only to a non-null value, and require it.

**Residual.** A host or another add-on binding its own class to either of core's would replace this one, or be replaced by it, depending on order.

**Companion.** #80.

## 2026-10-13: The fields editor's breadcrumb is filtered on the prepared Inertia response (#124)

**Decision.** `UAMSWeb\MultisiteForms\Http\BlueprintBreadcrumbs` listens for Laravel's `PreparingResponse` event. When the response is an Inertia response on the `statamic.cp.blueprints.forms.edit` route, it sets the `additionalBreadcrumbs` page prop to Statamic's pushed breadcrumbs with every link to a form's fields editor the user may not open removed: the forms for which the gate denies `configure form fields`. Every other response passes through.

**Why.** Measured before the change: an `es`-only user with `configure form fields` opening an `es`-only form's editor received the titles and editor links of every other form, `en`-only forms included, in the page's `additionalBreadcrumbs` prop, and in the HTML of a full page load. Core's `FormBlueprintController::edit()` pushes the breadcrumb, and `Breadcrumbs::push()` shares the result with Inertia at once, so nothing before the controller can filter it. Route middleware receives the response only after `Router::prepareResponse()` has rendered it, while `PreparingResponse` fires with the Inertia response still open, and a page prop set with `with()` overrides a shared one of the same name. Limiting links to editors the user may open, rather than to forms they may view, is narrower than the ticket asks: a link to an editor that #116 refuses would lead to a 403.

**Rejected.** Replacing or extending core's controller: it ties the add-on to that class's internals. Filtering in the editor route's middleware: the response is already rendered by then. Rewriting `Breadcrumbs::$pushed` before the controller runs: the controller pushes after any middleware's first half. Limiting links to the forms the user may view: an `es`-only user would see links to an `en` and `es` form's editor, which refuses them.

**Residual.** The blueprints index and the command palette still list every form by title (#133). Another listener that sets `additionalBreadcrumbs` on the same response after this one would replace the filtered prop.

**Companion.** #124. Earlier decisions: #77, #116.

## 2026-10-13: The submission scope recognizes a Control Panel request by the trimmed route prefix (#122)

**Decision.** `SubmissionScope::isControlPanelRequest()` replaces core's `Statamic::isCpRoute()` as the scope's test for a Control Panel request. It is false when the Control Panel is disabled or `statamic.cp.route` is not a string; otherwise it trims slashes from the configured route and from the URL-decoded request path, and is true when the path is the route or starts with the route and a slash. An empty route, which puts the Control Panel's routes at the root where they collide with the front end, makes every path count as one.

**Why.** Measured in the host (Windows 11, `statamic/cms` v6.35.1, PHP 8.4.20) by handling requests in process under each `CP_ROUTE`, with `cp` as the control: with `/cp` and with `/cp/`, `/cp/auth/login` answered 200 and `/cp` and `/cp/forms` redirected to the login page, each on its `statamic.cp.*` route, while `isCpRoute()` was false on all three; with `cp/` it was false on `/cp` itself. Core registers the routes with the configured value as a prefix, which Laravel trims, while `isCpRoute()` compares the raw value, so under those values every Control Panel read went unscoped. Trimming both sides matches the prefix the routes are registered under. Under `/cp` the Control Panel only partly works, which the host's scope tests showed: core's route bindings test the route's URI against the raw value too (`RouteServiceProvider::isCpRoute()`), so every Control Panel route that binds a form receives the handle as a string, and those tests answered 403, 500 and redirects where they pass under `cp`. The trimmed check therefore matters there for pages that bind no form, such as the forms index and its counts. The path is decoded because the router matches the decoded path while `path()` keeps it encoded: before the change, in the Testbench app, an `es`-only user listing a form's submissions through `/%63p/forms/shared/submissions` or `/cp%2Fforms/shared/submissions` was served the `en` and unattributed submissions too, since `isCpRoute()` is false on the encoded path; core's check has the same gap.

**Rejected.** Testing the matched route's name for the `statamic.cp.` prefix: a read made before routing, or outside a route, has no route to ask, and the scope's tests and callers decide by path today. Normalizing `statamic.cp.route` in config at boot: it would change the value core and other add-ons read.

**Residual.** Core's own uses of `isCpRoute()` are unchanged, so other core behavior under a slashed route or an encoded path is as core has it. Under an empty route, front-end and API reads by a signed-in non-super user are scoped as well, which hides submissions rather than showing them; that configuration already collides with the front end. A path with a doubled leading slash counts as a Control Panel one though the Control Panel's routes do not match it, as in core, which also errs toward scoping.

**Companion.** #122. Earlier decisions: #49, #78.

## 2026-10-13: The blueprints index and the command palette are filtered on the prepared response, as the breadcrumb is (#133)

**Decision.** `UAMSWeb\MultisiteForms\Http\BlueprintListings` listens for `PreparingResponse`. On the `statamic.cp.blueprints.index` route, while the response is an Inertia response, it sets the `forms` page prop to core's `BlueprintController::forms()` less every form whose fields editor the user may not open. On the `statamic.cp.command-palette.index` route, where core's controller returns the palette as a collection, it removes the items linking to such an editor from that collection in place, keeping it a list. `UAMSWeb\MultisiteForms\Access\RefusedFormEditors` names the refused editors' paths, built as core builds its links, for this listener and the breadcrumb's (#124); links are compared by path alone.

**Why.** Measured before the change, in the Testbench app: for an `es`-only user with `configure fields` and `configure form fields`, the index's `forms` prop and its HTML carried all four test forms' titles and editor links, and the palette's response linked all four forms' editors, each named by its blueprint's title, which defaults to one made from the form's handle. The palette is cached forever per user (`Palette::cacheKey()`), so filtering the response rather than the cache needs no invalidation. The response is the collection core returned, filtered in place, since a listener cannot replace it; under a store that keeps objects without serializing them, such as the array store, that collection is the cached one, which then stays filtered for that user until core clears the cache. The cache key names no host, so on a multisite with several domains a palette built on one is served on the others, with absolute links naming the first: the adversarial review of this change found that comparing whole URLs let every form through there, reproduced by a test that builds the palette on one host and requests it on another, so links are compared by path. The rule is the editor's own, as for the breadcrumb, so every link left opens.

**Rejected.** Replacing or extending core's controllers or palette: it ties the add-on to their internals. Adding to the palette's build through its public `add()`: it can only add items, not remove them. Reading the index's `forms` prop back from the Inertia response: the response has no public reader for it, so the listener asks core's controller for the same list again.

**Residual.** The palette's search endpoint (`command-palette.search`) searches the `cp` search index, which is not filtered here; core's default for that index searches `content`, `users` and `addons` (`IndexManager::indexesConfig()`), so it holds no forms unless a host adds them. Another listener that changes the same prop or collection after this one acts on the filtered list, and one that changed the index's `forms` prop before it loses its change, since the prop is rebuilt from core's controller. The palette's front end keeps the last few items opened in the browser's local storage, per browser, so a form opened before this change, or before the user's access narrowed, can still show under its recent items until replaced; not measured.

**Companion.** #133. Earlier decisions: #116, #124.

## 2026-10-13: The submission scope reads unattributed ids once per request (#123)

**Decision.** `SubmissionScope` works out a user's plan (the sites they can access, whether they cover every form, and the ids of the unattributed submissions they may read) once per request and user, as a `ScopePlan` held in a request attribute, and every scoped builder in that request applies it. The plan is dropped when core dispatches an event for a write that could change it: a submission saved or deleted, a form saved or deleted, a site created, saved or deleted, and a role, user or user group saved or deleted.

**Why.** Measured in the host (Windows 11, `statamic/cms` v6.35.1, PHP 8.4.20, add-on at `cf546d4` and then this branch) by requesting the Control Panel forms index in process, five timed requests after one warm-up, with forms and submissions in a temporary directory: a third of the forms listing `en`, a third `es` and a third both, each holding the given number of submissions with no `_site`, as a host adopting the add-on holds them. An `es`-only user who may view every form's submissions was timed against a super user on the same data in the same process. Median times:

| Forms, unattributed submissions per form | Super user | `es`-only, before | `es`-only, after |
| --- | --- | --- | --- |
| 6, 5 | 131 ms | 147 ms | 145 ms |
| 15, 50 | 153 ms | 350 ms | 199 ms |
| 30, 100 | 206 ms | 1,212 ms | 326 ms |
| 60, 200 | 574 ms | 6,947 ms | 992 ms |

The page counted the same submissions before and after (at 60 forms, 12,000 for the super user and 4,000 for the `es`-only user, whose shared forms count none), so the scope applied in every run. Before, each of the builders the index resolves, one per form it lists, read the covered forms' unattributed submissions again, so the cost grew with the square of the forms; after, it is one read per request.

#78 rejected this caching because a delete in the same request would leave the list stale; dropping the plan on the change events answers that, and the tests cover each event and a site change made in the same request.

The adversarial review of this change found an older gap in the id rule, reproduced by a test: an unattributed submission of a covered form whose id an attributed submission of another covered form also carries, as a copied file can, showed that attributed submission whatever its site. `unattributedIds()` dropped an id shared with an uncovered form only; it now also drops one that an attributed submission of a covered form carries, which hides the unattributed submission too.

**Rejected.** Caching the ids across requests: invalidation would then have to reach every process and every write path, where a request attribute ends with the request. Leaving the cost: seven seconds for an index of 60 forms is not usable.

**Residual.** A write that dispatches no event, such as `saveQuietly()` or `deleteQuietly()`, leaves the plan as it was until the request ends; the add-on's own quiet save is in the `multisite-forms:attribute` command, where the scope does not apply, and core's is the removal of temporary attachments, which changes neither a submission's site nor whether it exists. A quiet write in a Control Panel request can therefore leave a submission hidden or shown as the plan had it: a new unattributed one hidden, or one the plan keeps by id shown after its site changes or another form's submission takes its id. Core's Control Panel makes no such write. In console, `request()` lasts the process, so a plan would outlast one job only where the scope applies there, which is only under an empty `statamic.cp.route`. Measured against core's Stache builder only.

**Companion.** #123. Earlier decisions: #78, #122.

## 2026-10-13: Saving a form's sites may not produce a form its saver could not edit (#118)

**Decision.** For a non-super user, a save of a form's Sites field is refused when the selection names a site the user cannot access, or when it is empty and the user does not cover every configured site. A super user may save any configured sites, and an empty list. This supersedes #85 for forms. The build is #138.

**Why.** #85 let the Sites field accept any configured site because `configure forms` was a structural permission covering every form, as core's collection sites are. #77 removed that bypass: editing, deleting and configuring a form's fields need every site the form lists, or every configured site for a global form. Core's `FormsController::update` authorizes `edit` before it validates, so an editor who reaches a save already covers the form's current sites; refusing additions and refusing any inaccessible handle are therefore the same rule. Without it, an `en`-only editor could clear the field on an `en`-only form in one click, publishing it to every site and losing the right to edit it, or add `es` with a forged request (core's `sites` fieldtype offers only the sites the editor may view). One rule covers both: the saved assignment must be one the saver could still edit.

**Rejected.** Keeping #85: forms no longer match collections on site access (#54, #77), so the consistency it bought is gone, and it allows a scoped editor to publish a form to sites they cannot access. Allowing an empty list for a scoped editor: it is the case reachable in the user interface, and it publishes the form to every site.

**Residual.** Field validation runs on the Control Panel save only, so a save in code or from the console is not checked. Forms already widened or made global this way are not found or reverted. Duplicating a form the creator does not cover is #117's.

**Companion.** #118; build in #138. Earlier decisions: #42, #77, #85.

## 2026-10-13: An empty `_site` stays hidden in the scope, and the attribute command repairs it (#130)

**Decision.** A submission whose `_site` is an empty string is left to the Control Panel scope as it is: no non-super user's sites include it and `whereNull('_site')` does not match it, so only super users see it. `multisite-forms:attribute` reads it as unattributed and writes a real site handle, which repairs it. The README says so. The build is #140.

**Why.** Measured in the Testbench app on core's Stache builder (Windows 11): a submission saved through core with `_site` set to `''` is written without the key, reads back as `null`, and is treated as unattributed, because core's `Submission::fileData()` drops the empty value. A file written directly with `_site: ''` reads back as `''`; `whereNull('_site')` misses it and an `es`-only user's Control Panel listing hides it while listing the `es` submission beside it; re-saving it through core removes the key. So an empty site exists only in a file written around core, core gives it the meaning "unattributed" on its next save, and the scope meanwhile fails closed. The command is the tool for giving a submission its site, and it saves through core.

**Rejected.** Treating `''` as unattributed in the scope and the Site filter as well: it spreads a raw-file edge case through every query on the access-control path for a state core never writes. Leaving it with a README note only: operators would have no repair the add-on provides.

**Residual.** Not measured on `statamic/eloquent-driver`, which this repository does not install; whether it stores `''` in its JSON column is open, and #65 can measure it. A file with another non-handle `_site` value behaves the same way and is not covered. Nothing finds such submissions; the command repairs them when run on their form.

**Companion.** #130; build in #140. Earlier decisions: #40, #78, #81.

## 2026-10-13: The wiki holds guides, written in `docs/wiki/` and published by github-wiki-action (#156)

**Decision.** The GitHub wiki holds guides: a walkthrough, task guides by role, troubleshooting and project links. The README keeps the reference for settings, tags, commands and permissions. Wiki pages are written in `docs/wiki/` and reviewed in pull requests, and `.github/workflows/publish-wiki.yml` publishes the folder with github-wiki-action pinned to v5.0.6 (`1bbb428`) when a release is published, or by hand. Pages are not edited in the browser.

**Why.** Search engines index a wiki only on a repository with at least 500 stars and restricted public editing, so reference kept only in the wiki could not be found from a web search; the README is indexed and shown on Packagist. A wiki takes no pull requests, so pages written in the browser would get no review and no checks. The action replaces the whole wiki with the folder on every run (it deletes every other file in the wiki clone and force-pushes), which makes the folder the single source.

**Rejected.** Writing the pages directly in the wiki: no review, and the first chosen plan before the action's behavior was known. Moving most of the README into the wiki: the reference would drop out of search. GitHub Pages: a site to build and host for documentation that fits a README and a handful of guides.

**Residual.** An edit made in the browser is lost at the next publish without warning; the footer of every page says where to edit instead. The first publish settled four platform behaviors and left five open; `.claude/skills/github-wiki/SKILL.md` lists both, and the guide tickets settle the rest as their pages need them.

**Companion.** #156; skill in #152 and #154; guides in #157 to #163; a shared skill in `UAMS-Web/uams-claude-skills#172`.

## 2026-10-13: The wiki holds the reference too, and the README becomes an overview (#166)

**Decision.** The README holds an overview and getting started: what the add-on does, its status, requirements, installation, a short quick start and a link to the wiki, with the development notes and the license. The wiki holds everything long-form: the reference for settings, tags, the attribute command, permissions and the API filter, as well as the walkthrough, guides, troubleshooting and project links. Where the wiki needs installation, it links the README. This supersedes the README half of #156's decision; publishing from `docs/wiki/` with github-wiki-action is unchanged. #163 moves the reference.

**Why.** GitHub's documentation describes the README as the place to say what a project does and how to get started, and the wiki as the place for long-form documentation, and the example wikis reviewed for #152 follow that split, linking the README for installation rather than holding reference in it. #156 departed from it only because a wiki on a repository under 500 stars is not indexed by search engines. Search indexing is not a priority for this repository, so that reason does not hold.

**Rejected.** Keeping the reference in the README (#156): it departs from the guidance for a reason this repository does not weigh. Keeping it in both: two copies drift.

**Residual.** Reference moved to the wiki cannot be found through a web search while the repository is below 500 stars. Until #163 lands, Home links the README sections that hold it.

**Companion.** #166; the move in #163. Earlier decision: #156.

## 2026-10-14: The development patches are declared in a root-only `patches.json` (#178)

**Decision.** The seven `pest-plugin-mutate` patches are declared in `patches.json` at the repository root, not under `extra.patches` in `composer.json`. The 2026-10-11 decision to carry them is unchanged.

**Why.** Composer copies a package's `extra` block into each host's `composer.lock`, and `cweagans/composer-patches` 2.0.0's `Dependencies` resolver applies `extra.patches` from every package there, so a host running that plugin alongside `pest-plugin-mutate` would try to apply this repository's patches, from a `patches/` directory the dist archive does not contain. The resolver has no path for a dependency's `patches.json`, so the root file stays this repository's own. This is the package form the `upstream-patch-lifecycle` skill prescribes, and it clears the way to publishing on Packagist.

**Rejected.** Keeping `extra.patches` and asking hosts to set `ignore-dependency-patches`: every host would have to know to do it, and one that did not would fail to install. Shipping `patches/` in the dist archive: the patches would then apply to a host's plugin, which is worse than failing.

**Companion.** #178; the `upstream-patch-lifecycle` skill.

## 2026-10-14: Releases stay on 0.x until the add-on has run in production with a stable surface (#180)

**Decision.** Releases stay 0.x pre-releases, and the package goes to Packagist on 0.x. 1.0.0 is cut when #180's criteria are met: 30 consecutive days on a production UAMS Statamic site with no change to a stored key (`sites`, `site_overrides`, `_site`), a config key or a public class signature, no open behavior bug, the host-bound suite passing on macOS, and `statamic/eloquent-driver` either measured or named as unsupported. That release adds nothing beyond the version.

**Why.** 1.0.0 promises the public surface until a 2.0.0, and the stored keys are the costliest part of it to change, since they sit in other repositories' content files and a change to one needs a migration. Every check so far has run against the test suites and the Herd host, and macOS and the Eloquent driver are open residuals of earlier entries. Packagist does not need 1.0.0: Composer serves 0.x tags normally, and a `^0.6` constraint already holds an install to one minor.

**Rejected.** 1.0.0 at the Packagist listing: it would promise a surface no production site has exercised, and the first change to a stored key would force a 2.0.0. Leaving the bar unstated: 1.0.0 would then wait on a judgment nobody recorded, and nothing would say when the add-on had earned it.

**Companion.** #180, which carries the criteria as its acceptance list.

## 2026-10-14: The Statamic Marketplace listing waits for v1.0.0 (#187)

**Decision.** The add-on is listed on the Statamic Marketplace only after v1.0.0 is released. Packagist, where it was listed on 2026-10-14 (#185), is how it is installed until then. #187 carries the listing steps and is blocked by #180.

**Why.** Packagist serves anyone who already knows the package and pins a version. The Marketplace offers the add-on to site owners browsing for one, and they are the readers least likely to read a 0.x warning or to expect a breaking minor release. The 1.0.0 criteria (#180) are the point at which the stored keys, config keys and public classes are promised, so they are also the point at which the add-on is ready to be offered to them.

**Rejected.** Listing now on 0.x: the Marketplace guidelines accept a labeled pre-release (Rule 06), but sites found through the listing would build on stored keys that no production site has yet exercised, and a change to one needs a migration in each of their content repositories. Leaving the timing unstated: `docs/SUBMISSION.md` would read as a list of next steps, and a later session could start them.

**Companion.** #187; `docs/SUBMISSION.md`.

## 2026-10-14: The copyright holder is the University of Arkansas for Medical Sciences, and 1.0.0 waits on UAMS legal's licensing decision (#180)

**Decision.** `LICENSE` names `University of Arkansas for Medical Sciences` as the copyright holder, in place of `UAMS Web Services`. 1.0.0 also waits on UAMS legal's general decision on licensing and disclosing public `UAMS-Web` repositories (`UAMS-Web/uams-statamic#261`) being applied here: the license, the copyright line, any paths the code license must exclude, and the disclosure. #180 carries that as a criterion and is blocked by #3039. The license stays MIT, and `composer.json`'s `authors` keep `UAMS Web Services`, which credits the team that wrote the code rather than naming its owner.

**Why.** University of Arkansas System Board Policy 210.1 gives software its employees write to the University, so the copyright line names an owner, and `UAMS Web Services` is a department, not a legal entity. The campus is the line #3039 offers alongside the Board of Trustees. The package is already public and on Packagist, so whatever legal decides about the license or the line has to land while the version still says it can change: 1.0.0 is the release that promises the surface.

**Rejected.** Waiting for legal before changing the line: the department name is wrong under either of #3039's options, and the campus is the operator's choice until legal gives another form. Leaving the legal decision out of the 1.0.0 criteria: a license or copyright change after 1.0.0 would land on installs that took the release as settled.

**Companion.** #180; `UAMS-Web/uams-statamic#261`.

## 2026-10-22: The ruleset requires one aggregate check, and the drift check is removed (#169)

**Decision.** `ci.yml` ends in a `CI` job that `needs:` every other job and fails unless each reports `success`. The organization ruleset for this repository requires `CI`, `Attribution footer` and `Closing keywords` and no per-job name. `check-required-checks.yml`, `scripts/check-required-checks.mjs` and its test are removed.

**Why.** The ruleset listed 17 job names, so every rename or added job meant editing the ruleset, and the drift check existed only to report when the two lists disagreed. With one aggregate name the lists cannot disagree: a job that is not in `needs:` of `CI` is the only drift left, and the template's own check in `UAMS-Web/uams-claude-skills` covers it. Supersedes the 2026-10-12 entry on the drift check (#11).

**Rejected.** Keeping the drift check beside the aggregate: it would compare a list of three names with job names it no longer has to match. Gating `CI` on a remote-CI variable: this repository is public and runs every job on every pull request.

**Companion.** `UAMS-Web/uams-claude-skills#292`, `#291`, `#296`.

## 2026-11-10: The `pest-plugin-mutate` patches come from the shared `uams-web/patches-pest-plugin-mutate` package (#243)

**Decision.** `composer.json` requires `uams-web/patches-pest-plugin-mutate` `^1.0` in `require-dev`, and the root `patches.json` lists the package's seven patches by `vendor/uams-web/patches-pest-plugin-mutate/patches/` path in the package's order. The `patches/` directory and this repository's copies are removed. The package form stays: the file is root-only, so nothing reaches this add-on's consumers.

**Why.** Thirteen repositories carried the same seven patches as copies, and two had drifted into three and four versions (`UAMS-Web/uams-claude-skills#548`). With the package, a patch changes and retires in one release. The `windows-filter-length` copy here was an earlier revision of the package's, which also widens a filter PCRE cannot compile on every platform and gives a widened child a longer time limit.

**Rejected.** Keeping the copies: they drift, and each retirement would stay one pull request per repository. Moving to `extra.patches` in the package alone: this add-on is installed by other repositories, and the plugin would apply the patches inside their trees.

**Companion.** `UAMS-Web/uams-claude-skills#548`, `#643`.
