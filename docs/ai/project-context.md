# Authentication Module Requirements

## 1. Objective

Implement a production-grade authentication module for a Laravel 13 REST API.

The module must be:

* Secure
* Maintainable
* Testable
* Scalable
* Idempotent where appropriate
* Retryable
* Observable
* Suitable for future multi-tenant SaaS expansion

The first milestone is **authentication only**. Tenant management, billing, subscriptions, permissions, and other SaaS features will be implemented later.

The implementation must demonstrate architecture-level decisions suitable for a Senior Software Engineer / Software Architect interview.

---

## 2. Technology Stack

* PHP 8.4
* Laravel 13
* PostgreSQL 18
* Redis
* RabbitMQ
* PgBouncer
* Docker
* PHP-Open-Source-Saver/jwt-auth
* PHPStan / Larastan
* PHPUnit / Laravel testing tools

The application must expose REST APIs only. No frontend implementation is required.

---

## 3. Architectural Principles

The implementation must follow:

* Separation of concerns
* Dependency inversion
* Explicit application services
* Thin controllers
* Domain-oriented business logic
* Transactional consistency
* Secure token lifecycle management
* At-least-once event delivery
* Idempotent consumers
* Explicit error handling
* Testable application boundaries

Avoid unnecessary microservices, event sourcing, CQRS, or distributed locking in this milestone.

The application should be structured so that these patterns can be introduced later if required.

---

# 4. API Versioning

All authentication APIs must be versioned.

Example:

```text
/api/v1/auth/register
/api/v1/auth/login
/api/v1/auth/refresh
/api/v1/auth/logout
/api/v1/auth/logout-all
/api/v1/auth/me
```

Requirements:

* Use URL-based versioning.
* All authentication endpoints must be under `/api/v1`.
* Future versions must be able to coexist.
* API versioning must not require changes to the underlying authentication domain services.

---

# 5. Authentication Model

The system must support:

* User registration
* Login
* Logout
* Refresh token rotation
* Logout from all devices
* Admin-initiated global logout
* Current-user information
* Session management
* JWT invalidation
* Refresh-token reuse detection

The system must support multiple concurrent sessions for the same user.

Example:

```text
User
 ├── Mobile Session
 ├── Web Session
 └── Another Device Session
```

Logging out from one device must not invalidate other active sessions.

Logging out from all devices must invalidate all active sessions.

---

# 6. JWT Access Tokens

Use PHP-Open-Source-Saver/jwt-auth for JWT access-token generation and validation.

The JWT signing algorithm must be:

```text
RS256
```

Use an RSA public/private key pair.

Requirements:

* Private key must never be exposed through the API.
* Private key must not be committed to source control.
* Public key must be used for token verification.
* JWT configuration must be environment-driven.
* JWT secrets and keys must not be hardcoded.
* Access tokens must be short-lived.
* Refresh tokens must not be JWTs.

Suggested initial access-token lifetime:

```text
15 minutes
```

This value must be configurable.

---

## 6.1 Required JWT Claims

Every access token must include:

```text
iss
aud
sub
iat
exp
nbf
jti
sid
auth_version
```

### Claim requirements

| Claim          | Requirement                                   |
| -------------- | --------------------------------------------- |
| `iss`          | Must identify the application                 |
| `aud`          | Must identify the intended API                |
| `sub`          | Must contain the user UUID                    |
| `iat`          | Must represent token issuance time            |
| `exp`          | Must represent token expiration time          |
| `nbf`          | Must be present and validated                 |
| `jti`          | Must be unique for every access token         |
| `sid`          | Must identify the authentication session      |
| `auth_version` | Must support global user-session invalidation |

The system must reject tokens with:

* Invalid signature
* Missing required claims
* Invalid issuer
* Invalid audience
* Expired `exp`
* Future `nbf`
* Invalid `iat`
* Invalid `jti`
* Invalid `sid`
* Invalid `auth_version`

Do not place sensitive information in JWT claims.

---

# 7. Authentication Sessions

Create a database table representing a user authentication session.

Suggested table:

```text
auth_sessions
```

Suggested fields:

```text
id UUID
user_id UUID
status
device_name
ip_address
user_agent
last_used_at
expires_at
revoked_at
revoked_by
created_at
updated_at
```

