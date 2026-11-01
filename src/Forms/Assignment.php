<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Forms\Multisite\Forms;

use Statamic\Forms\Form;
use Statamic\Sites\Site;
use UAMSWeb\Aether\Forms\Multisite\Sites\RequestSite;

/**
 * Reads which sites a form is assigned to.
 *
 * The assignment is the `sites` key in the form file. A form with no `sites`
 * key, or an empty list, belongs to every site, which is how core treats every
 * form; that keeps a form the add-on never touched behaving exactly as before.
 */
final class Assignment
{
    /** The form-file key that holds the assignment. */
    public const string KEY = 'sites';

    /**
     * The site handles a form lists, in file order, without blanks or repeats.
     *
     * An empty result means the form is assigned to every site.
     *
     * @param  Form  $form  The form whose assignment to read.
     * @return list<string> The assigned site handles; empty for a global form.
     */
    public static function sites(Form $form): array
    {
        // Read the assignment from the form file
        $value = $form->get(self::KEY);

        // A single handle written as a string is one site, not a list of characters
        $handles = is_string($value) ? [$value] : (is_array($value) ? $value : []);

        // Keep only non-empty string handles, once each
        $sites = [];
        foreach ($handles as $handle) {
            // Add the handle if it is a non-empty string not already kept
            if (is_string($handle) && $handle !== '' && ! in_array($handle, $sites, true)) {
                $sites[] = $handle;
            }
        }

        return $sites;
    }

    /**
     * Whether the form lists no sites, and so belongs to every site.
     *
     * @param  Form  $form  The form to check.
     * @return bool True when the form is assigned to every site.
     */
    public static function isGlobal(Form $form): bool
    {
        return self::sites($form) === [];
    }

    /**
     * Whether the form is assigned to a site.
     *
     * @param  Form  $form  The form to check.
     * @param  Site|string  $site  The site, or its handle.
     * @return bool True when the form is global or lists the site.
     */
    public static function covers(Form $form, Site|string $site): bool
    {
        // Read the sites the form lists
        $sites = self::sites($form);

        // Admit any site for a global form, else only a listed one
        return $sites === [] || in_array(RequestSite::handle($site), $sites, true);
    }
}
