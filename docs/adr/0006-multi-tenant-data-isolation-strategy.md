# 6. Multi-Tenant Integration and Data Isolation Strategy

Date: 2026-09-07

## Status

Accepted

## Context

The platform is designed to expand into a multi-tenant B2B SaaS system while keeping the authentication core clean, decoupled, and highly performant. A flexible tenant membership architecture is needed that allows users to belong to multiple tenants with distinct roles.

## Decision

1. **Decoupled User Identity**: Users exist globally at the platform level (`users` table) and authenticate once via the authentication core.
2. **Tenancy Membership Model**:
   - `tenants` stores organization records with UUID v7 identifiers, unique slug indexes, and `status` checks.
   - `tenant_memberships` manages the many-to-many relationship linking `user_id` and `tenant_id` with typed roles (`TenantRole`: Owner, Admin, Member, Guest) and membership statuses (`TenantMembershipStatus`: Active, Invited, Suspended).
   - Global administrative operations are governed separately by `platform_roles` and `user_platform_roles` (`PlatformRole`: SuperAdmin, SupportAdmin, BillingAdmin).
3. **Database Schema & Foreign Keys**: Multi-tenant tables leverage PostgreSQL UUID foreign keys with `ON DELETE CASCADE` and partial composite unique indexes on active memberships.

## Consequences

- Clean separation between global user identity/authentication and tenant-scoped authorization.
- Users can seamlessly switch between multiple organizations without re-authenticating.
