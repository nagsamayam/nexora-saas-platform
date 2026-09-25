# 2. Opaque Refresh Tokens and Automatic Reuse Detection

Date: 2026-09-07

## Status

Accepted

## Context

To support long-lived sessions on mobile and web clients without extending access token lifespans, refresh tokens are required. However, refresh tokens can be stolen or intercepted. If a refresh token is compromised, a mechanism is needed to detect token replay attacks and protect user sessions.

## Decision

1. **Opaque Random Strings**: Refresh tokens are cryptographically secure random 64-byte hex strings (`random_bytes(64)`), never JWTs.
2. **Hash-Only Storage**: Raw refresh tokens are returned to the client once at issuance. Only their SHA-256 cryptographic hashes are stored in `auth_refresh_tokens`.
3. **Single-Use Rotation**: Every refresh token can be used exactly once. Upon usage, the token is marked `consumed` and replaced by a newly generated refresh token.
4. **Concurrency Safety**: Token rotation runs inside a database transaction with row-level locking (`SELECT ... FOR UPDATE`).
5. **Reuse Detection (Compromise Response)**: If a refresh token with `consumed` status is submitted, the system flags a potential token theft, revokes the associated session and all its active refresh tokens, logs an audit event (`RefreshTokenReuseDetected`), and rejects the request.

## Consequences

- Compromised refresh tokens immediately disable the entire session upon reuse, isolating the breach.
- Storage leaks cannot expose raw credentials due to one-way SHA-256 hashing.
