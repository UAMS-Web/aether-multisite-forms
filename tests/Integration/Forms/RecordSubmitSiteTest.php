<?php

declare(strict_types=1);

use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Statamic\Contracts\Forms\Submission;
use Statamic\Events\FormSubmitted;
use Statamic\Events\SubmissionCreated;
use Statamic\Facades\Form as FormFacade;
use Statamic\Facades\Stache;
use Statamic\Forms\Form;
use Statamic\Forms\SendEmails;
use Statamic\Stache\Stores\Store;
use UAMSWeb\Aether\Forms\Multisite\Forms\RecordSubmitSite;
use UAMSWeb\Aether\Forms\Multisite\Sites\RequestSite;
use UAMSWeb\Aether\Forms\Multisite\Tests\TestCase;

/**
 * A submission made through core's submit route records the submit site under
 * `_site`, and a visitor cannot set it (#74).
 *
 * @command  vendor/bin/pest tests/Integration/Forms/RecordSubmitSiteTest.php
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
});

afterEach(function (): void {
    removeFormsDirectory();
});

/**
 * Save a global `feedback` form, storing its submissions unless told otherwise.
 *
 * @param  bool  $store  Whether the form stores its submissions.
 * @return Form The saved form.
 */
function feedbackForm(bool $store = true): Form
{
    // Build and save a global feedback form
    $form = new Form;
    $form->handle('feedback');
    $form->title('Feedback');
    $form->store($store);
    $form->save();

    return $form;
}

/**
 * Post to the `feedback` form from a page, with any extra request values.
 *
 * @param  TestCase  $case  The running test, which sends the request.
 * @param  string  $page  URL of the page the submit comes from.
 * @param  array<string, mixed>  $values  Extra values to post with the message.
 */
function postFeedback(TestCase $case, string $page, array $values = []): void
{
    $case->post('/!/forms/feedback', ['message' => 'hello', ...$values], ['referer' => $page])->assertRedirect();
}

/**
 * The `feedback` form's stored submissions, read back from storage.
 *
 * @return list<Submission> The stored submissions.
 *
 * @throws RuntimeException When the `feedback` form is missing.
 */
function storedFeedback(): array
{
    // Find the feedback form, refusing if it is missing
    $form = FormFacade::find('feedback');
    if (! $form instanceof Form) {
        throw new RuntimeException('The feedback form is missing.');
    }

    // Keep only the submissions
    return array_values(array_filter($form->submissions()->all(), fn (mixed $submission): bool => $submission instanceof Submission));
}

it('records the submit site on a stored submission', function (string $page, string $site): void {
    // Save the form
    feedbackForm();

    // Submit from the given page
    postFeedback($this, $page);

    // Expect one submission recording that page's site
    $submissions = storedFeedback();
    expect($submissions)->toHaveCount(1)
        ->and($submissions[0]->get(RecordSubmitSite::KEY))->toBe($site);
})->with([
    'from the en site' => ['http://localhost/contact', 'en'],
    'from the es site' => ['http://localhost/es/contacto', 'es'],
]);

it('ignores a _site value in the request that the form tag did not sign', function (): void {
    // Save the form
    feedbackForm();

    // Submit from an English page, posting a Spanish _site
    postFeedback($this, 'http://localhost/contact', ['_site' => 'es']);

    // Expect the English site recorded
    expect(storedFeedback()[0]->get(RecordSubmitSite::KEY))->toBe('en');
});

it('records the signed site the form carried, whatever page the referrer names', function (): void {
    // Save the form
    feedbackForm();

    // Submit from an English page, carrying the Spanish site the form was rendered on
    postFeedback($this, 'http://localhost/contact', [RequestSite::FIELD => RequestSite::sign('es')]);

    // Expect the Spanish site recorded
    expect(storedFeedback()[0]->get(RecordSubmitSite::KEY))->toBe('es');
});

