<?php

declare(strict_types=1);

use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use Statamic\Facades\Form as FormFacade;
use Statamic\Facades\YAML;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;
use Statamic\Fields\Value;
use Statamic\Forms\Form;
use Statamic\Forms\Tags;
use Statamic\Http\Controllers\CP\Forms\FormsController;
use Statamic\Sites\Site as SiteObject;
use Statamic\Tags\Loader;
use UAMSWeb\Aether\Forms\Multisite\Forms\Assignment;
use UAMSWeb\Aether\Forms\Multisite\Forms\ConfigFields;
use UAMSWeb\Aether\Forms\Multisite\Forms\FormResolver;
use UAMSWeb\Aether\Forms\Multisite\Forms\Overrides;

/**
 * The form settings screen gains a Sites section whose selection is saved to the
 * form file as `sites`, through core's own edit and update endpoints (#70).
 *
 * @command  vendor/bin/pest tests/Integration/Forms/ConfigFieldsTest.php
 */
beforeEach(function (): void {
    // Configure two sites and a temporary forms directory
    useTwoSites();
    useTemporaryFormsDirectory();

    // Sign in as a super user
    $this->actingAs(superUser());

    // Save a global contact form
    $form = FormFacade::make('contact');
    $form->title('Contact');
    $form->save();
});

afterEach(function (): void {
    removeFormsDirectory();
});

/**
 * The settings a core form screen submits, with `sites` set when given.
 *
 * @param  array<array-key, mixed>|string|null  $sites  The sites value to send, or null to send none.
 * @return array<string, mixed> The settings payload.
 */
function settingsPayload(array|string|null $sites): array
{
    // Build core's own settings
    $payload = [
        'title' => 'Contact',
        'honeypot' => 'trap',
        'store' => false,
        'email' => [['to' => 'base@example.com', 'subject' => 'Base']],
    ];

    // Add the sites value when one is given
    if ($sites !== null) {
        $payload['sites'] = $sites;
    }

    return $payload;
}

/**
 * The URL of a form's settings screen, or of the endpoint it saves to.
 *
 * @param  string  $handle  Handle of the form.
 * @param  string  $route  Name of the Control Panel route.
 * @return string The resolved URL.
 *
 * @throws RuntimeException When the route does not resolve to a URL.
 */
function settingsUrl(string $handle, string $route = 'forms.edit'): string
{
    // Resolve the route, refusing if it does not give a URL
    $url = cp_route($route, $handle);
    if (! is_string($url)) {
        throw new RuntimeException("The [{$route}] route did not resolve.");
    }

    return $url;
}

/**
 * The settings payload with override rows, as the screen submits them: each
 * row's site a one-item list.
 *
 * @param  array<array-key, mixed>|string|null  $sites  The sites value to send, or null to send none.
 * @param  list<array<string, mixed>>  $rows  The override rows to send.
 * @return array<string, mixed> The settings payload with the rows.
 */
function overridesPayload(array|string|null $sites, array $rows): array
{
    return [...settingsPayload($sites), Overrides::KEY => $rows];
}

/**
 * A stored form, read back through core's repository.
 *
 * @param  string  $handle  Handle of the form.
 * @return Form The stored form.
 *
 * @throws RuntimeException When no form with that handle is stored.
 */
function storedForm(string $handle): Form
{
    // Find the form, refusing if it is not stored
    $form = FormFacade::find($handle);
    if (! $form instanceof Form) {
        throw new RuntimeException("No [{$handle}] form is stored.");
    }

    return $form;
}

/**
 * The parsed form file for a handle.
 *
 * @param  string  $handle  Handle of the form.
 * @return array<string, mixed> The file's parsed YAML.
 */
function formFile(string $handle): array
{
    /** @var array<string, mixed> */
    return YAML::file(formsDirectory()."/{$handle}.yaml")->parse();
}

/**
 * The Inertia page props of a settings screen response.
 *
 * @param  TestResponse<Response>  $response  The settings screen's response.
 * @return array<string, mixed> The page's props.
 */
