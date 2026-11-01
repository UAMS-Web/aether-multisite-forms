<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Forms\Multisite\Tags;

use Statamic\Exceptions\NotFoundHttpException;
use Statamic\Facades\Blink;
use Statamic\Facades\Form as FormFacade;
use Statamic\Forms\Form;
use Statamic\Forms\Tags;
use UAMSWeb\Aether\Forms\Multisite\Forms\FormResolver;
use UAMSWeb\Aether\Forms\Multisite\Forms\NotAssigned;
use UAMSWeb\Aether\Forms\Multisite\Forms\SiteForm;
use UAMSWeb\Aether\Forms\Multisite\Sites\RequestSite;

/**
 * Core's `{{ form }}` tags, gated by site assignment.
 *
 * It registers under core's own handle, `form`, so it replaces core's tags. Each
 * tag that reads a form resolves it for the page's site first: an assigned form,
 * or one with no sites, renders as core renders it, through the site's read-only
 * view; an unassigned form follows the `not_assigned` setting.
 *
 * Seeding core's `form-{handle}` Blink key with the site's view (the spec's first
 * mechanism) is not enough on its own: core puts a form passed in from context
 * straight into that key, replacing a seed, and a missing form makes core throw
 * rather than render nothing. So the form is resolved here, from the base form
 * core finds, every time a tag asks for it.
 *
 * `form:fields` is not gated: core's reads only the fields `form:create` hands
 * it, so it renders nothing once `form:create` is gated.
 *
 * Each tag returns whatever core's returns, which is wider than core's docblocks
 * say (`create` returns an array when there is no parser to render its content,
 * as through `Statamic::tag()`), hence `mixed`. For an unassigned form `create`
 * returns an empty string, or an empty array when there is no parser (#89),
 * `errors` and `success` return `false`, `submission`
 * returns null, and `submissions` returns an empty list, which a tag pair renders
 * as it renders a form with no submissions.
 */
class FormTags extends Tags
{
    /**
     * The form tags' handle, which is core's.
     *
     * @var string The handle the tags register under.
     */
    protected static $handle = 'form';

    /**
     * Holds the handle of the form a 404 is aborting the request for, so no form
     * on the error page, whose layout may render forms too, aborts it again.
     */
    private const string ABORTING = 'multisite-forms.not-assigned.aborting';

    /**
     * Each handle this tag has resolved, with the site's view or null when the
     * form is unassigned, so a render resolves a form once rather than per call.
     *
     * @var array<string, SiteForm|null> Each resolved view, by form handle.
     */
    private array $resolved = [];

    /**
     * `{{ form:create }}`, and `{{ form:{handle} }}`, which core's `__call`
     * renders through it.
     *
     * Gated with no parser, it returns an empty array: core returns the form's
     * data as an array there, and an empty one is what core returns when it has
     * nothing (`user:passkeys`), so `@if ($form)` skips it and a loop over it
     * runs zero times.
     *
     * @return mixed core's docblock says string; see the class docblock
     *
     * @throws NotFoundHttpException When the form is unassigned and the
     *                               setting is `404`.
     */
    public function create(): mixed
    {
        // Render core's form when the tag is not gated
        if (! $this->gated()) {
            return parent::create();
        }

        // Render nothing, as a string or an array for the caller
        return $this->canParseContents() ? '' : [];
    }

    /**
     * `{{ form:errors }}`, or false for an unassigned form.
     *
     * @return mixed Core's errors, or false when the form is gated.
     *
     * @throws NotFoundHttpException When the form is unassigned and the
     *                               setting is `404`.
     */
    public function errors(): mixed
    {
        return $this->gated() ? false : parent::errors();
    }

    /**
     * `{{ form:success }}`, or false for an unassigned form.
     *
     * @return mixed Core's success value, or false when the form is gated.
     *
     * @throws NotFoundHttpException When the form is unassigned and the
     *                               setting is `404`.
     */
    public function success(): mixed
    {
        return $this->gated() ? false : parent::success();
    }

    /**
     * `{{ form:submission }}`, the submission core flashed after a submit.
     *
     * Core's returns the submission only after `success`, which is gated, so an
     * unassigned form gets null here without a gate of its own.
     *
     * @return array<array-key, mixed>|null The submission, or null when none.
     */
    public function submission(): mixed
    {
        // Read core's submission
        $submission = parent::submission();

        // Return it only when it is an array
        return is_array($submission) ? $submission : null;
    }

    /**
     * `{{ form:submissions }}`, or an empty list for an unassigned form.
     *
     * @return array<array-key, mixed> The form's submissions, or none when gated.
     *
     * @throws NotFoundHttpException When the form is unassigned and the
     *                               setting is `404`.
     */
    public function submissions(): mixed
    {
        return $this->gated() ? [] : parent::submissions();
    }

