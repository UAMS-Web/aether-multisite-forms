<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as KernelContract;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Statamic\Events\FormSubmitted;
use Statamic\Facades\Form as FormFacade;
use Statamic\Facades\Stache;
use Statamic\Forms\Form;
use Statamic\Stache\Stores\Store;
use UAMSWeb\Aether\Forms\Multisite\Forms\FormResolver;
use UAMSWeb\Aether\Forms\Multisite\Forms\SiteForm;
use UAMSWeb\Aether\Forms\Multisite\Http\Middleware\ResolveSubmittedForm;
use UAMSWeb\Aether\Forms\Multisite\Http\SubmitGate;
use UAMSWeb\Aether\Forms\Multisite\Sites\RequestSite;
use UAMSWeb\Aether\Forms\Multisite\Tests\TestCase;

/**
 * Core's form submit route answers 404 for a form not assigned to the submit
 * site, and submits the site's view of a form that is (#72).
 *
 * @command  vendor/bin/pest tests/Integration/Http/SubmitGateTest.php
 */
beforeEach(function (): void {
    // Configure two sites and a temporary forms directory for this test
    useTwoSites();
    $directory = useTemporaryFormsDirectory();

    // Find the submissions store, refusing if the Stache has none
    $store = Stache::store('form-submissions');
    if (! $store instanceof Store) {
        throw new RuntimeException('The Stache has no form submissions store.');
    }

    // Point the submissions store inside the temporary directory
    $store->directory($directory.'/_submissions');
});

afterEach(function (): void {
    removeFormsDirectory();
});

/**
 * Save a `contact` form that stores submissions, on the given sites or global.
 *
 * @param  list<string>|null  $sites  Site handles to assign, or null for a global form.
 * @return Form The saved form.
 */
function submittableForm(?array $sites): Form
{
    // Build a contact form that stores its submissions
    $form = new Form;
    $form->handle('contact');
    $form->title('Contact');
    $form->store(true);

    // Assign the sites when the form is not global
    if ($sites !== null) {
        $form->set('sites', $sites);
    }

    // Save the form
    $form->save();

    return $form;
}

/**
 * Submit the `contact` form from a page on the given site.
 *
 * @param  TestCase  $case  The running test, which sends the request.
 * @param  string  $page  URL of the page the submit comes from.
 * @return TestResponse<Symfony\Component\HttpFoundation\Response> The submit route's response.
 */
function submitFrom(TestCase $case, string $page): TestResponse
{
    return $case->post('/!/forms/contact', ['message' => 'hello'], ['referer' => $page]);
}

/**
 * How many submissions the `contact` form has stored.
 *
 * @return int The number of stored submissions.
 *
 * @throws RuntimeException When no `contact` form is stored.
 */
function storedSubmissions(): int
{
    // Find the contact form, refusing if it is missing
    $form = FormFacade::find('contact');
    if (! $form instanceof Form) {
        throw new RuntimeException('No [contact] form is stored.');
    }

    // Count its submissions
    return $form->submissions()->count();
}

/**
 * The middleware core's submit route runs, in order, by class name.
 *
 * @return list<string> Middleware class names, parameters stripped.
 *
 * @throws RuntimeException When core registered no submit route.
 */
function submitRouteMiddleware(): array
{
    // Find core's submit route, refusing if it is missing
    $router = app(Router::class);
    $route = $router->getRoutes()->getByName(ResolveSubmittedForm::ROUTE);
    if (! $route instanceof Route) {
        throw new RuntimeException('Core registered no form submit route.');
    }

    // Collect each named middleware, dropping its parameters
    $middleware = [];
    foreach ($router->gatherRouteMiddleware($route) as $entry) {
        // Skip closures and other entries that are not names
        if (is_string($entry)) {
            $middleware[] = explode(':', $entry)[0];
        }
    }

    return $middleware;
}

/**
 * The position of a middleware in the submit route's stack.
 *
 * @param  string  $middleware  Class name of the middleware to find.
 * @return int Zero-based position in the stack.
 *
 * @throws RuntimeException When the route does not run it.
 */
function submitRoutePosition(string $middleware): int
{
    // Find the middleware, refusing if the route does not run it
    $position = array_search($middleware, submitRouteMiddleware(), true);
    if (! is_int($position)) {
        throw new RuntimeException("The submit route does not run [{$middleware}].");
    }

    return $position;
}

/**
 * The middleware in one of the router's groups.
 *
 * @param  string  $group  Name of the middleware group.
 * @return list<mixed> The group's middleware, in order.
 *
 * @throws RuntimeException When the router has no such group.
 */
function routerGroup(string $group): array
{
    // Read the group, refusing if the router does not define it
    $middleware = app(Router::class)->getMiddlewareGroups()[$group] ?? null;
    if (! is_array($middleware)) {
        throw new RuntimeException("The router has no [{$group}] group.");
    }

    return array_values($middleware);
}

