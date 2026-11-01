<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Statamic\Auth\File\User;
use Statamic\Facades\Form as FormFacade;
use Statamic\Facades\YAML;
use Statamic\Forms\Form;
use Symfony\Component\HttpFoundation\Response;
use UAMSWeb\Aether\Forms\Multisite\Access\AccessibleSites;
use UAMSWeb\Aether\Forms\Multisite\Tests\TestCase;

/**
 * A non-super user may save a form's sites only as a selection they could still
 * edit the form under: no site they cannot access, and no empty selection unless
 * they can access every site (#118, #138).
 *
 * @command  vendor/bin/pest tests/Integration/Access/AccessibleSitesTest.php
 */
beforeEach(function (): void {
    // Configure two sites, a temporary forms directory and temporary roles
    useTwoSites();
    useTemporaryFormsDirectory();
    $this->rolesFile = useTemporaryRoles();

    // Make the contact form, failing if the repository returns another class
    $form = FormFacade::make('contact');
    if (! $form instanceof Form) {
        throw new RuntimeException('The forms repository did not make a form.');
    }

    // Save the contact form assigned to en
    $form->title('Contact');
    $form->set('sites', ['en']);
    $form->save();
});

afterEach(function (): void {
    // Remove the stored forms and the temporary roles file
    removeFormsDirectory();
    File::delete($this->rolesFile);
});

/**
 * A user who may configure forms, with access to the given sites.
 *
 * @param  string  $id  The id to save the user under.
 * @param  list<string>  $sites  The handles of the sites the user can access.
 * @return User The saved user.
 */
function formsEditor(string $id, array $sites): User
{
    return userWith($id, ['access cp', 'configure forms', ...array_map(static fn (string $site): string => "access {$site} site", $sites)]);
}

/**
 * Save the form's settings with the given sites, or without a sites value for
 * null.
 *
 * @param  TestCase  $case  The running test, whose signed-in user saves.
 * @param  array<array-key, mixed>|string|null  $sites  The sites value to send.
 * @return TestResponse<Response> The response to the save.
 *
 * @throws RuntimeException When the update route does not resolve to a URL.
 */
function saveSites(TestCase $case, array|string|null $sites): TestResponse
{
    // Build the settings payload, adding the sites value when one is given
    $payload = ['title' => 'Contact', 'honeypot' => 'trap', 'store' => false, 'email' => []];
    if ($sites !== null) {
        $payload['sites'] = $sites;
    }

    // Resolve the update route, failing when it does not resolve
    $url = cp_route('forms.update', 'contact');
    if (! is_string($url)) {
        throw new RuntimeException('The [forms.update] route did not resolve.');
    }

    // Send the settings to the update route
    return $case->patchJson($url, $payload);
}

/**
 * The sites the form file lists, or null when it lists none.
 *
 * @return mixed The file's sites value, or null when it has none.
 */
function savedSites(): mixed
{
    // Read and parse the contact form's file
    $directory = config('statamic.forms.forms');
    $data = YAML::parse(File::get((is_string($directory) ? $directory : '').'/contact.yaml'));

    // Return the sites it lists, if any
    return $data['sites'] ?? null;
}

it('lets a user save the sites they can access', function (): void {
    // Sign in as an editor who can access only en
    $this->actingAs(formsEditor('en-only', ['en']));

    // Expect saving the settings with en accepted
    saveSites($this, ['en'])->assertOk();

    // Expect the file to list en
    expect(savedSites())->toBe(['en']);
});

it('refuses a site the user cannot access, naming it, and leaves the file alone', function (array $sites): void {
    // Sign in as an editor who can access only en
    $this->actingAs(formsEditor('en-only', ['en']));

    // Expect the save refused, naming Spanish
    saveSites($this, $sites)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['sites' => 'This form cannot be assigned to a site you cannot access: Spanish.']);

    // Expect the file to still list en only
    expect(savedSites())->toBe(['en']);
})->with([
    'another site beside their own' => [['en', 'es']],
    'only another site' => [['es']],
]);

