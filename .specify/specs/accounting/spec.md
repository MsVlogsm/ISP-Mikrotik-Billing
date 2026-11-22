# Accounting module specification

**Status:** Draft. **Scope:** income, expense, cashflow, ledgers, collections, reseller finance summaries, and financial reports.

## Requirements
- ACC-001: Posted collection totals include only records with `payment_status = paid`; non-posted states are excluded and reported separately where reconciliation requires them.
- ACC-002: Signed paid returns/refunds reduce posted income; never convert a negative return into positive revenue.
- ACC-003: Income, ledger, dashboard, reseller cashflow, and customer paid-ledger views use the same reusable accounting scopes and definitions.
- ACC-004: Date filters validate format and order, use the business timezone, include the complete selected end date, and apply consistently to timestamps and date columns.
- ACC-005: Display pagination/row limits MUST NOT cap aggregate totals.
- ACC-006: Expenses MUST be separately classified from customer collections; expense entry, edits, deletes, and report inclusion require authorized roles and audit history.
- ACC-007: Reconciliation MUST distinguish posted income, pending/unposted items, reversals, returns, expenses, and net totals without silently dropping unmatched entries.
- ACC-008: Financial corrections MUST be traceable and transactional; historical rows must not be silently overwritten to force a report to balance.

## Acceptance criteria
- A fixture containing paid positive, paid negative return, pending, reversed, reconciled, and null-status rows yields the exact documented posted and non-posted totals.
- One-day report includes 23:59:59 on the selected date and excludes the next day's midnight.
- Ledger with more than 100 entries shows a capped list but calculates the total across all matching rows.
- Dashboard, income report, ledger, reseller report, and customer paid ledger agree for the same data.
- Missing expense entries are distinguishable from filters/date-boundary errors during investigation.

## Required tests and evidence
Unit/feature tests for accounting scopes, sign handling, date boundaries, aggregate-vs-page totals, expense CRUD/authorization, and report consistency. Add MySQL Staging verification for DB-specific date/aggregate behavior. Browser evidence must include Admin and Manager access/denials and report filters. Map every report card and column to its query and test.

## Dependencies and unresolved decisions
Depends on Billing, Payment, Reseller, and Customer. Identify the authoritative expense model, categories, edit/delete policy, opening balances, cash/bank account model, and treatment of chargebacks/fees from the actual repository; do not invent account classifications.
