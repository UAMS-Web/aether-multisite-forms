<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Forms\Multisite\Tests\Feature;

use Illuminate\Support\Facades\File;
use Statamic\Facades\Form;
use Statamic\Facades\Stache;
use Statamic\Facades\YAML;
use Tests\TestCase;

/**
 * Host-bound: a submit from a page on each site of a real two-site host stores a
 * submission file whose `_site` is that site, whatever the request says (#74).
 *
 * It reads the stored files themselves rather than the Stache, so it shows what
 * lands on disk. Form files and submissions go to a temporary directory, so the
 * host's own content is never written.
 *
 * @command  php artisan test vendor/uams-web/aether-multisite-forms/tests/Feature/RecordSubmitSiteTest.php
 */
final class RecordSubmitSiteTest extends TestCase
{
    /**
     * The temporary directory this test's forms and submissions live in.
     *
     * @var string Absolute path, unique to this test.
     */
    private string $directory;

    /**
     * Boot the host, point forms and submissions at a temporary directory, and
     * save a global form that stores submissions.
     */
    protected function setUp(): void
    {
        // Boot the host application
        parent::setUp();

        // Point form files at a unique temporary directory
        $this->directory = sys_get_temp_dir().'/msf-host-site-'.uniqid('', true);
        config(['statamic.forms.forms' => $this->directory]);
        // Store submissions beneath it too
        Stache::store('form-submissions')->directory($this->directory.'/_submissions');

        // Save a global form that stores its submissions
        Form::make('msf_host_site')->title('Site')->store(true)->save();
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

    /** A submit from each site stores a file whose `_site` is that site. */
    public function test_each_site_is_recorded_in_the_stored_file(): void
    {
        // Submit once from an en page and once from an es page
        $this->post('/!/forms/msf_host_site', ['message' => 'en'], ['referer' => url('/contact')])->assertRedirect();
        $this->post('/!/forms/msf_host_site', ['message' => 'es'], ['referer' => url('/es/contacto')])->assertRedirect();

        // Expect one file recorded for each site
        $this->assertSame(['en', 'es'], $this->storedSites());
    }

    /** A `_site` in the request body does not change the recorded site. */
    public function test_a_forged_site_is_ignored(): void
    {
        // Submit from an en page with a forged es site in the body
        $this->post('/!/forms/msf_host_site', ['message' => 'forged', '_site' => 'es'], ['referer' => url('/contact')])->assertRedirect();

        // Expect the file recorded for en
        $this->assertSame(['en'], $this->storedSites());
    }

    /**
     * The `_site` of each stored submission file, sorted.
     *
     * @return list<string|null> The recorded site of each file, null where absent.
     */
    private function storedSites(): array
    {
        // Find the stored submission files and expect at least one
        $files = File::glob($this->directory.'/_submissions/msf_host_site/*.yaml');
        $this->assertNotEmpty($files, 'No submission file was stored.');

        // Read each file's recorded site and sort them
        $sites = array_map(fn (string $file): mixed => YAML::parse(File::get($file))['_site'] ?? null, $files);
        sort($sites);

        return $sites;
    }
}
