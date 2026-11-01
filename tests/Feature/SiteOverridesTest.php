<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Forms\Multisite\Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use ReflectionProperty;
use Statamic\Facades\Form;
use Statamic\Facades\Stache;
use Statamic\Forms\Email;
use Tests\TestCase;

/**
 * Host-bound: a site's `site_overrides` row reaches core's submit path, in a real
 * two-site host (#75).
 *
 * One form is shared by `en` and `es`, emails `base@example.com` and stores its
 * submissions; its `es` row emails `es@example.com` and stores nothing. A submit
 * from each site is read the way spike 5 read it: the email config core hands to
 * each mailable, which the faked mailer records before the mailable builds, and
 * the number of stored submissions. Form files and submissions go to a temporary
 * directory, so the host's own content is never written.
 *
 * @command  php artisan test vendor/uams-web/aether-multisite-forms/tests/Feature/SiteOverridesTest.php
 */
final class SiteOverridesTest extends TestCase
{
    /**
     * The submit action of the form under test.
     */
    private const string ACTION = '/!/forms/msf_host_overrides';

    /**
     * The temporary directory this test's forms and submissions live in.
     *
     * @var string Absolute path, unique to this test.
     */
    private string $directory;

    /**
     * Boot the host, point forms and submissions at a temporary directory, save
     * the shared form with its `es` row, and fake the mailer.
     */
    protected function setUp(): void
    {
        // Boot the host application
        parent::setUp();

        // Point form files at a unique temporary directory
        $this->directory = sys_get_temp_dir().'/msf-host-overrides-'.uniqid('', true);
        config(['statamic.forms.forms' => $this->directory]);
        // Store submissions beneath it too
        Stache::store('form-submissions')->directory($this->directory.'/_submissions');

        // Save a form shared by en and es, with an es row overriding email and store
        Form::make('msf_host_overrides')->title('Overrides')->store(true)
            ->email([['to' => 'base@example.com', 'subject' => 'Base']])
            ->set('sites', ['en', 'es'])
            ->set('site_overrides', [[
                'site' => 'es',
                'email' => [['to' => 'es@example.com', 'subject' => 'ES']],
                'store' => false,
            ]])
            ->save();

        // Record mailables instead of sending them
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

    /**
     * A submit from `en`, which has no row, emails the base recipient and is
     * stored.
     */
    public function test_a_submit_from_a_site_without_a_row_uses_the_base_values(): void
    {
        // Submit from an en page
        $this->post(self::ACTION, ['message' => 'en'], ['referer' => url('/contact')])->assertRedirect();

        // Expect the base recipient and one stored submission
        $this->assertSame(['base@example.com'], $this->recipients());
        $this->assertSame(1, Form::find('msf_host_overrides')->submissions()->count());
    }

    /** A submit from `es` emails only the row's recipient and is not stored. */
    public function test_a_submit_from_a_site_with_a_row_uses_its_overrides(): void
    {
        // Submit from an es page
        $this->post(self::ACTION, ['message' => 'es'], ['referer' => url('/es/contacto')])->assertRedirect();

        // Expect the row's recipient and nothing stored
        $this->assertSame(['es@example.com'], $this->recipients());
        $this->assertSame(0, Form::find('msf_host_overrides')->submissions()->count());
    }

    /**
     * The two sites together: one recipient each, one stored submission, and the
     * form file unchanged.
     */
    public function test_each_site_emails_its_own_recipient_and_only_en_stores(): void
    {
        // Submit once from an en page and once from an es page
        $this->post(self::ACTION, ['message' => 'en'], ['referer' => url('/contact')])->assertRedirect();
        $this->post(self::ACTION, ['message' => 'es'], ['referer' => url('/es/contacto')])->assertRedirect();

        // Expect each site's own recipient, in order
        $this->assertSame(['base@example.com', 'es@example.com'], $this->recipients());

        // Expect only the en submission stored
        $form = Form::find('msf_host_overrides');
        $this->assertSame(1, $form->submissions()->count());
        $this->assertSame('en', $form->submissions()->first()?->get('_site'));
        // Expect the saved form to keep its base email and store
        $this->assertSame([['to' => 'base@example.com', 'subject' => 'Base']], $form->email());
        $this->assertTrue($form->store());
    }

    /**
     * The `to` of each email config core handed to a mailable, sent or queued,
     * in order.
     *
     * @return list<string> The recipient of each recorded mailable.
     */
    private function recipients(): array
    {
        // Reach the email config each mailable holds
        $config = new ReflectionProperty(Email::class, 'config');
        // Collect the sent and queued form emails
        $mailables = Mail::sent(Email::class)->merge(Mail::queued(Email::class));

        // Read the recipient of each one
        return $mailables->map(fn (Email $mail): string => (string) $config->getValue($mail)['to'])->values()->all();
    }
}
