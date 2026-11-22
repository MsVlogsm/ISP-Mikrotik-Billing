# OLT Vendor / Model / Firmware Capability Matrix

The dashboard matrix describes the capability evidence available for one OLT
from its inventory record and latest stored read-only observations.

## Status semantics

- **Verified live** — the exact device/model/firmware profile is identified or
  a recent read-only poll returned the corresponding data.
- **Configured** — required per-device OID or transport settings exist, but
  the latest poll has not returned a usable measurement.
- **Family profile** — an X-Link adapter entry exists for the vendor family,
  but that does not certify every model or firmware in that family.
- **Model MIB required** — no verified profile exists for the exact model;
  unknown private OIDs are not attempted.
- **Not configured / Not verified / Disabled** — the corresponding capability
  has no active configuration, no successful observation, or is deliberately
  not part of the collector.

## Known exact live profile

The currently recorded production VSOL OLT is inventory model
V1601E04-DP, firmware V1.0.2R; its SNMP profile uses the
V-Solution V1600D4 live sysDescr identity / authInfoTable mapping.
The read-only Bridge FDB collector has returned non-empty dot1q snapshots.
The capability matrix uses the firmware/model combination, latest stored FDB
snapshot, latest standard IF-MIB snapshot, ONU optical sample count and SFP DDM
records to mark capabilities as verified.

## Other vendor families

The existing adapter catalogue contains family-level entries for BDCOM,
Huawei, C-Data, HSGQ, PHOTON, ZTE, FiberHome and Nokia. Those entries do not
constitute universal model/firmware certification. Exact vendor MIB support
must be tied to a precise model and firmware and validated with read-only walks.
Vendor/model names present in the supplied APK are discovery leads, not proof
that a specific private OID works on an X-Link device.

## DDM and chassis telemetry

SFP DDM OIDs must be explicitly configured under adapter_config.sfp_ddm_oids
and must match the installed transceiver and firmware. Missing values stay
null. A standard ENTITY-SENSOR / ENTITY-MIB walk returning no rows does not
prove that every private vendor DDM MIB is unsupported.

CPU, memory and chassis temperature OIDs likewise require an exact model MIB.
A vendor-family name alone is not sufficient to query guessed private OIDs.

## Transport safety

The matrix marks SSH CLI fallback as configured only when SSH is explicitly
enabled and credentials are present. The P1 CLI collector uses its fixed
read-only command allowlist; it does not use default passwords, Telnet,
enable, configuration mode or write commands. A RouterOS SNMP proxy is
reported as configured only when an explicit proxy router ID is saved.
