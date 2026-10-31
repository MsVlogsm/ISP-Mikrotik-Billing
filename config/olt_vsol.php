<?php

return [
    // CLI provisioning profiles remain separate from the read-only SNMP MIB profiles.
    'profiles' => [
        'v1600d_ep_series_v1_2' => [
            'label' => 'VSOL/UPLINK EP Series / V1600D CLI v1.2',
            'models' => ['V1600D'],
            'transport' => 'ssh',
            'detect' => ['/\\bV1600D\\b/i', '/V1600D[[:space:]_-]*(EPON|OLT)/i'],
            'commands' => [
                'authorize_mac' => ['configure terminal', 'interface epon {{pon}}', 'onu-auth mode mac', 'onu mac-auth add {{mac}}', 'exit', 'exit'],
                'remove_mac' => ['configure terminal', 'interface epon {{pon}}', 'onu mac-auth del {{mac}}', 'exit', 'exit'],
                'disable_onu' => ['configure terminal', 'interface epon {{pon}}', 'onu {{onu}} disable', 'exit', 'exit'],
                'enable_onu' => ['configure terminal', 'interface epon {{pon}}', 'onu {{onu}} enable', 'exit', 'exit'],
                'pppoe' => ['configure terminal', 'interface epon {{pon}}', 'onu {{onu}} pri wan_conn add route internet nat enable', 'onu {{onu}} pri wan_conn index 1 pppoe proxy disable user {{username}} pwd {{password}} server {{server}} mode auto', 'onu {{onu}} pri wan_conn commit', 'exit', 'exit'],
                'static_ip' => ['configure terminal', 'interface epon {{pon}}', 'onu {{onu}} pri wan_conn add route internet nat enable', 'onu {{onu}} pri wan_conn index 1 static ip {{ip}} mask {{netmask}} gw {{gateway}} dns master {{dns1}} slave {{dns2}}', 'onu {{onu}} pri wan_conn commit', 'exit', 'exit'],
                'save' => ['write'],
            ],
        ],
    ],

    // Read-only MIB profiles verified against live SNMP walks on BT-EPON-OLT
    // (inventory model V1601E04-DP, live sysDescr V1600D4, 2026-10-12/13).
    // No SET/write OIDs are configured here.
    'snmp_profiles' => [
        'v1600d4_auth_info_table' => [
            'label' => 'V-Solution V1600D4 authInfoTable identity (live verified on firmware V1.0.2R)',
            'models' => ['V1600D4'],
            'enterprise_oid' => '1.3.6.1.4.1.37950',
            'mib_table_oid' => '1.3.6.1.4.1.37950.1.1.5.12.1.12',
            'client_mac_discovery' => false,
            'system_oids' => [
                'sysDescr' => '1.3.6.1.2.1.1.1.0',
                'sysUpTime' => '1.3.6.1.2.1.1.3.0',
            ],
            'onu_oids' => [
                // Live V1.0.2R authInfoTable is the exact MAC↔ONU-ID source.
                // Columns: authInfoPonNo=.2, authInfoOnuNo=.3, LLID=.4,
                // lineStatus=.5, onuMacAddr=.6. The .9 table's .3 value is
                // LLID-like and must not be treated as the Web UI ONU ID.
                'pon_port' => '1.3.6.1.4.1.37950.1.1.5.12.1.12.1.2',
                'onu_id' => '1.3.6.1.4.1.37950.1.1.5.12.1.12.1.3',
                'llid' => '1.3.6.1.4.1.37950.1.1.5.12.1.12.1.4',
                'status' => '1.3.6.1.4.1.37950.1.1.5.12.1.12.1.5',
                'onu_mac' => '1.3.6.1.4.1.37950.1.1.5.12.1.12.1.6',
            ],
            // Some firmware does not enumerate the OPM walk; per-ONU GET uses PON.ONU-ID.
            'opm_oids' => [
                'tx_power' => '1.3.6.1.4.1.37950.1.1.5.12.2.1.8.1.6',
                'rx_power' => '1.3.6.1.4.1.37950.1.1.5.12.2.1.8.1.7',
            ],
        ],
        'v1601e_epon_authinfo2' => [
            'label' => 'V-Solution V1601E EPON authInfo2Table',
            'models' => ['V1601E04-DP', 'V1601E'],
            'enterprise_oid' => '1.3.6.1.4.1.37950',
            'mib_table_oid' => '1.3.6.1.4.1.37950.1.1.5.12.1.25',
            'system_oids' => [
                'sysDescr' => '1.3.6.1.2.1.1.1.0',
                'sysUpTime' => '1.3.6.1.2.1.1.3.0',
            ],
            'onu_oids' => [
                'pon_port' => '1.3.6.1.4.1.37950.1.1.5.12.1.25.1.1',
                'onu_id' => '1.3.6.1.4.1.37950.1.1.5.12.1.25.1.2',
                'llid' => '1.3.6.1.4.1.37950.1.1.5.12.1.25.1.3',
                'status' => '1.3.6.1.4.1.37950.1.1.5.12.1.25.1.4',
                'onu_mac' => '1.3.6.1.4.1.37950.1.1.5.12.1.25.1.5',
                'onu_type' => '1.3.6.1.4.1.37950.1.1.5.12.1.25.1.6',
                'onu_model' => '1.3.6.1.4.1.37950.1.1.5.12.1.25.1.13',
                'distance_m' => '1.3.6.1.4.1.37950.1.1.5.12.1.25.1.17',
            ],
            'opm_oids' => [
                'tx_power' => '1.3.6.1.4.1.37950.1.1.5.12.2.1.8.1.6',
                'rx_power' => '1.3.6.1.4.1.37950.1.1.5.12.2.1.8.1.7',
            ],
        ],
        'v1600d_epon_authinfo2' => [
            'label' => 'V-Solution V1600D EPON authInfo2Table (model-specific; not V1600D4)',
            'models' => ['V1600D'],
            'enterprise_oid' => '1.3.6.1.4.1.37950',
            'mib_table_oid' => '1.3.6.1.4.1.37950.1.1.5.12.1.25',
            'system_oids' => [
                'sysDescr' => '1.3.6.1.2.1.1.1.0',
                'sysUpTime' => '1.3.6.1.2.1.1.3.0',
            ],
            'onu_oids' => [
                'pon_port' => '1.3.6.1.4.1.37950.1.1.5.12.1.25.1.1',
                'onu_id' => '1.3.6.1.4.1.37950.1.1.5.12.1.25.1.2',
                'llid' => '1.3.6.1.4.1.37950.1.1.5.12.1.25.1.3',
                'status' => '1.3.6.1.4.1.37950.1.1.5.12.1.25.1.4',
                'onu_mac' => '1.3.6.1.4.1.37950.1.1.5.12.1.25.1.5',
                'onu_type' => '1.3.6.1.4.1.37950.1.1.5.12.1.25.1.6',
                'onu_model' => '1.3.6.1.4.1.37950.1.1.5.12.1.25.1.13',
                'distance_m' => '1.3.6.1.4.1.37950.1.1.5.12.1.25.1.17',
            ],
            'opm_oids' => [
                'tx_power' => '1.3.6.1.4.1.37950.1.1.5.12.2.1.8.1.6',
                'rx_power' => '1.3.6.1.4.1.37950.1.1.5.12.2.1.8.1.7',
            ],
        ],
    ],
];
