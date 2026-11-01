<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Forms\Multisite\Http;

use Illuminate\Contracts\Http\Kernel as KernelContract;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use UAMSWeb\Aether\Forms\Multisite\Http\Middleware\ResolveSubmittedForm;

/**
 * Attaches the submit gate to core's front-end routes.
 *
 * Core loads its front-end routes, the form submit route among them, under the
 * middleware named by `statamic.routes.middleware` (`web` by default). The gate
 * is appended to each of those that is a middleware group, so it travels with
 * the route whether or not routes are cached. It is listed after
 * `SubstituteBindings` in the priority list, so it runs after binding even if
 * something places it earlier in the group; where a host's priority list leaves
 * `SubstituteBindings` out, it lands last in that list and its place in the
 * group decides, which is after the group's own `SubstituteBindings`.
 *
 * A group the HTTP kernel owns is changed through the kernel. The kernel's
 * group, priority and alias setters each copy the kernel's groups and priority
 * onto the router, replacing the router's; so the router's current groups and
 * priority are copied into the kernel first. Middleware other code pushed onto
 * the router alone is then kept, and a later kernel setter keeps the gate.
 */
final class SubmitGate
{
    /**
     * Append the submit gate to each middleware group core's front-end routes
     * run under, and order it after `SubstituteBindings`.
     *
     * @param  Router  $router  The router that holds groups the kernel does not.
     * @param  KernelContract  $kernel  The HTTP kernel that may own the groups.
     */
    public static function register(Router $router, KernelContract $kernel): void
    {
        // Sync the kernel with the router, then rank the gate after binding
        if ($kernel instanceof Kernel) {
            self::adoptRouterState($router, $kernel);
            $kernel->addToMiddlewarePriorityAfter(SubstituteBindings::class, ResolveSubmittedForm::class);
        }

        // Append the gate to each configured group that exists
        $attached = false;
        foreach (Arr::wrap(config('statamic.routes.middleware', 'web')) as $group) {
            // Skip a configured entry that is not a group name
            if (! is_string($group)) {
                continue;
            }

            // Append through the kernel when it owns the group, else the router
            if ($kernel instanceof Kernel && array_key_exists($group, $kernel->getMiddlewareGroups())) {
                $kernel->appendMiddlewareToGroup($group, ResolveSubmittedForm::class);
                $attached = true;
            } elseif ($router->hasMiddlewareGroup($group)) {
                $router->pushMiddlewareToGroup($group, ResolveSubmittedForm::class);
                $attached = true;
            }
        }

        // Warn when no group took the gate, so submits stay ungated
        if (! $attached) {
            Log::warning('Multisite Forms could not gate form submits by site: statamic.routes.middleware names no middleware group, so forms accept submits from every site.');
        }
    }

    /**
     * Copy the router's version of each kernel-owned group, and the router's
     * priority list, into the kernel, so the kernel's next copy to the router
     * writes back what is already there.
     *
     * @param  Router  $router  The router whose groups and priority are copied.
     * @param  Kernel  $kernel  The kernel that receives them.
     */
    private static function adoptRouterState(Router $router, Kernel $kernel): void
    {
        // Read both before either setter runs: each one copies the kernel's state
        // onto the router, which would overwrite what is still to be read.
        $routerGroups = $router->getMiddlewareGroups();
        $routerPriority = $router->middlewarePriority;

        // Prefer the router's version of each group the kernel owns
        $groups = [];
        foreach ($kernel->getMiddlewareGroups() as $name => $middleware) {
            $groups[$name] = $routerGroups[$name] ?? $middleware;
        }

        // Write the groups and the priority list back into the kernel
        $kernel->setMiddlewareGroups($groups);
        $kernel->setMiddlewarePriority($routerPriority);
    }
}