function screenProps(TestResponse $response): array
{
    // Read the Inertia page from the view data
    /** @var array{props: array<string, mixed>} $page */
    $page = $response->viewData('page');

    return $page['props'];
}

/**
 * The fields of one section of a settings screen, keyed by handle.
 *
 * @param  array<string, mixed>  $props  The screen's page props.
 * @param  string  $display  Display name of the section to read.
 * @return array<string, array<string, mixed>> The section's fields, by handle.
 */
function screenSection(array $props, string $display): array
{
    // Read the blueprint from the props
    /** @var array{tabs: list<array{sections: list<array{display: string, fields: list<array<string, mixed>>}>}>} $blueprint */
    $blueprint = $props['blueprint'];

    // Collect the fields of every section with that display name
    $fields = [];
    foreach ($blueprint['tabs'] as $tab) {
        foreach ($tab['sections'] as $section) {
            // Skip any other section
            if ($section['display'] !== $display) {
                continue;
            }

            // Key each field that has a handle by it
            foreach ($section['fields'] as $field) {
                if (is_string($field['handle'] ?? null)) {
                    $fields[$field['handle']] = $field;
                }
            }
        }
    }

    return $fields;
}

it('adds a Sites section holding a sites field to the form settings screen', function (): void {
    // Open the settings screen
    $response = $this->get(settingsUrl('contact'))->assertOk();

    // Read its Sites section
    $fields = screenSection(screenProps($response), 'Sites');

    // Expect a sites field of type sites
    expect($fields)->toHaveKey('sites')
        ->and($fields['sites']['type'] ?? null)->toBe('sites');
});

it('labels the field, explains an empty selection, and offers the sites as a select', function (): void {
    // Read the sites field from the settings screen
    $field = screenSection(screenProps($this->get(settingsUrl('contact'))->assertOk()), 'Sites')['sites'] ?? [];

    // Expect its label, instructions and select mode
    expect($field['display'] ?? null)->toBe('Sites')
        ->and($field['instructions'] ?? null)->toBe('The sites this form belongs to. Leave it empty to use the form on every site, which only an editor who can access every site may do.')
        ->and($field['mode'] ?? null)->toBe('select');
});

it('does not add the field to any other section', function (): void {
    // Read the settings screen's props
    $props = screenProps($this->get(settingsUrl('contact'))->assertOk());

    // Expect the field only in the Sites section
    expect(screenSection($props, 'Fields'))->not->toHaveKey('sites')
        ->and(screenSection($props, 'Sites'))->toHaveCount(2);
});

it('adds the section to every form, not only one handle', function (): void {
    // Read the extra config for an arbitrary handle
    $config = FormFacade::extraConfigFor('any_form');

    // Expect the Sites section with its sites field
    expect($config)->toHaveKey('sites')
        ->and(data_get($config, 'sites.fields'))->toHaveKey('sites');
});

it('saves the selected sites to the form file and shows them on the screen again', function (array $sites): void {
    // Save the settings with the given sites
    $this->patchJson(settingsUrl('contact', 'forms.update'), settingsPayload($sites))->assertOk();

    // Expect the sites in the file and on the stored form
    expect(formFile('contact')['sites'] ?? null)->toBe($sites)
        ->and(Assignment::sites(storedForm('contact')))->toBe($sites);

    // Read the values the screen shows
    /** @var array{sites?: mixed} $values */
    $values = screenProps($this->get(settingsUrl('contact'))->assertOk())['values'];

    // Expect the screen to show the sites again
    expect($values['sites'] ?? null)->toBe($sites);
})->with([
    'one site' => [['es']],
    'both sites' => [['en', 'es']],
]);

it('leaves the sites key out of the file when no site is selected, so the form is global', function (?array $sites): void {
    // Save the settings with Spanish and expect it in the file
    $this->patchJson(settingsUrl('contact', 'forms.update'), settingsPayload(['es']))->assertOk();
    expect(formFile('contact')['sites'] ?? null)->toBe(['es']);

    // Save the settings again with no site selected
    $this->patchJson(settingsUrl('contact', 'forms.update'), settingsPayload($sites))->assertOk();

    // Expect the key gone and the form global
    expect(formFile('contact'))->not->toHaveKey('sites')
        ->and(Assignment::isGlobal(storedForm('contact')))->toBeTrue();
})->with([
    'an empty selection' => [[]],
    'no sites value sent' => [null],
]);

