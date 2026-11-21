<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NetworkBridgeFdbEntry extends Model
{
    protected $table = 'network_bridge_fdb_entries';

    protected $fillable = [
        'snapshot_id',
        'mac_address',
        'vlan_id',
        'fdb_id',
        'bridge_port',
        'if_index',
        'interface_name',
        'entry_status',
        'status_code',
        'source',
    ];

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(NetworkBridgeFdbSnapshot::class, 'snapshot_id');
    }
}
