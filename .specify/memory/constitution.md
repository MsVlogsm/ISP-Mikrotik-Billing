<!--
Sync Impact Report
- Version change: none -> 1.0.0 (initial constitution; no prior versioned constitution exists).
- Modified principles: none; established five initial principles: Financial and Operational Data Integrity;
  Security, Privacy, and Least Privilege; Safe and Explicit Network Operations;
  Controlled Evolution and Compatibility; Evidence-Based Quality and Regression Prevention.
- Added sections: Technology and Data Constraints; Delivery and Operational Quality Gates.
- Removed sections: none.
- Ratification date recorded as 2026-11-23, based on the project owner's clarification
  that they learned about the constitution on this date.
-->

# X-Link Billing Constitution

## Core Principles

### I. Financial and Operational Data Integrity

Billing, payment, ledger, reseller-balance, and service-state calculations MUST use explicit,
consistent business rules across every dashboard, report, customer history, scheduled task,
webhook, and API that presents or changes the same data. Only transactions with
`payment_status = paid` contribute to posted collection totals. Signed paid returns remain
negative and reduce posted income. Pending, initiated, processing, reversed, reconciled,
unspecified, and other non-paid records MUST remain outside posted totals and MUST be
reported separately where they affect reconciliation or audit review.

Financial changes MUST be transactional, traceable, and idempotent where repeated requests or
provider callbacks are possible. Corrections MUST preserve an auditable record; they MUST NOT
silently erase or rewrite financial history. Date-range reports MUST include the complete
selected end date and MUST aggregate the full requested period, not merely the visible page.

Customer, PPPoE, ONU, router, and inventory identities MUST be linked only through exact,
unambiguous evidence. Ambiguous mappings, unsupported telemetry, and missing measurements
MUST remain explicitly unresolved or null; the application MUST NOT invent a match or
substitute an estimated value.

### II. Security, Privacy, and Least Privilege

Every protected operation MUST enforce authorization on the server side. Navigation visibility
or a disabled UI control is not an authorization boundary. The Manager permission set MUST
remain a strict subset of the Admin permission set, and Reseller access MUST be restricted to
the reseller's authorized customers, resources, and transactions. Privileged role and
permission administration MUST remain limited to the authorized highest-privilege role.

Passwords, API tokens, webhook secrets, router credentials, SNMP communities, and other
sensitive values MUST use the project's approved secret storage and encryption mechanisms.
They MUST NOT appear in logs, screenshots, exports, error messages, source control, or test
fixtures. Webhooks and API integrations MUST authenticate requests, validate the expected
transaction or payload, and safely handle duplicate delivery. Sensitive customer information
MUST be exposed only to users and processes with a demonstrated business need.

Administrative, financial, permission, and network changes MUST retain an appropriate audit
trail without recording secrets or unnecessary personal data.

### III. Safe and Explicit Network Operations

MikroTik, OLT, ONU, switch, and other device integrations MUST default to read-only behavior
unless a separately authorized business workflow explicitly requires a write. Read-only
inventory and dashboard GET requests MUST NOT trigger live polling or device changes.
Network polling MUST be opt-in, scoped to an identified device, and enabled only after the
required dedicated read-only credentials and transport settings are configured and verified.
Staging MUST keep live polling disabled until its own credentials and test target are
configured.

Vendor-specific MIBs, OIDs, CLI commands, and telemetry interpretations MUST be tied to
verified model, firmware, and (where relevant) transceiver evidence. Vendor-family membership
alone is not proof of device capability. Unsupported or unverified values MUST remain
unconfigured or null. SSH collection MUST require explicit enablement, stored credentials,
and a fixed read-only command allowlist; collectors MUST NOT use default credentials,
Telnet, configuration mode, or write commands.

Any device mutation MUST require an authorized operator or an explicitly approved business
workflow, a precisely scoped target, confirmation appropriate to the impact, and an audit
record. Automated tests and routine browser checks MUST use mocks, fixtures, or isolated test
devices and MUST NOT contact or modify live routers or OLTs unintentionally.

### IV. Controlled Evolution and Compatibility

Changes MUST preserve existing working billing, customer, reseller, payment, administration,
and network functionality unless an approved specification explicitly changes that behavior.
A feature change MUST be scoped to its stated purpose; unrelated production files or
functionality MUST NOT be overwritten merely to make source trees look identical. Before
release, the candidate source MUST be compared with the active Production release so
Production-only functionality and newer merged changes are both accounted for.

The current application baseline is PHP 8.5 and Laravel 13, with the versions declared and
locked by the repository's Composer manifests; Filament and Livewire versions MUST follow
those same authoritative dependency files. The Composer and npm lockfiles MUST be updated
with dependency changes. README badges or stale prose MUST NOT override the checked-in
dependency manifests and lockfiles.

Database and API changes MUST preserve existing data and supported integrations. Schema
changes MUST be tested against a fresh database and the relevant Staging database engine.
Destructive or irreversible migrations MUST be separately reviewed, backed up, and explicitly
approved before Production execution. Compatibility breaks MUST be documented with a
migration and rollback approach.

