<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Forms\Multisite\Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\Form;
use Statamic\Facades\Role;
use Statamic\Facades\User;
use Statamic\Fields\BlueprintRepository;
use Tests\TestCase;

/**
 * Host-bound: on a real multisite host, an `es`-only Control Panel user does not
 * see an `en`-only form on the forms index and cannot open the settings of an
 * `en`-and-`es` form or their fields, even with `configure forms` or
 * `configure form fields`, and cannot duplicate the `en`-only one (#77, #116,
 * #117).
 *
 * The add-on's Integration suite covers the gate against a minimal Testbench app;
 * this run confirms it holds behind the host's full provider list, its own sites
 * and its Control Panel middleware. Form files, blueprints and roles go to
 * temporary paths, so the host's own forms, blueprints and roles are never
 * written.
 *
 * @command  php artisan test vendor/uams-web/aether-multisite-forms/tests/Feature/FormSiteAccessTest.php
 */
final class FormSiteAccessTest extends TestCase
{
    /**
     * The temporary directory this test's form files live in.
     *
     * @var string Absolute path, unique to this test.
     */
    private string $formsDirectory;

    /**
     * The temporary roles file this test's roles are saved to.
     *
     * @var string Absolute path, unique to this test.
     */
    private string $rolesFile;

    /**
     * The temporary directory this test's blueprints live in.
     *
     * @var string Absolute path, unique to this test.
     */
    private string $blueprintsDirectory;

    /**
     * Boot the host, point forms, roles and blueprints at temporary paths, and
     * save an `en`-only form and an `en`-and-`es` form.
     */
    protected function setUp(): void
    {
        // Boot the host application
        parent::setUp();

        // Point form files at a unique temporary directory
        $this->formsDirectory = sys_get_temp_dir().'/msf-host-forms-'.uniqid('', true);
        config(['statamic.forms.forms' => $this->formsDirectory]);

        // Point roles at a unique temporary file
        $this->rolesFile = sys_get_temp_dir().'/msf-host-roles-'.uniqid('', true).'.yaml';
        Role::path($this->rolesFile);

        // The Duplicate action writes the copy's blueprint, so blueprints go to a
        // directory of this test's own
        $this->blueprintsDirectory = sys_get_temp_dir().'/msf-host-blueprints-'.uniqid('', true);
        app(BlueprintRepository::class)->setDirectories($this->blueprintsDirectory);

        // Save an en-only form and a form shared by en and es
        foreach (['msf_host_english' => ['en'], 'msf_host_shared' => ['en', 'es']] as $handle => $sites) {
            $form = Form::make($handle);
            $form->title($handle);
            $form->set('sites', $sites);
            $form->save();
        }
    }

    /**
     * Delete the temporary paths and tear the host down.
     */
    protected function tearDown(): void
    {
        // Remove this test's forms, roles and blueprints
        File::deleteDirectory($this->formsDirectory);
        File::delete($this->rolesFile);
        File::deleteDirectory($this->blueprintsDirectory);

        // Tear down the host application
        parent::tearDown();
    }

    /**
     * A user who can access only the `es` site, with these further permissions.
     *
     * @param  string  $id  Id of both the user and its role, unique in the test.
     * @param  list<string>  $permissions  Permissions granted beyond the Control
     *                                     Panel and the `es` site.
     * @return UserContract An unsaved user assigned the new role.
     */
    private function spanishUser(string $id, array $permissions): UserContract
    {
        // Save a role with es access and the given permissions
        Role::make($id)->title($id)->permissions(['access cp', 'access es site', ...$permissions])->save();

        // Make a user holding that role
        return User::make()->id($id)->email("{$id}@example.com")->assignRole($id);
    }

