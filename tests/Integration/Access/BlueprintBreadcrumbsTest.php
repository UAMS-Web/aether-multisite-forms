<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Routing\Events\PreparingResponse;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Inertia\Testing\AssertableInertia;
use Statamic\Facades\Form as FormFacade;
use Statamic\Forms\Form;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response;
use UAMSWeb\Aether\Forms\Multisite\Http\BlueprintBreadcrumbs;
use UAMSWeb\Aether\Forms\Multisite\Tests\TestCase;

/**
 * The form fields editor's breadcrumb links only the forms whose editor the user
 * may open, so it shows no title of a form on a site they cannot access (#124).
 *
 * @command  vendor/bin/pest tests/Integration/Access/BlueprintBreadcrumbsTest.php
 */

/** The forms every test stores, by handle, with the sites each lists. */
const BREADCRUMB_FORMS = [
    'english' => ['en'],
    'shared' => ['en', 'es'],
    'spanish' => ['es'],
    'everywhere' => [],
];

beforeEach(function (): void {
    // Configure two sites, a temporary forms directory and temporary roles
    useTwoSites();
    useTemporaryFormsDirectory();
    $this->rolesFile = useTemporaryRoles();

    // Store each test form with the sites it lists
    foreach (BREADCRUMB_FORMS as $handle => $sites) {
        // Make the form, failing if the repository returns another class
        $form = FormFacade::make($handle);
        if (! $form instanceof Form) {
            throw new RuntimeException('The forms repository did not make a form.');
        }

        // Title the form and assign its sites unless it is global
        $form->title('Title of '.$handle);
        if ($sites !== []) {
            $form->set('sites', $sites);
        }

        // Save the form to the temporary directory
        $form->save();
    }
});

afterEach(function (): void {
    // Remove the stored forms and the temporary roles file
    removeFormsDirectory();
    File::delete($this->rolesFile);
});

/**
 * The form fields editor's page for a form.
 *
 * @param  TestCase  $case  The running test, whose signed-in user asks.
 * @param  string  $handle  The handle of the form whose editor to open.
 * @return TestResponse<Response> The editor page, asserted to have opened.
 *
 * @throws RuntimeException When the editor route does not resolve to a URL.
 */
function fieldsEditorPage(TestCase $case, string $handle): TestResponse
{
    // Resolve the editor route, failing when it does not resolve
    $url = cp_route('blueprints.forms.edit', $handle);
    if (! is_string($url)) {
        throw new RuntimeException('The fields editor route did not resolve.');
    }

    // Open the editor page and expect it to load
    return $case->get($url)->assertOk();
}

/**
 * The titles the editor page's form breadcrumb links, sorted.
 *
 * @param  TestResponse<Response>  $response  The fields editor page.
 * @return list<string> The title of every breadcrumb link, sorted.
 */
function linkedTitles(TestResponse $response): array
{
    // Collect the link titles from the page's Inertia props
    $titles = [];
    $response->assertInertia(static function (AssertableInertia $page) use (&$titles): void {
        // Read the pushed breadcrumbs, failing when the page has none
        $props = $page->toArray()['props'];
        $crumbs = is_array($props) ? ($props[BlueprintBreadcrumbs::PROP] ?? null) : null;
        if (! is_array($crumbs)) {
            throw new RuntimeException('The page has no pushed breadcrumbs.');
        }

        // Gather the title of every link under every crumb
        foreach ($crumbs as $crumb) {
            foreach (is_array($crumb) && is_array($crumb['links'] ?? null) ? $crumb['links'] : [] as $link) {
                $titles[] = is_array($link) && is_string($link['display'] ?? null) ? $link['display'] : throw new RuntimeException('A link has no title.');
            }
        }
    });

    // Sort the titles so the order is stable
    sort($titles);

    return $titles;
}

/**
 * The breadcrumb prop an Inertia response carries, or null.
 *
 * Read from the response's own props: inertia-laravel 2 merges shared props into
 * them at render and 3 keeps them apart, so only this prop is compared.
 *
 * @param  InertiaResponse  $response  The Inertia response to read.
 * @return mixed The breadcrumb prop, or null when the response has none.
 */
function breadcrumbProp(InertiaResponse $response): mixed
{
    // Read the response's own props through reflection
    $props = new ReflectionProperty(InertiaResponse::class, 'props')->getValue($response);

    // Return the breadcrumb prop, if any
    return is_array($props) ? ($props[BlueprintBreadcrumbs::PROP] ?? null) : null;
}

it('links only the forms whose editor the user may open', function (): void {
    // An es-only user may open only the es-only form's editor, so link no other form
    $this->actingAs(userWith('fields-es', ['access cp', 'configure form fields', 'access es site']));

    // Open the es-only form's fields editor
    $response = fieldsEditorPage($this, 'spanish');

    // Expect no form linked and no en-only title in the page
    expect(linkedTitles($response))->toBe([])
        ->and($response->getContent())->not->toContain('Title of english');
});

