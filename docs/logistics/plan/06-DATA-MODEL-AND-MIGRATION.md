---
title: Logistics Data Model and Migration Plan
version: 1.1.0
date: 2026-09-20
---

# Logistics Data Model and Migration Plan

## Persistence families

These families define required persisted meaning. Physical tables may combine closely related records when constraints and lifecycle remain clear.

| ID | Family | Minimum persisted fields | Required constraints/indexes |
| --- | --- | --- | --- |
| DM1 | Trips/stops | company, mode, origin, ordered known endpoints, planned/actual revision, operational flags. | Company/state/date; no cross-company root. |
| DM2 | Demand allocations | source line/move, source revision, planned quantity, active claim quantity/state, successor lineage. | Source aggregate guarded under lock; active-claim indexes; a claim is the quantity-consuming state of the allocation. |
| DM3 | Commitment/load snapshots | product/UoM, commercial/native quantities, conversion, pack contents, lot, measures, provenance, revision. | Append-only source/revision identity. |
| DM4 | Booking agreement revisions and carrier consignments | Booking records preserve provider roles, contractual endpoints, terms, acceptance and cancellation. Separate consignment records preserve issuer-scoped identity, shipment portions assigned to the consignment, physical handovers into carrier custody with actor, time and evidence, the governing booking revision and optional known-trip links. | Stable booking identity; immutable prior revisions; consignment uniqueness is issuer and service scoped; assignment is not physical handover. |
| DM5 | Release batches/items | frozen load/native set, approvals, outcomes, authority, revocation/consumption. | One current authority per exact revision; unique native effect references. |
| DM6 | Command results | company/type/key, fingerprint, expected revision, actor context, result/uncertainty. | Unique company/type/key; root business locks still required. |
| DM7 | Approvals | scope, revision, approver, evidence, validity, invalidation/consumption. | At most one current authority for scoped action. |
| DM8 | Cargo cohorts/portions | source cohort, parent, effective quantity, custodian, segment, condition, retirement. | Nonnegative; parent/child conservation under cohort lock; active indexes. |
| DM9 | Receipt evidence | Reporting party, recording user, occurrence time and uncertainty, recording time, input intent, physical-event identity or cumulative-confirmation scope and cutoff, evidence state, conflict state and supersession link. | Request deduplication is separate from event matching; date and quantity alone are never unique identity. |
| DM10 | Effective outcome allocations | Dispatched cohort or portion, source observation or cumulative confirmation, exact quantity and unit, one approved outbound outcome, and correction or reversal link. Condition and unmatched overage are stored separately. | Outcomes are limited to accepted, returned and received without prior acceptance, approved final non-delivery resolution, or outstanding; their total is bounded by dispatched quantity under lock. |
| DM11 | Exception/correction cases | affected facts, quantity/amount, owner role/user, next action, age, dependencies, disposition. | Company/owner/state/age; inactive user cannot orphan ownership. |
| DM12 | Freight agreements/allocations | revisioned components, supplier/recipient/payer/cost bearer, basis, native record links, applied/reversed amount. | Company/currency/counterparty compatibility; charge conservation. |
| DM13 | Evidence | Private object key, hash, size, detected type, Company, provenance, retention rule, legal holds, immutable associations, lifecycle state and approved-disposal tombstone fields. | No silent loss or mutation of retained accepted bytes; disposal requires expired retention, no legal hold, authorized outcome and preserved tombstone; no destructive Chatter cascade. |
| DM14 | Operational controls | company status, effective cutoff, reason, actor and history. | One current company control; serialized change and authorization. |
| DM15 | Outbound shipment portions | Company, origin, destination, source allocations, commitment snapshot, recipient, planned transport mode, current load/release lineage and revision. | One source quantity cannot be active in overlapping shipment portions; a portion may link to a trip, consignment or pickup without requiring a synthetic trip. |

## Native-owner schema changes

- Sales stores immutable quantity/packing commitment snapshots at confirmation and approved confirmed-line revision.
- Inventory adds only the approved quant uniqueness/serialization support, enrolled execution hook data where necessary, owner command/result identity, cumulative return guards and onsite correction audit.
- Accounts adds only the approved advance/payment instruction, recipient authority, effect identity and uncertainty/reconciliation fields needed to protect native financial effects.
- Logistics stores transport coordination, evidence and cross-owner references. It does not copy native balances.

## Migration principles

