Written against v0.8.2.

A form assigned to some sites renders nothing on the others, but the heading or introduction around it still renders. The `form:assigned` tag tells a template whether the form renders on the page's site, so it can hide those, or show something else in their place.

## In Antlers

Use the tag as a condition, or as a pair that renders its contents only where the form does:

```antlers
{{ if {form:assigned in="contact"} }}
    <h2>Contact us</h2>
{{ /if }}

{{ form:assigned in="contact" }}
    <p>We reply within two working days.</p>
{{ /form:assigned }}

{{ unless {form:assigned in="contact"} }}
    <p>Write to us at info@example.com.</p>
{{ /unless }}

{{ form:create in="contact" }}
    ...
{{ /form:create }}
```

With the form assigned to the English site only, the English page shows the heading, the reply time and the form; the Spanish page shows only the line pointing to the email address.

## In Blade

Fetch the tag for a condition, or use the pair as a component:

```blade
@if (Statamic::tag('form:assigned')->params(['in' => 'contact'])->fetch())
    <h2>Contact us</h2>
@endif

<s:form:assigned in="contact">
    <p>We reply within two working days.</p>
</s:form:assigned>

@unless (Statamic::tag('form:assigned')->params(['in' => 'contact'])->fetch())
    <p>Write to us at info@example.com.</p>
@endunless
```

## When the page should not render at all

`form:assigned` never aborts a page or logs anything. To answer with the site's 404 page wherever the form is not assigned, set `not_assigned` instead; see [Showing a form on some sites only](Showing-a-form-on-some-sites-only.md#answer-with-the-sites-404-page-instead).

## Reference

- [Form assigned tag](Form-assigned-tag.md): every result, and the ways to name the form.
- [Configuration](Configuration.md#not_assigned): what the `{{ form }}` tags do on a site the form is not assigned to.
