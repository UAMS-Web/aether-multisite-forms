<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Forms\Multisite\Http;

use Illuminate\Http\Request;
use Illuminate\Routing\Events\PreparingResponse;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Event;
use Inertia\Response as InertiaResponse;
use Statamic\CP\Breadcrumbs\Breadcrumbs;
use UAMSWeb\Aether\Forms\Multisite\Access\RefusedFormEditors;

/**
 * Keeps forms the user may not open out of the form fields editor's
 * breadcrumb (#124).
 *
 * Core's `FormBlueprintController::edit()` pushes a breadcrumb linking every
 * other form's fields editor, each by title, and Statamic shares the pushed
 * breadcrumbs with the page as `additionalBreadcrumbs`. A user allowed on one
 * form's editor would see the titles of forms assigned to sites they cannot
 * access.
 *
 * Route middleware sees the response only once it is rendered, so this listens
 * for the response being prepared, when the editor's page is still an Inertia
 * response, and replaces the shared breadcrumbs on it with a copy whose links
 * leave out every form whose editor the user may not open. That is the add-on's
 * rule for changing a form, so a link the user sees is one the editor accepts
 * (#116). Core's controller is untouched, and every other response passes
 * through.
 */
final class BlueprintBreadcrumbs
{
    /** The name of the form fields editor's screen route. */
    public const string ROUTE = 'statamic.cp.blueprints.forms.edit';

    /** The page prop Statamic shares the pushed breadcrumbs as. */
    public const string PROP = 'additionalBreadcrumbs';

    /**
     * Listen for responses being prepared.
     */
    public static function register(): void
    {
        Event::listen(PreparingResponse::class, self::preparing(...));
    }

    /**
     * Replace the editor page's breadcrumbs with those the user may open.
     *
     * @param  PreparingResponse  $event  The response being prepared.
     */
    public static function preparing(PreparingResponse $event): void
    {
        // Skip a response that is not an Inertia page for an HTTP request
        $request = $event->request;
        if (! $event->response instanceof InertiaResponse || ! $request instanceof Request) {
            return;
        }

        // Skip every route other than the fields editor's screen
        $route = $request->route();
        if (! $route instanceof Route || $route->getName() !== self::ROUTE) {
            return;
        }

        // Read the breadcrumbs core pushed for the page
        /** @var array<array-key, mixed> $crumbs core's `additional()` returns a list, untyped */
        $crumbs = Breadcrumbs::additional();

        // Share the filtered breadcrumbs in place of core's
        $event->response->with(self::PROP, self::openable($crumbs));
    }

    /**
     * The breadcrumbs, each without links to a fields editor the user may not
     * open.
     *
     * A link to anything other than a form's fields editor is kept.
     *
     * @param  array<array-key, mixed>  $crumbs  The breadcrumbs core pushed.
     * @return list<mixed> The breadcrumbs, with refused links left out.
     */
    public static function openable(array $crumbs): array
    {
        // Find the fields editor paths the user may not open
        $refused = RefusedFormEditors::paths();

        // Copy each breadcrumb, filtering the links of any that has them
        $kept = [];
        foreach ($crumbs as $crumb) {
            // Check whether the breadcrumb carries a list of links
            if (is_array($crumb) && is_iterable($crumb['links'] ?? null)) {
                // Keep only the links the user may open
                $links = [];
                foreach ($crumb['links'] as $link) {
                    if (! RefusedFormEditors::isRefused($link, $refused)) {
                        $links[] = $link;
                    }
                }

                // Replace the breadcrumb's links with the kept ones
                $crumb['links'] = $links;
            }

            // Add the breadcrumb to those kept
            $kept[] = $crumb;
        }

        return $kept;
    }
}
