<?php

namespace App\Console\Commands;

use App\Services\DataIntegrityAuditor;
use Illuminate\Console\Command;

class AuditDataIntegrity extends Command
{
    protected $signature = 'app:audit-data-integrity';

    protected $description = 'Run read-only data integrity checks for customer, PPPoE, ONU mapping and queue state.';

    public function handle(DataIntegrityAuditor $auditor): int
    {
        $result = $auditor->run();
        $this->info($result['message']);
        $this->line(json_encode($result['metadata'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}