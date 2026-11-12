<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Statamic\Facades\Stache;
use Statamic\Forms\Email;
use Statamic\Forms\Form;
use Statamic\Forms\SendEmail;
use Statamic\Sites\Site;
use Statamic\Stache\Stores\Store;
use UAMSWeb\Aether\Forms\Multisite\Forms\SendSiteEmail;
use UAMSWeb\Aether\Forms\Multisite\Sites\RequestSite;
use UAMSWeb\Aether\Forms\Multisite\Tests\TestCase;

/**
 * A submission's email is sent for the site it was recorded under, not the
 * site core derives from the referrer (#249).
 *
 * @command  vendor/bin/pest tests/Integration/Forms/SendSiteEmailTest.php
 */
beforeEach(function (): void {
    // Configure two sites and a temporary forms directory for this test
    useTwoSites();
    $directory = useTemporaryFormsDirectory();

    // Find the submissions store, refusing if the Stache has none
    $store = Stache::store('form-submissions');
    if (! $store instanceof Store) {
        throw new RuntimeException('The Stache has no form submissions store.');
    }

    // Point the submissions store inside the temporary directory
    $store->directory($directory.'/_submissions');

    // Save a global form that emails each submission
    $form = new Form;
    $form->handle('mailed');
    $form->title('Mailed');
    $form->store(true);
    $form->email(['to' => 'info@example.com', 'subject' => 'Hello']);
    $form->save();

    // Capture the mail instead of sending it
    Mail::fake();
});

afterEach(function (): void {
    removeFormsDirectory();
});

/**
 * Post to the `mailed` form, from a page when given one.
 *
 * @param  TestCase  $case  The running test, which sends the request.
 * @param  string|null  $page  URL of the referring page, or null to send no `Referer`.
 * @param  array<string, mixed>  $values  Extra values to post with the message.
 */
function postMailed(TestCase $case, ?string $page, array $values = []): void
{
    $headers = $page === null ? [] : ['referer' => $page];
    $case->post('/!/forms/mailed', ['message' => 'hello', ...$values], $headers)->assertRedirect();
}

/**
 * The handles of the sites the sent emails were composed for.
 *
 * @return list<string> One handle per email sent.
 */
function emailedSites(): array
{
    // Read the site off each email that was sent
    $handles = [];
    foreach (Mail::sent(Email::class) as $email) {
        // Skip anything that is not a Statamic email
        if ($email instanceof Email) {
            $handles[] = handleOf($email->getSite());
        }
    }

    return $handles;
}

/**
 * The handle of a site read from an untyped property.
 *
 * @param  mixed  $site  The value read.
 * @return string The site's handle.
 *
 * @throws RuntimeException When the value is not a site.
 */
function handleOf(mixed $site): string
{
    // Refuse a value that is not a site
    if (! $site instanceof Site) {
        throw new RuntimeException('The value is not a site.');
    }

    return RequestSite::handle($site);
}

it('emails a submit with a signed site and no referrer for that site', function (): void {
    // Submit from the Spanish site's form with no Referer header
    postMailed($this, null, [RequestSite::FIELD => RequestSite::sign('es')]);

    // Expect the email composed for the Spanish site
    expect(emailedSites())->toBe(['es']);
});

it('emails a submit whose signed site and referrer differ for the signed site', function (): void {
    // Submit with a Spanish signed field from an English page
    postMailed($this, 'http://localhost/contact', [RequestSite::FIELD => RequestSite::sign('es')]);

    // Expect the email composed for the Spanish site
    expect(emailedSites())->toBe(['es']);
});

it("keeps core's site for a submit with no signed field", function (string $page, string $site): void {
    // Submit from the given page with no signed field
    postMailed($this, $page);

    // Expect the email composed for the referrer's site
    expect(emailedSites())->toBe([$site]);
})->with([
    'from the en site' => ['http://localhost/contact', 'en'],
    'from the es site' => ['http://localhost/es/contacto', 'es'],
]);

it("falls back to core's site for a recorded value that names no site", function (mixed $recorded): void {
    // Build the job for a submission whose recorded value cannot name a site
    $form = Statamic\Facades\Form::find('mailed');
    $submission = $form instanceof Form ? $form->makeSubmission() : throw new RuntimeException('The form is missing.');
    if ($recorded !== null) {
        $submission->set('_site', $recorded);
    }
    $job = new SendSiteEmail($submission, configuredSite('es'), ['to' => 'a@example.com']);

    // Expect core's site kept
    expect(handleOf($job->site))->toBe('es');
})->with([
    'no recorded site' => [null],
    'an empty string' => [''],
    'a value that is not a string' => [['en']],
    'a site that is no longer configured' => ['gone'],
]);

it("is installed in place of core's job, and leaves a host's own job alone", function (): void {
    // Expect the add-on's job installed over core's by the boot
    expect(config('statamic.forms.send_email_job'))->toBe(SendSiteEmail::class);

    // Reinstalling over a host's own job changes nothing
    config(['statamic.forms.send_email_job' => 'App\\HostEmailJob']);
    SendSiteEmail::register();
    expect(config('statamic.forms.send_email_job'))->toBe('App\\HostEmailJob');

    // Reinstalling over core's job installs the add-on's
    config(['statamic.forms.send_email_job' => SendEmail::class]);
    SendSiteEmail::register();
    expect(config('statamic.forms.send_email_job'))->toBe(SendSiteEmail::class);
});
