<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Forms\Multisite\Forms;

use Statamic\Forms\Form;
use Statamic\Sites\Site;
use UAMSWeb\Aether\Forms\Multisite\Sites\RequestSite;

/**
 * Resolves a form for a site.
 *
 * Every surface that shows or accepts a form for a site (the tags, the submit
 * route, the APIs) asks this class, so assignment and the site's overrides are
 * decided in one place. The view carries the base form's values, with the
 * site's `site_overrides` row applied on top (see `Overrides`).
 */
final class FormResolver
{
    /**
     * The form as the site sees it, or null when the form is not assigned to
     * the site.
     *
     * @param  Form  $form  The form to resolve, or a view of it.
     * @param  Site|string  $site  The site, or its handle.
     * @return ?SiteForm The site's view, or null when the form is not its.
     */
    public function resolve(Form $form, Site|string $site): ?SiteForm
    {
        // Resolve from the base form, so a view is never resolved from another view
        $base = $form instanceof SiteForm ? $form->base() : $form;

        // Name the site by its handle
        $handle = RequestSite::handle($site);

        // Check whether the form is assigned to the site
        if (! Assignment::covers($base, $handle)) {
            return null;
        }

        // Build the site's view and apply its overrides
        $view = SiteForm::of($base, $handle);
        Overrides::apply($view);

        return $view;
    }
}
