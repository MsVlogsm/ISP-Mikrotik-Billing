# MikroTik module specification

**Status:** Draft. **Scope:** Router inventory, credentials, RouterOS API/API-SSL connectivity, PPPoE secrets/profiles, active sessions, router logs, and controlled router actions.

## Requirements
- MTK-001: Router connection configuration MUST validate address, API/API-SSL port, transport, credential presence, timeout, and connectivity without exposing secrets.
- MTK-002: Read-only diagnostics and inventory reads MUST be separated from write-capable provisioning operations.
- MTK-003: Active PPPoE session counts shown in dashboards MUST derive from a clearly defined live/session snapshot source and show freshness/error state; they must not be substituted with configured secret counts.
- MTK-004: PPPoE secret/profile create, update, disable, enable, and bulk-copy operations MUST target the selected router and preserve source records. Bulk copy is not an interface/port move.
- MTK-005: Profile mapping during bulk operations MUST match explicit profile names/rules; creation of a missing profile requires a confirmed mapping and must not silently invent bandwidth policy.
- MTK-006: Router-unavailable failures MUST not corrupt local billing/customer records or falsely report a device mutation as successful.
- MTK-007: Router commands and logs MUST be audited with actor, target, action, result, and safe error context; credentials and tokens must be redacted.
- MTK-008: Network writes require server-side permission, explicit target scope, confirmation for impactful bulk actions, and idempotent retry semantics where possible.
- MTK-009: Staging tests MUST use dedicated router credentials and test devices; no Production router writes or polling are implied by this specification.

## Acceptance criteria
- Invalid credentials, unreachable API, TLS mismatch, timeout, and permission-denied cases are distinguishable.
- Read-only page loads issue no write commands.
- A bulk-copy test preserves source secrets and creates/updates only selected destination records with the approved profile mapping.
- Repeated requests do not create duplicate secrets or profiles.
- A disabled/unreachable router does not block local customer-state changes unless an explicitly required router operation is being requested.
- Session counts show source timestamp and stale/failed state.

## Required tests and evidence
Mocked RouterOS contract tests for success, timeout, malformed response, auth failure, and command allowlist; feature tests for authorization, bulk operations, audit, and retry/idempotency. Authenticated Staging browser tests must prove both permitted and denied actions. Never use live Production hardware in automated tests.

## Dependencies and unresolved decisions
Depends on Customer, Billing, Network, and Administration. Confirm RouterOS API wrapper behavior/version, exact supported RouterOS releases, command allowlists, session freshness, and per-router credential storage from code and vendor documentation.
