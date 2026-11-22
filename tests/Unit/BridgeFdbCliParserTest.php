<?php

namespace Tests\Unit;

use App\Services\Olt\BridgeFdbOidParser;
use App\Services\Olt\BridgeFdbSnmpService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class BridgeFdbCliParserTest extends TestCase
{
    public function test_it_extracts_dynamic_cli_mac_rows_and_vendor_interface_names(): void
    {
        $service = new BridgeFdbSnmpService(new BridgeFdbOidParser);
        $method = new ReflectionMethod($service, 'parseCliMacRows');

        $rows = $method->invoke($service, implode("\n", [
            '100 00:11:22:33:44:55 Dynamic EPON0/1:12',
            '200 aa:bb:cc:dd:ee:ff Static GE0/1',
            '300 0011.2233.4455 Dynamic GPON0/2:8',
            'invalid row without mac',
        ]));

        $this->assertCount(3, $rows);
        $this->assertSame('00:11:22:33:44:55', $rows[0]['mac_address']);
        $this->assertSame('EPON0/1:12', $rows[0]['interface_name']);
        $this->assertSame('learned', $rows[0]['entry_status']);
        $this->assertSame(100, $rows[0]['vlan_id']);
        $this->assertSame('other', $rows[1]['entry_status']);
        $this->assertSame('aa:bb:cc:dd:ee:ff', $rows[1]['mac_address']);
    }

    public function test_it_deduplicates_identical_cli_mac_vlan_and_interface_rows(): void
    {
        $service = new BridgeFdbSnmpService(new BridgeFdbOidParser);
        $method = new ReflectionMethod($service, 'parseCliMacRows');

        $rows = $method->invoke($service, implode("\n", [
            '100 00:11:22:33:44:55 Dynamic EPON0/1:12',
            '100 00:11:22:33:44:55 Dynamic EPON0/1:12',
        ]));

        $this->assertCount(1, $rows);
    }
}