/**
 * Collect the warnings logged from here on.
 *
 * @return ArrayObject<int, string> Warning messages, filled as they are logged.
 */
function loggedWarnings(): ArrayObject
{
    // Start an empty collection the listener fills
    /** @var ArrayObject<int, string> $warnings */
    $warnings = new ArrayObject;

    // Listen for log messages and keep the warnings
    Event::listen(MessageLogged::class, function (MessageLogged $event) use ($warnings): void {
        if ($event->level === 'warning') {
            $warnings[] = $event->message;
        }
    });

    return $warnings;
}

/**
 * The application's HTTP kernel, which Testbench builds from the framework's.
 *
 * @return Kernel The framework HTTP kernel.
 */
function httpKernel(): Kernel
{
    return app(KernelContract::class);
}

it('accepts a submit from a site the form is assigned to', function (): void {
    // Save a form assigned to English
    submittableForm(['en']);

    // Submit from an English page and expect the redirect
    submitFrom($this, 'http://localhost/contact')->assertRedirect();

    // Expect the submission stored
    expect(storedSubmissions())->toBe(1);
});

it('answers 404 to a submit from a site the form is not assigned to, and stores nothing', function (): void {
    // Save a form assigned to English
    submittableForm(['en']);

    // Submit from a Spanish page and expect a 404
    submitFrom($this, 'http://localhost/es/contacto')->assertNotFound();

    // Expect nothing stored
    expect(storedSubmissions())->toBe(0);
});

it('accepts a submit to a form with no sites from every site, as core does', function (string $page): void {
    // Save a global form
    submittableForm(null);

    // Submit from the given page and expect the redirect
    submitFrom($this, $page)->assertRedirect();

    // Expect the submission stored
    expect(storedSubmissions())->toBe(1);
})->with([
    'English page' => ['http://localhost/contact'],
    'Spanish page' => ['http://localhost/es/contacto'],
]);

it('hands core the read-only view of the form for the submit site', function (): void {
    // Save a form assigned to both sites
    submittableForm(['en', 'es']);

    // Capture the form core sees when the submission fires
    $submitted = null;
    Event::listen(FormSubmitted::class, function (FormSubmitted $event) use (&$submitted): void {
        $submitted = $event->submission->form();
    });

    // Submit from a Spanish page
    submitFrom($this, 'http://localhost/es/contacto')->assertRedirect();

    // Expect the Spanish site view of the form
    expect($submitted)->toBeInstanceOf(SiteForm::class)
        ->and($submitted instanceof SiteForm ? $submitted->site() : null)->toBe('es');
});

it('runs after route binding and keeps the route forgery check', function (): void {
    expect(submitRoutePosition(ResolveSubmittedForm::class))->toBeGreaterThan(submitRoutePosition(SubstituteBindings::class))
        ->and(submitRouteMiddleware())->toContain(PreventRequestForgery::class);
});

it('stays after route binding even when pushed before it', function (): void {
    // Put the gate at the front of the web group
    app(Router::class)->middlewareGroup('web', [ResolveSubmittedForm::class, ...routerGroup('web')]);

    // Expect the route still to run it after route binding
    expect(submitRoutePosition(ResolveSubmittedForm::class))->toBeGreaterThan(submitRoutePosition(SubstituteBindings::class));
});

it('keeps the gate on the group when the kernel later copies its groups to the router again', function (): void {
    // The kernel's group, priority and alias setters re-copy its groups to the router
    httpKernel()->prependToMiddlewarePriority(SubstituteBindings::class);

    // Expect the web group still to carry the gate
    expect(routerGroup('web'))->toContain(ResolveSubmittedForm::class);
});

it('keeps middleware other code pushed onto the group through the router alone', function (): void {
    // Push a middleware and a priority entry through the router only
    $router = app(Router::class);
    $router->pushMiddlewareToGroup('web', 'msf-pushed-by-the-host');
    $router->middlewarePriority = [...$router->middlewarePriority, 'msf-priority-from-the-host'];

    // Register the gate again
    SubmitGate::register($router, httpKernel());

    // Expect the host's entries kept beside the gate on router and kernel
    expect(routerGroup('web'))->toContain('msf-pushed-by-the-host')
        ->and(routerGroup('web'))->toContain(ResolveSubmittedForm::class)
        ->and(httpKernel()->getMiddlewareGroups()['web'] ?? [])->toContain('msf-pushed-by-the-host')
        ->and($router->middlewarePriority)->toContain('msf-priority-from-the-host');
});

it('warns when the front-end route middleware names no group, so nothing is gated', function (): void {
    // Collect warnings and name a group that does not exist
    $warnings = loggedWarnings();
    config(['statamic.routes.middleware' => ['not-a-group']]);

    // Register the gate
    SubmitGate::register(app(Router::class), httpKernel());

    // Expect one warning that the gate could not attach
    expect($warnings)->toHaveCount(1)
        ->and($warnings[0] ?? '')->toContain('could not gate form submits');
});

