# Multi-Tenant SaaS Backend

## Implementation Milestone Sequence

## 1. Project Objective

Build a production-grade, multi-tenant SaaS backend as a modular monolith using:

* PHP 8.4
* Laravel 13
* PostgreSQL 18
* Redis
* RabbitMQ
* PgBouncer
* Docker
* Larastan/PHPStan
* JWT with RS256
* UUID identifiers
* UTC timestamps
* REST APIs only during the initial phase

The implementation must be completed incrementally. Each milestone must be implemented, tested, reviewed, and committed before starting the next milestone.

---

## 2. Implementation Rules

For every milestone:

1. Read the project context and architecture documents.
2. Inspect the current repository.
3. Confirm the current milestone status.
4. Explain the proposed design.
5. List files to create or modify.
6. Implement only the current milestone.
7. Add or update automated tests.
8. Run migrations, tests, and static analysis.
9. Update the milestone status only after acceptance criteria pass.
10. Commit the completed work.

Do not implement future milestones prematurely.

Do not modify unrelated files.

If a requirement is ambiguous, ask a focused question before coding.

---

## 3. Status Values

Use one of these statuses:

```text
not_started
in_progress
blocked
completed
```

---

## 4. Milestone Overview

| No. | Milestone                              | Status      |
| --: | -------------------------------------- | ----------- |
|   0 | Project Foundation                     | completed   |
|   1 | Database Foundation                    | not_started |
|   2 | Identity and Registration              | not_started |
|   3 | Authentication Sessions                | not_started |
|   4 | JWT Access Tokens                      | not_started |
|   5 | Login                                  | not_started |
|   6 | Refresh-Token Rotation                 | not_started |
|   7 | Logout and Revocation                  | not_started |
|   8 | Tenant Authorization                   | not_started |
|   9 | Outbox and RabbitMQ                    | not_started |
|  10 | Notifications                          | not_started |
|  11 | Audit and Blame Context                | not_started |
|  12 | Rate Limiting and Abuse Protection     | not_started |
|  13 | Concurrency and Consistency Hardening  | not_started |
|  14 | Observability and Production Hardening | not_started |
|  15 | Final Architecture and Security Review | not_started |

---

# Milestone 0 — Project Foundation

## Objective

Establish the Laravel application foundation and local infrastructure.

## Scope

* Laravel 13 project setup
* PHP 8.4 configuration
* Docker configuration
* PostgreSQL connection
* Redis connection
* RabbitMQ connection
* PgBouncer configuration where applicable
* Environment configuration
* API version prefix `/api/v1`
* Liveness endpoint
* Readiness endpoint
* Request ID middleware
* Standard JSON error response
* Central exception handling
* Initial modular directory structure
* Larastan/PHPStan setup
* Base test configuration

## Out of Scope

* Registration
* Login
* JWT
* Refresh tokens
* Users
* Tenants
* Memberships
* Business events
* Notifications

## Acceptance Criteria

* Application starts successfully through Docker.
* PostgreSQL connection works.
* Redis connection works.
* RabbitMQ connection works.
* `/api/v1/health/live` returns HTTP 200.
* `/api/v1/health/ready` checks required dependencies.
* Every request receives or generates a request ID.
* Errors use the standard JSON error structure.
* Tests pass.
* Static analysis passes.

## Deliverables

* Docker files
* Environment example file
* Health controllers and services
* Request ID middleware
* Exception handler
* API response helpers
* Test setup
* Project documentation

### Completion Record

- Status: completed
- Completed on: 2026-09-06
- Deliverables:
  - `App\Modules\Shared\Infrastructure\Http\Middleware\RequestCorrelationMiddleware`
  - `App\Modules\Shared\Infrastructure\Http\Responses\ApiResponse`
  - `App\Modules\Shared\Infrastructure\Http\Controllers\LiveHealthController`
  - `App\Modules\Shared\Infrastructure\Http\Controllers\ReadyHealthController`
  - Central exception handler in `bootstrap/app.php`
  - `tests/Feature/RequestIdMiddlewareTest.php`
  - `tests/Feature/HealthTest.php`
- Acceptance Criteria: All passed. Standard JSON error envelope, correlation tracking, and `/api/v1/health/*` routes live.

---

# Milestone 1 — Database Foundation

## Objective

Create the foundational identity and tenancy database schema.

## Scope

Create migrations, models, enums, relationships, constraints, and seeders for:

* `users`
* `platform_roles`
* `user_platform_roles`
* `tenants`
* `tenant_memberships`

Implement:

* UUID primary keys
* PostgreSQL `timestamptz`
* User status enum
* Tenant status enum
* Membership status enum
* Foreign keys
* Unique constraints
* Partial indexes
* `row_version`
* Soft deletes where appropriate
* Platform-role seeder

