<?php

declare(strict_types=1);

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\PendingCommand;
use Statamic\Console\RunsInPlease;
use Statamic\Events\SubmissionCreated;
use Statamic\Events\SubmissionSaved;
use Statamic\Events\SubmissionSaving;
use Statamic\Facades\Form as FormFacade;
use Statamic\Facades\FormSubmission;
use Statamic\Facades\Stache;
use Statamic\Facades\YAML;
use Statamic\Forms\Form;
use Statamic\Forms\Submission;
use Statamic\Query\Builder;
use Statamic\Stache\Stores\Store;
use UAMSWeb\Aether\Forms\Multisite\Commands\AttributeSubmissions;
use UAMSWeb\Aether\Forms\Multisite\Forms\RecordSubmitSite;
use UAMSWeb\Aether\Forms\Multisite\Tests\TestCase;

/**
 * `multisite-forms:attribute {form} {site}` records a site on a form's
 * submissions that have none, and touches nothing else (#81).
 *
 * @command  vendor/bin/pest tests/Integration/Commands/AttributeSubmissionsTest.php
 */

/** The forms every test stores, by handle, with the sites each lists. */
const ATTRIBUTE_FORMS = [
    'shared' => ['en', 'es'],
    'english' => ['en'],
    'everywhere' => [],
];

beforeEach(function (): void {
    // Configure two sites
    useTwoSites();

    // Every info line logged during the test, as [message, context]
    $this->logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event): void {
        // Record the line when it is logged at info
        if ($event->level === 'info') {
            $this->logged[] = [$event->message, $event->context];
        }
    });

    // Use a temporary forms directory
    $this->directory = useTemporaryFormsDirectory();

    // Find the submissions store, failing when the Stache has none
    $store = Stache::store('form-submissions');
    if (! $store instanceof Store) {
        throw new RuntimeException('The Stache has no form submissions store.');
    }

    // Store submissions beside the temporary forms
    $store->directory($this->directory.'/_submissions');

    // Store each test form with its sites and its submissions
    foreach (ATTRIBUTE_FORMS as $handle => $sites) {
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

        // One submission per site and two with no site, named after the site. Core's
        // default id has 0.1ms resolution, so two saved that close would share one.
        foreach (['en', 'es', null, null] as $index => $site) {
            // Make the submission with a fixed id, named after its site
            $submission = $form->makeSubmission();
            $submission->id("{$handle}-{$index}");
            $submission->set('name', ($site ?? 'none').$index);

            // Attribute it to its site unless it has none
            if ($site !== null) {
                $submission->set('_site', $site);
            }

            // Save the submission
            $submission->save();
        }
    }
});

afterEach(function (): void {
    removeFormsDirectory();
});

/**
 * The attribute command, run with the given arguments once its expectations are
 * set.
 *
 * @param  TestCase  $case  The running test.
 * @param  array<string, mixed>  $arguments  The command's arguments and options.
 * @return PendingCommand The command, not yet run.
 *
 * @throws RuntimeException When the command ran before it could be returned.
 */
function attribute(TestCase $case, array $arguments): PendingCommand
{
    // Prepare the command, failing if it has already run
    $command = $case->artisan('multisite-forms:attribute', $arguments);
    if (! $command instanceof PendingCommand) {
        throw new RuntimeException('The command ran before its expectations were set.');
    }

    // Return it for the caller to set expectations on
    return $command;
}

/**
 * A form's stored submissions.
 *
 * @param  string  $form  The handle of the form.
 * @return iterable<Submission> The form's stored submissions.
 */
function storedSubmissionsOf(string $form): iterable
{
    // The facade declares `mixed`; core returns a collection of its submissions
    /** @var iterable<Submission> */
    return FormSubmission::whereForm($form);
}

/**
 * Each submission of a form by name, with the site it records, or null for
 * none.
 *
 * @param  string  $form  The handle of the form.
 * @return array<string, mixed> Each recorded site, keyed and sorted by name.
 *
 * @throws RuntimeException When a submission has no name.
 */
