<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('links', function (Blueprint $table) {
            $table->id();
            // Binary collation: "aB3xK9q" and "ab3xk9q" are different links.
            $table->string('code', 32)->charset('ascii')->collation('ascii_bin')->unique();
            $table->string('target_url', 2048);
            // DATETIME, not TIMESTAMP: expiry dates may be later than 2038.
            $table->dateTime('expires_at')->nullable();
            // Soft disable instead of DELETE: clicks and aggregates keep their link.
            $table->dateTime('disabled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('links');
    }
};
