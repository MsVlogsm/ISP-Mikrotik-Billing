<?php

namespace Tests\Unit;

use App\Models\NetworkInventoryDevice;
use App\Services\NetworkInventoryHealthProbe;
use Tests\TestCase;

class NetworkInventoryHealthProbeTest extends TestCase
{
    public function test_port_161_never_uses_tcp_probe_when_snmp_is_not_configured(): void
    {
        $device=new NetworkInventoryDevice(['ip_address'=>'192.0.2.1','health_port'=>161,'snmp_version'=>'2C']);
        $result=(new NetworkInventoryHealthProbe())->check($device);
        $this->assertFalse($result['ok']);
        $this->assertSame('not_ready',$result['status']);
        $this->assertSame('snmp',$result['protocol']);
        $this->assertStringContainsString('TCP probing is not a valid SNMP check',$result['message']);
    }
}
