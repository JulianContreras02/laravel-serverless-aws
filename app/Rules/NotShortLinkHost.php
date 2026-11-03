<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Config;

/**
 * Rejects target URLs on the shortener's own host, which would create redirect loops.
 */
class NotShortLinkHost implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $targetHost = parse_url($value, PHP_URL_HOST);
        $ownHost = parse_url(Config::string('shortlinks.short_url_base'), PHP_URL_HOST);

        if (is_string($targetHost) && is_string($ownHost) && strcasecmp($targetHost, $ownHost) === 0) {
            $fail('The :attribute cannot point to this link shortener.');
        }
    }
}
