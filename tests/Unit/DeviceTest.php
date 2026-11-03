<?php

use App\Enums\Device;

it('classifies user agents', function (?string $userAgent, Device $expected) {
    expect(Device::fromUserAgent($userAgent))->toBe($expected);
})->with([
    'missing' => [null, Device::Unknown],
    'blank' => ['  ', Device::Unknown],
    'desktop chrome' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36', Device::Desktop],
    'iphone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Mobile/15E148', Device::Mobile],
    'android phone' => ['Mozilla/5.0 (Linux; Android 15; Pixel 9) Chrome/130.0 Mobile Safari/537.36', Device::Mobile],
    'android tablet' => ['Mozilla/5.0 (Linux; Android 15; SM-X710) Chrome/130.0 Safari/537.36', Device::Tablet],
    'ipad' => ['Mozilla/5.0 (iPad; CPU OS 18_0 like Mac OS X) Mobile/15E148', Device::Tablet],
    'googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', Device::Bot],
    'slack preview' => ['Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)', Device::Bot],
    'curl' => ['curl/8.10.1', Device::Bot],
]);