    /**
     * `{{ form:assigned in="contact" }}`: whether the form renders on the page's
     * site, so a template can hide what surrounds it (#60). True for a form
     * assigned to the site or one with no sites; false for an unassigned or
     * missing form. It never aborts or logs, whatever `not_assigned` says.
     *
     * As a single tag it returns that boolean, for an Antlers `if` or
     * `Statamic::tag('form:assigned')->fetch()` in Blade; as a tag pair it
     * renders its contents only when the form is assigned, as core's
     * `form:errors` does with its errors. Its name shadows a form whose handle
     * is `assigned` in the `{{ form:{handle} }}` shorthand, as core's own tag
     * names do.
     *
     * @return mixed The boolean for a single tag, or the rendered contents.
     */
    public function assigned(): mixed
    {
        // Check whether the named form resolves to a view for the page's site
        $handle = $this->namedHandle();
        $assigned = $handle !== null && $this->resolved($handle) instanceof SiteForm;

        // Return the boolean when the tag is used on its own
        if ($this->content === '') {
            return $assigned;
        }

        // Render the pair's contents only for an assigned form
        return $assigned ? $this->parse() : '';
    }

    /**
     * The form for the page's site: the site's read-only view, or null when the
     * form is unassigned or missing.
     *
     * @return SiteForm|null The site's view, or null when there is none.
     */
    protected function form(): ?SiteForm
    {
        // Find the handle the tag names
        $handle = $this->namedHandle();

        // Resolve the site's view of that form, when a handle is named
        return $handle === null ? null : $this->resolved($handle);
    }

    /**
     * Whether the tag's form is not assigned to the page's site, so the tag must
     * render nothing; under the `404` setting it aborts instead. A missing form
     * is not gated, so core reports it.
     *
     * @return bool True when the tag must render nothing for its form.
     *
     * @throws NotFoundHttpException When the form is unassigned and the
     *                               setting is `404`.
     */
    private function gated(): bool
    {
        // Leave a missing, unnamed or assigned form ungated
        $handle = $this->namedHandle();
        if ($handle === null || ! $this->baseForm($handle) instanceof Form || $this->resolved($handle) instanceof SiteForm) {
            return false;
        }

        // Find the handle of the page's site
        $site = RequestSite::handle(app(RequestSite::class)->forPage());

        // Check whether the `not_assigned` setting asks for a 404
        if (NotAssigned::fromConfig() === NotAssigned::NotFound) {
            // Render nothing when a 404 is already aborting the request
            if (is_string(Blink::get(self::ABORTING))) {
                return true;
            }

            // Mark the request as aborting, then abort it
            Blink::put(self::ABORTING, $handle);

            throw new NotFoundHttpException("Form [{$handle}] is not assigned to site [{$site}].");
        }

        // Once per form and site per request: Blink marks the key as seen even
        // though the callback stores nothing.
        Blink::once("multisite-forms.not-assigned.{$handle}.{$site}", function () use ($handle, $site): void {
            logger()->warning("Multisite Forms rendered nothing for a form not assigned to the page's site.", [
                'form' => $handle,
                'site' => $site,
            ]);
        });

        // Render nothing for the unassigned form
        return true;
    }

    /**
     * The handle the tag names, from its parameters or a form in context, or
     * null.
     *
     * @return string|null The form's handle, or null when the tag names none.
     */
    private function namedHandle(): ?string
    {
        // Read the handle the way core's tags read it
        $handle = $this->formHandle();

        // Accept only a string handle
        return is_string($handle) ? $handle : null;
    }

    /**
     * The form with that handle, found and cached the way core finds it. It may
     * already be a site's view, which the resolver resolves from its base.
     *
     * @param  string  $handle  The form's handle.
     * @return mixed The form, or whatever core's lookup returned for it.
     */
    private function baseForm(string $handle): mixed
    {
        return Blink::once("form-{$handle}", fn (): mixed => FormFacade::find($handle));
    }

    /**
     * The site's view of the form with that handle, or null when it is
     * unassigned or missing.
     *
     * @param  string  $handle  The form's handle.
     * @return SiteForm|null The site's view, or null when there is none.
     */
    private function resolved(string $handle): ?SiteForm
    {
        // Resolve and remember the view the first time the handle is asked for
        if (! array_key_exists($handle, $this->resolved)) {
            $base = $this->baseForm($handle);
            $this->resolved[$handle] = $base instanceof Form
                ? app(FormResolver::class)->resolve($base, app(RequestSite::class)->forPage())
                : null;
        }

        // Return the remembered view
        return $this->resolved[$handle];
    }
}