### Requirements

* Each successful login creates a new session.
* A session represents one device/login context.
* Multiple active sessions per user are allowed.
* Refresh tokens belong to a session.
* JWT `sid` must identify the session.
* Logout must revoke the current session.
* Logout-all must revoke all active sessions for the user.
* Admin global logout must revoke all active sessions for the user.

Suggested statuses:

```text
active
revoked
expired
```

---

# 8. User Account Security Version

Add a user-level authentication version.

Suggested field:

```text
auth_version BIGINT
```

Initial value:

```text
0
```

When an administrator performs global logout:

```text
auth_version = auth_version + 1
```

Every newly issued JWT must contain the current `auth_version`.

During authentication, the system must verify:

```text
JWT auth_version === users.auth_version
```

If the values do not match, the token must be rejected.

This provides immediate invalidation of all existing access tokens for the user.

---

# 9. Refresh Tokens

Refresh tokens must be opaque strings.

They must not be JWTs.

Generate refresh tokens using a cryptographically secure random generator.

Example:

```text
random_bytes()
```

The raw refresh token must be returned to the client only once.

The database must store only a cryptographic hash of the refresh token.

Example:

```text
SHA-256
```

Never store raw refresh tokens in the database.

Never log raw refresh tokens.

---

## 9.1 Refresh Token Table

Suggested table:

```text
auth_refresh_tokens
```

Suggested fields:

```text
id UUID
session_id UUID
token_hash VARCHAR
status
expires_at
consumed_at
revoked_at
replaced_by UUID
created_at
updated_at
```

Suggested statuses:

```text
active
consumed
revoked
expired
```

Requirements:

* A refresh token belongs to exactly one session.
* A refresh token can be consumed only once.
* A refresh token must have an expiration time.
* A consumed refresh token must not be reusable.
* A revoked refresh token must not be reusable.
* A refresh token must be invalidated when its session is revoked.

---

# 10. Refresh Token Rotation

Every successful refresh request must:

1. Validate the refresh token.
2. Locate the refresh-token hash.
3. Verify that the token is active.
4. Verify that the token has not expired.
5. Verify that the associated session is active.
6. Lock the session / refresh-token record.
7. Mark the old refresh token as consumed.
8. Create a new refresh token.
9. Create a new access token.
10. Return the new token pair.

The old refresh token must never remain active after successful rotation.

---

## 10.1 Concurrency Requirements

Refresh-token rotation must be concurrency-safe.

Use a database transaction.

Use row-level locking where appropriate.

Example:

```text
SELECT ... FOR UPDATE
```

Two concurrent refresh requests using the same refresh token must not both succeed.

Expected behavior:

```text
Request A → succeeds
Request B → detects consumed token
```

---

# 11. Refresh Token Reuse Detection

If a previously consumed refresh token is used again, the system must treat it as a possible compromise.

The system must:

1. Detect the reuse.
2. Revoke the associated session.
3. Revoke all active refresh tokens belonging to that session.
4. Reject the request.
5. Record a security audit event.
6. Return an appropriate authentication error.

The system must not silently issue a new token.

Suggested audit event:

```text
RefreshTokenReuseDetected
```

---

# 12. Registration

Endpoint:

```text
POST /api/v1/auth/register
```

Request:

```json
{
  "name": "John Doe",
  "email": "john@example.com",
  "password": "strong-password",
  "password_confirmation": "strong-password"
}
```

Requirements:

* Validate all input.
* Normalize email addresses.
* Enforce unique email addresses.
* Hash passwords using Laravel's password hashing mechanism.
* Never store plaintext passwords.
* Create the user inside a database transaction.
* Create an outbox event inside the same transaction.
* Return a safe user representation.
* Do not return the password or password hash.
* Do not automatically create a JWT unless explicitly required by the API design.

Suggested event:

```text
UserRegistered
```

Registration must be idempotent with respect to duplicate email registration.

Repeated registration attempts for the same email must not create duplicate users.

---

# 13. Login

Endpoint:

```text
POST /api/v1/auth/login
```

Request:

```json
{
  "email": "john@example.com",
  "password": "strong-password",
  "device_name": "Chrome Browser"
}
```

Requirements:

