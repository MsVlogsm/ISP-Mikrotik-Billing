# OLT L2 Bridge / FDB

## Scope

The Bridge FDB page reads the latest saved forwarding-database snapshot for an OLT or managed L2 switch. It displays MAC address, VLAN (when Q-BRIDGE-MIB exposes the mapping), FDB ID, bridge port, IF-MIB interface/ifIndex, entry status, and an exact subscriber/ONU identity where an unambiguous stored mapping exists.

The collector only sends SNMP GET/WALK operations. It does not issue SNMP SET, SSH/Telnet commands, VLAN changes, MAC flushes, or bridge configuration changes.

## Supported standards

The first implementation uses standard RFC MIB objects:

| Purpose | MIB object | OID |
|---|---|---|
| VLAN-aware forwarding port | `dot1qTpFdbPort` | `.1.3.6.1.2.1.17.7.1.2.2.1.2` |
| VLAN-aware forwarding status | `dot1qTpFdbStatus` | `.1.3.6.1.2.1.17.7.1.2.2.1.3` |
| VLAN-to-FDB ID mapping | `dot1qVlanCurrentFdbId` | `.1.3.6.1.2.1.17.7.1.4.2.1.3` |
| Classic bridge forwarding port | `dot1dTpFdbPort` | `.1.3.6.1.2.1.17.4.3.1.2` |
| Classic bridge forwarding status | `dot1dTpFdbStatus` | `.1.3.6.1.2.1.17.4.3.1.3` |
| Bridge port to ifIndex | `dot1dBasePortIfIndex` | `.1.3.6.1.2.1.17.1.4.1.2` |
| Interface name | `ifName` | `.1.3.6.1.2.1.31.1.1.1.1` |
| Interface description | `ifDescr` | `.1.3.6.1.2.1.2.2.1.2` |

Q-BRIDGE-MIB is preferred. If its forwarding table is empty, the collector attempts the classic BRIDGE-MIB table. Fixed-length `MacAddress` indexes are decoded as six octets without a length sub-identifier, per SMIv2 RFC 2578 section 7.7. Q-BRIDGE indexes are the FDB ID followed by those six octets.

## Enablement and access

Live polling is disabled by default:

```dotenv
OLT_BRIDGE_FDB_POLL_ENABLED=false
```

Keep it disabled until a dedicated read-only SNMP community has been configured on a Staging OLT/switch and its MIB access verified. The device's encrypted `snmp_community` value is used, with an optional per-device `adapter_config.bridge_fdb.community` override. Do not reuse a write-enabled community.

After staging verification, a controlled deployment can set:

```dotenv
OLT_BRIDGE_FDB_POLL_ENABLED=true
```

The UI still requires an explicit read-only poll confirmation. The POST endpoint is throttled to three requests per minute. GET requests only display saved snapshots and never initiate polling.

SNMP v1 and v2c are supported by this collector. SNMPv3 currently returns an explicit unsupported-configuration error; it must not be treated as a successful empty FDB read. Vendor-private FDB/ONU port indexes are not guessed. Any vendor-specific support should be added only with verified MIB/OID documentation and tests for that firmware.

## Storage

Each manual poll creates an immutable point-in-time snapshot and its rows in the `network_bridge_fdb_snapshots` and `network_bridge_fdb_entries` tables. Snapshots older than the configured retention period (30 days by default) are removed during a subsequent successful or failed collection attempt for that device. A failed read is recorded as an `error` snapshot; it is not represented as an empty successful FDB.

Subscriber labels are joined only via exact, unique active client-MAC, ONU-MAC, or exact EPON interface-to-ONU mapping. Ambiguous matches remain explicitly unresolved.

## Staging checklist

1. Apply the new migration to the Staging database.
2. Verify the target OLT/switch exposes the relevant standard BRIDGE-MIB/Q-BRIDGE-MIB objects to the read-only SNMP community.
3. Verify VLAN-to-FDB mapping, bridge-port-to-ifIndex, ifName/ifDescr, and exact ONU interface identity against that hardware/firmware.
4. Enable polling only on Staging and confirm saved snapshots and error handling.
5. Review per-vendor support before enabling production polling.


### OID index compatibility

The collector accepts the standard fixed-size six-octet `MacAddress` index and a firmware variant that inserts an explicit length sub-identifier of `6` before the six octets. The latter form was verified on the production VSOL `V1600D4` read-only MIB walk. Any other length marker or index shape is rejected.

## P1 collection and diagnostics extensions

- Collection order: direct device SNMP GET/WALK, then an explicitly configured
  RouterOS SNMP proxy (bridge_fdb.proxy_router_id), then an explicitly enabled
  SSH account running a fixed vendor command allowlist.
- The RouterOS proxy uses /tool/snmp-walk with the target device IP, SNMP port,
  read-only community, version and OID. The proxy router must be selected by ID
  and connected with API access; it is never auto-selected.
- SSH fallback requires ssh_enabled=true and saved device credentials. It does
  not use default credentials, Telnet, enable mode, configuration mode or writes.
- MAC movement history compares the same learned MAC/VLAN between consecutive
  successful snapshots. Three or more observed interface moves in ten minutes
  are marked as flapping.
- SFP DDM OIDs are per-model / firmware / transceiver configuration only.
  Populate adapter_config.sfp_ddm_oids and, where required,
  adapter_config.sfp_ddm_ifindex_map from the exact vendor MIB.
  Unsupported or unconfigured values remain null and are not estimated.
- Universal Network Search reads customer/PPPoE/ONU records and, when
  submitted, performs read-only RouterOS active-session and ARP queries.

## P1 collection and diagnostics extensions

- Collection order: direct device SNMP GET/WALK, then an explicitly configured
  RouterOS SNMP proxy (bridge_fdb.proxy_router_id), then an explicitly enabled
  SSH account running a fixed vendor command allowlist.
- The RouterOS proxy uses /tool/snmp-walk with the target device IP, SNMP port,
  read-only community, version and OID. The proxy router must be selected by ID
  and connected with API access; it is never auto-selected.
- SSH fallback requires ssh_enabled=true and saved device credentials. It does
  not use default credentials, Telnet, enable mode, configuration mode or writes.
- MAC movement history compares the same learned MAC/VLAN between consecutive
  successful snapshots. Three or more observed interface moves in ten minutes
  are marked as flapping.
- SFP DDM OIDs are per-model / firmware / transceiver configuration only.
  Populate adapter_config.sfp_ddm_oids and, where required,
  adapter_config.sfp_ddm_ifindex_map from the exact vendor MIB.
  Unsupported or unconfigured values remain null and are not estimated.
- Universal Network Search reads customer/PPPoE/ONU records and, when
  submitted, performs read-only RouterOS active-session and ARP queries.
