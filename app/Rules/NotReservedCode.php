<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Config;

/**
 * Rejects aliases that would shadow a route of this service (case-insensitive).
 */
class NotReservedCode implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        foreach (Config::array('shortlinks.reserved_codes') as $reserved) {
            if (is_string($reserved) && strcasecmp($reserved, $value) === 0) {
                $fail('The :attribute is reserved.');

                return;
            }
        }
    }
}
