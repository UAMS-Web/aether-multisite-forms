<?php

declare(strict_types=1);

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Statamic\Events\FormBlueprintFound;
use Statamic\Exceptions\NotFoundHttpException;
use Statamic\Facades\Antlers;
use Statamic\Facades\Blink;
use Statamic\Facades\Site;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Value;
use Statamic\Fields\Values;
use Statamic\Forms\Form;
use Statamic\Forms\RenderableField;
use Statamic\Forms\Tags as CoreFormTags;
use Statamic\Sites\Site as SiteObject;
use Statamic\Statamic;
use Statamic\Tags\FluentTag;
use Statamic\Tags\Loader;
use UAMSWeb\Aether\Forms\Multisite\Forms\NotAssigned;
use UAMSWeb\Aether\Forms\Multisite\Forms\SiteForm;
use UAMSWeb\Aether\Forms\Multisite\Sites\RequestSite;
use UAMSWeb\Aether\Forms\Multisite\Tags\FormTags;

/**
 * The `{{ form }}` tags render a form for the page's site, and follow the
 * `not_assigned` setting for a form not assigned to it (#73).
 *
 * @command  vendor/bin/pest tests/Integration/Tags/FormTagsTest.php
 */
beforeEach(function (): void {
    // Configure two sites and a temporary forms directory
    useTwoSites();
    useTemporaryFormsDirectory();

    // Render on the English site unless a test says otherwise
    Site::setCurrent('en');
});

afterEach(function (): void {
    removeFormsDirectory();
});

/**
 * Save a `contact` form assigned to the given sites, or global when given null.
 *
 * @param  array<array-key, mixed>|null  $sites  The sites value to store, or null for a global form.
 * @return Form The saved form.
 */
function taggedForm(?array $sites): Form
{
    // Build a contact form with a honeypot field
    $form = new Form;
    $form->handle('contact');
    $form->title('Contact');
    $form->honeypot('trap');

    // Assign the sites when the form is not global
    if ($sites !== null) {
        $form->set('sites', $sites);
    }

    // Save the form
    $form->save();

    return $form;
}

/**
 * Load the `form` tag the way Antlers does, for one of its methods. With no
 * parser, as through `Statamic::tag()->fetch()`, core's `create` returns data
 * rather than HTML.
 *
 * @param  string  $method  The tag method to load the tag for.
 * @param  array<string, mixed>  $params  Parameters given to the tag.
 * @param  array<string, mixed>  $context  Context the tag renders in.
 * @param  class-string  $expected  the tag class the handle must resolve to
 * @param  bool  $parsed  Whether to give the tag an Antlers parser.
 * @return CoreFormTags The loaded tag.
 *
 * @throws RuntimeException When the tag loads as another class.
 */
function formTag(string $method, array $params = ['in' => 'contact'], array $context = [], string $expected = FormTags::class, bool $parsed = false): CoreFormTags
{
    // Load the tag through Statamic's tag loader
    $tag = app(Loader::class)->load('form', [
        'parser' => $parsed ? Antlers::parser() : null,
        'params' => $params,
        'content' => '',
        'context' => $context,
        'tag' => "form:{$method}",
        'tag_method' => $method,
    ]);

    // Check that the handle resolved to the expected class
    if (! $tag instanceof $expected || ! $tag instanceof CoreFormTags) {
        throw new RuntimeException("The form tag did not load as [{$expected}].");
    }

    return $tag;
}

/**
 * Core's own form tags, loaded directly, for comparison.
 *
 * @param  array<string, mixed>  $params  Parameters given to the tag.
 * @return CoreFormTags Core's tag, set up for `form:create`.
 */
function coreFormTag(array $params = ['in' => 'contact']): CoreFormTags
{
    // Build core's tag and give it the properties Antlers would
    $tag = new CoreFormTags;
    $tag->setProperties([
        'parser' => null,
        'params' => $params,
        'content' => '',
        'context' => [],
        'tag' => 'form:create',
        'tag_method' => 'create',
    ]);

    return $tag;
}

/**
 * The tag's data with each renderable field replaced by the HTML it renders, which
 * is what a template outputs. The field objects themselves differ by design: each
 * carries the site's view of the form rather than the base form.
 *
 * @param  mixed  $data  The data a form tag returned.
 * @return array<array-key, mixed> The data with every field rendered to HTML.
 *
 * @throws RuntimeException When the data is not an array.
 */
