# Multi-Tenant SaaS Backend

A production-oriented, multi-tenant SaaS backend built as a modular monolith using Laravel 13 and PHP 8.4.

The project focuses on secure authentication, tenant isolation, reliable asynchronous processing, database consistency, observability, and architecture suitable for senior software engineering and solution architect interviews.

> **Project status:** Under active development
> **Current implementation approach:** Milestone-based development
> **Initial interface:** REST API only

---

## 1. Technology Stack

| Technology       | Purpose                                              |
| ---------------- | ---------------------------------------------------- |
| PHP 8.4          | Application runtime                                  |
| Laravel 13       | Backend framework                                    |
| PostgreSQL 18    | Primary relational database                          |
| Redis            | Cache, rate limiting, JWT blocklist, temporary locks |
| RabbitMQ         | Asynchronous messaging                               |
| PgBouncer        | PostgreSQL connection pooling                        |
| Docker           | Local development infrastructure                     |
| Larastan/PHPStan | Static analysis                                      |
| PHPUnit/Pest     | Automated testing                                    |
| JWT with RS256   | Access-token authentication                          |
| UUID             | Entity identifiers                                   |

---

## 2. Architecture

The application is implemented as a **modular monolith**.

Business capabilities are separated into modules while remaining within one deployable Laravel application.

### Planned modules

```text
app/
├── Modules/
│   ├── Identity/
│   ├── Authentication/
│   ├── Tenancy/
│   ├── Authorization/
│   ├── Audit/
│   ├── Outbox/
│   ├── Notifications/
│   └── Shared/
│
├── Http/
├── Console/
├── Jobs/
├── Providers/
└── Support/
```

The exact directory structure may evolve during implementation, but business logic should remain organized by capability rather than only by framework layer.

---

## 3. Core Design Principles

* Modular monolith before microservices
* Thin controllers
* Application services for use cases
* Explicit dependency injection
* Strong input validation
* Secure-by-default authentication
* Tenant isolation enforced server-side
* Database transactions for security-sensitive operations
* Idempotent asynchronous consumers
* Structured logging
* Automated testing
* Static analysis
* Explicit architecture decisions
* Incremental milestone-based delivery

---

## 4. Authentication Architecture

The authentication system uses a hybrid token architecture:

```text
Short-lived JWT access token
+
Opaque rotating refresh token
+
PostgreSQL authentication session
+
Redis JWT blocklist
```

### Access tokens

Access tokens are:

* JWT-based
* Signed using RS256
* Short-lived
* Approximately 15 minutes by default
* Validated on every authenticated request
* Revocable through Redis blocklisting

### Refresh tokens

Refresh tokens are:

* Opaque random values
* Stored only as hashes
* Stored in PostgreSQL
* Rotated after every successful refresh
* Associated with a session
* Associated with a refresh-token family
* Protected against reuse

### JWT claims

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

JWT claims identify the request context but do not replace database checks for mutable state.

---

## 5. JWT Blocklist

JWT blocklisting is implemented using Redis.

No PostgreSQL `jwt_blocklist` table is required.

### Redis key

```text
auth:jwt:blocklist:{jti}
```

### TTL

The Redis entry expires when the JWT expires.

```text
TTL = JWT expiration time - current time
```

Redis is used for fast runtime checks. PostgreSQL stores durable session, refresh-token, audit, and security state.

---

## 6. Multi-Tenancy

Tenant identity is never accepted through an `X-Tenant-ID` header.

Tenant context must come from the validated JWT and be checked against current database state.

Tenant access requires:

```text
User.status = active
Tenant.status = active
TenantMembership.status = active
```

### Platform roles

```text
SUPER_ADMIN
PLATFORM_ADMIN
```

### Tenant roles

```text
TENANT_OWNER
TENANT_ADMIN
TENANT_MEMBER
```

---

## 7. Status Values

### User statuses

```text
invited
active
suspended
disabled
```

### Tenant statuses

```text
pending
approved
provisioning
active
provisioning_failed
suspended
rejected
```

### Membership statuses

```text
invited
active
suspended
removed
expired
```

