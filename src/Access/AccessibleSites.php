<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Forms\Multisite\Access;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Statamic\Sites\Site as SiteObject;

/**
 * Refuses a sites selection its saver could no longer edit the form under (#118).
 *
 * Editing a form needs every site it lists, or every configured site for a global
 * form (#77), so a non-super user may not save a site they cannot access, nor an
 * empty selection, which makes the form global, unless they can access every
 * site. A super user, and a save with no signed-in user, pass. A handle naming no
 * configured site is left to `ConfiguredSites`.
 *
 * The rule is implicit, so it also runs when the selection is missing, which
 * saves the form as global just as an empty one does.
 */
final class AccessibleSites implements ValidationRule
{
    // AccessibleSitesTest's missing-selection row fails when this is false, shown by
    // hand (#138), but a property default is not an executable line, so the coverage
    // driver links no test to the mutant and it is never run.
    // @pest-mutate-ignore: TrueToFalse
    /**
     * Run the rule when the value is missing or empty too.
     *
     * @var bool True, so Laravel validates a missing or empty selection.
     */
    public bool $implicit = true;

    /**
     * Fail a selection naming a site, or leaving out sites, the user cannot access.
     *
     * @param  string  $attribute  The name of the field being validated.
     * @param  mixed  $value  The submitted selection of site handles.
     * @param  Closure(string): mixed  $fail  Reports the validation message.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Pass a save with no signed-in user, or a super user's
        $user = User::current();
        if ($user === null || $user->isSuper()) {
            return;
        }

        // Read the selection as a list of entries
        $entries = (array) $value;

        // A blank handle saves as no site, so the form would be global (the Control
        // Panel trims " " to "", which neither `array` nor ConfiguredSites checks)
        $handles = array_filter($entries, static fn (mixed $handle): bool => is_string($handle) && trim($handle) !== '');

        // Check whether the selection would make the form global
        if ($handles === []) {
            // An entry that is not a handle at all is ConfiguredSites' to report
            if (array_filter($entries, static fn (mixed $entry): bool => ! is_string($entry)) !== []) {
                return;
            }

            // Refuse a global form to a user who cannot access every site
            if (! SiteAccess::canAccessAll($user, SiteAccess::allHandles())) {
                $fail('Choose the sites this form belongs to. Leaving :attribute empty would put it on every site, including sites you cannot access.');
            }

            return;
        }

        // Collect the names of the selected sites the user cannot access
        $refused = [];
        foreach ($handles as $handle) {
            // Name a configured site the user cannot access
            $site = Site::get($handle);
            if ($site instanceof SiteObject && ! SiteAccess::canAccess($user, $handle)) {
                // Core leaves name() untyped; it falls back to the handle itself
                $name = $site->name();
                $refused[] = is_string($name) ? $name : $handle;
            }
        }

        // Fail the selection, naming each refused site
        if ($refused !== []) {
            $fail('This form cannot be assigned to a site you cannot access: '.implode(', ', $refused).'.');
        }
    }
}