function rendered(mixed $data): array
{
    // Walk the data, rendering each field it holds
    $walk = function (mixed $value) use (&$walk): mixed {
        // Recurse into arrays
        if (is_array($value)) {
            return array_map($walk, $value);
        }

        // Render a field to HTML and keep anything else as it is
        return $value instanceof RenderableField ? $value->toHtml() : $value;
    };

    // Render the data, refusing anything that is not an array
    $walked = $walk($data);
    if (! is_array($walked)) {
        throw new RuntimeException('The form tag did not return data.');
    }

    return $walked;
}

/**
 * Collect the log records written from here on.
 *
 * @return ArrayObject<int, MessageLogged> Log records, filled as they are logged.
 */
function loggedRecords(): ArrayObject
{
    // Start an empty collection the listener fills
    /** @var ArrayObject<int, MessageLogged> $records */
    $records = new ArrayObject;

    // Listen for every log message
    Event::listen(MessageLogged::class, function (MessageLogged $event) use ($records): void {
        $records[] = $event;
    });

    return $records;
}

it("replaces core's form tags under the form handle", function (): void {
    expect(formTag('create'))->toBeInstanceOf(FormTags::class);
});

it("reads the not_assigned setting from the add-on's config, defaulting to empty", function (): void {
    expect(config(NotAssigned::CONFIG_KEY))->toBe('empty')
        ->and(NotAssigned::fromConfig())->toBe(NotAssigned::Empty);
});

it('renders an assigned form, or one with no sites, as core renders it', function (?array $sites): void {
    // Save the form with the given sites
    taggedForm($sites);

    // Give the form a required text field whenever its blueprint is found
    Event::listen(FormBlueprintFound::class, function (FormBlueprintFound $event): void {
        // Check that the event carried a blueprint
        $blueprint = $event->blueprint;
        if (! $blueprint instanceof Blueprint) {
            throw new RuntimeException('The form blueprint event carried no blueprint.');
        }

        // Replace its contents with one required name field
        $blueprint->setContents(['tabs' => ['main' => ['sections' => [['fields' => [
            ['handle' => 'name', 'field' => ['type' => 'text', 'validate' => 'required']],
        ]]]]]]);
    });
    $params = ['in' => 'contact', 'js' => 'alpine'];

    // Render through our tag, then through core's with a clean cache
    $ours = formTag('create', $params)->create();
    Blink::flush();
    $core = coreFormTag($params)->create();

    // Ours adds one parameter to core's: the page's site, signed
    $ours = rendered($ours);
    $params = $ours['params'] ?? null;
    $params = is_array($params) ? $params : [];
    $signed = $params[RequestSite::FIELD] ?? null;
    expect($signed)->toBeString()
        ->and(Crypt::decryptString(is_string($signed) ? $signed : ''))->toBe('en');
    $ours['params'] = array_diff_key($params, [RequestSite::FIELD => true]);

    // With no parser, core's create returns the data a template would receive
    expect(rendered($ours))->toEqual(rendered($core))
        ->and(data_get(rendered($ours), 'fields.0.field'))->toContain('name="name"')
        ->and(data_get($ours, 'honeypot'))->toBe('trap')
        ->and(data_get($ours, 'fields.0.handle'))->toBe('name')
        ->and(data_get($ours, 'js_driver'))->toBe('alpine');
})->with([
    "assigned to the page's site" => [['en']],
    'no sites' => [null],
]);

it("hands core the read-only view of the form for the page's site", function (): void {
    // Save a form on both sites and render on Spanish
    taggedForm(['en', 'es']);
    Site::setCurrent('es');

    // Read the form the tag resolves
    $tag = formTag('create');
    $form = new ReflectionMethod($tag, 'form')->invoke($tag);

    // Expect the Spanish site view of the form
    expect($form)->toBeInstanceOf(SiteForm::class)
        ->and($form instanceof SiteForm ? $form->site() : null)->toBe('es');
});

it('renders nothing from every form tag for an unassigned form under empty', function (string $method, mixed $nothing): void {
    // Save an English form and render on Spanish
    taggedForm(['en']);
    Site::setCurrent('es');

    // Expect the method's empty value
    expect(formTag($method, parsed: true)->{$method}())->toBe($nothing);
})->with([
    'create' => ['create', ''],
    'errors' => ['errors', false],
    'success' => ['success', false],
    'submission' => ['submission', null],
    'submissions' => ['submissions', []],
]);

