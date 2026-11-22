# Tasks — Customer

**Traceability:** CUS-001…CUS-009 in `spec.md`.

## Phase 1 — Identity and data contracts
- [ ] [T001] Trace customer ID generation/manual input, unique indexes, validation, and duplicate handling. Refs: CUS-001, CUS-002. Paths: `app/Models/CustomersInfo.php`, customer migrations, `app/Livewire/NewCustomer.php`, `app/Livewire/EditCustomer.php`.
- [ ] [T002] Inventory required/optional form fields, phone/identity validation, package selection, and existing-customer search. Refs: CUS-002, CUS-003.
- [ ] [T003] Map customer-to-PPPoE/router/ONU relationships and exact identity provenance. Refs: CUS-004, CUS-008, CUS-009. Paths: `app/Models/CustomersInfo.php`, `app/Models/OltOnuCustomerMapping.php`, `app/Services/OnuMacLedgerService.php`.
## Phase 2 — User workflows
- [ ] [T004] Trace profile, list, paid ledger, package/IP/MAC/optical fields and their query/freshness contracts. Refs: CUS-004, CUS-005. Existing route: `customer/{id}/paid-ledger`.
- [ ] [T005] Audit disable/delete/restore semantics when associated router is offline; distinguish local and remote outcomes. Refs: CUS-006, CUS-009. Paths: `app/Http/Controllers/CustomersController.php`, `app/Http/Controllers/CustomerActionsController.php`.
- [ ] [T006] Audit bulk import/export templates, encoding, duplicate rows, disabled-router filters, and partial-failure handling. Refs: CUS-007.
## Phase 3 — Tests and browser verification
- [ ] [T007] Map/add CRUD, validation, duplicate ID, optional fields, search and lifecycle tests. Existing candidates: `tests/Feature/CustomerCrudTest.php`, `CustomerActionTest.php`, `CustomerAddEditEnableWorkflowTest.php`, `CustomerBulkImportWorkflowTest.php`, `CustomerCidReuseTest.php`. Refs: CUS-001, CUS-002, CUS-003, CUS-006, CUS-007.
- [ ] [T008] Map/add profile, ledger, package, identity and unknown telemetry tests. Existing candidates: `tests/Feature/CustomerDetailsPageTest.php`, `OnuCustomerMappingWorkflowTest.php`. Refs: CUS-004, CUS-005, CUS-008, CUS-009.
- [ ] [T009] Verify Admin/Manager browser flows and two-Reseller tenant boundary for customer routes, exports, and nested resources. Refs: CUS-002, CUS-006, CUS-007.
- [ ] [T010] Publish field provenance and requirement-to-test matrix; record CI and Staging evidence for CUS-001…CUS-009.