## Out of Scope

* Authentication
* JWT
* Login
* Refresh tokens
* Session management
* RabbitMQ events

## Acceptance Criteria

* All migrations run successfully.
* All migrations can be rolled back.
* Foreign keys are enforced.
* Duplicate active emails are rejected.
* Duplicate active tenant slugs are rejected.
* User, tenant, and membership relationships work.
* Enum casts work correctly.
* Database tests pass.

---

# Milestone 2 — Identity and Registration

## Objective

Implement secure user registration.

## Scope

* Registration endpoint
* Registration request validation
* Email normalization
* Password hashing
* Duplicate-email handling
* User creation
* Initial user status
* Registration response
* Registration audit event preparation
* Registration outbox event preparation

## Out of Scope

* Login
* JWT issuance
* Refresh tokens
* Tenant provisioning
* Notification delivery

## Acceptance Criteria

* Valid registration creates a user.
* Passwords are never stored in plain text.
* Email uniqueness is case-insensitive.
* Invalid input returns HTTP 422.
* Duplicate email returns HTTP 409 or the agreed error response.
* Registration returns HTTP 201.
* Registration tests pass.

---

# Milestone 3 — Authentication Sessions

## Objective

Implement durable login-session management.

## Scope

Create and implement:

* `auth_sessions`
* Session creation
* Session lookup
* Session expiration
* Session revocation
* Current-session logout service
* Logout-all-sessions service
* Session repository
* Session-related exceptions

## Out of Scope

* JWT issuance
* Refresh-token rotation
* Login endpoint
* RabbitMQ publishing

## Acceptance Criteria

* A session can be created for a user.
* A session can be revoked.
* Revoked sessions cannot be used for authentication.
* Logout-all revokes all active sessions for a user.
* Session queries use the primary database where consistency is required.
* Session tests pass.

---

# Milestone 4 — JWT Access Tokens

## Objective

Implement secure RS256-signed JWT access tokens.

## Scope

* RS256 key configuration
* JWT issuer service
* JWT claims
* Access-token TTL
* Token type validation
* Issuer validation
* Audience validation
* `iat`, `nbf`, and `exp` validation
* JWT authentication middleware
* Authenticated-user context
* Redis JWT blocklist
* JWT blocklist TTL handling

## JWT Claims

Required claims:

```text
iss
sub
aud
jti
sid
iat
nbf
exp
token_type
scope
```

Tenant-scoped tokens additionally contain:

```text
tenant_id
```

## Redis Blocklist

Use:

```text
auth:jwt:blocklist:{jti}
```

The TTL must equal the remaining access-token lifetime.

## Out of Scope

* Refresh-token rotation
* Login endpoint
* Tenant authorization
* Notifications

## Acceptance Criteria

* Tokens are signed using RS256.
* Invalid signatures are rejected.
* Expired tokens are rejected.
* Future `nbf` values are rejected.
* Invalid issuer or audience is rejected.
* Blocklisted JTIs are rejected.
* Authentication middleware exposes the authenticated user and session.
* JWT tests pass.

---

# Milestone 5 — Login

## Objective

Implement secure user login.

## Scope

* Login request validation
* Credential verification
* User-status validation
* Session creation
* Access-token issuance
* Refresh-token family root creation
* Login response
* Login audit event preparation
* Login outbox event preparation
* Login rate limiting

## Authentication Rules

Authentication must fail when the user is:

* Suspended
* Disabled
* Deleted
* Otherwise not eligible to authenticate

## Out of Scope

* Refresh-token rotation
* Tenant authorization
* Notification consumers
* RabbitMQ publishing

## Acceptance Criteria

* Valid credentials return HTTP 200.
* Invalid credentials return the standard 401 response.
* Suspended and disabled users cannot log in.
* A session is created on successful login.
* An access token is issued.
* A refresh-token family root is created.
* Login is rate-limited.
* Login tests pass.

---

# Milestone 6 — Refresh-Token Rotation

## Objective

Implement secure, rotating opaque refresh tokens.

## Scope

Create and implement:

* `refresh_tokens`
* Opaque token generation
* Token hashing
* Hash-only persistence
* Refresh-token lookup
* Transactional rotation
* Row locking
* Parent-child token relationships
* Token-family tracking
* Expiration checks
* Session checks
* User-status checks
* Reuse detection
* Family revocation
* Session revocation after reuse
* Refresh endpoint

## Security Rules

