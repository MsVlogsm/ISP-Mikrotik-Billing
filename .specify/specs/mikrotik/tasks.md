# Tasks — MikroTik

**Traceability:** MTK-001…MTK-009 in `spec.md`.

## Phase 1 — Connectivity and credential inventory
- [ ] [T001] Trace router schema, encrypted credential fields, API/API-SSL ports, validation, and connectivity diagnostics. Refs: MTK-001, MTK-007. Paths: `app/Models/RouterList.php`, `app/Services/MikrotikApiService.php`, router migrations.
- [ ] [T002] Inventory all RouterOS API and SSH call sites and classify every command read-only vs mutation. Refs: MTK-002, MTK-008. Paths: `app/Services/MikrotikApiService.php`, `MikrotikSSHService.php`, `app/Http/Controllers/MikrotikController.php`.
## Phase 2 — Session and provisioning behavior
- [ ] [T003] Trace live session source, aggregation, freshness, failures, and dashboard consumers. Refs: MTK-003. Candidates: `app/Services/OnlineCustomerSessionFilter.php`, `tests/Feature/OnlineCustomerSessionFilterTest.php`, `DashboardLiveMetricsTest.php`.
- [ ] [T004] Verify PPP secret/profile create/update/disable and selected-router targeting. Refs: MTK-004, MTK-005. Paths: `app/Livewire/Mikrotik/PppoeSetup.php`, `app/Livewire/MikrotikClients.php`.
- [ ] [T005] Verify pending-action queue retry, idempotency, audit, router unavailable handling and no false-success state. Refs: MTK-006…MTK-008. Paths: `app/Services/MikrotikPendingActionQueue.php`, `app/Jobs/ReconcileMikrotikPendingAction.php`.
## Phase 3 — Safety tests and isolated verification
- [ ] [T006] Add mocked API/SSH contract tests for auth failure, timeout, malformed output and allowlist enforcement. Refs: MTK-001, MTK-002, MTK-006, MTK-008.
- [ ] [T007] Verify bulk-copy source preservation, destination scope, profile mapping and duplicate prevention. Refs: MTK-004, MTK-005.
- [ ] [T008] Map role/permission and audit coverage; verify logs redact router secrets. Refs: MTK-007, MTK-008.
- [ ] [T009] Run authenticated Staging Admin/Manager browser tests with dedicated test-router credentials only; confirm no Production router access. Refs: MTK-009.
- [ ] [T010] Record source/test matrix, session freshness contract, CI evidence, and remaining unknown RouterOS version/API limitations. Refs: MTK-001, MTK-002, MTK-003, MTK-004, MTK-005, MTK-006, MTK-007, MTK-008, MTK-009.