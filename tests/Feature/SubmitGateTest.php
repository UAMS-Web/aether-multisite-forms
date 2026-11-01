<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Forms\Multisite\Tests\Feature;

use Illuminate\Support\Facades\File;
use Statamic\Facades\Form;
use Statamic\Facades\Stache;
use Tests\TestCase;

/**
 * Host-bound: core's form submit route, in a real two-site host, accepts a submit
 * from a site the form is assigned to and answers 404 from one it is not (#72).
 *
 * It runs through the host's own `web` group, kernel and route registration, which
 * is what attaching the gate from the add-on depends on. Form files and stored
 * submissions go to a temporary directory, so the host's own content is never
 * written.
 *
 * @command  php artisan test vendor/uams-web/aether-multisite-forms/tests/Feature/SubmitGateTest.php
 */
final class SubmitGateTest extends TestCase
{
    /**
     * The temporary directory this test's forms and submissions live in.
     *
     * @var string Absolute path, unique to this test.
     */
    private string $directory;

    /**
     * Boot the host and point forms and submissions at a temporary directory.
     */
    protected function setUp(): void
    {
        // Boot the host application
        parent::setUp();

        // Point form files at a unique temporary directory
        $this->directory = sys_get_temp_dir().'/msf-host-submit-'.uniqid('', true);
        config(['statamic.forms.forms' => $this->directory]);
        // Store submissions beneath it too
        Stache::store('form-submissions')->directory($this->directory.'/_submissions');
    }

    /**
     * Delete the temporary directory and tear the host down.
     */
    protected function tearDown(): void
    {
        // Remove this test's forms and submissions
        File::deleteDirectory($this->directory);

        // Tear down the host application
        parent::tearDown();
    }

    /** A form assigned to `en` accepts a submit from the `en` site and stores it. */
    public function test_a_submit_from_an_assigned_site_is_stored(): void
    {
        // Save a form assigned to en
        $this->makeForm(['en']);

        // Submit it from an en page and expect a redirect
        $this->post('/!/forms/msf_host_submit', ['message' => 'hello'], ['referer' => url('/contact')])->assertRedirect();

        // Expect one stored submission
        $this->assertSame(1, Form::find('msf_host_submit')->submissions()->count());
    }

    /** The same form answers 404 to a submit from the `es` site and stores nothing. */
    public function test_a_submit_from_an_unassigned_site_is_not_found(): void
    {
        // Save a form assigned to en
        $this->makeForm(['en']);

        // Submit it from an es page and expect a 404
        $this->post('/!/forms/msf_host_submit', ['message' => 'hello'], ['referer' => url('/es/contacto')])->assertNotFound();

        // Expect nothing stored
        $this->assertSame(0, Form::find('msf_host_submit')->submissions()->count());
    }

    /** A form with no sites accepts a submit from each site, as core does. */
    public function test_a_global_form_accepts_a_submit_from_each_site(): void
    {
        // Save a form with no sites
        $this->makeForm(null);

        // Submit it from an en page and from an es page
        $this->post('/!/forms/msf_host_submit', ['message' => 'en'], ['referer' => url('/contact')])->assertRedirect();
        $this->post('/!/forms/msf_host_submit', ['message' => 'es'], ['referer' => url('/es/contacto')])->assertRedirect();

        // Expect both submissions stored
        $this->assertSame(2, Form::find('msf_host_submit')->submissions()->count());
    }

    /**
     * Save the `msf_host_submit` form, assigned to the given sites.
     *
     * @param  list<string>|null  $sites  Handles to assign, or null for no `sites` key.
     */
    private function makeForm(?array $sites): void
    {
        // Make the form with storage on
        $form = Form::make('msf_host_submit')->title('Submit')->store(true);
        // Assign the sites only when some were given
        if ($sites !== null) {
            $form->set('sites', $sites);
        }

        // Save the form
        $form->save();
    }
}
