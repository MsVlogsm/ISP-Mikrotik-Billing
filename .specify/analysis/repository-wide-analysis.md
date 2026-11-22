# Repository-wide requirements / implementation / test analysis

**Baseline:** `main` commit `eced4661783a832dd314cb1f060295075f338c54` (2026-11-22).  
**Analysis mode:** Static source and documentation review plus prior repository inventory. This is not a canonical `/speckit.analyze` run.  
**Status vocabulary:** Observed = supported by inspected source; Partial = some route/UI/service evidence but full behavior not proven; Unverified = implementation/test mapping not inspected sufficiently; Gap = artifact/evidence missing.

## 1. Repository baseline findings

- Composer declares PHP `^8.5`, Laravel `^13.0`, Filament `^5.0`, Livewire `^4.0`, PHPUnit `^12.0`. These are authoritative over the stale README badges and Tech Stack section, which still advertise Laravel 12, PHP 8.3+, and Filament 3.
- `routes/web.php` is approximately 1,700+ lines and centralizes many module routes, closures, dashboards, and integration actions. This raises route discoverability and module-boundary regression risk; it is not by itself proof of a runtime defect.
- Prior main-branch inventory: 1,192 tracked files; 301 under app, 281 resources, 139 database, 88 test files (79 Feature, 8 Unit, plus TestCase), 3 route files, and 3 GitHub workflow files.
- Existing CI workflow includes PHP 8.5, frontend install/build, Composer validation/install, SQLite migrations, PHPUnit, deployment helper syntax, and Laravel cache preflight.
- Production deploy workflow is manual, requires typed `deploy`, runs from `main`, keeps migrations as a separate opt-in input, and verifies the HTTPS login endpoint and Laravel 13 release.
- Main has no previously established repository Constitution or module spec set. The current branch adds the Constitution and ten module `spec.md` artifacts. Ratification remains explicitly unconfirmed.
- Existing OLT/Bridge-FDB feature artifacts include `docs/olt-bridge-fdb.md`, `docs/olt-capability-matrix.md`, and `.specify/specs/olt-bridge-fdb/tasks.md`; the last is a PR #75 execution checklist, not a substitute for the module's full requirements/plan.

## 2. Module traceability review

| Module | Source evidence observed on main | Requirements coverage | Test traceability |
|---|---|---|---|
| Billing | `BillingFinanceDashboardController`, `PaymentCollection`, customer billing routes, `customer/{id}/paid-ledger`, `income-summary`, `ledger-summary` | Partial: billing/report endpoints exist; consistency across every bill, expiry, due, and activation path not proven | Unverified per requirement; test suite has no demonstrated requirement-to-test matrix |
| Accounting | `CollectionReportController`, billing finance dashboard, income/ledger routes; PR #74 changed consumers to `postedForAccounting()` and introduced full-day date boundaries | Partial: posted/non-posted accounting behavior has targeted PR #74 tests; all report consumers and expenses still need full trace | Partial: PR #74 adds focused accounting feature tests; full module coverage not established |
| Customer | `CustomerList`, `NewCustomer`, `EditCustomer`, `CustomerDetailsController`, `CustomersController`, paid-ledger route | Partial: customer CRUD/list/profile and billing routes exist; optional-field rules, exports, ONU telemetry freshness, lifecycle behavior need verification | Unverified per requirement |
| MikroTik | RouterOS Livewire components include PPPoE, IP, interface, queue, firewall, VPN, backup, hotspot, traffic, logs; `RouterList` appears in network dashboard routes | Partial: integration surface exists; read/write separation, API/API-SSL validation, live-session freshness, bulk-copy idempotency not fully traced | Unverified per requirement |
| OLT/ONU | OLT inventory CRUD/sync routes, ONU mapping models/services, ONU MAC ledger route; `docs/olt-bridge-fdb.md` describes SNMP/FDB behavior | Partial: capabilities, read-only collection, exact mapping, polling controls have documented evidence; all vendor adapters and reboot controls not fully traced | Partial: PR #75 tasks and focused tests documented; T036 explicitly leaves Production polling disabled |
| Network | `NetworkEvents`, `NetworkMap`, `NetworkTopology`, inventory routes, network dashboard and device attention data | Partial: inventory/map/topology surfaces exist; physical fiber mapping, manual-only rules, alarm lifecycle, map evidence semantics require code-level trace | Unverified per requirement |
| Payment | bKash/Nagad/SSLCommerz controllers, payment collection/edit/invoice routes, mobile banking configuration | Partial: gateway endpoints and collection UI exist; provider-by-provider authentication, idempotency, refund and activation semantics need audit | Unverified per provider/requirement |
| SMS | `SMSSetup`, SMS service/send routes, templates and dispatch logs | Partial: setup, template, send, and delivery-log surfaces exist; active provider, retry/idempotency, inbound MFS filtering and secret masking require verification | Unverified per requirement |
| Reseller | reseller dashboard controllers, customer/package/voucher/wallet Livewire components, cashflow and ledger routes | Partial: wallet/voucher/customer/finance surfaces exist; tenant isolation, concurrency, commission reversal, cross-reseller exports need full proof | Unverified per requirement |
| Administration | role management, `EnforceModulePermissions`, activity/login/system logs, expense manager, settings and operational dashboard routes | Partial: role and audit surfaces exist; complete route/action/API permission matrix and secret redaction not proven | Unverified per requirement |

