# Implementation Milestones: Multi-Tenant SaaS Platform

This document defines the sequential implementation milestones for building the production-grade, multi-tenant modular monolith SaaS backend according to the specifications in `docs/ai/project-context.md`.

All AI agents and developers must execute these milestones in sequence, adhering strictly to the architecture, security rules, and code quality constraints.

---

## Milestone 1: Foundation & Shared Infrastructure (Core Monolith Setup)

### 1. Objective
Establish the baseline domain primitives, standardized API response formatters, centralized exception handling, request correlation tracing, and system-wide state enums.

### 2. Scope & Key Deliverables
- **Traits & Primitives:**
  - `HasUuid`: Primary key UUID generation for Eloquent models.
  - `HasRowVersion`: Optimistic locking via integer `row_version` increments.
- **Enums (PHP Backed Enums with strict typing):**
  - `UserStatus`: `invited`, `active`, `suspended`, `disabled`.
  - `TenantStatus`: `pending`, `approved`, `provisioning`, `active`, `provisioning_failed`, `suspended`, `rejected`.
  - `TenantMembershipStatus`: `invited`, `active`, `suspended`, `removed`, `expired`.
  - `PlatformRole`: `SUPER_ADMIN`, `PLATFORM_ADMIN`.
  - `TenantRole`: `TENANT_OWNER`, `TENANT_ADMIN`, `TENANT_MEMBER`.
- **API Response Formatting & Exception Handling:**
  - Standard JSON envelope: `success` (bool), `data` (nullable), `error` (`code`, `message`, `details`), `meta` (`request_id`).
  - Global exception handler mapping HTTP status codes:
    - 200 (OK), 201 (Created), 204 (No Content)
    - 400 (Bad Request), 401 (Unauthenticated), 403 (Unauthorized), 404 (Not Found), 409 (Conflict/Optimistic Lock Failure), 422 (Validation), 429 (Rate Limit), 500 (Internal Error).
- **Correlation & Tracing Middleware:**
  - Middleware injecting `X-Request-ID`, `X-Correlation-ID`, and `X-Causation-ID` into request headers and Monolog structured context.

### 3. Acceptance Criteria
- All standard and error responses match the required JSON structure.
- All requests have unique UUID correlation tracking attached in log outputs.

---

## Milestone 2: Identity & Authentication Core (JWT + Sessions)

### 1. Objective
Implement asymmetric (RS256) JWT authentication, database authentication sessions, rotating refresh tokens with reuse detection, and Redis JWT blocklisting.

### 2. Scope & Key Deliverables
- **Database Migrations & Models:**
  - `users`: `id` (UUID), `email`, `password_hash`, `status`, `row_version`, `created_at`, `updated_at`, `deleted_at`.
  - `auth_sessions`: `id` (UUID), `user_id`, `ip_address`, `user_agent`, `last_activity_at`, `revoked_at`, `created_at`.
  - `refresh_tokens`: `id` (UUID), `session_id`, `token_hash`, `family_id`, `nonce`, `expires_at`, `revoked_at`, `created_at`.
- **JWT Infrastructure:**
  - RS256 asymmetric signing via `PHP-Open-Source-Saver/jwt-auth` or dedicated crypto service.
  - Required claims: `iss`, `sub`, `aud`, `jti`, `sid`, `iat`, `nbf`, `exp`, `token_type`, `scope`.
  - Token lifetimes: Access token (~15 mins), Refresh token (~30 days).
  - Strict validation rejecting tokens with future `nbf`.
- **Authentication Endpoints:**
  - `POST /api/v1/auth/register` (201 Created)
  - `POST /api/v1/auth/login` (200 OK)
  - `POST /api/v1/auth/refresh` (200 OK): Uses row locking (`lockForUpdate`). Issues new refresh token, revokes previous. If reuse is detected, revokes entire token family/session.
  - `POST /api/v1/auth/logout` (204 No Content): Revokes current session and blocklists access token in Redis (`auth:jwt:blocklist:{jti}`) for remaining TTL.
  - `POST /api/v1/auth/logout-all` (204 No Content): Revokes all active sessions for the user.

### 3. Acceptance Criteria
- Tampered or expired JWTs fail authentication with standard 401 response.
- Reusing an old refresh token invalidates the entire session and triggers security revocation.
- Blocklisted JWTs immediately fail authentication checks before database queries are run.

---

## Milestone 3: Tenancy & Membership Management

### 1. Objective
Build the multi-tenant architecture, tenant onboarding workflows, membership lifecycle, and tenant-scoped JWT generation.

### 2. Scope & Key Deliverables
- **Database Migrations & Models:**
  - `tenants`: `id` (UUID), `name`, `slug` (unique), `status`, `row_version`, `created_at`, `updated_at`, `deleted_at`.
  - `tenant_memberships`: `id` (UUID), `tenant_id`, `user_id`, `role`, `status`, `row_version`, `created_at`, `updated_at`.
  - Unique constraint on `(tenant_id, user_id)`.
- **Tenant Context Resolution:**
  - Token exchange/issuance of tenant-scoped JWT containing `tenant_id` claim.
  - Strict security rule: **Never accept tenant identity through `X-Tenant-ID` header**. Context must come exclusively from verified JWT claims.
