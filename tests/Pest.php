<?php

use App\Support\Metrics\Metrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/**
 * Captures the EMF metrics emitted during a test, decoded.
 *
 * @return ArrayObject<int, array<string, mixed>>
 */
function fakeMetrics(): ArrayObject
{
    $emitted = new ArrayObject;

    app()->instance(Metrics::class, new Metrics(
        true, 'Shortlinks', 'testing',
        function (string $line) use ($emitted): void {
            $emitted->append(json_decode($line, true, flags: JSON_THROW_ON_ERROR));
        },
    ));

    return $emitted;
}

/**
 * Names of the metrics in an EMF capture, in emission order.
 *
 * @param  ArrayObject<int, array<string, mixed>>  $emitted
 * @return list<string>
 */
function metricNames(ArrayObject $emitted): array
{
    return array_map(fn (array $line) => $line['_aws']['CloudWatchMetrics'][0]['Metrics'][0]['Name'], $emitted->getArrayCopy());
}