it('refuses a selection naming a site the install does not define, and leaves the file alone', function (array|string $sites): void {
    // Save the settings with Spanish
    $this->patchJson(settingsUrl('contact', 'forms.update'), settingsPayload(['es']))->assertOk();

    // Save the invalid selection and expect it refused
    $this->patchJson(settingsUrl('contact', 'forms.update'), settingsPayload($sites))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('sites');

    // Expect the file still to hold Spanish
    expect(formFile('contact')['sites'] ?? null)->toBe(['es']);
})->with([
    'an unknown handle' => [['nope']],
    'a known handle beside an unknown one' => [['es', 'nope']],
    'a handle that is not a string' => [[['es']]],
    'a bare handle rather than a list' => ['nope'],
]);

it('renders the form tag of an assigned form, exposing its sites to the template', function (): void {
    // Save the settings with both sites
    $this->patchJson(settingsUrl('contact', 'forms.update'), settingsPayload(['en', 'es']))->assertOk();

    // With no content, core's tag returns the data a template would receive.
    $tag = app(Loader::class)->load('form', [
        'parser' => null,
        'params' => ['in' => 'contact'],
        'content' => '',
        'context' => [],
        'tag' => 'form:create',
        'tag_method' => 'create',
    ]);
    if (! $tag instanceof Tags) {
        throw new RuntimeException('The form tag did not load.');
    }

    // Read the sites from form_config, unwrapping an augmented value
    $sites = data_get($tag->create(), 'form_config.sites');
    $sites = $sites instanceof Value ? $sites->value() : $sites;
    if (! is_iterable($sites)) {
        throw new RuntimeException('The form tag exposed no sites.');
    }

    // Reduce each site to its handle
    $handles = [];
    foreach ($sites as $site) {
        $handles[] = $site instanceof SiteObject ? $site->handle() : $site;
    }

    // Expect both sites
    expect($handles)->toBe(['en', 'es']);
});

it('writes core keys exactly as core writes them, adding only the sites key', function (): void {
    // Save a second form to stay global
    $global = FormFacade::make('global_contact');
    $global->title('Contact');
    $global->save();

    // Save one with Spanish and the other with no sites
    $this->patchJson(settingsUrl('contact', 'forms.update'), settingsPayload(['es']))->assertOk();
    $this->patchJson(settingsUrl('global_contact', 'forms.update'), settingsPayload([]))->assertOk();

    // Read both files
    $assigned = formFile('contact');
    $unassigned = formFile('global_contact');

    // Expect them to differ only by the sites key
    expect(array_diff_key($assigned, $unassigned))->toBe(['sites' => ['es']])
        ->and(array_diff_key($assigned, ['sites' => true]))->toBe($unassigned)
        ->and($unassigned)->toBe([
            'title' => 'Contact',
            'honeypot' => 'trap',
            'email' => [['to' => 'base@example.com', 'subject' => 'Base']],
            'store' => false,
        ]);
});

it('adds a site overrides grid to the Sites section (#76)', function (): void {
    // Read the overrides field from the settings screen
    $field = screenSection(screenProps($this->get(settingsUrl('contact'))->assertOk()), 'Sites')[Overrides::KEY] ?? [];

    // Read its columns
    /** @var list<array{handle: string}> $columns */
    $columns = $field['fields'] ?? [];

    // Expect a grid with the four columns
    expect($field['type'] ?? null)->toBe('grid')
        ->and($field['display'] ?? null)->toBe('Site overrides')
        ->and(array_column($columns, 'handle'))->toBe(['site', 'title', 'store', 'email']);
});

