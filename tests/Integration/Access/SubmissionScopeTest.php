<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Mockery\MockInterface;
use Statamic\Auth\File\User as UserObject;
use Statamic\Contracts\Forms\Submission;
use Statamic\Contracts\Forms\SubmissionQueryBuilder;
use Statamic\Events\FormDeleted;
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
use Statamic\Facades\FormSubmission;
use Statamic\Facades\Role;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;
use Statamic\Facades\User;
use Statamic\Facades\UserGroup;
use Statamic\Forms\Form;
use Statamic\Forms\Submission as SubmissionObject;
use Statamic\Query\EloquentQueryBuilder;
use Statamic\Sites\Site as SiteObject;
use Statamic\Stache\Query\SubmissionQueryBuilder as StacheSubmissionQueryBuilder;
use Statamic\Stache\Stores\Store;
use Statamic\Statamic;
use Statamic\Tags\FluentTag;
use UAMSWeb\Aether\Forms\Multisite\Access\SiteAccess;
use UAMSWeb\Aether\Forms\Multisite\Access\SubmissionScope;
use UAMSWeb\Aether\Forms\Multisite\Tests\TestCase;

/**
 * A Control Panel user reads the submissions of the sites they can access, and
 * unattributed ones only of forms whose every site they can access; the API, the
 * tags and super users read every submission (#78).
 *
 * @command  vendor/bin/pest tests/Integration/Access/SubmissionScopeTest.php
 */

/** A submission query builder that extends neither of core's query builders. */
final class UnscopableSubmissionQuery implements SubmissionQueryBuilder {}

/** The forms every test stores, by handle, with the sites each lists. */
const SCOPE_FORMS = [
    'shared' => ['en', 'es'],
    'spanish' => ['es'],
    'everywhere' => [],
];

beforeEach(function (): void {
    // Configure two sites, a temporary forms directory and temporary roles
    useTwoSites();
    $directory = useTemporaryFormsDirectory();
    $this->rolesFile = useTemporaryRoles();

    // Store submissions beside the temporary forms
    submissionsStore()->directory($directory.'/_submissions');

    // Store each test form with its sites and its submissions
    foreach (SCOPE_FORMS as $handle => $sites) {
        // Make the form, failing if the repository returns another class
        $form = FormFacade::make($handle);
        if (! $form instanceof Form) {
            throw new RuntimeException('The forms repository did not make a form.');
        }

        // Title the form and assign its sites unless it is global
        $form->title(ucfirst($handle));
        if ($sites !== []) {
            $form->set('sites', $sites);
        }

        // Save the form to the temporary directory
        $form->save();

        // One submission per site, and one with no site, named after the site
        foreach (['en', 'es', null] as $site) {
            // Make the submission, named after its site
            $submission = $form->makeSubmission();
            $submission->set('name', $site ?? 'none');

            // Attribute it to its site unless it has none
            if ($site !== null) {
                $submission->set('_site', $site);
            }

            // Save the submission
            $submission->save();
        }
    }
});

afterEach(function (): void {
    // Remove the stored forms and the temporary roles file
    removeFormsDirectory();
    File::delete($this->rolesFile);
});

/**
 * Core's Stache store of form submissions.
 *
 * @return Store The form submissions store.
 *
 * @throws RuntimeException When the Stache has no such store.
 */
function submissionsStore(): Store
{
    // Find the store, failing when the Stache has none
    $store = Stache::store('form-submissions');
    if (! $store instanceof Store) {
        throw new RuntimeException('The Stache has no form submissions store.');
    }

    // Return the store
    return $store;
}

/**
 * A user who can view and delete every form's submissions, with access to the
 * given sites.
 *
 * @param  string  $id  The id to save the user under.
 * @param  list<string>  $sites  The handles of the sites the user can access.
 * @return UserObject The saved user.
 */
function scopeUser(string $id, array $sites): UserObject
{
    // Grant viewing and deleting the submissions of every test form
    $permissions = ['access cp'];
    foreach (array_keys(SCOPE_FORMS) as $handle) {
        $permissions[] = "view {$handle} form submissions";
        $permissions[] = "delete {$handle} form submissions";
    }

    // Grant access to each given site
    foreach ($sites as $site) {
        $permissions[] = "access {$site} site";
    }

    // Save the user with those permissions
    return userWith($id, $permissions);
}

