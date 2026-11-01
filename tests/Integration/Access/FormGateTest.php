<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Statamic\Auth\File\User as UserObject;
use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Facades\Form as FormFacade;
use Statamic\Forms\Form;
use UAMSWeb\Aether\Forms\Multisite\Access\FormGate;

/**
 * Control Panel users are denied the forms of sites they cannot access, and
 * changing a form needs every site it reaches; `configure forms` does not bypass
 * either rule, only a super user does (#77).
 *
 * @command  vendor/bin/pest tests/Integration/Access/FormGateTest.php
 */

/** The forms every test stores, by handle, with the sites each lists. */
const GATE_FORMS = [
    'english' => ['en'],
    'shared' => ['en', 'es'],
    'everywhere' => [],
    'removed' => ['fr'],
];

beforeEach(function (): void {
    // Configure two sites, a temporary forms directory and temporary roles
    useTwoSites();
    useTemporaryFormsDirectory();
    $this->rolesFile = useTemporaryRoles();

    // Store each test form with the sites it lists
    foreach (GATE_FORMS as $handle => $sites) {
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
    }
});

afterEach(function (): void {
    // Remove the stored forms and the temporary roles file
    removeFormsDirectory();
    File::delete($this->rolesFile);
});

/**
 * The permission to view every stored form's submissions.
 *
 * @return list<string> One `view <handle> form submissions` per stored form.
 */
function viewEveryForm(): array
{
    return array_map(static fn (string $handle): string => "view {$handle} form submissions", array_keys(GATE_FORMS));
}

/**
 * A user of one of the kinds the tests compare, by name.
 *
 * @param  string  $kind  The kind of user, such as `super` or `es viewer`.
 * @return UserObject The user, saved with the permissions of that kind.
 *
 * @throws RuntimeException When the name is not one of the kinds.
 */
function gateUser(string $kind): UserObject
{
    // Name the permission sets the kinds combine
    $cp = ['access cp'];
    $es = ['access es site'];
    $both = ['access en site', 'access es site'];

    // Build the user of the named kind
    return match ($kind) {
        'super' => superUser(),
        'es configurer' => userWith('es-configurer', [...$cp, ...$es, 'configure forms', 'configure form fields']),
        'both configurer' => userWith('both-configurer', [...$cp, ...$both, 'configure forms', 'configure form fields']),
        'es viewer' => userWith('es-viewer', [...$cp, ...$es, ...viewEveryForm()]),
        'both viewer' => userWith('both-viewer', [...$cp, ...$both, ...viewEveryForm()]),
        default => throw new RuntimeException("No [{$kind}] user."),
    };
}

/**
 * A stored form by handle.
 *
 * @param  string  $handle  The handle of the stored form.
 * @return Form The stored form.
 *
 * @throws RuntimeException When no form with that handle is stored.
 */
function gateForm(string $handle): Form
{
    // Find the form, failing when none is stored under the handle
    $form = FormFacade::find($handle);
    if (! $form instanceof Form) {
        throw new RuntimeException("No [{$handle}] form is stored.");
    }

    // Return the stored form
    return $form;
}

/**
 * The URL of a Control Panel route, for a form when given.
 *
 * @param  string  $route  The Control Panel route name, without its prefix.
 * @param  string|null  $handle  The form handle the route takes, if any.
 * @return string The resolved URL.
 *
 * @throws RuntimeException When the route does not resolve to a URL.
 */
function gateUrl(string $route, ?string $handle = null): string
{
    // Resolve the route, with the form handle when one is given
    $url = $handle === null ? cp_route($route) : cp_route($route, $handle);

    // Fail when the route did not resolve to a URL
    if (! is_string($url)) {
        throw new RuntimeException("The [{$route}] route did not resolve.");
    }

    // Return the resolved URL
    return $url;
}

/**
 * Whether the gate allows a user an ability on a form.
 *
 * @param  string  $kind  The kind of user, as `gateUser()` names it.
 * @param  string  $ability  The ability to ask of the gate.
 * @param  string  $handle  The handle of the stored form.
 * @return bool True when the gate allows the ability.
 */
function gateAllows(string $kind, string $ability, string $handle): bool
{
    return Gate::forUser(gateUser($kind))->allows($ability, gateForm($handle));
}

it('decides view and edit by the user and the sites the form lists', function (string $kind, string $handle, bool $view, bool $edit): void {
    expect(gateAllows($kind, 'view', $handle))->toBe($view)
        ->and(gateAllows($kind, 'edit', $handle))->toBe($edit);
})->with([
    // A super user passes every rule, even for a site no longer configured
    'super, en-only form' => ['super', 'english', true, true],
    'super, en and es form' => ['super', 'shared', true, true],
    'super, global form' => ['super', 'everywhere', true, true],
    'super, form of a removed site' => ['super', 'removed', true, true],

    // configure forms no longer bypasses: edit needs every site the form reaches
    'es configurer, en-only form' => ['es configurer', 'english', false, false],
    'es configurer, en and es form' => ['es configurer', 'shared', true, false],
    'es configurer, global form' => ['es configurer', 'everywhere', true, false],
    'es configurer, form of a removed site' => ['es configurer', 'removed', false, false],
    'both configurer, en-only form' => ['both configurer', 'english', true, true],
    'both configurer, en and es form' => ['both configurer', 'shared', true, true],
    'both configurer, global form' => ['both configurer', 'everywhere', true, true],
    'both configurer, form of a removed site' => ['both configurer', 'removed', false, false],

    // A plain viewer keeps core's answer for every form it can reach
    'es viewer, en-only form' => ['es viewer', 'english', false, false],
    'es viewer, en and es form' => ['es viewer', 'shared', true, false],
    'es viewer, global form' => ['es viewer', 'everywhere', true, false],
    'both viewer, en-only form' => ['both viewer', 'english', true, false],
    'both viewer, en and es form' => ['both viewer', 'shared', true, false],
    'both viewer, global form' => ['both viewer', 'everywhere', true, false],
]);

