# Tasks — Payment

**Traceability:** PAY-001…PAY-009 in `spec.md`.

## Phase 1 — Provider inventory
- [ ] [T001] Inventory enabled provider flags, payment routes, controller methods, secrets/config sources and callback URLs. Refs: PAY-001, PAY-007, PAY-009. Paths: `app/Http/Controllers/Payment`, `app/Services/PaymentService.php`, `routes/api.php`, `routes/web.php`.
- [ ] [T002] Trace internal transaction identity, collection/payment summary linkage, provider references, status enum and amount/currency fields. Refs: PAY-001, PAY-006. Paths: `app/Models/CollectionSummary.php`, `PaymentSummary.php`, payment migrations.
## Phase 2 — Correctness and safety
- [ ] [T003] Audit authentication/signature/token checks, customer/amount/reference match and failure quarantine for every provider. Refs: PAY-002, PAY-008.
- [ ] [T004] Verify duplicate/replay idempotency, database transaction boundaries and single service activation trigger. Refs: PAY-003, PAY-004.
- [ ] [T005] Trace manual cash authorization, actor attribution, override reason, return/refund/chargeback and original transaction linkage. Refs: PAY-005, PAY-006.
- [ ] [T006] Verify provider secret encryption/redaction, outage behavior and preservation of current gateways. Refs: PAY-007, PAY-009.
## Phase 3 — Contract and integration tests
- [ ] [T007] Map/add bKash callback contract tests. Existing candidate: `tests/Feature/BkashIpnTest.php`. Refs: PAY-002…PAY-004.
- [ ] [T008] Add/locate equivalent Nagad, SSLCommerz, webhook, manual-collection, duplicate, mismatch, timeout and return tests. Existing candidate: `tests/Feature/PaymentCollectionWorkflowTest.php`. Refs: PAY-001…PAY-009.
- [ ] [T009] Verify Accounting posted-state and Billing activation handoffs with mocked provider events; verify Admin/Manager permission boundaries. Refs: PAY-003, PAY-004, PAY-005, PAY-007, PAY-008.
- [ ] [T010] Produce provider matrix with sandbox/mocked evidence, unsupported gaps, CI results and no-live-payment confirmation. Refs: PAY-001, PAY-002, PAY-003, PAY-004, PAY-005, PAY-006, PAY-007, PAY-008, PAY-009.