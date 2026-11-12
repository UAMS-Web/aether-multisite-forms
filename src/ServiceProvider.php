<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Forms\Multisite;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Statamic\Events\FormCreating;
use Statamic\Events\FormSubmitted;
use Statamic\Events\SubmissionCreating;
use Statamic\Providers\AddonServiceProvider;
use UAMSWeb\Aether\Forms\Multisite\Access\CreatorSites;
use UAMSWeb\Aether\Forms\Multisite\Access\FormGate;
use UAMSWeb\Aether\Forms\Multisite\Access\SubmissionScope;
use UAMSWeb\Aether\Forms\Multisite\Api\FormsController;
use UAMSWeb\Aether\Forms\Multisite\Forms\ConfigFields;
use UAMSWeb\Aether\Forms\Multisite\Forms\EmailRecordedSite;
use UAMSWeb\Aether\Forms\Multisite\Forms\NotAssigned;
use UAMSWeb\Aether\Forms\Multisite\Forms\RecordSubmitSite;
use UAMSWeb\Aether\Forms\Multisite\GraphQL\FormsQuery;
use UAMSWeb\Aether\Forms\Multisite\Http\BlueprintBreadcrumbs;
use UAMSWeb\Aether\Forms\Multisite\Http\BlueprintListings;
use UAMSWeb\Aether\Forms\Multisite\Http\Middleware\AuthorizeFormBlueprint;
use UAMSWeb\Aether\Forms\Multisite\Http\SubmitGate;

/**
 * Service provider for the Multisite Forms add-on.
 *
 * The host application discovers it through `extra.laravel.providers` in
 * `composer.json`. It registers the Control Panel build, the Sites section of
 * the form settings screen, the site rules on form and submission access in
 * the Control Panel and on its form fields editor, that editor's breadcrumb, the
 * blueprints index and the command palette, the site filter on the REST and
 * GraphQL form listings, the gate on core's form submit route, the listener that
 * records a submission's site, the bus pipe that emails for it, and the one that assigns a new form to its
 * creator's sites. Its actions, such as the Duplicate
 * action that replaces core's, are registered from `src/Actions` by Statamic;
 * the rest is added as the phases in the add-on's specification land.
 */
class ServiceProvider extends AddonServiceProvider
{
    // tests/Unit/ViteBuildTest.php fails when any entry of $vite is removed, shown by
    // hand for each one (#82), but a property default is not an executable line, so
    // the coverage driver links no test to these mutants and they are never run.
    // @pest-mutate-ignore: RemoveArrayItem
    /**
     * The Vite build of the Control Panel assets. `input` and `publicDirectory`
     * must match `vite.config.js`; the built files are committed under
     * `resources/dist`.
     *
     * @var array{input: list<string>, publicDirectory: string}
     */
    protected $vite = [
        'input' => [
            'resources/js/cp.js',
            'resources/css/cp.css',
        ],
        'publicDirectory' => 'resources/dist',
    ];

    /**
     * Boot the add-on once Statamic has booted.
     */
    public function bootAddon(): void
    {
        // Report an invalid add-on configuration early
        NotAssigned::reportInvalidConfig();

        // Add the Sites section to the form settings screen
        ConfigFields::register();

        // Apply the site rules to form access and submission reads
        FormGate::register();
        SubmissionScope::register($this->app);

        // Filter the REST and GraphQL form listings by site
        FormsController::register($this->app);
        FormsQuery::register($this->app);

        // Guard the form fields editor and the places that link to it
        AuthorizeFormBlueprint::register($this->app->make(Router::class));
        BlueprintBreadcrumbs::register();
        BlueprintListings::register();

        // Gate core's form submit route
        SubmitGate::register($this->app->make(Router::class), $this->app->make(Kernel::class));

        // Record the site a submission was made on
        Event::listen(FormSubmitted::class, [RecordSubmitSite::class, 'submitted']);
        Event::listen(SubmissionCreating::class, [RecordSubmitSite::class, 'creating']);

        // Send a submission's email for the site it was recorded under
        EmailRecordedSite::register($this->app->make(Dispatcher::class));

        // Assign a new form to its creator's sites
        Event::listen(FormCreating::class, CreatorSites::creating(...));
    }
}
