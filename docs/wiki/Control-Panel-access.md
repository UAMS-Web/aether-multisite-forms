Written against v0.8.2.

Access to forms in the Control Panel follows the site access a user already has, through core's `access {site} site` permissions; there are no new permission keys.

## Forms

- A form assigned to sites is hidden from, and refused to, a user who can access none of them. Its submissions are refused the same way.
- Editing a form's settings, deleting it, or editing its fields needs access to every site it is assigned to, or to every site for a form with no sites, because each change reaches all of them.
- The form fields editor is refused for a form the user cannot change, by the same rule as editing its settings. The blueprints index, the command palette and the editor's breadcrumb leave out the forms whose editor the user cannot open.
- `configure forms` does not bypass either rule. Only super users do, which narrows what core allows that permission.
- A form created by a user who cannot access every site is assigned to the sites they can access, so they can still edit it. A form that already lists sites when it is created, such as a duplicate of an assigned form, keeps them.
- Core's Duplicate action is refused for a form the user cannot view, and is not offered for one. A copy keeps its source's sites.
- A form whose `sites` names a site no longer configured is open to super users only.

## Submissions

- Within a form a user can open, the submissions listing, a submission's detail view, deleting one, the export, and the count on the forms index show only submissions recorded for a site the user can access.
- A submission with no recorded site, such as one saved before the add-on was installed, shows only to a user who can access every site its form is assigned to, or every site for a form with no sites. [The attribute command](Attribute-command.md) records a site on such submissions.
- Deleting a form still deletes every one of its submissions.
- The API, the `form:submissions` tag and console commands are not scoped.

## The Site filter

A **Site** filter on the submissions listing narrows it, and an export made with it applied, to the submissions recorded for one site. It offers the sites the user can access that the form is assigned to, and appears only when there are at least two. A chosen site leaves out submissions with no recorded site.
