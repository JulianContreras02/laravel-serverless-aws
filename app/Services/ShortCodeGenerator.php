<?php

namespace App\Services;

class ShortCodeGenerator
{
    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    /**
     * Random base62 code. With 7 characters there are 62^7 (about 3.5 trillion)
     * combinations, so collisions are rare and handled by retrying the insert.
     */
    public function generate(int $length): string
    {
        $code = '';
        $maxIndex = strlen(self::ALPHABET) - 1;

        for ($i = 0; $i < $length; $i++) {
            $code .= self::ALPHABET[random_int(0, $maxIndex)];
        }

        return $code;
    }
}
