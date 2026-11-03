<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

class ClickRollup
{
    private const UPSERT_CHUNK = 500;

    /**
     * Rebuilds the daily aggregates of the whole UTC days [$firstDay, $lastDay]
     * from raw clicks. Counts are recomputed and overwritten, so running it twice
     * gives the same result. Only whole days are processed: aggregating part of a
     * day would overwrite its full count with a partial one.
     *
     * @return int number of (link, day, referrer, device) groups written
     */
    public function rollup(CarbonImmutable $firstDay, CarbonImmutable $lastDay): int
    {
        $from = $firstDay->utc()->startOfDay();
        $until = $lastDay->utc()->startOfDay()->addDay();

        // A plain SELECT is a non-locking consistent read in InnoDB. A single
        // INSERT ... SELECT would take shared next-key locks on the clicks it
        // scans and stall the redirects that insert new clicks meanwhile.
        $groups = DB::table('clicks')
            ->selectRaw("link_id, DATE(clicked_at) AS day, COALESCE(referrer_host, '') AS referrer_host, device, COUNT(*) AS clicks")
            ->where('clicked_at', '>=', $from->toDateTimeString())
            ->where('clicked_at', '<', $until->toDateTimeString())
            ->groupByRaw("link_id, DATE(clicked_at), COALESCE(referrer_host, ''), device")
            ->get();

        foreach ($groups->chunk(self::UPSERT_CHUNK) as $chunk) {
            DB::table('link_daily_stats')->upsert(
                $chunk->map(fn (stdClass $row): array => (array) $row)->values()->all(),
                ['link_id', 'day', 'referrer_host', 'device'],
                ['clicks'],
            );
        }

        return $groups->count();
    }
}
