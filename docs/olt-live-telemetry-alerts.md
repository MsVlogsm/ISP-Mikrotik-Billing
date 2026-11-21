# OLT Live Telemetry Alerts

The OLT live-metrics collector remains the source of truth for read-only measurements. This alert layer consumes saved snapshots; it does not issue additional SNMP, SSH, API, or configuration requests.

## Default thresholds

- CPU: warning at 85%, critical at 95%.
- Memory: warning at 90%, critical at 97%.
- ONU average RX: warning at or below -20 dBm, critical at or below -27 dBm.
- PON down: critical only when the sampled PON has ONU records.
- Uplink down: warning when another sampled uplink remains up; critical when every sampled uplink is down.
- Chassis temperature: disabled unless exact-device, model-specific thresholds are configured in `adapter_config.alert_thresholds.temperature_c.warning` and `critical`.
- SFP DDM: temperature and TX-power alarms are disabled unless a saved numeric sample has an explicit `ddm_source` and exact-device transceiver thresholds are configured under `adapter_config.alert_thresholds.sfp.temperature_c` or `adapter_config.alert_thresholds.sfp.tx_power_dbm`.

Thresholds are evaluated only when a numeric measurement exists. Missing or stale values are not interpreted as healthy or as zero.

## Incident lifecycle

Events are saved to the existing `network_events` table and displayed in Events & Alerts. An active event is updated by fingerprint and its occurrence count increases; a resolved event starts a new incident if the condition returns. When a later successful sample confirms recovery, the active event is marked resolved. Acknowledged events remain acknowledged while the underlying condition persists.

Poll failures create a deduplicated warning. The message records only the exception class, not its potentially sensitive text. A subsequent successful read-only sample resolves the poll-failure event.

## Safety

The alert service reads persisted snapshots and never contacts devices. Existing private-MIB gating, explicit DDM configuration, read-only adapter checks, and vendor-specific model/firmware restrictions remain unchanged. The existing network-events schema is reused; no new migration is required.
