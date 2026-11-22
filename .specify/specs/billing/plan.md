# Implementation plan — Billing

**Status:** Draft plan for analysis; implementation not authorized by this document.
**Stack baseline:** PHP 8.5 / Laravel 13 / Filament 5 / Livewire 4 from Composer.
**Constitution:** Financial integrity, idempotency, least privilege, explicit network actions, and evidence-based tests are mandatory.

## Architecture and source boundaries
- Billing presentation and workflow entry points include `app/Http/Controllers/BillingFinanceDashboardController.php`, `app/Http/Controllers/CustomersController.php`, `app/Http/Controllers/CustomerDetailsController.php`, `app/Livewire/PaymentCollection.php`, `app/Livewire/CustomerList.php`, and corresponding `routes/web.php` declarations.
- Core persisted facts include `BillingInfo`, `PaymentSummary`, `CollectionSummary`, `CustomersInfo`, and package/customer associations. Verify exact relationships and field definitions before changing code.
- Prepaid date/expiry logic has dedicated service boundaries: `app/Services/PrepaidBillingCycle.php` and `app/Services/PrepaidExpiryGuard.php` (verify exact current paths before implementation).
- Payment finality belongs to Payment; posted financial meaning belongs to Accounting. Billing must consume those facts, not duplicate their rules.
- Router enable/disable is an external side effect. Keep local bill state separate and dispatch only through explicitly authorized integration workflows.

## Design decisions and constraints
1. Trace bill creation, due calculation, advance/discount, renewal, expiry, and reminder paths before consolidating logic.
2. Prefer existing services/models and current Laravel 13 conventions over a parallel billing engine.
3. Make bill generation and expiry processing idempotent under scheduler/queue retries and concurrent execution.
4. Keep business timezone and date-boundary rules explicit; test immediately before/at/after expiry.
5. No test may issue a Production MikroTik command. Use mocks or isolated staging fixtures.

## Phases
- P1 Baseline: inventory bill queries, jobs/commands/schedules, model fields, permissions, and consumers.
- P2 Domain consistency: document authoritative due/paid/expiry state, then propose narrowly scoped service changes only where drift is proven.
- P3 Reliability: idempotency, duplicate dispatch, queue failure/retry, timezone and date-boundary handling.
- P4 Verification: feature/unit tests, report parity, Admin/Manager browser checks, and Reseller scope checks.
- P5 Release readiness: CI evidence, migration/rollback review, and documented Staging-only external effects.

## Acceptance evidence
Every BILL requirement maps to an implementation path and a test method. Billing list, customer profile, dashboard, paid ledger, and exports must agree on a shared fixture. Record unresolved policy questions rather than choosing defaults.