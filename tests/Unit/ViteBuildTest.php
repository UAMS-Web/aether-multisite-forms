<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use UAMSWeb\Aether\Forms\Multisite\ServiceProvider;

/**
 * The Control Panel build: the provider's `$vite` property, `vite.config.js`,
 * the entry sources and the committed manifest must all describe the same
 * files (#15).
 *
 * @command  vendor/bin/pest tests/Unit/ViteBuildTest.php
 */

/**
 * The provider's `$vite` property, which is protected and has no accessor. A
 * subclass reads it so the property's declared array shape carries through to
 * the caller.
 *
 * @return array{input: list<string>, publicDirectory: string} The provider's entry files and public directory.
 */
function viteBuildProperty(): array
{
    // Subclass the provider to expose its protected Vite configuration
    $probe = new class(new Application(viteBuildRoot())) extends ServiceProvider
    {
        /**
         * The provider's Vite configuration.
         *
         * @return array{input: list<string>, publicDirectory: string} The provider's `$vite` property, unchanged.
         */
        public function vite(): array
        {
            return $this->vite;
        }
    };

    return $probe->vite();
}

/**
 * The repository root.
 *
 * @return string Absolute path of the repository root.
 */
function viteBuildRoot(): string
{
    return dirname(__DIR__, 2);
}

it('points the provider at entry files that exist', function (): void {
    // Read the provider's entry files and expect at least one
    $vite = viteBuildProperty();

    expect($vite['input'])->not->toBeEmpty();

    // Expect every entry file on disk
    foreach ($vite['input'] as $entry) {
        expect(viteBuildRoot().'/'.$entry)->toBeFile();
    }
});

it('keeps vite.config.js and the provider on the same inputs and output directory', function (): void {
    // Read the provider's configuration and vite.config.js
    $vite = viteBuildProperty();
    $config = (string) file_get_contents(viteBuildRoot().'/vite.config.js');

    // Expect vite.config.js to name every provider entry
    foreach ($vite['input'] as $entry) {
        expect($config)->toContain("'".$entry."'");
    }

    // Expect vite.config.js to use the provider's public directory
    expect($config)->toContain("publicDirectory: '".$vite['publicDirectory']."'");
});

it('lists in the provider every input vite.config.js builds and every entry the manifest holds', function (): void {
    // Collect the provider's inputs in sorted order
    $vite = viteBuildProperty();
    $provided = $vite['input'];
    sort($provided);

    // The checks above iterate the provider's own list, so they cannot see an
    // entry removed from it.
    $config = (string) file_get_contents(viteBuildRoot().'/vite.config.js');
    // Find the input list in vite.config.js, or fail
    if (preg_match('/input:\s*\[([^\]]*)\]/', $config, $match) !== 1) {
        throw new RuntimeException('vite.config.js has no input list.');
    }

    // Extract the quoted inputs in sorted order
    preg_match_all("/'([^']+)'/", $match[1], $quoted);
    $configured = $quoted[1];
    sort($configured);

    // Collect the manifest's entry chunks in sorted order
    /** @var array<string, array{isEntry?: bool}> $manifest */
    $manifest = json_decode((string) file_get_contents(viteBuildRoot().'/'.$vite['publicDirectory'].'/build/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    $entries = array_keys(array_filter($manifest, fn (array $chunk): bool => $chunk['isEntry'] ?? false));
    sort($entries);

    // Expect all three lists to name the same two entries
    expect($configured)->toBe(['resources/css/cp.css', 'resources/js/cp.js'])
        ->and($provided)->toBe($configured)
        ->and($entries)->toBe($configured);
});

it('commits a manifest that lists every entry with a built file on disk', function (): void {
    // Locate the committed manifest and expect it on disk
    $vite = viteBuildProperty();
    $manifestPath = viteBuildRoot().'/'.$vite['publicDirectory'].'/build/manifest.json';

    expect($manifestPath)->toBeFile();

    // Decode the manifest
    /** @var array<string, array{file: string, isEntry?: bool}> $manifest */
    $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);

    // Check each provider entry against the manifest
    foreach ($vite['input'] as $entry) {
        // Expect the entry listed, marked as an entry, and built on disk
        expect($manifest)->toHaveKey($entry);
        expect($manifest[$entry]['isEntry'] ?? false)->toBeTrue();
        expect(viteBuildRoot().'/'.$vite['publicDirectory'].'/build/'.$manifest[$entry]['file'])->toBeFile();
    }
});

/**
 * The `Statamic.booting(...)` calls in a script that pass a function.
 *
 * @param  string  $script  The script's text.
 * @return array{calls: int, withFunction: int} The number of `Statamic.booting(` calls, and how many of them take an arrow function or a function expression.
 */
function viteBuildBootingCalls(string $script): array
{
    // Count every call, and the ones whose first argument is a function
    return [
        'calls' => substr_count($script, 'Statamic.booting('),
        'withFunction' => (int) preg_match_all('/Statamic\.booting\(\s*(?:async\s*)?(?:function\b|\([^)]*\)\s*=>|\w+\s*=>)/', $script),
    ];
}

it('keeps the booting call in the JS entry source and in its built file', function (): void {
    // Locate the JS entry's source and its built file through the manifest
    $vite = viteBuildProperty();
    $build = viteBuildRoot().'/'.$vite['publicDirectory'].'/build/';

    /** @var array<string, array{file: string}> $manifest */
    $manifest = json_decode((string) file_get_contents($build.'manifest.json'), true, flags: JSON_THROW_ON_ERROR);

    // Expect each to call Statamic.booting exactly once, with a function
    foreach ([viteBuildRoot().'/resources/js/cp.js', $build.$manifest['resources/js/cp.js']['file']] as $path) {
        expect(viteBuildBootingCalls((string) file_get_contents($path)))->toBe(['calls' => 1, 'withFunction' => 1]);
    }
});

it('recognizes a booting call that takes a function and one that does not', function (): void {
    // Control: the matcher accepts each function form and refuses a non-function argument and a missing call
    expect(viteBuildBootingCalls('Statamic.booting(() => {})'))->toBe(['calls' => 1, 'withFunction' => 1])
        ->and(viteBuildBootingCalls('Statamic.booting(function () {})'))->toBe(['calls' => 1, 'withFunction' => 1])
        ->and(viteBuildBootingCalls('Statamic.booting(boot)'))->toBe(['calls' => 1, 'withFunction' => 0])
        ->and(viteBuildBootingCalls('// Statamic.booting is called elsewhere'))->toBe(['calls' => 0, 'withFunction' => 0]);
});

it('builds each manifest entry to a file of its source type, inside assets/', function (): void {
    // Read the manifest
    $vite = viteBuildProperty();
    $build = viteBuildRoot().'/'.$vite['publicDirectory'].'/build/';

    /** @var array<string, array{file: string, src?: string}> $manifest */
    $manifest = json_decode((string) file_get_contents($build.'manifest.json'), true, flags: JSON_THROW_ON_ERROR);

    foreach ($vite['input'] as $entry) {
        // Expect the built file to carry its source's extension, under assets/ with no parent-directory segment
        $file = $manifest[$entry]['file'];

        expect(pathinfo($file, PATHINFO_EXTENSION))->toBe(pathinfo($entry, PATHINFO_EXTENSION))
            ->and($file)->toStartWith('assets/')
            ->and($file)->not->toContain('..')
            ->and($manifest[$entry]['src'] ?? null)->toBe($entry);
    }
});
