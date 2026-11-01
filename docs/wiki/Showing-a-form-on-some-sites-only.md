Written against v0.6.1.

A form with no sites shows on every site. To show it on some sites only, assign it to those sites; the others then render the page without it.

## Assign the form

1. Open the form's settings in the Control Panel.
2. In the **Sites** section, choose the sites in the **Sites** field.
3. Save.

The form file lists the sites:

```yaml
# resources/forms/contact.yaml
sites:
  - en
```

To show the form on every site again, clear the **Sites** field.

## What the other sites show

On a site the form is not assigned to, the `{{ form }}` tags render nothing, and the rest of the page renders as usual. The log names the form and the site once per request:

```text
local.WARNING: Multisite Forms rendered nothing for a form not assigned to the page's site. {"form":"contact","site":"es"}
```

That suits a form placed in a shared layout or partial, which renders on every site while only some of them use it.

A submit to the form from such a site answers 404, even from a request made by hand.

## Answer with the site's 404 page instead

When a page that renders an unassigned form should not exist on that site at all, publish the config file:

```sh
php artisan vendor:publish --tag=aether-multisite-forms-config
```

Then set `not_assigned` to `404` in `config/aether-multisite-forms.php`:

```php
'not_assigned' => '404',
```

The page then answers with the site's 404 page on a site the form is not assigned to, and renders as before on the sites it is.

## Hide what surrounds the form

A heading or an introduction around the form still renders on a site where the form does not. Wrap it in the `form:assigned` tag; see [Checking a form's site in a template](Checking-a-forms-site-in-a-template.md).

## Reference

- [Configuration](Configuration.md#not_assigned): every value of `not_assigned`, and what each tag does.
- [Form assigned tag](Form-assigned-tag.md): `form:assigned`.
