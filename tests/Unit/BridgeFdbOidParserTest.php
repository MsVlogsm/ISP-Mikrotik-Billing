<?php

namespace Tests\Unit;

use App\Services\Olt\BridgeFdbOidParser;
use PHPUnit\Framework\TestCase;

class BridgeFdbOidParserTest extends TestCase
{
    public function test_it_parses_classic_dot1d_bridge_fdb_indexes(): void
    {
        $parser = new BridgeFdbOidParser;

        $this->assertSame(
            ['mac' => '00:11:22:33:44:55', 'fdb_id' => null],
            $parser->parseIndex('0.17.34.51.68.85', 'dot1d')
        );
    }

    public function test_it_parses_vlan_aware_dot1q_fdb_indexes_with_fdb_id_and_fixed_length_mac(): void
    {
        $parser = new BridgeFdbOidParser;

        $this->assertSame(
            ['mac' => '00:11:22:33:44:55', 'fdb_id' => 100],
            $parser->parseIndex('100.0.17.34.51.68.85', 'dot1q')
        );
    }

    public function test_it_rejects_malformed_or_unsupported_fdb_indexes(): void
    {
        $parser = new BridgeFdbOidParser;

        $this->assertNull($parser->parseIndex('6.0.17.34.51.68.85', 'dot1d'));
        $this->assertNull($parser->parseIndex('100.5.0.17.34.51.68.85', 'dot1q'));
        $this->assertNull($parser->parseIndex('100.0.17.34.51.68.999', 'dot1q'));
        $this->assertNull($parser->parseIndex('100.0.17.34.51.68.85', 'unknown'));
    }

    public function test_it_rejects_zero_and_broadcast_mac_addresses(): void
    {
        $parser = new BridgeFdbOidParser;

        $this->assertNull($parser->parseIndex('0.0.0.0.0.0', 'dot1d'));
        $this->assertNull($parser->parseIndex('255.255.255.255.255.255', 'dot1d'));
    }

    public function test_it_normalizes_mac_representations_and_fdb_status_codes(): void
    {
        $parser = new BridgeFdbOidParser;

        $this->assertSame('aa:bb:cc:dd:ee:ff', $parser->normalizeMac('AABB.CCDD.EEFF'));
        $this->assertSame('aa:bb:cc:dd:ee:ff', $parser->normalizeMac('AA-BB-CC-DD-EE-FF'));
        $this->assertSame('learned', $parser->statusName(3));
        $this->assertSame('self', $parser->statusName(4));
        $this->assertSame('management', $parser->statusName(5));
        $this->assertSame('unknown', $parser->statusName(null));
    }
}
