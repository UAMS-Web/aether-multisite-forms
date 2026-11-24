Written against v0.8.2.

An editor who looks after one site should see that site's forms and submissions, and not the others'. The add-on adds no permissions for this: it reads the site access the editor's role already grants.

## Create a role for the site

1. In the Control Panel, open **Users**, then **Roles**, and create a role, such as **Spanish editor**.
2. Grant **Access the Control Panel** and the site's **Access Spanish (es) site**.
3. Grant **View Contact submissions** for each form the editor works with, and **Delete Contact submissions** if they should delete them.
4. Save the role, and assign it to the editor.

The role file holds the same permissions:

```yaml
# resources/users/roles.yaml
spanish_editor:
  title: 'Spanish editor'
  permissions:
    - 'access cp'
    - 'access es site'
    - 'view contact form submissions'
```

## What the editor sees

Signed in with that role, on a site with a Contact form assigned to both English and Spanish:

- The forms index lists the forms they can view, leaving out any form assigned only to sites they cannot access.
- The Contact form's submissions listing shows the submissions from the Spanish site only. Its export, a submission's detail view and the count on the forms index follow the same rule.
- There is no **Site** filter: it appears only when the editor can access at least two of the form's sites. A super user, or a role with both sites, gets it, and choosing a site narrows the listing and the export to that site.
- A submission saved before the add-on was installed has no site, so it shows only to someone who can access every site the form is assigned to. [Attributing submissions from before the add-on](Attributing-submissions-from-before-the-add-on.md) gives such submissions a site.

## Letting the editor change forms

**Configure forms** lets a role create and edit forms. The add-on narrows it by site:

- The editor can edit the settings and fields of a form assigned only to sites they can access. A form shared with a site they cannot access, or a form with no sites, needs access to every site, since a change reaches all of them, and is refused.
- A form the editor creates is assigned to the sites they can access. An editor with the Spanish site only gets:

```yaml
# resources/forms/editor_made.yaml
sites:
  - es
title: 'Editor Made'
```

Only super users bypass these rules.

## Reference

- [Control Panel access](Control-Panel-access.md): every rule for forms, submissions and the Site filter.
- [Attribute command](Attribute-command.md): recording a site on older submissions.
