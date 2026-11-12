# Per-site forms: design spec

In a Statamic multisite install, forms are global: one definition, one submission pool, one set of recipients, visible on every site. This add-on makes a form belong to sites. A form can be limited to some sites, can differ per site where it matters (title, recipients, whether submissions are stored), and keeps its submissions attributable to the site they came from, with Control Panel access following the site access an editor already has.

This page is a design, not a record of built behavior. Every statement about core is from reading `statamic/cms` v6.35.1 (the version in the development host) and cites the file under `src/`. Statements marked **unverified** were not exercised and each has a spike in the phasing section. Decisions that close a fork go to `docs/DECISIONS.md` when they are made.

## What core does today

| Fact | Where it comes from |
| --- | --- |
| A form is one YAML file, `config('statamic.forms.forms')/{handle}.yaml`. | `Forms/Form.php` `path()` |
| Keys core does not recognize are kept: `hydrate()` merges them into the form's data and `save()` writes that data back. | `Forms/Form.php` `hydrate()`, `save()` |
| `email` is a list of email configs, or one config when it has a `to` key. Core iterates it as such. | `Forms/SendEmails.php` `emailConfigs()` |
| Add-ons can add sections of fields to the form config screen with `Form::appendConfigFields($handles, $display, $fields)`. Handles `title`, `honeypot`, `store` and `email` are reserved. | `Forms/FormRepository.php` |
| `Form::all()` feeds the Control Panel index, the REST and GraphQL queries, and the submissions Stache store's discovery of which forms exist. | `CP/Forms/FormsController.php`, `GraphQL/Queries/FormsQuery.php`, `Stache/Stores/SubmissionsStore.php` |
| On submit, the route's `form` parameter is bound by `Form::find()`, a missing form is a 404, and the site is `Site::findByUrl(URL::previous()) ?? Site::default()`. That site is passed to `SendEmails`, which uses it for the email locale and site globals. It is not stored on the submission. | `Providers/RouteServiceProvider.php`, `Http/Controllers/FormController.php` |
| The controller reads `$form->store()` from the bound form, and builds the submission with `$form->makeSubmission()`, which sets the submission's form to that same object. | `FormController.php`, `Forms/Form.php` |
| `FormSubmitted` is dispatched with the submission before it is saved and before emails are queued; a listener returning `false` ends the request as a silent success. | `FormController.php` |
| The `{{ form }}` tags look the form up with `Form::find()` cached under the Blink key `form-{handle}`, or accept a form object from context. | `Forms/Tags.php` `form()`, `formHandle()` |
| Submissions are Stache-backed. The store's file filter accepts only YAML files directly inside the form's own folder, and the form handle comes from that folder name. | `Stache/Stores/FormSubmissionsStore.php` |
| The submission query builder accepts arbitrary `where` columns (it special-cases only `form`, `forms` and the `datestamp` order). An arbitrary data key is filtered and gets a `Value` index on first use (spike 2). | `Stache/Query/SubmissionQueryBuilder.php` |
| Form permissions are per form: `view {handle} form submissions`, `delete {handle} form submissions`, plus `configure forms`. Core separately registers `access {site} site`. | `Policies/FormPolicy.php`, `Policies/FormSubmissionPolicy.php`, `Auth/CorePermissions.php` |
| `Form` is `__clone`-safe: it clones its data and supplements. | `Forms/Form.php` |

## Goals and non-goals

**Goals**

- Assign a form to one or more sites. Absent or empty means all sites, which is today's behavior.
- Override title, email configs and the store flag per site without cloning the form.
- Record which site each new submission came from, and let editors and exports work per site.
- Scope Control Panel access with the site access editors already have, without new permission keys to maintain.
- Stay additive: with the add-on removed, every form file is still a valid core form and behaves as a global form.

**Non-goals**

