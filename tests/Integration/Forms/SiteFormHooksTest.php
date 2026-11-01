<?php

declare(strict_types=1);

use Statamic\Forms\Form;
use UAMSWeb\Aether\Forms\Multisite\Forms\SiteForm;

/**
 * Hooks registered on core's Form, which core files under the class they were
 * registered on, also run for a site-resolved view (#71).
 *
 * @command  vendor/bin/pest tests/Integration/Forms/SiteFormHooksTest.php
 */

/**
 * A form whose handle and title make its augmentation readable.
 *
 * @return Form An unsaved `contact` form.
 */
function hookedForm(): Form
{
    // Build a contact form with a title
    $form = new Form;
    $form->handle('contact');
    $form->title('Contact');

    return $form;
}

it('runs augmented hooks registered on core Form for the view, as for the base', function (): void {
    // Record each call of a hook registered on core's Form
    $calls = new ArrayObject;
    Form::hook('augmented', function (mixed $augmented, Closure $next) use ($calls): mixed {
        $calls[] = 'core';

        return $next($augmented);
    });

    // Augment the base form and expect the hook to run
    hookedForm()->augmented();
    expect($calls->getArrayCopy())->toBe(['core']);

    // Augment a Spanish view and expect the hook to run again
    SiteForm::of(hookedForm(), 'es')->augmented();
    expect($calls->getArrayCopy())->toBe(['core', 'core']);
});

it('runs hooks registered on core Form before those registered on the view class', function (): void {
    // Record the order of a view-class hook and a core hook
    $order = new ArrayObject;
    SiteForm::hook('augmented', function (mixed $augmented, Closure $next) use ($order): mixed {
        $order[] = 'view';

        return $next($augmented);
    });
    Form::hook('augmented', function (mixed $augmented, Closure $next) use ($order): mixed {
        $order[] = 'core';

        return $next($augmented);
    });

    // Augment a Spanish view
    SiteForm::of(hookedForm(), 'es')->augmented();

    // Expect the core hook first
    expect($order->getArrayCopy())->toBe(['core', 'view']);
});

it('runs a view-class hook only for the view, not for the base form', function (): void {
    // Record each call of a hook registered on the view class
    $calls = new ArrayObject;
    SiteForm::hook('augmented', function (mixed $augmented, Closure $next) use ($calls): mixed {
        $calls[] = 'view';

        return $next($augmented);
    });

    // Augment the base form
    hookedForm()->augmented();

    // Expect the hook not to have run
    expect($calls->getArrayCopy())->toBe([]);
});
