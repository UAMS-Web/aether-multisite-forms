<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Forms\Multisite\Exceptions;

use LogicException;

/**
 * Thrown when code tries to save or delete a site-resolved form view.
 *
 * A resolved view carries one site's values over the base form. Saving it
 * would write those values into the base file for every site, so the view
 * refuses instead of doing so quietly.
 */
class ReadOnlyFormException extends LogicException
{
    /**
     * The exception for one refused operation on a form's resolved view.
     *
     * @param  string  $operation  The refused operation, such as `saved`.
     * @param  string  $handle  The handle of the form.
     * @param  string  $site  The handle of the site the view was resolved for.
     * @return self The exception, with a message naming the form and the site.
     */
    public static function for(string $operation, string $handle, string $site): self
    {
        return new self("The [{$handle}] form resolved for the [{$site}] site is read-only and cannot be {$operation}. Resolve nothing and use Form::find('{$handle}') to change the form itself.");
    }
}
