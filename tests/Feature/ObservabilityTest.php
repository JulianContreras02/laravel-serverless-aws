<?php

use App\Models\Click;
use App\Models\Link;
use App\Services\ClickRecorder;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

it('returns API Gateway\'s request id and shares it with the logs', function () {
    $this->withServerVariables(['LAMBDA_REQUEST_CONTEXT' => json_encode(['requestId' => 'apigw-req-123'])])
        ->get('/health')
        ->assertHeader('X-Request-Id', 'apigw-req-123');

    expect(Context::get('request_id'))->toBe('apigw-req-123');
});

it('generates a request id outside API Gateway', function () {
    $id = $this->get('/health')->headers->get('X-Request-Id');

    expect($id)->toBeString()->toMatch('/^[0-9a-f-]{36}$/');
});

it('writes one structured access log line per request', function () {
    Log::spy();
    Link::factory()->create(['code' => 'abc1234']);

    $this->get('/abc1234');

    Log::shouldHaveReceived('info')->once()->withArgs(fn (string $message, array $context) => $message === 'HTTP request'
        && $context['method'] === 'GET'
        && $context['route'] === '{code}'
        && $context['status'] === 302
        && is_float($context['duration_ms']));
});

it('counts redirects without using the link code as a dimension', function () {
    $metrics = fakeMetrics();
    Link::factory()->create(['code' => 'abc1234']);

    $this->get('/abc1234');

    expect(metricNames($metrics))->toBe(['RedirectServed'])
        ->and($metrics[0]['link_code'])->toBe('abc1234')
        ->and($metrics[0]['_aws']['CloudWatchMetrics'][0]['Dimensions'])->toBe([['Environment']]);
});

it('counts clicks that could not be stored', function () {
    $metrics = fakeMetrics();
    Link::factory()->create(['code' => 'abc1234']);
    $this->mock(ClickRecorder::class)->shouldReceive('record')->andReturnFalse();

    $this->get('/abc1234')->assertStatus(302);

    expect(metricNames($metrics))->toBe(['ClickDropped', 'RedirectServed']);
});

it('counts created links', function () {
    $metrics = fakeMetrics();

    $this->postJson('/api/links', ['url' => 'https://example.org'], ['Authorization' => 'Bearer test-token'])
        ->assertCreated();

    expect(metricNames($metrics))->toBe(['LinkCreated']);
});

it('reports the rollup duration', function () {
    $metrics = fakeMetrics();
    Click::factory()->create();

    $this->artisan('stats:rollup')->assertSuccessful();

    expect(metricNames($metrics))->toBe(['RollupDuration'])
        ->and($metrics[0]['_aws']['CloudWatchMetrics'][0]['Metrics'][0]['Unit'])->toBe('Milliseconds')
        ->and($metrics[0]['groups'])->toBe(1);
});