Mutable status values are checked from PostgreSQL and are not treated as authoritative JWT claims.

---

## 8. Database

The application uses PostgreSQL as the source of truth for durable state.

### Required tables

```text
users
platform_roles
user_platform_roles
tenants
tenant_memberships
auth_sessions
refresh_tokens
audit_logs
outbox_events
```

### Optional table

```text
invitations
```

### Database conventions

* UUID primary keys
* `timestamptz` columns
* UTC timestamps
* Foreign-key constraints
* Unique constraints
* Partial indexes where appropriate
* `row_version` for optimistic concurrency
* Soft deletes only where appropriate

### Read replicas

Read replicas may be used for reporting and non-critical read-heavy workloads.

Security-critical operations must use the primary database, including:

* Refresh-token rotation
* Session revocation
* User-status checks immediately after changes
* Tenant and membership authorization where consistency is required
* Audit writes
* Outbox writes

---

## 9. Asynchronous Processing

RabbitMQ is used for asynchronous processing.

The outbox pattern is used to ensure that database changes and event creation are committed atomically.

```text
Business transaction
    ├── Update business data
    ├── Insert outbox event
    └── Commit
            ↓
      Outbox publisher
            ↓
         RabbitMQ
            ↓
        Consumer
```

### Reliability requirements

* Events are written transactionally.
* Events are published after commit.
* Consumers are idempotent.
* Failed messages are retried.
* Permanently failed messages use dead-letter handling.
* Notification failures do not fail registration or login.

---

## 10. Audit Logging

Audit logs capture important security and business actions.

Audit context may include:

* Actor ID
* Actor type
* Source
* Correlation ID
* Causation ID
* Tenant ID
* Entity type
* Entity ID
* Action
* Metadata
* IP address
* User agent
* Timestamp

Audit records are intended to be immutable during normal application operation.

---

## 11. API

The initial API is versioned under:

```text
/api/v1
```

### Health endpoints

```http
GET /api/v1/health/live
GET /api/v1/health/ready
```

### Planned authentication endpoints

```http
POST /api/v1/auth/register
POST /api/v1/auth/login
POST /api/v1/auth/refresh
POST /api/v1/auth/logout
POST /api/v1/auth/logout-all
```

Additional administrative and tenant endpoints will be introduced in later milestones.

---

## 12. Standard Error Response

The API uses a consistent error structure:

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

### HTTP status conventions

| Situation                       | Status |
| ------------------------------- | -----: |
| Registration successful         |    201 |
| Login successful                |    200 |
| Refresh successful              |    200 |
| Logout successful               |    204 |
| Validation failure              |    422 |
| Authentication failure          |    401 |
| Authorization failure           |    403 |
| Resource not found              |    404 |
| Conflict or concurrency failure |    409 |
| Rate limit exceeded             |    429 |
| Unexpected server error         |    500 |

---

## 13. Local Development

### Prerequisites

Install:

* Docker
* Docker Compose
* Git
* Make, optional

### Clone the repository

```bash
git clone <repository-url>
cd <project-directory>
```

### Configure environment

```bash
cp .env.example .env
```

Update the environment values as required.

### Start infrastructure

```bash
docker compose up -d
```

### Install PHP dependencies

If PHP and Composer are available locally:

```bash
composer install
```

Alternatively, run Composer through the application container.

### Generate application key

```bash
php artisan key:generate
```

### Run migrations

```bash
php artisan migrate
```

### Run seeders

```bash
php artisan db:seed
```

### Start the application

```bash
php artisan serve
```

The application will normally be available at:

```text
http://localhost:8000
```

---

## 14. Common Commands

### Run tests

```bash
php artisan test
```

### Run a specific test

```bash
php artisan test --filter=AuthenticationTest
```

### Run static analysis

```bash
vendor/bin/phpstan analyse
```

### Format code

Use the project’s configured formatter or code-style command.

### Run migrations

```bash
php artisan migrate
```

### Roll back the latest migration batch

```bash
php artisan migrate:rollback
```

### Clear application caches

```bash
php artisan optimize:clear
```

