<?php

declare(strict_types=1);

use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Facades\Form as FormFacade;
use Statamic\Forms\Form;
use UAMSWeb\Aether\Forms\Multisite\Forms\FormResolver;
use UAMSWeb\Aether\Forms\Multisite\Forms\SiteForm;

/**
 * The resolver answers whether a form is assigned to a site, and returns a
 * read-only view for one it is assigned to (#71).
 *
 * @command  vendor/bin/pest tests/Integration/Forms/FormResolverTest.php
 */
beforeEach(function (): void {
    useTwoSites();
});

/**
 * A form assigned to the given sites, or global when given null.
 *
 * @param  list<string>|null  $sites  Site handles to assign, or null for a global form.
 * @return Form An unsaved `contact` form.
 */
function resolvableForm(?array $sites): Form
{
    // Build a contact form with a title
    $form = new Form;
    $form->handle('contact');
    $form->title('Contact');

    // Assign the sites when the form is not global
    if ($sites !== null) {
        $form->set('sites', $sites);
    }

    return $form;
}

it('resolves a form for a site it is assigned to', function (): void {
    // Resolve an English form for English
    $view = (new FormResolver)->resolve(resolvableForm(['en']), 'en');

    // Expect an English view keeping the base handle
    expect($view)->toBeInstanceOf(SiteForm::class)
        ->and($view?->site())->toBe('en')
        ->and($view?->handle())->toBe('contact');
});

it('returns null for a site the form is not assigned to', function (): void {
    expect((new FormResolver)->resolve(resolvableForm(['en']), 'es'))->toBeNull();
});

it('resolves a global form for every site', function (string $site): void {
    expect((new FormResolver)->resolve(resolvableForm(null), $site)?->site())->toBe($site);
})->with(['en', 'es']);

it('accepts a site object as well as a handle', function (): void {
    // Build a resolver and a Spanish form
    $resolver = new FormResolver;
    $form = resolvableForm(['es']);

    // Expect site objects to resolve as their handles would
    expect($resolver->resolve($form, configuredSite('es'))?->site())->toBe('es')
        ->and($resolver->resolve($form, configuredSite('en')))->toBeNull();
});

it('resolves from the base form when handed a view', function (): void {
    // Build a resolver and a form on both sites
    $resolver = new FormResolver;
    $base = resolvableForm(['en', 'es']);

    // Resolve the English view, then resolve that view for Spanish
    $again = $resolver->resolve($resolver->resolve($base, 'en') ?? throw new LogicException('not resolved'), 'es');

    // Expect a Spanish view of the original base
    expect($again?->site())->toBe('es')
        ->and($again?->base())->toBe($base);
});

it('keeps the base handle, so the action URL is the base form URL', function (): void {
    // Build a Spanish form
    $base = resolvableForm(['es']);

    // Expect the view's action URL to be the base form's
    expect((new FormResolver)->resolve($base, 'es')?->actionUrl())->toBe($base->actionUrl());
});

it('leaves the base form unchanged when its view is changed', function (): void {
    // Build a form on both sites
    $base = resolvableForm(['en', 'es']);

    // Change the sites and title on its Spanish view
    $view = (new FormResolver)->resolve($base, 'es');
    $view?->set('sites', ['es']);
    $view?->title('Contacto');

    // Expect the base form untouched
    expect($base->get('sites'))->toBe(['en', 'es'])
        ->and($base->title())->toBe('Contact');
});

it('applies the site override row to the view it resolves (#75)', function (): void {
    // Build a form on both sites with a Spanish title override
    $base = resolvableForm(['en', 'es']);
    $base->set('site_overrides', [['site' => 'es', 'title' => 'Contacto']]);

    // Build a resolver
    $resolver = new FormResolver;

    // Expect the override on the Spanish view only
    expect($resolver->resolve($base, 'es')?->title())->toBe('Contacto')
        ->and($resolver->resolve($base, 'en')?->title())->toBe('Contact')
        ->and($base->title())->toBe('Contact');
});

it('reaches a driver form class through base(), not through the view', function (): void {
    // Make a driver's subclass of core's Form, like statamic/eloquent-driver's (#107)
    $driverForm = new class extends Form
    {
        public function model(): string
        {
            return 'the stored row';
        }
    };
    app()->bind(FormContract::class, $driverForm::class);

    // Make a form, refusing if it is not the driver's class
    $base = FormFacade::make('contact');
    if (! $base instanceof $driverForm) {
        throw new LogicException('the contract did not resolve to the driver class');
    }

    // Assign it to English
    $base->set('sites', ['en']);

    // Resolve its English view
    $view = (new FormResolver)->resolve($base, 'en') ?? throw new LogicException('not resolved');

    // Build a call to the driver's method through the view
    $callModel =
        // @phpstan-ignore method.notFound (model() is the driver's; the view lacks it)
        (fn (): mixed => $view->model());

    // Expect the driver class only behind base(), and the view to refuse model()
    expect($view)->not->toBeInstanceOf($driverForm::class)
        ->and($view->base())->toBe($base)
        ->and($view->base())->toBeInstanceOf($driverForm::class)
        ->and($callModel)->toThrow(BadMethodCallException::class);
});
