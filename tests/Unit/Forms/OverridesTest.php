<?php

declare(strict_types=1);

use Statamic\Forms\Form;
use UAMSWeb\Aether\Forms\Multisite\Forms\Overrides;
use UAMSWeb\Aether\Forms\Multisite\Forms\SiteForm;

/**
 * A site's `site_overrides` row supplies its title, email and store, and every
 * value the row leaves out falls back to the base form's (#75).
 *
 * @command  vendor/bin/pest tests/Unit/Forms/OverridesTest.php
 */

/** The base recipients every override test starts from. */
const BASE_EMAIL = [['to' => 'base@example.com', 'subject' => 'Base']];

/**
 * A form shared by `en` and `es` with base values, and the given override
 * rows when any.
 *
 * @param  mixed  $rows  The `site_overrides` value to set, or null to leave it out.
 * @return Form An unsaved `contact` form.
 */
function overriddenForm(mixed $rows = null): Form
{
    // Make the form with its base title, email and store
    $form = new Form;
    $form->handle('contact');
    $form->title('Contact');
    $form->email(BASE_EMAIL);
    $form->store(true);
    // Assign it to both sites
    $form->set('sites', ['en', 'es']);

    // Set the override rows only when some were given
    if ($rows !== null) {
        $form->set(Overrides::KEY, $rows);
    }

    return $form;
}

/**
 * The form's view for a site, with that site's overrides applied.
 *
 * @param  Form  $form  The base form to resolve.
 * @param  string  $site  Handle of the site to resolve it for.
 * @return SiteForm The read-only view with the site's overrides applied.
 */
function overriddenView(Form $form, string $site): SiteForm
{
    // Resolve the form for the site and apply its overrides
    $view = SiteForm::of($form, $site);
    Overrides::apply($view);

    return $view;
}

it('applies each value the site row sets', function (string $key, mixed $value): void {
    // Resolve es with a row overriding this key
    $view = overriddenView(overriddenForm([['site' => 'es', $key => $value]]), 'es');

    // Expect the overriding value
    expect($view->{$key}())->toBe($value);
})->with([
    'title' => ['title', 'Contacto'],
    'email' => ['email', [['to' => 'es@example.com', 'subject' => 'ES']]],
    'store' => ['store', false],
]);

it('keeps the base value for each key the site row leaves out', function (string $key, mixed $base): void {
    // The row overrides one of the other two keys, so it exists and still
    // leaves this one out
    $other = $key === 'title' ? ['store' => false] : ['title' => 'Contacto'];
    // Resolve es with that row
    $view = overriddenView(overriddenForm([['site' => 'es', ...$other]]), 'es');

    // Expect the base value for the key left out
    expect($view->{$key}())->toBe($base);
})->with([
    'title' => ['title', 'Contact'],
    'email' => ['email', BASE_EMAIL],
    'store' => ['store', true],
]);

it('keeps every base value for a site with no row', function (): void {
    // Resolve en, which has no row, on a form overriding es
    $view = overriddenView(overriddenForm([['site' => 'es', 'title' => 'Contacto', 'email' => [['to' => 'es@example.com']], 'store' => false]]), 'en');

    // Expect every base value
    expect($view->title())->toBe('Contact')
        ->and($view->email())->toBe(BASE_EMAIL)
        ->and($view->store())->toBeTrue();
});

it('keeps every base value for a form with no overrides', function (mixed $rows): void {
    // Resolve es on a form with no usable overrides
    $view = overriddenView(overriddenForm($rows), 'es');

    // Expect every base value
    expect($view->title())->toBe('Contact')
        ->and($view->email())->toBe(BASE_EMAIL)
        ->and($view->store())->toBeTrue();
})->with([
    'no key' => [null],
    'empty list' => [[]],
    'a string' => ['es'],
]);

it('replaces the base email list with the overriding one, never merging them', function (): void {
    // Resolve es with a row overriding the email list
    $es = [['to' => 'es@example.com', 'subject' => 'ES']];
    $view = overriddenView(overriddenForm([['site' => 'es', 'email' => $es]]), 'es');

    // Expect only the overriding list
    expect($view->email())->toBe($es);
});

