<?php

namespace App\Models;

use App\Enums\Device;
use Carbon\CarbonImmutable;
use Database\Factories\ClickFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Config;

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
    /** @use HasFactory<ClickFactory> */
    use HasFactory;

    use MassPrunable;

    /**
     * Raw clicks older than the retention window, cut at a UTC day boundary so
     * every day the rollup may rebuild still has all of its clicks.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        $cutoff = CarbonImmutable::now()->utc()->startOfDay()
            ->subDays(Config::integer('shortlinks.click_retention_days'));

        return self::query()->where('clicked_at', '<', $cutoff->toDateTimeString());
    }

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
