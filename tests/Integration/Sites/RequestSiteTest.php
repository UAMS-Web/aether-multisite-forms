<?php

declare(strict_types=1);

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Statamic\Facades\Site;
use Statamic\Sites\Site as SiteObject;
use UAMSWeb\Aether\Forms\Multisite\Sites\RequestSite;

/**
 * The submit site is the one the form tag's signed field names; without the
 * field it is core's own rule: the site the referrer falls under, else the
 * default site (#71).
 *
 * @command  vendor/bin/pest tests/Integration/Sites/RequestSiteTest.php
 */
beforeEach(function (): void {
    useTwoSites();
});

it('names the site the referrer falls under', function (string $referrer, string $site): void {
    // Submit from the given page
    request()->headers->set('referer', $referrer);

    // Expect the site that page falls under
    expect((new RequestSite)->forSubmission()->handle())->toBe($site);
})->with([
    'English page' => ['http://localhost/contact', 'en'],
    'Spanish page' => ['http://localhost/es/contacto', 'es'],
    'Spanish home' => ['http://localhost/es/', 'es'],
]);

it('falls back to the default site with no referrer', function (): void {
    // Submit with no referrer
    request()->headers->remove('referer');

    // `en` is the first configured site, which core takes as the default
    expect((new RequestSite)->forSubmission()->handle())->toBe('en');
});

it('falls back to the default site for a referrer on no site', function (): void {
    // Submit from a page on another host
    request()->headers->set('referer', 'https://elsewhere.example/es/');

    // Expect the default site
    expect((new RequestSite)->forSubmission()->handle())->toBe('en');
});

it('prefers the signed site the form carried over the referrer', function (): void {
    // Submit from an English page, carrying the Spanish site the form was rendered on
    request()->headers->set('referer', 'http://localhost/contact');
    request()->merge([RequestSite::FIELD => RequestSite::sign(configuredSite('es'))]);

    // Expect the signed site
    expect((new RequestSite)->forSubmission()->handle())->toBe('es');
});

it('rejects a site field whose signature fails, warns, and lets the referrer decide', function (string $value): void {
    // Collect the warnings logged
    $warnings = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$warnings): void {
        $warnings[] = $event->level.': '.$event->message;
    });

    // Submit from a Spanish page, carrying an English site nobody signed
    request()->headers->set('referer', 'http://localhost/es/contacto');
    request()->merge([RequestSite::FIELD => $value]);

    // Expect the referrer's site, and one warning
    expect((new RequestSite)->forSubmission()->handle())->toBe('es')
        ->and($warnings)->toBe(['warning: Multisite Forms rejected a submit site whose signature failed; the referrer decides the site.']);
})->with([
    'a bare handle' => ['en'],
    'a tampered signature' => [fn (): string => strrev(RequestSite::sign('en'))],
]);

it('lets the referrer decide for a signed site that is no longer configured, and for an empty field', function (string $value): void {
    // Submit from a Spanish page
    request()->headers->set('referer', 'http://localhost/es/contacto');
    request()->merge([RequestSite::FIELD => $value]);

    // Expect the referrer's site
    expect((new RequestSite)->forSubmission()->handle())->toBe('es');
})->with([
    'a site removed since' => [fn (): string => RequestSite::sign('fr')],
    'an empty field' => [''],
]);

it('leaves a missing, empty or non-string site field to the referrer without a warning', function (mixed $value): void {
    // Collect the warnings logged
    $warnings = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$warnings): void {
        $warnings[] = $event->level.': '.$event->message;
    });

    // Submit from a Spanish page, with the field absent or carrying no signed value
    request()->headers->set('referer', 'http://localhost/es/contacto');
    if ($value !== null) {
        request()->merge([RequestSite::FIELD => $value]);
    }

    // Expect the referrer's site, and no signature check to have run
    expect((new RequestSite)->forSubmission()->handle())->toBe('es')
        ->and($warnings)->toBe([]);
})->with([
    'no field' => [null],
    'an empty field' => [''],
    'an array field' => [['en']],
]);

it('names the current site for a page render', function (): void {
    // Render the page on the Spanish site
    Site::setCurrent('es');

    // Expect the page site to be Spanish
    expect((new RequestSite)->forPage()->handle())->toBe('es');
});

it('reads the handle of a site given as an object or a handle', function (): void {
    expect(RequestSite::handle(configuredSite('es')))->toBe('es')
        ->and(RequestSite::handle('fr'))->toBe('fr');
});

it('refuses a site object with no handle', function (): void {
    RequestSite::handle(new SiteObject(null, ['url' => '/']));
})->throws(LogicException::class, 'A site has no handle');

it('refuses to name a site when the install defines none', function (string $surface): void {
    // Remove every site and submit from a Spanish page
    Site::setSites([]);
    request()->headers->set('referer', 'http://localhost/es/');

    // Ask the surface under test for its site
    $surface === 'submission' ? (new RequestSite)->forSubmission() : (new RequestSite)->forPage();
})->with(['submission', 'page'])->throws(LogicException::class, 'No site could be determined');
