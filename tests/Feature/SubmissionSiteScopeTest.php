<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Forms\Multisite\Tests\Feature;

use Illuminate\Support\Facades\File;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\Form;
use Statamic\Facades\Role;
use Statamic\Facades\Stache;
use Statamic\Facades\User;
use Tests\TestCase;

/**
 * Host-bound: on a real multisite host, an `es`-only Control Panel user lists,
 * opens and exports only the `es` submission of an `en`-and-`es` form, while a
 * super user reads all three (#78), and the Site filter narrows both to one site
 * (#58).
 *
 * The add-on's Integration suite covers the scope against a minimal Testbench
 * app; this run confirms it holds behind the host's full provider list, its own
 * sites, its Control Panel middleware and its submission store. Forms,
 * submissions and roles go to temporary paths, so the host's own are never
 * written.
 *
 * @command  php artisan test vendor/uams-web/aether-multisite-forms/tests/Feature/SubmissionSiteScopeTest.php
 */
final class SubmissionSiteScopeTest extends TestCase
{
    /**
     * The temporary directory this test's forms and submissions live in.
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
     * The ids of the three saved submissions.
     *
     * @var array<string, string> Submission ids by the name each was saved with.
     */
    private array $ids = [];

    /**
     * Boot the host, point forms, submissions and roles at temporary paths, and
     * save a shared form with an `en`, an `es` and an unattributed submission.
     */
    protected function setUp(): void
    {
        // Boot the host application
        parent::setUp();

        // Point form files at a unique temporary directory
        $this->formsDirectory = sys_get_temp_dir().'/msf-host-scope-'.uniqid('', true);
        config(['statamic.forms.forms' => $this->formsDirectory]);
        // Store submissions beneath it too
        Stache::store('form-submissions')->directory($this->formsDirectory.'/_submissions');

        // Point roles at a unique temporary file
        $this->rolesFile = sys_get_temp_dir().'/msf-host-scope-roles-'.uniqid('', true).'.yaml';
        Role::path($this->rolesFile);

        // Save a form shared by en and es
        $form = Form::make('msf_host_scoped');
        $form->title('msf_host_scoped');
        $form->set('sites', ['en', 'es']);
        $form->save();

        // Save one submission per site, and one that records no site
        foreach (['en', 'es', null] as $site) {
            $submission = $form->makeSubmission();
            // Core's default id has 0.1ms resolution, so two saved that close would share one
            $submission->id('msf-host-scoped-'.($site ?? 'none'));
            // Name the submission after its site
            $submission->set('name', $site ?? 'none');
            // Record the site only when there is one
            if ($site !== null) {
                $submission->set('_site', $site);
            }

            // Save the submission and remember its id by name
            $submission->save();
            $this->ids[$site ?? 'none'] = $submission->id();
        }
    }

    /**
     * Delete the temporary paths and tear the host down.
     */
    protected function tearDown(): void
    {
        // Remove this test's forms, submissions and roles
        File::deleteDirectory($this->formsDirectory);
        File::delete($this->rolesFile);

        // Tear down the host application
        parent::tearDown();
    }

    /**
     * A user who can access only the `es` site and read the form's submissions.
     *
     * @return UserContract An unsaved user assigned the `es` reader role.
     */
    private function spanishReader(): UserContract
    {
        // Save a role with es access and permission to read the form's submissions
        Role::make('msf-host-es-reader')->title('msf-host-es-reader')->permissions([
            'access cp',
            'access es site',
            'view msf_host_scoped form submissions',
        ])->save();

        // Make a user holding that role
        return User::make()->id('msf-host-es-reader')->email('msf-host-es-reader@example.com')->assignRole('msf-host-es-reader');
    }

