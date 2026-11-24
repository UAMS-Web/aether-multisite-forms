Written against v0.8.2.

A front end built on Statamic's REST API or GraphQL can ask for the forms of one site rather than every form. Pass the site's handle as the `site` filter on the form listing; the result is the forms assigned to that site, together with the forms that have no sites.

## Expose forms

Both APIs leave forms off by default. Enable the API, and its `forms` resource:

```php
// config/statamic/api.php
'enabled' => env('STATAMIC_API_ENABLED', false),
'resources' => [
    // ...
    'forms' => true,
],
```

```php
// config/statamic/graphql.php
'enabled' => env('STATAMIC_GRAPHQL_ENABLED', false),
'resources' => [
    // ...
    'forms' => true,
],
```

Then set `STATAMIC_API_ENABLED=true` or `STATAMIC_GRAPHQL_ENABLED=true` in `.env`. Statamic's [REST API](https://statamic.dev/rest-api) and [GraphQL](https://statamic.dev/graphql) documentation covers the rest of the setup.

## List a site's forms through REST

With three forms (`contact` assigned to English and Spanish, `english` assigned to English only, and `everywhere` with no sites):

```sh
curl 'https://example.com/api/forms?site=es'
```

The response lists `contact` and `everywhere`. Without `?site=`, it lists all three, as Statamic does.

## List a site's forms through GraphQL

```graphql
{
  forms(site: "es") {
    handle
    title
  }
}
```

The result is the same two forms.

## A site that does not exist

A `site` that names no configured site is an error rather than an empty list, so a typo in a handle does not read as a site with no forms:

- REST answers 422: `{"message":"The site filter names no configured site."}`
- GraphQL answers an error whose message is `validation`.

## What the filter does not change

The single-form endpoint (`/api/forms/contact`) and the `form` query, and submissions, are as Statamic serves them. A form's title there is the form's own, not a site's; to read a form with a site's values, see [Reading a form as a site sees it](Reading-a-form-as-a-site-sees-it.md).

## Reference

- [API site filter](API-site-filter.md): the parameter on each surface, and its behavior.
