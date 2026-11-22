# Tasks — Reseller

**Traceability:** RES-001…RES-008 in `spec.md`.

## Phase 1 — Tenant and ledger mapping
- [ ] [T001] Trace reseller/user/customer ownership relations and all reseller-scoped routes/queries/exports. Refs: RES-001, RES-007. Paths: `app/Models/Reseller.php`, `BandwidthReseller.php`, `app/Http/Controllers/Reseller`, reseller Livewire components.
- [ ] [T002] Trace wallet balance source, transaction types, opening balances, adjustments and audit metadata. Refs: RES-002, RES-005. Paths: `app/Models/ResellerWalletTransaction.php`, `app/Services/ResellerWalletService.php`.
## Phase 2 — Financial operations
- [ ] [T003] Verify transaction isolation, locking, rollback, idempotency and insufficient-balance behavior for concurrent purchases. Refs: RES-002, RES-003.
- [ ] [T004] Trace package/voucher purchase, voucher issue/void/refund and commission generation/reversal. Refs: RES-003, RES-004. Paths: `ResellerPackageManagement.php`, `ResellerVoucherManagement.php`, `app/Services/ResellerCommissionService.php`, `app/Listeners/GenerateResellerCommission.php`.
- [ ] [T005] Verify commission base/rate/timing, rounding, cancellations and return handling against approved configuration and fixtures. Refs: RES-004, RES-005.
- [ ] [T006] Compare reseller statements/cashflow/ledger with Accounting's posted scope and signed-return behavior. Refs: RES-006.
## Phase 3 — Isolation tests and browser
- [ ] [T007] Map/add reseller wallet/customer/voucher/commission tests. Existing candidates: `tests/Feature/ResellerTest.php`, `MacResellerPanelTest.php`, `PackagePurchaseRequestTest.php`.
- [ ] [T008] Add adversarial cross-reseller ID substitution tests for routes, APIs, nested resources, and exports. Refs: RES-001, RES-007, RES-008.
- [ ] [T009] Add concurrent purchase, duplicate request, ledger reconciliation and manual adjustment audit tests. Refs: RES-002…RES-006.
- [ ] [T010] Browser verify two different reseller accounts plus Admin/Manager actions; record exact allowed/denied matrix and CI evidence.