function recordedSites(string $form): array
{
    // Map each submission's name to the site it records
    $sites = [];
    foreach (storedSubmissionsOf($form) as $submission) {
        // Read the name, failing when the submission has none
        $name = $submission->get('name');
        if (! is_string($name)) {
            throw new RuntimeException('A submission has no name.');
        }

        // Record the submission's site under its name
        $sites[$name] = $submission->get('_site');
    }

    // Sort by name so the order is stable
    ksort($sites);

    return $sites;
}

/**
 * Every stored file under a directory, by path, with a hash of its contents.
 *
 * @param  string  $directory  The directory to read.
 * @return array<string, string> The MD5 hash of each file, keyed by path.
 */
function storedFiles(string $directory): array
{
    // Hash each file under the directory
    $files = [];
    foreach (File::allFiles($directory) as $file) {
        $files[$file->getPathname()] = (string) md5_file($file->getPathname());
    }

    // Sort by path so the order is stable
    ksort($files);

    return $files;
}

/**
 * The ids of a form's submissions that record no site.
 *
 * @param  string  $form  The handle of the form.
 * @return list<string> The ids, sorted.
 */
function unattributedIds(string $form): array
{
    // Collect the id of each submission that records no site
    $ids = [];
    foreach (storedSubmissionsOf($form) as $submission) {
        // Keep the id when the submission has one and records no site
        $id = $submission->id();
        if ($submission->get('_site') === null && is_string($id)) {
            $ids[] = $id;
        }
    }

    // Sort the ids so the order is stable
    sort($ids);

    return $ids;
}

/**
 * Store a submission of a form whose file holds an empty `_site`, as a direct
 * import writes it: core drops an empty `_site` whenever it saves one (#130).
 *
 * @param  string  $directory  The temporary forms directory.
 * @param  string  $form  The handle of the form.
 * @param  string  $id  The id to give the submission.
 * @return string The submission file's path
 *
 * @throws RuntimeException When the form is missing or the file not stored.
 */
function saveWithEmptySite(string $directory, string $form, string $id): string
{
    // Find the form, failing when it is missing
    $handle = FormFacade::find($form);
    if (! $handle instanceof Form) {
        throw new RuntimeException("There is no form [{$form}].");
    }

    // Save a submission with a placeholder site
    $submission = $handle->makeSubmission();
    $submission->id($id);
    $submission->set('name', 'blank');
    $submission->set('_site', 'placeholder');
    $submission->save();

    // Find the submission's file among the stored ones
    $path = null;
    foreach (File::allFiles($directory.'/_submissions') as $file) {
        if ($file->getFilename() === "{$id}.yaml") {
            $path = $file->getPathname();
        }
    }

    // Fail when the file was not stored
    if ($path === null) {
        throw new RuntimeException("The submission [{$id}] was not stored.");
    }

    // Empty the site in the file directly and drop the cached copy
    $data = YAML::parse(File::get($path));
    $data['_site'] = '';
    File::put($path, YAML::dump($data));
    Stache::clear();

    return $path;
}

it('records the site on the submissions that have none, and leaves the rest', function (): void {
    // Expect the command to attribute the two unattributed submissions
    attribute($this, ['form' => 'shared', 'site' => 'es'])
        ->expectsOutputToContain('Attributed 2 submissions of [shared] to [es].')
        ->assertSuccessful();

    // Expect only those two now recording es
    expect(recordedSites('shared'))->toBe(['en0' => 'en', 'es1' => 'es', 'none2' => 'es', 'none3' => 'es']);
});

it('attributes a submission whose file holds an empty site, and leaves no empty site behind', function (): void {
    // Store a submission whose file holds an empty site, and confirm it does
    $path = saveWithEmptySite($this->directory, 'shared', 'shared-blank');
    expect(YAML::parse(File::get($path)))->toHaveKey('_site', '');

    // Expect the command to attribute it with the others
    attribute($this, ['form' => 'shared', 'site' => 'es'])
        ->expectsOutputToContain('Attributed shared-blank')
        ->expectsOutputToContain('Attributed 3 submissions of [shared] to [es].')
        ->assertSuccessful();

    // Drop the cached copies so the files are read again
    Stache::clear();

    // Expect the file and every unattributed submission to record es
    expect(YAML::parse(File::get($path)))->toHaveKey('_site', 'es')
        ->and(recordedSites('shared'))->toBe(['blank' => 'es', 'en0' => 'en', 'es1' => 'es', 'none2' => 'es', 'none3' => 'es']);
});

