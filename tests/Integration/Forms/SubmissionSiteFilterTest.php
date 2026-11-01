<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Statamic\Auth\File\User as UserObject;
use Statamic\Facades\Form as FormFacade;
use Statamic\Facades\FormSubmission;
use Statamic\Facades\Scope;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;
use Statamic\Forms\Form;
use Statamic\Stache\Stores\Store;
use UAMSWeb\Aether\Forms\Multisite\Query\Scopes\Filters\SubmissionSite;
use UAMSWeb\Aether\Forms\Multisite\Tests\TestCase;

/**
 * The Site filter on a form's submissions listing and export narrows them to one
 * recorded site, offering only the sites the user can access that the form is
 * assigned to (#58).
 *
 * @command  vendor/bin/pest tests/Integration/Forms/SubmissionSiteFilterTest.php
 */

/** The forms every test stores, by handle, with the sites each lists. */
const FILTER_FORMS = [
    'shared' => ['en', 'es'],
    'english' => ['en'],
    'everywhere' => [],
];

beforeEach(function (): void {
    // Configure two sites, a temporary forms directory and a roles file
    useTwoSites();
    $directory = useTemporaryFormsDirectory();
    $this->rolesFile = useTemporaryRoles();

    // Find the submissions store, refusing if the Stache has none
    $store = Stache::store('form-submissions');
    if (! $store instanceof Store) {
        throw new RuntimeException('The Stache has no form submissions store.');
    }

    // Point the submissions store inside the temporary directory
    $store->directory($directory.'/_submissions');

    // Save each form with its sites and one submission per site
    foreach (FILTER_FORMS as $handle => $sites) {
        // Make the form, refusing if the repository returns something else
        $form = FormFacade::make($handle);
        if (! $form instanceof Form) {
            throw new RuntimeException('The forms repository did not make a form.');
        }

        // Title the form and assign its sites, if it lists any
        $form->title(ucfirst($handle));
        if ($sites !== []) {
            $form->set('sites', $sites);
        }

        // Save the form
        $form->save();

        // One submission per site, and one with no site, named after the site. Core's
        // default id has 0.1ms resolution, so two saved that close would share one.
        foreach (['en', 'es', null] as $site) {
            // Make a submission with a predictable id and a name
            $submission = $form->makeSubmission();
            $submission->id("{$handle}-".($site ?? 'none'));
            $submission->set('name', $site ?? 'none');

            // Record the site, unless this is the one with none
            if ($site !== null) {
                $submission->set('_site', $site);
            }

            // Save the submission
            $submission->save();
        }
    }
});

afterEach(function (): void {
    removeFormsDirectory();
    File::delete($this->rolesFile);
});

/**
 * A user who can view every form's submissions, with access to the given sites.
 *
 * @param  string  $id  The user's id.
 * @param  string  ...$sites  Handles of the sites the user can access.
 * @return UserObject The saved user.
 */
function filterUser(string $id, string ...$sites): UserObject
{
    // Grant Control Panel access and each form's submissions
    $permissions = ['access cp'];
    foreach (array_keys(FILTER_FORMS) as $handle) {
        $permissions[] = "view {$handle} form submissions";
    }

    // Grant access to each given site
    foreach ($sites as $site) {
        $permissions[] = "access {$site} site";
    }

    // Save a user with those permissions
    return userWith($id, $permissions);
}

/**
 * A Control Panel URL, with the given filters encoded as the listing sends them.
 *
 * @param  string  $route  Name of the Control Panel route.
 * @param  array<array-key, string>|string  $parameters  The route's parameters.
 * @param  array<string, mixed>|null  $filters  Filter values, or null for none.
 * @return string The URL, with any filters in its query string.
 *
 * @throws RuntimeException When the route does not resolve to a URL.
 */
function filterUrl(string $route, array|string $parameters, ?array $filters = null): string
{
    // Resolve the route, refusing if it does not give a URL
    $url = cp_route($route, $parameters);
    if (! is_string($url)) {
        throw new RuntimeException("The [{$route}] route did not resolve.");
    }

    // Append the filters encoded as the listing sends them
    return $filters === null ? $url : $url.'?filters='.base64_encode((string) json_encode($filters));
}

/**
 * The sorted names of the given submissions, read outside the Control Panel.
 *
 * @param  mixed  $rows  The rows a listing or export returned.
 * @return list<string> The submissions' names, sorted.
 *
 * @throws RuntimeException When the rows are not a list or name no submission.
 */
function filteredNames(mixed $rows): array
{
    // Check that the rows are a list
    if (! is_array($rows)) {
        throw new RuntimeException('The rows are not a list.');
    }

    // Read outside the Control Panel, so no submission scope applies
    Container::getInstance()->instance('request', Request::create('/'));

    // Look up the name of each row's stored submission
    $names = [];
    foreach ($rows as $row) {
        // Find the submission by the row's id, refusing a row that names none
        $id = is_array($row) ? ($row['id'] ?? null) : null;
        $name = is_string($id) ? FormSubmission::find($id)->get('name') : null;
        if (! is_string($name)) {
            throw new RuntimeException('A row names no stored submission.');
        }

        $names[] = $name;
    }

    // Sort the names
    sort($names);

    return $names;
}

