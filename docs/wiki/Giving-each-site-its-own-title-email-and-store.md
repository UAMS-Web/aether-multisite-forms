Written against v0.8.2.

A form shared by several sites can have a different title, email and storage choice on each. Add a row for each site that differs; a site without a row uses the form's own settings.

## Add a site's row

1. Open the form's settings in the Control Panel.
2. Under **Site overrides** in the **Sites** section, choose **Add site override**.
3. Pick the **Site**, and set any of **Title**, **Store Submissions** (**Store** or **Don't store**) and **Email**. Leave a setting empty to keep the form's own.
4. Save.

The site must be one the form is assigned to, and each site can have one row; saving refuses anything else.

The form file carries the row:

```yaml
# resources/forms/contact.yaml
title: Contact
store: true
email:
  -
    to: info@example.com
    subject: 'Website inquiry'
    html: emails/contact
sites:
  - en
  - es
site_overrides:
  -
    site: es
    title: Contacto
    store: false
    email:
      -
        to: info-es@example.com
        subject: 'Consulta del sitio'
        html: emails/contact
```

A row saved in the Control Panel also carries an `id` on the row and on each email, which the Control Panel uses to track them, and writes `store` as the text `'true'` or `'false'`. Leave both as they are: the add-on reads `'false'` the same as `false`, and a row written by hand needs no `id`.

## What each site then does

With the file above:

- A submission from the Spanish site is emailed to `info-es@example.com` only, with the subject `Consulta del sitio`, and is not stored.
- A submission from the English site is emailed to `info@example.com` and stored.

**Note:** a row's email list replaces the form's list on that site rather than adding to it. To keep sending to the form's recipients from that site, list them in the row too.

The email is built for the site recorded on the submission, so its locale and the site globals it reads follow that site; see [Submit site](Submit-site.md#email).

## Show the site's title

An email template sees the form with its site's values, so `{{ form:title }}` gives the site's title. In `resources/views/emails/contact.antlers.html`, the view both email lists above name:

```antlers
<p>A new message from the {{ form:title }} form.</p>
```

The Spanish site's email reads `A new message from the Contacto form.`; the English site's reads `A new message from the Contact form.`

## Reference

- [Per-site title, email and store](Per-site-title-email-and-store.md): every key a row can hold, and how each is read.
- [Site form view](Site-form-view.md): the form with its site's values, as templates and listeners receive it.
