<?php

namespace App\Http\Controllers;

use App\Models\Link;
use App\Services\ClickStats;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

class LinkStatsController extends Controller
{
    public function __invoke(Request $request, Link $link, ClickStats $stats): JsonResponse
    {
        $request->validate([
            'days' => ['sometimes', 'integer', 'min:1', 'max:'.Config::integer('shortlinks.stats.max_days')],
        ]);

        $days = $request->integer('days', Config::integer('shortlinks.stats.default_days'));

        return response()->json([
            'data' => ['code' => $link->code, ...$stats->forLink($link, $days, CarbonImmutable::now())],
            // Aggregates are rebuilt by the stats:rollup schedule, not on every click.
            'meta' => ['aggregation_interval_minutes' => Config::integer('shortlinks.stats.rollup_interval_minutes')],
        ]);
    }
}
