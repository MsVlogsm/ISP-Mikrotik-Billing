# Administration module specification

**Status:** Draft. **Scope:** users, roles/permissions, settings, audit logs, integrations, backup/restore, system health, deployment controls, and operational configuration.

## Requirements
- ADM-001: Every protected route, API, action, export, job dispatch, and device operation MUST enforce server-side role/permission checks.
- ADM-002: Admin, Manager, and Reseller role matrices MUST be explicit; Manager privileges remain within the approved Admin subset and Reseller access remains tenant-scoped.
- ADM-003: Role/permission changes MUST be restricted to authorized privileged operators, audited, and tested for immediate permission loss.
- ADM-004: Sensitive settings (API tokens, webhook secrets, router/OLT credentials, SMTP/SMS credentials) MUST use encrypted/secret storage, masked display, least privilege, and safe rotation.
- ADM-005: Integration configuration MUST expose health/connection status without leaking secrets or triggering unapproved live actions.
- ADM-006: Audit logs MUST capture actor, target, action, outcome, timestamp, and correlation/reference while excluding credentials and unnecessary personal data.
- ADM-007: Backup/restore procedures MUST define scope, retention, encryption, integrity verification, restore test, and separation of Production/Staging data.
- ADM-008: Health checks and dashboards MUST distinguish app health, queue/scheduler health, DB/cache/storage health, and external device/provider reachability.
- ADM-009: Configuration/cache changes and deployments MUST follow the reviewed release workflow; no direct edit of active Production release or environment files.
- ADM-010: Feature flags and polling toggles MUST default safely and be environment-scoped; Staging credentials/configuration are independent of Production.
- ADM-011: Import/export, log viewing, user management, and integration testing MUST apply role scope and sensitive-data redaction.

## Acceptance criteria
- Unauthorized requests are denied server-side even if a user crafts a direct URL/API call.
- Admin/Manager role matrix tests verify allowed and denied actions, including permission loss after role change.
- Secrets never appear in HTML, logs, error responses, exports, or audit payloads.
- Staging changes cannot mutate Production configuration, database, credentials, or live device state.
- Backup restore test proves a recoverable backup; backup existence alone is insufficient.
- Release candidate must pass CI, Staging browser gates, source alignment, backup, health check, and rollback readiness before Production deployment.

## Required tests and evidence
Feature tests for role matrix, permission loss, settings encryption/masking, audit redaction, feature flags, backup metadata, and direct-route denial. Browser verification for Admin and Manager on critical modules. Operational evidence for backup/restore, staging isolation, and release workflow.

## Dependencies and unresolved decisions
Cross-cutting dependency for every module. Confirm actual role names/permission catalog, audit retention, backup destination/encryption, SSO/MFA policy, settings ownership, and environment promotion process from repository and approved operations policy.