### Inspect routes

```bash
php artisan route:list
```

---

## 15. Project Documentation

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

### Important documents

| Document                                        | Purpose                                            |
| ----------------------------------------------- | -------------------------------------------------- |
| `docs/ai/project-context.md`                    | Stable project-wide rules for AI coding assistants |
| `docs/roadmap/milestone-sequence.md`            | Ordered implementation plan                        |
| `docs/architecture/implementation-blueprint.md` | Architecture and implementation design             |
| `docs/architecture/database-schema.md`          | Database schema and constraints                    |
| `docs/requirements/authentication.md`           | Authentication requirements                        |
| `docs/ai/milestone-prompt.md`                   | Reusable AI implementation prompt                  |
| `docs/ai/session-checkpoint.md`                 | Current implementation progress                    |

---

## 16. Milestone-Based Development

Development is intentionally divided into milestones.

### Current milestone sequence

```text
0. Project Foundation
1. Database Foundation
2. Identity and Registration
3. Authentication Sessions
4. JWT Access Tokens
5. Login
6. Refresh-Token Rotation
7. Logout and Revocation
8. Tenant Authorization
9. Outbox and RabbitMQ
10. Notifications
11. Audit and Blame Context
12. Rate Limiting and Abuse Protection
13. Concurrency and Consistency Hardening
14. Observability and Production Hardening
15. Final Architecture and Security Review
```

Each milestone must be:

* Implemented
* Tested
* Reviewed
* Documented
* Committed

before the next milestone begins.

---

## 17. AI-Assisted Development

AI coding assistants should read the following files before implementation:

```text
docs/ai/project-context.md
docs/roadmap/milestone-sequence.md
docs/architecture/implementation-blueprint.md
docs/architecture/database-schema.md
```

The AI must:

* Inspect the existing repository
* Implement only the requested milestone
* Avoid unrelated changes
* Add tests
* Explain design decisions
* Run migrations and tests
* Run static analysis
* Report assumptions and limitations

### Suggested prompt

```text
Read the project context, roadmap, implementation blueprint, and database schema.

Find the next milestone whose status is `not_started`.

Implement only that milestone.

Before coding:
1. Inspect the repository.
2. Confirm the milestone scope.
3. Explain the proposed design.
4. List files to create or modify.
5. Identify security, transaction, and concurrency concerns.

After coding:
1. Add tests.
2. Run migrations.
3. Run the test suite.
4. Run static analysis.
5. Report acceptance-criteria results.
6. Update the milestone status only if all criteria pass.

Do not implement future milestones.
Do not modify unrelated files.
```

---

## 18. Git Workflow

Use one commit or a small logical set of commits per milestone.

Example:

```bash
git checkout -b milestone/00-project-foundation
```

After completion:

```bash
git add .
git commit -m "Implement project foundation"
```

Suggested branch naming:

```text
milestone/00-project-foundation
milestone/01-database-foundation
milestone/02-registration
milestone/03-auth-sessions
```

---

## 19. Security Considerations

The project must follow these rules:

* Never store plain-text passwords.
* Never store raw refresh tokens.
* Never log access tokens or refresh tokens.
* Never accept tenant identity from an untrusted header.
* Validate JWT signature and claims.
* Check user, tenant, and membership status from the database.
* Use transactions for refresh-token rotation.
* Use row locking for refresh-token rotation.
* Revoke refresh-token families after reuse detection.
* Use Redis TTL for JWT blocklist entries.
* Apply rate limiting to authentication endpoints.
* Use structured security logging.
* Avoid exposing internal exception details in API responses.

---

## 20. Known Deferred Features

The following features are intentionally deferred until later milestones:

* Frontend implementation
* React application
* Advanced tenant provisioning
* Billing
* Subscription management
* Payment processing
* File storage
* Search infrastructure
* Analytics dashboards
* Microservice extraction
* Advanced distributed tracing
* Persistent JWT blocklist history

---

## 21. License

Add the project license here.

Example:

```text
MIT License
```

---

## 22. Maintainer

Add maintainer or organization information here.
