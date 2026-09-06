# Project Context

## Objective

Build a production-grade, multi-tenant SaaS backend as a modular monolith.

The application is initially API-only. No frontend implementation is required in the first phase.

The project is intended to demonstrate senior-level software engineering and architecture skills through:

* Clean architecture
* Modular design
* Secure authentication
* Multi-tenant authorization
* Reliable asynchronous processing
* Database transaction design
* Observability
* Testing
* Concurrency control
* Production-readiness

## Technology Stack

* PHP 8.4
* Laravel 13
* PostgreSQL 18
* Redis
* RabbitMQ
* PgBouncer
* Docker
* Larastan/PHPStan
* JWT using PHP-Open-Source-Saver/jwt-auth
* RS256 asymmetric signing
* UUID identifiers
* UTC timestamps
* REST APIs only
* API versioning under `/api/v1`

## Architecture

Use a modular monolith.

Organize code by business capability rather than only by technical layer.

Suggested modules:

* Identity
* Authentication
* Tenancy
* Authorization
* Audit
* Outbox
* Notifications
* Shared infrastructure

Do not introduce microservices unless explicitly requested.

## Authentication Architecture

Use:

* Short-lived JWT access tokens
* Opaque rotating refresh tokens
* Refresh-token hashes only in PostgreSQL
* Redis JWT blocklist
* PostgreSQL authentication sessions
* Refresh-token family tracking
* Refresh-token reuse detection
* Session revocation
* Logout current session
* Logout all sessions
* Administrative account/session revocation

JWT access tokens should be approximately 15 minutes, configurable through application configuration.

Refresh tokens should be approximately 30 days, configurable through application configuration.

## JWT Claims

Required claims:

* `iss`
* `sub`
* `aud`
* `jti`
* `sid`
* `iat`
* `nbf`
* `exp`
* `token_type`
* `scope`

Tenant-scoped tokens additionally contain:

* `tenant_id`

Platform-scoped tokens may omit `tenant_id`.

The server must reject tokens with a future `nbf`.

JWT claims are not authoritative for mutable user, tenant, or membership status.

## Authorization Requirements

Tenant access requires:

```text
User.status = active
Tenant.status = active
TenantMembership.status = active
```

Platform roles:

* `SUPER_ADMIN`
* `PLATFORM_ADMIN`

Tenant roles:

* `TENANT_OWNER`
* `TENANT_ADMIN`
* `TENANT_MEMBER`

Never accept tenant identity through an `X-Tenant-ID` header.

Tenant context must come from the validated JWT and server-side authorization checks.

## Status Enums

Tenant statuses:

* `pending`
* `approved`
* `provisioning`
* `active`
* `provisioning_failed`
* `suspended`
* `rejected`

User statuses:

* `invited`
* `active`
* `suspended`
* `disabled`

Membership statuses:

* `invited`
* `active`
* `suspended`
* `removed`
* `expired`

## Database Rules

Use:

* UUID primary keys
* PostgreSQL `timestamptz`
* UTC timestamps
* Foreign keys
* Unique constraints
* Partial indexes where appropriate
* `row_version` for optimistic concurrency
* Soft deletes only where appropriate

Required tables:

* `users`
* `platform_roles`
* `user_platform_roles`
* `tenants`
* `tenant_memberships`
* `auth_sessions`
* `refresh_tokens`
* `audit_logs`
* `outbox_events`

Optional:

* `invitations`

Do not create a `jwt_blocklist` PostgreSQL table.

JWT blocklisting is Redis-only:

```text
auth:jwt:blocklist:{jti}
```

The Redis TTL must equal the remaining JWT lifetime.

## Error Response

Use this standard structure:

```json
{
  "success": false,
  "error": {
    "code": "AUTH_INVALID_CREDENTIALS",
    "message": "The provided credentials are invalid.",
    "details": null
  },
  "meta": {
    "request_id": "request-uuid"
  }
}
```

## HTTP Status Rules

* Registration: `201`
* Login: `200`
* Refresh: `200`
* Logout: `204`
* Validation failure: `422`
* Authentication failure: `401`
* Authorization failure: `403`
* Not found: `404`
* Conflict or optimistic-lock failure: `409`
* Rate limit: `429`
* Unexpected error: `500`

## Reliability Rules

* Use database transactions for security-sensitive state changes.
* Use row locking for refresh-token rotation.
* Use the outbox pattern for asynchronous events.
* Do not publish RabbitMQ events before the database transaction commits.
* Consumers must be idempotent.
* Notification failures must not cause login or registration to fail.
* Use structured logging.
* Include request ID, correlation ID, causation ID, actor ID, actor type, and source where applicable.

## Blame Context

Every audit event should capture:

* Actor ID
* Actor type
* Source
* Correlation ID
* Causation ID

System actions should use a well-known system actor identifier.

## Coding Rules

* Use strict typing.
* Prefer immutable value objects where useful.
* Use PHP backed enums.
* Use constructor dependency injection.
* Avoid business logic in controllers.
* Avoid fat models.
* Avoid static service calls in domain logic.
* Use explicit application services or use-case classes.
* Use Form Requests for input validation.
* Use Policies or dedicated authorization services.
* Use API Resources or explicit response DTOs.
* Use Laravel events/jobs only where they fit the architecture.
* Keep infrastructure details behind interfaces.
* Add tests for every behavior introduced.
* Do not implement future milestones prematurely.
* Do not modify unrelated files.
* Do not introduce new dependencies without explaining why.

## Implementation Style

For each milestone:

1. Explain the intended design.
2. List files to create or modify.
3. Implement only the milestone scope.
4. Provide migrations.
5. Provide application code.
6. Provide tests.
7. Provide configuration changes.
8. Provide commands to run.
9. Explain acceptance criteria.
10. Identify known limitations and deferred work.

Do not generate the entire project at once.
