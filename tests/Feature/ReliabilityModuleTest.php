<?php

declare(strict_types=1);

use App\Application\Auth\LoginUserService;
use App\Application\Auth\RefreshTokenService;
use App\Application\Auth\RegisterUserService;
use App\Domain\Audit\Enums\AuditEventType;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Auth\DTOs\LoginUserDTO;
use App\Domain\Auth\DTOs\RegisterUserDTO;
use App\Domain\Auth\Exceptions\InvalidCredentialsException;
use App\Domain\Auth\Exceptions\RefreshTokenReuseException;
use App\Domain\Outbox\Enums\OutboxEventType;
use App\Domain\Outbox\Enums\OutboxStatus;
use App\Domain\Outbox\Models\OutboxMessage;
use App\Infrastructure\Audit\BlameContext;
use App\Infrastructure\Outbox\OutboxService;
use App\Jobs\Auth\SendLoginNotificationEmailJob;
use App\Jobs\Auth\SendRegistrationEmailJob;
use App\Mail\Auth\LoginNotificationMail;
use App\Mail\Auth\WelcomeRegistrationMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('user registration persists outbox event and audit log inside database transaction', function () {
    /** @var RegisterUserService $service */
    $service = app(RegisterUserService::class);

    $dto = new RegisterUserDTO(
        name: 'Jane Doe',
        email: 'jane.doe@example.com',
        password: 'Password123!',
        firstName: 'Jane',
        lastName: 'Doe',
    );

    $user = $service->register($dto);

    // Verify Outbox message was created
    $outbox = OutboxMessage::where('aggregate_id', $user->id)->first();
    expect($outbox)->not->toBeNull()
        ->and($outbox->event_type)->toBe(OutboxEventType::UserRegistered->value)
        ->and($outbox->status)->toBe(OutboxStatus::Pending)
        ->and($outbox->correlation_id)->toBeString()
        ->and($outbox->headers['correlation_id'])->toBe($outbox->correlation_id)
        ->and($outbox->payload['email'])->toBe('jane.doe@example.com')
        ->and($outbox->payload['name'])->toBe('Jane Doe');

    // Verify Audit log was created
    $audit = AuditLog::where('user_id', $user->id)
        ->where('event_type', AuditEventType::UserRegistered)
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->event_type)->toBe(AuditEventType::UserRegistered)
        ->and($audit->metadata['email'])->toBe('jane.doe@example.com');
});

test('outbox message is not created if registration transaction fails or rolls back', function () {
    /** @var RegisterUserService $service */
    $service = app(RegisterUserService::class);

    $dto = new RegisterUserDTO(
        name: 'Rollback User',
        email: 'rollback@example.com',
        password: 'Password123!',
    );

    $service->register($dto);

    $initialOutboxCount = OutboxMessage::count();

    // Duplicate email throws DuplicateEmailException and rolls back
    try {
        $service->register($dto);
    } catch (Throwable) {
        // Expected
    }

    expect(OutboxMessage::count())->toBe($initialOutboxCount);
});

test('user login persists outbox event and audit log', function () {
    $user = User::factory()->create([
        'email' => 'login.test@example.com',
        'email_normalized' => 'login.test@example.com',
        'password_hash' => Hash::make('SecretPass123!'),
    ]);

    /** @var LoginUserService $loginService */
    $loginService = app(LoginUserService::class);

    $dto = new LoginUserDTO(
        email: 'login.test@example.com',
        password: 'SecretPass123!',
        deviceName: 'MacBook Pro Safari',
        ipAddress: '192.168.1.100',
        userAgent: 'Mozilla/5.0 Safari',
    );

    $result = $loginService->login($dto);
    expect($result->accessToken)->toBeString();

    // Outbox check
    $outbox = OutboxMessage::where('aggregate_id', $user->id)
        ->where('event_type', OutboxEventType::UserLoggedIn->value)
        ->first();

    expect($outbox)->not->toBeNull()
        ->and($outbox->event_key)->toBe(sprintf('user-logged-in-%s', $outbox->headers['session_id']))
        ->and($outbox->correlation_id)->toBeString()
        ->and($outbox->headers['correlation_id'])->toBe($outbox->correlation_id)
        ->and($outbox->payload['email'])->toBe('login.test@example.com')
        ->and($outbox->payload['device_name'])->toBe('MacBook Pro Safari')
        ->and($outbox->headers['session_id'])->toBeString();

    // Audit check
    $audit = AuditLog::where('user_id', $user->id)
        ->where('event_type', AuditEventType::UserLoggedIn)
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->ip_address)->toBe('192.168.1.100')
        ->and($audit->user_agent)->toBe('Mozilla/5.0 Safari');
});

test('failed login creates audit log with LoginFailed event', function () {
    $user = User::factory()->create([
        'email' => 'fail.test@example.com',
        'email_normalized' => 'fail.test@example.com',
        'password_hash' => Hash::make('CorrectPassword!'),
    ]);

    /** @var LoginUserService $loginService */
    $loginService = app(LoginUserService::class);

    $dto = new LoginUserDTO(
        email: 'fail.test@example.com',
        password: 'WrongPassword!',
        ipAddress: '10.0.0.1',
        userAgent: 'PostmanRuntime/7.36',
    );

    try {
        $loginService->login($dto);
    } catch (InvalidCredentialsException) {
        // Expected
    }

    $audit = AuditLog::where('event_type', AuditEventType::LoginFailed)
        ->where('user_id', $user->id)
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->metadata['reason'])->toBe('Invalid credentials')
        ->and($audit->ip_address)->toBe('10.0.0.1');
});

