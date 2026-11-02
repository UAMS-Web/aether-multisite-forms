<?php

declare(strict_types=1);

use UAMSWeb\Aether\Forms\Multisite\Tests\TestCase;

/**
 * Each parallel worker compiles Blade views into its own directory, so workers
 * never rename over a compiled view another worker has open (#216).
 *
 * @command  vendor/bin/pest tests/Integration/ParallelViewsTest.php
 */
test('each worker token gets its own compiled-view directory', function (): void {
    expect(TestCase::compiledViewPath('3'))->toEndWith('/views-3')
        ->and(TestCase::compiledViewPath(7))->toEndWith('/views-7')
        ->and(TestCase::compiledViewPath('3'))->not->toBe(TestCase::compiledViewPath('4'));
});

test('a run with no worker token keeps the shared directory', function (): void {
    expect(TestCase::compiledViewPath(null))->toBeNull()
        ->and(TestCase::compiledViewPath(false))->toBeNull();
});

test('the application in this run compiles views into the directory for its own token', function (): void {
    // Work out this run's directory, falling back to the shared one
    $expected = TestCase::compiledViewPath($_SERVER['TEST_TOKEN'] ?? null) ?? storage_path('framework/views');

    // Expect the Blade compiler to write there, whichever separator each path uses
    $normalize = static fn (string $path): string => str_replace('\\', '/', $path);
    expect($normalize((string) config('view.compiled')))->toBe($normalize($expected))
        ->and($normalize(app('blade.compiler')->getCompiledPath('x')))->toStartWith($normalize($expected));
});
