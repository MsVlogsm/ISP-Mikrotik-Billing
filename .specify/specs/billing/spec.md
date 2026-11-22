# Billing module specification

**Status:** Draft. **Scope:** customer recurring charges, bill generation, due calculation, prepaid expiry, bill presentation, and billing notifications. Payment posting and accounting presentation are specified separately and must share the same authoritative paid-state rules.

## Requirements
- BILL-001: Generate recurring bills deterministically for the configured billing period and customer/package state. Re-running a job MUST NOT create duplicate bills.
- BILL-002: Calculate bill, paid, due, previous due, advance, discount, and adjustment values from persisted ledger/payment facts; avoid independent conflicting formulas in dashboards, lists, profiles, exports, and APIs.
- BILL-003: For prepaid service, disable the service on the configured expiry/connection rule and date; date boundaries MUST use the configured business timezone and be tested at start/end of day.
- BILL-004: A confirmed successful payment may renew/enable service according to the configured payment channel workflow. Cash/manual receipts require the authorized manual workflow. A failed, pending, reversed, duplicate, or unmatched payment MUST NOT activate service.
- BILL-005: Customer billing history MUST show month-by-month bills and paid-ledger entries, including returns/adjustments distinctly.
- BILL-006: Billing lists and reports MUST use consistent filters, pagination-independent totals, and full inclusive date ranges.
- BILL-007: Scheduled bill generation, expiry enforcement, reminders, and retryable work MUST be observable, idempotent, and safe under overlapping scheduler/queue execution.
- BILL-008: Customer-facing and staff-facing status labels MUST distinguish paid, due, overdue, disabled, enabled, and online; online state must not be inferred from payment state.

## Acceptance criteria
- Replaying a bill-generation command for the same customer/period creates no duplicate bill.
- A payment callback delivered twice changes the bill/service state only once.
- A pending or reversed receipt leaves posted paid amount and service activation unchanged.
- Expiry at the configured local date boundary produces the documented service state; adjacent-day cases are covered.
- Customer profile, billing list, dashboard, paid ledger, and export agree on the same fixture.
- A report's total includes records beyond the visible page and includes the selected end date.

## Required tests and evidence
Feature tests for generation idempotency, due arithmetic, payment/expiry transitions, date boundaries, authorization, and profile/list/report consistency. Scheduler/queue tests must simulate duplicate dispatch and failure/retry. Browser checks for Admin and Manager billing workflows; verify Reseller scope separately. Trace each criterion to actual tests and implementation before marking it met.

## Dependencies and unresolved decisions
Depends on Customer identity, Payment finality, Accounting posted scopes, Router service-state integration, and SMS notifications. Any exception/grace period, proration, package change mid-cycle, partial payment, credit note, and manual override rule must be discovered from code/business evidence and explicitly documented before implementation changes.
