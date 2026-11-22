# Reseller module specification

**Status:** Draft. **Scope:** reseller accounts, customer ownership, wallets, credits, package/voucher purchases, commissions, statements, and access boundaries.

## Requirements
- RES-001: Each reseller's customer, wallet, transaction, package, voucher, and report access MUST be scoped to that reseller on the server side.
- RES-002: Wallet balance MUST be derived from an immutable transaction ledger or a reconciled balance model; direct unexplained balance edits are prohibited.
- RES-003: Wallet debit/credit and package/voucher purchase MUST be transactional and idempotent; concurrent purchases cannot overspend the available balance.
- RES-004: Commission rules MUST define eligibility, rate/base, timing (upfront/deferred), reversal behavior, and rounding before calculation.
- RES-005: Commission payment, reversal, wallet adjustment, and manual credit require permission, reason, and audit trail.
- RES-006: Reseller statements and cashflow reports MUST use the same posted collection and signed-return accounting rules as the Accounting module.
- RES-007: Reseller users MUST NOT access other resellers' customers or financial records by changing URL, identifier, filter, export, or API payload.
- RES-008: Admin/Manager reseller management permissions MUST be explicit and tested; UI hiding is not sufficient.

## Acceptance criteria
- Cross-reseller ID substitution is denied for pages, APIs, exports, and nested resources.
- Concurrent purchase attempts cannot create negative wallet balance or duplicate vouchers.
- Replayed purchase/payment events do not double debit/credit.
- Commission calculation matches documented fixtures for upfront, deferred, cancellation, refund, and rounding.
- Reseller statement agrees with Accounting for the same transactions and date range.
- Every manual wallet/commission adjustment records actor, reason, before/after, and reference.

## Required tests and evidence
Feature tests for tenant isolation, authorization, wallet concurrency/transaction rollback, idempotency, commission fixtures, export scope, and accounting parity. Browser checks with two reseller identities plus Admin/Manager access.

## Dependencies and unresolved decisions
Depends on Customer, Billing, Payment, Accounting, and Administration. Confirm whether wallet is cash-backed/prepaid, negative balance policy, commission schedule, voucher lifecycle, transfer/refund behavior, and exact reseller role hierarchy from current code and business approval.