it('returns an empty array from create fetched with no parser for an unassigned form (#89)', function (): void {
    // Save an English form and render on Spanish
    taggedForm(['en']);
    Site::setCurrent('es');

    // Expect an empty array from create
    expect(formTag('create')->create())->toBe([]);
});

it('returns the HTML core renders from create with a parser for an assigned form', function (): void {
    // Save an English form
    taggedForm(['en']);

    // Expect create to render a form element
    expect(formTag('create', parsed: true)->create())->toContain('<form');
});

it("carries the page's site in a signed hidden field, rendered and fetched", function (): void {
    // Save a form on both sites and render on the Spanish one
    taggedForm(['en', 'es']);
    Site::setCurrent('es');

    // Expect the rendered form to carry the field, right after its opening tag
    $html = formTag('create', parsed: true)->create();
    expect(preg_match('/<form[^>]*>.*?<input type="hidden" name="_site" value="([^"]+)" \/>/s', $html, $match))->toBe(1)
        ->and(Crypt::decryptString(html_entity_decode($match[1] ?? '')))->toBe('es');

    // Expect the fetched data to carry it among core's parameters
    $signed = data_get(rendered(formTag('create')->create()), 'params.'.RequestSite::FIELD);
    expect($signed)->toBeString()
        ->and(Crypt::decryptString(is_string($signed) ? $signed : ''))->toBe('es');
});

it('returns the submission core flashed for an assigned form, and nothing before a submit', function (): void {
    // Save an English form
    $form = taggedForm(['en']);

    // Expect no submission before a submit
    expect(formTag('submission')->submission())->toBeNull();

    // Make a submission and flash it as core does after a submit
    $submission = $form->makeSubmission();

    session()->put('form.contact.success', 'Submission successful.');
    session()->put('submission', $submission);

    // Core exposes the blueprint's fields, plus `id` and `date`; this form has none
    expect(data_get(formTag('submission')->submission(), 'id'))->toBe($submission->id());
});

it('renders nothing through the wildcard form of the tag', function (): void {
    // Save an English form and render on Spanish
    taggedForm(['en']);
    Site::setCurrent('es');

    // Expect nothing with a parser and an empty array without one
    expect(formTag('contact', params: [], parsed: true)->__call('contact', []))->toBe('')
        ->and(formTag('contact', params: [])->__call('contact', []))->toBe([]);
});

it('logs one warning naming the form and the site, however often the form is rendered', function (): void {
    // Save an English form, render on Spanish, and collect log records
    taggedForm(['en']);
    Site::setCurrent('es');
    $records = loggedRecords();

    // Render the form through two tags
    formTag('create')->create();
    formTag('errors')->errors();

    // Keep only the warnings
    $warnings = array_values(array_filter($records->getArrayCopy(), fn (MessageLogged $record): bool => $record->level === 'warning'));

    // Expect one warning naming the form and the site
    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]->message)->toContain('not assigned')
        ->and($warnings[0]->context)->toBe(['form' => 'contact', 'site' => 'es']);
});

it('aborts with 404 for an unassigned form under 404', function (int|string $setting): void {
    // Set not_assigned to 404, save an English form, render on Spanish
    config([NotAssigned::CONFIG_KEY => $setting]);
    taggedForm(['en']);
    Site::setCurrent('es');

    // Render the form, which must abort
    formTag('create')->create();
})->with([
    'the string 404' => ['404'],
    'the integer 404' => [404],
])->throws(NotFoundHttpException::class, 'Form [contact] is not assigned to site [es].');

it('renders nothing rather than aborting again while the 404 page renders the same form', function (): void {
    // Set not_assigned to 404, save an English form, render on Spanish
    config([NotAssigned::CONFIG_KEY => '404']);
    taggedForm(['en']);
    Site::setCurrent('es');

    // Save a second English form the 404 page might also render
    $other = new Form;
    $other->handle('newsletter');
    $other->set('sites', ['en']);
    $other->save();

    // Expect the first render to abort and later renders to show nothing
    expect(fn (): mixed => formTag('create')->create())->toThrow(NotFoundHttpException::class)
        ->and(formTag('create')->create())->toBe([])
        ->and(formTag('create', ['in' => 'newsletter'])->create())->toBe([]);
});