/**
 * The URL of a Control Panel route.
 *
 * @param  string  $route  The Control Panel route name, without its prefix.
 * @param  array<array-key, string>|string  $parameters  The route's parameters.
 * @return string The resolved URL.
 *
 * @throws RuntimeException When the route does not resolve to a URL.
 */
function scopeUrl(string $route, array|string $parameters = []): string
{
    // Resolve the route with its parameters
    $url = cp_route($route, $parameters);

    // Fail when the route did not resolve to a URL
    if (! is_string($url)) {
        throw new RuntimeException("The [{$route}] route did not resolve.");
    }

    // Return the resolved URL
    return $url;
}

/**
 * Make the current request one for the given path, for reads made outside a
 * request.
 *
 * @param  string  $path  The path the current request is for.
 */
function onPath(string $path): void
{
    Container::getInstance()->instance('request', Request::create($path));
}

/**
 * A query over the submissions of the given forms, or of every form the test
 * stores.
 *
 * The query names its forms, since core's builder lists every form when it
 * does not.
 *
 * @param  list<string>|null  $forms  The form handles, or null for every one.
 * @return StacheSubmissionQueryBuilder The query, limited to those forms.
 *
 * @throws RuntimeException When the bound builder is not core's Stache builder.
 */
function submissionsOf(?array $forms = null): StacheSubmissionQueryBuilder
{
    // Make a query, failing when the bound builder is not core's Stache one
    $query = FormSubmission::query();
    if (! $query instanceof StacheSubmissionQueryBuilder) {
        throw new RuntimeException("The bound submission query builder is not core's Stache builder.");
    }

    // Limit the query to the given forms, or to every test form
    $query->whereIn('form', $forms ?? array_keys(SCOPE_FORMS));

    return $query;
}

/**
 * The id of the submission of a form saved for a site, or with no site for
 * null.
 *
 * @param  string  $form  The handle of the form.
 * @param  string|null  $site  The site handle, or null for no site.
 * @return string The submission's id.
 *
 * @throws RuntimeException When the test stored no such submission.
 */
function scopeSubmissionId(string $form, ?string $site): string
{
    // Read outside the Control Panel, so nothing is scoped
    onPath('/');

    // Fetch the form's submissions, failing when they are not a list
    $submissions = submissionsOf([$form])->get();
    if (! is_iterable($submissions)) {
        throw new RuntimeException("The [{$form}] submissions did not come back as a list.");
    }

    // Find the submission named after the site
    foreach ($submissions as $submission) {
        // Return its id when the name matches
        $id = $submission instanceof Submission ? $submission->id() : null;
        if (is_string($id) && $submission->get('name') === ($site ?? 'none')) {
            return $id;
        }
    }

    // Fail when no submission matched
    throw new RuntimeException("No [{$form}] submission for [".($site ?? 'none').'].');
}

/**
 * The names of the submissions with the given ids, read outside the Control
 * Panel, sorted.
 *
 * The forms have no blueprint fields, so the listing and the export return ids
 * only.
 *
 * @param  list<array{id: string}>  $rows  The rows a listing or export returned.
 * @return list<string> The name of each row's submission, sorted.
 */
function namesOf(array $rows): array
{
    // Read outside the Control Panel, so nothing is scoped
    onPath('/');

    // Look up each row's submission name, failing when one has none
    $names = array_map(static function (array $row): string {
        $name = FormSubmission::find($row['id'])->get('name');
        if (! is_string($name)) {
            throw new RuntimeException("No named submission [{$row['id']}].");
        }

        return $name;
    }, $rows);

    // Sort the names so the order is stable
    sort($names);

    return $names;
}

/**
 * The names of the submissions a Control Panel listing returned, sorted.
 *
 * @param  TestCase  $test  The running test, whose signed-in user asks.
 * @param  string  $form  The handle of the form whose listing to read.
 * @return list<string> The name of each listed submission, sorted.
 */
function listedNames(TestCase $test, string $form): array
{
    // Fetch the form's submissions listing
    /** @var list<array{id: string}> $rows */
    $rows = $test->getJson(scopeUrl('forms.submissions.index', $form))->assertOk()->json('data');

    // Name the listed submissions
    return namesOf($rows);
}

