<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Forms\Multisite\Access;

use Illuminate\Support\Facades\Gate;
use Statamic\Facades\Form as FormFacade;
use Statamic\Forms\Form;

/**
 * The form fields editors the current user may not open (#124, #133).
 *
 * Core lists every form's fields editor in places that know nothing of sites: the
 * editor's breadcrumb, the blueprints index and the command palette. Each of them
 * drops the links to the editors named here, so a user sees no title of a form
 * whose editor the add-on's rule for changing a form refuses them (#116).
 *
 * Links are compared by their path alone. Core builds them as absolute URLs on
 * the request's scheme and host, and caches the command palette per user without
 * either, so a palette built on one of a multisite's domains is served on the
 * others with links that name the first.
 */
final class RefusedFormEditors
{
    /**
     * The paths of the refused editors, built as core builds its links to them.
     *
     * @return list<string> The URL paths of the editors the current user is refused.
     */
    public static function paths(): array
    {
        // Collect the editor path of each form the user may not change
        $refused = [];
        /** @var Form $form */
        foreach (FormFacade::all() as $form) {
            // Build the path of the form's fields editor
            $path = self::pathOf(cp_route('blueprints.forms.edit', $form->handle()));

            // Keep the path when the gate refuses the editor
            if ($path !== null && Gate::denies('configure form fields', [$form])) {
                $refused[] = $path;
            }
        }

        return $refused;
    }

    /**
     * Whether a link (an array with its URL under a key) points at a refused editor.
     *
     * @param  mixed  $link  The link to check, expected to be an array.
     * @param  list<string>  $refused  The refused editor paths, from paths().
     * @param  string  $key  The key under which the link holds its URL.
     * @return bool True when the link's URL path is one of the refused paths.
     */
    public static function isRefused(mixed $link, array $refused, string $key = 'url'): bool
    {
        return is_array($link) && in_array(self::pathOf($link[$key] ?? null), $refused, true);
    }

    /**
     * A URL's path, or null for anything that is not a URL with one.
     *
     * @param  mixed  $url  The value to read a path from.
     * @return string|null The URL's path, or null when there is none.
     */
    private static function pathOf(mixed $url): ?string
    {
        // Parse the path out of a string URL
        $path = is_string($url) ? parse_url($url, PHP_URL_PATH) : null;

        // Return the path only when parsing found one
        return is_string($path) ? $path : null;
    }
}