it('ignores a key a row may not override, without error', function (): void {
    // Resolve es with a row that also sets keys it may not override
    $view = overriddenView(overriddenForm([['site' => 'es', 'title' => 'Contacto', 'redirect' => '/gracias', 'handle' => 'other']]), 'es');

    // Expect the title applied and the other keys ignored
    expect($view->title())->toBe('Contacto')
        ->and($view->handle())->toBe('contact')
        ->and($view->get('redirect'))->toBeNull();
});

it('keeps the base value for a blank or wrongly typed row value', function (string $key, mixed $value, mixed $base): void {
    // Resolve es with a row holding an unusable value
    $view = overriddenView(overriddenForm([['site' => 'es', $key => $value]]), 'es');

    // Expect the base value
    expect($view->{$key}())->toBe($base);
})->with([
    'null title' => ['title', null, 'Contact'],
    'empty title' => ['title', '', 'Contact'],
    'null email' => ['email', null, BASE_EMAIL],
    'empty email list' => ['email', [], BASE_EMAIL],
    'email as a string' => ['email', 'es@example.com', BASE_EMAIL],
    'null store' => ['store', null, true],
    'store as another string' => ['store', 'no', true],
    'store as a number' => ['store', 0, true],
]);

it('reads the store value the editor saves as well as a boolean (#76)', function (bool $base, mixed $value, bool $expected): void {
    // Build a form with this base store and an es store override
    $form = overriddenForm([['site' => 'es', 'store' => $value]]);
    $form->store($base);

    // Expect the override read as a boolean
    expect(overriddenView($form, 'es')->store())->toBe($expected);
})->with([
    'the editor\'s "true" over a form that does not store' => [false, Overrides::STORE, true],
    'the editor\'s "false" over a form that stores' => [true, Overrides::DO_NOT_STORE, false],
    'true over a form that does not store' => [false, true, true],
    'false over a form that stores' => [true, false, false],
]);

it('resolves from the first row for a site that has two', function (): void {
    // Resolve es with two rows naming it
    $view = overriddenView(overriddenForm([
        ['site' => 'es', 'title' => 'Primero'],
        ['site' => 'es', 'title' => 'Segundo'],
    ]), 'es');

    // Expect the first row's title
    expect($view->title())->toBe('Primero');
});

it('skips rows that are not lists or name no site', function (): void {
    // Resolve es with malformed rows ahead of a valid one
    $view = overriddenView(overriddenForm(['es', ['title' => 'No site'], ['site' => null, 'title' => 'Null site'], ['site' => 'es', 'title' => 'Contacto']]), 'es');

    // Expect the valid row's title
    expect($view->title())->toBe('Contacto');
});

it('leaves the base form unchanged', function (): void {
    // Build a form overriding every key for es
    $base = overriddenForm([['site' => 'es', 'title' => 'Contacto', 'email' => [['to' => 'es@example.com']], 'store' => false]]);

    // Resolve es and apply its overrides
    overriddenView($base, 'es');

    // Expect the base form to keep its own values
    expect($base->title())->toBe('Contact')
        ->and($base->email())->toBe(BASE_EMAIL)
        ->and($base->store())->toBeTrue();
});

it('finds no row for a site the overrides do not name', function (): void {
    expect(Overrides::rowFor(overriddenForm([['site' => 'es', 'title' => 'Contacto']]), 'en'))->toBeNull()
        ->and(Overrides::rowFor(overriddenForm([['site' => 'es', 'title' => 'Contacto']]), 'es'))->toBe(['site' => 'es', 'title' => 'Contacto']);
});

it('removes the rows from the view and keeps them on the base form (#62)', function (string $site): void {
    // Build a form with an es override row
    $rows = [['site' => 'es', 'title' => 'Contacto', 'email' => [['to' => 'es@example.com']]]];
    $base = overriddenForm($rows);

    // Resolve the site
    $view = overriddenView($base, $site);

    // Expect the rows gone from the view and kept on the base
    expect($view->get(Overrides::KEY))->toBeNull()
        ->and($base->get(Overrides::KEY))->toBe($rows);
})->with(['the site with a row' => ['es'], 'a site without one' => ['en']]);