it('lists only the sites a user can access, and unattributed ones only of forms they cover', function (string $kind, string $form, array $names): void {
    // Sign in as the user of the given kind
    $user = match ($kind) {
        'es-only' => scopeUser('es-only', ['es']),
        'both' => scopeUser('both', ['en', 'es']),
        default => superUser(),
    };
    $this->actingAs($user);

    // Expect the listing to hold only the readable submissions
    expect(listedNames($this, $form))->toBe($names);
})->with([
    // A super user is the control: every submission
    'super, en and es form' => ['super', 'shared', ['en', 'es', 'none']],
    // es-only covers the es-only form, so its unattributed one shows; not the others'
    'es-only, en and es form' => ['es-only', 'shared', ['es']],
    'es-only, es-only form' => ['es-only', 'spanish', ['es', 'none']],
    'es-only, global form' => ['es-only', 'everywhere', ['es']],
    // A user with every site covers every form, so nothing is hidden
    'both sites, en and es form' => ['both', 'shared', ['en', 'es', 'none']],
    'both sites, global form' => ['both', 'everywhere', ['en', 'es', 'none']],
]);

it('refuses the detail view of a submission of another site', function (): void {
    // Sign in as an es-only user and find the en and es submissions
    $this->actingAs(scopeUser('es-only', ['es']));
    $en = scopeSubmissionId('shared', 'en');
    $es = scopeSubmissionId('shared', 'es');

    // Expect the en submission not found and the es one shown
    $this->get(scopeUrl('forms.submissions.show', ['shared', $en]))->assertNotFound();
    $this->get(scopeUrl('forms.submissions.show', ['shared', $es]))->assertOk();
});

it('refuses to delete a submission of another site, and keeps it', function (): void {
    // Sign in as an es-only user and find the en and es submissions
    $this->actingAs(scopeUser('es-only', ['es']));
    $en = scopeSubmissionId('shared', 'en');
    $es = scopeSubmissionId('shared', 'es');

    // Expect deleting en refused and deleting es accepted
    $this->deleteJson(scopeUrl('forms.submissions.destroy', ['shared', $en]))->assertForbidden();
    $this->deleteJson(scopeUrl('forms.submissions.destroy', ['shared', $es]))->assertNoContent();

    // Expect the en submission kept and the es one gone, read unscoped
    onPath('/');
    expect(FormSubmission::find($en))->not->toBeNull()
        ->and(FormSubmission::find($es))->toBeNull();
});

it('exports only the submissions the user may read', function (bool $filtered): void {
    // Sign in as an es-only user
    $this->actingAs(scopeUser('es-only', ['es']));

    // Export the shared form's submissions, sorted when the case asks
    $url = scopeUrl('forms.export', ['shared', 'json']).($filtered ? '?sort=date' : '');
    $response = $this->get($url)->assertOk();

    // Decode the exported rows
    /** @var list<array{id: string}> $rows */
    $rows = json_decode((string) $response->getContent(), true);

    // Expect only the es submission exported
    expect(namesOf($rows))->toBe(['es']);
})->with(['the whole export' => [false], 'a sorted export' => [true]]);

it('counts only readable submissions on the forms index', function (): void {
    // Sign in as an es-only user
    $this->actingAs(scopeUser('es-only', ['es']));

    // Open the forms index and read its page data
    /** @var array{props: array{forms: list<array{id: string, submissions: int}>}} $page */
    $page = $this->get(scopeUrl('forms.index'))->assertOk()->viewData('page');

    // Expect each form's count to include only readable submissions
    expect(array_column($page['props']['forms'], 'submissions', 'id'))->toEqual(['shared' => 1, 'spanish' => 2, 'everywhere' => 1]);
});

it('reads every submission outside the Control Panel, whoever is signed in', function (string $path): void {
    // Sign in as an es-only user on a path outside the Control Panel
    $this->actingAs(scopeUser('es-only', ['es']));
    onPath($path);

    // Expect every submission of the shared form read
    expect(submissionsOf(['shared'])->count())->toBe(3);
})->with(['the API' => ['/api/forms/shared'], 'a front-end page' => ['/contact']]);

it('reads every submission through the form:submissions tag on a page', function (): void {
    // Sign in as an es-only user on a front-end page
    $this->actingAs(scopeUser('es-only', ['es']));
    onPath('/contact');

    // Fetch the shared form's submissions through the tag
    $submissions = FluentTag::make('form:submissions')->params(['in' => 'shared'])->fetch();

    // Expect every submission returned
    expect(is_countable($submissions) ? count($submissions) : null)->toBe(3);
});