it('saves override rows to the form file and shows them on the screen again (#76)', function (): void {
    // Save the settings with a Spanish override row
    $es = [['to' => 'es@example.com', 'subject' => 'ES']];

    $this->patchJson(settingsUrl('contact', 'forms.update'), overridesPayload(['en', 'es'], [
        ['site' => ['es'], 'title' => 'Contacto', 'store' => Overrides::DO_NOT_STORE, 'email' => $es],
    ]))->assertOk();

    // Read the rows from the file
    /** @var list<array<string, mixed>> $rows */
    $rows = formFile('contact')[Overrides::KEY] ?? [];

    // Expect the row stored with its site as a handle
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['site'] ?? null)->toBe('es')
        ->and($rows[0]['title'] ?? null)->toBe('Contacto')
        ->and($rows[0]['store'] ?? null)->toBe(Overrides::DO_NOT_STORE)
        ->and(data_get($rows, '0.email.0.to'))->toBe('es@example.com')
        ->and(data_get($rows, '0.email.0.subject'))->toBe('ES');

    // Read the values the screen shows
    /** @var array<string, mixed> $values */
    $values = screenProps($this->get(settingsUrl('contact'))->assertOk())['values'];

    // Expect the screen to show the row again
    expect(data_get($values, Overrides::KEY.'.0.title'))->toBe('Contacto')
        ->and(data_get($values, Overrides::KEY.'.0.store'))->toBe(Overrides::DO_NOT_STORE)
        ->and(data_get($values, Overrides::KEY.'.0.email.0.to'))->toBe('es@example.com');
});

it('resolves a saved row for its site, through the form the editor saved (#76)', function (): void {
    // Save the settings with a Spanish override row
    $this->patchJson(settingsUrl('contact', 'forms.update'), overridesPayload(['en', 'es'], [
        ['site' => ['es'], 'title' => 'Contacto', 'store' => Overrides::STORE],
    ]))->assertOk();

    // Read the stored form back
    $resolver = new FormResolver;
    $form = storedForm('contact');

    // Expect the override on Spanish only
    expect($resolver->resolve($form, 'es')?->title())->toBe('Contacto')
        ->and($resolver->resolve($form, 'es')?->store())->toBeTrue()
        ->and($resolver->resolve($form, 'en')?->title())->toBe('Contact')
        ->and($resolver->resolve($form, 'en')?->store())->toBeFalse();
});

it('leaves store out of a row whose store is left unset, so the form keeps its own (#76)', function (): void {
    // Save the settings with a row whose store is unset
    $this->patchJson(settingsUrl('contact', 'forms.update'), overridesPayload(['en', 'es'], [
        ['site' => ['es'], 'title' => 'Contacto', 'store' => null],
    ]))->assertOk();

    // Read the rows from the file
    /** @var list<array<string, mixed>> $rows */
    $rows = formFile('contact')[Overrides::KEY] ?? [];

    // Expect no store key on the row
    expect($rows[0] ?? [])->not->toHaveKey('store');
});

