# Implementation plan — Network

**Status:** Draft; physical fiber relationships remain manual unless separately specified and approved.

## Architecture and source boundaries
- Network UI includes `app/Livewire/NetworkMap.php`, `NetworkTopology.php`, `NetworkEvents.php` and routes in `routes/web.php`.
- Inventory/health/events/topology data includes `NetworkInventoryDevice`, `NetworkInventoryHealthCheck`, `NetworkTopologyNode`, `NetworkTopologyLink`, `NetworkTopologyMap`, `NetworkEvent` and alert-delivery models.
- Health probes/notifications include `NetworkInventoryHealthProbe` and `NetworkAlertNotificationService`.
- Universal search route/controller includes `UniversalNetworkSearchController`; live MikroTik queries need explicit submission, rate limits, read-only credentials, and source freshness.
- Fiber/core/splice/splitter schema must be discovered before proposing migrations; do not assume the full topology data model already exists.

## Design decisions and constraints
1. Separate saved inventory, manually recorded topology, stale links, and verified live telemetry.
2. Manual fiber map is the source of physical connectivity; no automatic customer↔splitter↔ONU wiring inference.
3. Apply approved core-count choices (2/4/6/12/24/48/96/144); verify exact color ordering against the approved engineering standard before code.
4. Alarm lifecycle changes must be append-only/auditable and role-scoped.
5. Search and diagnostics must be read-only, bounded and distinguish static matches from live device query results.

## Phases
- P1 Inventory route/model/schema and map component audit.
- P2 Trace topology CRUD, coordinates, cable/core/splice/splitter links and manual provenance.
- P3 Trace alarm create/acknowledge/disable/resolve/delete and notifications.
- P4 Trace search/live diagnostics, tenant access, rate limits and freshness.
- P5 Automated/browser verification and migration impact review.

## Acceptance evidence
A complete physical path is traceable with actor/time and manual/verified labels. Tests cover all supported core-count choices, alarm history/permissions, search limits and ambiguity. No automatic physical mapping is introduced.