test('outbox publish command processes pending messages and dispatches queue jobs', function () {
    Queue::fake();

    $user = User::factory()->create([
        'email' => 'publisher.test@example.com',
        'email_normalized' => 'publisher.test@example.com',
    ]);

    /** @var RegisterUserService $registerService */
    $registerService = app(RegisterUserService::class);
    $dto = new RegisterUserDTO(
        name: 'Publisher User',
        email: 'pub.user@example.com',
        password: 'Password123!',
    );
    $registerService->register($dto);

    // Run outbox:publish command
    Artisan::call('outbox:publish');

    Queue::assertPushed(SendRegistrationEmailJob::class, function ($job) {
        return $job->payload['email'] === 'pub.user@example.com';
    });

    $outbox = OutboxMessage::where('event_type', OutboxEventType::UserRegistered->value)->first();
    expect($outbox->status)->toBe(OutboxStatus::Published)
        ->and($outbox->published_at)->not->toBeNull();
});

test('registration and login email jobs execute idempotently without double sending', function () {
    Mail::fake();

    $job = new SendRegistrationEmailJob(
        eventId: '01918a22-0000-7000-8000-000000000001',
        eventType: 'UserRegistered',
        aggregateId: '01918a22-0000-7000-8000-000000000002',
        payload: [
            'name' => 'Alice Test',
            'email' => 'alice.idempotent@example.com',
        ],
        headers: [
            'actor_id' => '01918a22-0000-7000-8000-000000000002',
        ],
    );

    // First execution -> sends email
    $job->handle();

    Mail::assertSent(WelcomeRegistrationMail::class, 1);

    // Second execution -> duplicate should be skipped
    $job->handle();

    Mail::assertSent(WelcomeRegistrationMail::class, 1);
});

test('login notification email job executes idempotently', function () {
    Mail::fake();

    $job = new SendLoginNotificationEmailJob(
        eventId: '01918a22-0000-7000-8000-000000000003',
        eventType: 'UserLoggedIn',
        aggregateId: '01918a22-0000-7000-8000-000000000004',
        payload: [
            'email' => 'bob.login@example.com',
            'device_name' => 'Firefox on Linux',
            'ip_address' => '127.0.0.1',
        ],
    );

    $job->handle();
    Mail::assertSent(LoginNotificationMail::class, 1);

    // Second execution -> duplicate should be skipped
    $job->handle();
    Mail::assertSent(LoginNotificationMail::class, 1);
});

test('refresh token reuse detection records RefreshTokenReuseDetected audit log', function () {
    $user = User::factory()->create([
        'email' => 'reuse.audit@example.com',
        'email_normalized' => 'reuse.audit@example.com',
        'password_hash' => Hash::make('Password123!'),
    ]);

    /** @var LoginUserService $loginService */
    $loginService = app(LoginUserService::class);
    $loginResult = $loginService->login(new LoginUserDTO(
        email: 'reuse.audit@example.com',
        password: 'Password123!',
    ));

    /** @var RefreshTokenService $refreshService */
    $refreshService = app(RefreshTokenService::class);

    // First refresh: consumes old refresh token
    $refreshResult = $refreshService->refresh($loginResult->refreshToken);
    expect($refreshResult->refreshToken)->not->toBe($loginResult->refreshToken);

    // Second refresh with the consumed token -> Reuse detected
    try {
        $refreshService->refresh($loginResult->refreshToken);
    } catch (RefreshTokenReuseException) {
        // Expected
    }

    $audit = AuditLog::where('event_type', AuditEventType::RefreshTokenReuseDetected)->first();
    expect($audit)->not->toBeNull();
});

test('blame context provides fallback when request user is present or explicitly set', function () {
    BlameContext::clear();

    expect(BlameContext::getActorId())->toBeNull()
        ->and(BlameContext::getCorrelationId())->toBeNull();

    BlameContext::setActorId('01918a22-0000-7000-8000-000000000009');
    BlameContext::setCorrelationId('01918a22-0000-7000-8000-000000000010');

    expect(BlameContext::getActorId())->toBe('01918a22-0000-7000-8000-000000000009')
        ->and(BlameContext::getCorrelationId())->toBe('01918a22-0000-7000-8000-000000000010');

    BlameContext::clear();
    expect(BlameContext::getActorId())->toBeNull()
        ->and(BlameContext::getCorrelationId())->toBeNull();
});

test('outbox service persists explicit and context-based correlation IDs', function () {
    BlameContext::clear();

    /** @var OutboxService $outboxService */
    $outboxService = app(OutboxService::class);

    // 1. Explicit correlation ID passed
    $msg1 = $outboxService->record(
        eventType: 'CustomEvent',
        aggregateType: 'Order',
        aggregateId: '01918a22-0000-7000-8000-000000000011',
        payload: ['data' => 'test1'],
        correlationId: 'cid-explicit-12345',
    );

    expect($msg1->correlation_id)->toBe('cid-explicit-12345')
        ->and($msg1->headers['correlation_id'])->toBe('cid-explicit-12345');

    // Verify index query
    $queried = OutboxMessage::where('correlation_id', 'cid-explicit-12345')->first();
    expect($queried)->not->toBeNull()
        ->and($queried->id)->toBe($msg1->id);

    // 2. Correlation ID from BlameContext
    BlameContext::setCorrelationId('cid-from-blame-context');
    $msg2 = $outboxService->record(
        eventType: 'CustomEvent2',
        aggregateType: 'Order',
        aggregateId: '01918a22-0000-7000-8000-000000000012',
        payload: ['data' => 'test2'],
    );

    expect($msg2->correlation_id)->toBe('cid-from-blame-context')
        ->and($msg2->headers['correlation_id'])->toBe('cid-from-blame-context');

    BlameContext::clear();
});