it('asks the query for a missing site as well as an empty one', function (): void {
    // Core's flat-file builder compares a missing value as empty, so only a
    // builder that treats NULL apart from '' (the Eloquent driver's) tells the
    // two conditions apart; record what the command asks of the builder instead
    $group = Mockery::mock(Builder::class);
    $group->shouldReceive('whereNull')->once()->with(RecordSubmitSite::KEY);
    $group->shouldReceive('orWhere')->once()->with(RecordSubmitSite::KEY, '');

    // Hand the command a builder that runs the nested group against the recorder
    $query = Mockery::mock(Builder::class);
    $query->shouldReceive('where')->once()->with('form', 'shared')->andReturnSelf();
    $query->shouldReceive('where')->once()->with(Mockery::on(function (Closure $condition) use ($group): bool {
        $condition($group);

        return true;
    }))->andReturnSelf();
    $query->shouldReceive('get')->once()->andReturn(collect());
    FormSubmission::shouldReceive('query')->once()->andReturn($query);

    // Expect the command to find nothing to attribute through that builder
    attribute($this, ['form' => 'shared', 'site' => 'es'])->assertSuccessful();
});

it('counts a submission with an empty site in a dry run, and leaves its file alone', function (): void {
    // Store a submission with an empty site and keep its file's contents
    $path = saveWithEmptySite($this->directory, 'shared', 'shared-blank');
    $before = File::get($path);

    // Expect a dry run to count it with the others
    attribute($this, ['form' => 'shared', 'site' => 'es', '--dry-run' => true])
        ->expectsOutputToContain('Would attribute shared-blank')
        ->expectsOutputToContain('Would attribute 3 submissions of [shared] to [es].')
        ->assertSuccessful();

    // Expect its file unchanged
    expect(File::get($path))->toBe($before);
});

it("leaves another form's submission with an empty site alone", function (): void {
    // Store a submission with an empty site on the en-only form
    $path = saveWithEmptySite($this->directory, 'english', 'english-blank');

    // Expect the command on the shared form to attribute only its own two
    attribute($this, ['form' => 'shared', 'site' => 'en'])
        ->expectsOutputToContain('Attributed 2 submissions of [shared] to [en].')
        ->assertSuccessful();

    // Expect the en-only form's file to keep its empty site
    expect(YAML::parse(File::get($path)))->toHaveKey('_site', '');
});

it("leaves other forms' submissions alone", function (): void {
    // Attribute the shared form's submissions to en
    attribute($this, ['form' => 'shared', 'site' => 'en'])->assertSuccessful();

    // Expect the other forms' unattributed submissions untouched
    expect(recordedSites('english'))->toBe(['en0' => 'en', 'es1' => 'es', 'none2' => null, 'none3' => null])
        ->and(recordedSites('everywhere'))->toBe(['en0' => 'en', 'es1' => 'es', 'none2' => null, 'none3' => null]);
});

it('attributes the submissions of a form with no sites to any site', function (): void {
    // Attribute the global form's submissions to es
    attribute($this, ['form' => 'everywhere', 'site' => 'es'])->assertSuccessful();

    // Expect its unattributed submissions now recording es
    expect(recordedSites('everywhere'))->toBe(['en0' => 'en', 'es1' => 'es', 'none2' => 'es', 'none3' => 'es']);
});

it('stores the site so a later read finds it', function (): void {
    // Attribute the shared form's submissions to en
    attribute($this, ['form' => 'shared', 'site' => 'en'])->assertSuccessful();

    // Drop the cached copies so the files are read again
    Stache::clear();

    // Expect the stored files to record en
    expect(recordedSites('shared'))->toBe(['en0' => 'en', 'es1' => 'es', 'none2' => 'en', 'none3' => 'en']);
});

