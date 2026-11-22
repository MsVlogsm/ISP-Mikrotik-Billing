# Implementation plan — Reseller

**Status:** Draft; no wallet balance adjustment or payout is authorized by this specification.

## Architecture and source boundaries
- Core models/services include `app/Models/Reseller.php`, `BandwidthReseller.php`, `ResellerWalletTransaction.php`, `ResellerCommission.php`, `app/Services/ResellerWalletService.php`, and `ResellerCommissionService.php`.
- UI includes `app/Livewire/Reseller/ResellerCustomerList.php`, `ResellerPackageManagement.php`, `ResellerVoucherManagement.php`, `ResellerWalletManagement.php`.
- Controllers include Admin reseller/partner network controllers and Reseller dashboard controllers.
- Existing feature tests include `ResellerTest.php`, `MacResellerPanelTest.php`, `PackagePurchaseRequestTest.php`, plus shared finance accounting tests.

## Design decisions and constraints
1. Treat transaction ledger as the auditable source for wallet changes; verify whether cached balance is derived and reconciled.
2. Enforce reseller tenant scope on every server query, action, export, nested route and API—not only UI filters.
3. Wallet debit/credit and voucher/package purchase must run transactionally with concurrency protection and idempotency.
4. Define commission rate/base/timing/reversal/rounding from persisted settings and business approval; do not invent rates.
5. Reseller finance statements consume Accounting's posted/signed rules, never independent collection predicates.

## Phases
- P1 Tenant model, role middleware, relationship and wallet ledger inventory.
- P2 Wallet balance, debit/credit and purchase concurrency/idempotency review.
- P3 Commission generation/reversal and accounting/report parity.
- P4 Two-reseller isolation tests, Admin/Manager controls and browser/export evidence.

## Acceptance evidence
Two reseller identities cannot read/change one another's customer, wallet, voucher or finance data by ID substitution. Concurrent purchases cannot overspend; repeated requests cannot double debit/credit. Every manual adjustment is audited.