* Never store raw refresh tokens.
* Rotate on every successful refresh.
* Mark the previous token as used.
* Create the replacement token in the same transaction.
* Lock the token row with `FOR UPDATE`.
* Reuse of a previously used token revokes the entire family.
* Reuse detection revokes the associated session.

## Acceptance Criteria

* Valid refresh tokens return a new access token.
* Previous refresh tokens cannot be reused.
* Expired refresh tokens are rejected.
* Revoked sessions cannot refresh.
* Refresh-token hashes are stored only in PostgreSQL.
* Concurrent refresh requests are handled safely.
* Reuse detection revokes the token family.
* Refresh tests pass.

---

# Milestone 7 — Logout and Revocation

## Objective

Implement user and administrator-driven revocation.

## Scope

* Logout current session
* Logout all sessions
* JWT blocklisting
* Refresh-token revocation
* Administrative session revocation
* Administrative user revocation
* Revocation audit events
* Revocation response handling

## Out of Scope

* Tenant-role authorization
* Notifications
* Advanced monitoring

## Acceptance Criteria

* Current logout returns HTTP 204.
* Current JWT is blocklisted.
* Current session is revoked.
* Associated refresh-token family is revoked.
* Logout-all revokes all active sessions.
* Revoked access tokens are rejected.
* Revoked refresh tokens are rejected.
* Revocation tests pass.

---

# Milestone 8 — Tenant Authorization

## Objective

Implement secure tenant-scoped authorization.

## Scope

* Tenant context from JWT
* Tenant-active checks
* Membership-active checks
* Platform-role authorization
* Tenant-role authorization
* Authorization middleware or policies
* Tenant access denial behavior
* Tenant-scoped API examples

## Required Access Conditions

```text
User.status = active
Tenant.status = active
Membership.status = active
```

## Rules

* Never accept tenant identity through `X-Tenant-ID`.
* Tenant context must come from the validated JWT.
* JWT status claims must not be treated as authoritative.
* Current database state must be checked.

## Acceptance Criteria

* Users can access only authorized tenants.
* Suspended tenants cannot be accessed.
* Suspended or removed memberships cannot access the tenant.
* Platform administrators can perform permitted platform operations.
* Tenant roles are enforced.
* Cross-tenant access is rejected.
* Authorization tests pass.

---

# Milestone 9 — Outbox and RabbitMQ

## Objective

Implement reliable asynchronous event publishing.

## Scope

Create and implement:

* `outbox_events`
* Outbox event model
* Outbox repository
* Transactional event recording
* Publisher job
* RabbitMQ integration
* Retry policy
* Dead-letter handling
* Idempotent consumers
* Publishing metrics

## Rules

* Business mutations and outbox inserts must use the same transaction.
* Do not publish directly before transaction commit.
* Consumers must be idempotent.
* Failed messages must be retried.
* Permanently failed messages must be moved to a dead-letter mechanism.

## Acceptance Criteria

* Outbox events are written transactionally.
* Events are published after commit.
* Failed publishing is retried.
* Consumers do not process the same event twice.
* Failed events are observable.
* Outbox tests pass.

---

# Milestone 10 — Notifications

## Objective

Implement asynchronous registration and authentication notifications.

## Scope

* Registration notification event
* Login notification event, if required
* Notification jobs
* RabbitMQ consumers
* Retry behavior
* Failure logging
* Idempotency
* Notification status tracking

## Rules

Notification failure must not cause registration or login to fail.

## Acceptance Criteria

* Registration notification is queued asynchronously.
* Login notification is queued asynchronously if enabled.
* RabbitMQ failures do not fail authentication.
* Failed notifications are retried.
* Duplicate events do not send duplicate notifications.
* Notification tests pass.

---

# Milestone 11 — Audit and Blame Context

## Objective

Implement consistent security and business audit logging.

## Scope

Create and implement:

* `audit_logs`
* Blame Context Manager
* HTTP actor resolution
* Queue actor propagation
* System actor handling
* Audit service
* Audit event persistence
* Correlation ID
* Causation ID
* Audit middleware
* Audit tests

## Audit Context

Each audit event should capture:

* Actor ID
* Actor type
* Source
* Correlation ID
* Causation ID
* Tenant ID where applicable
* Entity type
* Entity ID
* Action
* Relevant metadata

## Acceptance Criteria

* HTTP actions have actor context.
* Queue actions preserve actor context.
* System actions use a system actor.
* Authentication events are audited.
* Tenant authorization events can be audited.
* Audit records are immutable during normal operation.
* Audit tests pass.

---

# Milestone 12 — Rate Limiting and Abuse Protection

## Objective

Protect authentication and sensitive APIs from abuse.

## Scope

Implement configurable Redis-backed limits for:

* Registration
* Login
* Refresh
* Logout
* Authenticated APIs
* Administrative operations

