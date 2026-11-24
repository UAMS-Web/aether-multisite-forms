Written against v0.8.2.

Where Statamic's REST API or GraphQL exposes forms, their form listings take an optional `site` filter, which lists only the forms assigned to that site, together with the forms that have no sites.

| Surface | Parameter | Example |
| --- | --- | --- |
| REST, `GET /api/forms` | `site` query parameter | `/api/forms?site=es` |
| GraphQL, `forms` query | `site` argument | `forms(site: "es")` |

```sh
curl 'https://example.com/api/forms?site=es'
```

```graphql
{
  forms(site: "es") {
    handle
    title
  }
}
```

## Behavior

- Without the filter, or with `site: null` in GraphQL, the listings are Statamic's.
- A `site` that names no configured site is an error rather than an empty list: the REST API answers 422 with a message, and GraphQL answers a `validation` error.
- The filter narrows only the forms the API already exposes.
- The single-form endpoint and query are unchanged.
- Submissions are not filtered.