it('leaves form:fields to core, which renders only what form:create hands it', function (): void {
    expect(new ReflectionMethod(FormTags::class, 'fields')->getDeclaringClass()->getName())->toBe(CoreFormTags::class);
});

it('gates a form passed in from context, which core would put in its cache directly', function (): void {
    // Save an English form and render on Spanish
    $form = taggedForm(['en']);
    Site::setCurrent('es');

    // Expect nothing when the form arrives through context
    expect(formTag('create', params: [], context: ['form' => $form])->create())->toBe([]);
});

it('gates the base form when the cache already holds a view for another site', function (): void {
    // Save an English form and cache its English view
    $form = taggedForm(['en']);
    Blink::put('form-contact', SiteForm::of($form, 'en'));

    // Render on Spanish
    Site::setCurrent('es');

    // Expect nothing despite the cached view
    expect(formTag('create')->create())->toBe([]);
});

it('leaves a missing form to core, which reports it', function (): void {
    formTag('create', params: ['in' => 'nope'])->create();
})->throws(Exception::class, 'Form with handle [nope] cannot be found.');

it('refuses a not_assigned value that is not a setting', function (mixed $value): void {
    // Configure the invalid value
    config([NotAssigned::CONFIG_KEY => $value]);

    // Read the setting, which must refuse it
    NotAssigned::fromConfig();
})->with([
    'an unknown word' => ['hide'],
    'a list' => [['empty']],
    'null' => [null],
])->throws(InvalidArgumentException::class, 'aether-multisite-forms.not_assigned');

/**
 * Load `form:assigned` as Antlers does: as a single tag or, with content, a pair.
 *
 * @param  array<string, mixed>  $params  Parameters given to the tag.
 * @param  string  $content  The pair's contents, or empty for a single tag.
 * @return FormTags The add-on's loaded tag.
 *
 * @throws RuntimeException When the tag does not load as the add-on's.
 */
function assignedTag(array $params = ['in' => 'contact'], string $content = ''): FormTags
{
    // Load the tag through Statamic's tag loader, with a parser
    $tag = app(Loader::class)->load('form', [
        'parser' => Antlers::parser(),
        'params' => $params,
        'content' => $content,
        'context' => [],
        'tag' => 'form:assigned',
        'tag_method' => 'assigned',
    ]);

    // Check that the handle resolved to the add-on's tag
    if (! $tag instanceof FormTags) {
        throw new RuntimeException("The form tag did not load as the add-on's.");
    }

    return $tag;
}

it("answers whether a form renders on the page's site (#60)", function (?array $sites, string $site, bool $expected): void {
    // Save the form and render on the given site
    taggedForm($sites);
    Site::setCurrent($site);

    // Expect the given answer
    expect(assignedTag()->assigned())->toBe($expected);
})->with([
    "assigned to the page's site" => [['en'], 'en', true],
    'assigned to another site' => [['en'], 'es', false],
    'no sites' => [null, 'es', true],
]);

it('answers false for a missing form rather than reporting it', function (): void {
    expect(assignedTag(['in' => 'nope'])->assigned())->toBeFalse();
});

it('neither aborts nor logs for an unassigned form, whatever not_assigned says', function (string $setting): void {
    // Configure the setting, save an English form, render on Spanish
    config([NotAssigned::CONFIG_KEY => $setting]);
    taggedForm(['en']);
    Site::setCurrent('es');

    // Collect log records
    $records = loggedRecords();

    // Expect false and nothing logged
    expect(assignedTag()->assigned())->toBeFalse()
        ->and($records->getArrayCopy())->toBe([]);
})->with(['empty', '404']);

it('renders its contents as a pair only when the form is assigned', function (): void {
    // Save an English form
    taggedForm(['en']);

    // With a parser, the pair renders through Antlers, which returns a Stringable
    $shown = assignedTag(content: '<h2>Contact us</h2>')->assigned();

    // Expect the contents on the English site
    expect($shown)->toBeInstanceOf(Stringable::class)
        ->and($shown instanceof Stringable ? (string) $shown : null)->toBe('<h2>Contact us</h2>');

    // Render on Spanish
    Site::setCurrent('es');

    // Expect nothing on the Spanish site
    expect(assignedTag(content: '<h2>Contact us</h2>')->assigned())->toBe('');
});

