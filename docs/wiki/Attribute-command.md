Written against v0.8.2.

`multisite-forms:attribute` records a site on a form's submissions that have none. A submission with no recorded site, such as one saved before the add-on was installed or by code that saves a submission itself, shows in the Control Panel only to users who can access every site its form is assigned to (see [Control Panel access](Control-Panel-access.md)). Recording a site shows it to that site's editors.

```sh
php please multisite-forms:attribute contact en --dry-run   # list what would change
php please multisite-forms:attribute contact en
```

| Argument or option | Meaning |
| --- | --- |
| `form` | The form's handle. Matched exactly, including case. |
| `site` | The site to record. Matched exactly, including case. It must be one the form is assigned to; a form with no sites takes any site. |
| `--dry-run` | Print the submissions that would change, and change nothing. |

## What it does

The command records the site on every submission of the form that has none, and leaves submissions that already record a site alone. It prints each submission it changes and a count, and logs each change at `info` level with the form, the submission's id and the site.

An unknown form or site, or a site the form is not assigned to, is an error and changes nothing.

Submissions are saved quietly, so no submission event fires, and no email is sent, as for any save outside the submit route.

A submission whose file records an empty site (`_site: ''`), which only a file written outside Statamic, such as a direct import, can hold, shows only to super users; the command counts it as unattributed and records the site in its place.

It works with submissions in flat files or in the database through `statamic/eloquent-driver`.
