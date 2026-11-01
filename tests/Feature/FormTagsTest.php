<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Forms\Multisite\Tests\Feature;

use Illuminate\Contracts\View\Factory;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Statamic\Facades\Form;
use Tests\TestCase;

/**
 * Host-bound: a page on each site of a real two-site host renders the `{{ form }}`
 * tags for a form assigned to that site, and follows `not_assigned` for one that
 * is not (#73).
 *
 * The page is an Antlers view served by a route on each site's URL, rendered
 * through the host's own kernel, view finder and exception handler, which is what
 * turns a 404 thrown from inside a tag into a 404 response rather than a view
 * error. Form files and the view go to a temporary directory, so the host's own
 * content is never written.
 *
 * @command  php artisan test vendor/uams-web/aether-multisite-forms/tests/Feature/FormTagsTest.php
 */
final class FormTagsTest extends TestCase
{
    /**
     * The submit action the rendered form posts to, present only when it renders.
     */
    private const string ACTION = '/!/forms/msf_host_tags';

    /**
     * The temporary directory this test's forms and views live in.
     *
     * @var string Absolute path, unique to this test.
     */
    private string $directory;

    /**
     * Boot the host, point forms at a temporary directory, and serve the probe
     * view on each site.
     */
    protected function setUp(): void
    {
        // Boot the host application
        parent::setUp();

        // Point form files at a unique temporary directory
        $this->directory = sys_get_temp_dir().'/msf-host-tags-'.uniqid('', true);
        config(['statamic.forms.forms' => $this->directory]);

        // Write the probe view that renders the form tags
        File::ensureDirectoryExists($this->directory.'/views');
        File::put(
            $this->directory.'/views/msf_form_probe.antlers.html',
            '<main id="probe">{{ form:create in="msf_host_tags" }}<input name="message">{{ /form:create }}'
            .'{{ form:submission in="msf_host_tags" }}<p>Thanks</p>{{ /form:submission }}</main>',
        );
        // Let the view finder find it
        View::addLocation($this->directory.'/views');

        // Serve the probe on each site's URL
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

    /** A form assigned to `en` renders on the `en` page as core renders it. */
    public function test_an_assigned_form_renders_on_its_site(): void
    {
        // Save a form assigned to en
        $this->makeForm(['en']);

        // Expect the en page to render the form
        $this->get('/msf-form-probe')->assertOk()->assertSee(self::ACTION, false);
    }

    /**
     * The same form renders nothing on the `es` page under `empty`, and logs one
     * warning.
     */
    public function test_an_unassigned_form_renders_nothing_under_empty(): void
    {
        // Save a form assigned to en
        $this->makeForm(['en']);
        // Collect the context of every warning logged
        $warnings = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$warnings): void {
            if ($event->level === 'warning') {
                $warnings[] = $event->context;
            }
        });

        // Expect the es page to render without the form
        $this->get('/es/msf-form-probe')
            ->assertOk()
            ->assertSee('<main id="probe"></main>', false)
            ->assertDontSee(self::ACTION, false);

        // Expect one warning naming the form and the site
        $this->assertSame([['form' => 'msf_host_tags', 'site' => 'es']], $warnings);
    }

    /**
     * Under `404`, the `es` page answers with the site's 404 page in its layout,
     * not a view error.
     */
    public function test_an_unassigned_form_answers_404_under_404(): void
    {
        // Answer an unassigned form with a 404
        config(['aether-multisite-forms.not_assigned' => '404']);
        $this->withoutVite();
        // Save a form assigned to en
        $this->makeForm(['en']);

        // Expect the es page to be the site's full 404 page
        $this->get('/es/msf-form-probe')
            ->assertNotFound()
            ->assertSee('<!doctype html>', false)
            ->assertSee('404 Page not found.');
        // Expect the en page to still render the form
        $this->get('/msf-form-probe')->assertOk()->assertSee(self::ACTION, false);
    }

    /**
     * An error layout that renders the same form, as a shared layout would, still
     * gives the 404 page rather than aborting again from inside it.
     */
    public function test_a_404_layout_that_renders_the_form_does_not_abort_again(): void
    {
        // Answer an unassigned form with a 404
        config(['aether-multisite-forms.not_assigned' => '404']);
        // Write an error layout that renders the same form
        File::ensureDirectoryExists($this->directory.'/views/errors');
        File::put(
            $this->directory.'/views/errors/layout.antlers.html',
            '<div id="error-layout">{{ form:create in="msf_host_tags" }}{{ /form:create }}{{ template_content }}</div>',
        );
        // Save a form assigned to en
        $this->makeForm(['en']);

        // Expect the 404 page in that layout, with the form left out
        $this->get('/es/msf-form-probe')
            ->assertNotFound()
            ->assertSee('<div id="error-layout">', false)
            ->assertSee('404 Page not found.')
            ->assertDontSee(self::ACTION, false);
    }

    /** A form with no sites renders on each site, as core does. */
    public function test_a_global_form_renders_on_each_site(): void
    {
        // Save a form with no sites
        $this->makeForm(null);

        // Expect both pages to render the form
        $this->get('/msf-form-probe')->assertOk()->assertSee(self::ACTION, false);
        $this->get('/es/msf-form-probe')->assertOk()->assertSee(self::ACTION, false);
    }

    /**
     * Serve the probe view on each site's URL, ahead of Statamic's catch-all
     * frontend route, which would otherwise match first.
     */
    private function routeProbePages(): void
    {
        // Register a probe route on each site's URL
        $probes = [
            Route::middleware('web')->get('msf-form-probe', fn (): Factory|\Illuminate\Contracts\View\View => view('msf_form_probe')),
            Route::middleware('web')->get('es/msf-form-probe', fn (): Factory|\Illuminate\Contracts\View\View => view('msf_form_probe')),
        ];

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
     * Save the `msf_host_tags` form, assigned to the given sites.
     *
     * @param  list<string>|null  $sites  Handles to assign, or null for no `sites` key.
     */
    private function makeForm(?array $sites): void
    {
        // Make the form
        $form = Form::make('msf_host_tags')->title('Tags');
        // Assign the sites only when some were given
        if ($sites !== null) {
            $form->set('sites', $sites);
        }

        // Save the form
        $form->save();
    }
}