it('branches an Antlers template and a fetched tag on the assignment', function (): void {
    // Save an English form and a template that branches on it
    taggedForm(['en']);
    $template = '{{ if {form:assigned in="contact"} }}yes{{ else }}no{{ /if }}|{{ form:assigned in="contact" }}pair{{ /form:assigned }}';

    // Build a fetch of the tag through Statamic's fluent API
    $fetched = function (): mixed {
        // Check that Statamic returned a fluent tag
        $tag = Statamic::tag('form:assigned');
        if (! $tag instanceof FluentTag) {
            throw new RuntimeException('Statamic::tag() did not return a fluent tag.');
        }

        // Fetch the answer for the contact form
        return $tag->params(['in' => 'contact'])->fetch();
    };
    // Trusted, as a template is: Antlers leaves tags in untrusted content unevaluated
    $render = fn (): string => (string) Antlers::parse($template, [], true);

    // Expect both to say yes on the English site
    expect($render())->toBe('yes|pair')
        ->and($fetched())->toBeTrue();

    // Render on Spanish with a clean cache
    Site::setCurrent('es');
    Blink::flush();

    // Expect both to say no on the Spanish site
    expect($render())->toBe('no|')
        ->and($fetched())->toBeFalse();
});

/**
 * Every string in a tag's data, with augmented values unwrapped, so a template's
 * reach into `form_config` can be searched whatever its nesting.
 *
 * @param  mixed  $value  The data to search.
 * @return list<string> Every string found, depth first.
 */
function stringsIn(mixed $value): array
{
    // Unwrap augmented values to what they hold
    while ($value instanceof Value) {
        $value = $value->value();
    }

    // Reduce a site to its handle
    if ($value instanceof SiteObject) {
        $handle = $value->handle();

        return is_string($handle) ? [$handle] : [];
    }

    // Turn value bags and collections into arrays
    if ($value instanceof Values || $value instanceof Collection) {
        $value = $value->all();
    }

    // Collect the strings from each item of an array
    if (is_array($value)) {
        $strings = [];
        foreach ($value as $item) {
            array_push($strings, ...stringsIn($item));
        }

        return $strings;
    }

    // Keep a string and drop anything else
    return is_string($value) ? [$value] : [];
}

/**
 * The `form_config` a fetched `form:create` hands a template.
 *
 * @param  CoreFormTags  $tag  A form tag loaded for `form:create`.
 * @return mixed The tag's `form_config` value.
 */
function formConfig(CoreFormTags $tag): mixed
{
    // With no template, create() returns data, although its signature says string
    return data_get($tag->create(), 'form_config');
}

/**
 * Save a form shared by both sites whose `es` row overrides the recipient (#62).
 *
 * @return Form The saved form.
 */
function formWithSpanishRecipient(): Form
{
    // Save a form on both sites
    $form = taggedForm(['en', 'es']);

    // Give it a base recipient and a Spanish override row
    $form->email([['to' => 'base@example.com']]);
    $form->set('site_overrides', [['site' => 'es', 'title' => 'Contacto', 'email' => [['to' => 'es-only@example.com']]]]);

    // Save the overrides
    $form->save();

    return $form;
}

it("keeps another site's override rows out of form_config (#62)", function (): void {
    // Save the form with a Spanish recipient
    formWithSpanishRecipient();

    // Read form_config through our tag on the English site
    $config = formConfig(formTag('create'));

    // The control: core's tag builds form_config from the stored form, rows included
    expect(stringsIn(formConfig(coreFormTag())))->toContain('es-only@example.com')
        ->and(stringsIn($config))->not->toContain('es-only@example.com')
        ->and(stringsIn($config))->not->toContain('Contacto')
        ->and(stringsIn($config))->toContain('en', 'es');
});

it('keeps the override rows out of form_config on the site they belong to as well (#62)', function (): void {
    // Render on Spanish and save the form with a Spanish recipient
    Site::setCurrent('es');
    formWithSpanishRecipient();

    // Read form_config through our tag
    $config = formConfig(formTag('create'));

    // Expect core's control to carry the row and ours to leave it out
    expect(stringsIn(formConfig(coreFormTag())))->toContain('es-only@example.com')
        ->and(stringsIn($config))->not->toContain('es-only@example.com');
});
