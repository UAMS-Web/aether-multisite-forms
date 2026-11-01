<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Forms\Multisite\Forms;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Statamic\Contracts\Forms\Submission;
use Statamic\Events\FormSubmitted;
use Statamic\Events\SubmissionCreating;
use UAMSWeb\Aether\Forms\Multisite\Http\Middleware\ResolveSubmittedForm;
use UAMSWeb\Aether\Forms\Multisite\Sites\RequestSite;

/**
 * Records the site a submission was made from, under the reserved `_site` key.
 *
 * It listens to `FormSubmitted`, which core dispatches from its submit route
 * after building the submission's data and before saving it or queuing its
 * emails. The site is the one core passes to the email job, from the shared
 * submit-site helper. A visitor cannot put a value of their own in the request
 * body: core treats request values starting with `_` as parameters, and only
 * blueprint fields become data. The site itself comes from the referrer, as
 * core's email site does, and the submit gate admits only the form's sites.
 *
 * Core dispatches `FormSubmitted` halting on the first listener that returns
 * anything other than null, so a listener registered before this one can stop
 * it from running. `SubmissionCreating` is the backstop: on the submit route, a
 * new submission of the submitted form that reaches its first save without
 * `_site` gets it then. A submission saved any other way, including another
 * form's saved during the submit request, is left unattributed, as the spec
 * defines.
 */
final class RecordSubmitSite
{
    /** The reserved data key the site is recorded under. */
    public const string KEY = '_site';

    /**
     * Create the listener with the services it reads the request through.
     *
     * @param  RequestSite  $requestSite  Names the site the submission came from.
     * @param  Request  $request  The current request, read for its route.
     */
    public function __construct(private readonly RequestSite $requestSite, private readonly Request $request) {}

    /**
     * Record the submit site on a submission made through core's submit route.
     *
     * @param  FormSubmitted  $event  The event carrying the new submission.
     */
    public function submitted(FormSubmitted $event): void
    {
        $this->record($event->submission);
    }

    /**
     * Record it on the first save of a submission the route made, if nothing
     * has.
     *
     * @param  SubmissionCreating  $event  The event carrying the submission.
     */
    public function creating(SubmissionCreating $event): void
    {
        $submission = $event->submission;
        // The event's property is untyped, so Larastan needs this guard; core always
        // passes a submission, so InstanceOfToTrue and the unreachable return
        // cannot be killed.
        // @pest-mutate-ignore: InstanceOfToTrue, RemoveEarlyReturn
        if (! $submission instanceof Submission) {
            return;
        }

        // Record the site if none is set and the submit route made the submission
        if ($submission->get(self::KEY) === null && $this->madeByTheRoute($submission)) {
            $this->record($submission);
        }
    }

    /**
     * Whether the request is core's submit route and the submission is of the
     * form it submits, so another form's submission saved during the request is
     * left alone.
     *
     * @param  Submission  $submission  The submission about to be saved.
     * @return bool True when the submit route made this submission.
     */
    private function madeByTheRoute(Submission $submission): bool
    {
        // Check whether the request is on core's submit route
        $route = $this->request->route();
        if (! $route instanceof Route || $route->getName() !== ResolveSubmittedForm::ROUTE) {
            return false;
        }

        // Check whether the submission is of the form the route submits
        return $submission->form()->handle() === $route->originalParameter('form');
    }

    /**
     * Record the request's submit site on a submission.
     *
     * @param  Submission  $submission  The submission to record the site on.
     */
    private function record(Submission $submission): void
    {
        $submission->set(self::KEY, RequestSite::handle($this->requestSite->forSubmission()));
    }
}
