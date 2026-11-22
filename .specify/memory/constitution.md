# X-Link Billing Constitution

**Status:** Draft for project-owner review. **Version:** 1.0.0. **Ratified:** TODO — do not infer a ratification date.

## Core principles

### 1. Financial and operational data integrity
Billing, payment, ledger, reseller balance, and service-state calculations MUST use one explicit business rule wherever the same fact is presented. Only `payment_status = paid` records contribute to posted collection totals; paid returns remain signed negative amounts. Pending, processing, reversed, reconciled, and unspecified records MUST remain outside posted income and be visible separately when relevant. Financial mutations MUST be transactional, auditable, and idempotent for repeated requests and provider callbacks. Reports MUST include the full selected date range, independent of pagination. Customer, PPPoE, ONU, router, and inventory identity links require exact, unique evidence; ambiguity or missing telemetry stays unresolved/null.

### 2. Security, privacy, and least privilege
Every protected operation MUST enforce server-side authorization. UI visibility is not authorization. Admin, Manager, and Reseller capabilities MUST follow documented least-privilege boundaries; Resellers may access only their own authorized customers, resources, and transactions. Passwords, API tokens, webhook secrets, router credentials, SNMP communities, and personal data MUST not leak into source control, logs, exports, screenshots, errors, or fixtures. External callbacks MUST authenticate, validate, and safely handle duplicate delivery. Sensitive actions MUST be auditable without recording secrets.

### 3. Safe and explicit network operations
MikroTik, OLT, ONU, switch, and other device operations MUST be read-only by default. Dashboard/GET requests MUST NOT initiate polling or device writes. Polling MUST be explicitly enabled, device-scoped, rate-limited, and backed by separately configured credentials. Staging MUST use its own credentials and test targets; live polling stays disabled until separately approved. Vendor OIDs/commands require verified model, firmware, and where applicable transceiver evidence. Unsupported readings stay null. SSH requires explicit enablement, saved credentials, and fixed read-only command allowlists; no default credentials, Telnet, configuration mode, or write commands. Mutations require explicit authorization, scoped target, appropriate confirmation, and audit evidence.

### 4. Controlled evolution and compatibility
Preserve working billing, accounting, customer, reseller, payment, administration, and network behavior unless an approved specification changes it. Scope changes narrowly; do not overwrite unrelated files to force source-tree similarity. Compare release candidates with active Production sources before release. The authoritative platform baseline is PHP 8.5 and Laravel 13; dependency manifests and lockfiles override stale README badges or prose. Database/API changes must preserve data and integrations. Test fresh migrations and the relevant Staging database engine. Destructive or irreversible Production migrations require separate review, backup, and explicit approval.

### 5. Evidence-based quality and regression prevention
Every behavior change requires appropriate unit, feature, integration, or contract tests, including relevant denial, invalid input, failure, duplicate, and boundary cases. External device/provider tests use mocks, fixtures, or dedicated non-production systems. Applicable CI gates include Composer validation/install, frontend build when relevant, fresh migrations, PHPUnit, deployment helper syntax, and Laravel cache preflight. Critical Admin/Manager browser workflows require authenticated Staging checks for allowed and denied actions. CI alone is not browser evidence; local tests do not replace GitHub Actions results.

## Technology and operational constraints
- PHP 8.5, Laravel 13, and package versions are determined by checked-in Composer manifests/lockfiles.
- PHPUnit with isolated SQLite is the default automated test environment; MySQL-specific behavior must also be tested against MySQL Staging.
- Production secrets, environment files, customer data, and shared storage are never copied into disposable test workspaces.
- Production deployment uses the repository's manual, release-aware workflow from `main`, explicit confirmation, verified backups, tested candidate, health checks, and rollback readiness.
- Database migrations remain disabled by default in deployment and require a separate explicit decision.
- Failure of CI, required browser verification, backup, source alignment, or health checks blocks release unless the project owner explicitly accepts a documented non-prohibited risk.

## Governance
This constitution governs specifications, plans, tasks, code review, tests, and release decisions. Conflicts with existing implementation must be documented and resolved, not hidden by weakening the rule. Amendments require a reviewed pull request, rationale, impact analysis, and updated version. Semantic versioning: MAJOR for incompatible rule changes, MINOR for new/material principles, PATCH for clarifications. No ratification date may be inserted without explicit project-owner confirmation.
