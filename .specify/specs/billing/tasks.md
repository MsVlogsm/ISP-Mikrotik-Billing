# Tasks — Billing

**Traceability:** BILL-001…BILL-008 in `spec.md`. Tasks are analysis/verification tasks until an approved implementation scope exists.

## Phase 1 — Baseline and source mapping
- [ ] [T001] Map bill-generation entry points, scheduler registrations, queues, and invoice uniqueness rules. Refs: BILL-001, BILL-007. Paths: `app/Console`, `routes/console.php`, `app/Models/BillingInfo.php`, billing-related migrations.
- [ ] [T002] Trace due/advance/discount/paid calculations across dashboard, customer list/profile, ledger, APIs, and exports. Refs: BILL-002, BILL-005, BILL-006. Paths: `app/Http/Controllers/BillingFinanceDashboardController.php`, `app/Livewire/CustomerList.php`, `app/Http/Controllers/CustomerDetailsController.php`.
- [ ] [T003] Trace prepaid expiry state and timezone boundaries. Refs: BILL-003, BILL-007. Paths: `app/Services/PrepaidBillingCycle.php`, `app/Services/PrepaidExpiryGuard.php`, `tests/Unit/PrepaidBillingCycleTest.php`, `tests/Unit/PrepaidExpiryGuardTest.php`.
## Phase 2 — Domain correctness
- [ ] [T004] Document Payment finality and Accounting scope dependencies; identify duplicate or inconsistent state rules. Refs: BILL-002, BILL-004, BILL-005.
- [ ] [T005] Verify activation/disable dispatch is separately authorized and does not mutate local state on router failure. Refs: BILL-003, BILL-004, BILL-008. Paths: `app/Http/Controllers/CustomersController.php`, `app/Http/Controllers/CustomerActionsController.php`.
- [ ] [T006] Verify scheduler/queue idempotency and retry/concurrency behavior. Refs: BILL-001, BILL-007.
## Phase 3 — Tests and browser evidence
- [ ] [T007] Map and extend tests for duplicate generation, due arithmetic, paid-only activation, date boundaries, and failure/retry. Refs: BILL-001…BILL-004, BILL-007. Existing candidates: `tests/Unit/PrepaidBillingCycleTest.php`, `tests/Unit/PrepaidExpiryGuardTest.php`, `tests/Feature/CustomerAddEditEnableWorkflowTest.php`, `tests/Feature/PaymentCollectionWorkflowTest.php`.
- [ ] [T008] Add cross-surface parity tests for dashboard/list/profile/ledger/export and pagination-independent totals. Refs: BILL-002, BILL-005, BILL-006. Existing candidate: `tests/Feature/XLinkBillingParityTest.php`.
- [ ] [T009] Verify Admin/Manager browser access and denial paths and Reseller customer scope; attach screenshots/network evidence. Refs: BILL-005, BILL-008.
- [ ] [T010] Record CI, migration, timezone, and staging evidence; mark every BILL requirement implemented/partial/missing/unverified with file+test references. Refs: BILL-001, BILL-002, BILL-003, BILL-004, BILL-005, BILL-006, BILL-007, BILL-008.