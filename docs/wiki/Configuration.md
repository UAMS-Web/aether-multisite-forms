The add-on has one setting, `not_assigned`. Publish the config file to change it:

```sh
php artisan vendor:publish --tag=aether-multisite-forms-config
```

That writes `config/aether-multisite-forms.php`.

| Key | Values | Default |
| --- | --- | --- |
| `not_assigned` | `empty`, `404` | `empty` |

## `not_assigned`

What the `{{ form }}` tags (`form:create`, the `{{ form:contact }}` shorthand, `form:errors`, `form:success`, `form:submission` and `form:submissions`) do with a form that is not assigned to the site the page is rendered for:

| Value | Effect |
| --- | --- |
| `empty` (default) | Renders nothing, and `form:submissions` renders as it does for a form with no submissions, so a `no_results` branch still shows. A warning naming the form and the site is logged at `warning` level, once per form and site per request, so a shared layout that renders the form on every page logs one line per page view rather than one per tag. |
| `404` | Aborts the request with the site's 404 page, as Statamic's `{{ 404 }}` tag does. If the 404 page's layout renders the same form, it renders nothing there rather than aborting again. |

The setting is checked each time the add-on boots, for every request and `artisan` command: any other value, including `null` from an unset `env()` variable, is logged at `error` level naming the setting and the value. A page that meets an unassigned form with such a value then fails with the same message, since the add-on cannot tell which behavior was meant. Pages with no unassigned form are unaffected.

`form:fields` inside `form:create` needs no gate of its own: it renders only the fields `form:create` hands it.

A form assigned to the page's site, and a form with no sites, render as Statamic renders them. A submit to a form from a site it is not assigned to always answers 404, whatever `not_assigned` says.

A submit's site is the site whose URL the request's referrer falls under, or the default site when there is none, the same rule Statamic uses to choose a submission's email locale. That site decides the 404, the site recorded on the submission, and the site's overrides. The referrer is sent by the browser, so this decides which forms a site offers and accepts; it does not stop someone who crafts a request from submitting as any site the form is assigned to.

### Fetching `form:create` without a template

Fetched with no template to render, as `Statamic::tag('form:create')->params(['in' => 'contact'])->fetch()` does in Blade or PHP, `form:create` returns the form's data as an array, as Statamic's does. Under `empty`, an unassigned form returns an empty array instead, so guard the markup that reads it:

```blade
@php($form = Statamic::tag('form:create')->params(['in' => 'contact'])->fetch())
@if ($form)
    <form method="{{ $form['attrs']['method'] }}" action="{{ $form['attrs']['action'] }}">
        ...
    </form>
@endif
```

Without the guard, reading `$form['attrs']` fails on a site the form is not assigned to.

To hide what surrounds a form on such a site, such as a heading, use [the `form:assigned` tag](Form-assigned-tag.md).
