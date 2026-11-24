Written against v0.8.2.

Submissions saved before the add-on was installed record no site, so in the Control Panel they show only to people who can access every site their form is assigned to. When you know which site they came from, record it, and that site's editors see them.

## Record the site

1. List what would change, without changing it:

   ```sh
   php please multisite-forms:attribute contact es --dry-run
   ```

   ```text
   Would attribute 1760000003.3

    INFO  Would attribute 1 submission of [contact] to [es].
   ```

2. Run it:

   ```sh
   php please multisite-forms:attribute contact es
   ```

   ```text
   Attributed 1760000003.3

    INFO  Attributed 1 submission of [contact] to [es].
   ```

The submission's file now records the site:

```yaml
# storage/forms/contact/1760000003.3.yaml
name: legacy
_site: es
```

An editor limited to the Spanish site now sees it in the Contact form's submissions. Submissions that already record a site are left alone, and no email is sent.

## When the submissions came from several sites

The command records one site on every submission of the form that has none. If older submissions came from different sites, choose the site whose editors should handle them, or leave them unattributed: super users, and anyone who can access every site the form is assigned to, still see them.

## When it refuses

The site must be one the form is assigned to, and both handles must match exactly:

```text
 ERROR  There is no site [fr].
 ERROR  The form [newsletter] is not assigned to the site [es].
```

Nothing changes when it refuses.

## Reference

- [Attribute command](Attribute-command.md): the arguments, what it logs, and empty sites in imported files.
- [Control Panel access](Control-Panel-access.md): who sees a submission with no site.