Suggested initial limits:

```text
Registration: 5 per minute per IP
Login: 5 per minute per IP and normalized email
Refresh: 20 per minute per session
Logout: 20 per minute per user
Authenticated APIs: 100 per minute per user
```

## Acceptance Criteria

* Rate limits are configurable.
* Exceeded limits return HTTP 429.
* Responses include useful retry information.
* Rate limits use appropriate keys.
* Login and registration abuse is limited.
* Rate-limit tests pass.

---

# Milestone 13 — Concurrency and Consistency Hardening

## Objective

Harden the system against race conditions and duplicate operations.

## Scope

* `row_version` handling
* Optimistic concurrency exceptions
* Duplicate-operation handling
* Idempotency keys where required
* Concurrent refresh tests
* Concurrent membership-update tests
* Transaction-boundary review
* Deadlock and retry strategy

## Rules

Use idempotency only for operations where retries may create duplicate effects.

Examples:

* Registration with a client request ID
* Tenant provisioning
* Payment-like operations
* Event consumers
* Administrative commands

Do not force every endpoint to be idempotent.

## Acceptance Criteria

* Concurrent updates do not silently overwrite changes.
* Optimistic-lock conflicts return HTTP 409.
* Refresh-token races are handled safely.
* Retry-safe operations are idempotent.
* Deadlocks are handled according to the agreed strategy.
* Concurrency tests pass.

---

# Milestone 14 — Observability and Production Hardening

## Objective

Make the application operationally observable and production-ready.

## Scope

* Structured logging
* Request IDs
* Correlation IDs
* Metrics
* Liveness checks
* Readiness checks
* PostgreSQL connectivity checks
* Redis connectivity checks
* RabbitMQ connectivity checks
* Queue-depth monitoring
* Security-event monitoring
* Configuration validation
* Production deployment documentation

## Acceptance Criteria

* Logs are structured.
* Requests can be traced using request and correlation IDs.
* Health endpoints distinguish liveness from readiness.
* Dependency failures are visible.
* Authentication failures are measurable.
* Queue failures are measurable.
* Production configuration is documented.

---

# Milestone 15 — Final Architecture and Security Review

## Objective

Perform a complete review before presenting the project as production-grade.

## Review Areas

* Authentication security
* JWT validation
* Refresh-token rotation
* Reuse detection
* Session revocation
* Tenant isolation
* Role authorization
* Database constraints
* Transaction boundaries
* Outbox reliability
* RabbitMQ retry behavior
* Audit completeness
* Rate limiting
* Concurrency
* Error handling
* Logging
* Monitoring
* Test coverage
* Static analysis
* Dependency security
* Performance
* Failure modes

## Deliverables

* Security review document
* Architecture review document
* API contract review
* Database review
* Test coverage report
* Static-analysis report
* Deployment guide
* Interview-ready architecture explanation
* Known limitations document

## Acceptance Criteria

* All critical tests pass.
* Static analysis passes.
* No known critical security issues remain.
* Architecture decisions are documented.
* Deferred features are explicitly listed.
* The project can be explained clearly in a senior-level interview.

---

# 5. Milestone Completion Record

After completing each milestone, update its section with:

```markdown
### Completion Record

- Status: completed
- Completed on: YYYY-MM-DD
- Commit:
- Tests:
- Static analysis:
- Migration status:
- Known limitations:
- Follow-up work:
```

---

# 6. Recommended Repository Documents

```text
docs/
├── ai/
│   ├── project-context.md
│   ├── milestone-prompt.md
│   └── session-checkpoint.md
│
├── requirements/
│   ├── authentication.md
│   ├── tenancy.md
│   └── api-contracts.md
│
├── architecture/
│   ├── implementation-blueprint.md
│   ├── database-schema.md
│   └── decisions/
│
└── roadmap/
    └── milestone-sequence.md
```

# 7. AI Session Startup Prompt

Use this prompt whenever you start implementation:

```text
Read these files before coding:

- docs/ai/project-context.md
- docs/roadmap/milestone-sequence.md
- docs/architecture/implementation-blueprint.md
- docs/architecture/database-schema.md

Find the next milestone whose status is `not_started`.

Implement only that milestone.

Before coding:
1. Inspect the repository.
2. Confirm the current milestone.
3. Explain the design.
4. List files to create or modify.
5. Identify dependencies and risks.

After coding:
1. Add tests.
2. Run migrations.
3. Run tests.
4. Run static analysis.
5. Show the acceptance-criteria results.
6. Update the milestone status only if everything passes.
7. Provide a completion record.

Do not implement future milestones.
Do not modify unrelated files.
```