## 3. Cross-module risks and gaps

### P1 — README dependency baseline is misleading
README badges and Tech Stack prose conflict with Composer's Laravel 13 / PHP 8.5 / Filament 5 baseline. Documentation should be corrected in a separate, reviewable change; dependency versions must not be downgraded to match stale prose.

### P1 — Requirement-to-test traceability is missing
The 88-file test inventory does not establish that every requirement in these ten specifications is implemented or covered. A matrix must map each requirement ID to concrete source paths, test class/methods, and browser evidence. Existing tests with similar names are not sufficient proof.

### P1 — Authorization proof is incomplete at module level
A global permission middleware and role-management route are present, but every mutating route, Livewire action, API endpoint, export, queue dispatch, and nested resource needs direct authorization tests. Admin/Manager allowed and denied behavior and Reseller tenant isolation must be independently evidenced.

### P1 — Device side effects need explicit boundaries
OLT documentation says GET pages read saved snapshots and polling is separately enabled. The module spec extends the same principle to MikroTik and all network diagnostics. Verify every GET, dashboard mount, scheduled task, sync action, and POST path to ensure device requests occur only at explicit authorized entry points. Production OLT polling remains disabled unless separately approved.

### P1 — Financial source-of-truth needs full consumer audit
PR #74 introduced shared posted/non-posted accounting scopes in key reports and tests. Remaining collection queries across Billing, Payment, Reseller, dashboard cards, exports, and API responses must be checked for raw unscoped aggregation and inconsistent return handling.

### P2 — Route concentration increases coupling
The large web route file mixes route declarations and inline business logic. Move behavior only where a focused refactor is justified and protected by tests; do not do a broad route rewrite as part of specification adoption.

### P2 — Existing OLT spec artifact is feature-specific
The existing `olt-bridge-fdb/tasks.md` is a completed/partially gated PR #75 checklist. It must be linked to the broader OLT/ONU specification without treating it as coverage of all OLT/ONU requirements.

### P2 — Technology and documentation drift
README also contains old repository URL and feature descriptions. Audit docs against the actual repo and deployed Laravel 13 baseline before making them authoritative.

## 4. Spec Kit analysis gate

The official Spec Kit documentation describes `/speckit.analyze` as a read-only consistency check across `spec.md`, `plan.md`, and `tasks.md`; it requires a successfully generated `tasks.md`. The ten module directories currently contain requirements `spec.md` only. Therefore the canonical Spec Kit analyze command is **not yet runnable** for these modules. No Spec Kit CLI/agent command was available through the connected staging session during this run.

Before claiming canonical analysis complete:
1. Initialize the official Spec Kit integration on a clean review branch/baseline (do not run `--force` against an unreviewed non-empty checkout).
2. Create/review a module-specific `plan.md` and `tasks.md` for each module.
3. Add implementation/source/test traceability and resolve any requirement ambiguities.
4. Run `/speckit.analyze` per module and record the read-only report; rerun after corrections.
5. Run CI and the applicable authenticated Staging browser tests. These are separate from static specification analysis.

## 5. Release and safety decision

This PR contains documentation/specification artifacts only. It does not authorize code changes, migrations, Production deployment, router/OLT credential changes, or live polling. Do not merge or deploy based only on this analysis. The Constitution's ratification date remains unconfirmed.
