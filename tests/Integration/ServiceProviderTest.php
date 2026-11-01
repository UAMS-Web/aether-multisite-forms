<?php

declare(strict_types=1);

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use UAMSWeb\Aether\Forms\Multisite\Forms\NotAssigned;
use UAMSWeb\Aether\Forms\Multisite\ServiceProvider;

/**
 * The add-on's service provider registers, publishes its config, and reports an
 * invalid not_assigned setting when it boots.
 *
 * @command  vendor/bin/pest tests/Integration/ServiceProviderTest.php
 */
it('registers its service provider with the application', function (): void {
    expect(app()->getProvider(ServiceProvider::class))->toBeInstanceOf(ServiceProvider::class);
});

it('publishes its config file under the tag the README names', function (): void {
    // Read the paths published under the config tag
    $paths = ServiceProvider::pathsToPublish(null, 'aether-multisite-forms-config');

    // Expect the package config to publish to the app's config directory
    expect(array_map(realpath(...), array_keys($paths)))->toBe([realpath(__DIR__.'/../../config/aether-multisite-forms.php')])
        ->and(array_values($paths))->toBe([config_path('aether-multisite-forms.php')]);
});

/**
 * Boot the add-on again with the given not_assigned value, and return the messages
 * logged at error level while it booted.
 *
 * @param  mixed  $notAssigned  The not_assigned value to configure before booting.
 * @return list<string> Messages logged at error level during the boot.
 *
 * @throws RuntimeException When the add-on provider is not registered.
 */
function errorsLoggedBooting(mixed $notAssigned): array
{
    // Configure the not_assigned value under test
    config([NotAssigned::CONFIG_KEY => $notAssigned]);

    // Collect every message logged at error level
    /** @var list<string> $errors */
    $errors = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$errors): void {
        // Keep only error-level messages
        if ($event->level === 'error') {
            $errors[] = $event->message;
        }
    });

    // Find the registered add-on provider, refusing if it is missing
    $provider = app()->getProvider(ServiceProvider::class);
    if (! $provider instanceof ServiceProvider) {
        throw new RuntimeException('The add-on provider is not registered.');
    }

    // Boot the add-on again so it validates the setting
    $provider->bootAddon();

    return $errors;
}

it('logs an invalid not_assigned setting when the add-on boots (#90)', function (mixed $value, string $shown): void {
    // Boot with the invalid value
    $errors = errorsLoggedBooting($value);

    // Expect one error naming the key and the value as given
    expect($errors)->toHaveCount(1)
        ->and($errors[0])->toContain(NotAssigned::CONFIG_KEY)
        ->and($errors[0])->toContain($shown);
})->with([
    'an unknown word' => ['hide', "'hide'"],
    'null, as an unset env() gives' => [null, 'NULL'],
    'a list' => [['empty'], 'array'],
]);

it('logs nothing at boot for a valid not_assigned setting', function (int|string $value): void {
    expect(errorsLoggedBooting($value))->toBe([]);
})->with([
    'empty' => ['empty'],
    'the string 404' => ['404'],
    'the integer 404' => [404],
]);
