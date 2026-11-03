<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\RedirectController;
use Illuminate\Support\Facades\Route;

// Public, unauthenticated routes. Registered after routes/api.php, so /api/* never reaches the catch-all.
Route::get('health', HealthController::class);

Route::get('{code}', RedirectController::class)->where('code', '[A-Za-z0-9_-]{3,32}');
