<?php

use App\Models\Click;
use App\Models\Link;
use App\Services\ClickRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

const IPHONE_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148';

it('redirects to the target and records the click', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-03 14:30:00'));
    $link = Link::factory()->create(['code' => 'abc1234', 'target_url' => 'https://example.org/landing']);

    $this->get('/abc1234', ['Referer' => 'https://www.news.example.net/article?id=42', 'User-Agent' => IPHONE_UA])
        ->assertStatus(302)
        ->assertRedirect('https://example.org/landing')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('X-Robots-Tag', 'noindex');

    $this->assertDatabaseHas('clicks', [
        'link_id' => $link->id,
        'clicked_at' => '2026-11-03 14:30:00',
        'referrer_host' => 'news.example.net',
        'device' => 'mobile',
    ]);
});

it('records direct traffic without a referrer', function () {
    Link::factory()->create(['code' => 'abc1234']);

    $this->get('/abc1234')->assertStatus(302);

    expect(Click::query()->sole()->referrer_host)->toBeNull();
});

it('discards forged referrers instead of failing the insert', function () {
    Link::factory()->create(['code' => 'abc1234']);

    $this->get('/abc1234', ['Referer' => "https://exämple.org/\u{1F600}"])->assertStatus(302);

    expect(Click::query()->sole()->referrer_host)->toBeNull();
});

it('does not count HEAD requests as clicks', function () {
    Link::factory()->create(['code' => 'abc1234']);

    $this->call('HEAD', '/abc1234')->assertStatus(302);

    expect(Click::query()->count())->toBe(0);
});

it('returns 404 for unknown codes', function () {
    $this->get('/nothing-here')
        ->assertNotFound()
        ->assertJsonPath('message', 'Link not found.');
});

it('returns 410 for expired or disabled links and records nothing', function (string $state) {
    Link::factory()->{$state}()->create(['code' => 'abc1234']);

    $this->get('/abc1234')->assertStatus(410);

    expect(Click::query()->count())->toBe(0);
})->with(['expired', 'disabled']);

it('still redirects when the click cannot be stored', function () {
    Link::factory()->create(['code' => 'abc1234', 'target_url' => 'https://example.org']);
    $this->mock(ClickRecorder::class)->shouldReceive('record')->once()->andReturnFalse();

    $this->get('/abc1234')->assertRedirect('https://example.org');
});

it('reports and swallows database errors while recording a click', function () {
    // A link that does not exist in the database: the foreign key rejects the INSERT.
    $ghost = (new Link)->forceFill(['id' => 999_999]);

    $stored = app(ClickRecorder::class)->record($ghost, Request::create('/x'), CarbonImmutable::now());

    expect($stored)->toBeFalse();
});

it('does not let the catch-all route shadow the API', function () {
    $this->getJson('/api/links/abc1234')->assertUnauthorized();
});
