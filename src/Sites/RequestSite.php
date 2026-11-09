<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Forms\Multisite\Sites;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use LogicException;
use Statamic\Facades\Site as SiteFacade;
use Statamic\Sites\Site;

/**
 * Names the site a request belongs to.
 *
 * The site that gates a submission and the site recorded on it must not
 * disagree, so the submit rule is kept in this one place. The form tag carries
 * the page's site in a signed hidden field, and a submission that carries it
 * names that site; a field whose signature fails is rejected, with a warning.
 * Without a field the rule is core's own expression from
 * `FormController::submit()`: the site the referrer falls under, else the
 * default, so a form rendered any other way submits as it did. Core's email
 * job still reads the referrer, so the two agree whenever a browser sends it.
 */
final class RequestSite
{
    /** The request value the form tag carries the page's site in, signed. */
    public const string FIELD = '_site';

    /**
     * The site of a form submission: the site the signed field names, else the
     * site whose URL the referrer falls under, else the default.
     *
     * @return Site The site the submission belongs to.
     *
     * @throws LogicException When the install defines no site at all.
     */
    public function forSubmission(): Site
    {
        return self::site($this->signedSite() ?? SiteFacade::findByUrl(URL::previous()) ?? SiteFacade::default());
    }

    /**
     * Sign a site's handle for the form tag's hidden field, so a submit can name
     * the page's site without the request being free to choose one.
     *
     * The handle is encrypted with the application key, which authenticates it
     * as well: a changed byte fails to decrypt. Nothing in it is secret.
     *
     * @param  Site|string  $site  The site, or its handle.
     * @return string The signed value for the hidden field.
     */
    public static function sign(Site|string $site): string
    {
        return Crypt::encryptString(self::handle($site));
    }

    /**
     * The site of a frontend page render.
     *
     * @return Site The site the page is rendered for.
     *
     * @throws LogicException When the install defines no site at all.
     */
    public function forPage(): Site
    {
        return self::site(SiteFacade::current());
    }

    /**
     * The handle of a site given as an object or a handle.
     *
     * @param  Site|string  $site  The site, or its handle.
     * @return string The site's handle.
     *
     * @throws LogicException When a site object carries no string handle.
     */
    public static function handle(Site|string $site): string
    {
        // Return a handle given as a string as it is
        if (is_string($site)) {
            return $site;
        }

        // Read the handle from the site object
        $handle = $site->handle();

        // Refuse a site object whose handle is not a string
        if (! is_string($handle)) {
            throw new LogicException('A site has no handle; check the sites configuration.');
        }

        return $handle;
    }

    /**
     * The site the request's signed field names, or null when the request
     * carries none, its signature fails, or the handle is no longer a configured
     * site, leaving the referrer rule to decide.
     *
     * @return Site|null The signed site, or null.
     */
    private function signedSite(): ?Site
    {
        // Leave a request with no field, or an empty one, to the referrer rule
        $value = request()->input(self::FIELD);
        if (! is_string($value) || $value === '') {
            return null;
        }

        // Reject a value that does not verify: the form tag did not make it
        try {
            $handle = Crypt::decryptString($value);
        } catch (DecryptException) {
            Log::warning('Multisite Forms rejected a submit site whose signature failed; the referrer decides the site.');

            return null;
        }

        // Accept only a site that is still configured
        $site = SiteFacade::get($handle);

        return $site instanceof Site ? $site : null;
    }

    /**
     * Narrow the facade's untyped answer, which is null when no site is
     * configured.
     *
     * @param  mixed  $site  The facade's answer.
     * @return Site The answer, narrowed to a site.
     *
     * @throws LogicException When it is not a site.
     */
    private static function site(mixed $site): Site
    {
        // Refuse an answer that is not a site
        if (! $site instanceof Site) {
            throw new LogicException('No site could be determined for the request; the install defines no sites.');
        }

        return $site;
    }
}