it('refuses an empty or missing selection from a user who cannot access every site', function (array|string|null $sites): void {
    // Sign in as an editor who can access only en
    $this->actingAs(formsEditor('en-only', ['en']));

    // Expect the save refused, asking for a choice of sites
    saveSites($this, $sites)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['sites' => 'Choose the sites this form belongs to. Leaving Sites empty would put it on every site, including sites you cannot access.']);

    // Expect the file to still list en only
    expect(savedSites())->toBe(['en']);
})->with([
    'an empty selection' => [[]],
    'no sites value sent' => [null],
    'an empty string' => [''],
    'a blank string, which the Control Panel trims to an empty one' => [' '],
    'a list holding only a blank handle' => [[' ']],
]);

it('lets a user who can access every site save any sites, or none', function (?array $sites, mixed $saved): void {
    // Sign in as an editor who can access both sites
    $this->actingAs(formsEditor('both', ['en', 'es']));

    // Expect the save accepted
    saveSites($this, $sites)->assertOk();

    // Expect the file to list the saved sites, or none
    expect(savedSites())->toBe($saved);
})->with([
    'both sites' => [['en', 'es'], ['en', 'es']],
    'an empty selection' => [[], null],
]);

it('lets a super user save a site and an empty selection', function (?array $sites, mixed $saved): void {
    // Sign in as a super user
    $this->actingAs(superUser());

    // Expect the save accepted
    saveSites($this, $sites)->assertOk();

    // Expect the file to list the saved sites, or none
    expect(savedSites())->toBe($saved);
})->with([
    'another site' => [['es'], ['es']],
    'an empty selection' => [[], null],
]);

it('passes any selection when no user is signed in', function (): void {
    // Validate an empty selection with nobody signed in, noting any failure
    $failed = false;
    new AccessibleSites()->validate('sites', [], static function () use (&$failed): void {
        $failed = true;
    });

    // Expect the rule not to fail
    expect($failed)->toBeFalse();
});

it('leaves a selection of entries that are not handles to the configured sites rule', function (array $sites): void {
    // Sign in as an editor who can access only en
    $this->actingAs(formsEditor('en-only', ['en']));

    // Validate the selection, collecting every failure message
    $messages = [];
    new AccessibleSites()->validate('sites', $sites, static function (string $message) use (&$messages): void {
        $messages[] = $message;
    });

    // Expect no message
    expect($messages)->toBe([]);
})->with([
    'a number' => [[123]],
    'a nested list' => [[['es']]],
    'a nested list beside a blank handle' => [[' ', ['es']]],
]);

it('counts a blank handle as no site even when nothing trimmed it first', function (): void {
    // Sign in as an editor who can access only en
    $this->actingAs(formsEditor('en-only', ['en']));

    // Validate an untrimmed blank handle, collecting every failure message
    $messages = [];
    new AccessibleSites()->validate('sites', [' '], static function (string $message) use (&$messages): void {
        $messages[] = $message;
    });

    // Expect one message asking for a choice of sites
    expect($messages)->toHaveCount(1)->and($messages[0])->toStartWith('Choose the sites this form belongs to.');
});

it('lets a user who can access every site, or a super user, send no sites value', function (bool $super): void {
    // Sign in as a super user or an editor who can access both sites
    $this->actingAs($super ? superUser() : formsEditor('both', ['en', 'es']));

    // Expect a save with no sites value accepted
    saveSites($this, null)->assertOk();

    // Expect the file to list no sites
    expect(savedSites())->toBeNull();
})->with([
    'every site' => [false],
    'super user' => [true],
]);

it('leaves a handle naming no configured site to the configured sites rule', function (): void {
    // Sign in as an editor who can access only en
    $this->actingAs(formsEditor('en-only', ['en']));

    // Validate a selection with an unknown handle, collecting every message
    $messages = [];
    new AccessibleSites()->validate('sites', ['en', 'nope', ['es']], static function (string $message) use (&$messages): void {
        $messages[] = $message;
    });

    // Expect no message
    expect($messages)->toBe([]);
});
