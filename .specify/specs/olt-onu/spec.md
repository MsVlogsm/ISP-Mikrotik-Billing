# OLT / ONU module specification

**Status:** Draft. **Scope:** OLT inventory, vendor capability registry, ONU discovery, ONU/customer mapping, telemetry, diagnostics, Bridge-FDB, optical readings, and authorized ONU actions.

## Requirements
- ONU-001: Support claims MUST be capability-based and tied to verified vendor, exact model, firmware, and relevant optics/transceiver evidence.
- ONU-002: Discovery and telemetry MUST preserve source, timestamp, success/error state, and raw identity needed for audit; failed collection is not an empty result.
- ONU-003: ONU-to-customer/PPPoE mapping MUST require exact unique evidence (MAC, serial, or verified interface identity). Ambiguous or stale matches remain unresolved.
- ONU-004: Manual mapping and sync operations MUST be separately permissioned, auditable, and reversible; no inferred mapping may be written automatically.
- ONU-005: Unsupported CPU, memory, temperature, uptime, RX/TX optical power, or other values remain null with an explicit unsupported/unavailable state.
- ONU-006: Direct SNMP collection is read-only. RouterOS SNMP proxy requires explicit proxy-router selection and credentials. SSH fallback requires explicit enablement, saved credentials, and fixed vendor read-only commands. No Telnet/default credentials/config writes.
- ONU-007: Dashboard/GET requests MUST display saved data only and MUST NOT trigger SNMP/SSH polling. Live polling remains disabled until separate staging and production authorization.
- ONU-008: ONU reboot or other device mutation requires privileged permission, explicit device/ONU target confirmation, audit record, and a tested failure path.
- ONU-009: Bridge-FDB snapshots distinguish collection error from successful empty table; MAC movement history uses consecutive successful snapshots only.
- ONU-010: Vendor OIDs and CLI parsers require fixture-based tests per model/firmware; no generic OID inference.

## Acceptance criteria
- Unsupported/unknown vendor firmware yields null telemetry and no false healthy status.
- Duplicate or ambiguous ONU identity is not assigned to a customer.
- GET navigation creates no device POST and no new telemetry/FDB snapshot.
- Unauthorized polling and reboot endpoints deny access before collector/action execution.
- Failed poll is stored as an error and not as an empty successful inventory.
- Manual mapping changes are attributable and can be reviewed; no automatic mapping occurs without exact evidence.
- Staging verification uses separate credentials and explicitly configured test OLT; Production polling remains off absent separate approval.

## Required tests and evidence
Unit tests for MIB/OID parsing and capability matrix; feature tests for mapping ambiguity, sync authorization, snapshots, GET side-effect absence, polling/reboot denials, and audit history. Per-vendor fixtures must identify model/firmware. Browser verification for Admin and Manager must include GET-only pages and forbidden POST paths.

## Dependencies and unresolved decisions
Depends on Customer, MikroTik, Network, and Administration. Confirm supported vendors/models/firmware, SNMP versions, optical thresholds, discovery source of truth, manual mapping lifecycle, polling cadence, retention, and exact ONU mutation permissions from code and approved operational policy. No live polling authorization is granted here.
