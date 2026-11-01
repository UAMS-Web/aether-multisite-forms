<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Forms\Multisite\Access;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use LogicException;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Forms\Submission;
use Statamic\Contracts\Forms\SubmissionQueryBuilder;
use Statamic\Events\FormDeleted;
use Statamic\Events\FormDeleting;
use Statamic\Events\FormSaved;
use Statamic\Events\RoleDeleted;
use Statamic\Events\RoleSaved;
use Statamic\Events\SiteCreated;
use Statamic\Events\SiteDeleted;
use Statamic\Events\SiteSaved;
use Statamic\Events\SubmissionDeleted;
use Statamic\Events\SubmissionSaved;
use Statamic\Events\UserDeleted;
use Statamic\Events\UserGroupDeleted;
use Statamic\Events\UserGroupSaved;
use Statamic\Events\UserSaved;
use Statamic\Facades\Form as FormFacade;
use Statamic\Facades\User;
use Statamic\Forms\Form;
use Statamic\Query\Builder;
use Statamic\Query\EloquentQueryBuilder;
use UAMSWeb\Aether\Forms\Multisite\Forms\Assignment;

/**
 * Scopes Control Panel submission reads to the sites the user can access (#78).
 *
 * The scope decorates whichever builder is bound to core's submission query
 * contract, core's Stache builder or a driver's, through `app()->extend()`, so
 * the listing, detail view, delete, export and the forms index count are scoped
 * without replacing a controller or a builder class (#49). It applies only on a
 * Control Panel request by a signed-in user who is not a super user; the API, the
 * `{{ form:submissions }}` tag and console commands read every submission.
 *
 * A submission is kept when its `_site` is a site the user can access. One with no
 * `_site` is kept only when the user can access every site its form is assigned
 * to, or every site for a form with no sites (#40).
 *
 * The unattributed rule depends on the form, and core's Stache builder treats a
 * `form` condition as a restriction on the whole query, not as a condition that
 * can sit inside a nested group, so the scope never puts `form` in a group. When
 * the user covers some forms but not others, it reads the ids of the unattributed
 * submissions of the covered forms with a top-level query, and keeps those ids.
 * Those reads are made once per request and user, not once per builder (#123).
 *
 * Deleting a form deletes its submissions through the same builder, so between
 * core's `FormDeleting` and `FormDeleted` events the request reads unscoped; a
 * form deleted from the Control Panel otherwise left behind the submissions its
 * deleter could not see, and they came back with a new form of the same handle.
 */
final class SubmissionScope
{
    /** The request attribute set while a form and its submissions are deleted. */
    public const string DELETING = 'multisite-forms.deleting-form';

    /** The request attribute that holds the scope's plan for the request (#123). */
    public const string PLAN = 'multisite-forms.submission-scope';

    // SubmissionScopeTest has a row per event that fails when its entry is removed,
    // shown by hand (#123), but a constant is not an executable line, so the coverage
    // driver links no test to these mutants and they are never run.
    // @pest-mutate-ignore: RemoveArrayItem
    /** Events of the writes that can change a plan: submissions, forms, sites, access. */
    public const array CHANGES = [
        SubmissionSaved::class,
        SubmissionDeleted::class,
        FormSaved::class,
        FormDeleted::class,
        SiteCreated::class,
        SiteSaved::class,
        SiteDeleted::class,
        RoleSaved::class,
        RoleDeleted::class,
        UserSaved::class,
        UserDeleted::class,
        UserGroupSaved::class,
        UserGroupDeleted::class,
    ];

    // SubmissionScopeTest fails when this default is true, shown by hand (#78), but a
    // property default is not an executable line, so the coverage driver links no
    // test to the mutant and it is never run.
    // @pest-mutate-ignore: FalseToTrue
    /**
     * Set while the scope reads unattributed ids, so that read is not scoped itself.
     *
     * @var bool True only during the scope's own reads.
     */
    private static bool $reading = false;

    /**
     * Decorate the bound submission query builder with the scope.
     *
     * @param  Application  $app  The application whose container binding is decorated.
     */
    public static function register(Application $app): void
    {
        // Scope every submission builder the container resolves
        $app->extend(SubmissionQueryBuilder::class, static fn (SubmissionQueryBuilder $builder): SubmissionQueryBuilder => self::apply($builder));

        // Core dispatches FormDeleting without halting, so once it fires the delete
        // runs; the flag lives on the request so it cannot outlast it.
        Event::listen(FormDeleting::class, static function (): void {
            request()->attributes->set(self::DELETING, true);
        });

        // Clear the flag once the form and its submissions are deleted
        Event::listen(FormDeleted::class, static function (): void {
            request()->attributes->remove(self::DELETING);
        });

        // Drop the request's plan on any write that could change it
        Event::listen(self::CHANGES, static function (): void {
            request()->attributes->remove(self::PLAN);
        });
    }

