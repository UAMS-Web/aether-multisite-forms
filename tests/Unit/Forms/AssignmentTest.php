<?php

declare(strict_types=1);

use Statamic\Forms\Form;
use UAMSWeb\Aether\Forms\Multisite\Forms\Assignment;

/**
 * A form's `sites` key decides which sites it belongs to, and no key, or an
 * empty list, means every site (#71).
 *
 * @command  vendor/bin/pest tests/Unit/Forms/AssignmentTest.php
 */

/**
 * A form whose `sites` key holds a value, or that has no `sites` key when
 * given nothing.
 *
 * @param  mixed  ...$sites  The `sites` value to set, or nothing to leave the key out.
 * @return Form An unsaved `contact` form.
 */
function assignedForm(mixed ...$sites): Form
{
    // Make the contact form
    $form = new Form;
    $form->handle('contact');

    // Set the sites key only when a value was given
    if ($sites !== []) {
        $form->set('sites', $sites[0]);
    }

    return $form;
}

it('assigns a form to the sites it lists and to no other', function (): void {
    // Assign the form to en and es
    $form = assignedForm(['en', 'es']);

    // Expect it to cover those two sites only
    expect(Assignment::covers($form, 'en'))->toBeTrue()
        ->and(Assignment::covers($form, 'es'))->toBeTrue()
        ->and(Assignment::covers($form, 'fr'))->toBeFalse()
        ->and(Assignment::isGlobal($form))->toBeFalse();
});

it('assigns a form with no sites key, or an empty one, to every site', function (Form $form): void {
    expect(Assignment::sites($form))->toBe([])
        ->and(Assignment::isGlobal($form))->toBeTrue()
        ->and(Assignment::covers($form, 'en'))->toBeTrue()
        ->and(Assignment::covers($form, 'any-site'))->toBeTrue();
})->with([
    'no key' => fn (): Form => assignedForm(),
    'empty list' => fn (): Form => assignedForm([]),
    'null' => fn (): Form => assignedForm(null),
    'empty string' => fn (): Form => assignedForm(''),
]);

it('reads a single handle written as a string as one site', function (): void {
    // Assign the form to es as a bare string
    $form = assignedForm('es');

    // Expect it read as the single site es
    expect(Assignment::sites($form))->toBe(['es'])
        ->and(Assignment::covers($form, 'es'))->toBeTrue()
        ->and(Assignment::covers($form, 'e'))->toBeFalse();
});

it('drops blanks, repeats and values that are not handles, and keeps file order', function (): void {
    // Assign a list mixing handles with blanks, repeats and non-strings
    $form = assignedForm(['es', '', 'en', 'es', 3, null, ['en']]);

    // Expect only the unique handles, in file order
    expect(Assignment::sites($form))->toBe(['es', 'en']);
});

it('treats a list holding no usable handle as every site', function (): void {
    // Assign a list holding no usable handle
    $form = assignedForm(['', null]);

    // Expect the form treated as global
    expect(Assignment::isGlobal($form))->toBeTrue()
        ->and(Assignment::covers($form, 'en'))->toBeTrue();
});

it('matches handles exactly, not loosely or by case', function (string $site): void {
    expect(Assignment::covers(assignedForm(['en']), $site))->toBeFalse();
})->with(['EN', 'en ', ' en', 'e']);