- Replacing core forms, the form builder, or the Control Panel screens.
- Translating form content. This is about site ownership, not locale.
- Per-site blueprints. One blueprint per form stays the rule.
- Per-site storage folders or storage drivers (see the decision below).
- Paid editions. The add-on is free with no editions (`docs/DECISIONS.md`, #17).

## Principles

1. **Do not change `Form::all()` or `Form::find()` for everyone.** The submissions store discovers forms through `Form::all()`, so filtering it by site would hide submissions from core itself. Scoping is applied at the seams (frontend, submit, API, Control Panel policy), never in the repository.
2. **A site-resolved form is a read-only view.** If a resolved form were saved, its overrides would be written into the base file. The resolved type refuses `save()` and `delete()`.
3. **Reuse core's site access.** Editors already have `access {site} site`. Per-site form access is derived from it, not duplicated.
4. **Prefer core's extension points to replacing core classes**: `appendConfigFields`, events, policies, middleware. Replacing a container binding is a last resort and needs its own decision.

## Data model

The base form file stays where core puts it and gains two keys the add-on owns.

```yaml
# resources/forms/contact.yaml
title: Contact
store: true
email:
  - to: info@example.com
    subject: Website inquiry
sites: [en, es]              # absent or empty = all sites
site_overrides:
  - site: es
    title: Contacto
    store: false
    email:
      - to: info-es@example.com
        subject: Consulta del sitio
```

- `sites` assigns the form. A form listing one site is a per-site form; a form listing several is shared with the option to differ; no list is global.
- `site_overrides` is a list of rows, one per site, each with a `site` handle. This is the shape a grid field saves (spike 1), so the Control Panel editor needs no custom fieldtype. Only `title`, `email` and `store` are recognized in the first release. Unknown keys are ignored, never errors, and a second row for the same site is a validation error.
- **Resolution for a site:** the value in the row whose `site` matches, if present, else the base value. An overriding `email` replaces the whole list; it is not merged, because merging two recipient lists silently sends mail to people nobody named for that site. A row value counts as present only when it holds something: a missing or empty `title`, a missing or empty `email` list, and a `store` that is neither a boolean nor the string `'true'` or `'false'` fall back to the base value, so a cell an editor left blank never clears a site's title or stops its email. A `store` of `false` (or `'false'`, which the editor saves) is a real override. With two rows for one site, the first applies (#75).
- **Why not keyed maps under core's own keys.** `email: {en: [...], es: [...]}` would be read by core as two email configs named `en` and `es`. Keeping core's keys exactly as core defines them is what makes removal safe.
- `success_message` and `redirect` are deliberately absent. Core has no such form keys: the redirect comes from tag parameters and `Form::getSubmissionRedirect()`. They become overridable only with a defined hook, as a later phase.

### Control Panel fields

`Form::appendConfigFields('*', 'Sites', [...])` adds a **Sites** section to the form config screen: a sites selector for `sites`, and a stacked grid for `site_overrides` (#76). This uses core's own extension point, so no Control Panel view is replaced. The same fields reach the front end: the `{{ form:create }}` tag exposes them to templates as `form_config`, built from the data of the form it renders. The site view removes `site_overrides` from its data when it applies its row, so `form_config.site_overrides` carries no rows on any site, including the site a row belongs to; a template reads that site's values from the form's own title and email instead (#62). The key is removed rather than narrowed to the current site's row because the view already carries those values, and a narrowed row would be a second, partial copy of them. `form_config.sites` is still exposed. Core's edit and save path round-trips both handles, including a grid nested in a grid (spike 1, below).

Each override row holds a `site` (the sites fieldtype, one site, required), a `title`, a `store` and a nested `email` grid. `store` is a clearable button group saving `'true'` or `'false'`, not core's toggle: a toggle saves `false` when left alone, so a row added only for its title would stop storing on that site. The `email` grid carries the same columns as core's form email grid, mirrored because core defines them inside a protected controller method; a test compares the two. Saving validates the rows together with the `sites` selection in the same request: a row naming a site the install does not define, a site the form is not assigned to (any site, for a global form), or a site with a second row is refused, with the site named.

## Site determination

The add-on resolves the submit request's site in one shared helper, so the site that gates a submission and the site recorded on it cannot disagree. The helper prefers the signed `_site` field and falls back to core's own rule, `Site::findByUrl(URL::previous()) ?? Site::default()`; core's email job is given that rule's site, and the add-on's `SendSiteEmail` replaces it with the recorded one, so the email's locale and globals follow the submission. On frontend page renders the site is `Site::current()`.

**Stated limit.** The form tag carries the page's site in a signed hidden field (`_site`), which the submit rule prefers; a field whose signature fails is rejected. Without the field the rule trusts the referrer, as core does, and a missing or stripped referrer resolves to the default site. Assignment therefore gates which forms are offered and accepted; it is **not** a security boundary against a client that crafts requests, since the signed value is readable from any page of the site it names.

## Runtime behavior

| Surface | Behavior |
| --- | --- |
| `{{ form:create }}` and the other form tags | Resolve the form for `Site::current()`. If the form is not assigned, behave per `not_assigned` (below). Otherwise the tag renders the resolved view, so title and the rest follow the site. Mechanism: seed the `form-{handle}` Blink key with the resolved view early in the request (spike 3). |
| Submit | Middleware on the form action route, after route binding, replaces the bound `form` with the resolved view for the request's site. A form not assigned to that site is a 404, matching core's missing-form 404. Because the controller reads `store()` and builds the submission from the bound object, the override of `store` and the email configs reach core with no change to core. Verified through the real controller (spike 5). |
| Recording the site | A `FormSubmitted` listener sets a reserved key on the submission data (`_site`). It runs before save and before emails are queued. A `SubmissionCreating` listener on the submit route is the backstop for a `FormSubmitted` dispatch an earlier listener halted (#74). Request values starting with `_` are treated by core as parameters, and only blueprint fields are processed into data, so a visitor cannot inject the key; the site is the one the form tag's signed `_site` field names, else the referrer's as core's email site is, among the sites the form is assigned to. |
| Email | The submission's form is the resolved view, so `SendEmails` reads the site's `email` list with no hook into core. Core passes the job the site its referrer names, so the add-on sets `statamic.forms.send_email_job` to `SendSiteEmail`, a subclass of core's `SendEmail` that takes the site recorded on the submission (`_site`) and keeps core's site only for a submission with no recorded or configured site. The swap happens only while the setting still names core's own job; a host that set its own job keeps it, and its emails follow core's site. |
| REST and GraphQL | An optional `site` filter limits form listings to forms assigned to it, and forms with no sites. Without it, behavior is core's. An unknown site is a validation error. Core's REST forms controller and GraphQL `forms` query are subclassed and bound in the container in their place (#80). |
| Static caching | Not changed. Whether static cache keys already separate sites (they are URL-based, and sites have distinct URLs) is **unverified**; confirm with the same URI on two domains before claiming it. |

`not_assigned` is a config value with two settings. `empty` renders nothing from the tag and logs a warning naming the form and site; `404` aborts the request. **The default is `empty`** (#39): a shared template (a footer or layout partial) rendered on every site with a form meant for some of them is the pattern multisite invites, and failing loudly would break pages that are working as intended. Core's own behavior for a missing form is to throw (`Tags::getForm()`); an unassigned form deliberately does not. Whether the warning is logged once per request or at debug level is settled at build time. A template check of assignment, to hide content around an empty form, is #60. Submit is always a 404.

## Submissions

New submissions store the site in the data under `_site`. Core's store, indexes and file layout are untouched.

- **Submissions with no `_site` are unattributed.** That covers submissions saved before the add-on and any saved without the submit route (code calling `$form->makeSubmission()->save()`, an import, another add-on), so it is an ongoing category, not only history. A non-super user sees them only when they can access every site the form is assigned to, which for a global form means every site (#40). Single-site editors therefore lose sight of pre-install submissions until they are attributed, and the release notes say so. A `multisite-forms:attribute` command assigns a site to unattributed submissions of a form (with `--dry-run` and a log of each change), for installs that want to backfill (#81). It refuses a site the form is not assigned to, matches the form's handle exactly, and saves quietly, so no submission event fires. It also repairs a submission whose file holds an empty `_site`, which the scope hides from every non-super user (#130, #140). It reads through the submission query builder, so it serves both drivers: on `statamic/eloquent-driver` v5.12.3 with SQLite, a dry run left four rows unchanged, the real run recorded the site on the two rows with none and left the attributed rows and another form's rows as they were, and a second run found nothing; there, a submission's id is a number rather than a string. Measured on Windows 11.
- **Per-site listing and export** filter on `_site` through the query builder. The Stache filters on the key correctly and indexes it on first use (spike 2, below), and the Eloquent driver filters on it as a JSON path in the `data` column (spike 6), so no post-filter is needed on either driver.
- **Why not a folder per site, now.** The store's filter only accepts files directly in the form folder, so `contact/clinic/*.yaml` would be invisible to core. A folder layout would mean replacing the store, its key and path rules and its indexes from the first release, and tying the add-on to Stache internals that move between versions. A folder layout can be reached later by a deterministic per-file move, so this choice is reversible and the other is not cheap. Decided in `docs/DECISIONS.md` (#43).

## Control Panel

No Control Panel view is replaced.

- **Form visibility.** Core's index keeps only forms the user `can('view')`. The add-on registers a global `Gate::before()` callback on the form and submission abilities: a form with a non-empty `sites` list is denied to a non-super user who has no `access {site} site` for any assigned site, and the callback returns nothing otherwise. It has to be a `before` callback: core's form policies return true from their own `before()` for `configure forms`, and Laravel's `Gate` lets an `after` callback change only a null result (`Illuminate/Auth/Access/Gate.php`, `callAfterCallbacks()`). Super users are untouched. The index therefore scopes itself.
- **Submission visibility.** A user sees submissions whose `_site` is a site they can access, and unattributed submissions only when they can access every site the form is assigned to (all sites for a global form; #40). Every Control Panel read goes through `Form::querySubmissions()` or `FormSubmission::find()`, which resolve to the container-bound `SubmissionQueryBuilder` contract. The add-on decorates that contract with `app()->extend()`, adding the site scope through the public query API, so the listing, detail view, delete, export and the forms index count are scoped without replacing a controller or core's builder class (spike 4, decided in #49). The scope applies only to Control Panel requests by non-super users, because the same builder serves the API and the `{{ form:submissions }}` tag. Core's Stache builder applies a `form` condition to the whole query and ignores it inside a nested group, so the unattributed rule cannot be a per-form group: when a user covers some forms but not others, the scope reads the ids of the covered forms' unattributed submissions with a separate query and keeps those ids (#78).
- **`configure forms` does not bypass the scope.** A non-super user with `configure forms` is scoped to their accessible sites like any other non-super user, for forms and submissions (#54). Only super users see every site. This narrows what core allows that permission, and the release notes say so.
- **Editing a form.** A non-super user may edit a form's settings only when they can access every site it is assigned to (every site, for a global form), because base values reach every site without an override row (#42). The check is the same global `Gate::before()` on the `edit` ability, so a scoped admin cannot reroute another site's email. A form created by a user who does not cover every site is assigned to the sites they can access, so they can still edit it: a `FormCreating` listener sets `sites` before the form is first written, whatever created it, and leaves a form that already lists sites as it is (#79). Saving the settings holds the new `sites` selection to the same rule, so an editor cannot save a form out of their own reach: a site they cannot access is refused with its name, and an empty or missing selection, which makes the form global, is refused unless they can access every site (#118). The settings screen itself is authorized on `edit` (`FormsController::edit()`), so the same denial also keeps such a user from viewing a shared form's settings (#57). Deleting a form and editing its fields (`delete`, `configure form fields`) are held to the same rule, since each reaches every site; a site handle no longer configured counts as one the user cannot access (#77). Core's blueprint editor authorizes `configure form fields` without naming the form, so a middleware appended to Statamic's authenticated Control Panel group authorizes it again with the form on the editor's screen and save routes, and the callback applies (#116); the editor's breadcrumb, which core builds from every form, links only the forms whose editor the user may open (#124), and so do the blueprints index and the command palette (#133); core's Duplicate action names no form either, so the add-on registers its own action under core's `duplicate_form` handle, which also asks `view` on the form; a copy keeps its source's sites, so it can be made only from a form the user may view (#117).
- **Permissions.** No new keys. Per-site access is the intersection of a core form permission and a core site permission, so an administrator already has the controls. This differs from per-form-per-site keys on purpose: it is less expressive and far less to keep correct. If per-form-per-site grants are demanded, they are an addition.
- **Site selector.** Not in the first release (#41): isolation is complete by access alone. A **Site** filter on the submissions listing followed (#58): a core filter class declared `visibleTo('form-submissions')` that the export reuses, so it needs no Vue change. It is `UAMSWeb\Aether\Forms\Multisite\Query\Scopes\Filters\SubmissionSite`, handle `submission_site`, which narrows by `_site` and offers the sites the user can access that the form is assigned to, appearing only when there are at least two; with no site chosen, the submission scope (#78) decides as before. A switcher following the Control Panel's selected site (`Site::selected()`) needs changes to core's Vue pages and is deferred until there is demand.

## Events

None added in the first release. Core's `FormSubmitted`, `SubmissionSaved`, `FormSaved` and the rest are enough for the add-on and for anyone extending it. A resolved-form event is added when a consumer needs it.

## Compatibility and risks

| Risk | Mitigation |
| --- | --- |
| Core changes `Form::hydrate()`/`save()` handling of unknown keys. | A test asserts both keys survive a core save round trip; the CI compatibility jobs already run the lowest and the latest `statamic/cms`. |
| The Control Panel config screen changes how added sections render. | Only `appendConfigFields` is used; the host-bound test loads the screen. |
| The resolved view leaks into a save. | It refuses `save()` and `delete()`; a unit test asserts both throw. |
| The submit middleware runs before or after route binding differently across versions. | Test in the host on the lowest and latest supported version. |
| A site-denying gate callback is overridden by core's policy `before`, which returns true for `configure forms`. | The callback is a global `Gate::before()`, which Laravel runs before any policy (`Gate::raw()`); it returns nothing for super users and for allowed sites. Tests cover a super user, a `configure forms` user and a plain viewer. |
| `_site` collides with a user field. | Underscore-prefixed keys are core's parameter namespace and cannot be blueprint handles. |
| Submissions are stored in the database through `statamic/eloquent-driver`, where `_site` is a JSON path rather than a Stache index. | Measured on SQLite, from a console (spike 6) and through Control Panel listing, detail and export requests (spike 8), and on MySQL 9.7.2 and PostgreSQL 18.0, whose grammars compile the JSON path to their own functions with the same results (spike 9). Volume and indexing a JSON path are not measured. |
| Forms are stored in the database through `statamic/eloquent-driver`, whose form class adds a model the resolved view does not copy. | Measured (spike 7): the view carries the same values as its base form. It is not an instance of the driver's class and has no `model()`; whether to close that is #107. |

## Phasing

Each phase ends with a host-bound test run in `statamic-multisite-forms-host` (`docs/DEVELOPMENT.md`).

| Phase | Scope | Exit criteria |
| --- | --- | --- |
| 0. Spikes | Spikes 1 to 6 below, each recorded in `docs/DECISIONS.md` or on its ticket. | Every unverified item above is either verified or has a recorded fallback. |
| 1. Assignment and submit | `sites` key and Sites config section; resolved view; submit middleware and 404 for unassigned sites; `{{ form }}` tag gating; `_site` recorded on new submissions. | In the two-site host, a form assigned to `en` renders on `en`, 404s on submit from `es`, and its submissions carry `_site: en`. |
| 2. Overrides | `site_overrides` for title, email and store; overrides editor in the config section. | A form assigned to both sites emails a different recipient from each, and stores on one site only. |
| 3. Access and APIs | Site-access gate on forms and submissions; per-site listing and export; `site` filter on REST and GraphQL; `multisite-forms:attribute`. | An editor with access to only `es` cannot list, open or export an `en` submission, and cannot see an `en`-only form. |
| 4. Optional | Success message and redirect overrides with a defined hook; selected-site filtering in the forms index; signed site value; per-site submission folders. | Driven by demand. |

### Spikes

1. **Config round trip.** Resolved (#34); see the results below.
2. **Querying `_site`.** Resolved (#35); see the results below.
3. **Tag resolution.** Resolved (#36); see the results below.
4. **Submission listing scope.** Resolved (#37); see the results below.
5. **Resolved view through the controller.** Resolved (#38); see the results below.
6. **The Eloquent submissions driver.** Resolved on SQLite (#53), through Control Panel requests (#65), and on MySQL and PostgreSQL (#66); see the results below.
7. **The resolved view under the Eloquent forms driver.** Resolved (#83); see the results below. Its one gap is a decision in #107.

## Open questions

- [x] Default for `not_assigned`. Decided: `empty` with a warning, `404` available (#39, `docs/DECISIONS.md`).
- [x] Who sees unattributed submissions. Decided: only users who can access every site the form is assigned to (#40, `docs/DECISIONS.md`).
- [x] Whether the first release needs more than access-based scoping. Decided: access only; a Site filter on the submissions listing follows as #58 (#41, `docs/DECISIONS.md`).
- [x] Whether `configure forms` bypasses the site scope. Decided: it does not; only super users do (#54, `docs/DECISIONS.md`).
- [x] Who may assign a form to sites and edit its settings. Decided: only users who can access every assigned site; a scoped admin's new form is assigned to their sites (#42, `docs/DECISIONS.md`).
- [x] Submissions as a flat store with `_site` or a folder per site. Decided: flat store with `_site` (#43, `docs/DECISIONS.md`).
- [x] How submissions are scoped by site in the Control Panel. Decided: decorate the `SubmissionQueryBuilder` contract with `app()->extend()` (#49, `docs/DECISIONS.md`).

## Test plan

A two-site fixture in the host: one form assigned to both sites with different recipients, and one assigned to a single site. Unit tests cover resolution order, the read-only view and the key survival round trip; Integration tests cover the gate callback and the `FormSubmitted` listener; host-bound tests cover the Control Panel config screen, the submit middleware, the tags and the emailed recipient. The `host-only-behavior-needs-a-host-run` rule applies to every phase after the first.

## Spike results

Each result names what was measured, where, and what it did not cover. Measured on Windows 11 with PHP 8.4.20, `statamic/cms` v6.35.1 and Laravel 13.35.0, in `statamic-multisite-forms-host`. macOS is not measured.

### Spike 1: config round trip (#34)

`Form::appendConfigFields('*', 'Sites', ...)` was registered with a `sites` field (the `sites` fieldtype) and a `site_overrides` grid whose rows hold a `site`, a `title`, a `store` toggle and a nested `email` grid. A probe then drove the same code path as `FormsController::edit()` and `update()`: built the edit blueprint, validated and processed a request body, merged the non-core values into the form, saved, reloaded from disk, and rebuilt the edit values.

| Check | Result |
| --- | --- |
| The edit blueprint contains `sites` and `site_overrides`. | Yes |
| `process()` returns both handles alongside core's. | Yes |
| The file on disk after save. | `sites: [en]`, and `site_overrides` as a list of rows with the nested `email` list intact |
| Reloaded form data and the values `edit()` would hand the screen. | Identical to what was saved |
| Control: a handle that was never appended. | Absent from the blueprint |

A grid saves a list of rows, not a map keyed by site, which is why the data model uses a list. Not covered: rendering in a browser, validation messages for the nested grid, and duplicate-site validation (the add-on must supply it).

### Spike 2: querying a reserved `_site` key (#35)

Four submissions were saved to a probe form through `$submission->save()`: two with `_site: en`, one with `_site: es`, and one with no `_site`. They were queried from a separate process, so the Stache was cold.

| Query | Result |
| --- | --- |
| `where('form', ...)` alone | all four |
| `where('_site', 'en')` | the two `en` submissions; `count()` returns 2 |
| `where('_site', 'es')` | the one `es` submission |
| `whereIn('_site', ['en', 'es'])` | the three attributed submissions |
| `whereNull('_site')` | the one unattributed submission |
| Control: `where('_site', 'xx')` | none |

After the queries, the form's store listed a `_site` index of class `Statamic\Stache\Indexes\Value`, alongside core's `id`, `path`, `form` and `date`. The index is created on first use and is not declared by the add-on, so nothing needs registering. Not covered: query performance at volume, and behavior when the key is absent from every submission of a form.

### Spike 3: resolving the site view in the form tags (#36)

The `create` tag looks its form up with `Blink::once("form-{handle}", ...)`. A probe instantiated `Statamic\Forms\Tags` as core does, rendered `create` with a content template of `[{{ honeypot }}]`, and compared three states of the Blink key. The honeypot was used as the observable because the tag exposes it from the form it resolved.

| State | Rendered honeypot |
| --- | --- |
| Control: nothing seeded | `base_trap`, the value on disk |
| A clone with honeypot `site_trap` seeded under `form-spike_t` | `site_trap` |
| The seeded key forgotten | `base_trap` again |

The base file on disk still read `base_trap` after the seeded render, and the form's action URL was unchanged (`/!/forms/spike_t`). So the tags accept a resolved view through Blink with no change to `Form::find()` or the repository. The seed must happen before the tag runs and within the same request, because Blink is request-scoped. Not covered: a render through a real page request and view, since `Antlers::parse` in a console returned empty output for every tag tried, including a control tag, so the probe went through the tag class directly.

The `create` tag also runs `attrs`, `after-open` and `before-close` hooks (`Forms/Tags.php`). `after-open` is a candidate seam for carrying a signed site value in a hidden field later, the hardening named under Site determination. It was read in source, not exercised.

### Spike 4: scoping submissions by site (#37)

Core's submission listing, export, detail view and delete all read through the container-bound `Statamic\Contracts\Forms\SubmissionQueryBuilder` (`Form::querySubmissions()` and `FormSubmission::find()`). A probe bound a subclass of the core builder, built with the `form-submissions` store the way core's own binding builds it, that adds `(_site in the user's accessible sites) or _site is null` for a non-super user. It then made real in-process Control Panel requests as a user holding `access cp`, `view` and `delete` on the form and `access en site` but not `access es site`. Three submissions existed: `en`, `es` and unattributed.

| Request | Scope off (control) | Scope on |
| --- | --- | --- |
| `GET /cp/forms/{form}/submissions` | `a`, `b`, `c` (all three) | `a`, `c` |
| Detail view of the `es` submission | 200 | 404 |
| Detail view of the `en` submission | 200 | 200 |

The scoped builder reached the controllers with no controller override. Findings the table does not show:

- A plain `app()->bind(Contract, Subclass)` fails, because the core builder needs a `Store` and the container cannot supply one. The binding must be a closure that passes the `form-submissions` store, as core's does.
- The same builder serves the API, the `{{ form:submissions }}` tag and the forms index count, so the scope has to be conditioned on a Control Panel request and a non-super user. The probe conditioned on the current user only; a request-context condition is still to be designed.
- The forms index count (`$form->querySubmissions()->count()`) would also be scoped, which is the intended behavior and avoids leaking other sites' counts.
- The export was not requested. Without filters it reads `$form->submissions()`, which calls `FormSubmission::whereForm()`, which uses the same builder by reading the code; that is not a measurement.
- Delete was not requested. It resolves its submission through `$form->submission($id)`, which calls the same `find()` the detail view uses.

### Spike 5: a resolved form through the submit controller (#38)

A probe added a route middleware to the real `FormController@submit` route that, when switched on, replaced the bound `form` parameter with a clone whose `email` and `store` differed from the file. It then posted a submission through the full request stack with the queue set to `sync` and the mailer faked. The probe read the email config core handed to each mailable, because the fake mailer records a mailable before core's `build()` sets recipients.

| Run | Email config handed to the mailable | Submissions stored |
| --- | --- | --- |
| A, control: middleware off | `base@example.com` with subject `Base` | 1 |
| B: middleware swaps in the clone | `es@example.com` with subject `ES` | still 1 (nothing new stored) |

Also observed: the form file on disk kept `base@example.com` and `store: true`; the stored submission lives in the form's own folder (`spike_s`); the clone kept the base handle, so the action URL and the folder are unchanged.

Route middleware order for that route was `HandlePrecognitiveRequests`, `EncryptCookies`, `AddQueuedCookiesToResponse`, `StartSession`, `ShareErrorsFromSession`, `ThrottleRequests:statamic.forms`, `SubstituteBindings`, then the probe middleware. The swap ran after binding, which is the order the design needs.

Not covered, and each is a limit on this result:

- The probe attached its middleware to the route object. Attaching from an add-on, through a middleware group, was not tried. Group middleware is sorted by Laravel's priority list, so a class outside the list should land after `SubstituteBindings`, but that is reasoning, not a measurement.
- CSRF protection (`PreventRequestForgery`) was removed from the probe route to submit without a token. The design does not touch it.
- The first two attempts returned 419 and a mail check that saw no mail even in the control. Both instruments were fixed before any result was read, and the table above is from the run where the control produced the base recipient.

Follow-up measurement for #49: the same scope applied with `app()->extend()` on the contract, instead of a subclass, left core's `Statamic\Stache\Query\SubmissionQueryBuilder` bound and scoped every read path tried. With the extender off (control) a listing returned `a,b,c`, `find()` of the `es` submission found it and the count was 3; with it on, the listing returned `a,c`, `find()` returned null, the count was 2, and `whereForm()` (the export path) returned `a,c`. Switching the extender off returned the listing to `a,b,c`. Measured in a console, not through Control Panel requests.

### Spike 6: `_site` and the scope under the Eloquent submissions driver (#53)

Spikes 2 and 4 ran on the Stache. The host was switched temporarily to `statamic/eloquent-driver` v5.12.2 with `form_submissions.driver` set to `eloquent`, the driver's two submission migrations run on SQLite 3.51.3, and then restored. Four submissions were saved to a probe form through `$submission->save()`: `a` and `d` with `_site: en`, `b` with `_site: es`, and `c` with no `_site`. The form's submissions folder held no files afterwards and the table held four rows, so the probe read the database and not the Stache. A stored row's `data` column read `{"name":"a","_site":"en"}`.

| Query | Result |
| --- | --- |
| `where('form', ...)` alone | `a`, `b`, `c`, `d` |
| `where('_site', 'en')` | `a`, `d`; `count()` returns 2 |
| `where('_site', 'es')` | `b` |
| `whereIn('_site', ['en', 'es'])` | `a`, `b`, `d` |
| `whereNull('_site')` | `c` |
| The scope's nested `whereIn` or `whereNull` | `a`, `c`, `d` |
| Control: `where('_site', 'xx')` | none |

The driver's builder maps a column that is not on its model to a JSON path in `data`, so the scope compiled to `json_extract("data", '$."_site"') in (?) or json_extract("data", '$."_site"') is null`, and a missing key reads as null, which is what the unattributed rule needs.

The #49 scope was then applied with `app()->extend()` on the contract, for a user with access to `en` only. The bound class stayed `Statamic\Eloquent\Forms\SubmissionQueryBuilder`.

| Read | Extender off (control) | Extender on |
| --- | --- | --- |
| Listing | `a`, `b`, `c`, `d` | `a`, `c`, `d` |
| `find()` of `b` (the `es` submission) | found | null |
| `find()` of `a` | found | found |
| `count()` | 4 | 3 |
| `whereForm()` (the export path) | `a`, `b`, `c`, `d` | `a`, `c`, `d` |

Registration order, read in source: the driver binds the contract from its service provider's `register()`, and Laravel's `Container::bind()` drops a stale resolved instance but keeps the registered extenders, so an add-on's `extend()` from `boot()` applies on top of the driver's binding. In the probe the extender was registered after the driver had bound.

Not covered, and each is a limit on this result: MySQL and PostgreSQL, whose grammars compile the JSON path to their own functions; Control Panel requests (the reads were in a console); and query performance at volume, since a JSON path is not indexed. Measured on Windows 11.

### Spike 7: the resolved view under the Eloquent forms driver (#83)

`SiteForm` copies the properties core's `Form` declares, so a form class with more of its own would lose them in a view. The host was switched temporarily to `statamic/eloquent-driver` v5.12.2 (commit `5b8f88b9`) with `forms.driver` set to `eloquent` and the driver's forms migration run on SQLite, then restored. Submissions stayed on the file driver. The form contract was then bound to `Statamic\Eloquent\Forms\Form` and the repository to `Statamic\Eloquent\Forms\FormRepository`. That class extends core's `Form`, adds one property, `model`, and declares `fromModel`, `toModel`, `makeModelFromContract`, `model`, `save` and `delete`.

A probe form with a blueprint, an email, `store`, a honeypot, `sites: [en]` and one submission was saved, read back with `Form::find()`, and resolved for `en`. The form folder held no file and the `forms` table held the row, so the probe read the database. The same probe was run first on core's file driver as the control.

| Read, view against its base | File driver (control) | Eloquent forms driver |
| --- | --- | --- |
| Handle, title, email, store, honeypot, `sites` | same | same |
| Blueprint handle and fields | same | same |
| Action URL | same | same |
| `submissions()` and `querySubmissions()->count()` | same | same |
| `toAugmentedArray()` and `toArray()` | same | same |
| Resolved for `es` | null | null |
| A submission saved through the view | stored on the base form | stored on the base form |
| View is an instance of the bound class | yes (`Form`) | no |
| `model()` on the view | not defined on core's `Form` | throws `BadMethodCallException` |

Within the driver's forms code, `model()` is called on a form only in its own `save()` and `delete()`, which a view refuses, and its `Submission` reads only the form's handle. The gap reaches code outside the add-on through `form()` on a submission made through a view, which returns the view, so a listener that calls `model()` on it or checks for the driver's class fails under this driver alone. That is filed as a decision in #107 rather than changed here.

The host's Feature tests for the tags, the submit gate, `_site` recording, `form:assigned` and the Phase 1 exit criterion (`FormTagsTest`, `SubmitGateTest`, `RecordSubmitSiteTest`, `FormAssignedTest`, `PhaseOneExitTest`) were also run against the migrated database with the Eloquent forms driver on: 14 passed, and the forms they create were rows in the `forms` table.

Not covered: Control Panel requests on the form screens, the Eloquent submissions driver at the same time, MySQL and PostgreSQL. Measured on Windows 11 with `statamic/cms` v6.35.1 and PHP 8.4.20; macOS is not yet measured.

### Spike 8: the submission scope through Control Panel requests on the Eloquent driver (#65)

Spike 6 read the scope from a console. Here the host was switched temporarily to `statamic/eloquent-driver` v5.12.3 (commit `50c19165`) with `form_submissions.driver` set to `eloquent` and the driver's two submission migrations run on SQLite 3.51.3, and then restored. A probe test in the host sent real Control Panel requests through the host's middleware, as a user with `access cp`, `access en site` and `view {form} form submissions`, but not `access es site`. The form was assigned to `en` and `es`. Four submissions were saved through `$submission->save()`: `en` and `es` with that `_site`, `none` with no `_site`, and `blank`, whose row was then edited so that `data` held `"_site":""`, as a write around core would leave it (#130). The table held four rows and the form's submissions folder held no files, so the requests read the database. The bound builder was `Statamic\Eloquent\Forms\SubmissionQueryBuilder` in both columns.

The control removed the add-on's extender with `app()->forgetExtenders()` on the `SubmissionQueryBuilder` contract, in the same test, after the scoped requests.

| Request | Scope off (control) | Scope on |
| --- | --- | --- |
| `GET /cp/forms/{form}/submissions` | `en`, `es`, `none`, `blank` | `en` |
| Detail view of the `es` submission | 200 | 404 |
| Detail view of the `en` submission | 200 | 200 |
| Detail view of the unattributed submission | 200 | 404 |
| `GET /cp/forms/{form}/export/json` | `en`, `es`, `none`, `blank` | `en` |

The unattributed and empty-site submissions are hidden because the user does not cover every site the form is assigned to (#40, #130); spike 4 left the export unrequested, and on this driver it is scoped too. `multisite-forms:attribute {form} en` was then run on the same rows: the dry run listed `none` and `blank` and changed nothing, the real run wrote `"_site":"en"` into both rows' `data` and left `en` and `es` as they were, and a second run attributed 0 (#140).

Not covered: volume. MySQL and PostgreSQL are spike 9. Measured on Windows 11 with `statamic/cms` v6.35.1, Laravel 13.35.0 and PHP 8.4.20; macOS is not yet measured.

### Spike 9: `_site` and the scope on MySQL and PostgreSQL (#66)

Spikes 6 and 8 ran on SQLite. The host was switched temporarily to `statamic/eloquent-driver` v5.12.3 (commit `50c19165`) with `form_submissions.driver` set to `eloquent`, and the same probe test was run twice, once against MySQL 9.7.2 and once against PostgreSQL 18.0, each in a throwaway database that was dropped afterwards; the host was then restored. Each run printed the connection's driver, the server version and the database name, so neither could have fallen back to SQLite. Five submissions were saved to a form assigned to `en` and `es`: `a` and `d` with `_site: en`, `b` with `_site: es`, `c` with no `_site`, and `e`, whose row was edited to hold `"_site":""` (#130). Each table held five rows and the form's submissions folder held no files.

Both engines returned the same answers, which are also the answers spike 6 recorded on SQLite:

| Query | MySQL 9.7.2 | PostgreSQL 18.0 |
| --- | --- | --- |
| `where('form', ...)` alone | `a`, `b`, `c`, `d`, `e` | `a`, `b`, `c`, `d`, `e` |
| `where('_site', 'en')` | `a`, `d`; `count()` returns 2 | `a`, `d`; `count()` returns 2 |
| `where('_site', 'es')` | `b` | `b` |
| `whereIn('_site', ['en', 'es'])` | `a`, `b`, `d` | `a`, `b`, `d` |
| `whereNull('_site')` | `c` | `c` |
| `where('_site', '')` | `e` | `e` |
| Control: `where('_site', 'xx')` | none | none |

The driver's builder compiles the JSON path differently on each engine. On MySQL, `where` and `whereIn` read ``json_unquote(json_extract(`data`, '$."_site"'))``, and `whereNull` reads ``json_extract(`data`, '$."_site"') is null OR json_type(json_extract(`data`, '$."_site"')) = 'NULL'``. On PostgreSQL, both read `"data"->>'_site'`, with `is null` for `whereNull`. On both, a missing key reads as null and an empty string does not, which is what #130 relies on.

The scope was then exercised through Control Panel requests, as in spike 8, with the extender removed for the control:

| Request, as a user with `access en site` only | Scope off (control) | Scope on, both engines |
| --- | --- | --- |
| Listing | `a`, `b`, `c`, `d`, `e` | `a`, `d` |
| Detail view of `b` (`es`), through `find()` | 200 | 404 |
| Detail view of `a` (`en`) | 200 | 200 |
| Detail view of `c` (no site) | 200 | 404 |
| JSON export, through `whereForm()` | `a`, `b`, `c`, `d`, `e` | `a`, `d` |

A user with access to both sites listed `a`, `b`, `c` and `d`: the unattributed `c` shows, because they cover every site the form is assigned to, and `e`, with an empty site, stays hidden (#130). The scoped SQL was `(<_site path> in (?) or 0 = 1) and form = ?` for the `en`-only user, whose unattributed branch is empty, and `(<_site path> in (?, ?) or <_site path> is null) and form = ?` for the user covering both sites. `find()` added `and id = ?` to the same group. `multisite-forms:attribute {form} en` listed `c` and `e` in a dry run, attributed both, and attributed 0 on a second run, on each engine.

Not covered: MariaDB, which Laravel compiles with its own grammar; other server versions; volume and indexing a JSON path. Measured on Windows 11 with `statamic/cms` v6.35.1, Laravel 13.35.0 and PHP 8.4.20; macOS is not yet measured.
