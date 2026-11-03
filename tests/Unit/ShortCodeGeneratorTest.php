<?php

use App\Services\ShortCodeGenerator;

it('generates base62 codes of the requested length', function (int $length) {
    $code = (new ShortCodeGenerator)->generate($length);

    expect($code)->toHaveLength($length)->toMatch('/^[0-9A-Za-z]+$/');
})->with([3, 7, 32]);

it('does not repeat codes in a large sample', function () {
    $generator = new ShortCodeGenerator;
    $codes = array_map(fn () => $generator->generate(7), range(1, 10_000));

    expect(array_unique($codes))->toHaveCount(10_000);
});
