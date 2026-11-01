<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Forms\Multisite\Forms;

use Statamic\Facades\Form;
use UAMSWeb\Aether\Forms\Multisite\Access\AccessibleSites;
use UAMSWeb\Aether\Forms\Multisite\Sites\ConfiguredSites;

/**
 * The Sites section the add-on adds to every form's settings screen.
 *
 * It uses core's own extension point, `Form::appendConfigFields()`, so no
 * Control Panel view is replaced: core builds the section into the edit
 * blueprint, validates and processes it, and merges the value into the form's
 * data, which `Form::save()` writes to the form file. An empty selection
 * processes to null, which core leaves out of the file, so clearing the field
 * makes the form global again. A non-super user may save only a selection they
 * could still edit the form under (#118, `AccessibleSites`).
 *
 * The section's second field edits `site_overrides`, the rows `Overrides`
 * applies: a site, and a title, a store setting and email configs for it. Each
 * value can be left empty to keep the form's own. Store is a clearable choice
 * rather than core's toggle, because a toggle saves `false` when left alone and
 * a row added only for its title would then stop storing on that site.
 */
final class ConfigFields
{
    /** The section's display name; core keys the section by its snake case, `sites`. */
    public const string SECTION = 'Sites';

    /**
     * Add the Sites section to the settings screen of every form.
     */
    public static function register(): void
    {
        Form::appendConfigFields('*', self::SECTION, self::fields());
    }

    /**
     * The section's fields, keyed by handle, in the shape core's form settings use.
     *
     * @return array<string, array<string, mixed>> The sites and overrides fields.
     */
    public static function fields(): array
    {
        return [
            Assignment::KEY => [
                // No `display`: core labels the field with the title-cased handle, "Sites".
                'type' => 'sites',
                'instructions' => 'The sites this form belongs to. Leave it empty to use the form on every site, which only an editor who can access every site may do.',
                'mode' => 'select',
                'validate' => ['new '.ConfiguredSites::class, 'new '.AccessibleSites::class],
            ],
            Overrides::KEY => [
                'display' => 'Site overrides',
                'type' => 'grid',
                'mode' => 'stacked',
                'add_row' => 'Add site override',
                'instructions' => "Change the title, storing or email for one site. Anything a row leaves empty uses the form's own setting.",
                'validate' => ['new '.OverrideRows::class],
                'fields' => [
                    ['handle' => 'site', 'field' => [
                        'type' => 'sites',
                        'mode' => 'select',
                        'max_items' => 1,
                        'validate' => ['required'],
                    ]],
                    ['handle' => 'title', 'field' => [
                        // No `type`: a field without one is a text field.
                        'instructions' => "Leave empty to use the form's title.",
                    ]],
                    ['handle' => 'store', 'field' => [
                        'display' => 'Store Submissions',
                        'type' => 'button_group',
                        'options' => [Overrides::STORE => 'Store', Overrides::DO_NOT_STORE => "Don't store"],
                        'clearable' => true,
                        'instructions' => "Leave unset to use the form's setting.",
                    ]],
                    ['handle' => 'email', 'field' => [
                        'type' => 'grid',
                        'mode' => 'stacked',
                        'add_row' => __('Add Email'),
                        'instructions' => "Replaces the form's emails for this site. Leave empty to send the form's emails.",
                        'fields' => self::emailFields(),
                    ]],
                ],
            ],
        ];
    }

    /**
     * The columns of an email config, as core's form settings screen defines them.
     *
     * An overriding email replaces the form's list, so a row needs every column a
     * base email has, or a site would lose a template or a mailer the form uses.
     * Core defines these inside `FormsController::editFormBlueprint()`, which an
     * add-on cannot reuse, so they are mirrored here; a test compares them with
     * core's on every run.
     *
     * @return list<array{handle: string, field: array<string, mixed>}> The email config columns, in core's order.
     */
    public static function emailFields(): array
    {
        // Build the text, template and toggle field shapes core's columns use
        $text = fn (string $display, string $message, array $extra = []): array => [
            'type' => 'text',
            'display' => __($display),
            'instructions' => __('statamic::messages.'.$message),
            ...$extra,
        ];
        $template = fn (string $display, string $message): array => [
            'type' => 'template',
            'display' => __($display),
            'instructions' => __('statamic::messages.'.$message),
            'folder' => config('statamic.forms.email_view_folder'),
            'clearable' => true,
        ];
        $toggle = fn (string $display, string $message): array => [
            'type' => 'toggle',
            'display' => __($display),
            'instructions' => __('statamic::messages.'.$message),
        ];

        // Read the configured mailers, offered as the mailer column's options
        /** @var array<string, mixed> $mailers */
        $mailers = config('mail.mailers', []);

        // Core concatenates the address as it is, so an unset one shows as "()".
        $sender = config('mail.from.address');
        $sender = is_string($sender) ? $sender : '';

        // List the columns in core's order
        return [
            ['handle' => 'to', 'field' => $text('Recipient(s)', 'form_configure_email_to_instructions', ['validate' => ['required']])],
            ['handle' => 'cc', 'field' => $text('CC Recipient(s)', 'form_configure_email_cc_instructions')],
            ['handle' => 'bcc', 'field' => $text('BCC Recipient(s)', 'form_configure_email_bcc_instructions')],
            ['handle' => 'from', 'field' => [
                ...$text('Sender', 'form_configure_email_from_instructions'),
                'instructions' => __('statamic::messages.form_configure_email_from_instructions').' ('.$sender.').',
            ]],
            ['handle' => 'reply_to', 'field' => $text('Reply To', 'form_configure_email_reply_to_instructions')],
            ['handle' => 'subject', 'field' => $text('Subject', 'form_configure_email_subject_instructions')],
            ['handle' => 'html', 'field' => $template('HTML view', 'form_configure_email_html_instructions')],
            ['handle' => 'text', 'field' => $template('Text view', 'form_configure_email_text_instructions')],
            ['handle' => 'markdown', 'field' => $toggle('Markdown', 'form_configure_email_markdown_instructions')],
            ['handle' => 'attachments', 'field' => $toggle('Attachments', 'form_configure_email_attachments_instructions')],
            ['handle' => 'mailer', 'field' => [
                'type' => 'select',
                'instructions' => __('statamic::messages.form_configure_mailer_instructions'),
                'options' => array_keys($mailers),
                'clearable' => true,
            ]],
        ];
    }
}