    /**
     * Add the scope to a new builder when the request calls for it.
     *
     * @param  SubmissionQueryBuilder  $builder  The newly resolved builder.
     * @return SubmissionQueryBuilder The same builder, scoped when the request calls for it.
     *
     * @throws LogicException When the bound builder extends neither core builder.
     */
    public static function apply(SubmissionQueryBuilder $builder): SubmissionQueryBuilder
    {
        // Skip the scope's own reads, a form delete, and non-Control Panel requests
        if (self::$reading || request()->attributes->get(self::DELETING) === true || ! self::isControlPanelRequest()) {
            return $builder;
        }

        // Skip a request with no signed-in user, or a super user's
        $user = User::current();
        if ($user === null || $user->isSuper()) {
            return $builder;
        }

        // Refuse a builder the scope does not know how to constrain
        if (! $builder instanceof Builder && ! $builder instanceof EloquentQueryBuilder) {
            throw new LogicException('Cannot scope submissions by site: the bound submission query builder ['.$builder::class.'] extends neither of the core query builders.');
        }

        // Constrain the builder by the user's plan for this request
        self::scope($builder, self::plan($user));

        return $builder;
    }

    /**
     * The sites, the coverage and the unattributed ids the scope applies for a user,
     * worked out once per request.
     *
     * The forms index resolves a builder per form for its counts, and reading the
     * ids for each one made that page's cost grow with the number of forms times
     * the number of unattributed submissions: measured in the host at 60 forms of
     * 200 unattributed submissions, 6.9s for a user covering some forms against
     * 0.57s for a super user (#123). Any write that dispatches an event that could
     * change the plan drops it, so a delete or a save in the same request is read
     * afresh.
     *
     * @param  UserContract  $user  The signed-in, non-super user being scoped.
     * @return ScopePlan The request's plan for that user.
     */
    private static function plan(UserContract $user): ScopePlan
    {
        // Reuse the request's plan when it was worked out for this user
        $plan = request()->attributes->get(self::PLAN);
        if ($plan instanceof ScopePlan && $plan->user === $user->getAuthIdentifier()) {
            return $plan;
        }

        // Find the forms the user covers, then build the plan from them
        $covered = self::coveredForms($user);
        $plan = new ScopePlan(
            $user->getAuthIdentifier(),
            SiteAccess::accessible($user),
            $covered['all'],
            $covered['all'] ? [] : self::unattributedIds($covered['handles'], $covered['others']),
        );

        // Keep the plan on the request for later builders
        request()->attributes->set(self::PLAN, $plan);

        return $plan;
    }

    /**
     * Whether the current request is for a Control Panel path.
     *
     * Core's `Statamic::isCpRoute()` compares the path with `statamic.cp.route` as
     * configured, while core registers the Control Panel's routes under that value
     * as a route prefix, which Laravel trims of slashes. So a route configured as
     * `/cp` serves the Control Panel at `cp` while `isCpRoute()` is false on every
     * request, which would leave every Control Panel read unscoped (#122). This
     * trims the configured value the same way.
     *
     * The path is compared decoded, because the router matches routes against the
     * decoded path: `/%63p/forms` reaches the Control Panel's forms page, while
     * `path()` keeps it encoded and core's check is false on it. The decoded path
     * is not trimmed again: Laravel trims slashes before decoding, so a slash left
     * at the start came from `%2F`, and the router serves no Control Panel route
     * there (`/%2Fcp/forms` is a 404).
     *
     * An empty route puts the Control Panel's routes at the root, where they
     * collide with the front end. Every path then counts as a Control Panel one,
     * which also scopes front-end and API reads for a signed-in non-super user:
     * that hides submissions rather than showing them.
     *
     * @return bool True when the request's path is under the Control Panel's route.
     */
    public static function isControlPanelRequest(): bool
    {
        // Answer false when the Control Panel is off or its route is unreadable
        $route = config('statamic.cp.route');
        if (! config('statamic.cp.enabled') || ! is_string($route)) {
            return false;
        }

        // Trim the route as the router trims a prefix, and read the decoded path
        $prefix = trim($route, '/');
        $path = request()->decodedPath();

        // Match the path against the prefix, or every path for an empty prefix
        return $prefix === '' || $path === $prefix || str_starts_with($path, $prefix.'/');
    }

    /**
     * Keep submissions of the given sites, and unattributed ones of the covered forms.
     *
     * When the user covers every form, an unattributed submission is kept outright
     * rather than by id, and the plan reads no ids.
     *
     * @param  Builder|EloquentQueryBuilder  $builder  The builder to constrain.
     * @param  ScopePlan  $plan  The request's plan for the user.
     */
    private static function scope(Builder|EloquentQueryBuilder $builder, ScopePlan $plan): void
    {
        $sites = $plan->sites;

        // Check whether the user covers every form
        if ($plan->all) {
            // Keep the user's sites, and every unattributed submission
            $builder->where(static function (Builder|EloquentQueryBuilder $query) use ($sites): void {
                $query->whereIn('_site', $sites);
                $query->orWhereNull('_site');
            });

            return;
        }

        $unattributed = $plan->ids;

        // Keep the user's sites, and the unattributed submissions the plan allows
        $builder->where(static function (Builder|EloquentQueryBuilder $query) use ($sites, $unattributed): void {
            $query->whereIn('_site', $sites);
            $query->orWhereIn('id', $unattributed);
        });
    }

