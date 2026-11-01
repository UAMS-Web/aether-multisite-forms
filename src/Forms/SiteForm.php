<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Forms\Multisite\Forms;

use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Collection;
use ReflectionClass;
use Statamic\Forms\Form;
use UAMSWeb\Aether\Forms\Multisite\Exceptions\ReadOnlyFormException;

/**
 * A read-only view of a form as one site sees it.
 *
 * It holds a copy of the base form's state, so the handle, the blueprint, the
 * action URL and the submissions folder are the base form's, and it remembers
 * the site it was resolved for. Saving or deleting it throws: a resolved view
 * written back would put one site's values into the base file for every site.
 * Build one with `FormResolver`, not directly.
 *
 * The view is always a core Form, whatever class the form contract is bound to,
 * so a driver's own methods (`statamic/eloquent-driver`'s `model()`, for one)
 * are not on it. Reach them through `base()`, which returns the stored form in
 * its own class (#107).
 */
class SiteForm extends Form
{
    /**
     * The handle of the site this view was resolved for.
     *
     * @var string Handle of the site whose values the view carries.
     */
    private string $site;

    /**
     * The base form this view was copied from.
     *
     * @var Form The stored form, in its own class.
     */
    private Form $base;

    /**
     * A view of a base form for a site, with the base form left untouched.
     *
     * @param  Form  $base  The stored form to copy.
     * @param  string  $site  The handle of the site the view is for.
     * @return self The read-only view.
     */
    public static function of(Form $base, string $site): self
    {
        // Copy from a clone, so the data and supplements collections are not
        // shared with the base
        $source = clone $base;

        // Create the empty view to copy into
        $view = new self;

        // Copy every property core's Form declares, its traits' included
        foreach (new ReflectionClass(Form::class)->getProperties() as $property) {
            // Skip what cannot be copied: a static property, and a typed property with no
            // default, which throws when read. Core's Form declares neither today, so both
            // halves are always true and swapping && for || cannot be told apart.
            // @pest-mutate-ignore: BooleanAndToBooleanOr
            if (! $property->isStatic() && $property->isInitialized($source)) {
                $property->setValue($view, $property->getValue($source));
            }
        }

        // Remember the site and the base form the view was resolved from
        $view->site = $site;
        $view->base = $base;

        return $view;
    }

    /**
     * The handle of the site this view was resolved for.
     *
     * @return string The site's handle.
     */
    public function site(): string
    {
        return $this->site;
    }

    /**
     * The base form this view was copied from.
     *
     * @return Form The stored form, in its own class.
     */
    public function base(): Form
    {
        return $this->base;
    }

    /**
     * Refuse to save: the view's values belong to one site.
     *
     * @throws ReadOnlyFormException Always, naming the form and the site.
     */
    public function save(): never
    {
        throw ReadOnlyFormException::for('saved', $this->handleName(), $this->site);
    }

    /**
     * Refuse to delete: deleting the view would delete the base form and its
     * submissions.
     *
     * @throws ReadOnlyFormException Always, naming the form and the site.
     */
    public function delete(): never
    {
        throw ReadOnlyFormException::for('deleted', $this->handleName(), $this->site);
    }

    /**
     * Run the hooks registered on core's Form, then those registered on this class.
     *
     * Core files a hook under the class it was registered on and runs only the
     * hooks filed under `static::class`, so without this a hook an add-on or app
     * registered with `Form::hook()` would silently skip every resolved view.
     *
     * @param  string  $name  The name of the hook to run.
     * @param  mixed  $payload  The value passed through the hooks.
     * @return mixed The payload as the last hook returns it.
     */
    protected function runHooks(string $name, $payload = null)
    {
        // Read the registered hooks
        $hooks = app('statamic.hooks');

        // Gather core's Form hooks, then this class's, bound to this view
        $closures = Collection::wrap(data_get($hooks, [Form::class, $name], []))
            ->merge(Collection::wrap(data_get($hooks, [static::class, $name], [])))
            ->map->bindTo($this, $this);

        // Send the payload through every hook in turn
        return (new Pipeline)
            ->send($payload)
            ->through($closures->all())
            ->thenReturn();
    }

    /**
     * The form handle for a message, which core's untyped getter does not
     * promise is a string.
     *
     * @return string The handle, or an empty string when it is not one.
     */
    private function handleName(): string
    {
        // Read the handle from core's untyped getter
        $handle = $this->handle();

        // Fall back to an empty string when the handle is not a string
        return is_string($handle) ? $handle : '';
    }
}
