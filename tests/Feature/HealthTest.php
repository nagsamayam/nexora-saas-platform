<?php

declare(strict_types=1);

test('live health endpoint returns HTTP 200 with alive status', function () {
    $response = $this->getJson('/api/v1/health/live');

    $response->assertOk()
        ->assertJsonStructure([
            'success',
            'data' => [
                'status',
                'timestamp',
            ],
            'meta' => [
                'request_id',
            ],
        ])
        ->assertJson([
            'success' => true,
            'data' => [
                'status' => 'alive',
            ],
        ]);
});

test('ready health endpoint returns valid structure', function () {
    $response = $this->getJson('/api/v1/health/ready');

    $response->assertJsonStructure([
        'success',
        'meta' => [
            'request_id',
        ],
    ]);

    expect(in_array($response->getStatusCode(), [200, 503], true))->toBeTrue();
});