- **Tenancy Endpoints:**
  - `POST /api/v1/tenants` (Create/onboard tenant, assigning creator as `TENANT_OWNER`).
  - `GET /api/v1/tenants` (List user's active tenants).
  - `POST /api/v1/tenants/{tenant}/members` (Invite member with role).
  - `PATCH /api/v1/tenants/{tenant}/members/{member}` (Update member role/status).
  - `DELETE /api/v1/tenants/{tenant}/members/{member}` (Remove membership).

### 3. Acceptance Criteria
- Tenant access strictly verifies triple-active condition:
  `User.status = active AND Tenant.status = active AND TenantMembership.status = active`.
- Any tenant header spoofing attempt is ignored.

---

## Milestone 4: Authorization, RBAC & Context Enforcement

### 1. Objective
Implement robust platform-level and tenant-level role-based access control (RBAC), scoping middleware, and administrative controls.

### 2. Scope & Key Deliverables
- **Database Migrations & Models:**
  - `platform_roles`: `id` (UUID), `name`, `slug` (`SUPER_ADMIN`, `PLATFORM_ADMIN`).
  - `user_platform_roles`: `id` (UUID), `user_id`, `platform_role_id`.
- **Policies & Guards:**
  - Platform authorization policies: Super Admin and Platform Admin scoping.
  - Tenant authorization policies: Enforcing `TENANT_OWNER`, `TENANT_ADMIN`, and `TENANT_MEMBER` capabilities.
- **Administrative Operations:**
  - Platform Admin APIs: Suspend/activate tenant, suspend/disable user, force global session revocation.
  - Tenant Admin APIs: Manage workspace settings and member permissions.

### 3. Acceptance Criteria
- Platform admins cannot read private tenant data without explicit authorization.
- Tenant members cannot access admin endpoints. Cross-tenant queries return 403/404 without data leakage.

---

## Milestone 5: Transactional Outbox & Reliable Messaging

### 1. Objective
Ensure atomic, resilient event dispatching to RabbitMQ using the Transactional Outbox pattern, preventing dual-write inconsistencies.

### 2. Scope & Key Deliverables
- **Database Migrations & Models:**
  - `outbox_events`: `id` (UUID), `aggregate_type`, `aggregate_id`, `event_type`, `payload` (JSONB), `status` (`pending`, `published`, `failed`), `correlation_id`, `causation_id`, `retry_count`, `published_at`, `created_at`.
- **Outbox Publisher & Worker:**
  - Outbox service writing events within active database transactions.
  - Scheduled background relay worker (`artisan outbox:publish` or queue job) reading pending events with `SELECT ... FOR UPDATE SKIP LOCKED` and dispatching to RabbitMQ exchange.
  - Idempotent base consumer structure with message deduplication handling.

### 3. Acceptance Criteria
- Rolling back a database transaction cancels the corresponding outbox event.
- Broker connection failures do not disrupt HTTP API transactions.
- Consumers process duplicated messages idempotently without side-effects.

---

## Milestone 6: Audit Logging & Security Blame Tracking

### 1. Objective
Provide tamper-evident, structured audit trails for all sensitive security and domain events with full actor blame context.

### 2. Scope & Key Deliverables
- **Database Migrations & Models:**
  - `audit_logs`: `id` (UUID), `actor_id` (nullable for system), `actor_type` (`user`, `system`, `api_key`), `action`, `resource_type`, `resource_id`, `old_values` (JSONB), `new_values` (JSONB), `ip_address`, `user_agent`, `correlation_id`, `causation_id`, `created_at` (timestamptz).
- **Audit Logging Service:**
  - Event listeners logging authentication events (login, refresh, logout, password change), tenant changes, membership modifications, and administrative overrides.
  - Automated blame context extraction from current request context or CLI worker context.

### 3. Acceptance Criteria
- Every authentication attempt, permission update, and tenant status modification produces an immutable audit log entry.
- Correlation IDs seamlessly link API requests, outbox events, and audit logs.

---

## Milestone 7: Asynchronous Notifications & Production Hardening

### 1. Objective
Implement background notification dispatching, API rate limiting, system health probes, and complete integration test verification.

### 2. Scope & Key Deliverables
- **Asynchronous Notifications:**
  - RabbitMQ workers for transactional emails (welcome email, tenant invitations, security alert notifications).
  - Non-blocking execution: mailer downtime does not impact core API response times.
- **Rate Limiting & Protection:**
  - Redis-backed rate limiting per IP and per user account on auth endpoints (`POST /auth/login`, `POST /auth/register`, `POST /auth/refresh`).
- **Health Checks & Monitoring:**
  - `/health/liveness` & `/health/readiness` probes verifying PostgreSQL Primary, Replica, Redis, and RabbitMQ.
- **Quality Gates:**
  - Full Pest feature test suite covering concurrency, token rotation reuse, tenant boundary isolation, and edge cases.
  - PHPStan / Larastan strict Level 8+ validation.

### 3. Acceptance Criteria
- End-to-end test suite passes cleanly in CI/CD pipeline.
- High traffic and third-party outages do not impact API uptime or data integrity.
