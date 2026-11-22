# SMS module specification

**Status:** Draft. **Scope:** SMS provider configuration, templates, notifications, inbound MFS SMS parsing, due reminders, retries, delivery tracking, and audit.

## Requirements
- SMS-001: Provider credentials and endpoints MUST be stored securely and never exposed in logs, UI responses, exports, or source control.
- SMS-002: Outbound messages MUST use approved templates, recipient validation, customer locale/business timezone, and provider-specific adapters.
- SMS-003: Due reminders and scheduled notifications MUST be idempotent per customer, template, and billing event; duplicate scheduler runs must not spam customers.
- SMS-004: Delivery attempts MUST record provider, masked recipient, message/template reference, attempt count, status, provider response code, and timestamps without retaining unnecessary sensitive message content.
- SMS-005: Retry policy MUST distinguish transient provider failures from permanent validation/authentication failures and use bounded backoff.
- SMS-006: Inbound MFS SMS parsing MUST be restricted to configured senders and transaction patterns; OTP, PIN, password, and verification messages MUST be rejected and never interpreted as payments.
- SMS-007: Parsed inbound payment notifications are untrusted until reconciled against amount, transaction ID, sender/channel, customer reference, and duplicate history.
- SMS-008: Test messages require explicit authorized action and clearly labeled test recipient; provider credentials may be absent while the feature remains disabled.
- SMS-009: Provider change or outage must not silently discard queued messages; delivery status must be observable.

## Acceptance criteria
- Re-running a due-reminder schedule does not duplicate the same event message.
- Provider timeout retries only within configured bounds; invalid credentials fail without endless retry.
- OTP/PIN/password-like messages are rejected by inbound filters.
- An inbound MFS message alone does not post a payment until transaction reconciliation succeeds.
- Disabled/unconfigured provider produces an explicit safe status and no external request.
- UI and logs mask phone numbers and secrets according to policy.

## Required tests and evidence
Unit tests for parser patterns and sensitive-message rejection; contract tests for provider success/failure/timeout; feature tests for schedule idempotency, retries, permissions, and inbound reconciliation. Use mocked providers or test endpoints only.

## Dependencies and unresolved decisions
Depends on Billing, Payment, Customer, and Administration. Confirm active provider, endpoint contract, sender IDs, message templates, per-provider limits, retention policy, retry windows, and Bangladesh-specific sender/consent obligations from current configuration and approved policy.
