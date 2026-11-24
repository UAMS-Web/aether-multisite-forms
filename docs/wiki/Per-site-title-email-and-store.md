Written against v0.8.2.

A form shared by several sites can differ on each for three settings. Each site that differs gets one row under `site_overrides` in the form file; a site without a row, and any setting a row leaves out, uses the form's own value.

| Row key | Type | Effect |
| --- | --- | --- |
| `site` | site handle | The site the row applies to. |
| `title` | string | The form's title on that site. Empty uses the form's own title. |
| `email` | list of email configurations | Replaces the form's email list on that site rather than adding to it. Empty uses the form's own list. |
| `store` | `true` or `false` | Whether that site's submissions are stored. Also read from the strings `'true'` and `'false'` the Control Panel saves; anything else uses the form's setting. |

Other keys in a row are ignored. An empty `title` or `email` never clears the value for that site.

## Example

```yaml
# resources/forms/contact.yaml
title: Contact
store: true
email:
  - to: info@example.com
    subject: Website inquiry
sites: [en, es]
site_overrides:
  - site: es
    title: Contacto
    store: false
    email:
      - to: info-es@example.com
        subject: Consulta del sitio
```

Here a submission from the English site is stored and emailed to `info@example.com`; one from the Spanish site is emailed to `info-es@example.com` only and is not stored.

## In the Control Panel

The rows are edited under **Site overrides** in the Sites section of a form's settings. Each row picks one site and sets any of the three values; its email list has the same columns as the form's own. A row for a site the form is not assigned to, or a second row for the same site, is refused when saving.

Templates and the submit route see a form with its site's values applied; see [Site form view](Site-form-view.md).
