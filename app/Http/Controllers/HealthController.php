<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    /**
     * Used by the post-deploy smoke test: checks that the function can reach the database.
     */
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('SELECT 1');
            $database = 'ok';
        } catch (Throwable $e) {
            report($e);
            $database = 'unavailable';
        }

        $healthy = $database === 'ok';

        return response()->json(
            ['status' => $healthy ? 'ok' : 'degraded', 'checks' => ['database' => $database]],
            $healthy ? 200 : 503,
            ['Cache-Control' => 'no-store'],
        );
    }
}
