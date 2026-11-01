<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Forms\Multisite\Forms;

use InvalidArgumentException;

/**
 * What the form tags do with a form not assigned to the page's site, read from
 * `aether-multisite-forms.not_assigned`.
 */
enum NotAssigned: string
{
    /** Render nothing and log a warning. */
    case Empty = 'empty';

    /** Abort the request with a 404. */
    case NotFound = '404';

    /** The config key the setting is read from. */
    public const string CONFIG_KEY = 'aether-multisite-forms.not_assigned';

    /**
     * The configured setting. An integer 404 is accepted, since a config file
     * can easily say `404` without quotes.
     *
     * @return self The setting the config names.
     *
     * @throws InvalidArgumentException When the configured value is not a setting.
     */
    public static function fromConfig(): self
    {
        // Read the configured value, defaulting to rendering nothing
        $value = config(self::CONFIG_KEY, self::Empty->value);

        // Match a string or integer value to a setting
        $setting = is_string($value) || is_int($value) ? self::tryFrom((string) $value) : null;

        // Refuse a value that names no setting
        if ($setting === null) {
            throw new InvalidArgumentException(sprintf(
                'The %s setting must be "empty" or "404"; got %s.',
                self::CONFIG_KEY,
                var_export($value, true),
            ));
        }

        return $setting;
    }

    /**
     * Log an invalid setting as an error, so it is reported when the add-on boots
     * rather than only when a page first meets an unassigned form (#90). It does
     * not throw: that would stop every `artisan` command, `package:discover`
     * included, and every page rather than the few that render such a form,
     * which still throw from `fromConfig()`.
     */
    public static function reportInvalidConfig(): void
    {
        // Attempt to read the setting
        try {
            self::fromConfig();
        } catch (InvalidArgumentException $invalidArgumentException) {
            // Log the invalid setting instead of throwing
            logger()->error($invalidArgumentException->getMessage());
        }
    }
}
