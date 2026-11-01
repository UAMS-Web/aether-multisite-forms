<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Forms\Multisite\Forms;

use Statamic\Forms\Form;

/**
 * Reads a form's per-site overrides and applies a site's to its resolved view.
 *
 * The overrides are the `site_overrides` key in the form file: a list of rows,
 * each naming a `site`, which is the shape a grid field saves. A site's row may
 * set `title`, `email` and `store`; a value it leaves out falls back to the base
 * form's. Any other key in a row is ignored, never an error.
 */
final class Overrides
{
    /** The form-file key that holds the rows. */
    public const string KEY = 'site_overrides';

    /** The `store` value the editor saves for "Store". */
    public const string STORE = 'true';

    /** The `store` value the editor saves for "Don't store". */
    public const string DO_NOT_STORE = 'false';

    /**
     * The first row for a site, or null when the form has none for it.
     *
     * Rows that are not lists of values, or that name no site, are skipped. A
     * second row for the same site is a validation error in the editor, so a
     * form that has one anyway resolves from the first.
     *
     * @param  Form  $form  The form whose rows to read.
     * @param  string  $site  The handle of the site to find a row for.
     * @return array<array-key, mixed>|null The site's row, or null when it has none.
     */
    public static function rowFor(Form $form, string $site): ?array
    {
        // Read the rows from the form file
        $rows = $form->get(self::KEY);

        // Check whether the form has a list of rows at all
        if (! is_array($rows)) {
            return null;
        }

        // Find the first row that names the site
        foreach ($rows as $row) {
            if (is_array($row) && ($row['site'] ?? null) === $site) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Set the site's overriding title, email and store on its view.
     *
     * A row value counts as set only when it holds something: an empty title or
     * an empty email list falls back to the base form's, so a cell the editor
     * left blank never clears a site's title or stops its email. An overriding
     * email replaces the base list whole, because merging two recipient lists
     * would mail people nobody named for that site. Store is read as a boolean
     * written by hand or as the `'true'` or `'false'` the editor's button group
     * saves; anything else falls back to the base form's.
     *
     * The rows themselves are removed from the view, whatever site it is for.
     * Templates receive the view's data as `form_config`, and the rows hold every
     * site's recipients; the view carries its own site's values as its title,
     * email and store instead. The rows stay on the base form (#62).
     *
     * @param  SiteForm  $view  The resolved view to apply the site's row to.
     */
    public static function apply(SiteForm $view): void
    {
        // Remove every site's rows from the view
        $view->remove(self::KEY);

        // Find the view's site row on the base form
        $row = self::rowFor($view->base(), $view->site());

        // Keep the base form's values when the site has no row
        if ($row === null) {
            return;
        }

        // Override the title when the row sets one
        $title = $row['title'] ?? null;
        if (is_string($title) && $title !== '') {
            $view->title($title);
        }

        // Replace the email list when the row sets one
        $email = $row['email'] ?? null;
        if (is_array($email) && $email !== []) {
            $view->email($email);
        }

        // Override the store setting when the row sets one
        $store = self::storeOf($row['store'] ?? null);
        if ($store !== null) {
            $view->store($store);
        }
    }

    /**
     * A row's store setting as a boolean, or null when it sets none.
     *
     * @param  mixed  $value  The row's `store` value.
     * @return ?bool The store setting, or null when the row sets none.
     */
    private static function storeOf(mixed $value): ?bool
    {
        return match ($value) {
            true, self::STORE => true,
            false, self::DO_NOT_STORE => false,
            default => null,
        };
    }
}