it('attaches to a group the router defines but the kernel does not', function (): void {
    // Collect warnings and define a group on the router only
    $warnings = loggedWarnings();
    app(Router::class)->middlewareGroup('msf-router-only', []);
    config(['statamic.routes.middleware' => ['msf-router-only']]);

    // Register the gate
    SubmitGate::register(app(Router::class), httpKernel());

    // Expect the gate on that group and no warning
    expect(routerGroup('msf-router-only'))->toBe([ResolveSubmittedForm::class])
        ->and($warnings)->toHaveCount(0);
});

it('attaches through the router under a kernel that is not the framework one', function (): void {
    // Collect warnings and define a plain group for the front end
    $warnings = loggedWarnings();
    app(Router::class)->middlewareGroup('msf-plain', []);
    config(['statamic.routes.middleware' => ['msf-plain']]);

    // Build a kernel that is not the framework one
    $kernel = new class implements KernelContract
    {
        public function bootstrap(): void {}

        public function handle($request): Response
        {
            return new Response;
        }

        public function terminate($request, $response): void {}

        public function getApplication(): Application
        {
            return app();
        }
    };

    // Register the gate under that kernel
    SubmitGate::register(app(Router::class), $kernel);

    // Expect the gate on the group and no warning
    expect(routerGroup('msf-plain'))->toBe([ResolveSubmittedForm::class])
        ->and($warnings)->toHaveCount(0);
});

it('skips an entry that is not a group name and still attaches to the groups after it', function (): void {
    // Collect warnings and list a non-name before a real group
    $warnings = loggedWarnings();
    app(Router::class)->middlewareGroup('msf-after', []);
    config(['statamic.routes.middleware' => [123, 'msf-after']]);

    // Register the gate
    SubmitGate::register(app(Router::class), httpKernel());

    // Expect the gate on the later group and no warning
    expect(routerGroup('msf-after'))->toBe([ResolveSubmittedForm::class])
        ->and($warnings)->toHaveCount(0);
});

it('does not warn when it attaches to a group', function (): void {
    // Collect warnings
    $warnings = loggedWarnings();

    // Register the gate on the default groups
    SubmitGate::register(app(Router::class), httpKernel());

    // Expect no warning
    expect($warnings)->toHaveCount(0);
});

it('passes a request with no route through', function (): void {
    // Run the gate on a request that has no route
    $response = new ResolveSubmittedForm(new FormResolver, new RequestSite)
        ->handle(Request::create('http://localhost/es/'), fn (): Response => new Response('ok'));

    // Expect the next handler's response
    expect($response->getContent())->toBe('ok');
});

it('passes the submit route through, with a warning, when its form was never bound', function (): void {
    // Collect warnings and the context of the last log message
    $warnings = loggedWarnings();
    $context = null;
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$context): void {
        $context = $event->context;
    });

    // Save a form and build a submit request whose form stays a handle
    submittableForm(['en']);
    $request = Request::create('http://localhost/!/forms/contact', 'POST', server: ['HTTP_REFERER' => 'http://localhost/es/']);
    $route = new Route('POST', '!/forms/{form}', fn (): string => '');
    $route->name(ResolveSubmittedForm::ROUTE)->bind($request);
    $request->setRouteResolver(fn (): Route => $route);

    // Run the gate on that request
    $response = new ResolveSubmittedForm(new FormResolver, new RequestSite)
        ->handle($request, fn (): Response => new Response('ok'));

    // Expect it passed through untouched, with one warning naming the form
    expect($response->getContent())->toBe('ok')
        ->and($route->parameter('form'))->toBe('contact')
        ->and($warnings)->toHaveCount(1)
        ->and($warnings[0] ?? '')->toContain('the form was not bound')
        ->and($context)->toBe(['form' => 'contact']);
});

it('passes any other route through without touching its form parameter', function (): void {
    // Build a request on another route that carries a bound form
    $form = submittableForm(['en']);
    $request = Request::create('http://localhost/es/elsewhere');
    $route = new Route('GET', 'elsewhere', fn (): string => '');
    $route->name('elsewhere')->bind($request);
    $route->setParameter('form', $form);
    $request->setRouteResolver(fn (): Route => $route);

    // Run the gate on that request
    new ResolveSubmittedForm(new FormResolver, new RequestSite)
        ->handle($request, fn (): Response => new Response('ok'));

    // Expect the form parameter left as it was
    expect($route->parameter('form'))->toBe($form);
});

it("stores submissions in this test's own directory, never in shared storage", function (): void {
    // Save a global form and submit it
    submittableForm(null);
    submitFrom($this, 'http://localhost/contact')->assertRedirect();

    // Expect the submissions directory inside this test's forms directory
    expect(File::isDirectory(formsDirectory().'/_submissions'))->toBeTrue();
});
