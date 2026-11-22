# Network module specification

**Status:** Draft. **Scope:** network inventory, routers/switches/OLT topology, fiber/cable/core/splitter mapping, diagnostics, alarms, maps, and cross-device search.

## Requirements
- NET-001: Network inventory records MUST have stable identity, device type, management endpoint, vendor/model/firmware, ownership, status, and audit metadata.
- NET-002: Topology links (uplink, OLT/PON, cable, fiber core, splice, splitter, splitter port, ONU, customer) MUST be explicitly recorded and traceable to their source.
- NET-003: Fiber core colors MUST use the approved core-count standard (2, 4, 6, 12, 24, 48, 96, 144) and documented color sequence; the application MUST NOT infer physical splice/core connectivity.
- NET-004: Fiber/customer/ONU mapping is manual unless a separately approved, evidence-backed sync is specified. No auto-mapping based on proximity, MAC similarity, or guessed port indexes.
- NET-005: Network maps MUST distinguish confirmed, manually entered, stale, unresolved, and live-derived relationships.
- NET-006: Alarm lifecycle (create, acknowledge, disable, resolve, delete) MUST preserve event history and enforce role permissions.
- NET-007: Diagnostics and universal search MUST be read-only by default, bounded, rate-limited, and clearly label live queries versus saved inventory.
- NET-008: Network search must not imply identity certainty where multiple customers/devices match; ambiguous results remain separate candidates.
- NET-009: Network exports and map views MUST respect role scope and not expose device credentials or sensitive customer details to unauthorized users.

## Acceptance criteria
- A manually entered topology chain can be traced from OLT/PON through cable/core/splice/splitter/port to ONU/customer with actor and timestamp.
- All supported core counts produce the approved color sequence; unsupported count is rejected.
- Map renders manual and verified live links distinctly; no automatic physical mapping is performed.
- Alarm acknowledgment and lifecycle actions preserve history and deny unauthorized roles.
- Search returns saved and live results in separate groups and records live query freshness/failure.
- Stale or ambiguous topology does not display as confirmed connectivity.

## Required tests and evidence
Feature tests for topology CRUD, core-color rules, audit, role scope, alarm lifecycle, search rate limits, and ambiguity. Browser tests for map/mobile views and authorized/denied alarm actions. Use fixtures for live device results.

## Dependencies and unresolved decisions
Depends on Customer, MikroTik, OLT/ONU, and Administration. Confirm map provider/library, coordinate requirements, topology schema, cable register fields, splice semantics, alarm severity/retention, and exact core color order from approved engineering standards before implementation.
