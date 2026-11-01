<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Forms\Multisite\Access;

use Statamic\Events\FormCreating;
use Statamic\Facades\User;
use Statamic\Forms\Form;
use UAMSWeb\Aether\Forms\Multisite\Forms\Assignment;

/**
 * Assigns a new form to the sites of a creator who cannot access every site (#79).
 *
 * A form with no sites belongs to every site, and changing it needs access to
 * every site (#77), so a scoped administrator who created one could not edit it.
 * Listening on `FormCreating` sets `sites` to the sites the current user can
 * access before the form is first written, whichever screen or action created it.
 *
 * Nothing changes for a form that already lists sites (one assigned in code, or a
 * duplicate that copied its source's), for a super user or one covering every
 * site, for no signed-in user (a console command), or for a user who can access
 * no site, whose form keeps core's behavior rather than an assignment that would
 * mean the same as none.
 */
final class CreatorSites
{
    /**
     * Assign the new form to the user's sites when they do not cover every site.
     *
     * @param  FormCreating  $event  The event core dispatches before a new form is written.
     */
    public static function creating(FormCreating $event): void
    {
        // Leave a form that already lists sites as it is
        $form = $event->form;
        if (! $form instanceof Form || Assignment::sites($form) !== []) {
            return;
        }

        // Skip a console write and a super user
        $user = User::current();
        if ($user === null || $user->isSuper()) {
            return;
        }

        // Skip a user who can access no site, or every site
        $accessible = SiteAccess::accessible($user);
        if ($accessible === [] || $accessible === SiteAccess::allHandles()) {
            return;
        }

        // Assign the form to the sites the user can access
        $form->set(Assignment::KEY, $accessible);
    }
}
