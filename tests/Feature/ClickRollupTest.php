<?php

use App\Enums\Device;
use App\Models\Click;
use App\Models\Link;
use App\Services\ClickRollup;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;

function dailyStats(): array
{
    return DB::table('link_daily_stats')
        ->orderBy('day')->orderBy('referrer_host')->orderBy('device')
        ->get(['day', 'referrer_host', 'device', 'clicks'])
        ->map(fn ($row) => (array) $row)
        ->all();
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-03 12:00:00'));
    $this->link = Link::factory()->create();
});

it('aggregates clicks per day, referrer and device', function () {
    $clicks = Click::factory()->for($this->link);
    $clicks->count(3)->create(['clicked_at' => '2026-11-02 23:59:59', 'referrer_host' => 'news.example.net', 'device' => Device::Mobile]);
    $clicks->create(['clicked_at' => '2026-11-03 00:00:00', 'device' => Device::Desktop]);
    $clicks->count(2)->create(['clicked_at' => '2026-11-03 11:00:00', 'device' => Device::Desktop]);

    $this->artisan('stats:rollup')->assertSuccessful();

    expect(dailyStats())->toBe([
        ['day' => '2026-11-02', 'referrer_host' => 'news.example.net', 'device' => 'mobile', 'clicks' => 3],
        ['day' => '2026-11-03', 'referrer_host' => '', 'device' => 'desktop', 'clicks' => 3],
    ]);
});

it('is idempotent and picks up new clicks on the next run', function () {
    Click::factory()->for($this->link)->count(2)->create();

    $this->artisan('stats:rollup')->assertSuccessful();
    $this->artisan('stats:rollup')->assertSuccessful();
    expect(dailyStats()[0]['clicks'])->toBe(2);

    Click::factory()->for($this->link)->create();
    $this->artisan('stats:rollup')->assertSuccessful();
    expect(dailyStats())->toHaveCount(1)
        ->and(dailyStats()[0]['clicks'])->toBe(3);
});

it('only rebuilds whole days inside the requested window', function () {
    Click::factory()->for($this->link)->create(['clicked_at' => '2026-11-01 23:59:59']);
    Click::factory()->for($this->link)->create(['clicked_at' => '2026-11-02 00:00:00']);

    // Default window: yesterday and today.
    $this->artisan('stats:rollup')->assertSuccessful();
    expect(array_column(dailyStats(), 'day'))->toBe(['2026-11-02']);

    $this->artisan('stats:rollup', ['--days' => 3])->assertSuccessful();
    expect(array_column(dailyStats(), 'day'))->toBe(['2026-11-01', '2026-11-02']);
});

it('refuses windows larger than the click retention', function (string $days) {
    config(['shortlinks.click_retention_days' => 90]);

    $this->artisan('stats:rollup', ['--days' => $days])->assertExitCode(2);
})->with(['0', '91', 'abc']);

it('returns the number of groups written', function () {
    Click::factory()->for($this->link)->create(['device' => Device::Mobile]);
    Click::factory()->for($this->link)->create(['device' => Device::Desktop]);

    $today = CarbonImmutable::now();

    expect(app(ClickRollup::class)->rollup($today, $today))->toBe(2);
});

it('prunes raw clicks past the retention window but keeps the aggregates', function () {
    config(['shortlinks.click_retention_days' => 90]);
    Click::factory()->for($this->link)->create(['clicked_at' => '2026-08-04 23:59:59']); // 91 days ago: pruned
    Click::factory()->for($this->link)->create(['clicked_at' => '2026-08-05 00:00:00']); // kept
    DB::table('link_daily_stats')->insert([
        'link_id' => $this->link->id, 'day' => '2026-08-04', 'referrer_host' => '', 'device' => 'desktop', 'clicks' => 1,
    ]);

    $this->artisan('model:prune', ['--model' => [Click::class]])->assertSuccessful();

    expect(Click::query()->pluck('clicked_at')->map->toDateTimeString()->all())->toBe(['2026-08-05 00:00:00'])
        ->and(dailyStats())->toHaveCount(1);
});

it('schedules the rollup and the pruning', function () {
    $events = collect(app(Schedule::class)->events())
        ->mapWithKeys(fn ($event) => [$event->command => $event->expression]);

    expect($events->first(fn ($expression, $command) => str_contains($command, 'stats:rollup')))->toBe('*/5 * * * *')
        ->and($events->first(fn ($expression, $command) => str_contains($command, 'model:prune')))->toBe('15 3 * * *');
});
