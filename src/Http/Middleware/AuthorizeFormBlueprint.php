<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Forms\Multisite\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Statamic\Facades\Form as FormFacade;
use Statamic\Forms\Form;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses core's form blueprint editor for a form the user may not change (#116).
 *
 * Core's `FormBlueprintController` authorizes `configure form fields` without
 * naming the form, so the add-on's site rules on form access (#77) never see
 * which form is asked for. This middleware is appended to Statamic's
 * authenticated Control Panel group and, on the editor's screen and save routes
 * only, authorizes the same ability with the form, which the add-on's gate
 * answers with the rule for changing a form: every site it is assigned to, or
 * every site for a form with no sites. Every other route passes through.
 */
final class AuthorizeFormBlueprint
{
    /** The Control Panel middleware group the middleware is appended to. */
    public const string GROUP = 'statamic.cp.authenticated';

    // FormBlueprintGateTest fails when either name is removed, shown by hand (#116),
    // but a constant is not an executable line, so the coverage driver links no test
    // to these mutants and they are never run.
    // @pest-mutate-ignore: RemoveArrayItem
    /** The names of the blueprint editor's routes for a form. */
    public const array ROUTES = [
        'statamic.cp.blueprints.forms.edit',
        'statamic.cp.blueprints.forms.update',
    ];

    /**
     * Append the middleware to Statamic's authenticated Control Panel group.
     *
     * @param  Router  $router  The router that holds the middleware group.
     */
    public static function register(Router $router): void
    {
        $router->pushMiddlewareToGroup(self::GROUP, self::class);
    }

    /**
     * Authorize `configure form fields` with the form on the blueprint
     * editor's routes.
     *
     * @param  Request  $request  The incoming request.
     * @param  Closure(Request): Response  $next  The rest of the pipeline.
     * @return Response The response from the rest of the pipeline.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check whether the request is for one of the blueprint editor's routes
        $route = $request->route();
        if ($route instanceof Route && in_array($route->getName(), self::ROUTES, true)) {
            // Find the form when the route names it by handle
            $form = $route->parameter('form');
            if (is_string($form)) {
                $form = FormFacade::find($form);
            }

            // A missing form is left to core, which answers it as it always has
            if ($form instanceof Form) {
                Gate::authorize('configure form fields', [$form]);
            }
        }

        // Continue to the rest of the pipeline
        return $next($request);
    }
}