1. Additive before constraining: deploy nullable/new structures and compatible code before enforcing new NOT NULL/unique/check constraints.
2. Preflight before uniqueness: report duplicate quant/business identities read-only and stop; never merge/delete historical stock automatically.
3. Explicit legacy state: classify missing provenance, conversion, evidence or source attribution as `legacy_unknown`, not a fabricated default.
4. Bounded backfill: use stable primary-key windows, resumable checkpoints and row counts/hashes; do not hold operational locks for a full historical table.
5. Dual-read only when necessary and time-bounded; name the retirement criterion. Avoid dual-write unless both writes share one transaction and invariant.
6. Forward correction over history rewrite: opening/adoption records reference existing native effects and physical position.
7. PostgreSQL is primary and MySQL is also supported under [ADR 0008](../../adr/0008-postgresql-primary-and-mysql-support.md). Prove identity, constraints and transaction behavior on both recorded engine versions. The preflight SQL below is PostgreSQL-specific; MySQL must use an equivalent read-only duplicate report before applying constraints to existing data.
8. Rollback preserves facts: code rollback may disable new commands, but migrations containing actual business history are not destructively rolled back.

## Required physical schema specification

The persistence families above define business meaning; they are not permission to invent tables while coding. Before authoring a migration for a unit, its owner must add a reviewed physical schema specification to that unit's implementation record. The specification must state:

- exact table and model names, owning module and business purpose;
- every column's database type, precision, nullability, default and whether it is mutable after use;
- foreign keys, deletion behavior and the treatment of parent or source removal;
- unique, check and lookup indexes, with verified PostgreSQL and MySQL behavior;
- the records and transitions that require transaction locks;
- legacy classification, backfill source, checkpoint and reconciliation totals;
- compatible application versions for expand, activate and contract steps; and
- rollback or forward-recovery behavior plus the condition for retiring compatibility code.

Schema review must link the applicable D, IC, DM, G, Q and AE identifiers. A migration cannot start while any required identity, precision, ownership, lifecycle or historical-data rule is unresolved.

## Migration sequence

### M1A. Nonproduction characterization

- Freeze a schema/source snapshot for relevant Sales, Inventory, Accounts, Support and Logistics tables.
- Run read-only duplicate/null/precision/source-orphan reports.
- Use representative, sanitized nonproduction data to design reports and classify possible legacy conditions during P0.

### M1B. Authorized opening inventory

- During P7, run approved read-only reports against the opening cohort to inventory in-flight shipments, issued moves, returns, unsettled freight, pending payments and evidence files.
- Record unsupported rows as owned migration cases. P0 does not authorize production access.

### M2A. Inventory owner integrity

