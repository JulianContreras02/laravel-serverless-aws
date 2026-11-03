<?php

namespace App\Console\Commands;

use App\Services\ClickRollup;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class RollupClickStats extends Command
{
    protected $signature = 'stats:rollup
        {--days=2 : Whole UTC days to rebuild, ending today (2 covers the midnight boundary)}';

    protected $description = 'Rebuild daily click aggregates from raw clicks (idempotent)';

    public function handle(ClickRollup $rollup): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);
        $maxDays = Config::integer('shortlinks.click_retention_days');

        // Older days no longer have all their raw clicks: rebuilding them would
        // overwrite correct aggregates with smaller numbers.
        if ($days === false || $days < 1 || $days > $maxDays) {
            $this->error("--days must be an integer between 1 and {$maxDays} (the click retention).");

            return self::INVALID;
        }

        $today = CarbonImmutable::now()->utc()->startOfDay();
        $startedAt = hrtime(true);

        $groups = $rollup->rollup($today->subDays($days - 1), $today);

        $durationMs = round((hrtime(true) - $startedAt) / 1_000_000, 1);
        Log::info('Click rollup completed', ['days' => $days, 'groups' => $groups, 'duration_ms' => $durationMs]);
        $this->info("Rebuilt {$groups} aggregate rows for the last {$days} day(s) in {$durationMs} ms.");

        return self::SUCCESS;
    }
}
