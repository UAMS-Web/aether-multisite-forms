<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Forms\Multisite\Access;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Statamic\Contracts\Auth\User;
use Statamic\Facades\Site;
use Statamic\Sites\Site as SiteObject;

/**
 * Which sites a Control Panel user can access, through core's own site policy.
 *
 * Access is asked of the gate as `view` on the site, so it follows core's rules: a
 * super user can access every site, and with multisite disabled everyone can. A
 * handle naming no configured site is never accessible, so a form listing a site
 * that has since been removed stays closed rather than opening up.
 */
final class SiteAccess
{
    /**
     * Whether the user can access the site a handle names.
     *
     * @param  User  $user  The Control Panel user to check.
     * @param  string  $handle  The handle of the site to check.
     * @return bool True when the site exists and the user may view it.
     */
    public static function canAccess(User $user, string $handle): bool
    {
        // Find the configured site the handle names
        $site = Site::get($handle);

        // Without the check, `view` on null finds no policy and answers false even for a
        // super user, so InstanceOfToTrue is equivalent; the check keeps that fail-closed
        // answer explicit rather than resting on how the gate treats a null subject.
        // @pest-mutate-ignore: InstanceOfToTrue
        return $site instanceof SiteObject && Gate::forUser($user)->allows('view', $site);
    }

    /**
     * Whether the user can access at least one of the sites.
     *
     * @param  User  $user  The Control Panel user to check.
     * @param  list<string>  $handles  The handles of the sites to check.
     * @return bool True when at least one of the sites is accessible.
     */
    public static function canAccessAny(User $user, array $handles): bool
    {
        return array_any($handles, fn (string $handle): bool => self::canAccess($user, $handle));
    }

    /**
     * Whether the user can access every one of the sites.
     *
     * @param  User  $user  The Control Panel user to check.
     * @param  list<string>  $handles  The handles of the sites to check.
     * @return bool True when every site is accessible, including for no sites.
     */
    public static function canAccessAll(User $user, array $handles): bool
    {
        return array_all($handles, fn (string $handle): bool => self::canAccess($user, $handle));
    }

    /**
     * The handles of the configured sites the user can access, in configured order.
     *
     * @param  User  $user  The Control Panel user to check.
     * @return list<string> The accessible site handles.
     */
    public static function accessible(User $user): array
    {
        return array_values(array_filter(self::allHandles(), fn (string $handle): bool => self::canAccess($user, $handle)));
    }

    /**
     * The handles of every configured site.
     *
     * @return list<string> Every configured site handle, in configured order.
     */
    public static function allHandles(): array
    {
        // The facade declares `mixed`; core's Sites::all() is a collection keyed by handle
        /** @var Collection<string, SiteObject> $sites */
        $sites = Site::all();

        // keys() is already indexed from zero, so UnwrapArrayValues is equivalent;
        // array_values is there for PHPStan, which types Collection::all() as an array
        // rather than a list.
        // @pest-mutate-ignore: UnwrapArrayValues
        return array_values($sites->keys()->all());
    }
}
