<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Forms\Multisite\Forms;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Statamic\Facades\Site;
use Statamic\Sites\Site as SiteObject;

/**
 * Validates the `site_overrides` rows submitted from a form's settings screen.
 *
 * Each row's site must be a configured site the form is assigned to (any site,
 * for a form with no sites), and no site may have two rows, because only the
 * first would ever apply. The assignment is read from the `sites` value in the
 * same request, so a row and the selection it depends on are checked together.
 * A row's site arrives as a one-item list from the screen and as a handle from
 * the form file; both are read. A row with no site is left to the row field's
 * own `required` rule.
 */
final class OverrideRows implements DataAwareRule, ValidationRule
{
    /**
     * The whole request data, set by the validator before validation.
     *
     * @var array<array-key, mixed> The submitted values, keyed by field handle.
     */
    private array $data = [];

    /**
     * Keep the request data, to read the submitted sites selection from.
     *
     * @param  array<array-key, mixed>  $data  The submitted values.
     * @return static This rule.
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    /**
     * Fail the attribute on the first row naming an unknown, unassigned or
     * repeated site.
     *
     * @param  string  $attribute  The name of the field being validated.
     * @param  mixed  $value  The submitted override rows.
     * @param  Closure(string): mixed  $fail  Reports the failure message.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Read the submitted assignment and start with no sites seen
        $assigned = $this->assignedSites();
        $seen = [];

        // Check each row in turn, stopping at the first failure
        foreach (is_array($value) ? $value : [] as $row) {
            // Skip a row with no site, which the row's own rule reports
            $handle = self::siteOf($row);
            if ($handle === null) {
                continue;
            }

            // Fail on a site this install does not define
            $site = Site::get($handle);
            if (! $site instanceof SiteObject) {
                $fail("An override row names [{$handle}], which is not a site this install defines.");

                return;
            }

            // Name the site for messages, falling back to its handle
            $name = $site->name();
            $name = is_string($name) && $name !== '' ? $name : $handle;

            // Fail on a site the form is not assigned to
            if ($assigned !== [] && ! in_array($handle, $assigned, true)) {
                $fail("{$name} has an override row but is not one of this form's sites.");

                return;
            }

            // Fail on a second row for the same site
            if (in_array($handle, $seen, true)) {
                $fail("{$name} has more than one override row. Keep one row per site.");

                return;
            }

            // Remember the site as seen
            $seen[] = $handle;
        }
    }

    /**
     * The site handle a row names, or null when it names none.
     *
     * @param  mixed  $row  One submitted override row.
     * @return ?string The row's site handle, or null when it names none.
     */
    private static function siteOf(mixed $row): ?string
    {
        // Read the row's site value
        $site = is_array($row) ? ($row['site'] ?? null) : null;

        // Take the first item of the one-item list the screen submits
        if (is_array($site)) {
            $site = $site[0] ?? null;
        }

        // Accept only a non-empty string handle
        return is_string($site) && $site !== '' ? $site : null;
    }

    /**
     * The sites the submitted selection assigns the form to; empty for a
     * global form.
     *
     * @return list<string> The assigned site handles.
     */
    private function assignedSites(): array
    {
        // Read the sites selection submitted with the rows
        $sites = $this->data[Assignment::KEY] ?? null;

        // The keys are only reindexed for the list type: the caller compares against
        // [] and searches values, so a keyed result behaves the same.
        // @pest-mutate-ignore: UnwrapArrayValues
        return array_values(array_filter(
            is_array($sites) ? $sites : (is_string($sites) ? [$sites] : []),
            fn (mixed $handle): bool => is_string($handle) && $handle !== '',
        ));
    }
}
