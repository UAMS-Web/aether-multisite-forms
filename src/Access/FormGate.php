<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Forms\Multisite\Access;

use Illuminate\Support\Facades\Gate;
use Statamic\Contracts\Forms\Submission;
use Statamic\Facades\User;
use Statamic\Forms\Form;
use UAMSWeb\Aether\Forms\Multisite\Forms\Assignment;

/**
 * Denies Control Panel users the forms of sites they cannot access (#77).
 *
 * Registered as a global `Gate::before()` callback, because core's form and
 * submission policies return true from their own `before()` for any user with
 * `configure forms`, and a policy method would never be reached for them. Only a
 * super user passes untouched; `configure forms` does not bypass the site rules.
 *
 * Two rules apply to an ability asked about a form, or about a submission of one:
 *
 * - A form listing sites is denied to a user who can access none of them.
 * - Changing a form (`edit`, `delete`, `configure form fields`) needs access to
 *   every site it is assigned to, or to every configured site for a global form,
 *   because the change reaches each of those sites.
 *
 * Otherwise the callback returns null, so core's policies decide as before. It
 * never grants anything.
 */
final class FormGate
{
    // tests/Integration/Access/FormGateTest.php fails when any item is removed, shown
    // by hand for each one (#77), but a constant is not an executable line, so the
    // coverage driver links no test to these mutants and they are never run.
    // @pest-mutate-ignore: RemoveArrayItem
    /** The abilities that change a form, and so need every one of its sites. */
    public const array CHANGING = ['edit', 'delete', 'configure form fields'];

    /**
     * Register the callback with the gate.
     */
    public static function register(): void
    {
        Gate::before(static fn (mixed $user, string $ability, array $arguments): ?bool => self::decide($user, $ability, $arguments));
    }

    /**
     * False to deny the ability, or null to leave it to core's policies.
     *
     * @param  mixed  $user  The user the gate is asked about, in any form core accepts.
     * @param  string  $ability  The ability being checked, such as `edit`.
     * @param  array<array-key, mixed>  $arguments  The gate's arguments; the first is the subject.
     * @return bool|null False to deny, or null to defer to core's policies.
     */
    public static function decide(mixed $user, string $ability, array $arguments): ?bool
    {
        // Leave any ability not about a form or a submission to core
        $form = self::formOf($arguments[0] ?? null);
        if (! $form instanceof Form) {
            return null;
        }

        // Leave a missing user or a super user to core
        $user = User::fromUser($user);
        if ($user === null || $user->isSuper()) {
            return null;
        }

        // Read the sites the form is assigned to
        $sites = Assignment::sites($form);

        // Deny a form listing sites when the user can access none of them
        if ($sites !== [] && ! SiteAccess::canAccessAny($user, $sites)) {
            return false;
        }

        // Deny a change to the form unless the user covers every one of its sites
        $changing = $form === $arguments[0] && in_array($ability, self::CHANGING, true);
        if ($changing && ! SiteAccess::canAccessAll($user, $sites === [] ? SiteAccess::allHandles() : $sites)) {
            return false;
        }

        return null;
    }

    /**
     * The form an ability is asked about: the subject itself, or a submission's form.
     *
     * @param  mixed  $subject  The gate's first argument.
     * @return Form|null The form, or null when the subject is neither kind.
     */
    private static function formOf(mixed $subject): ?Form
    {
        // Swap a submission for the form it belongs to
        if ($subject instanceof Submission) {
            $subject = $subject->form();
        }

        // Return the subject only when it is a form
        return $subject instanceof Form ? $subject : null;
    }
}
