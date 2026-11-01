<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Forms\Multisite\Tests\Feature;

use Illuminate\Support\Facades\File;
use Statamic\Facades\Form;
use Statamic\Facades\User;
use Statamic\Facades\YAML;
use Tests\TestCase;

/**
 * Host-bound: the form settings screen of a real multisite host shows the Sites
 * section, and saving it writes `sites` to the form file (#70).
 *
 * The add-on's own Integration suite covers the same requests against a minimal
 * Testbench app; this run confirms the section survives the host's full provider
 * list, its own sites and its Control Panel middleware. Form files go to a
 * temporary directory, so the host's own forms are never written.
 *
 * @command  php artisan test vendor/uams-web/aether-multisite-forms/tests/Feature/SitesConfigSectionTest.php
 */
final class SitesConfigSectionTest extends TestCase
{
    /**
     * The temporary directory this test's form files live in.
     *
     * @var string Absolute path, unique to this test.
     */
    private string $formsDirectory;

    /**
     * Boot the host, point forms at a temporary directory, sign in as a super
     * user, and save the form under test.
     */
    protected function setUp(): void
    {
        // Boot the host application
        parent::setUp();

        // Point form files at a unique temporary directory
        $this->formsDirectory = sys_get_temp_dir().'/msf-host-forms-'.uniqid('', true);
        config(['statamic.forms.forms' => $this->formsDirectory]);

        // Sign in as a super user
        $user = User::make()->id('msf-host-admin')->email('msf-host-admin@example.com')->makeSuper();
        $this->actingAs($user);

        // Save the contact form
        $form = Form::make('msf_host_contact');
        $form->title('Contact');
        $form->save();
    }

    /**
     * Delete the temporary directory and tear the host down.
     */
    protected function tearDown(): void
    {
        // Remove this test's form files
        File::deleteDirectory($this->formsDirectory);

        // Tear down the host application
        parent::tearDown();
    }

    /**
     * The settings screen carries a Sites section holding the `sites` field and
     * the overrides grid (#76).
     */
    public function test_the_settings_screen_shows_the_sites_section(): void
    {
        // Open the form's settings screen
        $response = $this->get(cp_route('forms.edit', 'msf_host_contact'))->assertOk();

        // Collect each field's type from the Sites section
        $page = $response->viewData('page');
        $fields = [];
        foreach ($page['props']['blueprint']['tabs'] as $tab) {
            foreach ($tab['sections'] as $section) {
                // Check whether this is the Sites section
                if ($section['display'] === 'Sites') {
                    foreach ($section['fields'] as $field) {
                        $fields[$field['handle']] = $field['type'];
                    }
                }
            }
        }

        // Expect the sites field and the overrides grid
        $this->assertSame(['sites' => 'sites', 'site_overrides' => 'grid'], $fields);
    }

    /**
     * Saving a selection writes it to the form file, and clearing it removes
     * the key.
     */
    public function test_saving_the_section_writes_the_form_file(): void
    {
        // Name the update route and the form file
        $url = cp_route('forms.update', 'msf_host_contact');
        $file = $this->formsDirectory.'/msf_host_contact.yaml';

        // Save es as the selection and expect it in the file
        $this->patchJson($url, ['title' => 'Contact', 'sites' => ['es']])->assertOk();
        $this->assertSame(['es'], YAML::file($file)->parse()['sites'] ?? null);

        // Clear the selection and expect the key gone
        $this->patchJson($url, ['title' => 'Contact', 'sites' => []])->assertOk();
        $this->assertArrayNotHasKey('sites', YAML::file($file)->parse());
    }
}