it('reads every submission in the Control Panel with no signed-in user', function (): void {
    // Read on a Control Panel path with nobody signed in
    onPath('/cp/forms');

    // Expect every submission of the shared form read
    expect(submissionsOf(['shared'])->count())->toBe(3);
});

it("keeps core's builder bound and scopes it", function (): void {
    // Sign in as an es-only user on a Control Panel path
    $this->actingAs(scopeUser('es-only', ['es']));
    onPath('/cp/forms');

    // Expect core's builder still bound, and the read scoped to es
    expect(app(SubmissionQueryBuilder::class)::class)->toBe(StacheSubmissionQueryBuilder::class)
        ->and(submissionsOf(['shared'])->count())->toBe(1);
});

it('scopes a builder a driver binds after the add-on boots', function (): void {
    // Bind a subclass of core's builder, as a driver would after boot
    $store = submissionsStore();
    app()->bind(SubmissionQueryBuilder::class, fn (): SubmissionQueryBuilder => new class($store) extends StacheSubmissionQueryBuilder {});

    // Sign in as an es-only user on a Control Panel path
    $this->actingAs(scopeUser('es-only', ['es']));
    onPath('/cp/forms');

    // Expect the new builder bound, and the read still scoped to es
    expect(app(SubmissionQueryBuilder::class)::class)->not->toBe(StacheSubmissionQueryBuilder::class)
        ->and(submissionsOf(['shared'])->count())->toBe(1);
});

it('refuses to read rather than leave submissions unscoped for a builder it cannot scope', function (): void {
    // Sign in as an es-only user on a Control Panel path
    $this->actingAs(scopeUser('es-only', ['es']));
    onPath('/cp/forms');

    // Apply the scope to a builder it cannot scope, expecting it to throw
    SubmissionScope::apply(new UnscopableSubmissionQuery);
})->throws(LogicException::class, 'Cannot scope submissions by site: the bound submission query builder [UnscopableSubmissionQuery] extends neither of the core query builders.');

it("scopes a builder that extends core's Eloquent query builder", function (): void {
    // Sign in as an es-only user on a Control Panel path
    $this->actingAs(scopeUser('es-only', ['es']));
    onPath('/cp/forms');

    // Mock an Eloquent builder that captures the condition it is given
    $condition = null;

    /** @var EloquentQueryBuilder&SubmissionQueryBuilder&MockInterface $builder */
    $builder = Mockery::mock(EloquentQueryBuilder::class.', '.SubmissionQueryBuilder::class);
    $builder->shouldReceive('where')->once()->with(Mockery::on(static function (mixed $argument) use (&$condition): bool {
        $condition = $argument;

        return $argument instanceof Closure;
    }))->andReturnSelf();

    // Expect the scope applied as one grouped condition
    expect(SubmissionScope::apply($builder))->toBe($builder)
        ->and($condition)->toBeInstanceOf(Closure::class);

    // The es-only user covers only the es-only form, so the group keeps es and that
    // form's unattributed submission by id
    $unattributed = scopeSubmissionId('spanish', null);

    // Mock the group, expecting es and that unattributed id
    /** @var EloquentQueryBuilder&MockInterface $group */
    $group = Mockery::mock(EloquentQueryBuilder::class);
    $group->shouldReceive('whereIn')->once()->with('_site', ['es']);
    $group->shouldReceive('orWhereIn')->once()->with('id', [$unattributed]);

    // Run the captured condition against the group
    /** @var Closure(EloquentQueryBuilder): void $condition */
    $condition($group);
});

it('deletes every submission of a form a scoped user deletes, not only those they can read', function (): void {
    // Sign in as a configurer who can access only es
    $this->actingAs(userWith('es-configurer', ['access cp', 'configure forms', 'access es site']));

    // Expect deleting the es-only form accepted
    $this->deleteJson(scopeUrl('forms.destroy', 'spanish'))->assertOk();

    // Expect all its submissions gone and the others kept, read unscoped
    onPath('/');
    expect(submissionsOf(['spanish'])->count())->toBe(0)
        ->and(submissionsOf(['shared'])->count())->toBe(3);
});

it('scopes again once a form has been deleted in the same request', function (): void {
    // Sign in as an es-only user on a Control Panel path
    $this->actingAs(scopeUser('es-only', ['es']));
    onPath('/cp/forms');

    // Find the es-only form, failing when it is missing
    $spanish = FormFacade::find('spanish');
    if (! $spanish instanceof Form) {
        throw new RuntimeException('No [spanish] form.');
    }

    // Delete it within the request
    $spanish->delete();

    // Expect the shared form's read still scoped to es
    expect(submissionsOf(['shared'])->count())->toBe(1);
});