    /**
     * The handles of the forms whose every site the user can access, those of the
     * other forms, and whether the first is every form.
     *
     * Core's form getter does not type its handle, so the handles are only passed on
     * to a query, never read.
     *
     * @param  UserContract  $user  The user whose coverage is worked out.
     * @return array{all: bool, handles: list<mixed>, others: list<mixed>} The covered handles, the others, and whether all are covered.
     */
    private static function coveredForms(UserContract $user): array
    {
        // Start by assuming the user covers every form
        $all = true;
        $handles = [];
        $others = [];

        // Sort each form into covered or not covered
        foreach (FormFacade::all() as $form) {
            // A form whose sites cannot be read is one the user is not known to cover
            if (! $form instanceof Form) {
                $all = false;

                continue;
            }

            // Count the form as covered when the user can access every one of its sites
            $sites = Assignment::sites($form);
            if (SiteAccess::canAccessAll($user, $sites === [] ? SiteAccess::allHandles() : $sites)) {
                $handles[] = $form->handle();
            } else {
                $all = false;
                $others[] = $form->handle();
            }
        }

        return ['all' => $all, 'handles' => $handles, 'others' => $others];
    }

    /**
     * The ids of the submissions with no `_site` in the given forms, less any id a
     * submission of one of the other forms, or an attributed submission of one of
     * the given forms, also carries.
     *
     * An id is not unique across forms (it is the submission's file name, and a
     * copied or imported file can repeat one), and the id rule is not tied to a form
     * or a site, so an id shared with any submission the rule should not reach is
     * dropped, which hides the unattributed submission too.
     *
     * @param  list<mixed>  $forms  The handles of the forms the user covers.
     * @param  list<mixed>  $others  The handles of the other forms.
     * @return array<mixed> The unattributed submission ids the user may read.
     */
    private static function unattributedIds(array $forms, array $others): array
    {
        // Without this return, an empty form list reads ids the exclusion below then
        // drops, since every form is one of the others; it only saves the reads, so
        // removing it is equivalent.
        // @pest-mutate-ignore: RemoveEarlyReturn
        if ($forms === []) {
            return [];
        }

        // Leave the reads below unscoped
        self::$reading = true;

        try {
            // Read the ids of the covered forms' unattributed submissions
            $ids = self::idsOf($forms, static function (Builder|EloquentQueryBuilder $query): void {
                $query->whereNull('_site');
            });

            // Without this return, both reads below match no id and the result is the
            // same empty list; it only saves the reads, so removing it is equivalent.
            // @pest-mutate-ignore: RemoveEarlyReturn
            if ($ids === []) {
                return $ids;
            }

            // The id rule keeps any submission with one of these ids, so an id that an
            // attributed submission of a covered form also carries would show that one
            // whatever its site (#123). Without the id condition the read returns every
            // attributed id of those forms, which drops the same ids; the condition only
            // narrows the read, so removing it is equivalent.
            // @pest-mutate-ignore: RemoveMethodCall
            $shared = self::idsOf($forms, static function (Builder|EloquentQueryBuilder $query) use ($ids): void {
                $query->whereNotNull('_site');
                $query->whereIn('id', $ids);
            });

            // Add the ids the other forms' submissions also carry
            if ($others !== []) {
                // As above for the other forms, whatever their submissions' sites.
                // @pest-mutate-ignore: RemoveMethodCall
                $shared = [...$shared, ...self::idsOf($others, static function (Builder|EloquentQueryBuilder $query) use ($ids): void {
                    $query->whereIn('id', $ids);
                })];
            }

            // Keep only the ids no other submission carries
            return array_filter($ids, static fn (mixed $id): bool => ! in_array($id, $shared, true));
        } finally {
            // Scope later reads again
            self::$reading = false;
        }
    }

    /**
     * The ids of the given forms' submissions that a condition keeps, read unscoped.
     *
     * @param  list<mixed>  $forms  The handles of the forms to read.
     * @param  callable(Builder|EloquentQueryBuilder): void  $condition  Adds the condition to the query.
     * @return array<mixed> The ids of the matching submissions.
     */
    private static function idsOf(array $forms, callable $condition): array
    {
        // apply() has refused any other builder, and $reading leaves this read unscoped
        /** @var Builder|EloquentQueryBuilder $query */
        $query = app(SubmissionQueryBuilder::class);

        // Restrict the read to the given forms, then apply the caller's condition
        $query->whereIn('form', $forms);
        $condition($query);

        // Not pluck('id'): core's Stache builder plucks its own `form::id` keys,
        // which no `id` condition matches
        /** @var Collection<array-key, Submission> $found */
        $found = $query->get();

        // Return each found submission's own id
        return $found->map(static fn (Submission $submission): mixed => $submission->id())->all();
    }
}
