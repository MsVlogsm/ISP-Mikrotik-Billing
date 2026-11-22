# Tasks: OLT / Bridge-FDB Production Source Alignment

**Source of truth:** PR #75 scope and validation record, `docs/olt-bridge-fdb.md`, and `docs/olt-capability-matrix.md`.
**Implementation baseline:** PR #75 head `d8bdcb6b0d800a78a09ed1dbab3594b094664efd`.
**Status note:** This checklist tracks completion of the existing PR #75 scope and its remaining release gates. It does not authorize Production polling, merge, or deployment.

## Phase 1 — Production source alignment

- [x] T001 Restore the Production-backed Bridge-FDB polling command and movement model.
- [x] T002 Restore the OLT capability registry and align the OLT dashboard/controller integration.
- [x] T003 Align Bridge-FDB controller, SNMP service, read-only adapter, live metrics service, configuration, and related views.
- [x] T004 Restore the SFP DDM metrics migration and Bridge-FDB movement-history migration.
- [x] T005 Add only the Production-backed five-minute Bridge-FDB schedule registration.
- [x] T006 Preserve current `main` route registrations and newer merged finance/accounting changes; do not copy older Production files over them.

## Phase 2 — Data and collector correctness

- [x] T007 Parse standard Q-BRIDGE-MIB and BRIDGE-MIB forwarding entries, including supported six-octet MAC index variants.
- [x] T008 Persist immutable FDB snapshots and entries; distinguish failed collection from a successful empty table.
- [x] T009 Resolve subscriber/ONU context only through exact, unique stored identity evidence; leave ambiguous matches unresolved.
- [x] T010 Record MAC/VLAN interface movements across successful snapshots and identify the configured flapping threshold.
- [x] T011 Keep SFP DDM and chassis telemetry dependent on exact model/firmware/transceiver evidence; leave unsupported readings null.
- [x] T012 Keep manual and scheduled polling disabled by default; ensure GET/dashboard reads do not initiate live polling.
- [x] T013 Keep SSH fallback explicitly enabled and restricted to its fixed read-only command allowlist; do not use Telnet, default credentials, configuration mode, or write commands.

## Phase 3 — Automated verification

- [x] T014 Add focused movement-history feature tests.
- [x] T015 Add SFP DDM migration regression tests.
- [x] T016 Add OLT dashboard and capability-matrix tests.
- [x] T017 Add Bridge-FDB CLI/OID parsing and multi-vendor read-only adapter tests.
- [x] T018 Run PHP syntax and whitespace/diff checks.
- [x] T019 Run focused OLT/Bridge-FDB tests (20 tests, 94 assertions).
- [x] T020 Run full PHPUnit suite (371 tests, 1,564 assertions, 9 skipped).
- [x] T021 Verify GitHub Actions run #641 passed for PR #75 (head `d8bdcb6`).
- [x] T022 Verify frontend build, Composer validation/install, fresh SQLite migrations, deploy helper/verifier syntax, and Laravel cache preflight.

## Phase 4 — Staging verification and release gates

- [x] T023 Back up the Staging database and previous public build before applying the candidate.
- [x] T024 Apply the three Bridge-FDB/SFP DDM migrations to Staging and verify the resulting tables.
- [x] T025 Verify Laravel package discovery, Vite manifest, route/config/view caches, and route/schedule registration on Staging.
- [x] T026 Confirm Staging manual and scheduled polling flags remain disabled and no SNMP/SSH poll has been executed.
- [ ] T027 Obtain an authorized authenticated Staging browser session without changing the existing Basic Auth file or user accounts.
- [ ] T028 Verify the affected OLT dashboard and Bridge-FDB pages as Admin in Staging; capture screenshot and HTTP evidence.
- [ ] T029 Verify the same pages and expected authorization denials as Manager in Staging; capture screenshot and HTTP evidence.
- [ ] T030 Verify GET requests are read-only and do not trigger SNMP/SSH polling; verify no device mutation occurs.
- [ ] T031 Review browser evidence against the PR #75 acceptance scope and record any defects as follow-up tasks.

## Additional implementation regression coverage

- [x] T037 Add feature regression tests proving OLT Dashboard and Bridge-FDB routes are GET-only and their GET controller paths render saved page data without invoking live OLT/FDB polling services or creating a Bridge-FDB snapshot. Authenticated Staging HTTP/runtime evidence remains tracked separately under T027–T030.

## Phase 5 — Final review (not deployment authorization)

- [ ] T032 Re-check PR #75 head, base `main`, changed-file scope, and compatibility with the latest merged `main`.
- [ ] T033 Re-run applicable CI after any fixes and confirm all required checks are green.
- [ ] T034 Confirm all Staging browser gates are complete and documented before requesting PR review/readiness.
- [ ] T035 Obtain separate explicit approval for merge and, later, a separate Production deployment decision.
- [ ] T036 Keep Production live polling disabled unless a separate approved change verifies dedicated read-only credentials, target hardware/model/firmware, and operational impact.

## Dependencies and safety constraints

- T027 blocks T028–T031.
- T028–T031 and T032–T034 must be complete before PR #75 can be considered ready for review under its stated release gate.
- T035 is a separate approval; completing this checklist does not merge PR #75.
- No task in this file authorizes Production changes, live OLT/router polling, credential changes, or deployment.
