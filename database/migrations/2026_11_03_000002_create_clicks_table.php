<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only log written on every redirect. No counter column on `links`:
        // incrementing one row per click would serialize writes on a viral link.
        Schema::create('clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('link_id')->constrained();
            $table->dateTime('clicked_at');
            $table->string('referrer_host')->charset('ascii')->nullable();
            $table->string('device', 16)->charset('ascii');

            // Rollup and pruning scan by time window; per-link reads scan by link then time.
            $table->index('clicked_at');
            $table->index(['link_id', 'clicked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clicks');
    }
};
