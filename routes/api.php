<?php

use App\Http\Controllers\LinkController;
use Illuminate\Support\Facades\Route;

Route::middleware('api.token')->group(function () {
    Route::post('links', [LinkController::class, 'store']);
    Route::get('links/{link}', [LinkController::class, 'show']);
    Route::delete('links/{link}', [LinkController::class, 'destroy']);
});
