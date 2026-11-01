<?php

declare(strict_types=1);

use Illuminate\Testing\TestResponse;
use Statamic\Facades\Form as FormFacade;
use Statamic\Forms\Form;
use Statamic\GraphQL\Queries\FormsQuery as CoreFormsQuery;
use Statamic\Http\Controllers\API\FormsController as CoreFormsController;
use Symfony\Component\HttpFoundation\Response;
use UAMSWeb\Aether\Forms\Multisite\Api\FormsController;
use UAMSWeb\Aether\Forms\Multisite\GraphQL\FormsQuery;
use UAMSWeb\Aether\Forms\Multisite\Tests\TestCase;

/**
 * The REST and GraphQL form listings take an optional `site` filter, limiting
 * them to forms assigned to that site, forms with no sites included (#80).
 *
 * @command  vendor/bin/pest tests/Integration/Api/FormsSiteFilterTest.php
 */

/** The forms every test stores, by handle, with the sites each lists. */
const API_FORMS = [
    'english' => ['en'],
    'spanish' => ['es'],
    'shared' => ['en', 'es'],
    'everywhere' => [],
];

beforeEach(function (): void {
    // Configure two sites and a temporary forms directory
    useTwoSites();
    useTemporaryFormsDirectory();

    // Expose forms through the REST and GraphQL APIs
    config(['statamic.api.resources.forms' => true, 'statamic.graphql.resources.forms' => true]);

    // Store each test form with the sites it lists
    foreach (API_FORMS as $handle => $sites) {
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
    removeFormsDirectory();
});

/**
 * The handles in a list of forms from either API, sorted.
 *
 * @param  mixed  $forms  The decoded list of forms from a response.
 * @return list<string> The handle of each listed form, sorted.
 *
 * @throws RuntimeException When there is no list, or a form has no handle.
 */
function listedHandles(mixed $forms): array
{
    // Fail when the response holds no list
    if (! is_array($forms)) {
        throw new RuntimeException('The response holds no list of forms.');
    }

    // Collect each listed form's handle
    $handles = [];
    foreach ($forms as $form) {
        // Read the handle, failing when the form has none
        $handle = is_array($form) ? ($form['handle'] ?? null) : null;
        if (! is_string($handle)) {
            throw new RuntimeException('A listed form has no handle.');
        }

        $handles[] = $handle;
    }

    // Sort the handles so the order is stable
    sort($handles);

    return $handles;
}

/**
 * The REST forms listing, with the given query string.
 *
 * @param  TestCase  $case  The running test.
 * @param  array<string, mixed>  $query  The query string's parameters.
 * @return TestResponse<Response> The listing response.
 */
function restForms(TestCase $case, array $query = []): TestResponse
{
    return $case->getJson('/api/forms'.($query === [] ? '' : '?'.http_build_query($query)));
}

/**
 * The GraphQL forms query, with the given arguments written as GraphQL.
 *
 * @param  TestCase  $case  The running test.
 * @param  string  $arguments  The query's arguments, parentheses included.
 * @return TestResponse<Response> The query response.
 */
function graphqlForms(TestCase $case, string $arguments = ''): TestResponse
{
    return $case->postJson('/graphql', ['query' => "{ forms{$arguments} { handle } }"]);
}

it('lists every form without a site filter', function (): void {
    expect(listedHandles(restForms($this)->assertOk()->json('data')))->toBe(['english', 'everywhere', 'shared', 'spanish']);
});

it('lists the forms assigned to the filtered site, and the forms with no sites', function (string $site, array $handles): void {
    expect(listedHandles(restForms($this, ['site' => $site])->assertOk()->json('data')))->toBe($handles);
})->with([
    'en' => ['en', ['english', 'everywhere', 'shared']],
    'es' => ['es', ['everywhere', 'shared', 'spanish']],
]);

it('refuses a site filter that names no configured site', function (mixed $site): void {
    restForms($this, ['site' => $site])
        ->assertUnprocessable()
        ->assertJson(['message' => 'The site filter names no configured site.']);
})->with([
    'an unknown handle' => ['fr'],
    'an empty value' => [''],
    'a list' => [['en']],
]);

it('filters only the forms the API exposes', function (): void {
    // Expose only two forms through the REST API
    config(['statamic.api.resources.forms' => ['english', 'spanish']]);

    // Expect only exposed forms listed, with or without the filter
    expect(listedHandles(restForms($this)->assertOk()->json('data')))->toBe(['english', 'spanish'])
        ->and(listedHandles(restForms($this, ['site' => 'en'])->assertOk()->json('data')))->toBe(['english']);
});

it('leaves the single form endpoint and a disabled API as core has them', function (): void {
    // Expect the single form endpoint to ignore the site filter
    $this->getJson('/api/forms/spanish?site=en')->assertOk()->assertJsonPath('data.handle', 'spanish');

    // An unknown site too, so a disabled API answers before the filter is read
    config(['statamic.api.resources.forms' => false]);
    restForms($this, ['site' => 'en'])->assertNotFound();
    restForms($this, ['site' => 'fr'])->assertNotFound();
});

it('lists every form from GraphQL without a site argument, or with a null one', function (string $arguments): void {
    expect(listedHandles(graphqlForms($this, $arguments)->assertOk()->json('data.forms')))->toBe(['english', 'everywhere', 'shared', 'spanish']);
})->with([
    'no argument' => [''],
    'a null site' => ['(site: null)'],
]);

it('lists the forms assigned to a site from GraphQL, and the forms with no sites', function (string $site, array $handles): void {
    expect(listedHandles(graphqlForms($this, "(site: \"{$site}\")")->assertOk()->json('data.forms')))->toBe($handles);
})->with([
    'en' => ['en', ['english', 'everywhere', 'shared']],
    'es' => ['es', ['everywhere', 'shared', 'spanish']],
]);

it('answers a GraphQL site argument that names no configured site with a validation error', function (string $site): void {
    // Query the forms with the unknown site
    $response = graphqlForms($this, "(site: \"{$site}\")")->assertOk();

    // Expect a validation error on site and no forms
    expect($response->json('errors.0.message'))->toBe('validation')
        ->and($response->json('errors.0.extensions.validation.site'))->toBeArray()->not->toBeEmpty()
        ->and($response->json('data.forms'))->toBeNull();
})->with([
    'an unknown handle' => ['fr'],
    'an empty value' => [''],
]);

it('reads the GraphQL site argument from a variable', function (): void {
    // Write a query that takes the site from a variable
    $query = 'query ($site: String) { forms(site: $site) { handle } }';

    // Expect no variable, a known site and an unknown one each answered
    expect(listedHandles($this->postJson('/graphql', ['query' => $query])->assertOk()->json('data.forms')))
        ->toBe(['english', 'everywhere', 'shared', 'spanish'])
        ->and(listedHandles($this->postJson('/graphql', ['query' => $query, 'variables' => ['site' => 'es']])->assertOk()->json('data.forms')))
        ->toBe(['everywhere', 'shared', 'spanish'])
        ->and($this->postJson('/graphql', ['query' => $query, 'variables' => ['site' => 'fr']])->assertOk()->json('errors.0.message'))
        ->toBe('validation');
});

it('filters only the forms GraphQL exposes', function (): void {
    // Expose only two forms through GraphQL
    config(['statamic.graphql.resources.forms' => ['english', 'spanish']]);

    // Expect only exposed forms listed, with or without the filter
    expect(listedHandles(graphqlForms($this)->assertOk()->json('data.forms')))->toBe(['english', 'spanish'])
        ->and(listedHandles(graphqlForms($this, '(site: "es")')->assertOk()->json('data.forms')))->toBe(['spanish']);
});

it("replaces core's REST controller and GraphQL query", function (): void {
    expect(app(CoreFormsController::class))->toBeInstanceOf(FormsController::class)
        ->and(app(CoreFormsQuery::class))->toBeInstanceOf(FormsQuery::class);
});
