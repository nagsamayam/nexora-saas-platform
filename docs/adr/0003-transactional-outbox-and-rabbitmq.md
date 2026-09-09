# 3. Transactional Outbox Pattern and RabbitMQ Integration

Date: 2026-09-07

## Status

Accepted

## Context

Critical domain events (such as user registration or login notifications) must trigger asynchronous tasks (e.g. sending welcome emails, notifications, third-party syncing) without risking dual-write inconsistencies or losing events during unexpected application crashes or queue outages.

## Decision

1. **Transactional Outbox Table**: Domain events are persisted to the PostgreSQL `outbox_messages` table within the same database transaction as the primary entity mutation.
2. **Asynchronous Publishing Worker**: A scheduled/daemon CLI command (`php artisan outbox:publish`) polls pending outbox messages using row-level locking (`SELECT ... FOR UPDATE SKIP LOCKED`), dispatches them onto RabbitMQ queues via Laravel Jobs, and marks them `published`.
3. **Idempotent Queue Consumers**: Queue consumers (e.g., `SendRegistrationEmailJob`, `SendLoginNotificationEmailJob`) check deduplication keys before executing side effects and record completion, guaranteeing safe at-least-once message processing.
4. **Resilience & Backoff**: Outbox publishing implements exponential backoff and max retry limits before marking records `failed` for dead-letter inspection.
5. **Distributed Correlation Tracing**: Outbox records persist and propagate `correlation_id` to rehydrate `BlameContext` across asynchronous worker boundaries.

## Consequences

- Eliminates 2PC (two-phase commit) complexity while guaranteeing at-least-once event delivery.
- Domain operations never fail due to queue broker transient unavailability.
