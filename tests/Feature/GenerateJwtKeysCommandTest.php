<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

test('jwt:generate-keys generates RSA key files successfully', function () {
    $tempDir = storage_path('framework/testing/jwt-keys-test');
    if (is_dir($tempDir)) {
        File::deleteDirectory($tempDir);
    }
    mkdir($tempDir, 0755, true);

    $privateKey = $tempDir.'/private.pem';
    $publicKey = $tempDir.'/public.pem';

    config([
        'jwt.keys.private' => $privateKey,
        'jwt.keys.public' => $publicKey,
    ]);

    $this->artisan('jwt:generate-keys', ['--bits' => '2048', '--force' => true])
        ->assertSuccessful();

    expect(file_exists($privateKey))->toBeTrue()
        ->and(file_exists($publicKey))->toBeTrue();

    $privateContent = file_get_contents($privateKey);
    $publicContent = file_get_contents($publicKey);

    expect($privateContent)->toContain('BEGIN PRIVATE KEY')
        ->and($publicContent)->toContain('BEGIN PUBLIC KEY');

    File::deleteDirectory($tempDir);
});

test('jwt:generate-keys warns if keys exist without force flag', function () {
    $tempDir = storage_path('framework/testing/jwt-keys-test-warn');
    if (! is_dir($tempDir)) {
        mkdir($tempDir, 0755, true);
    }

    $privateKey = $tempDir.'/private.pem';
    $publicKey = $tempDir.'/public.pem';

    file_put_contents($privateKey, 'test');
    file_put_contents($publicKey, 'test');

    config([
        'jwt.keys.private' => $privateKey,
        'jwt.keys.public' => $publicKey,
    ]);

    $this->artisan('jwt:generate-keys', ['--bits' => '2048'])
        ->assertFailed();

    File::deleteDirectory($tempDir);
});
