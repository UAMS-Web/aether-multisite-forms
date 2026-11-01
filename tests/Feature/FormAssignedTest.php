<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Forms\Multisite\Tests\Feature;

use Illuminate\Contracts\View\Factory;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Statamic\Facades\Form;
use Tests\TestCase;

/**
 * Host-bound: an Antlers page and a Blade page on each site of a real two-site
 * host branch on `form:assigned`, so content around a form shows only where
 * the form renders (#60).
 *
 * Each page is a view served by a route on each site's URL, rendered through the
 * host's own kernel and view finder. Form files and the views go to a temporary
 * directory, so the host's own content is never written.
 *
 * @command  php artisan test vendor/uams-web/aether-multisite-forms/tests/Feature/FormAssignedTest.php
 */
final class FormAssignedTest extends TestCase
{
    /**
     * The Antlers probe view, using `form:assigned` as a condition and as a pair.
     */
    private const string ANTLERS = '<main id="probe">{{ if {form:assigned in="msf_host_assigned"} }}<h2>Contact us</h2>{{ /if }}'
        .'{{ form:assigned in="msf_host_assigned" }}<p>Intro</p>{{ /form:assigned }}</main>';

    /**
     * The Blade probe view, using `form:assigned` as a condition and as a component.
     */
    private const string BLADE = '<main id="probe">@if (Statamic::tag(\'form:assigned\')->params([\'in\' => \'msf_host_assigned\'])->fetch())<h2>Contact us</h2>@endif'
        .'<s:form:assigned in="msf_host_assigned"><p>Intro</p></s:form:assigned></main>';

    /**
     * The probe's markup when the form is assigned to the site.
     */
    private const string SHOWN = '<main id="probe"><h2>Contact us</h2><p>Intro</p></main>';

    /**
     * The probe's markup when the form is not assigned to the site.
     */
    private const string HIDDEN = '<main id="probe"></main>';

    /**
     * The temporary directory this test's forms and views live in.
     *
     * @var string Absolute path, unique to this test.
     */
    private string $directory;

    /**
     * Boot the host, point forms at a temporary directory, and serve both probe
     * views on each site.
     */
    protected function setUp(): void
    {
        // Boot the host application
        parent::setUp();

        // Point form files at a unique temporary directory
        $this->directory = sys_get_temp_dir().'/msf-host-assigned-'.uniqid('', true);
        config(['statamic.forms.forms' => $this->directory]);

        // Write the Antlers and Blade probe views
        File::ensureDirectoryExists($this->directory.'/views');
        File::put($this->directory.'/views/msf_assigned_antlers.antlers.html', self::ANTLERS);
        File::put($this->directory.'/views/msf_assigned_blade.blade.php', self::BLADE);
        // Let the view finder find them
        View::addLocation($this->directory.'/views');

        // Serve the probes on each site's URL
        $this->routeProbePages();
    }

    /**
     * Delete the temporary directory and tear the host down.
     */
    protected function tearDown(): void
    {
        // Remove this test's forms and views
        File::deleteDirectory($this->directory);

        // Tear down the host application
        parent::tearDown();
    }

    /**
     * A form assigned to `en` shows the content around it on `en` and hides it
     * on `es`, in both languages.
     */
    public function test_content_shows_only_on_the_assigned_site(): void
    {
        // Save a form assigned to en
        $this->makeForm(['en']);

        // Check the Antlers and the Blade probe
        foreach (['antlers', 'blade'] as $language) {
            // Expect the content shown on en and hidden on es
            $this->get("/msf-assigned-{$language}")->assertOk()->assertSee(self::SHOWN, false);
            $this->get("/es/msf-assigned-{$language}")->assertOk()->assertSee(self::HIDDEN, false);
        }
    }

    /** A form with no sites shows the content on each site. */
    public function test_content_shows_on_each_site_for_a_global_form(): void
    {
        // Save a form with no sites
        $this->makeForm(null);

        // Check the Antlers and the Blade probe
        foreach (['antlers', 'blade'] as $language) {
            // Expect the content shown on both sites
            $this->get("/msf-assigned-{$language}")->assertOk()->assertSee(self::SHOWN, false);
            $this->get("/es/msf-assigned-{$language}")->assertOk()->assertSee(self::SHOWN, false);
        }
    }

    /** Under `404` the check still answers rather than aborting the page. */
    public function test_the_check_does_not_abort_under_404(): void
    {
        // Answer an unassigned form with a 404, and save a form assigned to en
        config(['aether-multisite-forms.not_assigned' => '404']);
        $this->makeForm(['en']);

        // Expect the es page to render with the content hidden
        $this->get('/es/msf-assigned-antlers')->assertOk()->assertSee(self::HIDDEN, false);
    }

    /**
     * Serve the probe views on each site's URL, ahead of Statamic's catch-all
     * frontend route, which would otherwise match first.
     */
    private function routeProbePages(): void
    {
        // Register a route for each probe view on each site's URL
        $probes = [];
        foreach (['antlers', 'blade'] as $language) {
            foreach (['', 'es/'] as $prefix) {
                $probes[] = Route::middleware('web')->get(
                    "{$prefix}msf-assigned-{$language}",
                    fn (): Factory|\Illuminate\Contracts\View\View => view("msf_assigned_{$language}"),
                );
            }
        }

        // Start a new collection with the probes first
        $routes = new RouteCollection;
        foreach ($probes as $probe) {
            $routes->add($probe);
        }

        // Append every other registered route after them
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! in_array($route, $probes, true)) {
                $routes->add($route);
            }
        }

        // Replace the router's routes with the reordered collection
        Route::setRoutes($routes);
    }

    /**
     * Save the `msf_host_assigned` form, assigned to the given sites.
     *
     * @param  list<string>|null  $sites  Handles to assign, or null for no `sites` key.
     */
    private function makeForm(?array $sites): void
    {
        // Make the form
        $form = Form::make('msf_host_assigned')->title('Assigned');
        // Assign the sites only when some were given
        if ($sites !== null) {
            $form->set('sites', $sites);
        }

        // Save the form
        $form->save();
    }
}
