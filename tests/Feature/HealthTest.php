<?php

use Illuminate\Support\Facades\DB;

it('reports healthy when the database answers', function () {
    $this->get('/health')
        ->assertOk()
        ->assertExactJson(['status' => 'ok', 'checks' => ['database' => 'ok']]);
});

it('returns 503 when the database is unreachable', function () {
    config(['database.connections.unreachable' => array_merge(
        config('database.connections.mysql'),
        ['host' => '127.0.0.1', 'port' => 1],
    )]);
    DB::setDefaultConnection('unreachable');

    try {
        $this->get('/health')
            ->assertStatus(503)
            ->assertJsonPath('checks.database', 'unavailable');
    } finally {
        DB::setDefaultConnection('mysql');
    }
});