it('records the site on the submission the email job receives, when submissions are not stored', function (): void {
    // Fake the bus and save a form that does not store submissions
    Bus::fake();
    feedbackForm(store: false);

    // Submit from a Spanish page
    postFeedback($this, 'http://localhost/es/contacto');

    // Expect the email job's submission to carry the Spanish site
    Bus::assertDispatched(SendEmails::class, function (SendEmails $job): bool {
        $submission = new ReflectionProperty($job, 'submission')->getValue($job);

        return $submission instanceof Submission && $submission->get(RecordSubmitSite::KEY) === 'es';
    });

    // Expect nothing stored
    expect(storedFeedback())->toBe([]);
});

it('records the site at the first save when an earlier listener halts FormSubmitted', function (): void {
    // Save the form
    feedbackForm();

    // Put a halting listener ahead of the add-on's own
    Event::forget(FormSubmitted::class);
    Event::listen(FormSubmitted::class, fn (): bool => true);
    Event::listen(FormSubmitted::class, [RecordSubmitSite::class, 'submitted']);

    // Submit from a Spanish page
    postFeedback($this, 'http://localhost/es/contacto');

    // Expect the Spanish site recorded anyway
    expect(storedFeedback()[0]->get(RecordSubmitSite::KEY))->toBe('es');
});

it('leaves a submission saved without the submit route unattributed', function (): void {
    // Save the form
    $form = feedbackForm();

    // Save a submission directly, outside any request
    $submission = $form->makeSubmission();
    $submission->data(['message' => 'hello']);
    $submission->save();

    // Expect one submission with no site
    $submissions = storedFeedback();
    expect($submissions)->toHaveCount(1)
        ->and($submissions[0]->data()->has(RecordSubmitSite::KEY))->toBeFalse();
});

it('leaves a submission saved on another named route unattributed', function (): void {
    // Save the form
    feedbackForm();

    // Register a named route that saves a submission itself
    $probe = Route::post('msf-probe-save', function (): string {
        // Find the feedback form, refusing if it is missing
        $form = FormFacade::find('feedback');
        if (! $form instanceof Form) {
            throw new RuntimeException('The feedback form is missing.');
        }

        // Save a submission to it
        $submission = $form->makeSubmission();
        $submission->data(['message' => 'saved elsewhere']);
        $submission->save();

        return 'saved';
    })->name('msf.probe.save');

    // Ahead of Statamic's catch-all frontend route, which would otherwise match first.
    $routes = new RouteCollection;
    $routes->add($probe);
    foreach (Route::getRoutes()->getRoutes() as $route) {
        // Add every other route after the probe
        if ($route !== $probe) {
            $routes->add($route);
        }
    }

    // Install the reordered routes
    Route::setRoutes($routes);

    // Call the probe route from a Spanish page
    $this->post('/msf-probe-save', [], ['referer' => 'http://localhost/es/contacto'])->assertOk();

    // Expect one submission with no site
    $submissions = storedFeedback();
    expect($submissions)->toHaveCount(1)
        ->and($submissions[0]->data()->has(RecordSubmitSite::KEY))->toBeFalse();
});

it("leaves another form's submission saved during a submit unattributed", function (): void {
    // Save the feedback form and an audit form that stores submissions
    feedbackForm();
    $audit = new Form;
    $audit->handle('audit');
    $audit->store(true);
    $audit->save();

    // Mirror each feedback submission into the audit form as it is created
    Event::listen(SubmissionCreated::class, function (SubmissionCreated $event) use ($audit): void {
        // Check that the new submission belongs to the feedback form
        $submission = $event->submission;
        if ($submission instanceof Submission && $submission->form()->handle() === 'feedback') {
            // Save a mirror submission to the audit form
            $mirror = $audit->makeSubmission();
            $mirror->data(['message' => 'mirrored']);
            $mirror->save();
        }
    });

    // Submit feedback from a Spanish page
    postFeedback($this, 'http://localhost/es/contacto');

    // Expect the site on the feedback submission and none on the mirror
    $mirrored = array_values($audit->submissions()->all());
    expect(storedFeedback()[0]->get(RecordSubmitSite::KEY))->toBe('es')
        ->and($mirrored)->toHaveCount(1)
        ->and($mirrored[0] instanceof Submission ? $mirrored[0]->data()->has(RecordSubmitSite::KEY) : null)->toBeFalse();
});
