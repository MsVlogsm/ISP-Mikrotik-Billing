<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class NetworkInventoryDevice extends Model
{
    use HasFactory;

    protected $fillable = [
        'type', 'name', 'ip_address', 'latitude', 'longitude', 'health_port', 'monitor_enabled',
        'vendor', 'model', 'onu_total', 'onu_online', 'rx_power', 'customer_count', 'status', 'health_status', 'last_latency_ms',
        'last_checked_at', 'location', 'notes', 'olt_type_id', 'host', 'firmware', 'serial_no', 'web_protocol',
        'web_port', 'port', 'snmp_port', 'pon_ports', 'ge_ports', 'sfp_ports', 'sfp_plus_ports', 'connect_timeout', 'cli_timeout',
        'read_delay_ms', 'diagnostic_command', 'adapter_config', 'is_active', 'latitude', 'longitude',
        'username', 'password', 'snmp_community', 'api_token', 'api_endpoint', 'snmp_version',
        'ssh_port', 'telnet_port', 'api_port', 'ssh_enabled', 'telnet_enabled', 'api_enabled',
        'provisioning_enabled',
    ];

    protected $casts = [
        'monitor_enabled' => 'boolean', 'is_active' => 'boolean',
        'last_checked_at' => 'datetime',
        'ssh_enabled' => 'boolean', 'telnet_enabled' => 'boolean', 'api_enabled' => 'boolean',
        'provisioning_enabled' => 'boolean',
    ];

    private function encryptCredentialValue($value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        $value = (string) $value;
        try {
            Crypt::decryptString($value);
            return $value; // Already encrypted; do not encrypt twice.
        } catch (\Throwable) {
            return Crypt::encryptString($value);
        }
    }

    private function decryptCredentialValue($value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        try {
            return Crypt::decryptString((string) $value);
        } catch (\Throwable) {
            return (string) $value; // Backward-compatible for existing plaintext rows.
        }
    }

    public function setUsernameAttribute($value): void { $this->attributes['username'] = $this->encryptCredentialValue($value); }
    public function getUsernameAttribute($value): ?string { return $this->decryptCredentialValue($value); }
    public function setPasswordAttribute($value): void { $this->attributes['password'] = $this->encryptCredentialValue($value); }
    public function getPasswordAttribute($value): ?string { return $this->decryptCredentialValue($value); }
    public function setSnmpCommunityAttribute($value): void { $this->attributes['snmp_community'] = $this->encryptCredentialValue($value); }
    public function getSnmpCommunityAttribute($value): ?string { return $this->decryptCredentialValue($value); }
    public function setApiTokenAttribute($value): void { $this->attributes['api_token'] = $this->encryptCredentialValue($value); }
    public function getApiTokenAttribute($value): ?string { return $this->decryptCredentialValue($value); }

    public function scopeType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function onuMappings(): HasMany { return $this->hasMany(OltOnuCustomerMapping::class, 'olt_device_id'); }

    public function healthChecks(): HasMany
    {
        return $this->hasMany(NetworkInventoryHealthCheck::class);
    }

    public function monitoringSnapshots(): HasMany
    {
        return $this->hasMany(OltMonitoringSnapshot::class, 'olt_device_id');
    }
}