    /** The listing shows the es submission only; a super user sees all three. */
    public function test_the_listing_shows_only_the_sites_a_user_can_access(): void
    {
        // List the submissions as the es reader
        $this->actingAs($this->spanishReader());
        $rows = $this->getJson(cp_route('forms.submissions.index', 'msf_host_scoped'))->assertOk()->json('data');
        $spanish = array_column($rows, 'id');

        // List them again as a super user
        $this->actingAs(User::make()->id('msf-host-scope-admin')->email('msf-host-scope-admin@example.com')->makeSuper());
        $rows = $this->getJson(cp_route('forms.submissions.index', 'msf_host_scoped'))->assertOk()->json('data');
        $every = array_column($rows, 'id');

        // Expect the es submission alone for the reader, and all three otherwise
        $this->assertSame([$this->ids['es']], $spanish);
        $this->assertEqualsCanonicalizing(array_values($this->ids), $every);
    }

    /** The en submission cannot be opened; the es one can. */
    public function test_the_detail_view_of_another_site_is_not_found(): void
    {
        // Sign in as the es reader
        $this->actingAs($this->spanishReader());

        // Expect the en submission not found and the es one to open
        $this->get(cp_route('forms.submissions.show', ['msf_host_scoped', $this->ids['en']]))->assertNotFound();
        $this->get(cp_route('forms.submissions.show', ['msf_host_scoped', $this->ids['es']]))->assertOk();
    }

    /** The export carries the es submission only. */
    public function test_the_export_carries_only_the_sites_a_user_can_access(): void
    {
        // Sign in as the es reader
        $this->actingAs($this->spanishReader());

        // Export the submissions as JSON
        $rows = json_decode((string) $this->get(cp_route('forms.export', ['msf_host_scoped', 'json']))->assertOk()->getContent(), true);

        // Expect the es submission alone
        $this->assertSame([$this->ids['es']], array_column($rows, 'id'));
    }

    /**
     * The submissions page offers the Site filter, which narrows the listing and
     * the export to one site (#58).
     */
    public function test_the_site_filter_narrows_the_listing_and_the_export(): void
    {
        // Sign in as a super user
        $this->actingAs(User::make()->id('msf-host-filter-admin')->email('msf-host-filter-admin@example.com')->makeSuper());

        // Expect the submissions page to offer the Site filter
        $page = $this->get(cp_route('forms.show', 'msf_host_scoped'))->assertOk()->viewData('page');
        $this->assertContains('submission_site', array_column($page['props']['filters'], 'handle'));

        // List and export the submissions filtered to en
        $filters = '?filters='.base64_encode((string) json_encode(['submission_site' => ['site' => 'en']]));
        $listed = $this->getJson(cp_route('forms.submissions.index', 'msf_host_scoped').$filters)->assertOk()->json('data');
        $exported = json_decode((string) $this->get(cp_route('forms.export', ['msf_host_scoped', 'json']).$filters)->assertOk()->getContent(), true);

        // Expect only the en submission in both
        $this->assertSame([$this->ids['en']], array_column($listed, 'id'));
        $this->assertSame([$this->ids['en']], array_column($exported, 'id'));
    }

    /**
     * For a user scoped to es, the filter narrows within the scope and never
     * past it (#58).
     */
    public function test_the_site_filter_stays_inside_the_scope(): void
    {
        // Sign in as the es reader and name the filtered listing URL
        $this->actingAs($this->spanishReader());
        $url = cp_route('forms.submissions.index', 'msf_host_scoped').'?filters=';

        // List the submissions filtered to es, then to en
        $spanish = $this->getJson($url.base64_encode((string) json_encode(['submission_site' => ['site' => 'es']])))->assertOk();
        $english = $this->getJson($url.base64_encode((string) json_encode(['submission_site' => ['site' => 'en']])))->assertOk();

        // Expect the es submission under es, nothing under en, and the en badge
        $this->assertSame([$this->ids['es']], array_column($spanish->json('data'), 'id'));
        $this->assertSame([], array_column($english->json('data'), 'id'));
        $this->assertSame(['submission_site' => 'Site: en'], $english->json('meta.activeFilterBadges'));
    }
}