* Validate credentials.
* Reject inactive / suspended users.
* Create a new authentication session.
* Create a refresh token.
* Issue a JWT access token.
* Return the access token and refresh token.
* Record the login event.
* Do not expose sensitive authentication details in error messages.

Suggested response:

```json
{
  "data": {
    "access_token": "...",
    "refresh_token": "...",
    "token_type": "Bearer",
    "expires_in": 900
  }
}
```

Login must not be made idempotent.

Every successful login may create a new session.

---

# 14. Logout

Endpoint:

```text
POST /api/v1/auth/logout
```

Requirements:

* Require a valid access token.
* Identify the current session using `sid`.
* Revoke the current session.
* Revoke active refresh tokens belonging to the session.
* Invalidate the current JWT using the JWT package blacklist mechanism.
* Return success even if the session has already been revoked.

Logout must be idempotent.

---

# 15. Logout All Devices

Endpoint:

```text
POST /api/v1/auth/logout-all
```

Requirements:

* Require authentication.
* Revoke all active sessions for the user.
* Revoke all active refresh tokens for the user.
* Increment the user's `auth_version`.
* Invalidate the current JWT.
* Return success even if no active sessions exist.

This operation must be idempotent.

---

# 16. Admin Global Logout

Provide an application service that allows an administrator to invalidate all active sessions for a user.

Requirements:

* Verify administrator authorization.
* Revoke all active sessions for the target user.
* Revoke all active refresh tokens.
* Increment the target user's `auth_version`.
* Record an audit event.
* Do not require the administrator to know the target user's refresh tokens.

Suggested event:

```text
UserSessionsRevoked
```

---

# 17. JWT Blocklist

Enable the JWT package blacklist functionality.

The system must use the package's invalidation mechanism for access-token revocation.

The application must not rely only on JWT expiration for logout.

Requirements:

* Logout must invalidate the current JWT.
* Logout-all must invalidate all existing access tokens through the user `auth_version`.
* Admin global logout must invalidate all existing access tokens through the user `auth_version`.
* Blacklist storage must be configured appropriately for the application.
* Redis may be used for JWT blacklist storage.

The application must not assume that a JWT is valid merely because its signature is valid.

---

# 18. Rate Limiting

Implement rate limiting for authentication endpoints.

Suggested initial limits:

```text
Register: 5 requests per minute per IP
Login: 10 requests per minute per IP
Refresh: 20 requests per minute per IP
```

These values must be configurable.

Requirements:

* Use Laravel rate limiting.
* Use Redis as the backing store.
* Return HTTP 429 when the limit is exceeded.
* Include retry information where appropriate.
* Avoid leaking whether an email address exists.
* Consider combining IP-based and account-based login throttling.

---

# 19. Standard Error Response

All API errors must use a consistent JSON structure.

Suggested format:

```json
{
  "error": {
    "code": "AUTH_INVALID_CREDENTIALS",
    "message": "The provided credentials are invalid.",
    "details": null,
    "trace_id": "01J..."
  }
}
```

Validation example:

```json
{
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "The request contains invalid data.",
    "details": {
      "email": [
        "The email field is required."
      ]
    },
    "trace_id": "01J..."
  }
}
```

Requirements:

* All errors must use the standard structure.
* Error codes must be stable.
* Messages must not expose sensitive information.
* Include a request / trace identifier.
* Do not expose stack traces in production.
* Do not expose database errors to API clients.

---

# 20. HTTP Status Code Mapping

Use standard HTTP status codes.

| Scenario                | Status |
| ----------------------- | -----: |
| Successful registration |    201 |
| Successful login        |    200 |
| Successful refresh      |    200 |
| Successful logout       |    204 |
| Successful logout-all   |    204 |
| Validation failure      |    422 |
| Invalid credentials     |    401 |
| Missing authentication  |    401 |
| Invalid / expired token |    401 |
| Forbidden operation     |    403 |
| Duplicate registration  |    409 |
| Rate limit exceeded     |    429 |
| Unexpected server error |    500 |

---

# 21. Exception Handling

Create application-specific exceptions where appropriate.

Examples:

```text
InvalidCredentialsException
AuthenticationTokenException
RefreshTokenException
RefreshTokenReuseException
SessionRevokedException
ConcurrencyException
```

Requirements:

