<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Forms\Multisite\Tests;

use Illuminate\Foundation\Application;
use Statamic\Testing\AddonTestCase;
use UAMSWeb\Aether\Forms\Multisite\ServiceProvider;

use function Orchestra\Testbench\default_skeleton_path;

/**
 * Base class for the Integration suite: boots a minimal Statamic through
 * Orchestra Testbench with this add-on's provider registered.
 */
abstract class TestCase extends AddonTestCase
{
    /**
     * The add-on provider Statamic's test case registers before each test.
     *
     * @var string Class name of this add-on's service provider.
     */
    protected string $addonServiceProvider = ServiceProvider::class;

    /**
     * Give each parallel worker its own compiled-view directory before the
     * application boots (#216).
     *
     * The service manifest needs no override here: the patched Testbench names it
     * per worker (`uams-web/patches-orchestra-testbench-core`, #253).
     */
    protected function setUp(): void
    {
        // Read the parallel worker token, if this is a parallel run
        $token = $_SERVER['TEST_TOKEN'] ?? null;

        // Name a compiled-view directory for this worker when there is a token (#216)
        $views = self::compiledViewPath($token);
        if ($views !== null) {
            $_SERVER['VIEW_COMPILED_PATH'] = $_ENV['VIEW_COMPILED_PATH'] = $views;
        }

        // Boot the application
        parent::setUp();
    }

    /**
     * Give each parallel worker its own license-check cache, after Statamic's
     * test case sets the shared one.
     *
     * Every authenticated Control Panel page asks Statamic's license check, which
     * takes a file lock in the `outpost` cache store. Statamic's `AddonTestCase`
     * points that store at one directory for every worker, so on Windows a worker
     * opening the lock file while another holds it fails with "Permission denied",
     * failing whichever test was rendering a page (#126). A directory per worker
     * keeps the lock and the cached response apart, and still keeps the response
     * between tests, so the check does not reach the network on every test.
     *
     * Statamic sets the store here rather than in `defineEnvironment()`, which runs
     * first, so the override must follow the parent call.
     *
     * @param  Application  $app  The application being configured.
     */
    #[\Override]
    protected function getEnvironmentSetUp($app): void
    {
        // Let Statamic's test case configure the environment first
        parent::getEnvironmentSetUp($app);

        // Point the license-check cache at this worker's directory, if any
        $path = self::outpostCachePath($_SERVER['TEST_TOKEN'] ?? null);
        if ($path !== null) {
            $app['config']->set('cache.stores.outpost.path', $path);
        }
    }

    /**
     * The compiled-view directory for a parallel worker token, or null outside a
     * parallel run.
     *
     * Every worker boots the same Testbench skeleton, so all of them compile Blade
     * views into one `storage/framework/views`. Compiling writes a temporary file
     * and renames it over the compiled view; on Windows that rename fails with
     * "Access is denied" while another worker has the target open, failing whichever
     * test was rendering the view (#216).
     *
     * This is an absolute path built from the skeleton rather than `storage_path()`,
     * because `setUp()` needs it as `VIEW_COMPILED_PATH` before any application
     * exists: the Blade compiler is built before `getEnvironmentSetUp()` runs, so a
     * config override there is too late.
     *
     * @param  mixed  $token  The parallel worker token, or null outside a parallel run.
     * @return ?string The worker's compiled-view directory, or null without a usable token.
     */
    public static function compiledViewPath(mixed $token): ?string
    {
        return is_string($token) || is_int($token) ? default_skeleton_path('storage/framework')."/views-{$token}" : null;
    }

    /**
     * The license-check cache directory for a parallel worker token, or null
     * outside a parallel run.
     *
     * @param  mixed  $token  The parallel worker token, or null outside a parallel run.
     * @return ?string The worker's cache directory, or null without a usable token.
     */
    public static function outpostCachePath(mixed $token): ?string
    {
        return is_string($token) || is_int($token) ? storage_path("framework/cache/outpost-data-{$token}") : null;
    }

    /**
     * Give the application an encryption key, which a Control Panel request
     * needs for its session, and register the REST and GraphQL routes.
     *
     * @param  Application  $app  The application being configured.
     */
    protected function defineEnvironment($app): void
    {
        // Set an encryption key for the session
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));

        // Statamic registers the REST and GraphQL routes while booting, and only when
        // enabled, so they are enabled here; which resources they expose, and the Pro
        // edition they require, are read per request and left to each test.
        $app['config']->set('statamic.api.enabled', true);
        $app['config']->set('statamic.graphql.enabled', true);
    }
}