it('links every other form for a user who may open every editor', function (string $kind): void {
    // Sign in as a super user or a fields configurer of both sites
    $this->actingAs($kind === 'super' ? superUser() : userWith('fields-both', ['access cp', 'configure form fields', 'access en site', 'access es site']));

    // Open the es-only form's fields editor
    $response = fieldsEditorPage($this, 'spanish');

    // The positive control for the HTML assertion above: an allowed title is shown
    expect(linkedTitles($response))->toBe(['Title of english', 'Title of everywhere', 'Title of shared'])
        ->and($response->getContent())->toContain('Title of english');
})->with(['a super user' => ['super'], 'a user with both sites' => ['both']]);

it('filters the breadcrumb on an Inertia visit as on a full page load', function (): void {
    // Sign in as a fields configurer who can access only es
    $this->actingAs(userWith('fields-es', ['access cp', 'configure form fields', 'access es site']));

    // Resolve the es-only form's editor route, failing when it does not resolve
    $url = cp_route('blueprints.forms.edit', 'spanish');
    if (! is_string($url)) {
        throw new RuntimeException('The fields editor route did not resolve.');
    }

    // Visit the editor as Inertia does and read the breadcrumb prop
    $version = Inertia::getVersion();
    $crumbs = $this->get($url, ['X-Inertia' => 'true', 'X-Inertia-Version' => $version])->assertOk()->json('props.'.BlueprintBreadcrumbs::PROP);

    // Expect the current form kept and the en-only form left out
    expect($crumbs)->toBeArray()
        ->and(json_encode($crumbs))->not->toContain('Title of english')
        ->and(json_encode($crumbs))->toContain('Title of spanish');
});

it('keeps the current form and the forms crumb, and every link that names no fields editor', function (): void {
    // Sign in as a fields configurer who can access only es
    $this->actingAs(userWith('fields-es', ['access cp', 'configure form fields', 'access es site']));

    // Filter crumbs that mix an editor link with other links and entries
    $crumbs = BlueprintBreadcrumbs::openable([
        ['display' => 'Forms', 'links' => []],
        ['display' => 'Title of spanish', 'links' => [
            ['display' => 'Title of english', 'url' => cp_route('blueprints.forms.edit', 'english')],
            ['display' => 'Elsewhere', 'url' => 'http://localhost/cp/elsewhere'],
            'not a link',
        ]],
        'not a crumb',
        ['display' => 'No links'],
    ]);

    // Expect only the en-only editor link removed
    expect($crumbs)->toBe([
        ['display' => 'Forms', 'links' => []],
        ['display' => 'Title of spanish', 'links' => [
            ['display' => 'Elsewhere', 'url' => 'http://localhost/cp/elsewhere'],
            'not a link',
        ]],
        'not a crumb',
        ['display' => 'No links'],
    ]);
});

it('leaves every other response alone', function (?string $routeName, bool $inertia): void {
    // Sign in as a fields configurer who can access only es
    $this->actingAs(userWith('fields-es', ['access cp', 'configure form fields', 'access es site']));

    // Build a request, on the named route when the case gives one
    $request = Request::create('/cp/somewhere');
    if ($routeName !== null) {
        $route = new Route('GET', 'cp/somewhere', [])->name($routeName);
        $route->bind($request);
        $request->setRouteResolver(static fn (): Route => $route);
    }

    // Prepare an Inertia or plain response through the listener
    $response = $inertia ? Inertia::render('Page', [BlueprintBreadcrumbs::PROP => 'as given']) : new Response('plain');
    BlueprintBreadcrumbs::preparing(new PreparingResponse($request, $response));

    // Expect the response left as it was given
    expect($response instanceof InertiaResponse ? breadcrumbProp($response) : $response->getContent())->toBe($inertia ? 'as given' : 'plain');
})->with([
    'an Inertia page on another route' => ['statamic.cp.forms.edit', true],
    'an Inertia page with no route' => [null, true],
    'a plain response on the editor route' => [BlueprintBreadcrumbs::ROUTE, false],
]);

it('leaves a response alone whose request is not a Laravel request', function (): void {
    // Prepare an Inertia response for a plain Symfony request
    $response = Inertia::render('Page', [BlueprintBreadcrumbs::PROP => 'as given']);
    BlueprintBreadcrumbs::preparing(new PreparingResponse(SymfonyRequest::create('/cp/fields/blueprints/forms/spanish/edit'), $response));

    // Expect the breadcrumb prop left as it was given
    expect(breadcrumbProp($response))->toBe('as given');
});

it('listens for responses being prepared', function (): void {
    // Build a request on the fields editor route
    $request = Request::create('/cp/fields/blueprints/forms/spanish/edit');
    $route = new Route('GET', 'cp/fields/blueprints/forms/{form}/edit', [])->name(BlueprintBreadcrumbs::ROUTE);
    $route->bind($request);

    // Resolve the request to that route and sign in as a super user
    $request->setRouteResolver(static fn (): Route => $route);
    $this->actingAs(superUser());

    // Dispatch the preparing event for an Inertia response
    $response = Inertia::render('Page');
    event(new PreparingResponse($request, $response));

    // Nothing was pushed, so the filtered list is empty rather than absent
    expect(breadcrumbProp($response))->toBe([]);
});
