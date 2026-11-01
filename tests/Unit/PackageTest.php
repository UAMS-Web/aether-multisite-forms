<?php

declare(strict_types=1);

use UAMSWeb\Aether\Forms\Multisite\ServiceProvider;

/**
 * `composer.json` registers the add-on's service provider for Laravel package
 * discovery, which is how a host application boots it.
 *
 * @command  vendor/bin/pest tests/Unit/PackageTest.php
 */
it('lists its service provider in composer.json so the host discovers it', function (): void {
    // Read composer.json and confirm it was readable
    $contents = file_get_contents(__DIR__.'/../../composer.json');
    expect($contents)->toBeString();

    // Decode the manifest
    /** @var array{extra: array{laravel: array{providers: list<string>}}} $manifest */
    $manifest = json_decode((string) $contents, true, flags: JSON_THROW_ON_ERROR);

    // Expect the provider among the discovered providers
    expect($manifest['extra']['laravel']['providers'])->toContain(ServiceProvider::class);
});

it('names the aether package and maps the aether namespace to src', function (): void {
    // Read and decode composer.json
    $contents = file_get_contents(__DIR__.'/../../composer.json');
    expect($contents)->toBeString();

    /** @var array{name: string, autoload: array{'psr-4': array<string, string>}} $manifest */
    $manifest = json_decode((string) $contents, true, flags: JSON_THROW_ON_ERROR);

    // Expect the renamed package, the new PSR-4 prefix, and a provider that autoloads from it
    expect($manifest['name'])->toBe('uams-web/aether-multisite-forms')
        ->and($manifest['autoload']['psr-4'])->toBe(['UAMSWeb\\Aether\\Forms\\Multisite\\' => 'src/'])
        ->and(class_exists(ServiceProvider::class))->toBeTrue()
        ->and(class_exists('UAMSWeb\\MultisiteForms\\ServiceProvider'))->toBeFalse();
});