it('names each submission it changes, and logs each with its id', function (): void {
    // Prepare the command, expecting it to name each unattributed submission
    $ids = unattributedIds('shared');
    $command = attribute($this, ['form' => 'shared', 'site' => 'es']);
    foreach ($ids as $id) {
        $command->expectsOutputToContain("Attributed {$id}");
    }

    // Run the command, expecting it to succeed
    $command->assertSuccessful();
    $command->run();

    // Build the info line expected for each submission
    $expected = array_map(static fn (string $id): array => ['Multisite Forms attributed a submission to a site.', [
        'form' => 'shared',
        'submission' => $id,
        'site' => 'es',
    ]], $ids);

    // Expect one logged line per changed submission
    expect($ids)->toHaveCount(2)
        ->and($this->logged)->toEqualCanonicalizing($expected);
});

it('reports what a dry run would change, and writes and logs nothing', function (): void {
    // Note the unattributed ids and the stored files before the run
    $ids = unattributedIds('shared');
    $before = storedFiles($this->directory);

    // Prepare a dry run, expecting it to name each unattributed submission
    $command = attribute($this, ['form' => 'shared', 'site' => 'es', '--dry-run' => true]);
    foreach ($ids as $id) {
        $command->expectsOutputToContain("Would attribute {$id}");
    }

    // Run it, expecting the summary and success
    $command->expectsOutputToContain('Would attribute 2 submissions of [shared] to [es].')->assertSuccessful();
    $command->run();

    // Drop the cached copies so the files are read again
    Stache::clear();

    // Expect no file changed, no site recorded and nothing logged
    expect(storedFiles($this->directory))->toBe($before)
        ->and($before)->not->toBe([])
        ->and(recordedSites('shared'))->toBe(['en0' => 'en', 'es1' => 'es', 'none2' => null, 'none3' => null])
        ->and($this->logged)->toBe([]);
});

it('saves quietly, so no submission event fires', function (): void {
    // Fake the submission events
    Event::fake([SubmissionCreated::class, SubmissionSaving::class, SubmissionSaved::class]);

    // Attribute the shared form's submissions to es
    attribute($this, ['form' => 'shared', 'site' => 'es'])->assertSuccessful();

    // Expect the site recorded and no submission event dispatched
    expect(recordedSites('shared'))->toHaveKey('none2', 'es');
    Event::assertNotDispatched(SubmissionCreated::class);
    Event::assertNotDispatched(SubmissionSaving::class);
    Event::assertNotDispatched(SubmissionSaved::class);
});

it('counts one submission, and none', function (): void {
    // Attribute every unattributed submission of the shared form
    attribute($this, ['form' => 'shared', 'site' => 'es'])->assertSuccessful();

    // Expect a second run to count none
    attribute($this, ['form' => 'shared', 'site' => 'es'])
        ->expectsOutputToContain('Attributed 0 submissions of [shared] to [es].')
        ->assertSuccessful();

    // Save one new unattributed submission
    $form = FormFacade::find('shared');
    $submission = $form->makeSubmission();
    $submission->set('name', 'late');
    $submission->save();

    // Expect the next run to count it in the singular
    attribute($this, ['form' => 'shared', 'site' => 'en'])
        ->expectsOutputToContain('Attributed 1 submission of [shared] to [en].')
        ->assertSuccessful();
});

it('refuses an unknown form, an unknown site, or a site the form is not assigned to, and changes nothing', function (string $form, string $site, string $message): void {
    // Note the stored files before the run
    $before = storedFiles($this->directory);

    // Expect the command to fail with the case's message
    attribute($this, ['form' => $form, 'site' => $site])
        ->expectsOutputToContain($message)
        ->assertFailed();

    // Expect no stored file changed
    expect(storedFiles($this->directory))->toBe($before);
})->with([
    'an unknown form' => ['missing', 'en', 'There is no form [missing].'],
    'a form handle in another case' => ['Shared', 'en', 'There is no form [Shared].'],
    'an unknown site' => ['shared', 'fr', 'There is no site [fr].'],
    'a site the form is not assigned to' => ['english', 'es', 'The form [english] is not assigned to the site [es].'],
]);

it('is registered under its own name for artisan and please', function (): void {
    expect(Artisan::all())->toHaveKey('multisite-forms:attribute')
        ->and(Artisan::all()['multisite-forms:attribute'])->toBeInstanceOf(AttributeSubmissions::class)
        ->and(class_uses(AttributeSubmissions::class))->toHaveKey(RunsInPlease::class);
});
