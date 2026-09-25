# 1. JWT Authentication with RS256 and User Auth Version

Date: 2026-09-07

## Status

Accepted

## Context

The API requires a stateless, secure, and scalable authentication mechanism for client requests. Relying solely on stateful sessions for every API request limits scalability across microservices or multi-region deployments, while pure stateless JWTs make immediate revocation (e.g., during password resets or compromised sessions) difficult without maintaining expansive server-side token state.

## Decision

1. **Algorithm**: We use RS256 (asymmetric RSA 4096-bit key pair) for signing and verifying access tokens. The private key remains secure on the server for token issuance, while the public key is used for verification.
2. **Access Token Lifespan**: Short-lived (15 minutes).
3. **Required Claims**: Every access token contains standard claims (`iss`, `aud`, `sub`, `iat`, `exp`, `nbf`, `jti`) alongside custom claims `sid` (Session UUID) and `auth_version` (User security version).
4. **Global and Session Invalidation**:
   - Access tokens check `auth_version` against the user's current version in the database/cache, allowing immediate O(1) global revocation across all devices upon security events (e.g., password change, admin global logout, logout-all).
   - Single-session revocation uses the Redis-backed JWT blacklist and updates `auth_sessions` status.

## Consequences

- Asymmetric signing ensures verification cannot forge tokens even if verification keys are distributed.
- Combining short token TTLs with `auth_version` achieves immediate revocation capabilities without needing to store all active access tokens in memory.
