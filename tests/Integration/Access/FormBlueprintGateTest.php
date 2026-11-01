<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\File;
use Statamic\Auth\File\User as UserObject;
use Statamic\Facades\Form as FormFacade;
use Statamic\Fields\Blueprint;
use Statamic\Fields\BlueprintRepository;
use Statamic\Forms\Form;
use Symfony\Component\HttpFoundation\Response;
use UAMSWeb\Aether\Forms\Multisite\Http\Middleware\AuthorizeFormBlueprint;

/**
 * Core's form blueprint editor, the screen and the save, is refused for a form
 * the user may not change under the site rules, as its settings screen is (#116).
 *
 * @command  vendor/bin/pest tests/Integration/Access/FormBlueprintGateTest.php
 */

/** The forms every test stores, by handle, with the sites each lists. */
const BLUEPRINT_FORMS = [
    'english' => ['en'],
    'shared' => ['en', 'es'],
    'spanish' => ['es'],
];

beforeEach(function (): void {
    // Configure two sites, a temporary forms directory and temporary roles
    useTwoSites();
    useTemporaryFormsDirectory();
    $this->rolesFile = useTemporaryRoles();

    // The editor's save writes blueprints, so give them this test's own directory
    $this->blueprintsDirectory = sys_get_temp_dir().'/msf-blueprints-'.uniqid('', true);
    app(BlueprintRepository::class)->setDirectories($this->blueprintsDirectory);

    // Store each test form with the sites it lists
    foreach (BLUEPRINT_FORMS as $handle => $sites) {
        // Make the form, failing if the repository returns another class
        $form = FormFacade::make($handle);
        if (! $form instanceof Form) {
            throw new RuntimeException('The forms repository did not make a form.');
        }

        // Title the form, assign its sites and save it
        $form->title(ucfirst($handle));
        $form->set('sites', $sites);
        $form->save();
    }
});

afterEach(function (): void {
    // Remove the stored forms, the roles file and the blueprints directory
    removeFormsDirectory();
    File::delete($this->rolesFile);
    File::deleteDirectory($this->blueprintsDirectory);
});

/**
 * A user with `configure form fields` who can access the given sites.
 *
 * @param  string  $id  The id to save the user under.
 * @param  list<string>  $sites  The handles of the sites the user can access.
 * @return UserObject The saved user.
 */
function fieldsConfigurer(string $id, array $sites): UserObject
{
    return userWith($id, ['access cp', 'configure form fields', ...array_map(static fn (string $site): string => "access {$site} site", $sites)]);
}

/**
 * The URL of a blueprint editor route for a form.
 *
 * @param  string  $route  The Control Panel route name, without its prefix.
 * @param  string  $handle  The form handle the route takes.
 * @return string The resolved URL.
 *
 * @throws RuntimeException When the route does not resolve to a URL.
 */
function blueprintUrl(string $route, string $handle): string
{
    // Resolve the route for the form
    $url = cp_route($route, $handle);

    // Fail when the route did not resolve to a URL
    if (! is_string($url)) {
        throw new RuntimeException("The [{$route}] route did not resolve.");
    }

    // Return the resolved URL
    return $url;
}

/**
 * A blueprint the editor's save accepts: one tab with one text field.
 *
 * @return array{tabs: list<array<string, mixed>>} The blueprint payload.
 */
function blueprintPayload(): array
{
    return ['tabs' => [[
        '_id' => 'main',
        'handle' => 'main',
        'display' => 'Main',
        'sections' => [[
            '_id' => 'section',
            'display' => 'Fields',
            'fields' => [[
                '_id' => 'msf_probe',
                'handle' => 'msf_probe',
                'type' => 'inline',
                'config' => ['type' => 'text', 'display' => 'Name'],
            ]],
        ]],
    ]]];
}

/**
 * Whether a stored form's blueprint has the field the test payload adds.
 *
 * @param  string  $handle  The handle of the stored form.
 * @return bool True when the blueprint has the `msf_probe` field.
 *
 * @throws RuntimeException When the form or its blueprint is missing.
 */
function hasProbeField(string $handle): bool
{
    // Find the form's blueprint, failing when either is missing
    $form = FormFacade::find($handle);
    $blueprint = $form instanceof Form ? $form->blueprint() : null;
    if (! $blueprint instanceof Blueprint) {
        throw new RuntimeException("No [{$handle}] form blueprint.");
    }

    // Check whether the blueprint has the probe field
    return $blueprint->fields()->has('msf_probe') === true;
}

