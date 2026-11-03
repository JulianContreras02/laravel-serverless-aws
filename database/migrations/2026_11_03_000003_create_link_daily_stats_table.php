<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Daily aggregates rebuilt from `clicks` by the stats:rollup command.
        // The stats endpoint reads only this table, so its cost depends on the
        // number of days requested, not on the number of clicks.
        Schema::create('link_daily_stats', function (Blueprint $table) {
            $table->foreignId('link_id')->constrained();
            $table->date('day');
            // Empty string means "direct" (no referrer): primary key columns cannot be NULL.
            $table->string('referrer_host')->charset('ascii')->default('');
            $table->string('device', 16)->charset('ascii');
            $table->unsignedInteger('clicks');

            $table->primary(['link_id', 'day', 'referrer_host', 'device']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_daily_stats');
    }
};