it('drops an unattributed id that a submission of an uncovered form also carries', function (): void {
    // Copy the es-only form's unattributed submission's id onto an en submission of
    // the en and es form, as a copied or imported file can
    $id = scopeSubmissionId('spanish', null);

    // Find the shared form, failing when it is missing
    $shared = FormFacade::find('shared');
    if (! $shared instanceof Form) {
        throw new RuntimeException('No [shared] form.');
    }

    // Save an en submission to it carrying the copied id
    $clash = $shared->makeSubmission();
    $clash->id($id);
    $clash->set('name', 'clash');
    $clash->set('_site', 'en');
    $clash->save();

    // Sign in as an es-only user
    $this->actingAs(scopeUser('es-only', ['es']));

    // Expect neither listing to show the clashing or unattributed submission
    expect(listedNames($this, 'shared'))->toBe(['es'])
        ->and(listedNames($this, 'spanish'))->toBe(['es']);
});

it('hides a stray submission of another site when the user covers every form', function (): void {
    // Only the es-only form is left, so the es-only user covers every form; its
    // en submission was saved for a site the form does not list
    foreach (['shared', 'everywhere'] as $handle) {
        // Find the form, failing when it is missing
        $form = FormFacade::find($handle);
        if (! $form instanceof Form) {
            throw new RuntimeException("No [{$handle}] form.");
        }

        // Delete the form
        $form->delete();
    }

    // Sign in as an es-only user
    $this->actingAs(scopeUser('es-only', ['es']));

    // Expect the en submission hidden and the unattributed one shown
    expect(listedNames($this, 'spanish'))->toBe(['es', 'none']);
});

it('reads no submission for a user who can access no site', function (): void {
    // Sign in as a user with no site on a Control Panel path
    $this->actingAs(scopeUser('nowhere', []));
    onPath('/cp/forms');

    // Expect no submission read
    expect(submissionsOf()->count())->toBe(0);
});

it('treats a form it cannot read the sites of as one the user does not cover', function (): void {
    // Sign in as an es-only user on a Control Panel path
    $user = scopeUser('es-only', ['es']);
    $this->actingAs($user);
    onPath('/cp/forms');

    // Core's permissions list every form when they are first read, so read them
    // before the forms repository starts returning something that is not a form
    expect(SiteAccess::accessible($user))->toBe(['es']);

    // Make the forms repository list something that is not a form
    $spanish = FormFacade::find('spanish');
    FormFacade::partialMock()->shouldReceive('all')->andReturn(collect([new stdClass, $spanish]));

    // Every form's es submission, and the unattributed one of the es-only form only
    expect(submissionsOf()->count())->toBe(4);
});

it('treats a path under the Control Panel route as a Control Panel request, however the route is slashed', function (string $route, string $path, bool $expected): void {
    // Configure the Control Panel route and request the path
    config(['statamic.cp.route' => $route]);
    onPath($path);

    // Expect the path judged a Control Panel request or not
    expect(SubmissionScope::isControlPanelRequest())->toBe($expected);
})->with([
    'cp, the Control Panel itself' => ['cp', '/cp', true],
    'cp, a page under it' => ['cp', '/cp/forms', true],
    '/cp, a page under it' => ['/cp', '/cp/forms', true],
    '/cp/, the Control Panel itself' => ['/cp/', '/cp', true],
    'cp/, the Control Panel itself' => ['cp/', '/cp', true],
    'a nested route' => ['/admin/cp', '/admin/cp/forms', true],
    'a path that only starts with the route' => ['/cp', '/cpanel/forms', false],
    'a front-end path' => ['/cp', '/contact', false],
    'an API path' => ['/cp', '/api/forms/shared', false],
    'an encoded Control Panel path' => ['/cp', '/%63p/forms', true],
    'a path decoding to a leading slash' => ['cp', '/%2Fcp/forms', false],
    'the site root' => ['cp', '/', false],
    'an empty route, at the root' => ['', '/forms', true],
]);