it('refuses the screen and the save to a user without every site of the form', function (string $handle): void {
    // Sign in as a fields configurer who can access only es
    $this->actingAs(fieldsConfigurer('es-fields', ['es']));

    // Expect the editor screen and its save both refused
    $this->getJson(blueprintUrl('blueprints.forms.edit', $handle))->assertForbidden();
    $this->patchJson(blueprintUrl('blueprints.forms.update', $handle), blueprintPayload())->assertForbidden();

    // Expect the blueprint left without the probe field
    expect(hasProbeField($handle))->toBeFalse();
})->with(['an en-only form' => ['english'], 'an en and es form' => ['shared']]);

it('opens and saves the editor for a user with every site of the form', function (string $kind, string $handle): void {
    // Sign in as a super user or a fields configurer of the given sites
    $this->actingAs($kind === 'super' ? superUser() : fieldsConfigurer('fields-'.$kind, $kind === 'both' ? ['en', 'es'] : ['es']));

    // Expect the editor screen opened and its save accepted
    $this->get(blueprintUrl('blueprints.forms.edit', $handle))->assertOk();
    $this->patchJson(blueprintUrl('blueprints.forms.update', $handle), blueprintPayload())->assertOk();

    // Expect the blueprint saved with the probe field
    expect(hasProbeField($handle))->toBeTrue();
})->with([
    'an es-only user, es-only form' => ['es', 'spanish'],
    'a user with both sites, en and es form' => ['both', 'shared'],
    'a super user, en-only form' => ['super', 'english'],
]);

it('leaves a missing form to core', function (): void {
    // Sign in as a fields configurer who can access only es
    $this->actingAs(fieldsConfigurer('es-fields', ['es']));

    // Expect core's not-found answer for a form that does not exist
    $this->getJson(blueprintUrl('blueprints.forms.edit', 'missing'))->assertNotFound();
});

it('passes every other Control Panel route through, even one naming the form', function (): void {
    // The user may read the en and es form's submissions, which needs one of its
    // sites, though its blueprint would need both
    $this->actingAs(userWith('es-reader', ['access cp', 'configure form fields', 'access es site', 'view shared form submissions']));

    // Expect the shared form's submissions listing to open
    $this->getJson(blueprintUrl('forms.submissions.index', 'shared'))->assertOk();
});

it('authorizes a form handle the route has not bound yet', function (): void {
    // Sign in as a fields configurer who can access only es
    $this->actingAs(fieldsConfigurer('es-fields', ['es']));

    // Build an editor request whose route holds the en-only handle unbound
    $route = new Route('GET', 'cp/fields/blueprints/forms/{form}/edit', [])->name('statamic.cp.blueprints.forms.edit');
    $request = Request::create('/cp/fields/blueprints/forms/english/edit');
    $route->bind($request);
    $request->setRouteResolver(static fn (): Route => $route);

    // Run the middleware, expecting it to refuse
    (new AuthorizeFormBlueprint)->handle($request, static fn (): Response => new Response);
})->throws(AuthorizationException::class);

it('passes a request with no route, or a handle naming no form, to the next middleware', function (?string $handle): void {
    // Without the permission, authorizing anything at all would refuse
    $this->actingAs(userWith('no-fields', ['access cp', 'access es site']));

    // Build an editor request for a form that does not exist
    $request = Request::create('/cp/fields/blueprints/forms/missing/edit');

    // Attach the editor route when the case names a handle
    if ($handle !== null) {
        $route = new Route('GET', 'cp/fields/blueprints/forms/{form}/edit', [])->name('statamic.cp.blueprints.forms.edit');
        $route->bind($request);
        $request->setRouteResolver(static fn (): Route => $route);
    }

    // Run the middleware with a next step that answers `next`
    $response = (new AuthorizeFormBlueprint)->handle($request, static fn (): Response => new Response('next'));

    // Expect the request passed on to the next step
    expect($response->getContent())->toBe('next');
})->with(['no route' => [null], 'a handle naming no form' => ['missing']]);

it('is appended to the authenticated Control Panel group', function (): void {
    expect(app('router')->getMiddlewareGroups()[AuthorizeFormBlueprint::GROUP] ?? [])->toContain(AuthorizeFormBlueprint::class);
});
