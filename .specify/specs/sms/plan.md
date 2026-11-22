# Implementation plan — SMS

**Status:** Draft plan; provider activation, live SMS dispatch and credential updates are not authorized here.

## Architecture and source boundaries
- UI/config: `app/Livewire/SMSSetup.php`, `app/Http/Controllers/SMSController.php`, site settings.
- Message persistence: `app/Models/SmsTemplate.php`, `SmsDispatchLog.php`.
- Async dispatch: `app/Jobs/SendBulkSmsJob.php` and scheduled reminder/notification commands.
- Inbound MFS/payment notifications must hand off to Payment reconciliation; an SMS body is untrusted input and not itself proof of payment.
- Existing feature evidence includes `tests/Feature/SmsServicePagesTest.php` and `CommunicationCenterTest.php`.

## Design decisions and constraints
1. Discover actual provider adapter, endpoint, credential fields, sender ID, and enabled state from repository config rather than stale docs.
2. Outbound dispatch is asynchronous, bounded, idempotent per event/recipient/template, and records delivery outcome without exposing full sensitive content.
3. Retry transient errors with bounded backoff; do not endlessly retry permanent validation/auth errors.
4. Inbound parser rejects OTP/PIN/password/verification content and only extracts structured candidate transactions; Payment must verify all facts before posting.
5. Provider-disabled/unconfigured mode makes no external call.

## Phases
- P1 Provider/config/template/job inventory and secret storage review.
- P2 Delivery lifecycle, retries, rate limits, masking and scheduler idempotency.
- P3 Inbound SMS filtering/parser and payment reconciliation boundary.
- P4 Mocked provider/parser tests, Admin/Manager browser verification, audit and CI.

## Acceptance evidence
Repeated schedule/job execution sends no duplicate event message. OTP-like messages are rejected. Unconfigured provider causes no external request. Delivery logs mask recipient/secrets and expose bounded error/status only.