* Exceptions must be mapped centrally.
* Controllers must not contain repetitive exception-handling logic.
* Authentication exceptions must return appropriate HTTP responses.
* Unexpected exceptions must be logged.
* Sensitive information must not be exposed.

---

# 22. Outbox Pattern

Use the outbox pattern for events that must be persisted reliably with database changes.

The first implementation must support:

```text
UserRegistered
UserLoggedIn
```

The outbox record must be created in the same transaction as the business operation.

Example:

```text
BEGIN TRANSACTION

Create User
Create Outbox Event

COMMIT
```

If the transaction fails, neither the user nor the event should be committed.

---

## 22.1 Outbox Table

Suggested fields:

```text
id UUID
correlation_id VARCHAR
event_type VARCHAR
aggregate_type VARCHAR
aggregate_id UUID
event_key VARCHAR
payload JSONB
status VARCHAR
attempts INTEGER
available_at TIMESTAMPTZ
published_at TIMESTAMPTZ
last_error TEXT
created_at TIMESTAMPTZ
updated_at TIMESTAMPTZ
```

Suggested statuses:

```text
pending
published
failed
```

---

## 22.2 Outbox Publishing

Implement a background publisher that:

1. Reads pending outbox records.
2. Publishes events to RabbitMQ.
3. Marks successfully published records as published.
4. Retries transient failures.
5. Uses exponential backoff.
6. Limits retry attempts.
7. Records the last error.
8. Supports dead-letter handling.

The publisher must be safe to run concurrently.

Use row locking or another safe claiming mechanism.

---

# 23. RabbitMQ Consumers

Use Laravel Jobs with RabbitMQ.

Consumers must be idempotent.

Example:

```text
UserRegistered
    ↓
SendRegistrationEmailJob
```

The job must:

* Receive the event payload.
* Include the originating user UUID.
* Include the event ID.
* Include the event type.
* Include a unique idempotency key.
* Rehydrate the audit blame context.
* Send the email.
* Record successful processing.
* Retry transient failures.
* Avoid duplicate email sending.

---

# 24. Email Notifications

Implement:

* Registration email
* Login notification email

The login email must be generated through a queued job.

Suggested events:

```text
UserRegistered
UserLoggedIn
```

Email delivery must be asynchronous.

The API must not wait for email delivery to complete.

The system must not fail registration or login merely because email delivery is temporarily unavailable.

---

# 25. Idempotency

Idempotency must be applied selectively.

The following operations must be idempotent:

* Registration
* Logout
* Logout-all
* Outbox publishing
* Email event processing

Login does not need to be idempotent.

Refresh-token rotation must be concurrency-safe and must detect token reuse.

The implementation must not attempt to make every API idempotent.

---

# 26. Retry Strategy

Retries must be applied only to transient failures.

Examples of retryable failures:

* RabbitMQ temporary connection failure
* Email provider timeout
* Temporary database connection failure
* Temporary network failure

Examples of non-retryable failures:

* Invalid credentials
* Invalid refresh token
* Duplicate registration
* Validation failure
* User suspended

Use exponential backoff.

Example:

```text
1 minute
5 minutes
15 minutes
30 minutes
```

The retry strategy must be configurable.

---

# 27. Audit Logging

Implement structured audit logging for security-sensitive authentication events.

Suggested events:

```text
UserRegistered
UserLoggedIn
UserLoggedOut
UserSessionsRevoked
RefreshTokenRotated
RefreshTokenReuseDetected
LoginFailed
```

Audit records must include:

```text
id UUID
event_type
actor_id
user_id
session_id
ip_address
user_agent
metadata JSONB
created_at
```

Audit records must be append-only.

Do not update or delete audit records during normal application operation.

---

# 28. Blame Context

Implement a centralized blame context manager.

The system must not rely directly on:

```text
Auth::id()
```

inside models or background jobs.

The blame context must support:

* HTTP request actor
* Authenticated user actor
* Background job actor
* System actor

For background jobs, the originating user UUID must be passed in the job payload.

For system-driven tasks, use:

```text
00000000-0000-0000-0000-000000000000
```

The blame context must be explicitly set and cleared during job execution.

---

# 29. UUID v7

Use UUID v7 for application-generated identifiers.

Requirements:

