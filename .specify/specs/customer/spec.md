# Customer module specification

**Status:** Draft. **Scope:** subscriber identity, customer lifecycle, profile, PPPoE association, package, status, list/search, imports/exports, and customer-linked service information.

## Requirements
- CUS-001: Customer unique ID is unique and follows the explicitly configured creation policy; manual entry and generated entry must not conflict.
- CUS-002: Customer creation/edit validation must require only fields mandated by the business rules; optional identity/contact fields must not become accidental hard requirements.
- CUS-003: Customer search should select/link existing records rather than create duplicates when an existing customer is found.
- CUS-004: Customer list/profile must show the authoritative package, PPPoE username, router, IP/MAC where verified, ONU optical power only when supported, and clear N/A/unknown states when data is absent.
- CUS-005: Customer profile must expose month-by-month bill and paid-ledger history with traceable transaction references.
- CUS-006: Disabled/deleted customer actions must have documented lifecycle semantics and must not require a router to be online merely to update local customer state.
- CUS-007: Exports/import templates must define stable columns, validation, encoding, and disabled-router handling; failed rows must be reported without partial silent corruption.
- CUS-008: Customer-to-PPPoE and customer-to-ONU identity links require exact unique evidence. Stale/ambiguous mappings must be flagged and never silently reassigned.
- CUS-009: Customer status, payment status, and live session status are separate facts and must be represented separately.

## Acceptance criteria
- Duplicate unique IDs and duplicate PPPoE associations are rejected or resolved through a documented workflow.
- Optional national ID/phone fields follow documented validation and can be absent where business rules allow.
- Profile, list, exports, paid ledger, and search resolve the same customer identity.
- A disabled router does not prevent permitted local disable/delete operations; no unintended router command is sent.
- Unknown package, IP, MAC, or optical telemetry displays as unknown/N/A, not a fabricated value.
- Import preview reports valid/invalid rows and does not silently overwrite existing customers.

## Required tests and evidence
Feature tests for create/update validation, duplicate detection, authorization, customer lifecycle, search, export/import, identity mapping, and profile data. Browser tests for Admin/Manager and Reseller tenant boundaries. Trace every displayed field to its source model/query and freshness semantics.

## Dependencies and unresolved decisions
Depends on Billing, MikroTik, OLT/ONU, Payment, and Reseller. Confirm the exact required customer fields, deletion/disable semantics, package source of truth, and export rules against current production-backed behavior before changing them.
