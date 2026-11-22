# Implementation plan — Customer

**Status:** Draft plan; behavior changes require separately approved scope.

## Architecture and source boundaries
- UI/workflow: `app/Livewire/CustomerList.php`, `app/Livewire/NewCustomer.php`, `app/Livewire/EditCustomer.php`, `app/Livewire/CustomerSummary.php`.
- HTTP operations: `app/Http/Controllers/CustomersController.php`, `CustomerActionsController.php`, `CustomerDetailsController.php`, `CustomerBulkImportController.php`.
- Data entities include `CustomersInfo`, `BillingInfo`, `PPPSecrets`, and OLT mapping/link models. Verify actual relationships and field constraints in code/migrations.
- Customer-to-network identity belongs to verified MikroTik/OLT identity workflows; never infer a mapping from similar names or MAC fragments.

## Design decisions and constraints
1. Identify canonical customer ID and uniqueness constraints from migrations/model validation; distinguish optional data from required fields.
2. Customer lifecycle and network reachability are independent: a local status change must not falsely report a router mutation.
3. Display field provenance and freshness for IP/MAC/ONU optics; unknown must remain unknown.
4. Imports require preview, validation, duplicate handling, row-level results, and transaction-safe persistence.
5. Profile, list, search, bill history and exports must reference the same customer record and authorization scope.

## Phases
- P1 Identity/schema and CRUD validation audit.
- P2 Customer search, PPPoE/package links, paid-ledger and display-field provenance.
- P3 Disable/delete, router-offline behavior, bulk import/export integrity.
- P4 Role-scope tests, profile/list parity, Admin/Manager/Reseller browser evidence.

## Acceptance evidence
For each customer-facing field, document source model/column, relation, fallback, null semantics, and freshness. No field is declared implemented based on UI text alone.