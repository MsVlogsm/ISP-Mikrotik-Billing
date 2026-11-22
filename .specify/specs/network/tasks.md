# Tasks — Network

**Traceability:** NET-001…NET-009 in `spec.md`.

## Phase 1 — Inventory and topology source
- [ ] [T001] Inventory device, health, event, topology, map models/migrations and their CRUD routes. Refs: NET-001, NET-002, NET-005. Paths: `app/Models/NetworkInventoryDevice.php`, `NetworkTopologyNode.php`, `NetworkTopologyLink.php`, `NetworkTopologyMap.php`, `routes/web.php`.
- [ ] [T002] Audit map rendering and relationship provenance: manual, live-derived, stale, unresolved. Refs: NET-002, NET-005. Paths: `app/Livewire/NetworkMap.php`, `NetworkTopology.php`.
- [ ] [T003] Discover fiber cable/core/splice/splitter data structures; verify approved core palette and prohibit inferred connectivity. Refs: NET-002…NET-004. Explicit core-count acceptance mapping: NET-003.
## Phase 2 — Alarms and search
- [ ] [T004] Trace alarm lifecycle, delivery, acknowledgment history, disable/resolve/delete permissions. Refs: NET-006. Paths: `app/Models/NetworkEvent.php`, `app/Services/NetworkAlertNotificationService.php`, `app/Livewire/NetworkEvents.php`.
- [ ] [T005] Audit universal search query boundaries, live RouterOS read calls, rate limits, result ambiguity and freshness labels. Refs: NET-007, NET-008. Path: `app/Http/Controllers/UniversalNetworkSearchController.php`.
- [ ] [T006] Verify role scoping and masking for inventory, maps, search results and exports. Refs: NET-009.
## Phase 3 — Tests and browser
- [ ] [T007] Map/add topology and health tests. Existing candidates: `tests/Feature/NetworkTopologyTest.php`, `tests/Unit/NetworkInventoryHealthProbeTest.php`. Refs: NET-001, NET-002, NET-003, NET-004, NET-005.
- [ ] [T008] Map/add alarm lifecycle, rate-limit, search ambiguity, and manual-vs-live topology provenance tests. Existing candidate: `tests/Feature/NetworkMonitoringAlertTest.php`. Refs: NET-006, NET-007, NET-008, NET-009.
- [ ] [T009] Browser-verify map and alarm flows for Admin/Manager, including denials and mobile layout; verify no GET-triggered device requests. Refs: NET-005, NET-006, NET-007, NET-009.
- [ ] [T010] Complete schema/provenance/test matrix and document any core color standard ambiguity before implementation approval. Refs: NET-001, NET-002, NET-003, NET-004, NET-005, NET-006, NET-007, NET-008, NET-009.