it('refuses a second row for the same site, naming the site, and leaves the file alone (#76)', function (): void {
    // Save the settings with Spanish and keep the file
    $this->patchJson(settingsUrl('contact', 'forms.update'), settingsPayload(['es']))->assertOk();
    $before = formFile('contact');

    // Save two Spanish rows and expect the save refused, naming Spanish
    $this->patchJson(settingsUrl('contact', 'forms.update'), overridesPayload(['en', 'es'], [
        ['site' => ['es'], 'title' => 'Uno'],
        ['site' => ['es'], 'title' => 'Dos'],
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([Overrides::KEY => 'Spanish has more than one override row. Keep one row per site.']);

    // Expect the file unchanged
    expect(formFile('contact'))->toBe($before);
});

it('refuses a row for a site the form is not assigned to, naming the site (#76)', function (): void {
    // Save a Spanish row on an English form and expect it refused
    $this->patchJson(settingsUrl('contact', 'forms.update'), overridesPayload(['en'], [
        ['site' => ['es'], 'title' => 'Contacto'],
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([Overrides::KEY => "Spanish has an override row but is not one of this form's sites."]);

    // Expect no rows in the file
    expect(formFile('contact'))->not->toHaveKey(Overrides::KEY);
});

it('refuses a row naming a site the install does not define (#76)', function (): void {
    $this->patchJson(settingsUrl('contact', 'forms.update'), overridesPayload(['en', 'es'], [
        ['site' => ['nope'], 'title' => 'Nope'],
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(Overrides::KEY);
});

it('accepts a row for any site on a form with no sites (#76)', function (string $site): void {
    // Save a row for the given site on a global form
    $this->patchJson(settingsUrl('contact', 'forms.update'), overridesPayload([], [
        ['site' => [$site], 'title' => 'Override'],
    ]))->assertOk();

    // Expect the row stored for that site
    expect(data_get(formFile('contact'), Overrides::KEY.'.0.site'))->toBe($site);
})->with(['en', 'es']);

it('refuses a row with no site (#76)', function (): void {
    // Save a row with no site and expect it refused
    $this->patchJson(settingsUrl('contact', 'forms.update'), overridesPayload(['en', 'es'], [
        ['site' => [], 'title' => 'Nowhere'],
    ]))->assertUnprocessable();

    // Expect no rows in the file
    expect(formFile('contact'))->not->toHaveKey(Overrides::KEY);
});

it('gives an override email the same columns as core gives a form email (#76)', function (?string $sender): void {
    // Configure the sender address
    config(['mail.from.address' => $sender]);

    // Read the email columns the screen's way: core builds them in a protected method
    $blueprint = new ReflectionMethod(FormsController::class, 'editFormBlueprint')
        ->invoke(app(FormsController::class), storedForm('contact'));
    if (! $blueprint instanceof Blueprint) {
        throw new RuntimeException('Core built no settings blueprint.');
    }

    // Find core's email field, refusing if it is missing
    $email = $blueprint->field('email');
    if (! $email instanceof Field) {
        throw new RuntimeException('Core built no email field.');
    }

    // Expect our email columns to match core's
    expect(ConfigFields::emailFields())->toEqual($email->config()['fields'] ?? null);
})->with([
    'a sender address' => ['hello@example.com'],
    'no sender address' => [null],
]);

it('configures the override grid and its columns for the screen (#76)', function (): void {
    // Read the overrides field from the settings screen
    $field = screenSection(screenProps($this->get(settingsUrl('contact'))->assertOk()), 'Sites')[Overrides::KEY] ?? [];

    // Key its columns by handle
    /** @var list<array<string, mixed>> $list */
    $list = $field['fields'] ?? [];
    $columns = array_column($list, null, 'handle');

    // Expect the grid and each column configured for the screen
    expect($field)->toMatchArray([
        'mode' => 'stacked',
        'add_row' => 'Add site override',
        'instructions' => "Change the title, storing or email for one site. Anything a row leaves empty uses the form's own setting.",
    ])
        ->and($columns['site'] ?? [])->toMatchArray(['type' => 'sites', 'mode' => 'select', 'max_items' => 1, 'required' => true])
        ->and($columns['title'] ?? [])->toMatchArray(['type' => 'text', 'instructions' => "Leave empty to use the form's title."])
        ->and($columns['store'] ?? [])->toMatchArray([
            'display' => 'Store Submissions',
            'type' => 'button_group',
            'clearable' => true,
            'instructions' => "Leave unset to use the form's setting.",
        ])
        ->and($columns['email'] ?? [])->toMatchArray([
            'type' => 'grid',
            'mode' => 'stacked',
            'add_row' => 'Add Email',
            'instructions' => "Replaces the form's emails for this site. Leave empty to send the form's emails.",
        ]);

    // Read the email grid's columns
    /** @var list<array{handle: string}> $emailColumns */
    $emailColumns = $columns['email']['fields'] ?? [];

    // Expect them to match the override email columns
    expect(array_column($emailColumns, 'handle'))->toBe(array_column(ConfigFields::emailFields(), 'handle'));

    // Read the store column's options
    /** @var list<array{value: string, label: string}>|array<string, string> $options */
    $options = $columns['store']['options'] ?? [];

    // Expect the store and do-not-store choices
    expect(json_encode($options))->toContain('"true"')
        ->toContain('"Store"')
        ->toContain('"false"')
        ->toContain("Don't store");
});