* User IDs must use UUID v7.
* Session IDs must use UUID v7.
* Refresh-token IDs must use UUID v7.
* Outbox IDs must use UUID v7.
* Audit IDs must use UUID v7.

PostgreSQL UUID columns must be used.

Do not use auto-incrementing integer IDs for these entities.

---

# 30. Time Handling

All database timestamps must be timezone-aware.

Use PostgreSQL:

```text
TIMESTAMPTZ
```

Requirements:

* Store timestamps in UTC.
* Use timezone-aware application date/time objects.
* Never store local time as a naive timestamp.
* API responses must use ISO 8601 timestamps.
* Token timestamps must be handled consistently.

Example:

```text
2026-09-07T09:00:00Z
```

---

# 31. Optimistic Concurrency Control

Use OCC only where it provides value.

Tables that represent mutable domain entities may include:

```text
row_version INTEGER NOT NULL DEFAULT 0
```

Updates must verify the expected version.

Example:

```text
UPDATE users
SET name = ?, row_version = row_version + 1
WHERE id = ?
AND row_version = ?
```

If no row is updated, throw:

```text
ConcurrencyException
```

Do not add OCC to append-only tables such as audit logs.

Do not use OCC for refresh-token rotation; use row locking and transactions.

---

# 32. Soft Deletes

Soft deletes must be applied only where appropriate.

For the initial authentication milestone, soft deletes are not required for every table.

If soft deletes are implemented, the lifecycle fields must include:

```text
deleted_at
deleted_by
restored_at
restored_by
```

Use soft deletes only for entities that require recovery or lifecycle tracking.

Audit records must not be soft-deleted during normal operation.

---

# 33. Testing Requirements

The implementation must include automated tests.

### Unit tests

Test:

* JWT claim creation
* JWT claim validation
* Refresh-token hashing
* Refresh-token generation
* Refresh-token reuse detection
* Authentication service behavior
* Session revocation
* Error mapping

### Feature tests

Test:

* Registration
* Login
* Logout
* Refresh
* Logout-all
* Admin global logout
* Invalid credentials
* Expired access token
* Invalid refresh token
* Refresh-token reuse
* Rate limiting
* Standard error responses

### Concurrency tests

Test:

* Two simultaneous refresh requests using the same refresh token.
* Only one request succeeds.
* The second request detects reuse / consumption.
* The session is revoked when reuse is detected.

### Outbox tests

Test:

* User registration creates an outbox event.
* Outbox event is not created if the transaction fails.
* Publisher retries failed messages.
* Consumer does not process the same event twice.
* Failed email delivery is retried.

---

# 34. Static Analysis

The code must pass:

```text
PHPStan
Larastan
```

Requirements:

* Use strict typing where appropriate.
* Avoid unnecessary dynamic behavior.
* Avoid suppressing static-analysis errors without justification.
* Use DTOs / value objects where they improve clarity.
* Keep service dependencies explicit.
* Maintain clean boundaries between infrastructure and application logic.

---

# 35. Observability

The system must provide:

* Structured logs
* Request / trace IDs
* Authentication failure logging
* Refresh-token reuse logging
* Outbox failure logging
* Queue retry logging
* Audit events
* Health checks

Do not log:

* Passwords
* Raw refresh tokens
* Private JWT keys
* Full access tokens

---

# 36. Security Requirements

The implementation must:

* Use secure password hashing.
* Use RSA keys for JWT signing.
* Use HTTPS in production.
* Use secure random refresh tokens.
* Store refresh tokens only as hashes.
* Validate all JWT claims.
* Validate token expiration.
* Validate token not-before time.
* Support token invalidation.
* Support global logout.
* Protect authentication endpoints with rate limiting.
* Avoid user enumeration.
* Avoid sensitive information in error messages.
* Avoid logging secrets.
* Use database transactions for authentication state changes.

---

# 37. Deliverables

The implementation must include:

1. Database migrations
2. Eloquent models
3. Authentication services
4. JWT integration
5. Refresh-token management
6. Session management
7. API controllers
8. API request validation
9. API resources / response DTOs
10. Exception handling
11. Rate limiting
12. Outbox implementation
13. RabbitMQ jobs
14. Audit logging
15. Blame context
16. Automated tests
17. PHPStan / Larastan configuration
18. Documentation
19. API examples
20. Architecture decision records

---

# 38. Expected Architecture

