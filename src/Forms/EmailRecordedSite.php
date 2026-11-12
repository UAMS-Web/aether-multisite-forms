<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Forms\Multisite\Forms;

use Closure;
use Illuminate\Bus\Dispatcher;
use Illuminate\Contracts\Bus\Dispatcher as DispatcherContract;
use ReflectionProperty;
use Statamic\Contracts\Forms\Submission;
use Statamic\Facades\Site as SiteFacade;
use Statamic\Forms\SendEmails;
use Statamic\Sites\Site;

/**
 * Sends a submission's email for the site it was recorded under.
 *
 * Core's submit route passes `SendEmails` the site the referrer falls under,
 * which differs from the recorded site when a browser sends no referrer, or one
 * on another site than the signed `_site` field. This is a bus pipe: it runs
 * before `SendEmails` builds its email jobs and sets the site the jobs receive to
 * the one recorded on the submission, so the email's locale and site globals
 * follow the submission. A pipe is used because it reaches every email job
 * class, including a host's own, where swapping the job class would not. A
 * submission with no recorded site, or one no longer configured, keeps the site
 * core passed.
 */
final class EmailRecordedSite
{
    /**
     * Add this pipe to the bus, keeping the pipes already set.
     *
     * @param  DispatcherContract  $bus  The bus the form jobs are dispatched through.
     */
    public static function register(DispatcherContract $bus): void
    {
        // Leave a bus that is not the framework's, which has no pipes to extend.
        // The InstanceOfToTrue mutant is killed on macOS (the test with a mocked
        // bus fails) but reported untested on the Linux PCOV job (#258), so it is
        // annotated rather than left to a driver-dependent result.
        // @pest-mutate-ignore: InstanceOfToTrue
        if (! $bus instanceof Dispatcher) {
            return;
        }

        // Keep the pipes a host or another add-on already set
        $current = new ReflectionProperty(Dispatcher::class, 'pipes')->getValue($bus);
        $pipes = is_array($current) ? $current : [];

        $bus->pipeThrough([...$pipes, self::class]);
    }

    /**
     * Set the site of a `SendEmails` job to the recorded one, then pass it on.
     *
     * @param  mixed  $job  The job passing through the bus.
     * @param  Closure(mixed): mixed  $next  The rest of the pipeline.
     * @return mixed The rest of the pipeline's answer.
     */
    public function handle(mixed $job, Closure $next): mixed
    {
        // Leave every job other than core's email dispatcher alone
        if ($job instanceof SendEmails) {
            $this->apply($job);
        }

        return $next($job);
    }

    /**
     * Replace the job's site with the submission's recorded one, when it has one.
     *
     * @param  SendEmails  $job  Core's email dispatcher job.
     */
    private function apply(SendEmails $job): void
    {
        // Read the submission core gave the job. Its constructor requires a
        // submission, so the check only narrows the untyped property for PHPStan:
        // replacing it with true or dropping the return changes nothing reachable.
        $submission = new ReflectionProperty(SendEmails::class, 'submission')->getValue($job);
        // @pest-mutate-ignore: InstanceOfToTrue, RemoveEarlyReturn
        if (! $submission instanceof Submission) {
            return;
        }

        // Keep core's site for a submission with no recorded, configured site
        $site = self::recordedSite($submission);
        if (! $site instanceof Site) {
            return;
        }

        new ReflectionProperty(SendEmails::class, 'site')->setValue($job, $site);
    }

    /**
     * The site recorded on a submission, when it is still configured.
     *
     * @param  Submission  $submission  The submission being emailed.
     * @return Site|null The recorded site, or null when none is recorded or configured.
     */
    private static function recordedSite(Submission $submission): ?Site
    {
        // Read the handle the submit route recorded
        $handle = $submission->get(RecordSubmitSite::KEY);
        if (! is_string($handle)) {
            return null;
        }

        // Accept only a site that is still configured
        $site = SiteFacade::get($handle);

        // The facade answers null for a handle that is not configured, so the
        // check only narrows its untyped answer for PHPStan: replacing it with
        // true returns the same null, and the mutant cannot be killed.
        // @pest-mutate-ignore: InstanceOfToTrue
        return $site instanceof Site ? $site : null;
    }
}
