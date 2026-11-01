<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Statamic\Auth\File\User as UserObject;
use Statamic\Events\FormCreating;
use Statamic\Facades\Form as FormFacade;
use Statamic\Facades\YAML;
use Statamic\Forms\Form;
use UAMSWeb\Aether\Forms\Multisite\Access\CreatorSites;
use UAMSWeb\Aether\Forms\Multisite\Tests\TestCase;

/**
 * A form created by a user who cannot access every site is assigned to the
 * sites they can access, so they can still edit it; every other creation is
 * left as core makes it (#79).
 *
 * @command  vendor/bin/pest tests/Integration/Access/CreatorSitesTest.php
 */
beforeEach(function (): void {
    // Configure two sites, a temporary forms directory and temporary roles
    useTwoSites();
    useTemporaryFormsDirectory();
    $this->rolesFile = useTemporaryRoles();
});

afterEach(function (): void {
    // Remove the stored forms and the temporary roles file
    removeFormsDirectory();
    File::delete($this->rolesFile);
});

/**
 * A user with `configure forms` who can access the given sites.
 *
 * @param  string  $id  The id to save the user under.
 * @param  list<string>  $sites  The handles of the sites the user can access.
 * @return UserObject The saved user.
 */
function creatorWith(string $id, array $sites): UserObject
{
    return userWith($id, ['access cp', 'configure forms', ...array_map(static fn (string $site): string => "access {$site} site", $sites)]);
}

/**
 * The parsed file of a created form.
 *
 * @param  string  $handle  The handle of the created form.
 * @return array<string, mixed> The form's YAML file, parsed.
 */
function createdFormFile(string $handle): array
{
    /** @var array<string, mixed> */
    return YAML::file(formsDirectory()."/{$handle}.yaml")->parse();
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
function creatorUrl(string $route, ?string $handle = null): string
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
 * Create a form through core's Control Panel endpoint, as the screen does.
 *
 * @param  TestCase  $test  The running test, whose signed-in user posts.
 * @param  string  $handle  The handle of the form to create.
 */
function createThroughScreen(TestCase $test, string $handle): void
{
    $test->postJson(creatorUrl('forms.store'), ['title' => ucfirst($handle), 'handle' => $handle])->assertOk();
}

it('assigns a form to the sites its creator can access when they cannot access every site', function (): void {
    // Sign in as a creator who can access only es
    $this->actingAs(creatorWith('es-creator', ['es']));

    // Create a form through the Control Panel screen
    createThroughScreen($this, 'spanish');

    // Expect the form assigned to es
    expect(createdFormFile('spanish')['sites'] ?? null)->toBe(['es']);
});

it("lets that creator open the new form's settings afterward", function (): void {
    // Sign in as a creator who can access only es
    $this->actingAs(creatorWith('es-creator', ['es']));

    // Create a form through the Control Panel screen
    createThroughScreen($this, 'spanish');

    // Expect the creator can open the new form's settings
    $this->get(creatorUrl('forms.edit', 'spanish'))->assertOk();
});

it('leaves the form without sites for a creator covering every site', function (string $kind): void {
    // Sign in as a super user or a creator who can access both sites
    $this->actingAs($kind === 'super' ? superUser() : creatorWith('both-creator', ['en', 'es']));

    // Create a form through the Control Panel screen
    createThroughScreen($this, 'everyone');

    // Expect the form saved without a sites list
    expect(createdFormFile('everyone'))->not->toHaveKey('sites');
})->with(['super', 'a user with both sites']);

it('leaves the form without sites on a single-site install, where every user covers the one site', function (): void {
    // Turn multisite off and sign in as a creator who can access only es
    config(['statamic.system.multisite' => false]);
    $this->actingAs(creatorWith('es-creator', ['es']));

    // Create a form through the Control Panel screen
    createThroughScreen($this, 'single');

    // Expect the form saved without a sites list
    expect(createdFormFile('single'))->not->toHaveKey('sites');
});

it('keeps sites a new form already lists', function (): void {
    // Sign in as a creator who can access only es
    $this->actingAs(creatorWith('es-creator', ['es']));

    // Make a form, failing if the repository returns another class
    $form = FormFacade::make('assigned');
    if (! $form instanceof Form) {
        throw new RuntimeException('The forms repository did not make a form.');
    }

    // Assign the new form to en and save it
    $form->set('sites', ['en']);
    $form->save();

    // Expect the form to keep en rather than take es
    expect(createdFormFile('assigned')['sites'] ?? null)->toBe(['en']);
});

it('assigns nothing with no signed-in user, as in a console command', function (): void {
    // Make a form, failing if the repository returns another class
    $form = FormFacade::make('console');
    if (! $form instanceof Form) {
        throw new RuntimeException('The forms repository did not make a form.');
    }

    // Save the form with nobody signed in
    $form->save();

    // Expect the form saved without a sites list
    expect(createdFormFile('console'))->not->toHaveKey('sites');
});

it('assigns nothing for a creator who can access no site', function (): void {
    // Sign in as a creator who can access no site
    $this->actingAs(creatorWith('no-site-creator', []));

    // Make a form, failing if the repository returns another class
    $form = FormFacade::make('nowhere');
    if (! $form instanceof Form) {
        throw new RuntimeException('The forms repository did not make a form.');
    }

    // Save the new form
    $form->save();

    // Expect the form saved without a sites list
    expect(createdFormFile('nowhere'))->not->toHaveKey('sites');
});

it("ignores a form that is not core's form class, rather than failing the save", function (): void {
    // Sign in as an es-only creator and make an object that is not a form
    $this->actingAs(creatorWith('es-creator', ['es']));
    $other = new stdClass;

    // Call the creating listener with that object
    CreatorSites::creating(new FormCreating($other));

    // Expect the object left untouched
    expect(get_object_vars($other))->toBe([]);
});

it('assigns only when the form is created, never when an existing form is saved again', function (): void {
    // Make a form, failing if the repository returns another class
    $form = FormFacade::make('existing');
    if (! $form instanceof Form) {
        throw new RuntimeException('The forms repository did not make a form.');
    }

    // Create the form with nobody signed in
    $form->save();

    // Save it again as a creator who can access only es
    $this->actingAs(creatorWith('es-creator', ['es']));
    $form->title('Existing, renamed');
    $form->save();

    // Expect the second save to leave the form without a sites list
    expect(createdFormFile('existing'))->not->toHaveKey('sites');
});
