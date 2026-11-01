# Multisite Forms for Statamic

A Statamic add-on that makes forms per-site in a multisite install: site assignment, per-site overrides, and site-separated submissions.

The documentation is in the [wiki](https://github.com/UAMS-Web/aether-multisite-forms/wiki): the reference for every setting, tag, command and permission, and guides as they are written.

## Status

Early development: releases are 0.x pre-releases, published on [Packagist](https://packagist.org/packages/uams-web/aether-multisite-forms). Until 1.0.0, a minor release (0.6 to 0.7) can carry a breaking change; its release notes call it out first, with the action to take. Everything under "What it does" ships today. It is free, has no editions, and is licensed under MIT. What 1.0.0 waits on is listed in [#180](https://github.com/UAMS-Web/aether-multisite-forms/issues/180).

## What it does

- **Site assignment:** a Sites field in a form's settings chooses the sites the form belongs to; left empty, the form belongs to every site. On any other site the form tags render nothing or a 404, as [configured](https://github.com/UAMS-Web/aether-multisite-forms/wiki/Configuration), and a submit answers 404. Each new submission records the site it was made from.
- **Per-site overrides:** a site can have its own title, email recipients and store setting for a shared form, edited in the form's settings. See [Per-site title, email and store](https://github.com/UAMS-Web/aether-multisite-forms/wiki/Per-site-title-email-and-store).
- **Site-separated submissions:** in the Control Panel, a user sees the forms and submissions of the sites they can access, through core's site permissions, with a Site filter on the submissions listing and export. A command records a site on submissions saved before the add-on. See [Control Panel access](https://github.com/UAMS-Web/aether-multisite-forms/wiki/Control-Panel-access) and the [attribute command](https://github.com/UAMS-Web/aether-multisite-forms/wiki/Attribute-command).
- **For templates and code:** a [`form:assigned` tag](https://github.com/UAMS-Web/aether-multisite-forms/wiki/Form-assigned-tag) to check a form's site, a [`site` filter](https://github.com/UAMS-Web/aether-multisite-forms/wiki/API-site-filter) on the API's form listings, and a [read-only view of a form](https://github.com/UAMS-Web/aether-multisite-forms/wiki/Site-form-view) for the page's site.

## Requirements

- PHP 8.4 or later
- Statamic (`statamic/cms`) 6.35 or later, in a multisite install
- Statamic Pro, which Statamic requires for more than one site: without it, a multisite install stops with `Statamic Pro is required to use multiple sites.` The add-on itself needs no license.
- Submissions in Statamic's flat files, or in a database through `statamic/eloquent-driver`. The database driver is verified on SQLite, MySQL 9.7.2 and PostgreSQL 18.0; MariaDB is not verified yet.

## Installation

Require the package in the host application:

```sh
composer require uams-web/aether-multisite-forms:^0.6
```

`^0.6` stays on 0.6 releases, so a breaking 0.7 is never installed by a `composer update`. No build step is needed: the Control Panel assets ship built.

Statamic discovers the add-on from the package, and it appears under Addons in the Control Panel. After a later `composer update`, `php artisan statamic:install` publishes its assets.

## Configuration

The add-on has one setting. Publish the config file to change it:

```sh
php artisan vendor:publish --tag=aether-multisite-forms-config
```

That writes `config/aether-multisite-forms.php`.

| Key | Values | Default |
| --- | --- | --- |
| `not_assigned` | `empty` renders nothing and logs a warning; `404` aborts with the site's 404 page | `empty` |

It decides what the form tags do with a form that is not assigned to the page's site. The [Configuration](https://github.com/UAMS-Web/aether-multisite-forms/wiki/Configuration) page has the details.

## Quick start

1. In the Control Panel, open a form's settings and choose its sites in the **Sites** field.
2. Render it as usual, for example with `{{ form:create in="contact" }} ... {{ /form:create }}`. On a site the form is not assigned to, the tags follow `not_assigned`.
3. To give one site its own title, recipients or store setting, add a row under **Site overrides** in the same section.

New submissions record their site, and editors limited to some sites see only those sites' submissions.

## External requests

None. The add-on calls no outside service and collects no data. A form's emails go only to the recipients its settings, or a site's override row, name.

## Upgrading from `statamic-multisite-forms`

The add-on was renamed to `aether-multisite-forms`. A site that installed the old package changes the following:

1. Replace the package: `composer remove uams-web/statamic-multisite-forms`, then `composer require uams-web/aether-multisite-forms`. The old package is marked abandoned with the new one named as its replacement.
2. Rename a published `config/statamic-multisite-forms.php` to `config/aether-multisite-forms.php`. The publish tag is now `aether-multisite-forms-config`, and the configuration key is `aether-multisite-forms.not_assigned`.
3. Re-run `php artisan statamic:install` so the Control Panel assets publish to `public/vendor/aether-multisite-forms`.
4. Code that references `UAMSWeb\MultisiteForms\` uses `UAMSWeb\Aether\Forms\Multisite\` instead.

The `form:assigned` tag, the `multisite-forms:attribute` command and the stored data (the `_site` value on each submission and the per-site rows in a form's settings) keep their names. The add-on declares no permissions and no settings blueprint of its own, so there are none to migrate.

## Changelog

Each release's notes are on the [releases page](https://github.com/UAMS-Web/aether-multisite-forms/releases), with any breaking change first.

## Support

Community support through the [issue tracker](https://github.com/UAMS-Web/aether-multisite-forms/issues); there is no paid support. [Troubleshooting](https://github.com/UAMS-Web/aether-multisite-forms/wiki/Troubleshooting) covers the problems met so far.

## Development

```sh
composer install
composer ci    # Pint, Rector dry run, Larastan, Pest (Unit and Integration)
```

[`docs/DEVELOPMENT.md`](docs/DEVELOPMENT.md) describes the development loop against a real multisite host, and the project conventions are in [`AGENTS.md`](AGENTS.md). The wiki's pages are written in [`docs/wiki/`](docs/wiki/) and published from there.

## License

MIT. See [`LICENSE`](LICENSE).
