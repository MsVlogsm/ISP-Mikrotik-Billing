# Implementation plan — Accounting

**Status:** Draft plan; no financial code changes authorized.
**Stack:** PHP 8.5 / Laravel 13; database behavior must be checked on SQLite CI and MySQL Staging.

## Architecture and source boundaries
- Primary model/query boundary: `app/Models/CollectionSummary.php`; expense data: `app/Models/IspExpense.php`.
- Reporting surfaces include `app/Http/Controllers/CollectionReportController.php`, `app/Http/Controllers/BillingFinanceDashboardController.php`, Admin expense management, and income/ledger routes in `routes/web.php`.
- Reseller finance consumers include `app/Http/Controllers/Admin/PartnerNetworkController.php`.
- Existing accounting regression evidence: `tests/Feature/FinanceAccountingReportTest.php`; PR #74 introduced posted/non-posted scopes and inclusive date-boundary tests.

## Design decisions and constraints
1. Treat paid status as the only posted collection state; preserve signed paid returns and keep non-posted rows separately visible.
2. Use shared query scopes/services instead of copy-pasted predicates, but first enumerate all consumers and verify semantics.
3. Aggregates must be computed independently of paginated/limited result rows.
4. Validate date input and use inclusive local-date semantics for timestamp and date columns.
5. Do not mutate/delete historical financial facts to reconcile reports; adjustments require audit evidence.

## Phases
- P1 Consumer inventory: all collection/expense sums, charts, reports, exports, reseller statements.
- P2 Semantic parity: compare all query filters/status/sign/date behavior with shared accounting scopes.
- P3 Data correctness: expense CRUD/history, reversals, reconciliation, transaction boundaries.
- P4 Verification: status matrix, signed return fixtures, full-day dates, >100 row aggregates, MySQL staging.
- P5 Release evidence: CI, Admin/Manager browser reports, backup/migration review.

## Acceptance evidence
A single fixture must reconcile across dashboard, income report, ledger, customer paid ledger, and reseller cashflow. Every displayed total must identify its source query and date/status treatment.