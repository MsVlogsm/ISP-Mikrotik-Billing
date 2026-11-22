# Tasks — SMS

**Traceability:** SMS-001…SMS-009 in `spec.md`.

## Phase 1 — Provider and data inventory
- [ ] [T001] Inventory configured SMS providers, endpoint contracts, active/default flags, credential storage and test-send route. Refs: SMS-001, SMS-008, SMS-009. Paths: `app/Livewire/SMSSetup.php`, `app/Http/Controllers/SMSController.php`, config and migrations.
- [ ] [T002] Map templates, dispatch logs, queue jobs, scheduled reminders and message status transitions. Refs: SMS-002…SMS-005. Paths: `app/Models/SmsTemplate.php`, `SmsDispatchLog.php`, `app/Jobs/SendBulkSmsJob.php`.
## Phase 2 — Delivery/inbound safety
- [ ] [T003] Verify secrets and endpoints are encrypted/masked and never leaked through logs, exceptions, exports or rendered settings. Refs: SMS-001, SMS-004.
- [ ] [T004] Verify idempotency key, retry classification/backoff, duplicate scheduler protection and delivery status retention. Refs: SMS-003…SMS-005, SMS-009.
- [ ] [T005] Inventory inbound MFS SMS route/parser; test sender allowlist and rejection of OTP/PIN/password/verification content. Refs: SMS-006, SMS-007.
- [ ] [T006] Verify inbound candidate cannot post or activate until Payment reconciliation validates transaction ID, amount, customer and duplicate status. Refs: SMS-007.
## Phase 3 — Tests and browser
- [ ] [T007] Map/add provider success/failure/timeout and page/authorization tests. Existing candidates: `tests/Feature/SmsServicePagesTest.php`, `CommunicationCenterTest.php`. Refs: SMS-001, SMS-002, SMS-004, SMS-008, SMS-009.
- [ ] [T008] Add parser redaction, OTP rejection, duplicate, schedule idempotency and reconciliation contract tests. Refs: SMS-003…SMS-007.
- [ ] [T009] Verify Admin/Manager provider settings and test-send authorization in Staging with mocked/test endpoint; check no request while disabled. Refs: SMS-001, SMS-008, SMS-009.
- [ ] [T010] Complete provider matrix, test-to-requirement mapping, secret redaction review and CI evidence. Refs: SMS-001, SMS-002, SMS-003, SMS-004, SMS-005, SMS-006, SMS-007, SMS-008, SMS-009.