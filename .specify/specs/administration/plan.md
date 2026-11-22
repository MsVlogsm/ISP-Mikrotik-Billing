# Implementation plan — Administration

**Status:** Draft; no Production permission, secret, deployment or environment changes authorized.

## Architecture and source boundaries
- Authorization middleware includes `app/Http/Middleware/EnforceModulePermissions.php` and related reseller/profile guards.
- Role administration: `app/Livewire/Admin/ManageRole.php`, Spatie permission tables and role/permission seed/config.
- Audit/operations surfaces: Admin activity/login/system logs, `app/Livewire/Admin/ExpenseManager.php`, company/site settings, API token management, system health controllers.
- Deployment authority is the manual `.github/workflows/production-deploy.yml`; CI is `.github/workflows/tests.yml`.
- Existing tests include `AdminAuditRolePermissionTest.php`, `OperationalModulePermissionTest.php`, `RolePermissionBoundaryTest.php`, `ApiTokenPermissionsTest.php`, `CompanySettingsPagesTest.php`, and system-sync health tests.

## Design decisions and constraints
1. Build an explicit role/action matrix from actual registered routes, Livewire actions, APIs, jobs, exports and nested resources.
2. Permission denial must occur server-side before side effects; permission revocation must apply to the next request.
3. Store sensitive values with approved encryption/secret mechanisms, reveal only masked metadata, and redact audit/error/log content.
4. Separate production/staging database, storage, secrets and device configuration; no cross-environment copy of live secrets.
5. Backup evidence includes integrity and restore-test outcome, not only file existence.
6. Production deploy retains manual confirmation, tested immutable release, backup, health verification and rollback; migration remains separately opted in.

## Phases
- P1 Role registry, middleware, route/action/API surface inventory.
- P2 Secret settings, integration status, audit event and redaction review.
- P3 Backup/restore, health, scheduler/cache and deployment procedure review.
- P4 Direct-route/action denial tests and Admin/Manager browser evidence.
- P5 Final operations control matrix, CI and staging isolation proof.

## Acceptance evidence
Every privileged action is in an allow/deny matrix by role. Tests prove direct requests are denied and secret values do not render/log. Restore testing and staging isolation are documented before any production release approval.