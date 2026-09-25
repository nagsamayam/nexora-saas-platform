# 5. Optimistic Concurrency Control (OCC) for Mutable Domain Entities

Date: 2026-09-07

## Status

Accepted

## Context

Concurrent update operations on shared mutable domain entities (such as user profile updates, tenant settings, and memberships) can lead to lost updates if two requests read the same state and simultaneously overwrite each other.

## Decision

1. **Row Versioning**: Mutable entities include a `row_version BIGINT NOT NULL DEFAULT 1` column.
2. **`HasRowVersion` Trait**: Eloquent models utilize `HasRowVersion::updateWithOcc(array $attributes, ?int $expectedVersion = null)`.
3. **Atomic Version Checking**: The update statement executes `UPDATE table SET ..., row_version = row_version + 1 WHERE id = :id AND row_version = :expected_version`.
4. **Conflict Exception**: If zero rows are affected, `App\Domain\Shared\Exceptions\ConcurrencyException` is thrown, which maps to HTTP 409 Conflict with standard error code `CONCURRENCY_CONFLICT`.
5. **Selective Application**: OCC is applied to mutable state entities. Append-only models (e.g. `audit_logs`, `outbox_messages`) and session refresh rotations (which use row-level locks) do not use OCC.

## Consequences

- Prevents lost updates under high concurrency.
- Clients receive explicit conflict feedback allowing clean client-side refresh and retry.
