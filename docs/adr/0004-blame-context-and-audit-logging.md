# 4. Centralized Blame Context and Append-Only Audit Logging

Date: 2026-09-07

## Status

Accepted

## Context

Security, compliance, and debugging require tracking who initiated every state change, across synchronous HTTP web requests, asynchronous background jobs, CLI commands, and system-triggered processes. Relying solely on `Auth::id()` fails inside background queues and non-HTTP contexts.

## Decision

1. **Centralized BlameContext**: A singleton `BlameContext` maintains actor information (`userId`, `ipAddress`, `userAgent`, `correlationId`, `causationId`).
2. **Context Propagation & Rehydration**:
   - HTTP middleware initializes `BlameContext` from request headers (`X-Request-ID`, `X-Correlation-ID`, `X-Causation-ID`) and authenticated user tokens.
   - Outbox messages and queued jobs serialize blame attributes, and queue workers rehydrate `BlameContext` prior to job execution.
   - System tasks use the standard nil UUID (`00000000-0000-0000-0000-000000000000`).
3. **Append-Only Audit Logs**: `audit_logs` table records security-sensitive events (`UserRegistered`, `UserLoggedIn`, `LoginFailed`, `RefreshTokenRotated`, `RefreshTokenReuseDetected`, `UserLoggedOut`, `UserSessionsRevoked`) with full blame metadata. Records are never updated or deleted during normal operation.

## Consequences

- Full traceability across distributed asynchronous processing pipelines.
- Decouples domain logic from Laravel's global session/auth facades.
