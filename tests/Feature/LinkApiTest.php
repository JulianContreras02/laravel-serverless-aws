<?php

use App\Models\Link;
use App\Services\ShortCodeGenerator;
use Carbon\CarbonImmutable;

function authHeaders(string $token = 'test-token'): array
{
    return ['Authorization' => 'Bearer '.$token];
}

describe('authentication', function () {
    it('rejects requests without a bearer token', function () {
        $this->postJson('/api/links', ['url' => 'https://example.org'])
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer');
    });

    it('rejects a wrong token', function () {
        $this->postJson('/api/links', ['url' => 'https://example.org'], authHeaders('nope'))
            ->assertUnauthorized();
    });

    it('authenticates before resolving the link, so codes cannot be probed', function () {
        $this->getJson('/api/links/does-not-exist')->assertUnauthorized();
    });

    it('fails closed when no token is configured', function () {
        config(['shortlinks.api_token' => null]);

        $this->postJson('/api/links', ['url' => 'https://example.org'], authHeaders(''))
            ->assertUnauthorized();
    });
});

describe('creating links', function () {
    it('creates a link with a generated base62 code', function () {
        $response = $this->postJson('/api/links', ['url' => 'https://example.org/some/page?x=1'], authHeaders());

        $response->assertCreated()
            ->assertJsonPath('data.target_url', 'https://example.org/some/page?x=1')
            ->assertJsonPath('data.expires_at', null)
            ->assertJsonPath('data.disabled_at', null);

        $code = $response->json('data.code');
        expect($code)->toMatch('/^[0-9A-Za-z]{7}$/')
            ->and($response->json('data.short_url'))->toBe("https://go.example.com/{$code}");

        $this->assertDatabaseHas('links', ['code' => $code]);
    });

    it('accepts a custom alias and an expiry date', function () {
        $expiresAt = CarbonImmutable::now()->addDays(7)->startOfSecond();

        $this->postJson('/api/links', [
            'url' => 'https://example.org',
            'alias' => 'black-friday',
            'expires_at' => $expiresAt->toIso8601String(),
        ], authHeaders())
            ->assertCreated()
            ->assertJsonPath('data.code', 'black-friday')
            ->assertJsonPath('data.expires_at', $expiresAt->toIso8601ZuluString());
    });

    it('rejects an alias that is already taken', function () {
        Link::factory()->create(['code' => 'taken']);

        $this->postJson('/api/links', ['url' => 'https://example.org', 'alias' => 'taken'], authHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('alias');
    });

    it('rejects reserved aliases regardless of case', function (string $alias) {
        $this->postJson('/api/links', ['url' => 'https://example.org', 'alias' => $alias], authHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('alias');
    })->with(['health', 'Health', 'API']);

    it('rejects invalid targets', function (mixed $url) {
        $this->postJson('/api/links', ['url' => $url], authHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('url');
    })->with([
        'missing' => [null],
        'not a url' => ['not a url'],
        'ftp scheme' => ['ftp://example.org/file'],
        'javascript scheme' => ['javascript:alert(1)'],
        'too long' => ['https://example.org/'.str_repeat('a', 2048)],
        'redirect loop' => ['https://go.example.com/abc'],
    ]);

    it('rejects an expiry date in the past', function () {
        $this->postJson('/api/links', [
            'url' => 'https://example.org',
            'expires_at' => CarbonImmutable::now()->subMinute()->toIso8601String(),
        ], authHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expires_at');
    });

    it('retries when a generated code collides with an existing one', function () {
        Link::factory()->create(['code' => 'AAAAAAA']);

        $this->mock(ShortCodeGenerator::class)
            ->shouldReceive('generate')
            ->twice()
            ->andReturn('AAAAAAA', 'BBBBBBB');

        $this->postJson('/api/links', ['url' => 'https://example.org'], authHeaders())
            ->assertCreated()
            ->assertJsonPath('data.code', 'BBBBBBB');
    });
});

describe('reading and disabling links', function () {
    it('shows a link by its code', function () {
        $link = Link::factory()->create(['code' => 'abc1234']);

        $this->getJson('/api/links/abc1234', authHeaders())
            ->assertOk()
            ->assertJsonPath('data.code', 'abc1234')
            ->assertJsonPath('data.target_url', $link->target_url);
    });

    it('treats codes as case-sensitive', function () {
        Link::factory()->create(['code' => 'abc1234']);

        $this->getJson('/api/links/ABC1234', authHeaders())->assertNotFound();
    });

    it('returns 404 for unknown codes', function () {
        $this->getJson('/api/links/missing', authHeaders())->assertNotFound();
    });

    it('disables a link without deleting it, idempotently', function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-01 10:00:00'));
        $link = Link::factory()->create(['code' => 'abc1234']);

        $this->deleteJson('/api/links/abc1234', [], authHeaders())->assertNoContent();

        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00:00'));
        $this->deleteJson('/api/links/abc1234', [], authHeaders())->assertNoContent();

        expect($link->fresh()?->disabled_at?->toDateTimeString())->toBe('2026-11-01 10:00:00');
        $this->getJson('/api/links/abc1234', authHeaders())
            ->assertOk()
            ->assertJsonPath('data.disabled_at', '2026-11-01T10:00:00Z');
    });
});