it('treats deleting a form and editing its fields as changing it', function (string $ability): void {
    expect(gateAllows('es configurer', $ability, 'shared'))->toBeFalse()
        ->and(gateAllows('es configurer', $ability, 'everywhere'))->toBeFalse()
        ->and(gateAllows('both configurer', $ability, 'shared'))->toBeTrue()
        ->and(gateAllows('both configurer', $ability, 'everywhere'))->toBeTrue();
})->with(['delete', 'configure form fields']);

it('applies the site rule to a submission through its form', function (): void {
    // Make a submission to an en-only form and to an en and es form
    $english = gateForm('english')->makeSubmission();
    $shared = gateForm('shared')->makeSubmission();

    // Ask the gate as a configurer who can access only es
    $user = Gate::forUser(gateUser('es configurer'));

    // Expect the en-only submission refused and the shared one allowed
    expect($user->allows('view', $english))->toBeFalse()
        ->and($user->allows('delete', $english))->toBeFalse()
        ->and($user->allows('view', $shared))->toBeTrue()
        ->and($user->allows('delete', $shared))->toBeTrue();
});

it('leaves abilities asked of the form class to core', function (string $kind): void {
    // Ask the gate as the configurer under test
    $user = Gate::forUser(gateUser($kind));

    // Expect core to allow creating and listing forms
    expect($user->allows('create', FormContract::class))->toBeTrue()
        ->and($user->allows('index', FormContract::class))->toBeTrue();
})->with(['es configurer', 'both configurer']);

it('leaves every form to core on a single-site install', function (): void {
    // Turn multisite off
    config(['statamic.system.multisite' => false]);

    // Expect an es-only configurer allowed to edit any form
    expect(gateAllows('es configurer', 'edit', 'english'))->toBeTrue()
        ->and(gateAllows('es configurer', 'edit', 'everywhere'))->toBeTrue();
});

it('returns null for a subject that is neither a form nor a submission', function (): void {
    expect(FormGate::decide(gateUser('es viewer'), 'view', [configuredSite('en')]))->toBeNull()
        ->and(FormGate::decide(gateUser('es viewer'), 'view', []))->toBeNull()
        ->and(FormGate::decide(gateUser('es viewer'), 'view', ['english']))->toBeNull();
});

it('never grants: it returns null where it does not deny', function (): void {
    expect(FormGate::decide(gateUser('both configurer'), 'edit', [gateForm('shared')]))->toBeNull()
        ->and(FormGate::decide(superUser(), 'edit', [gateForm('removed')]))->toBeNull()
        ->and(FormGate::decide(gateUser('es configurer'), 'edit', [gateForm('shared')]))->toBeFalse();
});

it('does not list an en-only form on the forms index for an es-only user', function (): void {
    // Sign in as a viewer who can access only es
    $this->actingAs(gateUser('es viewer'));

    // Open the forms index and read its page data
    /** @var array{props: array{forms: list<array{id: string, can_edit: bool}>}} $page */
    $page = $this->get(gateUrl('forms.index'))->assertOk()->viewData('page');

    // Expect only the forms that reach es listed
    expect(array_column($page['props']['forms'], 'id'))->toEqualCanonicalizing(['shared', 'everywhere']);
});

it('lists every form the user can reach, and only the ones it may change as editable', function (): void {
    // Sign in as a configurer who can access only es
    $this->actingAs(gateUser('es configurer'));

    // Open the forms index and read its page data
    /** @var array{props: array{forms: list<array{id: string, can_edit: bool}>}} $page */
    $page = $this->get(gateUrl('forms.index'))->assertOk()->viewData('page');

    // Expect the reachable forms listed, none of them editable
    expect(array_column($page['props']['forms'], 'can_edit', 'id'))->toEqual(['everywhere' => false, 'shared' => false]);
});

it('refuses an es-only user the settings screen of an en and es form', function (string $kind): void {
    // Sign in as the es-only user under test
    $this->actingAs(gateUser($kind));

    // Expect the settings screen of the shared form refused
    $this->getJson(gateUrl('forms.edit', 'shared'))->assertForbidden();
})->with(['es configurer', 'es viewer']);

it('opens the settings screen to a user with every site the form reaches', function (): void {
    // Sign in as a configurer who can access both sites
    $this->actingAs(gateUser('both configurer'));

    // Expect the settings screen of the shared form opened
    $this->get(gateUrl('forms.edit', 'shared'))->assertOk();
});
