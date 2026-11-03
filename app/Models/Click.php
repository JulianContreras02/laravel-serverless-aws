<?php

namespace App\Models;

use App\Enums\Device;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $link_id
 * @property CarbonImmutable $clicked_at
 * @property string|null $referrer_host
 * @property Device $device
 */
#[Fillable(['link_id', 'clicked_at', 'referrer_host', 'device'])]
#[WithoutTimestamps]
class Click extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'clicked_at' => 'immutable_datetime',
            'device' => Device::class,
        ];
    }

    /**
     * @return BelongsTo<Link, $this>
     */
    public function link(): BelongsTo
    {
        return $this->belongsTo(Link::class);
    }
}
