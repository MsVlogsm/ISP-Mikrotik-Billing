# Repository-wide module specifications

**Baseline:** `main` at `eced4661783a832dd314cb1f060295075f338c54` (2026-11-22).  
**Status:** Draft requirements for review; not a claim that every requirement is implemented or tested.

These specifications define intended behavior and acceptance evidence for the ten named modules. Each requirement must be traced to implementation files, tests, permissions, routes, migrations, and external integrations during the analysis phase. Unknown or conflicting current behavior must be recorded as a gap rather than guessed.

## Modules
1. [Billing](billing/spec.md)
2. [Accounting](accounting/spec.md)
3. [Customer](customer/spec.md)
4. [MikroTik](mikrotik/spec.md)
5. [OLT/ONU](olt-onu/spec.md)
6. [Network](network/spec.md)
7. [Payment](payment/spec.md)
8. [SMS](sms/spec.md)
9. [Reseller](reseller/spec.md)
10. [Administration](administration/spec.md)

## Cross-module rules
- Use the Constitution as the governing policy.
- Treat Composer/npm manifests and lockfiles as version authority; README technology badges may be stale.
- A shared business fact (customer identity, payment state, due, online session, ONU mapping) must have one authoritative source and consistent consumers.
- Each requirement needs implementation traceability and at least one named automated or browser acceptance test.
- Tests that would contact routers, OLTs, payment providers, or SMS providers must be mocked or use isolated non-production credentials.
- Every module must identify authorization roles, data ownership, audit events, failure behavior, migrations, exports, and dependencies.
- No specification grants permission to enable Production polling, change Production credentials, run Production migrations, merge a PR, or deploy.

## Analysis exit criteria
The repository-wide analysis is complete only when every requirement is marked implemented, partially implemented, missing, contradicted, or not verifiable; each status cites concrete source/test paths; cross-module conflicts and uncovered acceptance criteria are listed; and CI plus required Staging evidence are distinguished from static inspection. Do not call a requirement “passed” solely because a similarly named test exists.
