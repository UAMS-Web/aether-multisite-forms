Written against v0.8.2.

A form submit is made for one site. That site decides whether the submit is accepted, which site is recorded on the submission, which site's overrides apply, and which site the email is sent for. This page says how the site is named and where its limits are.

## How the site is named

The add-on takes the first of these that gives a site:

| Order | Source | Used when |
| --- | --- | --- |
| 1 | The signed `_site` field the form tag carries | The request has a `_site` value that verifies and names a configured site. |
| 2 | The site whose URL the request's referrer falls under | There is no usable `_site` value. |
| 3 | The default site | The request has no referrer, or one that falls under no site. |

Rules 2 and 3 are the rule Statamic uses on its own, so a form rendered without the add-on's tag submits as it always did.

That site then:

- **Gates the submit.** A submit to a form that is not assigned to the site answers 404, whatever [`not_assigned`](Configuration.md#not_assigned) says.
- **Is recorded** on the submission as `_site`; see [Control Panel access](Control-Panel-access.md#submissions).
- **Picks the overrides** the submission is built from; see [Per-site title, email and store](Per-site-title-email-and-store.md).
- **Is the site the email is sent for**, as described under [Email](#email).

## The signed field

The form tags (`form:create` and the `{{ form:contact }}` shorthand) add a hidden `_site` input right after the opening `<form>` tag:

```html
<input type="hidden" name="_site" value="eyJpdiI6..." />
```

Fetched with no template to render, as `Statamic::tag('form:create')->params(['in' => 'contact'])->fetch()` does in Blade or PHP, the returned data carries the same value as `params._site`, among Statamic's other `params`; a template that prints those parameters itself prints it too.

The value is the page's site handle, encrypted with the application key, so a changed byte fails to decrypt. The handle is not secret; the encryption only shows that the field was made by the form tag. The value depends on the page's site and the application key, and not on the visitor or the request.

A request is treated as follows:

| `_site` in the request | Result | Logged |
| --- | --- | --- |
| Verifies and names a configured site | That site decides | Nothing |
| Missing, empty, or not a string | The referrer decides | Nothing |
| Verifies, but names a site that is no longer configured | The referrer decides | Nothing |
| Fails to decrypt: edited, made by hand, or made under another application key | The referrer decides | A warning |

The warning reads:

```text
local.WARNING: Multisite Forms rejected a submit site whose signature failed; the referrer decides the site.
```

A page cached before an application key change keeps the old value, which then fails to decrypt until the page is cached again.

## Email

Statamic works out the email's site from the referrer alone. The add-on changes the email job before it builds its emails, so the email's locale and the site globals it sees follow the site recorded on the submission, which is the signed field's site when there is one. A browser that sends no referrer, or one on another site's page, therefore no longer decides the email's site.

A submission with no recorded site, or one whose recorded site is no longer configured, keeps the site Statamic chose. The change applies whichever class sends the email, including one a host or another add-on supplies.

## Limits

The signed value can be read from any page of the site it names, so someone who crafts a request can still submit as any site the form is assigned to, as visiting that site's page already allows. The signature stops a request from naming a site by editing the field, and stops a browser that hides its referrer from sending every submission to the default site. Assignment is not a boundary against a crafted request.

## Reference

- [Configuration](Configuration.md): `not_assigned`, and what the form tags do on an unassigned site.
- [Troubleshooting](Troubleshooting.md#a-submit-site-signature-fails): the warning above.
