Written against v0.6.1.

One entry per symptom, as you meet it: the message or behavior, its cause, and the fix.

- [A form does not show on one site](#a-form-does-not-show-on-one-site)
- [A page answers 404 on one site](#a-page-answers-404-on-one-site)
- [A form submit answers 404](#a-form-submit-answers-404)
- [The not_assigned setting must be "empty" or "404"](#the-not_assigned-setting-must-be-empty-or-404)
- [Multisite Forms could not gate form submits by site](#multisite-forms-could-not-gate-form-submits-by-site)
- [Saving a form's Sites is refused](#saving-a-forms-sites-is-refused)
- [Saving a form's Site overrides is refused](#saving-a-forms-site-overrides-is-refused)
- [An editor cannot see some submissions](#an-editor-cannot-see-some-submissions)
- [Editing a form's settings is refused](#editing-a-forms-settings-is-refused)
- [There is no Site filter on the submissions listing](#there-is-no-site-filter-on-the-submissions-listing)
- [The site filter names no configured site](#the-site-filter-names-no-configured-site)
- [A form resolved for a site is read-only](#a-form-resolved-for-a-site-is-read-only)
- [Cannot scope submissions by site](#cannot-scope-submissions-by-site)
- [Fixed](#fixed)

## A form does not show on one site

The page renders, without the form, and the log holds:

```text
local.WARNING: Multisite Forms rendered nothing for a form not assigned to the page's site. {"form":"contact","site":"es"}
```

**Cause:** the form is assigned to other sites only.

**Fix:** if the form belongs on that site, add the site to the form's **Sites** field. If it does not, the page is behaving as intended: hide what surrounds the form with [`form:assigned`](Checking-a-forms-site-in-a-template.md), or answer with the site's 404 page instead (see [Showing a form on some sites only](Showing-a-form-on-some-sites-only.md#answer-with-the-sites-404-page-instead)).

## A page answers 404 on one site

**Cause:** `not_assigned` is set to `404`, and the page renders a form that is not assigned to that site.

**Fix:** assign the form to the site, or set `not_assigned` back to `empty` to render the page without the form. See [Configuration](Configuration.md#not_assigned).

## A form submit answers 404

A submit from one site answers 404 while the same form submits from another. The exception behind the 404 reads `Form [contact] not found.`

**Cause:** the form is not assigned to the site the submit came from. This holds whatever `not_assigned` says.

**Fix:** add the site to the form's **Sites** field.

## The not_assigned setting must be "empty" or "404"

The log holds, on every request and `artisan` command:

```text
local.ERROR: The aether-multisite-forms.not_assigned setting must be "empty" or "404"; got NULL.
```

A page with a form not assigned to its site then fails with the same message.

**Cause:** `not_assigned` in `config/aether-multisite-forms.php` holds another value. `got NULL` is most often an `env()` call whose variable is not set, such as `env('MSF_NOT_ASSIGNED')`.

**Fix:** set it to `'empty'` or `'404'`, or give the `env()` call a default: `env('MSF_NOT_ASSIGNED', 'empty')`.

## Multisite Forms could not gate form submits by site

```text
local.WARNING: Multisite Forms could not gate form submits by site: statamic.routes.middleware names no middleware group, so forms accept submits from every site.
```

**Cause:** `middleware` in `config/statamic/routes.php` names no middleware group, such as an empty list. The add-on attaches its submit check to the groups named there, so with none, a submit from a site the form is not assigned to is not refused.

**Fix:** name the group Statamic's routes run in, which is `'web'` by default:

```php
// config/statamic/routes.php
'middleware' => 'web',
```

## Saving a form's Sites is refused

Saving a form's settings shows one of these under **Sites**:

```text
Choose the sites this form belongs to. Leaving Sites empty would put it on every site, including sites you cannot access.
This form cannot be assigned to a site you cannot access: English.
The Sites field must list only the sites this install defines.
```

**Cause:** in order, an editor who cannot access every site cleared the field; an editor chose a site they cannot access; or the form file names a site that is not configured.

**Fix:** choose only the sites you can access, and at least one. A form for every site, or for a site you cannot access, needs someone who can access those sites. See [Control Panel access](Control-Panel-access.md).

## Saving a form's Site overrides is refused

Saving shows one of these under **Site overrides**:

```text
Spanish has an override row but is not one of this form's sites.
Spanish has more than one override row. Keep one row per site.
An override row names [fr], which is not a site this install defines.
```

**Cause:** a row is for a site missing from the form's **Sites** field; two rows are for the same site; or a row in the form file names a site that is not configured.

**Fix:** add the site to **Sites**, or remove the row; keep one row per site, merging the two. See [Per-site title, email and store](Per-site-title-email-and-store.md).

## An editor cannot see some submissions

An editor sees some of a form's submissions, and someone with more sites sees more.

**Cause:** the editor sees only submissions recorded for a site they can access. A submission saved before the add-on was installed records no site, and shows only to someone who can access every site the form is assigned to.

**Fix:** if the missing submissions are from before the add-on, record their site; see [Attributing submissions from before the add-on](Attributing-submissions-from-before-the-add-on.md). Otherwise they are another site's, and the editor needs that site's access to see them.

## Editing a form's settings is refused

An editor with **Configure forms** is refused a form's settings or fields with a 403.

**Cause:** the form is assigned to a site the editor cannot access, or to no sites, which means every site. A change to the form reaches all of its sites.

**Fix:** have someone who can access all of the form's sites make the change. See [Giving editors access by site](Giving-editors-access-by-site.md#letting-the-editor-change-forms).

## There is no Site filter on the submissions listing

**Cause:** the filter appears only when the user can access at least two of the form's sites. With one, the listing already shows only that site.

**Fix:** none needed. A user with access to more of the form's sites gets the filter.

## The site filter names no configured site

`GET /api/forms?site=fr` answers 422:

```json
{"message":"The site filter names no configured site."}
```

GraphQL's `forms(site: "fr")` answers an error whose message is `validation`.

**Cause:** the `site` value is not the handle of a configured site. Handles match exactly, including case.

**Fix:** pass a configured site's handle. See [API site filter](API-site-filter.md).

## A form resolved for a site is read-only

```text
The [contact] form resolved for the [es] site is read-only and cannot be saved. Resolve nothing and use Form::find('contact') to change the form itself.
```

**Cause:** code saved or deleted a form returned by `FormResolver`, or by `$submission->form()` in a submission listener. Saving it would write one site's values into the form file for every site.

**Fix:** change the stored form, from `Form::find('contact')` or the view's `base()`. See [Reading a form as a site sees it](Reading-a-form-as-a-site-sees-it.md#changing-the-form-itself).

## Cannot scope submissions by site

```text
Cannot scope submissions by site: the bound submission query builder [App\Forms\SubmissionQuery] extends neither of the core query builders.
```

**Cause:** the app, or a package, binds Statamic's submission query builder to a class that extends neither of Statamic's query builders. The add-on cannot limit such a builder by site, so when a user who is not a super user reads submissions in the Control Panel, it refuses rather than show them unscoped.

**Fix:** extend the bound builder from `Statamic\Query\Builder`, which Statamic's own Stache builders extend, or from `Statamic\Query\EloquentQueryBuilder` for a builder over Eloquent models. A subclass of either is scoped.

## Fixed

No problem has been fixed in a release yet. When one is, its entry moves here with the release that fixed it.
