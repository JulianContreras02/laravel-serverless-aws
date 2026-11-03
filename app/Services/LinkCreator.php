<?php

namespace App\Services;

use App\Models\Link;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class LinkCreator
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly ShortCodeGenerator $codes) {}

    /**
     * Uniqueness is enforced by the database index, not by a SELECT before the
     * INSERT: two Lambda instances can run the same check at the same time.
     *
     * @throws ValidationException when the requested alias is already taken
     */
    public function create(string $targetUrl, ?string $alias = null, ?CarbonImmutable $expiresAt = null): Link
    {
        if ($alias !== null) {
            return $this->tryInsert($alias, $targetUrl, $expiresAt)
                ?? throw ValidationException::withMessages(['alias' => 'The alias has already been taken.']);
        }

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $code = $this->codes->generate(Config::integer('shortlinks.code_length'));
            $link = $this->tryInsert($code, $targetUrl, $expiresAt);

            if ($link !== null) {
                return $link;
            }
        }

        throw new RuntimeException('Could not generate a unique short code after '.self::MAX_ATTEMPTS.' attempts.');
    }

    private function tryInsert(string $code, string $targetUrl, ?CarbonImmutable $expiresAt): ?Link
    {
        try {
            return Link::query()->create([
                'code' => $code,
                'target_url' => $targetUrl,
                'expires_at' => $expiresAt,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }
}
