<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Forms\Multisite\Sites;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Statamic\Facades\Site;
use Statamic\Sites\Site as SiteObject;

/**
 * Validates that every handle in a sites selection names a configured site.
 *
 * Core's `sites` fieldtype limits only the options it offers, not the values it
 * accepts, so a crafted request could otherwise save a handle no site has and
 * hide the form on every site. A value that is not a list (null, or a bare
 * handle) is read as a list of itself, so it is checked rather than crashing the
 * request; core's own `array` rule reports the shape.
 */
final class ConfiguredSites implements ValidationRule
{
    /**
     * Fail the attribute when any handle in it names no configured site.
     *
     * @param  string  $attribute  The name of the field being validated.
     * @param  mixed  $value  The submitted sites selection.
     * @param  Closure(string): mixed  $fail  Reports the failure message.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Check each handle in the selection, read as a list
        foreach ((array) $value as $handle) {
            // Fail on the first value that is not a configured site's handle
            if (! is_string($handle) || ! Site::get($handle) instanceof SiteObject) {
                $fail('The :attribute field must list only the sites this install defines.');

                return;
            }
        }
    }
}
