<?php

namespace App\Support\Metrics;

use Closure;

/**
 * Custom metrics in CloudWatch Embedded Metric Format (EMF).
 *
 * Each metric is one JSON line on stderr. Lambda ships it to CloudWatch Logs,
 * which extracts the metric asynchronously: no SDK, no PutMetricData call and
 * no network egress from the VPC, so it works without a NAT gateway.
 */
class Metrics
{
    /**
     * @param  Closure(string): void  $write
     */
    public function __construct(
        private readonly bool $enabled,
        private readonly string $namespace,
        private readonly string $environment,
        private readonly Closure $write,
    ) {}

    public static function toStderr(bool $enabled, string $namespace, string $environment): self
    {
        return new self($enabled, $namespace, $environment, static function (string $line): void {
            file_put_contents('php://stderr', $line);
        });
    }

    /**
     * Properties become searchable fields in CloudWatch Logs Insights, not
     * dimensions: a link code as a dimension would create one billable metric per link.
     *
     * @param  array<string, scalar|null>  $properties
     */
    public function put(string $name, int|float $value, Unit $unit = Unit::Count, array $properties = []): void
    {
        if (! $this->enabled) {
            return;
        }

        $payload = [
            ...$properties,
            '_aws' => [
                'Timestamp' => (int) floor(microtime(true) * 1000),
                'CloudWatchMetrics' => [[
                    'Namespace' => $this->namespace,
                    'Dimensions' => [['Environment']],
                    'Metrics' => [['Name' => $name, 'Unit' => $unit->value]],
                ]],
            ],
            'Environment' => $this->environment,
            $name => $value,
        ];

        ($this->write)(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
    }
}
