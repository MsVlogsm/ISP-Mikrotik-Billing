# Implementation plan — OLT / ONU

**Status:** Draft; no permission to enable Production polling or device writes.
**Stack:** PHP 8.5 / Laravel 13; vendor collectors remain capability- and credential-scoped.

## Architecture and source boundaries
- Inventory and dashboard: `NetworkInventoryDevice`, `OltDashboardController`, `OltProvisioningController`, related routes and Filament/Livewire surfaces.
- Identity: `OltOnuCustomerMapping`, `OltOnuCustomerPppLink`, `OnuMacLedger`, `OnuMacLedgerService`, `OnuMappingIdentityReconciler`, `SyncOltOnuMappings`.
- Collection: `OltReadOnlyAdapterManager`, `MultiVendorSnmpReadOnlyAdapter`, `VsolSnmpReadOnlyAdapter`, `OltLiveMetricsService`, `BridgeFdbSnmpService`, `BridgeFdbOidParser`.
- Existing docs: `docs/olt-bridge-fdb.md`, `docs/olt-capability-matrix.md`, and existing PR #75 tasks artifact.
- Relevant tests include OLT dashboard/provisioning, ONU mapping/ledger/sync, Bridge-FDB SNMP/parser, multi-vendor read-only adapter, optical audit and VSOL OID profile tests.

## Design decisions and constraints
1. Capability registry is the authoritative gate for model/firmware-specific metrics; unknown data stays null.
2. Collection success/error/empty are distinct outcomes, and every sample includes source and timestamp.
3. Customer identity resolution uses exact unique MAC/serial/interface evidence. No heuristic or physical topology inference.
4. GET/UI rendering reads persisted observations only. Polling and ONU mutations are separately authorized POST/action paths.
5. Direct SNMP, configured RouterOS proxy and explicitly enabled SSH are separately traceable read-only collectors. SNMPv3 limitations and unsupported capabilities must be explicit.
6. All device tests use fixtures/mocks; Production live polling stays disabled absent separate approval.

## Phases
- P1 Catalog schemas, migrations, capability matrix, routes, adapters and data flows.
- P2 Validate identity reconciliation and MAC ledger against ambiguous/stale/duplicate examples.
- P3 Validate each vendor/firmware collector and failure/unsupported behavior.
- P4 Validate GET side-effect absence, poll/reboot denials, audit and Staging browser evidence.
- P5 Document per-vendor coverage matrix and operational enablement gates.

## Acceptance evidence
Tests must identify vendor/model/firmware fixture; every telemetry field must map to exact OID/parser and null behavior. Authenticated Staging evidence must prove GET-only display and forbidden mutations without making live Production requests.