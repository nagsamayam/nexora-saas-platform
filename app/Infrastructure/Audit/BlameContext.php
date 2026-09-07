<?php

declare(strict_types=1);

namespace App\Infrastructure\Audit;

use Illuminate\Support\Facades\Context;

final class BlameContext
{
    public const SYSTEM_ACTOR_ID = '00000000-0000-0000-0000-000000000000';

    private static ?string $explicitActorId = null;

    private static ?string $explicitUserId = null;

    private static ?string $explicitSessionId = null;

    private static ?string $explicitIpAddress = null;

    private static ?string $explicitUserAgent = null;

    public static function setActorId(?string $actorId): void
    {
        self::$explicitActorId = $actorId;
        Context::add('actor_id', $actorId);
    }

    public static function setUserId(?string $userId): void
    {
        self::$explicitUserId = $userId;
        Context::add('user_id', $userId);
    }

    public static function setSessionId(?string $sessionId): void
    {
        self::$explicitSessionId = $sessionId;
        Context::add('session_id', $sessionId);
    }

    public static function setIpAddress(?string $ipAddress): void
    {
        self::$explicitIpAddress = $ipAddress;
        Context::add('ip_address', $ipAddress);
    }

    public static function setUserAgent(?string $userAgent): void
    {
        self::$explicitUserAgent = $userAgent;
        Context::add('user_agent', $userAgent);
    }

    public static function getActorId(): ?string
    {
        if (self::$explicitActorId !== null) {
            return self::$explicitActorId;
        }

        $fromContext = Context::get('actor_id');
        if (is_string($fromContext) && $fromContext !== '') {
            return $fromContext;
        }

        if (app()->bound('request')) {
            $request = app('request');
            $user = $request->user();
            if ($user !== null && isset($user->id)) {
                return (string) $user->id;
            }
        }

        return null;
    }

    public static function getUserId(): ?string
    {
        if (self::$explicitUserId !== null) {
            return self::$explicitUserId;
        }

        $fromContext = Context::get('user_id');
        if (is_string($fromContext) && $fromContext !== '') {
            return $fromContext;
        }

        if (app()->bound('request')) {
            $request = app('request');
            $user = $request->user();
            if ($user !== null && isset($user->id)) {
                return (string) $user->id;
            }
        }

        return null;
    }

    public static function getSessionId(): ?string
    {
        if (self::$explicitSessionId !== null) {
            return self::$explicitSessionId;
        }

        $fromContext = Context::get('session_id');
        if (is_string($fromContext) && $fromContext !== '') {
            return $fromContext;
        }

        if (app()->bound('request')) {
            $request = app('request');
            $tokenSid = $request->attributes->get('jwt_sid');
            if (is_string($tokenSid) && $tokenSid !== '') {
                return $tokenSid;
            }
        }

        return null;
    }

    public static function getIpAddress(): ?string
    {
        if (self::$explicitIpAddress !== null) {
            return self::$explicitIpAddress;
        }

        $fromContext = Context::get('ip_address');
        if (is_string($fromContext) && $fromContext !== '') {
            return $fromContext;
        }

        if (app()->bound('request')) {
            $request = app('request');

            return $request->ip();
        }

        return null;
    }

    public static function getUserAgent(): ?string
    {
        if (self::$explicitUserAgent !== null) {
            return self::$explicitUserAgent;
        }

        $fromContext = Context::get('user_agent');
        if (is_string($fromContext) && $fromContext !== '') {
            return $fromContext;
        }

        if (app()->bound('request')) {
            $request = app('request');

            return $request->userAgent();
        }

        return null;
    }

    /**
     * @param array{
     *     actor_id?: string|null,
     *     user_id?: string|null,
     *     session_id?: string|null,
     *     ip_address?: string|null,
     *     user_agent?: string|null
     * } $context
     */
    public static function setContext(array $context): void
    {
        if (array_key_exists('actor_id', $context)) {
            self::setActorId($context['actor_id']);
        }
        if (array_key_exists('user_id', $context)) {
            self::setUserId($context['user_id']);
        }
        if (array_key_exists('session_id', $context)) {
            self::setSessionId($context['session_id']);
        }
        if (array_key_exists('ip_address', $context)) {
            self::setIpAddress($context['ip_address']);
        }
        if (array_key_exists('user_agent', $context)) {
            self::setUserAgent($context['user_agent']);
        }
    }

    public static function clear(): void
    {
        self::$explicitActorId = null;
        self::$explicitUserId = null;
        self::$explicitSessionId = null;
        self::$explicitIpAddress = null;
        self::$explicitUserAgent = null;

        Context::forget([
            'actor_id',
            'user_id',
            'session_id',
            'ip_address',
            'user_agent',
        ]);
    }
}