    /** The forms index lists the shared form and leaves out the en-only one. */
    public function test_the_index_leaves_out_a_form_of_another_site(): void
    {
        // Sign in as an es user who may view both forms' submissions
        $this->actingAs($this->spanishUser('msf-host-es-viewer', [
            'view msf_host_english form submissions',
            'view msf_host_shared form submissions',
        ]));

        // Open the forms index and collect the listed handles
        $page = $this->get(cp_route('forms.index'))->assertOk()->viewData('page');
        $handles = array_column($page['props']['forms'], 'id');

        // Expect the shared form listed and the en-only form left out
        $this->assertContains('msf_host_shared', $handles);
        $this->assertNotContains('msf_host_english', $handles);
    }

    /**
     * The settings screen of a form that also reaches en is refused, configure
     * forms or not.
     */
    public function test_the_settings_screen_is_refused_without_every_site(): void
    {
        // Sign in as an es user who may configure forms
        $this->actingAs($this->spanishUser('msf-host-es-configurer', ['configure forms']));

        // Expect both forms' settings screens refused
        $this->getJson(cp_route('forms.edit', 'msf_host_shared'))->assertForbidden();
        $this->getJson(cp_route('forms.edit', 'msf_host_english'))->assertForbidden();
    }

    /** A super user still opens the same screen, so the refusal is the site rule's. */
    public function test_a_super_user_opens_the_settings_screen(): void
    {
        // Sign in as a super user
        $this->actingAs(User::make()->id('msf-host-admin')->email('msf-host-admin@example.com')->makeSuper());

        // Expect the shared form's settings screen to open
        $this->get(cp_route('forms.edit', 'msf_host_shared'))->assertOk();
    }

    /**
     * The fields screen of a form that also reaches en is refused too, configure
     * form fields or not (#116).
     */
    public function test_the_fields_screen_is_refused_without_every_site(): void
    {
        // Sign in as an es user who may configure form fields
        $this->actingAs($this->spanishUser('msf-host-es-fields', ['configure form fields']));

        // Expect both forms' fields screens refused
        $this->getJson(cp_route('blueprints.forms.edit', 'msf_host_shared'))->assertForbidden();
        $this->getJson(cp_route('blueprints.forms.edit', 'msf_host_english'))->assertForbidden();
    }

    /** A super user opens the same fields screen, so the refusal is the site rule's. */
    public function test_a_super_user_opens_the_fields_screen(): void
    {
        // Sign in as a super user
        $this->actingAs(User::make()->id('msf-host-fields-admin')->email('msf-host-fields-admin@example.com')->makeSuper());

        // Expect the shared form's fields screen to open
        $this->get(cp_route('blueprints.forms.edit', 'msf_host_shared'))->assertOk();
    }

    /**
     * The Duplicate action is refused for a form of another site, and copies one
     * the user can view (#117).
     */
    public function test_the_duplicate_action_follows_the_site_rules(): void
    {
        // Sign in as an es user who may configure forms
        $this->actingAs($this->spanishUser('msf-host-es-duplicator', ['configure forms']));

        // Duplicate the en-only form and expect it refused with no copy made
        $this->duplicate('msf_host_english', 'msf_host_copy_en')->assertForbidden();
        $this->assertNull(Form::find('msf_host_copy_en'));

        // Duplicate the shared form and expect a copy keeping its sites
        $this->duplicate('msf_host_shared', 'msf_host_copy_shared')->assertOk();
        $this->assertSame(['en', 'es'], Form::find('msf_host_copy_shared')?->get('sites'));
    }

    /**
     * Run the Duplicate action on a form, copying it to a new handle.
     *
     * @param  string  $source  Handle of the form to duplicate.
     * @param  string  $copy  Handle and title to give the copy.
     * @return TestResponse The action's response.
     */
    private function duplicate(string $source, string $copy): TestResponse
    {
        return $this->postJson(cp_route('forms.actions.run'), [
            'action' => 'duplicate_form',
            'selections' => [$source],
            'values' => ['title' => $copy, 'handle' => $copy],
        ]);
    }
}
