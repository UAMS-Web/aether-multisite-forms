<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Forms\Multisite\Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Statamic\Facades\Form;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;
use Statamic\Forms\Email;
use Statamic\Sites\Site as SiteObject;
use Tests\TestCase;
use UAMSWeb\Aether\Forms\Multisite\Sites\RequestSite;

/**
 * Host-bound: a submit through the real stack is emailed for the site it was
 * recorded under, not the site the referrer names (#249).
 *
 * It picks two sites from the host's own configuration, the default and the
 * first other one, so it runs in any multisite host. The mailer is faked, and
 * form files go to a temporary directory.
 *
 * @command  php artisan test vendor/uams-web/aether-multisite-forms/tests/Feature/EmailRecordedSiteTest.php
 */
final class EmailRecordedSiteTest extends TestCase
{
    /**
     * The temporary directory this test's forms and submissions live in.
     *
     * @var string Absolute path, unique to this test.
     */
    private string $directory;

    /**
     * Boot the host, point forms at a temporary directory, save a global form
     * that emails each submission, and fake the mailer.
     */
    protected function setUp(): void
    {
        // Boot the host application
        parent::setUp();

        // Point form files and submissions at a unique temporary directory
        $this->directory = sys_get_temp_dir().'/msf-host-email-'.uniqid('', true);
        config(['statamic.forms.forms' => $this->directory]);
        Stache::store('form-submissions')->directory($this->directory.'/_submissions');

        // Save a global form that stores and emails its submissions
        Form::make('msf_host_email')->title('Email')->store(true)->email(['to' => 'info@example.com', 'subject' => 'Hello'])->save();

        // Capture the mail instead of sending it
        Mail::fake();
    }

    /**
     * Delete the temporary directory and tear the host down.
     */
    protected function tearDown(): void
    {
        // Remove this test's forms and submissions
        File::deleteDirectory($this->directory);

        // Tear down the host application
        parent::tearDown();
    }

    /** A signed site with no referrer is emailed for the signed site. */
    public function test_a_signed_site_with_no_referrer_is_emailed_for_that_site(): void
    {
        // Submit with the other site's signed field and no Referer header
        $other = $this->otherSite();
        $this->post('/!/forms/msf_host_email', ['message' => 'hi', RequestSite::FIELD => RequestSite::sign($other)])->assertRedirect();

        // Expect the email composed for the signed site
        $this->assertSame([RequestSite::handle($other)], $this->emailedSites());
    }

    /** A signed site and a referrer on another site are emailed for the signed site. */
    public function test_the_signed_site_wins_over_the_referrer(): void
    {
        // Submit with the other site's signed field from a default-site page
        $other = $this->otherSite();
        $this->post('/!/forms/msf_host_email', ['message' => 'hi', RequestSite::FIELD => RequestSite::sign($other)], ['referer' => (string) Site::default()->absoluteUrl()])->assertRedirect();

        // Expect the email composed for the signed site
        $this->assertSame([RequestSite::handle($other)], $this->emailedSites());
    }

    /** With no signed field, core's referrer rule still decides. */
    public function test_a_submit_with_no_signed_field_follows_the_referrer(): void
    {
        // Submit from a page of the other site with no signed field
        $other = $this->otherSite();
        $this->post('/!/forms/msf_host_email', ['message' => 'hi'], ['referer' => (string) $other->absoluteUrl()])->assertRedirect();

        // Expect the email composed for the referrer's site
        $this->assertSame([RequestSite::handle($other)], $this->emailedSites());
    }

    /**
     * The first configured site that is not the default.
     *
     * @return SiteObject The site.
     */
    private function otherSite(): SiteObject
    {
        // Pick a site other than the default one
        $default = RequestSite::handle(Site::default());
        foreach (Site::all() as $site) {
            if ($site instanceof SiteObject && RequestSite::handle($site) !== $default) {
                return $site;
            }
        }

        $this->fail('The host defines only one site.');
    }

    /**
     * The handles of the sites the sent emails were composed for.
     *
     * @return list<string> One handle per email sent.
     */
    private function emailedSites(): array
    {
        // Read the site off each email that was sent
        $handles = [];
        foreach (Mail::sent(Email::class) as $email) {
            if ($email instanceof Email && $email->getSite() instanceof SiteObject) {
                $handles[] = RequestSite::handle($email->getSite());
            }
        }

        return $handles;
    }
}
