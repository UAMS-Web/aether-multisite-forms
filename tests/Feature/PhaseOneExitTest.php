<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Forms\Multisite\Tests\Feature;

use Illuminate\Contracts\View\Factory;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Statamic\Facades\Form;
use Statamic\Facades\Stache;
use Statamic\Facades\YAML;
use Tests\TestCase;

/**
 * Host-bound: Phase 1's exit criterion, end to end in a real two-site host (#67).
 *
 * One form, assigned to `en` only, renders on the `en` page and not on the `es`
 * page, answers 404 to a submit from `es` and stores nothing, and stores a submit
 * from `en` with `_site: en` in the file on disk. Form files, submissions and the
 * page's view go to a temporary directory, so the host's own content is never
 * written.
 *
 * @command  php artisan test vendor/uams-web/aether-multisite-forms/tests/Feature/PhaseOneExitTest.php
 */
final class PhaseOneExitTest extends TestCase
{
    /**
     * The submit action the rendered form posts to, present only when it renders.
     */
    private const string ACTION = '/!/forms/msf_phase_one';

    /**
     * The temporary directory this test's forms, submissions and view live in.
     *
     * @var string Absolute path, unique to this test.
     */
    private string $directory;

    /**
     * Boot the host, point forms, submissions and the page view at a temporary
     * directory, serve the page on each site, and save the `en` form.
     */
    protected function setUp(): void
    {
        // Boot the host application
        parent::setUp();

        // Point form files at a unique temporary directory
        $this->directory = sys_get_temp_dir().'/msf-host-phase-one-'.uniqid('', true);
        config(['statamic.forms.forms' => $this->directory]);
        // Store submissions beneath it too
        Stache::store('form-submissions')->directory($this->directory.'/_submissions');

        // Write the page view that renders the form
        File::ensureDirectoryExists($this->directory.'/views');
        File::put(
            $this->directory.'/views/msf_phase_one.antlers.html',
            '<main id="page">{{ form:create in="msf_phase_one" }}<input name="message">{{ /form:create }}</main>',
        );
        // Let the view finder find it, and serve it on each site's URL
        View::addLocation($this->directory.'/views');
        $this->routePages();

        // Save a form assigned to en only that stores its submissions
        Form::make('msf_phase_one')->title('Phase one')->store(true)->set('sites', ['en'])->save();
    }

    /**
     * Delete the temporary directory and tear the host down.
     */
    protected function tearDown(): void
    {
        // Remove this test's forms, submissions and view
        File::deleteDirectory($this->directory);

        // Tear down the host application
        parent::tearDown();
    }

    /**
     * A form assigned to `en` renders, accepts a submit, and records it only on
     * the `en` site.
     */
    public function test_a_form_assigned_to_en_renders_submits_and_records_only_on_en(): void
    {
        // Expect the form on the en page and an empty page on es
        $this->get('/msf-phase-one')->assertOk()->assertSee(self::ACTION, false);
        $this->get('/es/msf-phase-one')->assertOk()->assertSee('<main id="page"></main>', false);

        // Submit from the es page and expect a 404 with nothing stored
        $this->post(self::ACTION, ['message' => 'es'], ['referer' => url('/es/msf-phase-one')])->assertNotFound();
        $this->assertSame([], $this->storedSites());

        // Submit from the en page and expect one file recorded for en
        $this->post(self::ACTION, ['message' => 'en'], ['referer' => url('/msf-phase-one')])->assertRedirect();
        $this->assertSame(['en'], $this->storedSites());
    }

    /**
     * Serve the page on each site's URL, ahead of Statamic's catch-all frontend
     * route.
     */
    private function routePages(): void
    {
        // Register a page route on each site's URL
        $pages = [
            Route::middleware('web')->get('msf-phase-one', fn (): Factory|\Illuminate\Contracts\View\View => view('msf_phase_one')),
            Route::middleware('web')->get('es/msf-phase-one', fn (): Factory|\Illuminate\Contracts\View\View => view('msf_phase_one')),
        ];

        // Start a new collection with the pages first
        $routes = new RouteCollection;
        foreach ($pages as $page) {
            $routes->add($page);
        }

        // Append every other registered route after them
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! in_array($route, $pages, true)) {
                $routes->add($route);
            }
        }

        // Replace the router's routes with the reordered collection
        Route::setRoutes($routes);
    }

    /**
     * The `_site` of each stored submission file of the form.
     *
     * @return list<string|null> The recorded site of each file, null where absent.
     */
    private function storedSites(): array
    {
        // Find the form's stored submission files
        $files = File::glob($this->directory.'/_submissions/msf_phase_one/*.yaml');

        // Read each file's recorded site
        return array_values(array_map(fn (string $file): mixed => YAML::parse(File::get($file))['_site'] ?? null, $files));
    }
}
