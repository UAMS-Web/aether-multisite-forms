<?php

declare(strict_types=1);

use Statamic\Facades\Site;
use UAMSWeb\Aether\Forms\Multisite\Forms\OverrideRows;

/**
 * The rule behind the site overrides editor: each row names a configured site
 * the form is assigned to, at most once (#76).
 *
 * @command  vendor/bin/pest tests/Integration/Forms/OverrideRowsTest.php
 */
beforeEach(function (): void {
    useTwoSites();
});

/**
 * The messages the rule fails with for the given rows and `sites` value.
 *
 * @param  mixed  $rows  The `site_overrides` value to validate.
 * @param  mixed  $sites  The form's `sites` value.
 * @return list<string> Each failure message, in order.
 */
function overrideRowFailures(mixed $rows, mixed $sites): array
{
    // Start with no failures
    $failures = [];

    // Validate the rows against the sites, collecting each failure
    (new OverrideRows)
        ->setData(['sites' => $sites])
        ->validate('site_overrides', $rows, function (string $message) use (&$failures): void {
            $failures[] = $message;
        });

    return $failures;
}

it('passes rows that each name a different assigned site', function (mixed $site): void {
    expect(overrideRowFailures([['site' => $site]], ['en', 'es']))->toBe([]);
})->with([
    'a handle, as the file holds it' => ['es'],
    'a one-item list, as the screen sends it' => [['es']],
]);

it('fails once, naming the site, for a site with two rows', function (): void {
    expect(overrideRowFailures([['site' => 'es'], ['site' => ['es']], ['site' => 'es']], ['en', 'es']))
        ->toBe(['Spanish has more than one override row. Keep one row per site.']);
});

it('fails once, naming the site, for rows of a site the form is not assigned to', function (): void {
    expect(overrideRowFailures([['site' => 'es'], ['site' => 'es']], ['en']))
        ->toBe(["Spanish has an override row but is not one of this form's sites."]);
});

it('fails once for a site the install does not define', function (): void {
    expect(overrideRowFailures([['site' => 'nope'], ['site' => 'nope']], ['en', 'es']))
        ->toBe(['An override row names [nope], which is not a site this install defines.']);
});

it('reads a form assigned by a bare handle', function (): void {
    expect(overrideRowFailures([['site' => 'en']], 'en'))->toBe([])
        ->and(overrideRowFailures([['site' => 'es']], 'en'))->toHaveCount(1);
});

it('accepts any site on a form whose selection holds no site', function (mixed $sites): void {
    expect(overrideRowFailures([['site' => 'en'], ['site' => 'es']], $sites))->toBe([]);
})->with([
    'no sites value' => [null],
    'an empty list' => [[]],
    'only empty and non-string entries' => [['', 5, null]],
    'an empty string' => [''],
]);

it('ignores empty and non-string entries in a selection that holds a site', function (): void {
    expect(overrideRowFailures([['site' => 'es']], ['', 'es', 5]))->toBe([])
        ->and(overrideRowFailures([['site' => 'en']], ['', 'es', 5]))->toHaveCount(1);
});

it('skips a row that names no site and still checks the rows after it', function (mixed $row): void {
    expect(overrideRowFailures([$row, ['site' => 'es'], ['site' => 'es']], ['en', 'es']))->toHaveCount(1);
})->with([
    'no site key' => [['title' => 'Nowhere']],
    'an empty handle' => [['site' => '']],
    'an empty list' => [['site' => []]],
    'a number' => [['site' => 5]],
    'not a row' => ['es'],
]);

it('names a site by its handle when its configured name is empty or not a string', function (mixed $name): void {
    // Configure the Spanish site with the given name
    Site::setSites([
        'en' => ['name' => 'English', 'url' => 'http://localhost/', 'locale' => 'en_US'],
        'es' => ['name' => $name, 'url' => 'http://localhost/es/', 'locale' => 'es_ES'],
    ]);

    // Expect the failure to name the site by its handle
    expect(overrideRowFailures([['site' => 'es']], ['en']))
        ->toBe(["es has an override row but is not one of this form's sites."]);
})->with([
    'empty' => [''],
    'a number' => [7],
]);

it("leaves a row that names no site to the column's own required rule", function (mixed $row): void {
    expect(overrideRowFailures([$row], ['en', 'es']))->toBe([]);
})->with([
    'an empty handle' => [['site' => '']],
    'an empty list' => [['site' => []]],
]);

it('passes a value that holds no rows', function (mixed $rows): void {
    expect(overrideRowFailures($rows, ['en']))->toBe([]);
})->with([
    'null' => [null],
    'an empty list' => [[]],
    'a string' => ['es'],
]);