/**
 * The submissions listing of a form, filtered or not, by name.
 *
 * @param  TestCase  $test  The running test, which sends the request.
 * @param  string  $form  Handle of the form whose listing to read.
 * @param  array<string, mixed>|null  $filters  Filter values, or null for none.
 * @return list<string> The listed submissions' names, sorted.
 */
function listingNames(TestCase $test, string $form, ?array $filters = null): array
{
    return filteredNames($test->getJson(filterUrl('forms.submissions.index', $form, $filters))->assertOk()->json('data'));
}

/**
 * The filters the submissions listing of a form offers, keyed by handle.
 *
 * @param  string  $form  Handle of the form whose listing to read.
 * @return array<array-key, mixed> The Site filter's array form, if offered.
 *
 * @throws RuntimeException When the listing filters are not iterable.
 */
function offeredFilters(string $form): array
{
    // Read the filters the listing offers, refusing anything not iterable
    $filters = Scope::filters('form-submissions', ['form' => $form]);
    if (! is_iterable($filters)) {
        throw new RuntimeException('The listing filters are not a list.');
    }

    // Keep the Site filter, keyed by its handle
    $offered = [];
    foreach ($filters as $filter) {
        if ($filter instanceof SubmissionSite) {
            $offered['submission_site'] = $filter->toArray();
        }
    }

    return $offered;
}

/**
 * The Site filter with the listing context of a form, or with none.
 *
 * @param  string|null  $form  Handle of the form, or null for no context.
 * @return SubmissionSite The filter.
 */
function siteFilterFor(?string $form): SubmissionSite
{
    // Build the filter and give it the form's context, if any
    $filter = new SubmissionSite;
    if ($form !== null) {
        $filter->context(['form' => $form]);
    }

    return $filter;
}

it('narrows the listing to the chosen site', function (string $site, array $names): void {
    // Sign in as a user who can access both sites
    $this->actingAs(filterUser('both', 'en', 'es'));

    // Expect only the chosen site's submission
    expect(listingNames($this, 'shared', ['submission_site' => ['site' => $site]]))->toBe($names);
})->with([
    'en' => ['en', ['en']],
    'es' => ['es', ['es']],
]);

it('leaves the listing to the submission scope when no site is chosen', function (): void {
    // Expect every submission for a user who can access both sites
    $this->actingAs(filterUser('both', 'en', 'es'));
    expect(listingNames($this, 'shared'))->toBe(['en', 'es', 'none']);

    // Expect only Spanish for a user who can access only es
    $this->actingAs(filterUser('es-only', 'es'));
    expect(listingNames($this, 'everywhere'))->toBe(['es']);
});

it('finds nothing for a site the user cannot access, rather than lifting the filter', function (): void {
    // Sign in as a user who can access only es
    $this->actingAs(filterUser('es-only', 'es'));

    // Expect nothing when filtering to English
    expect(listingNames($this, 'everywhere', ['submission_site' => ['site' => 'en']]))->toBe([]);
});

it('ignores a filter value that names no site', function (mixed $values): void {
    // Sign in as a user who can access both sites
    $this->actingAs(filterUser('both', 'en', 'es'));

    // Expect every submission
    expect(listingNames($this, 'shared', ['submission_site' => $values]))->toBe(['en', 'es', 'none']);
})->with([
    'an empty site' => [['site' => '']],
    'no site key' => [['other' => 'en']],
    'not a list' => ['en'],
]);

it('exports only the chosen site', function (): void {
    // Sign in as a user who can access both sites
    $this->actingAs(filterUser('both', 'en', 'es'));

    // Export the shared form's submissions filtered to Spanish
    $response = $this->get(filterUrl('forms.export', ['shared', 'json'], ['submission_site' => ['site' => 'es']]))->assertOk();

    // Expect only the Spanish submission
    expect(filteredNames(json_decode((string) $response->getContent(), true)))->toBe(['es']);
});

it('offers the sites the user can access that the form is assigned to', function (): void {
    // Sign in as a user who can access both sites
    $this->actingAs(filterUser('both', 'en', 'es'));

    // Expect a Site radio filter offering both sites on the shared form
    $offered = offeredFilters('shared');
    expect($offered)->toHaveKey('submission_site')
        ->and(data_get($offered, 'submission_site.fields.0.options'))->toBe(['en' => 'English', 'es' => 'Spanish'])
        ->and(data_get($offered, 'submission_site.fields.0.type'))->toBe('radio')
        ->and(data_get($offered, 'submission_site.title'))->toBe('Site')
        ->and(data_get($offered, 'submission_site.fields.0.display'))->toBe('Site');

    // Expect it offered on the global form too
    expect(offeredFilters('everywhere'))->toHaveKey('submission_site');
});

