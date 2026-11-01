<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Forms\Multisite\Actions;

use Illuminate\Support\Facades\Gate;
use Statamic\Actions\DuplicateForm as CoreDuplicateForm;
use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Forms\Form;

/**
 * Core's Duplicate form action, refused for a form the user cannot view (#117).
 *
 * Core's action asks only `create` on the form class and never names the form it
 * copies, so the site rules on form access (#77) could not reach it: a user with
 * `configure forms` could copy a form of a site they cannot access, with its email
 * recipients, data and blueprint. This class also asks `view` on the form, which
 * the add-on's gate answers.
 *
 * Statamic registers every action under the add-on's `src/Actions` by its handle
 * while booting the add-on, after core has registered its own. The short class
 * name matches core's, so this class takes core's handle, `duplicate_form`, and
 * replaces it for running the action and for listing it; the Control Panel posts
 * the same handle as before.
 */
final class DuplicateForm extends CoreDuplicateForm
{
    /**
     * Whether the user may duplicate the form: core's check, and `view` on the form.
     *
     * A selection naming no form arrives as null, which `view` refuses.
     *
     * @param  User  $user  The user running the action.
     * @param  Form|null  $item  The form to duplicate, or null when none is named.
     * @return bool True when core allows it and the user may view the form.
     */
    #[\Override]
    public function authorize($user, $item): bool
    {
        return parent::authorize($user, $item) === true
            && Gate::forUser($user)->allows('view', [$item]);
    }
}
