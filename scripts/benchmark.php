<?php

/**
 * Worst-case volume benchmark (local only, never deployed).
 *
 * Scenario: one viral link receives 90% of all clicks, and every click falls
 * inside the 2-day window that stats:rollup rebuilds. The table is filled in
 * steps (100k, 250k, 500k, 1M clicks) and, at each step, the script measures:
 *   - the database work of a redirect (link lookup + click INSERT),
 *   - the stats query on the daily aggregates (what the API does),
 *   - the same stats computed from raw clicks (the naive alternative),
 *   - the rollup of the 2-day window.
 *
 * Usage (wipes the target database):
 *   docker compose exec -e DB_DATABASE=testing app php scripts/benchmark.php
 */

use App\Models\Link;
use App\Services\ClickRecorder;
use App\Services\ClickRollup;
use App\Services\ClickStats;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ($app->isProduction() || DB::connection()->getDatabaseName() === 'shortlinks') {
    fwrite(STDERR, "Refusing to run: point DB_DATABASE at a disposable database (e.g. testing).\n");
    exit(1);
}

config(['shortlinks.metrics.enabled' => false]);

const STEPS = [100_000, 250_000, 500_000, 1_000_000];
const OTHER_LINKS = 1_000;
const INSERT_CHUNK = 5_000;

/** @return array{p50: float, p95: float} milliseconds */
function measure(int $iterations, Closure $work): array
{
    $samples = [];
    for ($i = 0; $i < $iterations; $i++) {
        $startedAt = hrtime(true);
        $work();
        $samples[] = (hrtime(true) - $startedAt) / 1_000_000;
    }
    sort($samples);

    $percentile = fn (float $p) => round($samples[(int) ceil($p * count($samples)) - 1], 2);

    return ['p50' => $percentile(0.50), 'p95' => $percentile(0.95)];
}

function seedClicks(int $count, int $viralId, int $firstOtherId, CarbonImmutable $now): void
{
    $referrers = array_merge([null, null, null], array_map(fn ($i) => "site{$i}.example.net", range(1, 47)));
    $devices = ['mobile', 'mobile', 'mobile', 'desktop', 'desktop', 'tablet', 'bot'];
    $windowSeconds = 2 * 86_400 - 1;
    $windowStart = $now->utc()->startOfDay()->subDay()->getTimestamp();

    for ($done = 0; $done < $count; $done += INSERT_CHUNK) {
        $rows = [];
        for ($i = 0, $n = min(INSERT_CHUNK, $count - $done); $i < $n; $i++) {
            $rows[] = [
                'link_id' => mt_rand(1, 10) <= 9 ? $viralId : $firstOtherId + mt_rand(0, OTHER_LINKS - 1),
                'clicked_at' => date('Y-m-d H:i:s', $windowStart + mt_rand(0, $windowSeconds)),
                'referrer_host' => $referrers[array_rand($referrers)],
                'device' => $devices[array_rand($devices)],
            ];
        }
        DB::table('clicks')->insert($rows);
    }
}

$now = CarbonImmutable::now();
Artisan::call('migrate:fresh', ['--force' => true]);

$viral = Link::query()->create(['code' => 'viral01', 'target_url' => 'https://example.org/launch']);
$firstOther = Link::factory()->count(OTHER_LINKS)->create()->first();
assert($firstOther instanceof Link);

$recorder = app(ClickRecorder::class);
$rollup = app(ClickRollup::class);
$stats = app(ClickStats::class);
$visit = Request::create('/viral01', 'GET', server: [
    'HTTP_REFERER' => 'https://site7.example.net/post',
    'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone) Mobile',
]);

$rows = [];
$seeded = 0;
foreach (STEPS as $target) {
    $startedAt = hrtime(true);
    seedClicks($target - $seeded, $viral->id, $firstOther->id, $now);
    $seedSeconds = round((hrtime(true) - $startedAt) / 1e9, 1);
    $seeded = $target;
    DB::statement('ANALYZE TABLE clicks');

    $startedAt = hrtime(true);
    $groups = $rollup->rollup($now->subDay(), $now);
    $rollupMs = round((hrtime(true) - $startedAt) / 1_000_000);

    $redirect = measure(500, function () use ($recorder, $visit, $now) {
        $link = Link::query()->where('code', 'viral01')->first(['id', 'code', 'target_url', 'expires_at', 'disabled_at']);
        assert($link instanceof Link);
        $recorder->record($link, $visit, $now);
    });

    $fromAggregates = measure(50, fn () => $stats->forLink($viral, 30, $now));

    $fromRawClicks = measure(5, fn () => DB::table('clicks')
        ->where('link_id', $viral->id)
        ->where('clicked_at', '>=', $now->subDays(29)->startOfDay()->toDateTimeString())
        ->selectRaw("DATE(clicked_at) AS day, COALESCE(referrer_host, '') AS ref, device, COUNT(*) AS clicks")
        ->groupByRaw("DATE(clicked_at), COALESCE(referrer_host, ''), device")
        ->get());

    $rows[] = [
        number_format($target), "{$seedSeconds} s", "{$rollupMs} ms ({$groups} groups)",
        "{$redirect['p50']} / {$redirect['p95']} ms",
        "{$fromAggregates['p50']} / {$fromAggregates['p95']} ms",
        "{$fromRawClicks['p50']} / {$fromRawClicks['p95']} ms",
    ];

    fwrite(STDERR, "done: {$target} clicks\n");
}

echo '| Clicks in window | Seed time | Rollup (2 days) | Redirect DB work p50 / p95 | Stats from aggregates p50 / p95 | Stats from raw clicks p50 / p95 |'.PHP_EOL;
echo '|---:|---:|---:|---:|---:|---:|'.PHP_EOL;
foreach ($rows as $row) {
    echo '| '.implode(' | ', $row).' |'.PHP_EOL;
}

$innodbBuffer = DB::selectOne("SHOW VARIABLES LIKE 'innodb_buffer_pool_size'");
$version = DB::selectOne('SELECT VERSION() AS v');
echo PHP_EOL.sprintf(
    'MySQL %s, innodb_buffer_pool_size = %d MB, PHP %s',
    is_object($version) ? $version->v : '?',
    is_object($innodbBuffer) ? ((int) $innodbBuffer->Value) / 1024 / 1024 : 0,
    PHP_VERSION,
).PHP_EOL;
