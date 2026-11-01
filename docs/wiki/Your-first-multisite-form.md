Written against v0.6.1.

By the end of this page you will have a contact form that shows on one site, then on two, with each site's submissions stored apart and each site's email going to its own recipient.

It assumes the add-on is installed (see [Installation (README)](https://github.com/UAMS-Web/aether-multisite-forms#installation)) in a multisite install with two sites: English (`en`, at `/`) and Spanish (`es`, at `/es/`). Use your own site handles where these appear.

## 1. Create a form

In the Control Panel, create a form named **Contact** (handle `contact`) with **Name**, **Email** and **Message** fields, and an email to `info@example.com`. Statamic's [forms documentation](https://statamic.dev/frontend/forms) covers this step; nothing about it is specific to the add-on.

The form file, `resources/forms/contact.yaml`, now holds the form's title and email, and no sites.

## 2. Render it on a page both sites use

Put the form on a template that both sites render, as you would on a single site:

```antlers
{{ form:create in="contact" }}
    {{ fields }}
        <label>{{ display }} {{ field }}</label>
    {{ /fields }}
    <button type="submit">Send</button>
{{ /form:create }}
```

Visit the page on each site. The form shows on both, because a form with no sites belongs to every site.

## 3. Assign it to the English site

Open the form's settings. In the **Sites** section, choose **English** in the **Sites** field, and save.

The form file now lists the site:

```yaml
sites:
  - en
```

Visit the page again:

- On the English site, the form shows.
- On the Spanish site, the page renders without the form, and the log records why:

```text
local.WARNING: Multisite Forms rendered nothing for a form not assigned to the page's site. {"form":"contact","site":"es"}
```

**Note:** to answer with the site's 404 page instead, set `not_assigned` to `404`; see [Configuration](Configuration.md).

## 4. Submit from the English site

Fill in the form on the English page and send it. The submission is stored, and its file under `storage/forms/contact/` records the site it came from:

```yaml
_site: en
```

A submit to the form from the Spanish site answers 404, even from a request made by hand, since the form is not assigned there.

## 5. Share it with the Spanish site, with its own recipient

Open the form's settings again:

1. In the **Sites** field, add **Spanish**.
2. Under **Site overrides**, choose **Add site override**, pick **Spanish**, set the title to `Contacto`, and add an email to `info-es@example.com`.
3. Save.

The form file now carries the override row:

```yaml
sites:
  - en
  - es
site_overrides:
  -
    site: es
    title: Contacto
    email:
      -
        to: info-es@example.com
        subject: 'Consulta del sitio'
```

The form now shows on both pages. Send one submission from each:

- The Spanish one is emailed to `info-es@example.com` and stored with `_site: es`.
- The English one is emailed to `info@example.com` and stored with `_site: en`.

A site's row replaces only what it sets; everything else comes from the form. See [Per-site title, email and store](Per-site-title-email-and-store.md) for every setting a row can hold.

## 6. See each site's submissions

Open the form's submissions in the Control Panel. A super user sees all three, and a **Site** filter narrows the listing, and an export, to one site. An editor who can access only the Spanish site sees only the Spanish submission. [Control Panel access](Control-Panel-access.md) describes who sees what.

## Next

- [Form assigned tag](Form-assigned-tag.md): hide a heading or introduction on a site where the form does not show.
- [Attribute command](Attribute-command.md): give submissions saved before the add-on a site.
- [Home](Home.md): the guides by role, and the rest of the reference.
