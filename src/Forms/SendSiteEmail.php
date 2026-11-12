<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Forms\Multisite\Forms;

use Statamic\Contracts\Forms\Submission;
use Statamic\Facades\Site as SiteFacade;
use Statamic\Forms\SendEmail;
use Statamic\Sites\Site;

/**
 * Core's email job, sent for the site the submission was recorded under.
 *
 * Core's submit route passes the job the site the referrer falls under, which
 * differs from the recorded site when a browser sends no referrer, or one on
 * another site than the signed `_site` field. The job takes the recorded site
 * instead, so the email's locale and site globals follow the submission. A
 * submission with no recorded site, or one that is no longer configured, keeps
 * the site core passed.
 *
 * The add-on installs this class as `statamic.forms.send_email_job` only when
 * that setting still names core's own job; a host that set its own job keeps it.
 */
class SendSiteEmail extends SendEmail
{
    /**
     * Create the job for the recorded site, else the site core passed.
     *
     * @param  Submission  $submission  The submission being emailed.
     * @param  Site  $site  The site core derived from the referrer.
     * @param  mixed  $config  One entry of the form's email configuration.
     */
    public function __construct(Submission $submission, Site $site, $config)
    {
        parent::__construct($submission, self::recordedSite($submission) ?? $site, $config);
    }

    /**
     * Install this job in place of core's, unless the host chose its own.
     */
    public static function register(): void
    {
        // Leave a job the host set to itself
        if (config('statamic.forms.send_email_job') !== SendEmail::class) {
            return;
        }

        config(['statamic.forms.send_email_job' => self::class]);
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
