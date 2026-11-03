<?php

use App\Models\Link;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function aggregate(Link $link, string $day, string $referrer, string $device, int $clicks): void
{
    DB::table('link_daily_stats')->insert([
        'link_id' => $link->id, 'day' => $day, 'referrer_host' => $referrer, 'device' => $device, 'clicks' => $clicks,
    ]);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-03 12:00:00'));
    $this->link = Link::factory()->create(['code' => 'abc1234']);
    $this->headers = ['Authorization' => 'Bearer test-token'];
});

it('returns a dense daily series with referrers and devices', function () {
    aggregate($this->link, '2026-11-01', 'news.example.net', 'mobile', 5);
    aggregate($this->link, '2026-11-01', '', 'desktop', 2);
    aggregate($this->link, '2026-11-03', 'social.example.com', 'mobile', 1);
    aggregate($this->link, '2026-10-01', 'news.example.net', 'mobile', 100); // outside the window
    aggregate(Link::factory()->create(), '2026-11-02', '', 'desktop', 50);    // another link

    $this->getJson('/api/links/abc1234/stats?days=3', $this->headers)
        ->assertOk()
        ->assertExactJson([
            'data' => [
                'code' => 'abc1234',
                'period' => ['from' => '2026-11-01', 'to' => '2026-11-03', 'days' => 3],
                'total_clicks' => 8,
                'daily' => [
                    ['date' => '2026-11-01', 'clicks' => 7],
                    ['date' => '2026-11-02', 'clicks' => 0],
                    ['date' => '2026-11-03', 'clicks' => 1],
                ],
                'top_referrers' => [
                    ['host' => 'news.example.net', 'clicks' => 5],
                    ['host' => null, 'clicks' => 2],
                    ['host' => 'social.example.com', 'clicks' => 1],
                ],
                'devices' => ['mobile' => 6, 'desktop' => 2],
            ],
            'meta' => ['aggregation_interval_minutes' => 5],
        ]);
});

it('defaults to the last 30 days', function () {
    $this->getJson('/api/links/abc1234/stats', $this->headers)
        ->assertOk()
        ->assertJsonPath('data.period', ['from' => '2026-10-05', 'to' => '2026-11-03', 'days' => 30])
        ->assertJsonCount(30, 'data.daily');
});

it('validates the number of days', function (mixed $days) {
    $this->getJson("/api/links/abc1234/stats?days={$days}", $this->headers)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('days');
})->with([0, 91, 'abc']);

it('requires the API token', function () {
    $this->getJson('/api/links/abc1234/stats')->assertUnauthorized();
});

it('counts clicks end to end: redirect, rollup, stats', function () {
    $this->get('/abc1234', ['Referer' => 'https://news.example.net/a'])->assertStatus(302);
    $this->get('/abc1234')->assertStatus(302);

    $this->artisan('stats:rollup')->assertSuccessful();

    $this->getJson('/api/links/abc1234/stats?days=1', $this->headers)
        ->assertOk()
        ->assertJsonPath('data.total_clicks', 2)
        ->assertJsonPath('data.top_referrers.0.clicks', 1);
});