it('is not a Control Panel request when the Control Panel is disabled or its route is not text', function (string $key, mixed $value): void {
    // Disable the Control Panel or break its route, then request a CP path
    config([$key => $value]);
    onPath('/cp/forms');

    // Expect the path not judged a Control Panel request
    expect(SubmissionScope::isControlPanelRequest())->toBeFalse();
})->with([
    'disabled' => ['statamic.cp.enabled', false],
    'a route that is not text' => ['statamic.cp.route', null],
]);

it('scopes Control Panel reads under a route configured with a leading slash, where core would not', function (): void {
    // Route the Control Panel with a leading slash, as an es-only user
    config(['statamic.cp.route' => '/cp']);
    $this->actingAs(scopeUser('es-only', ['es']));
    onPath('/cp/forms');

    // Expect core to miss the route while the read is still scoped
    expect(Statamic::isCpRoute())->toBeFalse()
        ->and(submissionsOf(['shared'])->count())->toBe(1);
});

it('scopes a Control Panel listing reached through a URL-encoded path', function (string $path): void {
    // Sign in as an es-only user
    $this->actingAs(scopeUser('es-only', ['es']));

    // Fetch the listing through the encoded path
    /** @var list<array{id: string}> $rows */
    $rows = $this->getJson($path)->assertOk()->json('data');

    // Expect only the es submission listed
    expect(namesOf($rows))->toBe(['es']);
})->with([
    'an encoded letter' => ['/%63p/forms/shared/submissions'],
    'an encoded slash' => ['/cp%2Fforms/shared/submissions'],
]);

it('serves no Control Panel listing on a path decoding to a leading slash', function (): void {
    // Sign in as a super user, whom the scope never limits
    $this->actingAs(superUser());

    // Expect the router to find no route there, so no Control Panel read happens
    $this->getJson('/%2Fcp/forms/shared/submissions')->assertNotFound();

    // Expect the same listing served on its plain path, as the control
    $this->getJson('/cp/forms/shared/submissions')->assertOk();
});

/**
 * Save an unattributed submission to the es-only form without dispatching events,
 * so nothing tells the scope its plan is out of date.
 *
 * @throws RuntimeException When the form or its submission cannot be made.
 */
function quietlySaveUnattributed(): void
{
    // Find the es-only form, failing when it is missing
    $spanish = FormFacade::find('spanish');
    if (! $spanish instanceof Form) {
        throw new RuntimeException('No [spanish] form.');
    }

    // Make a submission, failing if the form returns another class
    $submission = $spanish->makeSubmission();
    if (! $submission instanceof SubmissionObject) {
        throw new RuntimeException('The form did not make a submission.');
    }

    // Save it with no site and without dispatching events
    $submission->id('quiet-unattributed');
    $submission->set('name', 'quiet');
    $submission->saveQuietly();
}

/**
 * The es site.
 *
 * @return SiteObject The configured es site.
 *
 * @throws RuntimeException When there is no es site.
 */
function spanishSite(): SiteObject
{
    // Find the es site, failing when it is not configured
    $site = Site::get('es');
    if (! $site instanceof SiteObject) {
        throw new RuntimeException('No [es] site.');
    }

    // Return the site
    return $site;
}

it('reads the unattributed ids once per request, and afresh in the next', function (): void {
    // Sign in as an es-only user on a Control Panel path
    $this->actingAs(scopeUser('es-only', ['es']));
    onPath('/cp/forms');

    // Count before and after a quiet save within the same request
    $before = submissionsOf(['spanish'])->count();
    quietlySaveUnattributed();
    $same = submissionsOf(['spanish'])->count();

    // Start a new request
    onPath('/cp/forms');

    // Expect the new submission counted only in the new request
    expect([$before, $same, submissionsOf(['spanish'])->count()])->toBe([2, 2, 3]);
});

