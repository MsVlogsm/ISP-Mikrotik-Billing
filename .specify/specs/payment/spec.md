# Payment module specification

**Status:** Draft. **Scope:** payment collection, manual receipts, payment gateway adapters, MFS callbacks, reconciliation, refunds/returns, and service activation triggers.

## Requirements
- PAY-001: Every payment attempt/receipt MUST have a stable internal identity and preserve provider transaction references, amount, currency, customer/bill association, channel, status, and timestamps.
- PAY-002: Provider callback authentication, signature/token validation, amount/customer/reference matching, and status validation MUST occur before posting.
- PAY-003: Callback processing MUST be idempotent; duplicate callbacks or replayed requests cannot create duplicate paid receipts or repeat activation.
- PAY-004: Only confirmed paid transactions may enter posted accounting totals or trigger automatic service activation. Pending, failed, reversed, unmatched, or malformed events must not.
- PAY-005: Manual cash collection and online/MFS collections MUST have explicit actor and authorization rules; manual override requires reason and audit history.
- PAY-006: Refunds/returns/chargebacks MUST retain signed amounts and links to the original transaction; they must not erase history.
- PAY-007: Payment providers MUST be isolated behind adapters/configuration; secrets are encrypted and redacted. Provider outages must not block local billing reads or create false success.
- PAY-008: Payment reconciliation MUST identify unmatched, duplicate, amount-mismatched, stale, and reversed transactions for authorized review.
- PAY-009: Existing supported gateways must remain intact unless an approved specification explicitly removes or changes one.

## Acceptance criteria
- Valid signed callback posts exactly once and activates the intended eligible customer once.
- Duplicate callback returns a safe idempotent result and creates no duplicate ledger row.
- Wrong amount, wrong customer reference, invalid signature/token, unknown transaction, or failed status is rejected/quarantined without activation.
- Manual receipt records the actor and reason; unauthorized role is denied.
- Refund/return reduces net posted income while preserving the original receipt and audit trail.
- Provider timeout does not create a paid transaction.

## Required tests and evidence
Contract tests per provider for valid/invalid signatures, duplicate/replay, timeout, mismatch, status transitions, refund, and idempotency. Feature tests for permissions, ledger integration, and activation boundaries. Use provider sandbox or mocks only; no live financial transaction in tests.

## Dependencies and unresolved decisions
Depends on Billing, Accounting, Customer, and Administration. Inventory currently enabled providers, webhook routes, settlement model, supported currencies, refund policy, and credential rotation process from code/config; do not assume README lists are exhaustive or current.
