<?php

use App\Support\ReferrerHost;

it('normalizes referrers to a bare host', function (?string $header, ?string $expected) {
    expect(ReferrerHost::fromHeader($header))->toBe($expected);
})->with([
    'missing' => [null, null],
    'empty' => ['', null],
    'path and query dropped' => ['https://news.example.net/a/b?utm=1', 'news.example.net'],
    'www stripped' => ['https://www.example.org/', 'example.org'],
    'lowercased' => ['https://Social.Example.COM/post', 'social.example.com'],
    'punycode kept' => ['https://xn--exmple-cua.org/', 'xn--exmple-cua.org'],
    'unicode discarded' => ['https://exämple.org/', null],
    'not a url' => ['android-app://', null],
    'too long' => ['https://'.str_repeat('a', 300).'.org/', null],
]);
