# Tasks — OLT / ONU

**Traceability:** ONU-001…ONU-010 in `spec.md`.

## Phase 1 — Inventory and capability mapping
- [ ] [T001] Inventory OLT/ONU routes, controllers, models, migrations, jobs, schedules, and vendor configuration. Refs: ONU-001, ONU-006, ONU-007. Paths: `app/Http/Controllers/OltDashboardController.php`, `OltProvisioningController.php`, `app/Models/NetworkInventoryDevice.php`, `docs/olt-capability-matrix.md`.
- [ ] [T002] Map every metric to exact vendor/model/firmware/transceiver evidence and null/error semantics. Refs: ONU-001, ONU-005, ONU-010. Paths: `app/Services/Olt`, `docs/olt-capability-matrix.md`.
## Phase 2 — Mapping and collector behavior
- [ ] [T003] Trace exact unique ONU identity resolution, stale mapping reconciliation, manual mapping provenance and ambiguity cases. Refs: ONU-002…ONU-004. Paths: `app/Services/Olt/OnuMappingIdentityReconciler.php`, `OnuMacLedgerService.php`, `app/Models/OltOnuCustomerMapping.php`. Confirm explicit ONU-003 unique-identity rule.
- [ ] [T004] Verify direct SNMP, explicit RouterOS proxy, SSH read-only allowlist, disabled defaults and no Telnet/write paths. Refs: ONU-006, ONU-007. Paths: `app/Services/Olt/OltReadOnlyAdapterManager.php`, `BridgeFdbSnmpService.php`, `docs/olt-bridge-fdb.md`.
- [ ] [T005] Verify snapshot semantics: failed poll is not empty inventory; movement uses consecutive successful snapshots. Refs: ONU-002, ONU-009. Paths: `NetworkBridgeFdbSnapshot.php`, `NetworkBridgeFdbEntry.php`.
- [ ] [T006] Trace reboot/provisioning authorization, exact target selection, confirmations, audit, and failure behavior. Refs: ONU-008.
## Phase 3 — Tests and staged evidence
- [ ] [T007] Map/add OLT dashboard, provisioning safety, mapping and ledger feature tests. Existing candidates: `tests/Feature/OltDashboardTest.php`, `OltProvisioningSafetyTest.php`, `OnuCustomerMappingWorkflowTest.php`, `OnuMacLedgerTest.php`, `SyncOltOnuMappingsTest.php`. Refs: ONU-001, ONU-002, ONU-003, ONU-004, ONU-008.
- [ ] [T008] Map/add parser, multi-vendor, model/firmware and read-only adapter tests. Existing candidates: `tests/Unit/BridgeFdbOidParserTest.php`, `MultiVendorSnmpReadOnlyAdapterTest.php`, `OnuMappingIdentityReconcilerTest.php`, `VsolSnmpOidProfileTest.php`. Refs: ONU-001, ONU-005, ONU-006, ONU-009, ONU-010.
- [ ] [T009] Verify GET pages cause zero device POSTs; unauthorized poll/reboot returns denial before service call; Staging uses independent credentials and flags remain safe. Refs: ONU-006…ONU-008.
- [ ] [T010] Complete vendor capability matrix and per-requirement source/test/evidence matrix; keep Production polling disabled pending separate approval. Refs: ONU-001, ONU-002, ONU-003, ONU-004, ONU-005, ONU-006, ONU-007, ONU-008, ONU-009, ONU-010.