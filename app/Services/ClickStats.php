<?php

namespace App\Services;

use App\Models\Link;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use stdClass;

class ClickStats
{
    /**
     * Reads only the daily aggregates: the cost grows with the number of days
     * requested, not with the number of clicks the link received.
     *
     * @return array{
     *     period: array{from: string, to: string, days: int},
     *     total_clicks: int,
     *     daily: list<array{date: string, clicks: int}>,
     *     top_referrers: list<array{host: string|null, clicks: int}>,
     *     devices: array<string, int>,
     * }
     */
    public function forLink(Link $link, int $days, CarbonImmutable $today): array
    {
        $to = $today->utc()->startOfDay();
        $from = $to->subDays($days - 1);
        $period = fn (): Builder => DB::table('link_daily_stats')
            ->where('link_id', $link->id)
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()]);

        $daily = $this->dailySeries($period(), $from, $to);

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $days],
            'total_clicks' => array_sum(array_column($daily, 'clicks')),
            'daily' => $daily,
            'top_referrers' => $this->topReferrers($period()),
            'devices' => $this->devices($period()),
        ];
    }

    /**
     * One entry per day, including days without clicks, so clients can chart it directly.
     *
     * @return list<array{date: string, clicks: int}>
     */
    private function dailySeries(Builder $query, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $clicksByDay = [];
        foreach ($query->selectRaw('day, SUM(clicks) AS clicks')->groupBy('day')->get() as $row) {
            $clicksByDay[self::string($row, 'day')] = self::int($row, 'clicks');
        }

        $series = [];
        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $date = $day->toDateString();
            $series[] = ['date' => $date, 'clicks' => $clicksByDay[$date] ?? 0];
        }

        return $series;
    }

    /**
     * @return list<array{host: string|null, clicks: int}>
     */
    private function topReferrers(Builder $query): array
    {
        $rows = $query->selectRaw('referrer_host, SUM(clicks) AS clicks')
            ->groupBy('referrer_host')
            ->orderByDesc('clicks')
            ->orderBy('referrer_host')
            ->limit(Config::integer('shortlinks.stats.top_referrers'))
            ->get();

        $referrers = [];
        foreach ($rows as $row) {
            $referrers[] = [
                // The empty string is how aggregates store "direct" traffic.
                'host' => self::string($row, 'referrer_host') ?: null,
                'clicks' => self::int($row, 'clicks'),
            ];
        }

        return $referrers;
    }

    /**
     * @return array<string, int>
     */
    private function devices(Builder $query): array
    {
        $devices = [];
        foreach ($query->selectRaw('device, SUM(clicks) AS clicks')->groupBy('device')->orderByDesc('clicks')->get() as $row) {
            $devices[self::string($row, 'device')] = self::int($row, 'clicks');
        }

        return $devices;
    }

    private static function int(stdClass $row, string $column): int
    {
        $value = $row->{$column} ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    private static function string(stdClass $row, string $column): string
    {
        $value = $row->{$column} ?? '';

        return is_scalar($value) ? (string) $value : '';
    }
}
