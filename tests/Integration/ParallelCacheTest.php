<?php

declare(strict_types=1);

use UAMSWeb\Aether\Forms\Multisite\Tests\TestCase;

/**
 * Each parallel worker gets its own license-check cache, so workers never share
 * the lock file Statamic takes on every Control Panel page (#126).
 *
 * @command  vendor/bin/pest tests/Integration/ParallelCacheTest.php
 */
test('each worker token gets its own license-check cache directory', function (): void {
    expect(TestCase::outpostCachePath('3'))->toBe(storage_path('framework/cache/outpost-data-3'))
        ->and(TestCase::outpostCachePath(7))->toBe(storage_path('framework/cache/outpost-data-7'));
});

test('a run with no worker token keeps the shared directory', function (): void {
    expect(TestCase::outpostCachePath(null))->toBeNull()
        ->and(TestCase::outpostCachePath(false))->toBeNull();
});

test('the application in this run uses the directory for its own token', function (): void {
    // Work out this run's directory, falling back to the shared one
    $expected = TestCase::outpostCachePath($_SERVER['TEST_TOKEN'] ?? null) ?? storage_path('framework/cache/outpost-data');

    // Expect the outpost cache store to point at it
    expect(config('cache.stores.outpost.path'))->toBe($expected);
});
