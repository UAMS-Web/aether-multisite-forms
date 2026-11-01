<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Forms\Multisite\GraphQL;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Statamic\Facades\GraphQL;
use Statamic\Forms\Form;
use Statamic\GraphQL\Queries\FormsQuery as CoreFormsQuery;
use UAMSWeb\Aether\Forms\Multisite\Access\SiteAccess;
use UAMSWeb\Aether\Forms\Multisite\Forms\Assignment;

/**
 * The GraphQL `forms` query, with an optional `site` argument (#80).
 *
 * `forms(site: "es")` lists only the forms assigned to `es`, including forms with
 * no sites, which belong to every site. Without the argument, or with it null, the
 * query is core's. A `site` that names no configured site fails validation, so a
 * typo answers an error rather than an empty list.
 *
 * The GraphQL library builds each query class through the container, so binding
 * this subclass in place of core's adds the argument without changing the schema
 * Statamic registers.
 */
final class FormsQuery extends CoreFormsQuery
{
    /**
     * Use this query wherever core's GraphQL forms query is built.
     *
     * @param  Application  $app  The container to bind the query in.
     */
    public static function register(Application $app): void
    {
        $app->bind(CoreFormsQuery::class, self::class);
    }

    /**
     * Core's arguments, of which there are none, and `site`.
     *
     * @return array<string, mixed> The query's arguments, by name.
     */
    #[\Override]
    public function args(): array
    {
        return [
            'site' => GraphQL::string(),
        ];
    }

    /**
     * Core's forms, narrowed to the requested site.
     *
     * @param  mixed  $root  The parent value, which a root query does not use.
     * @param  array<string, mixed>  $args  The query's arguments.
     * @return Collection<array-key, Form> The forms, narrowed to the site.
     */
    #[\Override]
    public function resolve($root, $args): Collection
    {
        // Resolve core's forms
        /** @var Collection<array-key, Form> $forms */
        $forms = parent::resolve($root, $args);

        // Return core's forms unchanged when no site is requested
        $site = $args['site'] ?? null;
        if (! is_string($site)) {
            return $forms;
        }

        // Keep only the forms assigned to the site
        return $forms->filter(static fn (Form $form): bool => Assignment::covers($form, $site))->values();
    }

    /**
     * `site`, when given and not null, must name a configured site.
     *
     * Required rather than nullable when present: Laravel skips its other rules
     * for an empty string, which would then filter to the forms with no sites.
     *
     * @param  array<string, mixed>  $args  The query's arguments.
     * @return array<string, mixed> The validation rules, by argument.
     */
    #[\Override]
    protected function rules(array $args = []): array
    {
        // Validate nothing when no site is requested
        if (($args['site'] ?? null) === null) {
            return [];
        }

        // Require the site to name a configured site
        return [
            'site' => ['required', Rule::in(SiteAccess::allHandles())],
        ];
    }
}
