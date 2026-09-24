# P0 technical and workflow follow-up — 2026-09-24

> **Database-scope correction (2026-09-24):** The MySQL 8 run below was an exploratory check prompted by a previous request. It does not measure this project's acceptance baseline. PostgreSQL is the selected project engine under [ADR 0007](../adr/0007-postgresql-project-baseline.md). The temporary MySQL runner extension has been removed. Issues #62–#64 were closed as not planned; their migration compatibility work must not block P0 or release acceptance.

Status: substantial technical progress; **P0 remains open and the target workflow is proposed, not business-approved**. This report supplements the dated September 23 evidence without rewriting its results. No production database was queried, no operational stock/payment was posted, and no commit or push was made.

## Work completed and its limits

- Corrected reconciliation fixtures and exercised the full Recon suite, including independent worker sessions and persisted winner/loser allocations. Existing accounting validation was preserved.
- Reproduced cold and repeat Logistics installation successfully with the original 180-second command timeout. The historical timeout did not reproduce; its root cause is not established. No speculative optimization or timeout increase was applied.
- Temporarily extended the disposable runner to accept MySQL 8.0.46 for an exploratory check. That engine-specific runner path has since been removed; the project runner now targets PostgreSQL only.
- Ran one exploratory MySQL 8.0.46 bootstrap. It failed in an Accounts migration before test assertions. This is not a project baseline failure, support commitment, P0 defect, or release gate.
- Completed a bounded static [stock/payment writer and lock audit](STOCK-AND-PAYMENT-WRITER-AUDIT.md), including model hooks, API, Filament, Barcode, Manufacturing, Sales/Purchase, callbacks and ordinary versus bank reconciliation. Runtime races outside executed scenarios remain unproven.
- Began two dependency-ready owner-module repairs: existing stock-delta transaction protection and shared payment-registration atomicity. Neither activates Logistics execution or closes the larger Inventory/Accounts integrity phases.
- Designed the [stock-readiness target](plan/03-WORKFLOWS-AND-LIFECYCLES.md#stock-readiness-before-loading-proposed-target-for-review), with reviewable policy proposals and six small implementation issues. The workbook remains current-process evidence rather than the specification.
- Performed a [four-mode documentation-based tabletop](P0-TRANSPORT-MODE-TABLETOP-2026-09-24.md). It is not a facilitated operational walkthrough or approval.

## Verification record

Base revision: `c796209d281c87d9dcc46b5c3ea6a42ec662081d`, branch `production`, plus local changes. Preexisting dirty documentation and agent instructions were preserved. PHP 8.5.0; PostgreSQL 18.1; official MySQL Community 8.0.46 macOS ARM. Locked packages: Laravel 13.31.0, Filament 5.8.1, Pest 4.7.5 and PHPUnit 12.5.30; stale framework version guidance is not runtime evidence.

The default runner command is `rtk bash scripts/run_logistics_phase2a_tests.sh`; explicit file arguments replace its default selection. All targets below were fresh private disposable servers. No reused operational database was used.

| Selection | Result | What it proves |
| --- | --- | --- |
| Default 12-file selected baseline | 179 passed, 522 assertions, 142.62s (final-source rerun; earlier run 182.92s) | Existing selected Sales/Inventory/Accounts behavior and Sales confirmation worker case; not the entire repository. |
| Logistics FoundationTest + RemovalTest | 21 passed, 164 assertions, 54.98s | Actual cold/repeat installation, routes/permissions, non-posting and removal controls with unchanged timeout. |
| `tests/Feature/Recon/*Test.php` (21 files) | 154 passed, 802 assertions, 35.18s | Repaired fixtures and bank-matching worker evidence. A common start barrier does not itself demonstrate overlapping lock contention. |
| PaymentAtomicityTest + InvoiceTest | 38 passed, 114 assertions, 20.63s | Shared rollback, nested commit/rollback callbacks, connection rejection, reported notification failure and invoice regressions. |
| StockDeltaAtomicityTest + OneStepReceiptTest + OneStepDeliveryTest | 112 passed, 385 assertions, 57.08s | Existing-row lost-update repair with observed database lock wait and callback rollback, plus receipt/delivery regressions. |
| Exploratory MySQL bootstrap with TestBootstrapPluginPathTest | 1 failed, 0 assertions, 14.34s | Not project acceptance evidence. Other-engine compatibility is uncommitted and out of scope under ADR 0007. |
| Final combined installation/Recon/payment/stock run | 325 passed, 1,465 assertions, 268.19s | Integration and test-order verification after all local repairs. |

Final combined command (28 selected files):

```bash
rtk bash scripts/run_logistics_phase2a_tests.sh \
  plugins/shafique/logistics/tests/Feature/FoundationTest.php \
  plugins/shafique/logistics/tests/Feature/RemovalTest.php \
  tests/Feature/Recon/*Test.php \
  plugins/webkul/accounts/tests/Feature/Workflows/{PaymentAtomicityTest,InvoiceTest}.php \
  plugins/webkul/inventories/tests/Feature/Workflows/{StockDeltaAtomicityTest,OneStepReceiptTest,OneStepDeliveryTest}.php
```

Commands for the focused payment and stock rows use these paths beneath their respective plugin roots: `plugins/webkul/accounts/tests/Feature/Workflows/{PaymentAtomicityTest,InvoiceTest}.php` and `plugins/webkul/inventories/tests/Feature/Workflows/{StockDeltaAtomicityTest,OneStepReceiptTest,OneStepDeliveryTest}.php` (expand braces in a compatible shell). The runner enables the existing explicit concurrency flags. Direct Pest runs must still opt in deliberately.

The exploratory archive was downloaded from [MySQL Community downloads](https://dev.mysql.com/downloads/mysql/8.0.html) into `/tmp`, not installed system-wide. The bootstrap failed at `plugins/webkul/accounts/database/migrations/2026_09_07_000004_harden_reconciliation_schema.php:202` because that migration uses PostgreSQL `UPDATE ... FROM` syntax. No MySQL portability repair was implemented. The temporary archive and server were outside the repository; the private server was stopped and its data directory removed. These details document the unnecessary probe and are retained for historical accuracy only.

## Reproduced defects and changes

**Reconciliation fixtures (U14/#19):** old fixtures represented a bank receipt and its ledger receipt with opposite signs, conflicting with the current same-direction bank-matching rule. The concurrency setup lacked a Company. Once enabled, its committed fixture contaminated the shared seeded liquidity account. Corrected economic direction, initialized Company and used a dedicated liquidity account/journal. Tests assert two distinct database sessions, one accepted allocation, one clean rejection, one audit result, unchanged native accounting residuals and balanced GL. Receipt/payment sign tests explicitly retain opposite-direction rejection.

**Payment registration (#71, part of U15/#21):** injected late failure left payment, posted journal, reconciliation and residual changes without a shared transaction. Paid events fired before outer commit and survived rollback. The shared registrar now owns one Accounts connection transaction and rejects unsupported mixed-connection input. MovePaid runs after the outer commit; notification failure is reported without representing a committed payment as failed. This is not durable notification delivery, payment replay identity or proof against two payments consuming one residual.

**Stock deltas (#72, part of U3/#15):** a callback failure left quantity110 instead of100, and competing +10/+20 deltas against100 left110 instead of130. The existing balance's locked read and mutation now remain in one model-connection transaction, including synchronous callbacks. The test forces contention and observes the second worker's PostgreSQL lock wait. Missing-balance creation uniqueness, over-reservation, whole-transfer atomicity and purge/other-writer lock ordering remain outstanding.

**Audit correction:** the September23 report incorrectly described Invoice PayAction as lacking an outer transaction. It explicitly enables Filament transactions. Several Inventory/Manufacturing actions and Barcode validation also have outer transactions. Their existence does not supply shared service locks or replay identity. The newer writer audit records these distinctions and the pre-repair source snapshot.

**Combined-run fixture correction:** the first combined run had 324 passes and one failure (1,456 assertions). The committed concurrency account used an explicit `max(id) + 1`, leaving PostgreSQL's sequence behind. A later Accounts factory reached that ID. Native ID allocation fixes the cause; the ordered MatchConcurrency → PaymentAtomicity → Invoice regression passed 39 tests and 134 assertions in 21.74 seconds. This corrects fixture isolation rather than weakening a database constraint.

**MySQL migration analysis:** additional portability concerns were noted during the exploratory probe. They are not defects against the selected PostgreSQL deployment and are not current implementation tasks. Do not spend further engineering effort on them unless ADR 0007 is superseded with an explicit multi-engine support decision.

## Workflow and approval status

The proposed readiness result separates usable stock now, own reservations, forecast production, planned inbound supply and deadline shortfall. Forecast cannot create Inventory stock or release authority. Demand membership is explicit and reproducible from approved source rules or authorized adjustments, not spreadsheet position or Seq. Recovery preserves actual stock/custody and per-site release facts.

Five review areas are defined in the decision register: quantity/pack conversions, production ready-at, factory-to-Company mapping, demand eligibility, and approval responsibilities. The owner has been asked to approve the proposed direction and confirm Company relationships. **No response has been recorded in this run.** Do not mark Q decisions approved from this report. Accountable participant names and thresholds still require confirmation.

All four modes have normal and exception scenario coverage in the tabletop. Completed attributable operational records and participant sign-off remain absent; #56–#59 stay open. Additional evidence may arrive incrementally while unaffected technical work proceeds.

## Tracking and remaining gates

New readiness issues [#65–#70](https://github.com/SohaibRaza/aureuserp/issues/65) are explicitly proposed and dependency-gated; they include contracts, permission/validation boundaries, acceptance criteria and exclusions. Native fixes #71/#72 remain open for integration/review against the PostgreSQL baseline. Existing decision and mode issues are updated without claiming approval.

No phase labels were recreated. New issues carry `logistics`. The connector has no Project field mutation capability; the available browser is signed out and cannot open the private Project. Therefore new Phase-field assignments and auto-add enrollment are **not verified in this run**. Required assignment for active work: #65/#66/#68/#69=P3; #67/#70=P6; #71=P1B; #72=P1A. Do not substitute phase labels for this remaining metadata work.

P0 cannot exit until PostgreSQL baseline/control parity, relevant remaining writer/lock proofs, business-policy decisions and attributable four-mode operational validation are complete. MySQL is not an exit criterion. Selected passing tests and proposed workflows do not approve production rollout. All source changes remain local and uncommitted.

## Review and operational validation

Code review: harness-native fallback. A bounded independent reviewer inspected the technical diff after loading the review skill; the full multi-persona review pipeline was not executed. No actionable findings were reported. Separate reuse, quality and efficiency passes found no necessary simplification. The suggested `rescue()` replacement was left unapplied because the explicit exception handler makes this financial notification boundary clear. This is not a completed full `ce-code-review` receipt.

Pint, shell syntax, whitespace checks and the 13-document plan validator pass. The disposable PostgreSQL runner rejects parent traversal and verifies its database identity before running tests. Test evidence is limited to the selections above; it does not establish repository-wide or operational acceptance.

Before deployment, assign an Accounts owner and Inventory owner. In the first supervised test window, inspect payment/GL/residual consistency, notification exception reports, stock balances and database lock/deadlock errors after normal, failed and retried operations. Stop dependent Logistics execution if effects partially persist, quantities diverge or payment outcome is unknown; reconcile persisted state before retry. Notification transport failures require support follow-up and must not trigger another financial posting. These local changes have not been deployed, and no production monitoring evidence is claimed.

## Tested local source identity

The manifest below identifies the ten technical files against the base revision above, including new test files. It excludes preexisting unrelated documentation changes. SHA-256 of these sorted manifest lines, including their final newlines: `aa513a8fb67d8552d1800b8195c1092a8f5fd2e08ad11fe582ab0c088fe9c6cd`.

```text
1ea123dfbd793616104d5943051f27b2bc23e7a21ebbd548f61277e504e59ade  plugins/webkul/accounts/src/Services/PaymentRegistrar.php
5c0f70fdde6034c486c2523b8a323c997c8c393501e7d40105e3970eba2db244  plugins/webkul/accounts/tests/Feature/Workflows/PaymentAtomicityTest.php
4c9dc0a9be7e31d5e059471c029d5618f733b9f5b57a3c1ed078fca4cc0b62b3  plugins/webkul/inventories/src/Models/ProductQuantity.php
a8a2749021622801a776ec5221a606a49905b05c8fcc5999e55d23eca5f81f22  plugins/webkul/inventories/tests/Feature/Workflows/StockDeltaAtomicityTest.php
bc8c8326694a089094b1a79180b27840cdd2dfa52a6e1ac32832c3d634a1b73c  scripts/run_logistics_phase2a_tests.sh
3a3001a7e988b690f21bc76c05118ffb44a2f1838b4a108a7289ce53b4627ce1  tests/Feature/Recon/AccountantGoldenReconciliationTest.php
b25208fedfdb86efdbb49d35cd9f4cadcb29887824245a7bf333e31561a5045e  tests/Feature/Recon/AutoMatchAtomicityTest.php
e9e530f7cc49d1b1e1ca9ea06ce62354367afa313b0ae2d7d5a9a6d3fed0275d  tests/Feature/Recon/MatchAccountingIntegrationTest.php
d68e0f9d5c55aabc0a0a3df4dc1799efa7fd740cfb92746b25880ea9eaad21ca  tests/Feature/Recon/MatchConcurrencyTest.php
b4163c3f6f498d8f4873e87bfa8dd3b74a6ff6caaa9c82a2575b537d80317bff  tests/Feature/Recon/ReconTestSupport.php
```


## Continued Inventory and Accounts integrity work — 2026-09-24

This follow-up supersedes the outstanding-status statements for the bounded changes below, not the historical results or source manifest above. Work remains local on `production`; no commit, push, deployment or application-data migration was performed. All new database checks use private disposable PostgreSQL 18.1 clusters.

### Implemented boundaries and review findings

Inventory now has a six-column balance identity constraint, product-before-balance creation locking, locked reservation limits and transactional direct movement-line mutations. Transfer and lower-level movement completion/cancellation use shared transaction boundaries; completion covers source debit, destination credit, backorders and synchronous operation callbacks. Direct stale line edits fail before writing. Empty-balance cleanup is restricted to affected locked products.

Independent review identified a second lock-order risk when automatic restock reservation discovers a mixed-product delivery after receipt locks have already been obtained. A real two-worker regression creates that cycle. PostgreSQL aborts one command, the exception propagates through its outer transaction, and persisted stock/reservations remain coherent. This is failure atomicity, not deadlock prevention. A caller may retry only the entire business command with refreshed state after rollback. No partial downstream retry, global stock lock or new queue was introduced.

Accounts records a payment instruction fingerprint and canonical payment IDs in the same transaction as posting and reconciliation. It locks source accounting documents and term lines, then checks refreshed residuals. All four native pay APIs accept an optional `idempotency_key`; callers requiring lost-response replay must send the same key and intent. Omitting it remains a distinct command for backward compatibility. The shared Filament action generates a request key. Replays require payment-create authorization and active company access; the APIs retain their additional document-update permission checks. This does not introduce a new universal document-edit role requirement.

Review also found that direct request arguments needed binding to the named source document, and that multi-batch construction could exceed the requested payment amount. Shared validation rejects either discrepancy before posting. Partial multi-batch allocation and payments spanning inconsistent batch currencies fail before payment creation; no allocation policy is guessed. Existing payment registers migrate to `legacy_unverified`; they are not assumed unpaid and cannot be blindly retried. There is no new durable notification outbox.

### Migration and deployment conditions

- The Inventory constraint requires PostgreSQL 15 or newer and was tested on 18.1. Its transaction acquires a table lock and checks duplicates before adding `UNIQUE NULLS NOT DISTINCT`. Plan a maintenance window appropriate to table size; this is not an online, zero-blocking migration.
- Review a read-only grouping of `inventories_product_quantities` by company, product, location, lot, package and partner before deployment. Reconcile every duplicate with the Inventory owner; do not sum rows or discard history automatically. The migration refuses duplicates and changes no stock quantities.
- Product-level serialization currently includes all locations of the same product. Measure lock waits before replacing it with a more complex balance-identity lock strategy.
- Payment result columns preserve old rows as unverified history. Downgrade refuses to discard completed registration results. Use a forward correction once financial effects exist; do not erase replay evidence.
- Company/factory relationships, quantity units and approved stock-owner policy remain business gates. The migration does not approve unresolved Logistics policies.

### Engineering order and remaining scope

Dedicated hired vehicles and multi-stop delivery are the first implementation journeys after their owner-module prerequisites. Carrier consignments and distributor pickup follow. The previous all-four-mode live-pilot requirement has not been silently changed; implementation priority alone does not approve a narrower live launch.

P1A/P1B remain open. Physical-count absolute writes, scrap replay and identity, Manufacturing order-level atomicity, concurrent return bounds, enrolled release guards, canonical Inventory command responses, direct payment posting/reversal/reconciliation races, recipient controls and reliable notification delivery are not all established by this slice. Ordinary workflow regression passes do not prove these concurrent paths.


### Regression repairs found by the broader run

The initial 26-file Inventory/Manufacturing run produced **435 passes and seven failures (1,448 assertions)**. One stock-cleanup fixture inserted a second row with the same balance identity; it now invokes cleanup directly and proves that a zero balance assigned for counting is retained. Three Manufacturing consumption-diagnostic fixtures attempted picking against zero physical stock; they now seed enough component stock while retaining the original strict/flexible consumption assertions. Three genuine Manufacturing failures came from movement state propagation reusing cached lines already changed during completion. State propagation now queries current lines; the stale-edit guard remains intact.

Focused follow-up passed **20 tests/41 assertions** for the corrected Replication suite and **46 tests/122 assertions** across all seven Manufacturing workflow files plus StockIdentityTest. The independent review's downstream deadlock regression passed with TransferIntegrityTest: **20 tests/108 assertions**. Full Recon regression passed **154 tests/802 assertions**. The integration results below distinguish the broad run from its targeted corrective rerun.


### Final continued-work verification

| Selection | Observed result | Scope |
| --- | --- | --- |
| 32 Inventory, Manufacturing, Sales, Purchase and delivery API files | 509 passed, 2 failed; 1,669 assertions; 295.60s | Inventory/Manufacturing production repairs passed. Two Sales fixtures needed correction: duplicate stock setup and a global row-count assertion affected by committed concurrency data. |
| PaymentAtomicity, PaymentConcurrency, Invoice and PaymentRegisterBankAccount | 61 passed; 238 assertions; 42.32s | All four payment API routes, changed/revoked replay, amount/source validation, full same-currency multi-batch payments and three independent-process races. |
| VendorBill | 8 passed; 16 assertions; 8.39s | Existing vendor-bill workflow regression. |
| Sales concurrency → one-step order → order API, after fixture corrections | 42 passed; 125 assertions; 32.36s | Ordered verification of both failed Sales fixtures with the committed concurrency data present. |
| All 21 Recon files | 154 passed; 802 assertions; 30.89s | Bank reconciliation regression and opted-in workers. |

The Sales upselling test now adds two units to the existing ten-unit balance through Inventory, rather than creating a duplicate identity. The order API test counts rows belonging to its returned order, rather than counting the entire table after another test legitimately committed a fixture. Neither change relaxes a production validation. The targeted ordered rerun passed 42 tests and 125 assertions with the concurrency test that exposed the global count assumption. Its first attempt caught a fixture type mismatch between the base Product model and the Inventory Product model; the fixture now loads the required Inventory model. No production method signature was changed. The entire 32-file selection was not repeated after these test-only corrections.

Reproduction commands (the runner provisions and removes private PostgreSQL resources):

```bash
rtk bash scripts/run_logistics_phase2a_tests.sh \
  plugins/webkul/inventories/tests/Feature/Workflows/*Test.php \
  plugins/webkul/manufacturing/tests/Feature/Workflows/*Test.php \
  plugins/webkul/sales/tests/Feature/Workflows/OneStepSaleOrderTest.php \
  plugins/webkul/sales/tests/Feature/Workflows/OrderConfirmationIntegrityTest.php \
  plugins/webkul/sales/tests/Feature/Workflows/OrderConfirmationConcurrencyTest.php \
  plugins/webkul/sales/tests/Feature/API/V1/OrderTest.php \
  plugins/webkul/purchases/tests/Feature/Workflows/OrderBillingTest.php \
  plugins/webkul/inventories/tests/Feature/API/V1/DeliveryTest.php

rtk bash scripts/run_logistics_phase2a_tests.sh \
  plugins/webkul/accounts/tests/Feature/Workflows/{PaymentAtomicityTest,PaymentConcurrencyTest,InvoiceTest,PaymentRegisterBankAccountTest}.php
rtk bash scripts/run_logistics_phase2a_tests.sh \
  plugins/webkul/accounts/tests/Feature/Workflows/VendorBillTest.php
rtk bash scripts/run_logistics_phase2a_tests.sh tests/Feature/Recon/*Test.php

rtk bash scripts/run_logistics_phase2a_tests.sh \
  plugins/webkul/sales/tests/Feature/Workflows/OrderConfirmationConcurrencyTest.php \
  plugins/webkul/sales/tests/Feature/Workflows/OneStepSaleOrderTest.php \
  plugins/webkul/sales/tests/Feature/API/V1/OrderTest.php
```

Independent reviewers checked the original local fixes and the new stock/payment boundaries. Their actionable findings were addressed through scoped cleanup, failure-atomic deadlock evidence, source-bound payment requests and pre-write amount validation. Payment full-batch coverage additionally exposed and repaired native scalar-versus-collection handling and a payment-method object used instead of its foreign-key ID. Target-file Pint, shell syntax, whitespace checks and the 13-document plan validator pass. Browser interaction, live-data duplicate review and production rollout were not performed.


### Continued-work source identity

Base revision remains `c796209d281c87d9dcc46b5c3ea6a42ec662081d` plus the local files below. This manifest supersedes the earlier manifest for current code, without changing its historical evidence. It includes the preexisting reconciliation repairs exercised again in this follow-up. SHA-256 of sorted manifest lines including final newlines: `475e5542bc97eb63c7b97ea59892802c265eff2f3e9a708a00fc9d504c978bfa`.

```text
d5391804e5b5fc309ad806588230f7312e32de29b549514d579ba5e8730821ca  plugins/webkul/accounts/database/migrations/2026_09_24_200000_add_payment_registration_results.php
11ce4e1469a0da5b684f62fa43fd1b2a9c023cd692d9b0df809d6388716140b6  plugins/webkul/accounts/src/AccountServiceProvider.php
fa64b6493489c8ea6d93c81ca7ec546319af617a4c61c793ab6654973e536263  plugins/webkul/accounts/src/Filament/Resources/InvoiceResource/Actions/PayAction.php
fcdef89990b43c08aa48fcd3b76674a9177510aef4afa5127f157ee62c587702  plugins/webkul/accounts/src/Http/Controllers/API/V1/BillController.php
021c22647756fc4d574c87566226494961109f3e331facfb93707aa83569859d  plugins/webkul/accounts/src/Http/Controllers/API/V1/CreditNoteController.php
1c0a94a6560a65d5984e3adad9a511bc4ffdecce6c451cd415ef79d2529611e0  plugins/webkul/accounts/src/Http/Controllers/API/V1/InvoiceController.php
e9a1e6a87418bc9bcb1c8f3710612960afb7badd12ae44a479dcb0a49e7bb02f  plugins/webkul/accounts/src/Http/Controllers/API/V1/RefundController.php
2592f2946a84725a3ea58efcd80cc86a3b09af8869813d8ad2bd8062808be84c  plugins/webkul/accounts/src/Http/Requests/MovePaymentRequest.php
0900445f67ab0eb613cb9186b3699961a66fd20d1896fdbd24e43a2c41273bfa  plugins/webkul/accounts/src/Services/PaymentRegistrar.php
78074659a65b4b26d6c2f6a1ff04297d3e1ce2ff522a2d98a3fdf3c68b1eeb56  plugins/webkul/accounts/tests/Feature/Workflows/PaymentAtomicityTest.php
bd1f0bde9f1576135068f7a7b9db478ea0b5836381bb0ef56d6c3b7dce50a2cf  plugins/webkul/accounts/tests/Feature/Workflows/PaymentConcurrencyTest.php
e94eb508019e24607b4169f584245f96bc5d8d86773a8f3d3b8049208d7bfc95  plugins/webkul/inventories/database/migrations/2026_09_24_010000_enforce_product_quantity_identity.php
4bb08433dd4be130b0e0777f7e72f6af31e7cf31b72b218f15ad7717e18fd058  plugins/webkul/inventories/resources/lang/en/system.php
918e046a5a05d5d6094052861cd719b8fb56040ca405434f7187e8f3234e7cca  plugins/webkul/inventories/src/InventoryServiceProvider.php
82ce12e06e11ca4e1b6c701ea9c0b97d860334671744ad3ca7009e081915b64c  plugins/webkul/inventories/src/Models/Move.php
29a51b1e194570e5fb88b4d0081520310032c7ee31958a53fb7742e19130e6a4  plugins/webkul/inventories/src/Models/MoveLine.php
7d38ea17889835ef8f8a746b0f10e5e64bc1ae1d778a43bc54ba8153038e98e8  plugins/webkul/inventories/src/Models/ProductQuantity.php
9271b8c3e022e40dc7ac9089bf491f76dae0b333b7b829154b1849f604621c61  plugins/webkul/inventories/src/Services/MoveCanceller.php
3513b8c6b42537c50d95e4b60c03212a3d7c5ad54ee67d32566ec5c131af5d71  plugins/webkul/inventories/src/Services/MoveCompleter.php
78b3995412cb619cca78ce67ccbd2dda6a3b6e1146a110518788b4f232e54cd6  plugins/webkul/inventories/src/Services/MoveReserver.php
46551d55bfc5a02a2636de9420e4f0e4c47e2f956ebca8a922770ce2a0777edf  plugins/webkul/inventories/src/Services/TransferWorkflow.php
27817eb9de466669d986cc406c8778b2311f2b1f3dcbc0b30171fa3c635ef312  plugins/webkul/inventories/tests/Feature/Workflows/ReplicationTest.php
f97acb642f371445e70c1123344e0499ee4537029e83f385267d031ea86f3433  plugins/webkul/inventories/tests/Feature/Workflows/StockDeltaAtomicityTest.php
feb74307de4351d846051abbd5786e61c98a6d83f136003569d74942ae654a76  plugins/webkul/inventories/tests/Feature/Workflows/StockIdentityTest.php
c9be14c4f106b94e2d8eec3229a2d975dad6723d11e8135181858a0abd73d5f0  plugins/webkul/inventories/tests/Feature/Workflows/TransferIntegrityTest.php
f912d693300d704d98ef16a778ad40a6cedbf07a4972a1a17fb9d52373aca4f1  plugins/webkul/manufacturing/tests/Feature/Workflows/ManufacturingOrderTest.php
915178f371c7222a1821e98da351a467de13d9bce0115a3979282fa2dbb1a3dd  plugins/webkul/sales/tests/Feature/API/V1/OrderTest.php
314e35635bce348ceb2ea121e5cb98bce5f915a3c6f4f5b9fdd6d331628c95f1  plugins/webkul/sales/tests/Feature/Workflows/OneStepSaleOrderTest.php
bbfd47dbceb2f110a9675ed550b83192369e562c203c63aaca075b96278c4136  scripts/run_logistics_phase2a_tests.sh
3a3001a7e988b690f21bc76c05118ffb44a2f1838b4a108a7289ce53b4627ce1  tests/Feature/Recon/AccountantGoldenReconciliationTest.php
b25208fedfdb86efdbb49d35cd9f4cadcb29887824245a7bf333e31561a5045e  tests/Feature/Recon/AutoMatchAtomicityTest.php
e9e530f7cc49d1b1e1ca9ea06ce62354367afa313b0ae2d7d5a9a6d3fed0275d  tests/Feature/Recon/MatchAccountingIntegrationTest.php
8a765a366e54cf33747ace18eff433b29f9c30d2649411fc2de5e8dc054c6a3a  tests/Feature/Recon/MatchConcurrencyTest.php
b4163c3f6f498d8f4873e87bfa8dd3b74a6ff6caaa9c82a2575b537d80317bff  tests/Feature/Recon/ReconTestSupport.php
```

## Local commit record

The implementation and test changes described above were subsequently committed on the local `production` branch as `252a9f6` (project rules and PostgreSQL baseline), `03018aa` (reconciliation baseline), `1e6f524` (Inventory integrity), and `7a37ea6` (Accounts integrity). Earlier statements that the changes were uncommitted describe their state when the tests and reviews ran. These commits have not been pushed or deployed; the remaining approval and acceptance gates still apply.
