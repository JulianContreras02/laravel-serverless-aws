<?php

use App\Support\Metrics\Metrics;
use App\Support\Metrics\Unit;

it('writes one Embedded Metric Format line per metric', function () {
    $lines = [];
    $metrics = new Metrics(true, 'Shortlinks', 'production', function (string $line) use (&$lines) {
        $lines[] = $line;
    });

    $metrics->put('RollupDuration', 12.5, Unit::Milliseconds, ['groups' => 3]);

    expect($lines)->toHaveCount(1)
        ->and($lines[0])->toEndWith(PHP_EOL);

    $emf = json_decode($lines[0], true);
    expect($emf['_aws']['CloudWatchMetrics'])->toBe([[
        'Namespace' => 'Shortlinks',
        'Dimensions' => [['Environment']],
        'Metrics' => [['Name' => 'RollupDuration', 'Unit' => 'Milliseconds']],
    ]])
        ->and($emf['_aws']['Timestamp'])->toBeInt()
        ->and($emf['Environment'])->toBe('production')
        ->and($emf['RollupDuration'])->toBe(12.5)
        // Extra properties are plain log fields, never dimensions.
        ->and($emf['groups'])->toBe(3);
});

it('does not let properties overwrite the metric or its dimension', function () {
    $lines = [];
    $metrics = new Metrics(true, 'Shortlinks', 'production', function (string $line) use (&$lines) {
        $lines[] = $line;
    });

    $metrics->put('LinkCreated', 1, properties: ['LinkCreated' => 99, 'Environment' => 'spoofed']);

    $emf = json_decode($lines[0], true);
    expect($emf['LinkCreated'])->toBe(1)
        ->and($emf['Environment'])->toBe('production');
});

it('emits nothing when disabled', function () {
    $metrics = new Metrics(false, 'Shortlinks', 'production', function () {
        throw new LogicException('should not write');
    });

    $metrics->put('LinkCreated', 1);
})->throwsNoExceptions();