it('reads the unattributed ids afresh after a write that can change them in the same request', function (Closure $event): void {
    // Sign in as an es-only user on a Control Panel path
    $this->actingAs(scopeUser('es-only', ['es']));
    onPath('/cp/forms');

    // Count, save quietly, then dispatch the write event under test
    $before = submissionsOf(['spanish'])->count();
    quietlySaveUnattributed();
    event($event());

    // Expect the event to make the new submission count
    expect([$before, submissionsOf(['spanish'])->count()])->toBe([2, 3]);
})->with([
    'a submission saved' => [static fn (): object => new SubmissionSaved(FormSubmission::make())],
    'a submission deleted' => [static fn (): object => new SubmissionDeleted(FormSubmission::make())],
    'a form saved' => [static fn (): object => new FormSaved(FormFacade::make('other'))],
    'a form deleted' => [static fn (): object => new FormDeleted(FormFacade::make('other'))],
    'a site created' => [static fn (): object => new SiteCreated(spanishSite())],
    'a site saved' => [static fn (): object => new SiteSaved(spanishSite())],
    'a site deleted' => [static fn (): object => new SiteDeleted(spanishSite())],
    'a role saved' => [static fn (): object => new RoleSaved(Role::make('other'))],
    'a role deleted' => [static fn (): object => new RoleDeleted(Role::make('other'))],
    'a user saved' => [static fn (): object => new UserSaved(User::make())],
    'a user deleted' => [static fn (): object => new UserDeleted(User::make())],
    'a user group saved' => [static fn (): object => new UserGroupSaved(UserGroup::make())],
    'a user group deleted' => [static fn (): object => new UserGroupDeleted(UserGroup::make())],
]);

it('keeps the plan of one user from another in the same request', function (): void {
    // Read on a Control Panel path
    onPath('/cp/forms');

    // Count the shared form's submissions as an es-only user
    $this->actingAs(scopeUser('es-only', ['es']));
    $spanish = submissionsOf(['shared'])->count();

    // Switch to a user who can access both sites
    $this->actingAs(scopeUser('both', ['en', 'es']));

    // Expect each user's count to follow their own sites
    expect([$spanish, submissionsOf(['shared'])->count()])->toBe([1, 3]);
});

/**
 * Make a form for the given sites holding one unattributed submission with the
 * given id.
 *
 * @param  string  $handle  The handle of the form to make.
 * @param  list<string>  $sites  The handles of the sites the form lists.
 * @param  string  $id  The id to give the unattributed submission.
 *
 * @throws RuntimeException When the repository does not make a form.
 */
function formWithUnattributed(string $handle, array $sites, string $id): void
{
    // Make the form, failing if the repository returns another class
    $form = FormFacade::make($handle);
    if (! $form instanceof Form) {
        throw new RuntimeException('The forms repository did not make a form.');
    }

    // Title the form, assign its sites and save it
    $form->title(ucfirst($handle));
    $form->set('sites', $sites);
    $form->save();

    // Save one submission with the given id and no site
    $submission = $form->makeSubmission();
    $submission->id($id);
    $submission->set('name', 'collision');
    $submission->save();
}

it('drops an unattributed id that a submission of another site in a covered form also carries', function (): void {
    // The es-only form keeps the en submission it was saved with; a second es-only
    // form's unattributed submission carries the same id, as a copied file can
    formWithUnattributed('spanish_copy', ['es'], scopeSubmissionId('spanish', 'en'));

    // Sign in as an es-only user
    $this->actingAs(scopeUser('es-only', ['es']));

    // Expect the en submission still hidden from the es-only form's listing
    expect(listedNames($this, 'spanish'))->toBe(['es', 'none']);
});

it('keeps an unattributed submission whose id another site carries when the user covers every form', function (): void {
    // Delete every form but the es-only one, so the es-only user covers all
    foreach (['shared', 'everywhere'] as $handle) {
        // Find the form, failing when it is missing
        $form = FormFacade::find($handle);
        if (! $form instanceof Form) {
            throw new RuntimeException("No [{$handle}] form.");
        }

        // Delete the form
        $form->delete();
    }

    // Add an es-only form whose unattributed submission carries an en id
    formWithUnattributed('spanish_copy', ['es'], scopeSubmissionId('spanish', 'en'));

    // Sign in as an es-only user on a Control Panel path
    $this->actingAs(scopeUser('es-only', ['es']));
    onPath('/cp/forms');

    // Expect the unattributed submission kept
    expect(submissionsOf(['spanish_copy'])->count())->toBe(1);
});

it('hides a submission moved to another site in the same request', function (): void {
    // Find the es-only form's unattributed submission
    $submission = FormSubmission::find(scopeSubmissionId('spanish', null));

    // Sign in as an es-only user on a Control Panel path
    $this->actingAs(scopeUser('es-only', ['es']));
    onPath('/cp/forms');

    // Count, then move the submission to en within the request
    $before = submissionsOf(['spanish'])->count();
    $submission->set('_site', 'en');
    $submission->save();

    // Expect the moved submission no longer counted
    expect([$before, submissionsOf(['spanish'])->count()])->toBe([2, 1]);
});
