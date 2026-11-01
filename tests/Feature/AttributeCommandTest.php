<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Forms\Multisite\Tests\Feature;

use Illuminate\Support\Facades\File;
use Statamic\Facades\Form;
use Statamic\Facades\FormSubmission;
use Statamic\Facades\Stache;
use Tests\TestCase;

/**
 * Host-bound: on a real multisite host, `multisite-forms:attribute` records a
 * site on a form's unattributed submissions, a dry run changes nothing, and an
 * unknown site or one the form is not assigned to is refused (#81).
 *
 * The add-on's Integration suite covers the command against a minimal Testbench
 * app; this run confirms it is registered, and resolves the host's own sites,
 * behind the host's full provider list. Forms and submissions go to a temporary
 * path, so the host's own are never written.
 *
 * @command  php artisan test vendor/uams-web/aether-multisite-forms/tests/Feature/AttributeCommandTest.php
 */
final class AttributeCommandTest extends TestCase
{
    /**
     * The temporary directory this test's forms and submissions live in.
     *
     * @var string Absolute path, unique to this test.
     */
    private string $formsDirectory;

    /**
     * Boot the host, point forms and submissions at a temporary directory, and
     * save a shared form with two attributed and two unattributed submissions.
     */
    protected function setUp(): void
    {
        // Boot the host application
        parent::setUp();

        // Point form files at a unique temporary directory
        $this->formsDirectory = sys_get_temp_dir().'/msf-host-attribute-'.uniqid('', true);
        config(['statamic.forms.forms' => $this->formsDirectory]);
        // Store submissions beneath it too
        Stache::store('form-submissions')->directory($this->formsDirectory.'/_submissions');

        // Save a form shared by en and es
        $form = Form::make('msf_host_attribute');
        $form->title('msf_host_attribute');
        $form->set('sites', ['en', 'es']);
        $form->save();

        // Save one submission per site, and two that record no site
        foreach (['en', 'es', null, null] as $index => $site) {
            $submission = $form->makeSubmission();
            // Core's default id has 0.1ms resolution, so two saved that close would share one
            $submission->id("msf-host-attribute-{$index}");
            // Name the submission after its site and position
            $submission->set('name', ($site ?? 'none').$index);
            // Record the site only when there is one
            if ($site !== null) {
                $submission->set('_site', $site);
            }

            // Save the submission
            $submission->save();
        }
    }

    /**
     * Delete the temporary directory and tear the host down.
     */
    protected function tearDown(): void
    {
        // Remove this test's forms and submissions
        File::deleteDirectory($this->formsDirectory);

        // Tear down the host application
        parent::tearDown();
    }

    /**
     * The site each of the form's submissions records, keyed by name.
     *
     * @return array<string, mixed> Each submission's name, with the site it records.
     */
    private function recordedSites(): array
    {
        // Map each submission's name to its recorded site
        $sites = [];
        foreach (FormSubmission::whereForm('msf_host_attribute') as $submission) {
            $sites[$submission->get('name')] = $submission->get('_site');
        }

        // Sort by name so the order is stable
        ksort($sites);

        return $sites;
    }

    /**
     * A dry run reports two and changes nothing; the real run records the site
     * on those two only.
     */
    public function test_it_attributes_the_unattributed_submissions(): void
    {
        // Dry-run the command and expect it to report two without changing them
        $this->artisan('multisite-forms:attribute', ['form' => 'msf_host_attribute', 'site' => 'es', '--dry-run' => true])
            ->expectsOutputToContain('Would attribute 2 submissions of [msf_host_attribute] to [es].')
            ->assertSuccessful();
        $this->assertSame(['en0' => 'en', 'es1' => 'es', 'none2' => null, 'none3' => null], $this->recordedSites());

        // Run the command and expect es recorded on the two unattributed only
        $this->artisan('multisite-forms:attribute', ['form' => 'msf_host_attribute', 'site' => 'es'])
            ->expectsOutputToContain('Attributed 2 submissions of [msf_host_attribute] to [es].')
            ->assertSuccessful();
        $this->assertSame(['en0' => 'en', 'es1' => 'es', 'none2' => 'es', 'none3' => 'es'], $this->recordedSites());
    }

    /** A site the host does not configure is refused, and nothing changes. */
    public function test_it_refuses_an_unknown_site(): void
    {
        // Run the command for an unknown site and expect it refused
        $this->artisan('multisite-forms:attribute', ['form' => 'msf_host_attribute', 'site' => 'msf-no-such-site'])
            ->expectsOutputToContain('There is no site [msf-no-such-site].')
            ->assertFailed();

        // Expect every recorded site unchanged
        $this->assertSame(['en0' => 'en', 'es1' => 'es', 'none2' => null, 'none3' => null], $this->recordedSites());
    }
}
