# Implementation plan — MikroTik

**Status:** Draft plan; no router connection or command will be enabled by this specification.

## Architecture and source boundaries
- Router inventory/config: `app/Models/RouterList.php`, related migrations and settings.
- API and SSH integration: `app/Services/MikrotikApiService.php`, `MikrotikSSHService.php`, `MikrotikSetupService.php`, `app/Http/Controllers/MikrotikController.php`.
- UI modules: `app/Livewire/Mikrotik/*`, `app/Livewire/MikrotikClients.php`, `app/Livewire/MikrotikSync.php`.
- Pending action safety: `app/Services/MikrotikPendingActionQueue.php`, `app/Jobs/ReconcileMikrotikPendingAction.php`, and relevant command/schedule.
- Device mutations must be isolated from read-only status/session reads.

## Design decisions and constraints
1. Verify RouterOS wrapper and current API/API-SSL transport support against locked dependencies and real mocks before design changes.
2. Centralize connection validation and safe error handling; never render credentials.
3. Define each command as read-only or mutation and enforce permission/target/confirmation at server boundary.
4. Bulk copy is destination-scoped and preserves source state; profile creation requires explicit mapping.
5. Live session counts include source timestamp and stale/error state; configured PPP secrets are not active sessions.

## Phases
- P1 Router inventory and credential flow audit.
- P2 Command allowlist and read/write boundary review.
- P3 Sessions and sync freshness/idempotency review.
- P4 Bulk-operation, audit, failure, and role tests.
- P5 Separate Staging device verification only if dedicated credentials and test hardware are explicitly configured.

## Acceptance evidence
Mocked contract tests cover normal responses, authentication failures, timeouts, malformed data, and retries. Staging evidence must identify the test router, credential scope, commands issued, and confirm no Production target access.