Suggested structure:

```text
app/
├── Domain/
│   └── Auth/
│       ├── Models/
│       ├── Services/
│       ├── Exceptions/
│       ├── DTOs/
│       └── Contracts/
│
├── Application/
│   └── Auth/
│       ├── RegisterUser/
│       ├── LoginUser/
│       ├── RefreshToken/
│       ├── LogoutUser/
│       └── RevokeUserSessions/
│
├── Infrastructure/
│   ├── Jwt/
│   ├── Persistence/
│   ├── Outbox/
│   ├── RabbitMQ/
│   └── Audit/
│
└── Http/
    └── Controllers/
        └── Api/
            └── V1/
                └── AuthController.php
```

The exact directory structure may be adjusted to fit Laravel conventions, but business logic must not be placed directly inside controllers.

---

# 39. Definition of Done

The authentication module is complete when:

* Registration works.
* Login works.
* Access tokens are signed using RS256.
* JWT claims are validated.
* Refresh tokens are opaque and hashed.
* Refresh-token rotation is implemented.
* Refresh-token reuse detection is implemented.
* Sessions can be revoked.
* Logout-all works.
* Admin global logout works.
* JWT blocklisting is enabled.
* Rate limiting works.
* Standard error responses are implemented.
* Exception mapping is centralized.
* Registration and login emails are queued.
* Outbox events are persisted transactionally.
* RabbitMQ retries work.
* Consumers are idempotent.
* Audit logging works.
* Blame context works.
* UUID v7 is used.
* Timestamps are timezone-aware.
* Relevant OCC is implemented.
* Automated tests pass.
* PHPStan / Larastan passes.
* The implementation is documented.

---

# 40. Multi-Tenancy, System Maintenance & Guard Architecture (Milestones 4 & 5)

### Multi-Tenancy Onboarding & Provisioning Lifecycle
* **Onboarding**: `POST /api/v1/tenants/onboard` creates a tenant initialized in `pending` status and assigns the creator as `Owner`.
* **Admin Approval**: Platform SuperAdmin reviews and approves tenants via `POST /api/v1/admin/tenants/{tenant}/approve`, transitioning status to `provisioning`.
* **Idempotent Provisioning**: `ProvisionTenantService` and `ProvisionTenantJob` handle provisioning asynchronously using DB row locking (`lockForUpdate`), activating owner membership and transitioning tenant status to `active`.
* **Tenant Lifecycle Notifications**: Outbox events trigger asynchronous email delivery to tenant owners upon approval (`TenantApprovedMail`) and provisioning completion (`TenantProvisionedMail`).

### Transactional Outbox & Correlation Traceability
* **Correlation ID Tracking**: `outbox_messages.correlation_id` column and partial index enable distributed tracing across HTTP requests, Outbox records, queue workers, and audit logs.
* **High-Throughput Non-Blocking Publisher**: `php artisan outbox:publish` polls `pending` messages with PostgreSQL `FOR UPDATE SKIP LOCKED`, preventing lock contention across concurrent workers.
* **Outbox Pruning & Dead-Publisher Reaping**: `php artisan outbox:prune` cleans historical messages, while `php artisan outbox:reap` restores stuck `publishing` messages back to `pending`.

### System Maintenance & Self-Healing Reconciliation
* **Token & Session Cleanup**: `php artisan auth:cleanup-tokens` marks past-due tokens/sessions as `expired` and prunes terminal-state records older than retention threshold.
* **System Reconciliation Loop**: `php artisan system:reconcile` (scheduled hourly) resolves token-session state drift, bulk-revokes sessions/tokens for deactivated users, and recovers stuck provisioning tenants using `BlameContext::SYSTEM_ACTOR_ID` (`00000000-0000-0000-0000-000000000000`).

### Authentication Guard Driver & HTTP Client
* **Laravel `auth:api` Integration**: `App\Infrastructure\Jwt\JwtGuard` extends `Illuminate\Contracts\Auth\Guard` and is registered via `Auth::extend('jwt', ...)` in `AppServiceProvider`, allowing standard `middleware('auth:api')` routing.
* **PhpStorm HTTP Client**: Comprehensive `.http` files and environment matrix (`http-client.env.json`) in `requests/` provide ready-to-run API testing without external tools.
