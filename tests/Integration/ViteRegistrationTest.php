<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Request;
use Statamic\Statamic;
use UAMSWeb\Aether\Forms\Multisite\ServiceProvider;

/**
 * Statamic's registration of the add-on's Control Panel build: the provider's
 * `$vite` property reaches `Statamic::vite()` under the package name, and the
 * built files it publishes exist (#242).
 *
 * Under Testbench the add-on is the root package, so Statamic names it
 * `aether-multisite-forms`; installed in a host it is `uams-web/aether-multisite-forms`.
 * The assertions hold for both.
 *
 * @command  vendor/bin/pest tests/Integration/ViteRegistrationTest.php
 */
it('registers the Control Panel build with Statamic under the package name', function (): void {
    // Read what Statamic holds for every registered Vite build
    /** @var array<string, array{input: list<string>, buildDirectory: string}> $vites */
    $vites = Statamic::availableVites(Request::instance());

    // Keep the builds registered under this package's name
    $ours = [];
    foreach ($vites as $name => $vite) {
        if (str_ends_with($name, 'aether-multisite-forms')) {
            $ours[$name] = $vite;
        }
    }

    // Expect exactly one, with both entries and a build directory derived from its name
    expect($ours)->toHaveCount(1);

    $name = (string) array_key_first($ours);

    expect($ours[$name]['input'])->toBe(['resources/js/cp.js', 'resources/css/cp.css'])
        ->and($ours[$name]['buildDirectory'])->toBe('vendor/'.$name.'/build');
});

it('publishes the built Control Panel assets from a directory that exists', function (): void {
    // Read the paths published under the add-on's tag, with separators normalized for Windows
    $built = str_replace('\\', '/', (string) realpath(__DIR__.'/../../resources/dist/build'));
    $target = null;

    /** @var array<string, string> $published */
    $published = ServiceProvider::pathsToPublish(null, 'aether-multisite-forms');

    foreach ($published as $source => $destination) {
        if (str_replace('\\', '/', (string) realpath($source)) === $built) {
            $target = str_replace('\\', '/', $destination);
        }
    }

    // Expect the committed build directory published to a vendor path ending in /build/
    expect($built)->not->toBe('')
        ->and($target)->toEndWith('/aether-multisite-forms/build/');
});
