<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\LinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;

/**
 * @property int $id
 * @property string $code
 * @property string $target_url
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $disabled_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable(['code', 'target_url', 'expires_at'])]
#[RouteKey('code')]
class Link extends Model
{
    /** @use HasFactory<LinkFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    public function isExpired(?CarbonImmutable $now = null): bool
    {
        return $this->expires_at !== null && $this->expires_at->lessThanOrEqualTo($now ?? CarbonImmutable::now());
    }

    /**
     * A link redirects only while it is enabled and not expired.
     */
    public function isResolvable(?CarbonImmutable $now = null): bool
    {
        return ! $this->isDisabled() && ! $this->isExpired($now);
    }

    public function shortUrl(): string
    {
        return rtrim(Config::string('shortlinks.short_url_base'), '/').'/'.$this->code;
    }
}
