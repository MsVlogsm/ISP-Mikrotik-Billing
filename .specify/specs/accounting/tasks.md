# Tasks — Accounting

**Traceability:** ACC-001…ACC-008 in `spec.md`.

## Phase 1 — Query and ledger inventory
- [ ] [T001] Enumerate every `CollectionSummary` aggregate/list query and classify it as posted, non-posted, or operational. Refs: ACC-001…ACC-003. Paths: `app/Models/CollectionSummary.php`, `app/Http/Controllers/CollectionReportController.php`, `app/Http/Controllers/BillingFinanceDashboardController.php`, `routes/web.php`.
- [ ] [T002] Inventory expense model, entry/edit/delete flows, categories, role checks, and audit events. Refs: ACC-006, ACC-008. Paths: `app/Models/IspExpense.php`, `app/Livewire/Admin/ExpenseManager.php`.
- [ ] [T003] Trace Reseller income/cashflow/ledger predicates and parity with core Accounting. Refs: ACC-003, ACC-007. Path: `app/Http/Controllers/Admin/PartnerNetworkController.php`.
## Phase 2 — Consistency validation
- [ ] [T004] Compare paid, pending, reversed, reconciled, null-status, and negative-return handling across all consumers. Refs: ACC-001, ACC-002, ACC-007.
- [ ] [T005] Verify date parsing, inclusive end-date behavior, timezone, and timestamp-vs-date column semantics. Refs: ACC-004.
- [ ] [T006] Verify totals are calculated over full query result while visible rows are limited/paginated. Refs: ACC-005.
- [ ] [T007] Verify correction/reversal transactionality, audit history, and non-destructive adjustment behavior. Refs: ACC-008.
## Phase 3 — Automated and browser evidence
- [ ] [T008] Extend status/sign/date/aggregate tests. Existing candidate: `tests/Feature/FinanceAccountingReportTest.php`; add coverage for every report consumer. Refs: ACC-001…ACC-005, ACC-007.
- [ ] [T009] Test expense create/update/delete permissions, audit, and report inclusion/exclusion. Refs: ACC-006, ACC-008.
- [ ] [T010] Verify MySQL Staging behavior and Admin/Manager browser report filters; record report-to-query-to-test matrix and CI evidence.