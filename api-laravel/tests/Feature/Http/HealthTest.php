<?php

declare(strict_types=1);

uses()->group('ledger:rest:GET:/health', 'ledger:rest:GET:/ready');

use Illuminate\Support\Facades\DB;

/**
 * Port of ApplicationController#health / #ready (Rails: GET /health, GET /ready).
 */
it('answers GET /health with version, github_url and Success when the database is reachable', function (): void {
    $response = $this->getJson('/health');

    $response->assertOk()
        ->assertJson([
            'version' => config('lago.version'),
            'github_url' => config('lago.github_url'),
            'message' => 'Success',
        ]);
});

it('answers GET /health with 500 Unhealthy and details when the database check fails', function (): void {
    DB::shouldReceive('select')
        ->andThrow(new RuntimeException('connection refused'));

    $response = $this->getJson('/health');

    $response->assertStatus(500)
        ->assertJson([
            'version' => config('lago.version'),
            'github_url' => config('lago.github_url'),
            'message' => 'Unhealthy',
            'details' => 'connection refused',
        ]);
});

it('answers GET /ready with status ok', function (): void {
    $this->getJson('/ready')
        ->assertOk()
        ->assertJson(['status' => 'ok']);
});