### V. Evidence-Based Quality and Regression Prevention

Every behavior change MUST include automated tests at the appropriate unit, feature,
integration, or contract level. Tests MUST cover both successful behavior and relevant
authorization, validation, failure, duplicate-request, and boundary conditions. Tests that
depend on routers, OLTs, payment providers, SMS gateways, or other external systems MUST use
controlled fixtures, mocks, or dedicated non-production services.

A change MUST pass the repository's applicable CI gates before merge: Composer validation
and installation, frontend dependency installation and production build when assets or
frontend behavior are affected, fresh database migrations, the PHPUnit suite, deployment
helper/verifier syntax checks when relevant, and Laravel configuration, route, and view
cache preflight. A local test result MUST NOT be presented as a substitute for a failed or
pending GitHub Actions check.

Changes affecting login, roles, financial pages, customer billing, network operations, or
other critical browser workflows MUST receive authenticated Staging browser verification
for the affected Admin and Manager roles. Verification MUST check expected access as well
as expected denials; a correct forbidden response is a passing authorization result.
A CI pass alone does not establish that a production-like browser workflow is correct.

## Technology and Data Constraints

- Production runs on PHP 8.5, Laravel 13, and MySQL. The exact PHP and package constraints
  MUST be taken from `composer.json` and `composer.lock`, not inferred from old README
  badges or prior deployments.
- PHPUnit and isolated SQLite databases are the default automated-test environment. Any
  MySQL-specific query, migration, collation, transaction, or locking behavior MUST also be
  verified against a MySQL Staging environment before Production use.
- Frontend assets MUST be reproducible from the checked-in npm manifest and lockfile using
  the repository's CI build command. Generated build output MUST NOT be used as a substitute
  for reviewed source changes.
- Device telemetry and customer/device association MUST follow the evidence requirements in
  `docs/olt-bridge-fdb.md`. Missing, stale, ambiguous,
  or unsupported data MUST be visibly distinguished from verified live data.
- Production secrets, environment files, storage, and customer data MUST remain outside
  disposable build and test workspaces. A test or preview environment MUST use its own
  credentials, database, and polling configuration.

## Delivery and Operational Quality Gates

- Feature work MUST begin with an agreed problem statement and observable acceptance
  criteria. Non-trivial changes MUST have a specification or equivalent written scope,
  including affected modules, data changes, permission impact, and failure/rollback behavior.
- Pull requests MUST state their scope, risk, test evidence, migration implications, and
  any known gaps. Changes MUST be reviewed against all five Core Principles.
- Staging MUST precede Production for application changes. The Staging candidate MUST use
  the intended release source and must pass CI plus the applicable authenticated browser
  checks before the change is approved for Production.
- Production deployment MUST use the repository's manual, release-aware deployment
  workflow from `main` with its explicit deployment confirmation. The candidate MUST pass
  the Laravel 13 / PHP 8.5 baseline, build, test, cache, and release-layout checks.
- Production release cutover MUST use the immutable release layout, shared runtime
  configuration/storage, verified database and code backups, atomic current-release
  switching, HTTPS health verification, and rollback to the previous release on failure.
  Direct edits to the active release, shared Production data, or Production environment
  files are prohibited outside an explicitly approved operational procedure.
- Database migrations MUST remain disabled by default in the deployment workflow. They
  may run only after a separate explicit migration decision, verified backup, Staging
  migration test, and documented rollback assessment.
- A failed CI check, incomplete required browser verification, unexplained source drift,
  failed backup, or failed health check MUST block merge or deployment. Exceptions require
  documented risk acceptance from the project owner and MUST NOT waive legal, security,
  accounting-integrity, or data-protection requirements.

## Governance

This constitution is the project's highest-level engineering policy. Product specifications,
plans, tasks, code reviews, tests, and deployment decisions MUST conform to it. Where an
existing implementation conflicts with a principle, the conflict MUST be documented and
resolved through an approved change; silently weakening the principle is not permitted.

Amendments MUST be proposed through a reviewed pull request. Each amendment MUST include
its rationale, affected principles, compatibility impact, required migration or follow-up
work, and an updated Sync Impact Report at the top of this file. The project owner or an
authorized maintainer MUST approve amendments before they are merged.

The constitution uses semantic versioning:
- MAJOR for a backward-incompatible change to an existing principle or governance rule.
- MINOR for a new principle or a material expansion of project obligations.
- PATCH for clarifications, wording corrections, and non-semantic refinements.

Every pull request MUST identify applicable principles and provide evidence for the
corresponding quality gates. Reviewers MUST reject or request changes when required evidence
is absent. Compliance MUST be reviewed again before Production approval and whenever an
incident, security finding, accounting discrepancy, or material architecture change
indicates that the governing rules need revision.

The development and operational references are `README.md`,
`.github/workflows/tests.yml`, `.github/workflows/production-deploy.yml`, and
`docs/olt-bridge-fdb.md`. These references provide implementation guidance; none
overrides this constitution.

**Version**: 1.0.0 | **Ratified**: 2026-11-23 | **Last Amended**: 2026-11-23
