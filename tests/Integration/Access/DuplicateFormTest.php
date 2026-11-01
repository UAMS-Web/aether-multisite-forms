<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Statamic\Actions\DuplicateForm as CoreDuplicateForm;
use Statamic\Auth\File\User as UserObject;
use Statamic\Facades\Action;
use Statamic\Facades\Form as FormFacade;
use Statamic\Fields\BlueprintRepository;
use Statamic\Forms\Form;
use Symfony\Component\HttpFoundation\Response;
use UAMSWeb\Aether\Forms\Multisite\Actions\DuplicateForm;
use UAMSWeb\Aether\Forms\Multisite\Tests\TestCase;

/**
 * Core's Duplicate action on forms is refused for a form the user may not view
 * under the site rules, and still copies one they may (#117).
 *
 * @command  vendor/bin/pest tests/Integration/Access/DuplicateFormTest.php
 */

/** The forms every test stores, by handle, with the sites each lists. */
const DUPLICATE_FORMS = [
    'english' => ['en'],
    'shared' => ['en', 'es'],
    'spanish' => ['es'],
];

beforeEach(function (): void {
    // Configure two sites, a temporary forms directory and temporary roles
    useTwoSites();
    useTemporaryFormsDirectory();
    $this->rolesFile = useTemporaryRoles();

    // The action writes the copy's blueprint, so give blueprints this test's directory
    $this->blueprintsDirectory = sys_get_temp_dir().'/msf-blueprints-'.uniqid('', true);
    app(BlueprintRepository::class)->setDirectories($this->blueprintsDirectory);

    // Store each test form with the sites it lists
    foreach (DUPLICATE_FORMS as $handle => $sites) {
        // Make the form, failing if the repository returns another class
        $form = FormFacade::make($handle);
        if (! $form instanceof Form) {
            throw new RuntimeException('The forms repository did not make a form.');
        }

        // Title the form, give it an email and its sites, and save it
        $form->title(ucfirst($handle));
        $form->email([['to' => "{$handle}@example.com"]]);
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
 * A user with `configure forms` who can access the given sites.
 *
 * @param  string  $id  The id to save the user under.
 * @param  list<string>  $sites  The handles of the sites the user can access.
 * @return UserObject The saved user.
 */
function formsConfigurer(string $id, array $sites): UserObject
{
    return userWith($id, ['access cp', 'configure forms', ...array_map(static fn (string $site): string => "access {$site} site", $sites)]);
}

/**
 * A Control Panel action route's URL.
 *
 * @param  string  $route  The Control Panel route name, without its prefix.
 * @return string The resolved URL.
 *
 * @throws RuntimeException When the route does not resolve to a URL.
 */
function actionUrl(string $route): string
{
    // Resolve the route
    $url = cp_route($route);

    // Fail when the route did not resolve to a URL
    if (! is_string($url)) {
        throw new RuntimeException("The [{$route}] route did not resolve.");
    }

    // Return the resolved URL
    return $url;
}

/**
 * Run the Duplicate action on a form, copying it to the handle `copy`.
 *
 * @param  TestCase  $case  The running test, whose signed-in user posts.
 * @param  string  ...$handles  The handles of the forms to select.
 * @return TestResponse<Response> The response to the action request.
 */
function duplicate(TestCase $case, string ...$handles): TestResponse
{
    return $case->postJson(actionUrl('forms.actions.run'), [
        'action' => 'duplicate_form',
        'selections' => $handles,
        'values' => ['title' => 'Copy', 'handle' => 'copy'],
    ]);
}

/**
 * The handles of the actions the Control Panel offers for the given forms.
 *
 * @param  TestCase  $case  The running test, whose signed-in user posts.
 * @param  string  ...$handles  The handles of the forms to select.
 * @return list<mixed> The handle of each offered action.
 *
 * @throws RuntimeException When the response is not a list of actions.
 */
function offeredActions(TestCase $case, string ...$handles): array
{
    // Ask for the actions offered for the selection
    $actions = $case->postJson(actionUrl('forms.actions.bulk'), ['selections' => $handles])->assertOk()->json();

    // Fail when the response is not a list
    if (! is_array($actions)) {
        throw new RuntimeException('The action list is not an array.');
    }

    // Reduce each action to its handle
    return array_values(array_map(static fn (mixed $action): mixed => is_array($action) ? ($action['handle'] ?? null) : null, $actions));
}

/**
 * The handles of every stored form.
 *
 * @return list<mixed> The handle of each stored form.
 */
function storedHandles(): array
{
    return array_values(FormFacade::all()->map(static fn (mixed $form): mixed => $form instanceof Form ? $form->handle() : null)->all());
}

/**
 * The form the Duplicate action stored.
 *
 * @return Form The stored copy.
 *
 * @throws RuntimeException When no copy was stored.
 */
function storedCopy(): Form
{
    // Find the copy, failing when none was stored
    $copy = FormFacade::find('copy');
    if (! $copy instanceof Form) {
        throw new RuntimeException('No copy was stored.');
    }

    // Return the stored copy
    return $copy;
}

test('the duplicate is refused for a form of a site the user cannot access', function (): void {
    // Sign in as a configurer who can access only es
    $this->actingAs(formsConfigurer('es-configurer', ['es']));

    // Expect duplicating the en-only form refused
    duplicate($this, 'english')->assertForbidden();

    // Expect no copy stored
    expect(storedHandles())->not->toContain('copy');
});

test('the duplicate is refused when one of several forms is of another site', function (): void {
    // Sign in as a configurer who can access only es
    $this->actingAs(formsConfigurer('es-configurer', ['es']));

    // Expect duplicating an es form with an en form refused
    duplicate($this, 'spanish', 'english')->assertForbidden();

    // Expect no copy stored
    expect(storedHandles())->not->toContain('copy');
});

test('a form the user can view is duplicated with its sites', function (string $id, string $sites, string $source): void {
    // Sign in as a configurer of the given sites
    $this->actingAs(formsConfigurer($id, explode(',', $sites)));

    // Expect the duplicate accepted
    duplicate($this, $source)->assertOk();

    // Expect the copy to keep the source's sites, email and blueprint
    $copy = storedCopy();
    expect($copy->get('sites'))->toBe(DUPLICATE_FORMS[$source])
        ->and($copy->email())->toBe([['to' => "{$source}@example.com"]])
        ->and(File::exists($this->blueprintsDirectory.'/forms/copy.yaml'))->toBeTrue();
})->with([
    'es user, es form' => ['es-configurer', 'es', 'spanish'],
    'es user, shared form' => ['es-configurer', 'es', 'shared'],
    'both sites, en form' => ['both-configurer', 'en,es', 'english'],
]);

test('a super user duplicates a form of any site', function (): void {
    // Sign in as a super user
    $this->actingAs(superUser());

    // Expect duplicating the en-only form accepted
    duplicate($this, 'english')->assertOk();

    // Expect the copy assigned to en
    expect(storedCopy()->get('sites'))->toBe(['en']);
});

test('the action is not offered for a form of a site the user cannot access', function (): void {
    // Sign in as a configurer who can access only es
    $this->actingAs(formsConfigurer('es-configurer', ['es']));

    // Expect the action offered only for selections of es forms
    expect(offeredActions($this, 'english'))->not->toContain('duplicate_form')
        ->and(offeredActions($this, 'spanish', 'english'))->not->toContain('duplicate_form')
        ->and(offeredActions($this, 'spanish'))->toContain('duplicate_form')
        ->and(offeredActions($this, 'spanish', 'shared'))->toContain('duplicate_form');
});

test('the add-on action replaces the core action under the same handle', function (): void {
    expect(Action::get('duplicate_form'))->toBeInstanceOf(DuplicateForm::class)
        ->and(DuplicateForm::handle())->toBe(CoreDuplicateForm::handle())
        ->and(Action::all()->filter(static fn (mixed $action): bool => $action instanceof CoreDuplicateForm))->toHaveCount(1);
});

test('the action is refused for a selection that names no form', function (): void {
    // Make a configurer who can access only es
    $user = formsConfigurer('es-configurer', ['es']);

    // Expect a missing form refused and an es form allowed
    expect(app(DuplicateForm::class)->authorize($user, null))->toBeFalse()
        ->and(app(DuplicateForm::class)->authorize($user, FormFacade::find('spanish')))->toBeTrue();
});

test('a user who cannot create forms is refused even a form they can view', function (): void {
    // Make an es viewer without `configure forms`
    $user = userWith('es-viewer', ['access cp', 'access es site', 'view spanish form submissions']);

    // Expect the duplicate of the es form refused
    expect(app(DuplicateForm::class)->authorize($user, FormFacade::find('spanish')))->toBeFalse();
});
