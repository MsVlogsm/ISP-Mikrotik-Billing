# Tasks — Administration

**Traceability:** ADM-001…ADM-011 in `spec.md`.

## Phase 1 — Authorization inventory
- [ ] [T001] Inventory route middleware, Livewire actions, APIs, jobs, exports and nested resource authorization. Refs: ADM-001, ADM-002. Paths: `app/Http/Middleware/EnforceModulePermissions.php`, `routes/web.php`, `routes/api.php`, Livewire components.
- [ ] [T002] Build actual Admin/Manager/Reseller permission matrix and validate role hierarchy, privileged role administration and permission-loss behavior. Refs: ADM-002, ADM-003. Path: `app/Livewire/Admin/ManageRole.php`.
## Phase 2 — Secrets, audit, and environment separation
- [ ] [T003] Inventory credential/token settings and verify encryption, masking, rotation, and secret redaction at render/log/export/error boundaries. Refs: ADM-004, ADM-005, ADM-011.
- [ ] [T004] Trace audit event coverage for financial, permission, settings and network changes; verify actor/target/outcome and no secrets. Refs: ADM-006.
- [ ] [T005] Verify feature/polling flags and Staging/Production config, DB, storage and credential separation. Refs: ADM-005, ADM-010.
## Phase 3 — Operations and deployment
- [ ] [T006] Inventory backup scope, destination, encryption, retention, integrity and restore evidence. Refs: ADM-007.
- [ ] [T007] Verify independent app/queue/scheduler/database/cache/storage/provider/device health indicators. Refs: ADM-008.
- [ ] [T008] Validate release workflow confirmation, migration opt-in, backups, release cutover, HTTPS health, and rollback gates. Refs: ADM-009, ADM-010.
## Phase 4 — Tests and browser
- [ ] [T009] Map/add route/action permission matrix, role revocation, API tokens, settings masking and audit-redaction tests. Existing candidates: `tests/Feature/AdminAuditRolePermissionTest.php`, `OperationalModulePermissionTest.php`, `RolePermissionBoundaryTest.php`, `ApiTokenPermissionsTest.php`, `CompanySettingsPagesTest.php`.
- [ ] [T010] Verify Admin/Manager browser paths and expected denials on critical modules; document backup-restore and Staging isolation evidence, then complete requirement traceability.