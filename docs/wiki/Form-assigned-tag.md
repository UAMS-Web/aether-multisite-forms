Written against v0.8.2.

`form:assigned` answers whether a form renders on the page's site, so a template can hide what surrounds it, such as a heading or an introduction.

| Form | Result |
| --- | --- |
| Assigned to the page's site | true |
| With no sites | true |
| Assigned to other sites only | false |
| Does not exist | false |

It never aborts or logs, whatever [`not_assigned`](Configuration.md#not_assigned) says.

## Parameters

The tag names the form the way `form:create` does: with `in`, `handle`, `is`, `form` or `formset`, or from a form in the template's context.

## Antlers

Use it as a condition, or as a tag pair that renders its contents only when the form is assigned:

```antlers
{{ if {form:assigned in="contact"} }}
    <h2>Contact us</h2>
{{ /if }}

{{ form:assigned in="contact" }}
    <p>We reply within two working days.</p>
{{ /form:assigned }}
```

## Blade

Fetch it, or use the tag pair as a component:

```blade
@if (Statamic::tag('form:assigned')->params(['in' => 'contact'])->fetch())
    <h2>Contact us</h2>
@endif

<s:form:assigned in="contact">
    <p>We reply within two working days.</p>
</s:form:assigned>
```

## A form whose handle is `assigned`

Like Statamic's own `form:create` and `form:errors`, the tag's name takes precedence over a form whose handle is `assigned` in the `{{ form:assigned }}` shorthand; render such a form with `{{ form:create in="assigned" }}`.
