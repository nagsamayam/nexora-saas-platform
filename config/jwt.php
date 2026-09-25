<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | JWT Signing Algorithm
    |--------------------------------------------------------------------------
    */
    'algo' => env('JWT_ALGO', 'RS256'),

    /*
    |--------------------------------------------------------------------------
    | JWT Keys
    |--------------------------------------------------------------------------
    */
    'keys' => [
        'private' => env('JWT_PRIVATE_KEY', storage_path('app/keys/jwt-rsa-4096-private.pem')),
        'public' => env('JWT_PUBLIC_KEY', storage_path('app/keys/jwt-rsa-4096-public.pem')),
        'passphrase' => env('JWT_PASSPHRASE', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | JWT Lifetimes & Claims
    |--------------------------------------------------------------------------
    */
    'ttl' => (int) env('JWT_TTL', 900), // 15 minutes in seconds
    'refresh_ttl' => (int) env('JWT_REFRESH_TTL', 60 * 60 * 24 * 30), // 30 days in seconds
    'issuer' => env('JWT_ISSUER', env('APP_NAME', 'Nexora')),
    'audience' => env('JWT_AUDIENCE', 'nexora-api'),
    'leeway' => (int) env('JWT_LEEWAY', 0), // clock skew leeway in seconds

    /*
    |--------------------------------------------------------------------------
    | Blacklist Storage
    |--------------------------------------------------------------------------
    */
    'blacklist_enabled' => (bool) env('JWT_BLACKLIST_ENABLED', true),
    'blacklist_grace_period' => (int) env('JWT_BLACKLIST_GRACE_PERIOD', 0),
    'blacklist_cache_prefix' => 'jwt_blacklist:',
];
