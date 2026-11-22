# Implementation plan — Payment

**Status:** Draft; live financial transactions and provider-side changes are out of scope.

## Architecture and source boundaries
- Payment controllers: `app/Http/Controllers/Payment/BkashPaymentController.php`, `NagadPaymentController.php`, `SslCommerzPaymentController.php`, `WebhookPaymentController.php`, and Stripe-specific controllers.
- Shared domain/service/model paths include `app/Services/PaymentService.php`, `app/Models/CollectionSummary.php`, `PaymentSummary.php`, and `BillingInfo.php`.
- UI: `app/Livewire/PaymentCollection.php`, `CollectionEdit.php`, `Payment/Invoice.php`; configuration via site settings.
- Accounting determines posted state and signed returns; Billing consumes final payment outcome for service-state changes.

## Design decisions and constraints
1. Trace each provider's request/response, callback authentication, amount/currency/customer matching and state transitions separately.
2. Use one internal payment identity and idempotency boundary across callbacks and manual collection.
3. Do not mark paid or activate service on a request merely accepted/initiated by the provider.
4. Preserve original transactions and link return/refund/correction events.
5. Keep existing enabled gateways unless an approved change explicitly changes them; provider sandbox/mocks only.

## Phases
- P1 Provider-by-provider route/config/secret inventory.
- P2 Callback verification, matching, transactionality, duplicate/replay behavior.
- P3 Payment lifecycle, manual collection, return/refund and Accounting/Billing handoff.
- P4 Adapter contract tests, authorization tests and staging-only browser verification.
- P5 Reconciliation and security evidence; no live test payment.

## Acceptance evidence
Each provider has valid/invalid callback, timeout, duplicate, mismatch, and failed-status cases. Exactly one paid receipt and at most one activation result from repeated successful callback. Secrets are redacted from all output.