- Add Inventory constraints/indexes only after Q3 identity decision and clean preflight. The local six-column balance-identity implementation uses PostgreSQL `UNIQUE NULLS NOT DISTINCT` and therefore requires PostgreSQL 15 or newer; verify the deployment version and duplicates before applying it. See the [integrity evidence and migration conditions](../P0-EXECUTION-REPORT-2026-09-24.md#continued-inventory-and-accounts-integrity-work--2026-09-24).
- MySQL uses a functional unique index containing a null flag and a coalesced value for each nullable identity component. This treats matching empty values as equal without confusing an empty value with a real zero ID. Its hidden virtual columns must not appear in normal model attributes. Duplicate identities stop migration; they are never merged automatically. On MySQL, Company and stock-owner foreign keys use RESTRICT deletion: archive referenced records instead of deleting them. A cascading SET NULL collision reproducibly crashed MySQL 8.0.46 during rollback; rejecting parent deletion preserves the stock identity and avoids that engine path. PostgreSQL retains its existing constrained SET NULL behavior. MySQL stock-identity rollback refuses to restore the unsafe cascading foreign keys; use forward recovery.
- Immutable history uses database triggers on both engines. MySQL history migrations refuse destructive rollback before any DDL; use reviewed forward recovery. Existing PostgreSQL Logistics installations receive missing history protections through the registered forward migration rather than relying on edited historical migrations. Partially failed MySQL installations require schema and migration-ledger inspection before recovery; do not insert migration records to conceal missing constraints.
- Deploy compatible owner services before activating constraints that old writers cannot satisfy.

#### Read-only target duplicate preflight

No deployment target exists in the [current development-only project](../../BRANCHING-AND-RELEASES.md#current-project-lifecycle-2026-10-05). The deployment-target preflight is deferred until one is established; it is not a current development blocker. Continue isolated migration and concurrency verification. Before migrating existing development data, run the same preflight against that identified development database and preserve its records.

For a future deployment, run the following through the approved connection to the intended deployment database. Record its environment and exact PostgreSQL version. A result from a disposable test database does not clear that future deployment gate. The transaction reads existing records and rolls back without applying migrations or changing balances.

```sql
BEGIN READ ONLY;
SET LOCAL statement_timeout = '30s';
SET LOCAL lock_timeout = '5s';

SELECT current_database() AS database_name,
       current_setting('server_version') AS postgres_version,
       current_setting('server_version_num')::integer AS postgres_version_num;

WITH duplicate_identities AS (
    SELECT company_id, product_id, location_id, lot_id, package_id, partner_id,
           COUNT(*) AS balance_rows
    FROM inventories_product_quantities
    GROUP BY company_id, product_id, location_id, lot_id, package_id, partner_id
    HAVING COUNT(*) > 1
)
SELECT COUNT(*) AS duplicate_identity_groups,
       COALESCE(SUM(balance_rows), 0) AS rows_in_duplicate_groups,
       COALESCE(SUM(balance_rows - 1), 0) AS excess_balance_rows
FROM duplicate_identities;

SELECT conname, pg_get_constraintdef(oid) AS definition, convalidated
FROM pg_constraint
WHERE conrelid = 'inventories_product_quantities'::regclass
  AND conname = 'inventories_product_quantities_identity_unique';

ROLLBACK;
```

The version must be at least PostgreSQL 15 and all duplicate counts must be zero. Query errors, timeouts or missing access leave the gate open. An existing constraint must match the approved six-field definition with `NULLS NOT DISTINCT`; its existence alone does not prove writer compatibility. If duplicates exist, stop migration activation and request an authorized detailed reconciliation report. This summary intentionally contains no customer, owner or balance identifiers. Even a clean report requires a controlled migration window because other writers can change the data before migration execution; the registered migration repeats the duplicate check while holding its table lock.

#### Inventory operation result specification — 2026-10-15

Inventory owns `inventories_operation_results` and `Webkul\Inventory\Models\OperationResult`. Each transfer operation has at most one recorded completion or cancellation result. A separate table prevents native return and backorder replication from copying a source operation's execution result. The registered additive migration is `2026_10_15_220000_create_operation_results_table`. The coordinator reviewed this schema during isolated implementation; application activation and final acceptance are still pending. It implements the operation-root portion of IC3 and DM6, supporting U4, AE4 and AE6-AE8. It does not establish a command receipt for raw movements without a transfer root or authorize a Logistics load.

| Column | PostgreSQL type and nullability | Meaning and constraints |
| --- | --- | --- |
| `id` | Generated `bigint`, not null | Primary key. |
| `operation_id` | `bigint`, not null | Unique foreign key to `inventories_operations.id`; deletion is restricted. |
| `company_id` | `bigint`, not null | Foreign key to `companies.id`; deletion is restricted. |
| `actor_id` | `bigint`, not null | Authenticated executor; foreign key to `users.id`; physical deletion is restricted. Use the native inactive or soft-deleted user lifecycle to retain historical identity. |
| `action` | `varchar(255)`, not null | Check constraint permits `complete` and `cancel`. |
| `version` | `smallint`, not null, default 1 | Positive result format version, enforced by a check constraint. |
| `intent` | `jsonb`, not null | Original command options and the saved operation, movement and line facts before execution. Native saved quantities and explicitly supplied Barcode counts remain distinct inputs. |
| `result` | `jsonb`, not null | Canonical operation outcome and closing time, executed movements and lines, unit conversion data, lot and package IDs, related backorders and the exact newly created backorder IDs. |
| `created_at` | Timestamp without time zone, precision 0, not null | Owner-supplied recording time, following the native Inventory timestamp convention. There is no `updated_at`. |

PostgreSQL triggers reject updates and deletions; model checks provide a normal application error for those attempts. The owner locks the affected products, operation and movements, checks current executor access, and either returns the matching saved result or performs the mutation. Saving the result and dispatching the terminal event share the stock transaction. Failure of either, or of an enclosing caller transaction, rolls back the whole command. The saved result must exist before a matching reentrant event-listener call is accepted. A conflicting retry never changes the original result.

There is no legacy backfill. An already terminal operation without a verified saved result requires review; code must not reconstruct execution proof from current rows. Add the table before activating compatible writers. Older writers cannot maintain or honor this result contract and must not run after activation. Preserve a populated result table during rollback and use forward correction; the reverse migration is suitable only for an isolated empty schema. Restrictive foreign keys prevent deletion of the native operation, Company or executor from silently removing the recorded result.

### M2B. Accounts owner integrity

- The native payment-registration result and replay fields can be added before Q5 because they do not define freight charges, advances or payment recipients. First review historical payment registers, rehearse the PostgreSQL migration and verify that the compatible Accounts service is deployed.
- Add advance, recipient and freight-settlement fields only after the applicable Q5 accounting and approval rules are agreed.
- Deploy compatible Accounts services before activating constraints that old writers cannot satisfy.

### M2C. Sales commitment history

- Add Sales snapshot storage during P3 after Q1 approval.
- Backfill only facts supported by preserved source evidence; otherwise record legacy meaning as unknown.

#### Sales quantity history specification — 2026-10-15

The Sales-owned table is `sales_quantity_commitments`, represented by `Webkul\Sale\Models\QuantityCommitment`. It records the accepted meaning of a confirmed source line. The registered additive migration is `2026_10_15_000100_create_sales_quantity_commitments_table`. The coordinator reviewed the schema below after isolated implementation; it has not been applied to application data. Review corrections and integrated acceptance remain required before activation. This specification implements IC1 and DM3 under the scoped Q1 approval and supports R11-R14 and AE3.

| Column | PostgreSQL type and nullability | Meaning and constraints |
| --- | --- | --- |
| `id` | Generated `bigint`, not null | Primary key. |
| `order_id` | `bigint`, not null | Foreign key to `sales_orders.id`; deletion is restricted. |
| `order_line_id` | `bigint`, not null | Foreign key to `sales_order_lines.id`; deletion is restricted. |
| `revision` | `integer`, not null | The owner assigns the next positive revision under source locks. Unique with `order_line_id`. |
| `company_id` | `bigint`, not null | Exact Company at acceptance. No cascading Company foreign key; the historical identifier is retained. The owner validates it against the source. |
| `created_by` | `bigint`, nullable | Recording actor ID. No user-deletion cascade. |
| `data` | `jsonb`, not null | Product identity and name; commercial and stock quantities; both unit IDs, names, categories, factors and rounding; conversion; packaging identity, count and units per pack; warehouse and route; customer, shipping party and saved address. |
| `created_at` | Timestamp with time zone, not null | Acceptance time supplied by the owner. There is no `updated_at`. |

No business field has a fabricated legacy default. Quantities must be finite and representable at the saved unit precision and the existing four-decimal source-column precision; an unsupported value is rejected rather than silently rounded into a different requirement. Packaging count is derived from stock quantity and the saved pack conversion. PostgreSQL triggers reject every update or deletion of a persisted revision. Source and revision uniqueness also supplies the line-history lookup index.

Confirmation and confirmed source changes lock the order and affected lines, then the product, unit rows in ID order and selected packaging. Snapshot insertion, derived source quantities and native procurement participate in the same connection transaction. A late failure rolls them all back. Independent Inventory commands can acquire product locks first, so a cross-owner deadlock may require a complete fresh retry; the design does not claim to eliminate those deadlocks.

Existing confirmed rows without a snapshot remain unverified. Ordinary quantity or header edits must not create initial history for those existing lines from current master data; they require a separate provenance-backed verification process. Newly added confirmed lines may establish explicit current facts. There is no historical backfill, dual write or compatibility history to retire. Expand by adding the table before enabling the new source writers; do not run older confirmed-line writers after activation because they cannot maintain revisions. A code rollback must disable affected confirmation and source-edit commands while preserving history. The migration's `down()` is for isolated empty test schemas; it is not an approved way to remove accepted business records. Recovery uses a corrected forward migration and reconciled source revisions.

### M3A. Control and evidence foundation schema

- Add command/approval, evidence and operational-control records during P2.
- Preserve current existing foundation rows after reconciliation; do not create a competing settings/control model.

#### Suspension cutoff implementation boundary — 2026-10-16

The approved Q9 rule requires a transaction boundary, not a preliminary check of the settings page. An affected command must lock its owning `companies` row before reading the current Logistics control, and retain that lock until its business changes commit. Suspending or resuming activity must take the same lock first. If an already authorized command commits first, suspension takes effect after that command; if suspension commits first, the command must reject without effects. A check followed by an unlocked write does not satisfy this requirement.

Extend `logistics_company_controls` with a nonnegative `bigint` column named `revision` for the control revision. Existing rows start at revision zero, which identifies the state at activation of this mechanism; it does not invent revisions for historical changes. Add a nullable `bigint` column named `revision` to `logistics_control_changes`; historical rows retain null, while each new transition records its positive revision, unique within its Company. Increment the revision once for each actual active-to-suspended or suspended-to-active transition. Repeating the current setting must not advance it. Future approvals must record this revision and compare it under the same Company lock when consumed. Both suspension and resumption invalidate earlier unused approvals; resumption cannot revive an old load verification or stock-release approval.

The current actor's active status, permissions and Company access must be checked within the protected command boundary. The owning command starts the transaction and calls `OperationalControl::authorizeNewActivity()` before locking its other business records. That method requires an existing transaction, locks the Company row, rejects suspended activity and returns the current control revision. It requires an active Company as well as current authentication and Company access, including when the actor can bypass ordinary Company scope. It does not grant permission to plan, verify or release goods. Each owning command must enforce its own operational action policy. Dispatch staff must not need permission to view or change module settings merely to pass this operational gate; reading or changing the configuration retains its separate settings permissions. The command retains the same connection and transaction through its writes and any approval consumption. It must not perform irreversible external effects before commit. Source revision checks and business validations remain the responsibility of the owning command service. No general callback or workflow framework is needed for this boundary.

Company-first ordering applies to Logistics-owned commands. A native Inventory precondition may run after Inventory has already locked Products and movements. U9 and U11 must qualify that entry path separately: retain the enclosing stock transaction, serialize the control check with suspension, and prove that competing cross-owner lock acquisition either succeeds or rolls the entire losing command back. Do not release stock locks, check suspension outside the transaction, or claim a universal deadlock-free lock order. Any required whole-command retry must use fresh state and the owner's saved-result lookup; a partial stock step must never be retried independently.

Incident and evidence capture, and explicitly authorized corrections for goods already issued, use their own permission and state checks. They must not be routed through the new-activity gate. This bounded cutoff mechanism can be implemented before evidence acceptance and approval storage; doing so does not complete U8 or satisfy U7, and does not authorize planning, loading or release consumers until their own dependencies pass. Apply the additive migration to existing development data only after a separate preflight; isolated PostgreSQL tests may install it directly.

### M3B. Planning and loading schema

- After P1A and P2 acceptance, add outbound shipment portions, trip/stop, allocation and booking/load revision records during P3.
- Do not scaffold policy-sensitive structures before their blocking Q decisions close.

### M4. Execution and reconciliation schema

- Add release batch/items, cargo cohorts/portions, observations/events/attestations, receipt/outcome allocations and correction cases.
- Backfill only stable references; keep unknown physical history explicit.

### M5A. Freight linkage schema

- Add freight agreement revisions and allocations to native Accounts identities.
- Do not import a synthetic payable balance. Validate native company/currency/supplier relationships.

### M5B. Mode and handover schema

- During P6, add only the endpoint, segment, collector, consignment and handover associations required by the approved rules for responsibilities in all four transport modes.
- Reuse DM1, DM4, DM8 and DM15 identities. Do not introduce a second cargo, trip or consignment balance for a transport mode.
- Activate each association through the same physical schema review, PostgreSQL checks and expand/verify/activate/contract checkpoint used by earlier phases.

### M6. Opening adoption

- P7 builds and rehearses the opening mapping from an authorized read-only cohort extract. It must not apply the production opening batch.
- At the P8 cutover, establish one Company/cohort cutoff and durable batch identity. Fence new Logistics release and payment commands for the cohort, record late physical activity through the controlled fallback, and capture native high-watermarks for the source records.
- Re-extract and reconcile changes since the P7 rehearsal, then adopt current in-transit, onsite-issued, received-unresolved and financially open obligations once. Unrelated native work may continue; any cohort mutation that can change the opening position remains fenced until adoption and reconciliation finish.
- Produce reconciliation totals by Company, source, state, rehearsal batch, final batch and cutoff before activation. Re-running the same final batch must create zero new mappings or business effects.

### Per-phase activation and cleanup

Each phase uses an explicit expand, verify, activate and contract checkpoint. Enforce that phase's additive constraints only after compatible application code, data preflight and PostgreSQL checks pass. Record the switch that disables old writers and the rollback or forward-recovery response before activation. Remove compatibility reads only after telemetry and row classification prove that no supported writer or record requires them. Retain audit and mapping data needed to explain historical results.

## Migration-to-phase map

| Migration work | Phase | Required evidence before activation |
| --- | --- | --- |
| M1A nonproduction characterization | P0 | Reproducible reports, sanitized fixtures and explained findings. |
| M2A Inventory integrity | P1A | Q3 approval, duplicate preflight, caller map and PostgreSQL concurrency proof. |
| M2B Accounts integrity | P1B | Native registration-result and replay fields require historical-register review, corrected reconciliation fixtures, compatible service code and PostgreSQL verification. Advance, recipient and freight-settlement changes also require Q5 approval and their applicable effect/reversal proof. |
| M3A controls and evidence | P2 | Q2/Q7/Q9 decisions, evidence failure tests and suspension race proof. |
| M2C plus M3B planning/loading | P3 | Q1/Q4/Q6 decisions, physical schema specification and source-lineage proof. |
| M4 execution/reconciliation | P4 | P3 acceptance, Q10 decision, Inventory transaction and correction proof. |
| M5A freight linkage | P5 | P4 and P1B acceptance, Q5/Q6 decisions and accounting reconciliation proof. |
| M5B mode and handover associations | P6 | Signed mode matrix, reviewed physical schema, PostgreSQL checks and forward-recovery checkpoint. |
| M1B plus M6 opening rehearsal | P7 | Authorized read-only cohort extract, repeatable dry-run mappings, restore test and provisional reconciliation totals. |
| M6 final opening adoption | P8 cutover | Approved cutoff and batch identity, fenced cohort commands, late-change delta, final reconciliation and zero-effect rerun. |

## Rollback and recovery

- Before live activation, database backup and protected evidence snapshot must share a recoverable point or documented reconciliation procedure.
- Migration steps document PostgreSQL transaction boundaries and postconditions. After any DDL failure, keep affected capabilities disabled, inspect actual schema and migration-ledger state, and resume only through verified forward steps. Do not assume steps outside the failed transaction were rolled back.
- A failed backfill resumes from a verified checkpoint and never assumes a row failed merely because the client lost its response.
- A restored older backup fences release/payment/external workers until post-backup outcomes are reconciled.

## Migration acceptance

- Preflight counts are explained and approved; no silent repair occurred.
- Repeated backfill/adoption produces the same mapping and no duplicate effects.
- Native and Logistics totals reconcile by company and source identity.
- PostgreSQL enforces the approved identity and aggregate behavior.
- Code rollback cannot erase evidence history or approved-disposal tombstones, physical history, or native financial and stock facts.


### Reservation snapshots and pre-issue recovery history

Each new load item stores a `native_line_snapshot` containing the original reservation-line ID, move and operation IDs, exact Company and product, unit, source and destination locations, lot identity, package identities, stock owner and reserved quantity. The physical count remains a separate stock quantity linked to the saved Sales commitment. The load content hash includes the saved native details as well as the counted item identities and quantities. Historical load hashes are not recalculated.

An Inventory reservation line is temporary and may be deleted during native cancellation or reservation release. Its original ID in Logistics history is therefore an audit reference, not a foreign key that prevents the Inventory owner from deleting an otherwise releasable reservation. The native movement and saved commercial commitment retain their durable references. Current-load and issued-batch guards still prevent unsafe native mutation.

Append recovery events for removing an unused selection, confirming complete unloading of an unissued load, and cancelling an unissued trip. Store Company, trip, affected allocation or load where relevant, actor, reason, prior and resulting trip revisions, and recording time. Database triggers prevent changing or deleting these events. Clearing the current load pointer never deletes the original load or verification record. Any accepted native-line snapshot, recovery event or deleted native reservation row requires forward recovery. Schema rollback must not discard the historical facts used by a saved load hash, invent replacement reservation rows or delete recovery history.

The additive migration leaves pre-existing load snapshots null rather than manufacturing historical facts. Those legacy references remain protected until explicitly reviewed. Apply the migration to existing development data only after checking the target, migration ledger, table and column collisions, and any historical load items. No stock balances, orders, payments, staff assignments or activity controls are changed by this migration.
