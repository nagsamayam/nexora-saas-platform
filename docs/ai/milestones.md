Milestone 1 — Authentication Core [Completed]

- Registration (`POST /api/v1/auth/register`)
- Login (`POST /api/v1/auth/login`)
- RS256 JWT access tokens with blacklist validation & `auth_version` invalidation
- Opaque refresh-token rotation with reuse detection & lineage revocation
- Session revocation (`POST /api/v1/auth/logout`, `DELETE /api/v1/auth/sessions/{session}`)
- Global logout (`POST /api/v1/auth/logout-all`)
- Rate limiting per endpoint
- Dynamic OpenSSL JWT key generation CLI (`php artisan jwt:generate-keys`)
- Standardized API response format (`ApiResponse`) & live/ready health checks
- Comprehensive Pest & PHPStan test coverage

Milestone 2 — Reliability [Completed]

- Transactional Outbox pattern (`outbox_messages`) with dedicated `correlation_id` column & partial index
- End-to-end distributed trace correlation propagation across HTTP $\rightarrow$ Outbox $\rightarrow$ Queue Workers $\rightarrow$ Audit
- Non-blocking PostgreSQL publisher worker (`FOR UPDATE SKIP LOCKED` in `php artisan outbox:publish`)
- RabbitMQ queue integration & exponential retry backoff with dead-letter tracking
- Idempotent consumers with event-key deduplication
- Asynchronous email notifications (`WelcomeRegistrationMail`, `LoginNotificationMail`)
- Structured append-only audit logging (`audit_logs`)
- Centralized BlameContext management (`X-Correlation-ID`, `X-Causation-ID`, `X-Request-ID`, actor tracking)

Milestone 3 — Architecture Hardening [Completed]

- Optimistic Concurrency Control (`HasRowVersion`, `row_version`, `ConcurrencyException` / 409 Conflict)
- Session management endpoints (`GET /api/v1/auth/sessions`, `DELETE /api/v1/auth/sessions/{session}`)
- Admin global user session revocation (`POST /api/v1/admin/users/{user}/revoke-sessions`)
- Multi-tenant foundation integration (`UserPlatformRole`, `PlatformRoleSeeder`)
- Architecture Decision Records (ADRs 0001 through 0006 in `docs/adr/`)
- Security & unauthorized access test suites

Milestone 4 — Multi-Tenant Architecture & Onboarding [Completed]

- Tenant onboarding API (`POST /api/v1/tenants/onboard`) with default `pending` status
- Automatic Owner membership initialization
- Slug generation, validation, and collision resolution
- Admin approval workflow (`POST /api/v1/admin/tenants/{tenant}/approve`)
- Asynchronous, idempotent tenant provisioning (`ProvisionTenantService`, `ProvisionTenantJob`)
- Asynchronous owner notifications (`TenantApprovedMail`, `TenantProvisionedMail`)
- Outbox event publishing & audit logging for tenant lifecycle (`TenantCreated`, `TenantApproved`, `TenantProvisioningStarted`, `TenantProvisioned`)

Milestone 5 — System Maintenance & Reconciliation [Completed]

- Automated token & session cleanup (`php artisan auth:cleanup-tokens`)
- Outbox message pruning & stuck publisher reaping (`php artisan outbox:prune`, `php artisan outbox:reap`)
- Automated self-healing system reconciliation (`php artisan system:reconcile`) with `BlameContext::SYSTEM_ACTOR_ID`
- Laravel native `auth:api` custom JWT Guard driver (`JwtGuard`) registered in `config/auth.php` and `AppServiceProvider`
- PhpStorm environment-based HTTP client test suite (`requests/*.http`, `http-client.env.json`)
- Dynamic timezone resolution from `config('app.timezone')`