it('is not offered with fewer than two sites to choose between', function (string $id, string $sites, string $form): void {
    // Sign in as a user with the given sites
    $this->actingAs(filterUser($id, ...explode(',', $sites)));

    // Expect no filter offered
    expect(offeredFilters($form))->toBe([]);
})->with([
    'a user with one site' => ['es-only', 'es', 'shared'],
    'a form with one site' => ['both', 'en,es', 'english'],
]);

it('is not offered on another listing, for an unknown form, or with no user', function (): void {
    // Sign in as a user who can access both sites
    $this->actingAs(filterUser('both', 'en', 'es'));
    $filter = siteFilterFor('shared');

    // Expect it visible only on a known form's submissions listing
    expect($filter->visibleTo('form-submissions'))->toBeTrue()
        ->and($filter->visibleTo('entries'))->toBeFalse()
        ->and(siteFilterFor('missing')->visibleTo('form-submissions'))->toBeFalse()
        ->and(siteFilterFor(null)->visibleTo('form-submissions'))->toBeFalse();

    // Sign out and expect it hidden
    auth()->logout();
    expect($filter->visibleTo('form-submissions'))->toBeFalse();
});

it('offers a select instead of radio buttons beyond five sites', function (int $count, string $type): void {
    // Enable multisite and configure the given number of sites
    config(['statamic.editions.pro' => true, 'statamic.system.multisite' => true]);
    $sites = [];
    foreach (array_slice(['en', 'es', 'fr', 'de', 'it', 'pt'], 0, $count) as $handle) {
        $sites[$handle] = ['name' => strtoupper($handle), 'url' => "http://localhost/{$handle}/", 'locale' => $handle];
    }

    Site::setSites($sites);

    // Sign in as a super user
    $this->actingAs(superUser());

    // Expect the given field type offering every site
    $fields = siteFilterFor('everywhere')->toArray()['fields'];
    expect(data_get($fields, '0.type'))->toBe($type)
        ->and(data_get($fields, '0.options'))->toHaveCount($count);
})->with([
    'five sites' => [5, 'radio'],
    'six sites' => [6, 'select'],
]);

it('translates its title, field label and badge', function (): void {
    // Translate "Site" into Spanish and switch to the Spanish locale
    app('translator')->addLines(['*.Site' => 'Sitio'], 'es');
    app()->setLocale('es');

    // Sign in as a user who can access both sites
    $this->actingAs(filterUser('both', 'en', 'es'));

    // Expect the translation in the title, label and badge
    expect(SubmissionSite::title())->toBe('Sitio')
        ->and(data_get(siteFilterFor('shared')->toArray(), 'fields.0.display'))->toBe('Sitio')
        ->and(siteFilterFor('shared')->badge(['site' => 'es']))->toBe('Sitio: Spanish');
});

it('translates the site names it offers and shows', function (): void {
    // Translate "Spanish" and switch to the Spanish locale
    app('translator')->addLines(['*.Spanish' => 'Español'], 'es');
    app()->setLocale('es');

    // Sign in as a user who can access both sites
    $this->actingAs(filterUser('both', 'en', 'es'));

    // Expect the translated name in the options and the badge
    expect(data_get(siteFilterFor('shared')->toArray(), 'fields.0.options'))->toBe(['en' => 'English', 'es' => 'Español'])
        ->and(siteFilterFor('shared')->badge(['site' => 'es']))->toBe('Site: Español');
});

it('names an offered site in its badge, and only the handle of any other', function (mixed $values, string $badge): void {
    // Sign in as a user who can access only es
    $this->actingAs(filterUser('es-only', 'es'));

    // Expect the given badge
    expect(siteFilterFor('everywhere')->badge($values))->toBe($badge);
})->with([
    'a site the user can access' => [['site' => 'es'], 'Site: Spanish'],
    'a site the user cannot access' => [['site' => 'en'], 'Site: en'],
    'an unknown handle' => [['site' => 'gone'], 'Site: gone'],
]);

it('shows no badge when the value names no site', function (mixed $values): void {
    // Sign in as a user who can access both sites
    $this->actingAs(filterUser('both', 'en', 'es'));

    // Expect an empty badge
    expect(siteFilterFor('shared')->badge($values))->toBe('');
})->with([
    'no site' => [[]],
    'an empty site' => [['site' => '']],
    'not a list' => ['en'],
]);

it('does not name an inaccessible site in the badge the listing returns', function (): void {
    // Sign in as a user who can access only es
    $this->actingAs(filterUser('es-only', 'es'));

    // Request the listing filtered to English
    $response = $this->getJson(filterUrl('forms.submissions.index', 'everywhere', ['submission_site' => ['site' => 'en']]))->assertOk();

    // Expect the badge to show only the handle
    expect($response->json('meta.activeFilterBadges'))->toBe(['submission_site' => 'Site: en']);
});

it("is registered under its own handle, beside core's entries site filter", function (): void {
    expect(Scope::find('submission_site'))->toBeInstanceOf(SubmissionSite::class)
        ->and(Scope::find('site'))->toBeInstanceOf(Statamic\Query\Scopes\Filters\Site::class);
});
