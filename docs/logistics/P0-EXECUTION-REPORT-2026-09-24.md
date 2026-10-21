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

P0 cannot exit until PostgreSQL baseline/control parity, relevant remaining writer/lock proofs, business-policy decisions and attributable four-mode operational validation are complete. MySQL is not an exit criterion. Selected passing tests and proposed workflows do not approve production rollout. The listed baseline commits are local and unpushed; subsequent repairs remain uncommitted and undeployed.

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

P1A/P1B remain open. Scrap replay and identity, Manufacturing order-level atomicity, concurrent return bounds, enrolled release guards, canonical Inventory command responses, direct payment posting/reversal/reconciliation races, recipient controls and reliable notification delivery are not all established by this slice. Ordinary workflow regression passes do not prove these concurrent paths.


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

## Deployment gate review and subsequent local repairs

On 2026-09-24, the user stated that they represent both the Inventory and Sales owners and approved the exact six-field stock-balance identity recorded in [Q3](plan/12-OPEN-DECISIONS.md#q3-approved-stock-balance-identity). This resolves the business identity decision only. A read-only duplicate check on the intended PostgreSQL database, compatible-writer review and migration rehearsal remain required before applying the registered Inventory migration. The configured local database is not identified as that target, so no target duplicate result is claimed here.

A review of checked-in GitHub workflows found that pushing `production` starts CI but does not itself contain a target deployment or migration step. The user confirmed there is no external automatic deployment. The Inventory migration is registered and will run if a later deployment invokes package migrations or ERP installation. No push or target migration was performed in this review.

Local follow-up changes translate payment errors, distinguish policy-independent Accounts payment replay migration from Q5 freight policy, and clarify that dedicated vehicles and multi-stop delivery are implemented first within P6 without closing the four-mode pilot gate. Separate Inventory and Accounts integrity changes address physical-count writes, scrap validation, Manufacturing completion, return quantity limits and payment reversal. Focused disposable PostgreSQL 18.1 results are recorded with their respective changes; they do not substitute for the outstanding target duplicate check or release acceptance.

The first mixed Inventory, Manufacturing and Accounts run exposed a test-bootstrap ordering defect: existing Accounts tables caused the helper to mark the plugin installed without running its settings and permission installer. The helper now runs a candidate plugin installer when its installation marker is absent. After that test-only repair, a fresh PostgreSQL 18.1 run of the bootstrap, count, scrap, transfer, replication, Manufacturing, credit-note, payment-atomicity and payment-concurrency files passed **115 tests and 479 assertions**. The independent two-session return and payment-versus-reversal tests observed PostgreSQL lock waiting and verified the losing operation had no partial effect. The earlier mixed-run failure is retained here as diagnostic evidence; it is not a pass.

### 2026-09-28 physical-count concurrency addendum

The physical-count writer is now also covered by a real two-session PostgreSQL 18.1 test. The first session records and applies a count while retaining the product and balance locks; the second session has already read the old balance and is observed waiting on PostgreSQL. When the first session commits, the second session is rejected because its count is stale. The final balance is 8, its count marker is cleared and exactly one adjustment move exists. The focused `QuantityTest.php` selection passed **13 tests and 55 assertions** in 8.39 seconds on a fresh private database. A fresh combined bootstrap, Inventory, Manufacturing and Accounts selection then passed **116 tests and 488 assertions** in 62.71 seconds. A subsequent review corrected the API duplicate check to use all six approved stock-identity fields, corrected Scrap decimal comparison at product precision, and made the test bootstrap reject failed plugin installers. The subsequent run that included the new installer-failure test did not produce an observed final test summary; its earlier pass statement is withdrawn. The 2026-10-04 addendum below records the reproduced fixture failure, its repair and completed verification. This establishes the stale-count concurrency boundary only; it does not clear the target duplicate preflight, migration rehearsal or the remaining P1A/P1B gates.

### 2026-10-04 Scrap integrity and verified combined baseline

An unfiltered bootstrap run reproduced a fixture failure in `TestBootstrapPluginPathTest.php`. The test changed the Accounts installation marker within its ordinary transaction, then invoked the helper on its independent committed connection. That connection could not see the uncommitted marker and waited when updating the same row. PostgreSQL showed the blocking session through `pg_blocking_pids`. Cancelling only the blocked disposable-cluster query produced the observed result: **one failed test and two passed tests**, with 15 assertions. The fixture now updates the marker on the committed connection and restores the marker, static plugin cache and Artisan binding in `finally`. This prevents both the lock wait and contamination of later tests. A command wrapper's exit status without a final Pest summary is not acceptance evidence; [test guidance](../../tests/README.md) now makes that requirement explicit.

Two new Scrap tests failed before their repair. An instance with a cached old unit deducted 3 base units when the current scrap required 6. An instance whose product had changed still completed the scrap. The shared model now clears cached relationships after reloading the locked record and rejects a changed product before any stock mutation, preserving the product-before-record lock order. The focused bootstrap and Scrap selection then passed **13 tests and 54 assertions** in 7.98 seconds.

The existing `StockDeltaAtomicityTest.php` two-process test now also covers concurrent validation of the same Scrap and two separate scraps competing for insufficient shared stock. Both scenarios observed PostgreSQL lock contention. Each started with 100 units and a requested deduction of 60 units per worker. Exactly one validation succeeded; the final source balance was 40, the scrap-location balance was 60 and exactly one scrap movement existed. The competing second scrap remained draft. These tests establish these specific concurrency boundaries, not all draft-edit, deletion or cancellation races.

The final unfiltered run used a fresh task-owned PostgreSQL **18.1 (Homebrew)** cluster, PHP **8.5.0** and database `aureuserp_20261003222037_86956_testing`. It completed with **151 passed tests, 638 assertions and no skipped tests**, in **161.74 seconds**. The selected files were:

- Support `TestBootstrapPluginPathTest.php`.
- Inventory API `QuantityTest.php`.
- Inventory workflows `QuantityTest.php`, `ScrapTest.php`, `StockDeltaAtomicityTest.php`, `StockIdentityTest.php`, `TransferIntegrityTest.php` and `ReplicationTest.php`.
- Manufacturing `ManufacturingOrderTest.php`.
- Accounts `CreditNoteTest.php`, `PaymentAtomicityTest.php` and `PaymentConcurrencyTest.php`.

Run these explicit paths through `rtk proxy bash scripts/run_logistics_phase2a_tests.sh`; record the final summary. The selection includes database rejection of duplicate nullable stock identities and migration refusal over injected duplicates without merging balances. The read-only SQL in [M2A](plan/06-DATA-MODEL-AND-MIGRATION.md#read-only-target-duplicate-preflight) also executed successfully against this disposable database: all three duplicate counts were zero and the installed constraint matched the approved six-field definition. This proves the query's execution on the local test version; it is not a target deployment preflight or target migration rehearsal.

Only local database connections were found in the configured environment files. The deployment database has not been identified, so its version, duplicate status and migration readiness remain unverified. No target migrations, commits or pushes occurred. P1A/P1B remain open for the remaining caller boundaries, native reversal/reconciliation paths, enrolled release safeguards and policy-dependent behavior. Dedicated-vehicle and multi-stop execution still require their documented Inventory, control and business-policy dependencies.

### 2026-10-05 project lifecycle correction

The user confirmed: “There is no deployment set up yet. The project is fully in development, no production.” Earlier requests to identify a deployment database treated a future release check as an immediate next step. That assumption was incorrect. The deployment-target duplicate preflight and deployment rehearsal are deferred until a target is established, rather than failed or blocking current development. The `production` branch name refers to the custom development code line; it does not establish a deployed environment. See [the current project lifecycle](../BRANCHING-AND-RELEASES.md#current-project-lifecycle-2026-10-05).

Continue the remaining Inventory and Accounts integrity work and dependency-ready Logistics implementation with isolated PostgreSQL verification. Preserve development records and preflight the actual database before applying migrations to existing data. Business-policy approvals, technical dependencies and future all-four-mode live-pilot acceptance still apply to their respective behavior. The 2026-10-04 executed test evidence remains valid; it was development verification and did not imply a deployment.

### 2026-10-05 continued integrity implementation and review fixes

This continuation implements bounded portions of roadmap U2, U4, U5 and U15. It does not complete those units or their phase acceptance gates. The base revision is `8ce68a6a1c27468e0a0ce04d16734aeae3f7c9d5` on the local `production` development branch, together with the pending local changes. Existing edits and review proposals were preserved. No commit, push, deployment or migration against application records was performed.

**Inventory and Manufacturing behavior.** The Scrap model now serializes ordinary and quiet model saves and deletions with validation, using product locks before the persisted Scrap lock. A completed Scrap cannot be changed or deleted through these methods. Draft edits and validation cannot both succeed on stale data. The regression selection includes both orders of edit-versus-validation and deletion-versus-validation contention, as well as the earlier shared-stock validation races. Eight Scrap regressions and four contention regressions failed before these new guards; the worker's focused verification then passed 31 tests and 275 assertions.

Return limits now compare the actual completed quantity, requested quantity and previous non-cancelled return demands in the product's stock unit. The source movement's quantity is converted before the comparison. End-to-end tests create and complete deliveries using Units and Dozens in both conversion directions, then verify full returns, cumulative partial returns, excessive requests with no saved effects, and restored stock balances. These tests also exposed a second defect: Eloquent replication retained the source movement's loaded unit relationship after the return's `uom_id` changed, so saving converted an already-converted quantity again. The return movement now clears replicated relationships before saving, allowing defaults to resolve its current foreign keys. Inventory return validation and Manufacturing completion errors use their owning plugins' translation files. A Manufacturing regression verifies a runtime translation override and restores it afterward.

**Accounts behavior.** `MoveWorkflow::post()` now validates the persisted draft state after locking the entry and its reversal source in a consistent order. Posting, recomputation, line-state updates, partner-rank updates and synchronous database listener writes commit or roll back together. Intentional unsaved input, including the checked flag and invoice date, is retained, while entry identity changes are rejected. Failed posting restores the caller's attributes so the same instance can be retried. Five regressions failed before this repair. The worker's focused posting and payment-concurrency selection then passed 19 tests and 111 assertions, including actual two-session PostgreSQL contention that permits only one posting effect. This change preserves the pending payment-registration and reversal fixes; it does not make external callback side effects transactional.

**Dependency-ready Logistics behavior.** Control-change history rejects dirty saves and deletion even when Eloquent events are suppressed. The review found that replacing event guards with save and delete guards had left instance arithmetic updates unprotected. Both shared counter methods now reject ordinary and quiet increment, decrement and `Each` variants before changing the model or database. Unsaved-instance counter calls are also rejected because Laravel otherwise falls back to a broader query update. New-record creation and unchanged saves remain supported. The tests compare all stored attributes and cover both company and actor columns. Raw SQL and query-builder mutations remain outside these instance guards; database-level append-only protection is not established by this work.

**Review and failing proof.** The completed `ce-code-review` receipt is run `20261005-034411-b6893a8c`, with artifacts at `/private/tmp/compound-engineering-501/ce-code-review/20261005-034411-b6893a8c`. Nine reviewer lenses and a separate finding-validation pass reviewed the cumulative tracked diff. The available external review CLI was unauthenticated, so cross-model review did not run; a separate local adversarial context completed instead. All three actionable findings were applied in file batches: #1 corrected mixed-unit return limits, #2 translated Inventory and Manufacturing errors, and #3 protected audit-history counter methods. There are no unapplied actionable findings from that receipt. Its original “Not ready” verdict describes the pre-fix tree; it is not a new approval of the post-fix code.

Before applying the return and counter fixes, the three-file regression run completed with **21 failed tests, 49 passed tests and 323 assertions**, in **88.84 seconds**, on private database `aureuserp_20261004231014_72646_testing`. Five failures exposed return-unit handling, including the replicated relationship defect, and 16 exposed history counter writes. The earlier 318-test pass describes the pre-review-fix selection and is superseded for current verification by the completed run below.

**Final combined verification.** A fresh task-owned PostgreSQL **18.1 (Homebrew)** cluster, PHP **8.5.0** and database `aureuserp_20261004231501_79274_testing` completed **360 passed tests, 1,552 assertions and no skipped tests**, in **206.59 seconds**. The runner verified the isolated socket, database and storage identities and cleaned up its own cluster afterward. The actual command was:

```bash
rtk proxy bash scripts/run_logistics_phase2a_tests.sh \
  plugins/webkul/support/tests/Feature/Workflows/TestBootstrapPluginPathTest.php \
  plugins/webkul/inventories/tests/Feature/API/V1/QuantityTest.php \
  plugins/webkul/inventories/tests/Feature/API/V1/DeliveryTest.php \
  plugins/webkul/inventories/tests/Feature/Workflows/QuantityTest.php \
  plugins/webkul/inventories/tests/Feature/Workflows/ScrapTest.php \
  plugins/webkul/inventories/tests/Feature/Workflows/StockDeltaAtomicityTest.php \
  plugins/webkul/inventories/tests/Feature/Workflows/StockIdentityTest.php \
  plugins/webkul/inventories/tests/Feature/Workflows/TransferIntegrityTest.php \
  plugins/webkul/inventories/tests/Feature/Workflows/ReplicationTest.php \
  plugins/webkul/manufacturing/tests/Feature/Workflows/ManufacturingOrderTest.php \
  plugins/webkul/accounts/tests/Feature/Workflows/CreditNoteTest.php \
  plugins/webkul/accounts/tests/Feature/Workflows/PaymentAtomicityTest.php \
  plugins/webkul/accounts/tests/Feature/Workflows/PaymentConcurrencyTest.php \
  plugins/webkul/accounts/tests/Feature/Workflows/MoveLifecycleTest.php \
  plugins/webkul/accounts/tests/Feature/Workflows/InvoiceTest.php \
  plugins/webkul/accounts/tests/Feature/Workflows/VendorBillTest.php \
  plugins/webkul/accounts/tests/Feature/Workflows/JournalEntryTest.php \
  plugins/webkul/accounts/tests/Feature/Workflows/RefundTest.php \
  plugins/webkul/accounts/tests/Feature/Workflows/CurrencyTest.php \
  plugins/webkul/accounts/tests/Feature/Workflows/CashRoundingTest.php \
  plugins/shafique/logistics/tests/Feature/FoundationTest.php \
  plugins/shafique/logistics/tests/Feature/RemovalTest.php \
  plugins/webkul/sales/tests/Feature/Workflows/OneStepSaleOrderTest.php \
  plugins/webkul/purchases/tests/Feature/Workflows/OrderBillingTest.php \
  tests/Feature/Recon/MatchAccountingIntegrationTest.php
```

Task-owned Pint, `git diff --check` and the 13-document plan validator passed. No browser run, GitHub Actions run, operational transport walkthrough or full release acceptance is claimed.

**Remaining implementation work.** Native Accounts reconciliation and removal of reconciliation links, cancellation and reset-to-draft still need common transaction boundaries, compatible locking and competing-operation tests. Manufacturing needs direct late-failure rollback coverage after stock and work-order changes. Additional coverage remains for Scrap stock-owner and package combinations and Quantity API company-scope conflicts. Historical mixed-unit return records need a read-only assessment before any correction; this repair does not rewrite completed movements or silently adjust balances. Enrolled Logistics execution safeguards, correction workflows, command replay protection and policy-dependent behavior remain open under their owning roadmap units.

Dedicated hired vehicles and multi-stop delivery remain the first transport journeys to build once their Inventory, Accounts, controls and business-policy dependencies pass. No trip execution feature was added in this continuation. The all-four-mode first live-pilot acceptance requirement remains in place. Future deployment preflight remains deferred until a deployment target exists, as explained in the project lifecycle correction above.

### 2026-10-05 Accounts reconciliation and correction continuation

This continuation addresses the ordinary Accounts paths that remained open in the preceding addendum. It does not declare P1B complete. The project remains in development, with no configured deployment or production environment.

**Business behavior and implementation boundaries.** Matching an invoice or bill to a payment changes the amount still due. Matching the payment's bank entry to a bank statement is a separate operation. Under [ADR 0003](../adr/0003-allocation-matching-without-invented-accounting-reconciliation.md), removing the invoice's payment match or resetting that invoice must not undo a completed bank reconciliation, rewrite its accepted evidence, or change the payment's bank entry. Resetting the bank-linked payment itself must be rejected until its bank match is removed through the bank reconciliation workflow.

`Reconciler::reconcile`, `reconcileGroups` and `unReconcile` now own their database transaction. They reload the selected records, lock the connected journal entries in a consistent order, and then lock their lines and reconciliation records. Eligibility and the amount still due are checked from those locked records. Reconciliation links, exchange adjustments, amounts still due, parent document totals and payment flags are updated together. A failure in a synchronous database callback rolls back the entire operation. If a connected entry appears after locks have been taken and cannot be added in the established order, the operation fails with a refresh-and-retry message rather than continuing with incomplete locks.

Locking related entries does not authorize changing every line in those entries. Removing an invoice match changes only the affected debit and credit lines and the lines whose full-match membership is being cleared. Unrelated bank lines and bank reconciliation evidence remain unchanged. Closed-period and bank-link checks apply to the records actually being changed. The bank-link guard checks active proposed and confirmed bank allocations as well as native statement references; a partial bank match does not necessarily populate a native statement field.

`MoveWorkflow` posting, cancellation, reset and reversal use the same locking boundary and persisted state. Cancellation accepts a draft journal entry only. Reset accepts a posted or cancelled entry, subject to the existing accounting safeguards. A posted entry is corrected through the supported reset or reversal workflow rather than deleted. Exchange-adjustment entries cannot be reset directly; the user must remove their related payment match first. Expected business conflicts are translated validation errors; unexpected database or callback failures propagate and roll back.

Payment confirmation, cancellation and reset now pass through `PaymentRegistrar`. The service checks company access and the current payment-to-journal link, locks the journal entries before the payment record, and repeats the link check after waiting. A stale link or state cannot be used to delete history or overwrite a later action. Cancelling a draft payment retains its linked journal entry and its history. Resetting a payment and its journal entry is atomic, including synchronous callbacks. Payment registration continues to use its existing create permission; these correction rules do not require an unrelated update permission from a user who is registering a new payment.

The invoice, bill, credit-note and refund screens authorize correction actions on the server. The payment-summary component reloads the displayed document and selected payment, checks company and edit access, and rejects removal of a match belonging to another document. The actions are labelled **Match payment** and **Remove payment match**. An expected conflict explains the problem and stops the action without reporting success. An unexpected failure is allowed to roll back and reach normal error handling; raw database errors are not shown as payment instructions.

**Executed evidence and its limits.** New regressions reproduced stale amounts still due, acceptance of draft reconciliation lines, partial writes after a late callback failure, stale cancellation, and loss of a cancelled payment's journal history before their respective repairs. An intermediate eight-file integration run failed six tests because payment-summary fixtures used global reconciliation counts and selected unrelated committed concurrency fixtures. Those tests now select their own document's debit and credit endpoints and verify that an earlier unrelated match remains unchanged; no accounting guard or assertion was removed to obtain a pass.

Real two-session PostgreSQL tests observed lock waiting for native reconciliation conflicts and payment confirmation competing with a stale cancellation. The losing action left no partial financial change. Real bank-match fixtures exercise full, partial and proposed allocations, bank completion, removal of invoice matches, reallocation, invoice reset, and removal of bank matches through their owning workflow. These establish the exercised scenarios only. They do not prove concurrent bank completion against every native correction path.

The first 27-file verification used PostgreSQL **18.1 (Homebrew)** and PHP **8.5.0** in private database `aureuserp_20261005091042_58468_testing`. It finished with **385 passed tests, one failed test and 1,508 assertions**, in **160.77 seconds**. The failure was `CompanyCurrencyGuardTest.php`'s empty-company case: it selected the default company even though earlier concurrency fixtures had legitimately committed journal entries for that company. The observer correctly prevented changing that company's currency. This is a failed verification run, not a pass, and the fix must isolate the empty-company fixture without weakening the observer.

The actual `ce-simplify-code` pass removed an unnecessary action reconstruction and an empty reconciliation-lock lookup. Its 35 focused tests passed with 188 assertions on private PostgreSQL 18.1. The subsequent read-only `ce-code-review` completed with receipt `20261005-141057-40879236`, stored at `/tmp/compound-engineering-501/ce-code-review/20261005-141057-40879236/review.json`. It identified three actionable findings: per-line relationship queries while reconciliation locks are held, expected posting errors that bypass the payment action's warning handler, and a reconciliation warning outside the Accounts translation catalog. The reviewer roster covered correctness, standards, testing, maintainability, security, performance, reliability and adversarial analysis. The adversarial route used the local fallback because no authenticated different-provider route was available; this is not independent cross-provider verification. The review itself ran no tests. Fixes and their executed verification must be recorded separately from this completed review receipt.

Findings #1 and #3 were applied together in the reconciliation owner. Each plan now reloads its selected lines and relationships in a batch while preserving selected-line order. Reloading remains inside the plan loop so overlapping plans see the preceding plan's writes. The warning uses `accounts::system.reconciliation.already-reconciled`: “Some of these entries are already matched. Refresh the page and try again.” Added tests cover overlapping plans, rollback of the first plan when the second fails, and the introduced relationship-read cost. The final query regression rejects draft entries after the reload and before matching, so it measures this reload rather than later financial computations: account relationship reads remain **two for both two-line and five-line plans**. This is a bounded reload result, not a claim that the entire reconciliation operation has a constant query count.

The query regression initially failed as intended, with **13 passed tests, one failed test and 81 assertions** on PostgreSQL 18.1; account reads rose from 11 to 29. The first measurement after the repair still counted later matching work and failed with **13 passed tests, one failed test and 81 assertions**; those reads were 8 and 20. The next measurement still included other pre-match work, with reads of 4 and 7. That run additionally failed the existing foreign-currency test with an account-eligibility error: **12 passed tests, two failed tests and 72 assertions**. The currency failure's cause is unproven. Without changing the batch relationship implementation, the final focused selection passed twice on separate PostgreSQL 18.1 databases, with **14 tests and 85 assertions** each (`aureuserp_20261005095823_2695_testing` and `aureuserp_20261005095948_4810_testing`). The intermittent currency failure remains explicit rather than being labelled harmless or resolved by a single pass. The combined selection below must also verify the currency paths.

Finding #2 was applied in the posting owner rather than by catching every exception in the payment action. Deliberate posting rejections now use field-specific `ValidationException` errors. The currency check runs before code reads its rounding value, so an unavailable currency produces a warning instead of a null dereference. Unexpected exceptions still propagate. The existing bank warning now says: “This bank account is archived. Choose an active bank account before confirming.” Real retained-journal and generated-journal action tests first failed with the ordinary exception: **13 passed tests, two failed tests and 89 assertions**. After the repair, `PaymentResourceTest.php`, `MoveLifecycleTest.php` and `JournalEntryTest.php` passed **32 tests and 164 assertions** on private PostgreSQL 18.1 with PHP 8.5.0. These tests assert unchanged payment, journal, line and match records after rejection and no newly generated journal surviving rollback.

All three actionable findings from the completed review were applied in two file groups. A separate fixture-only repair gives the company-currency empty-company test a new company without changing the production observer. The bank-method requirements described below and additional invoice-owned unmatch warning coverage remain explicit follow-up work; they are not silently treated as tested or approved. The final formatting pass covered 27 task-owned PHP files and passed. No commit, push, deployment, application-database migration or browser verification was performed.

**Final combined verification.** The exact 27-file selection below passed **391 tests and 1,546 assertions, with no skipped tests**, in **158.30 seconds**. It ran on a new task-owned PostgreSQL **18.1 (Homebrew)** cluster with PHP **8.5.0**, database `aureuserp_20261005100554_14326_testing`. The observed complete output is `/private/tmp/aureuserp-accounts-integrated-final-20261005-492b.log`; the runner exited successfully and cleaned up its private cluster. The selection includes the independent-worker payment and reconciliation tests, company guards, all four document variants, native action and API callers, currency workflows and the two relevant bank reconciliation integration files. The repaired empty-company case and existing foreign-currency case passed in this run. This does not establish the cause of the earlier intermittent currency failure or certify the unexercised concurrency matrix. Pint, `git diff --check` and the 13-document plan validator passed. There are no unapplied actionable findings from receipt `20261005-141057-40879236`.

Reproduce the combined selection with explicit paths:

```bash
rtk proxy bash scripts/run_logistics_phase2a_tests.sh \
  plugins/webkul/accounts/tests/Feature/Workflows/InvoiceTest.php \
  plugins/webkul/accounts/tests/Feature/Workflows/VendorBillTest.php \
  plugins/webkul/accounts/tests/Feature/Workflows/CreditNoteTest.php \
  plugins/webkul/accounts/tests/Feature/Workflows/RefundTest.php \
  plugins/webkul/accounts/tests/Feature/Workflows/CurrencyTest.php \
  plugins/webkul/accounts/tests/Feature/Workflows/JournalEntryTest.php \
  plugins/webkul/accounts/tests/Feature/Workflows/MoveLifecycleTest.php \
  plugins/webkul/accounts/tests/Feature/Workflows/PaymentAtomicityTest.php \
  plugins/webkul/accounts/tests/Feature/Workflows/PaymentConcurrencyTest.php \
  plugins/webkul/accounts/tests/Feature/Workflows/PaymentCorrectionTest.php \
  plugins/webkul/accounts/tests/Feature/Workflows/ReconciliationIntegrityTest.php \
  plugins/webkul/accounts/tests/Feature/Workflows/CompanyCurrencyGuardTest.php \
  plugins/webkul/accounts/tests/Feature/Workflows/CompanyIsolationTest.php \
  plugins/webkul/accounts/tests/Feature/Workflows/CompanyScopingInvariantsTest.php \
  plugins/webkul/accounts/tests/Feature/Workflows/DocumentCompanyResolutionTest.php \
  plugins/webkul/accounts/tests/Feature/Filament/InvoiceResourceTest.php \
  plugins/webkul/accounts/tests/Feature/Filament/InvoiceSummaryTest.php \
  plugins/webkul/accounts/tests/Feature/Filament/PaymentResourceTest.php \
  plugins/webkul/accounts/tests/Feature/Filament/BillResourceTest.php \
  plugins/webkul/accounts/tests/Feature/Filament/RefundResourceTest.php \
  plugins/webkul/accounts/tests/Feature/Filament/CreditNoteResourceTest.php \
  plugins/webkul/accounts/tests/Feature/API/V1/InvoiceTest.php \
  plugins/webkul/accounts/tests/Feature/API/V1/BillTest.php \
  plugins/webkul/accounts/tests/Feature/API/V1/CreditNoteTest.php \
  plugins/webkul/accounts/tests/Feature/API/V1/RefundTest.php \
  tests/Feature/Recon/MatchAccountingIntegrationTest.php \
  tests/Feature/Recon/ReconciliationControlsTest.php
```

**Remaining Accounts requirements.** `Payment::getMethodCodesNeedingBankAccount()` currently returns an empty list. Consequently, the native method hook does not require a recipient bank account for any payment method. An archived account already linked to a journal entry is a reachable posting rejection, but that is not proof that missing or unvalidated recipient accounts are rejected for bank payments. Accounts owners must define which supported methods require a recipient account and its validation, and the implementation must check the persisted account and method rather than trust caller-supplied flags. Missing, unvalidated, validated and account-not-required cases, including stale relationships, need regression coverage after that rule is implemented. Freight-specific recipient and payer treatment remains subject to Q5.

**Work still required before transport execution.** Direct payment journal generation, ordinary edit and delete paths, adjustment posting and reversal, bank completion against competing native corrections, and durable notification delivery still need their bounded caller and concurrency checks. Inventory release prerequisites, enrolled Logistics controls, stock corrections and replay-safe execution commands remain separate dependencies. Quantity and packaging rules, factory relationships, planning eligibility, approval responsibilities, and loading and transport responsibilities must be approved before implementing policy-dependent dedicated-vehicle and multi-stop execution. The spreadsheet informs these decisions but does not define the software workflow. No deployment database is required to continue this development work.

### 2026-10-15 Product settings repair and approved operating rules

The user reported a web startup failure at Inventory packaging discovery: Spatie could not load `ProductSettings` because `enable_price_lists` was absent. Inspection confirmed that the property and its registered, guarded settings migration already existed. Reading `enable_packagings` loads all declared settings, so packaging discovery exposed the missing price-list value rather than a packaging calculation defect.

A read-only transaction against the configured development PostgreSQL **18.1 (Homebrew)** database `aureuserp` confirmed that both the value and migration ledger entry were absent. The four existing Product flags were present. The code now gives only the new `enable_price_lists` property a default of `false`, keeping startup compatible while the migration is pending. It does not write that default during a web request or conceal other missing required settings. Existing persisted values take precedence. The migration is still required for normal settings persistence.

`ProductSettingsMigrationTest.php` first reproduced the exact missing-setting failure in web-style packaging discovery. After the one-line fix, the worker's focused run and the coordinator's independent run each passed **three tests and 14 assertions** on private PostgreSQL **18.1** with PHP **8.5.0**. The coordinator used `aureuserp_20261015140900_95688_testing`; the completed run took **84.31 seconds**, with output at `/private/tmp/aureuserp-product-settings-root-20261015.log`. Coverage includes the pending-migration fallback without a database insert, persisted `true`, applying the actual registered migration, repeat application preserving `true`, preserving other flags, and normal save after migration. Pint and PHP syntax checks passed for the two changed files. The reported web PHP version was 8.4.21; only CLI PHP 8.5.0 was independently verified.

After the focused proof and read-only preflight, the user explicitly approved applying this one migration to the development database. The coordinator ran:

```bash
rtk proxy php -d memory_limit=512M artisan migrate \
  --path=plugins/webkul/products/database/settings/2026_09_15_000300_add_enable_price_lists_to_products_product_settings.php \
  --no-interaction
```

The exact migration completed successfully. A subsequent read-only check confirmed `enable_price_lists = false`, one migration record, and unchanged existing values for `enable_variants`, `enable_uom`, `enable_packagings` and `enable_deliver_content_by_email`. Settings caching was disabled, so no unrelated cache flush was needed. An unauthenticated HTTP request to the configured `http://aureuserp.test/admin` returned **302** to `/admin/login`, rather than the missing-setting error. The user's session cookie was not used. This verifies startup and the login redirect, not an authenticated admin workflow. No broad migration, reinstall, reset, seeding or rollback was run against development data. The first CLI help attempt hit PHP's 128 MB memory limit; repeating the read-only help command with the project's 512 MB test allowance succeeded before the targeted migration.

The owner also explicitly approved [parts A-D of the quantity, loading and first-journey target rules](plan/12-OPEN-DECISIONS.md#quantity-loading-and-first-journey-decision-proposal--2026-10-15). These govern the stated Q1/Q2/Q3/Q4/Q6 scope without approving unrelated freight, evidence, additional-mode or live-pilot decisions. The later direct confirmation that **SF1 and SF2 are two factory units at different locations within the same legal Company** supersedes the earlier structured answer “Not confirmed yet.” Use separate warehouse or loading-site records under that Company, native same-company transfers and destination receipt; do not manufacture a second legal Company. Actual native record IDs, product conversions, capacity and quality profiles, operator assignments and the remaining technical dependencies still require configuration or verification.

**Inventory safeguards in this increment.** The native Inventory module supplies a tagged synchronous precondition contract without importing Logistics classes. Transfer and lower-level services, direct movement and line writes, and existing-operation edits and deletion invoke it inside their transaction and stock locks. The operation destination form uses one transaction and per-record saves instead of bypassing those model checks with bulk updates. See the [writer audit](STOCK-AND-PAYMENT-WRITER-AUDIT.md#inventory-mutation-preconditions--2026-10-15) for the exact boundary and exclusions. The rejecting test consumer demonstrates the extension point; it is not a real Logistics allocation or release consumer.

**Review and verification status.** The three-persona simplification pass made no source changes: the repeated checks protect different entry points and rejection timing. The actual `ce-code-review` invocation completed with seven independent local reviewers and an independent validator, receipt `20261015-200543-f4d834ac` in `/tmp/compound-engineering-501/ce-code-review/20261015-200543-f4d834ac/review.json`. It initially returned **Not ready** with three actionable findings: concurrent child insertion could bypass operation-deletion checks; denial could discard a caller's unsaved movement edits; and new model wrappers repeated operation-wide scans even when no consumer was registered. The review did not run tests or modify source. External peer review was excluded because its helper could not honor this task's restricted local file scope; the independent local adversarial reviewer ran instead. All three findings were subsequently corrected and the final integration result is recorded below. The actual U9 allocation consumer, U11 release consumer and persistent terminal command-result replay remain open.

**Initial integration result, before review fixes.** The 14-file selection below ran on private PostgreSQL **18.1 (Homebrew)** with PHP **8.5.0**, database `aureuserp_20261015150508_93761_testing`. It finished with **284 passes, three failures and 1,049 assertions in 528.43 seconds**. Output is `/private/tmp/aureuserp-inventory-guard-root-20261015.log`; the runner exited with status 2 and cleaned up its cluster. This is a failed integration run, not acceptance evidence. A short random warehouse code collided with a retained worker fixture's location barcode; the Move API listing test assumed exactly two global rows despite committed concurrency fixtures; and the cross-company Internal resource test expected an uncaught exception rather than checking the rendered denial response. Their corrections must preserve the uniqueness constraint, unrelated fixture records and cross-company denial.

```bash
rtk proxy bash scripts/run_logistics_phase2a_tests.sh \
  plugins/webkul/inventories/tests/Feature/Workflows/StockMutationPreconditionTest.php \
  plugins/webkul/inventories/tests/Feature/Workflows/TransferIntegrityTest.php \
  plugins/webkul/inventories/tests/Feature/Workflows/StockDeltaAtomicityTest.php \
  plugins/webkul/inventories/tests/Feature/Workflows/StockIdentityTest.php \
  plugins/webkul/inventories/tests/Feature/Workflows/ReplicationTest.php \
  plugins/webkul/inventories/tests/Feature/Workflows/OneStepDeliveryTest.php \
  plugins/webkul/inventories/tests/Feature/Workflows/OperationChangeTest.php \
  plugins/webkul/inventories/tests/Feature/API/V1/MoveTest.php \
  plugins/webkul/inventories/tests/Feature/API/V1/DeliveryTest.php \
  plugins/webkul/inventories/tests/Feature/Filament/InternalResourceTest.php \
  plugins/webkul/sales/tests/Feature/Workflows/OneStepSaleOrderTest.php \
  plugins/webkul/manufacturing/tests/Feature/Workflows/ManufacturingOrderTest.php \
  plugins/webkul/products/tests/Feature/Workflows/ProductSettingsMigrationTest.php \
  plugins/webkul/products/tests/Feature/Workflows/PriceListResolverTest.php
```

**Review corrections and focused proof.** All three actionable findings were applied as one coupled Inventory fix. The existing lock helper now rejects a changed complete-operation movement set before invoking guards. Guards inspect fresh locked movement records before the caller's model is refreshed, so a rejected save or confirmation retains its pending edits and original values. The newly added movement and operation model wrappers check for registered consumers dynamically and skip their added scans when none exist; existing movement-line and service locking remains unchanged. Conflict text uses the existing Inventory translation structure: “This transfer changed while you were working. Reload it and try again.”

New regressions first failed **six cases, with 21 passes and 108 assertions in 93.07 seconds**, on private PostgreSQL **18.1** and PHP **8.5.0**, database `aureuserp_20261015152128_42973_testing`; log `/private/tmp/aureuserp-guard-review-red-20261015.log`. These reproduced lost edits on direct save and confirmation, unnecessary scans without consumers, and concurrent different-product insertion against operation deletion, edit and backorder creation. After correction, the guard, Move API and Internal resource files passed **58 tests and 275 assertions, with no skips, in 27.62 seconds**, database `aureuserp_20261015152453_50467_testing`; log `/private/tmp/aureuserp-guard-review-green-20261015.log`. The independent-session tests verify that the first command rejects changed membership and a fresh retry presents the new product to the rejecting guard. Additional checks cover deletion, release, merge, assignment, push rules and return denial; return denial leaves stock, records, chatter and the return event unchanged. An earlier sandbox shared-memory startup refusal was resolved through the approved isolated runner and is not counted as a reproduced code failure.

Warehouse fixtures now use UUID codes rather than a small random namespace. The Move API test preserves retained concurrency fixtures and verifies its newest records and actual pagination total. The Internal resource test asserts the observed **404** for a record belonging to another Company; access control was not relaxed. No review finding remains unapplied. Registered-consumer query cost, direct Filament destination-change rollback coverage, actual Logistics consumers and saved terminal-result replay remain distinct verification or implementation work; the focused pass is not full P1A acceptance.

The first complete rerun after these corrections finished with **299 passes, one failure and 1,250 assertions in 247.13 seconds** on private PostgreSQL **18.1**, PHP **8.5.0**, database `aureuserp_20261015155311_66093_testing`; log `/private/tmp/aureuserp-inventory-guard-root-final-20261015.log`. Its remaining Move API include test inspected the first global row, which could be a retained concurrency fixture with no operation. The test now requests its own line ID and checks exact movement, operation and product IDs, rather than assuming every unrelated line has an operation. Production code did not change for this final fixture correction.

**Final integration verification.** The same 14-file command above passed **300 tests and 1,253 assertions, with no skipped tests, in 295.31 seconds** after all corrections. The root agent observed completion and exit status 0 on private PostgreSQL **18.1 (Homebrew)** with PHP **8.5.0**, database `aureuserp_20261015155925_81089_testing`; output `/private/tmp/aureuserp-inventory-guard-root-verified-20261015.log`. The runner cleaned up its private cluster. This includes registered guard denial and real lock contention, stock identity and reservation races, transfer completion/cancellation and return regressions, ordinary native UI/API callers, Sales and Manufacturing integration, and Product settings and price-list regressions. The 22 task-owned PHP files passed root Pint verification; the final API fixture edit also passed Pint. Git whitespace checks and the 13-document plan validator passed. No additional application migrations were applied, and no commits or pushes were made.

**Next bounded work.** Implement Sales quantity and packaging snapshots under U6 using the approved target rules and explicit missing-configuration validation. Continue U4 saved terminal-result replay and the remaining owner-integrity acceptance before dependent allocation and execution commands activate. U9 and U11 must register and qualify the actual Logistics consumers, including representative registered-consumer performance and a direct destination-change rollback test. Dedicated-vehicle and multi-stop development remains first in the engineering sequence; the all-four-mode live-pilot gate is unchanged.

### 2026-10-15 Sales quantity snapshots and commercial-unit corrections

The U6 implementation adds `sales_quantity_commitments`, a Sales-owned append-only history keyed by source line and revision. Confirmation and significant confirmed-line changes save commercial and stock quantities, both units, conversion and precision, packaging, customer, address and loading origin. PostgreSQL prevents changes and deletion of these saved revisions. Existing historical confirmations are not backfilled. Source changes are serialized, stale edits reject without partial effects, and ordinary saves, quiet saves, increments and API line omission use the owner checks. Product, unit and packaging records remain locked through acceptance and procurement. The [writer audit](STOCK-AND-PAYMENT-WRITER-AUDIT.md#sales-quantity-snapshots-and-procurement--2026-10-15) records the boundary and its limits.

Tests exposed three real quantity defects: six cartons of twelve units were invoiced as 72 cartons; a subsequent one-carton increase invoiced all seven cartons; and price-only recalculation could change a confirmed loading origin after route changes. Sales now uses stock quantities for procurement, commercial quantities for delivery and invoicing, the remaining quantity for additional invoices, and preserved links to earlier invoices. Confirmed origin and conversion data are retained. A separate PostgreSQL regression reproduced a master-data writer completing without waiting during confirmation; metadata row locks now serialize those writes with acceptance.

Verification used private PostgreSQL **18.1 (Homebrew)** databases and PHP **8.5.0**. The seven-file selection below finished with **80 passes, nine failures and 253 assertions in 54.06 seconds**, without skips; output `/private/tmp/aureuserp-u6-combined-20261015.log`. All quantity, invoicing, API, one-step delivery and independent-session concurrency cases passed. The nine remaining failures were in revised Filament fixtures. Subsequent fixture corrections selected the proper guard and owner, supplied required product data, and asserted the observed HTTP 404 for another Company's record while also proving that a scoped query could not find it. Runtime access controls were not weakened.

```bash
rtk proxy bash scripts/run_logistics_phase2a_tests.sh \
  plugins/webkul/sales/tests/Feature/Workflows/QuantityCommitmentTest.php \
  plugins/webkul/sales/tests/Feature/Workflows/OrderConfirmationIntegrityTest.php \
  plugins/webkul/sales/tests/Feature/Workflows/OrderConfirmationConcurrencyTest.php \
  plugins/webkul/sales/tests/Feature/Workflows/OneStepSaleOrderTest.php \
  plugins/webkul/sales/tests/Feature/Workflows/OrderInvoicingTest.php \
  plugins/webkul/sales/tests/Feature/API/V1/OrderTest.php \
  plugins/webkul/sales/tests/Feature/Filament/OrderResourceTest.php
```

After the final quantity-only packaging regression change, all **21 quantity tests passed** in a two-file selection that still had eight UI fixture failures: **27 passes, eight failures and 79 assertions in 29.00 seconds**; output `/private/tmp/aureuserp-u6-final-focused-20261015.log`. After the remaining fixture corrections, `OrderResourceTest.php` passed **14 tests and 56 assertions, with no skips, in 16.53 seconds**, database `aureuserp_20261015172833_73408_testing`; output `/private/tmp/aureuserp-u6-filament-scope-20261015.log`. These are separate results, not a single passing seven-file run. Final integrated verification remains required after the Inventory changes and review corrections.

Two Sales invoice tests previously attempted ordinary cancellation of posted entries. Their fixtures now exercise draft cancellation and reset, retaining their Sales quantity assertions. An additional regression checks that posted cancellation is rejected and leaves Sales quantities and invoice links unchanged. Accounts still requires a reversal for posted accounting effects. Native quotation and creation-only form authorization are covered without granting permission to edit arbitrary existing orders.

The source worker formatted all 18 task-owned PHP files and passed Sales whitespace checks. Two prolonged runs were interrupted and are not acceptance evidence. A later diagnostic stack showed permission-cache hydration during Company context setup; the instrumented seven-file selection completed in 58.99 seconds. The intermittent slowdown has not been conclusively resolved, and temporary diagnostics were removed. This increment has not applied application database migrations or created commits or pushes. Review, the native in-use UOM rounding safeguard, saved Inventory result replay and actual Logistics consumers remain separate work.

## 2026-10-16 Sales and Inventory review corrections

The completed Sales review `20261015-223201-4e91525f` found six actionable issues: fabricated initial history on legacy source edits; inconsistent excluded-line eligibility; false revisions when the API inferred stock quantities; missing client source-revision checks; a historical-data assertion comparing the same refreshed object; and missing return declarations. The first correction batch also tests invalid raw quantities before numeric coercion. Its initial regression run reproduced seven failures with 41 passes and 148 assertions on private PostgreSQL 18.1; `/private/tmp/aureuserp-sales-review-A-red-20261015.log`. An API request must retain the commercial unit selected on the existing line when that field is omitted, rather than inferring today's Product base unit.

The Inventory operation-result review `20261016-005746-058b4e35` found a shared Security cache invalidation gap and two sources of per-move queries while stock locks are held. The cache finding was initially established by source inspection, not a database reproduction: native role permission changes cleared the Spatie registrar, while permission lookup used the separate Webkul registrar. Independent review coverage was reduced by agent network failures; the receipt records its inline fallback and completed efficiency reviewer. Do not describe it as a fully independent review.

These findings are being corrected in bounded owner-local batches before combined acceptance. The earlier individual passing selections do not establish that the final integrated source passes. No application database migration, commit or push is authorized by this evidence report.

The Sales eligibility batch reproduced excluded goods creating stock demand and recorded goods being repurposed without updating their commitment. Its first run returned 26 passes, eight failures and 98 assertions in 88.54 seconds. After aligning procurement eligibility and rejecting recorded-line repurposing, a valid product-free note exposed an existing invoice-remainder dereference; the narrow calculator guard now assigns notes a zero remainder. The final `QuantityCommitmentTest.php` selection passed **34 tests and 132 assertions, with no skips, in 40.39 seconds**, private PostgreSQL **18.1**, PHP **8.5.0**, database `aureuserp_20261015201349_15845_testing`; `/private/tmp/aureuserp-sales-review-B-final-20261016.log`. Tests independently capture the original history and check native Inventory events, locations and demand after a quiet Sales save. Service items continue to retain commercial history without stock procurement.

The last completed source-revision batch selection returned 81 passes, one test assertion failure and 325 assertions in 67.88 seconds. The failure compared a RichEditor document with a plain string; the corrected test checks its actual text and independently saved order and move data. A subsequent one-file run was interrupted after stalling while PHP waited for a child command; PostgreSQL sessions were idle, with no observed lock wait. It produced no test result and does not establish acceptance. Its task-owned process and cluster were removed. Logs: `/private/tmp/aureuserp-sales-review-A-final-20261016.log` and `/private/tmp/aureuserp-sales-review-A-form-final-20261016.log`; diagnostic sample `/private/tmp/aureuserp-sales-review-A-php-sample-20261016.txt`. The final combined run must exercise this corrected assertion and the successive-save case.

The first Security regression attempt failed its initial permission-grant assertion because the helper selected the API guard while the fixture granted native web permissions. That run did not reproduce revocation. After selecting the correct guard, all three initial grant checks passed and all three revoked-role retries incorrectly succeeded instead of throwing an authorization exception: **32 passes, three failures and 108 assertions in 42.46 seconds**, PostgreSQL **18.1**, PHP **8.5.0**, private database `aureuserp_20261015201926_19532_testing`; `/private/tmp/aureuserp-security-revocation-red-web-20261016.log`. The local fix overrides the native Role cache-flush method to retain the parent flush and clear the Webkul registrar as well. The next Inventory selection and final combined run must verify denial after revocation, access after regrant and unchanged terminal results, stock and events. Permission rename and deletion lifecycle invalidation is outside this role-permission fix.

The Inventory query regressions then returned **35 passes, four failures and 199 assertions in 174.59 seconds** on PostgreSQL **18.1**, PHP **8.5.0**, private database `aureuserp_20261015202139_21029_testing`; `/private/tmp/aureuserp-inventory-query-red-20261016.log`. All three Security revocation and regrant cases passed. Increasing a snapshot from one move to five increased its queries from nine to 25; completion and cancellation guard queries increased from six to ten. The direct-line guard case initially failed an assertion about ordering rather than query count; its test now compares the same membership without imposing an unsupported order. The local query fixes batch operation reads and eager-load ordered snapshot relations without changing the stock lock order, saved fields or guard decisions.

After those fixes, the three-file selection of `OperationResultReplayTest.php`, `StockMutationPreconditionTest.php` and `TransferIntegrityTest.php` passed **103 tests and 598 assertions, with no skips, in 219.74 seconds**, PostgreSQL **18.1**, PHP **8.5.0**, private database `aureuserp_20261015202539_21525_testing`; `/private/tmp/aureuserp-inventory-query-green-20261016.log`. This includes the three native role revocation and regrant cases, snapshot and guard query-growth checks, replay behavior, stock mutation denial and transfer contention. The runner exited zero and removed its private cluster. Task-owned Pint and whitespace checks passed. The combined Sales, Inventory and Manufacturing selection remains a separate final check.

The first 19-file root integration run finished with **346 passes, ten failures and 1,483 assertions in 448.51 seconds**, without skips, PostgreSQL **18.1**, PHP **8.5.0**, private database `aureuserp_20261015203122_22257_testing`; `/private/tmp/aureuserp-sales-inventory-combined-20261016.log`. It is not an accepted combined baseline. Failures were a snapshot unit-factor assertion, six mixed-unit return fixture assertions, a stock-concurrency child exiting 255, an API first-page membership assertion and a count rollback test whose expected exception did not occur. Shared unit-factor changes are a suspected integration-fixture cause, not yet a confirmed explanation. The private runner exited one and removed its cluster. The exact selection was:

```bash
rtk proxy bash scripts/run_logistics_phase2a_tests.sh \
  plugins/webkul/sales/tests/Feature/Workflows/QuantityCommitmentTest.php \
  plugins/webkul/sales/tests/Feature/Workflows/OrderConfirmationIntegrityTest.php \
  plugins/webkul/sales/tests/Feature/Workflows/OrderConfirmationConcurrencyTest.php \
  plugins/webkul/sales/tests/Feature/Workflows/OneStepSaleOrderTest.php \
  plugins/webkul/sales/tests/Feature/Workflows/OrderInvoicingTest.php \
  plugins/webkul/sales/tests/Feature/API/V1/OrderTest.php \
  plugins/webkul/sales/tests/Feature/Filament/OrderResourceTest.php \
  plugins/webkul/sales/tests/Feature/Filament/QuotationResourceTest.php \
  plugins/webkul/inventories/tests/Feature/Workflows/OperationResultReplayTest.php \
  plugins/webkul/inventories/tests/Feature/Workflows/StockMutationPreconditionTest.php \
  plugins/webkul/inventories/tests/Feature/Workflows/StockDeltaAtomicityTest.php \
  plugins/webkul/inventories/tests/Feature/Workflows/TransferIntegrityTest.php \
  plugins/webkul/inventories/tests/Feature/API/V1/DeliveryTest.php \
  plugins/webkul/inventories/tests/Feature/API/V1/ReceiptTest.php \
  plugins/webkul/inventories/tests/Feature/API/V1/InternalTransferTest.php \
  plugins/webkul/inventories/tests/Feature/API/V1/DropshipTest.php \
  plugins/webkul/inventories/tests/Feature/Workflows/QuantityTest.php \
  plugins/webkul/inventories/tests/Feature/Workflows/ScrapTest.php \
  plugins/webkul/manufacturing/tests/Feature/Workflows/ManufacturingOrderTest.php
```

A separate read-only end-to-end check found an inherited commercial-reduction defect: a negative procurement remainder can become a positive reverse-direction movement after pending demand is offset. This does not automatically post stock, but it proposes an unrequested physical return. A Sales-owned issued-quantity safeguard is required before U9 consumes remaining demand. An order of ten with six completed issues and four pending must support reduction to six without a reverse movement; reduction to five must reject atomically until an explicit supported return or onsite correction establishes the corresponding physical result.

The Sales safeguard reproduced five failures and two passes before implementation. Procurement now owns an outer transaction that locks Sales orders, lines and then Products before re-reading source and stock-movement facts. A proposed reduction below completed issues rejects before procurement; only compatible completed returns with proven direct source linkage reduce that minimum. Pending and unlinked returns do not. Completed first-leg issues in multi-step deliveries remain protected until a supported correction; indirect multi-leg return attribution is not invented. A separate packaging-change regression showed that changing packaging could prevent pending-demand offset. Packaging now uses the existing active-movement and invoice identity guard; no new movement-diff framework was added.

The final selection of new `IssuedQuantityReductionTest.php`, `OrderConfirmationConcurrencyTest.php` and `QuantityCommitmentTest.php` passed **48 tests and 199 assertions, with no skips, in 48.96 seconds**, PostgreSQL **18.1**, PHP **8.5.0**, private database `aureuserp_20261015204703_34050_testing`; `/private/tmp/aureuserp-issued-reduction-final-green-20261016.log`. A real second session waited on the Product `FOR UPDATE` query, then rejected the reduction after Inventory committed, preserving source quantity, history and completed movements. This does not establish a universal cross-owner lock order: native completion callbacks can acquire Sales locks after Product locks, so a deadlock must still roll back the whole losing command and be retried from fresh state.

The concurrency test that intentionally changes a unit factor now creates a private unit. Its former update of the seeded Units row was committed and caused seven failures in the first combined run. The remaining count, API pagination and worker-diagnostic fixtures are being repaired separately; return and snapshot arithmetic assertions are retained.

The fixture repairs passed a focused three-file run of **41 tests and 234 assertions in 26.59 seconds**, then an ordered six-file run of **113 tests and 694 assertions in 109.74 seconds**, both without skips on PostgreSQL **18.1** and PHP **8.5.0**. The ordered selection ran Sales master-data contention before Inventory replay, stock-delta contention, transfer integrity, count workflows and the delivery API; private database `aureuserp_20261015204932_35529_testing`, `/private/tmp/aureuserp-combined-fixture-order-20261016.log`. The source snapshot and mixed-unit arithmetic assertions were not weakened. The count rollback test now injects a product-specific movement-history write failure and restores its event dispatcher. The API fixture uses the supported ID filter rather than assuming that a newly created record appears on the first page. Unexpected stock-worker exceptions now include their class, message and trace in the child diagnostics, and the parent assertion includes both output streams. The earlier exit 255 did not reproduce in either passing selection; its cause remains unconfirmed. Do not label the diagnostic improvement as a confirmed runtime fix.


The Sales form regression covers an editor opened before the warehouse issues six of ten units. The source revision remains current, but the displayed delivery count is stale. Reducing the order to five must therefore reach the authoritative issued-quantity check. Without the form correction, both full-save and products-only cases failed to display the owner error at `data.products`: **15 passes, two failures and 92 assertions in 31.13 seconds**; `/private/tmp/aureuserp-sales-quantity-feedback-red-20261016.log`. Sales-local wrappers now retain native rollback and cross-company handling, map only the `products` messages to the form field, and rethrow the same validation exception. API error keys are unchanged. The final `OrderResourceTest.php` selection passed **17 tests and 108 assertions, without skips, in 24.38 seconds**, private PostgreSQL **18.1**, PHP **8.5.0**, database `aureuserp_20261015205749_53057_testing`; `/private/tmp/aureuserp-sales-quantity-feedback-green-20261016.log`. Both paths display the translated message, preserve the user's quantity, note and source token, and leave persisted Sales, movement and stock records unchanged. Pint and scoped whitespace checks passed. Combined verification and the bounded suspension safeguard remain separate checks.


### 2026-10-16 Serialized suspension boundary

The bounded U8 foundation adds `revision` to the existing Company control and control-history tables. Existing control rows start at zero; old history remains null. Database constraints reject negative current revisions, nonpositive recorded transition revisions and duplicate Company transition revisions. An actual state change advances the revision and saves history atomically; a repeated setting does neither. This does not fabricate historical approval generations.

`authorizeNewActivity()` now requires a caller-owned transaction, locks Company and then control, checks a fresh active actor and current Company membership, rejects inactive Companies and suspended activity, and returns the control revision. Settings permissions apply separately to settings reads and changes; operational staff need their command's action permission rather than settings access. The Company lock remains held through the calling command's transaction. Settings changes take the same lock and repeat authorization inside it. New translated messages describe rejection and recovery plainly. Actual approval records must eventually bind this revision; none are implemented by this boundary.

The initial Foundation selection returned **26 passes, seven expected failures and 148 assertions in 50.61 seconds**; `/private/tmp/aureuserp-u8-red-20261016.log`. The final selection of `FoundationTest.php`, `RemovalTest.php` and `SuspensionCutoffTest.php` passed **49 tests and 298 assertions, with no skips, in 72.99 seconds**, private PostgreSQL **18.1**, PHP **8.5.0**, database `aureuserp_20261015210118_60620_testing`; `/private/tmp/aureuserp-u8-green-20261016.log`. Independent sessions observe an actual blocking relationship before continuing: when the command wins, its protected write commits before suspension; when suspension wins, the waiting command rejects without its business write. Tests also cover revision history, no-op settings, rollback, physical constraints, inactive accounts and Companies, revoked Company membership, and permission separation. Eight owned PHP files passed Pint and scoped whitespace checks. The private cluster was removed.

This is a shared boundary for future commands, not completion of all U8 acceptance. Command identity, scoped approvals, protected evidence, actual allocation and release consumers, stale worker checks and allowed correction workflows still require their own implementation and tests. Dedicated-vehicle and multi-stop execution must not bypass those dependencies. The additive migration has not been applied to the application database.


**Bounded quality review.** Four independent read-only contexts reviewed the new issued-quantity safeguard, interrupted-edit feedback and suspension cutoff for correctness and concurrency, security, testing and maintainability, and migration and project standards. No actionable defect remained after an independent validator rejected a fixture-cleanup candidate: the test's committed synthetic identities remain inside the task-owned cluster that the canonical runner removes. Three minor simplification candidates were deliberately skipped because they did not justify extra source changes. Receipt: `/tmp/compound-engineering-501/ce-code-review/20261016-020148-f9e92203/review.json`. Its status is explicitly `degraded`: the skill's Git-only scope helper and external peer could not represent this restricted method-level and pre-unit snapshot comparison. Exact bounded independent review and findings validation ran; this is not an unmodified complete review pipeline, a review of the entire dirty tree, or full U4/U8 acceptance. Cross-owner deadlocks, automatic whole-command retries and actual Logistics consumers remain qualified limitations.


### 2026-10-16 Final combined PostgreSQL verification

The root agent observed exit status zero for the following 23-file selection: **418 tests passed, 1,947 assertions, no skipped tests, 445.54 seconds**. The private database was `aureuserp_20261015210305_64120_testing`, PostgreSQL **18.1 (Homebrew)** with PHP **8.5.0**. Output: `/private/tmp/aureuserp-integrity-combined-final-20261016.log`. The runner removed its task-owned cluster. A preliminary invocation stopped before database setup because the new test filename was supplied incorrectly; the corrected selection below is the completed acceptance run.

```bash
rtk proxy bash scripts/run_logistics_phase2a_tests.sh \
  plugins/webkul/sales/tests/Feature/Workflows/QuantityCommitmentTest.php \
  plugins/webkul/sales/tests/Feature/Workflows/OrderConfirmationIntegrityTest.php \
  plugins/webkul/sales/tests/Feature/Workflows/OrderConfirmationConcurrencyTest.php \
  plugins/webkul/sales/tests/Feature/Workflows/IssuedQuantityReductionTest.php \
  plugins/webkul/sales/tests/Feature/Workflows/OneStepSaleOrderTest.php \
  plugins/webkul/sales/tests/Feature/Workflows/OrderInvoicingTest.php \
  plugins/webkul/sales/tests/Feature/API/V1/OrderTest.php \
  plugins/webkul/sales/tests/Feature/Filament/OrderResourceTest.php \
  plugins/webkul/sales/tests/Feature/Filament/QuotationResourceTest.php \
  plugins/webkul/inventories/tests/Feature/Workflows/OperationResultReplayTest.php \
  plugins/webkul/inventories/tests/Feature/Workflows/StockMutationPreconditionTest.php \
  plugins/webkul/inventories/tests/Feature/Workflows/StockDeltaAtomicityTest.php \
  plugins/webkul/inventories/tests/Feature/Workflows/TransferIntegrityTest.php \
  plugins/webkul/inventories/tests/Feature/API/V1/DeliveryTest.php \
  plugins/webkul/inventories/tests/Feature/API/V1/ReceiptTest.php \
  plugins/webkul/inventories/tests/Feature/API/V1/InternalTransferTest.php \
  plugins/webkul/inventories/tests/Feature/API/V1/DropshipTest.php \
  plugins/webkul/inventories/tests/Feature/Workflows/QuantityTest.php \
  plugins/webkul/inventories/tests/Feature/Workflows/ScrapTest.php \
  plugins/webkul/manufacturing/tests/Feature/Workflows/ManufacturingOrderTest.php \
  plugins/shafique/logistics/tests/Feature/FoundationTest.php \
  plugins/shafique/logistics/tests/Feature/RemovalTest.php \
  plugins/shafique/logistics/tests/Feature/SuspensionCutoffTest.php
```

This run combines Sales snapshots, stale edits, issued-quantity reductions and native forms/API; Inventory terminal replay, access revocation, query-growth and registered mutation guards, stock contention, transfers, counts, scrap and native APIs; Manufacturing transitions; and Logistics foundation, removal and serialized suspension. The corrected source-revision form assertions, private-unit contention fixture, return arithmetic, late count rollback and exact API membership all passed together. Earlier failed and interrupted results remain above rather than being presented as successful runs.

Task-owned PHP files passed their recorded Pint checks; final Git whitespace and local-file-link checks passed across all 13 plan documents. The bounded review's scope limitation remains explicit above. No application migration, browser validation, operational transport acceptance, commit or push was performed. This is integrated local verification of the listed behavior, not full U4, U8 or release acceptance.

**Next implementation sequence.** Continue protected evidence with configured retention, command identity and scoped approvals; then implement selected-demand allocation, native remainder lineage and the actual Inventory precondition consumers. Build dedicated-vehicle and multi-stop booking, independent load verification and exact stock release on those foundations. Preserve explicit issued-goods correction and receiving dependencies; do not interpret the new control gate as permission to bypass them. The first live pilot still requires all four transport modes.

### 2026-10-16 Logistics evidence and outbound trip execution

The work described above has advanced locally. The Logistics plugin now has additive schema and service foundations for private evidence, Company-scoped retention policies, legal holds, evidence access checks, immutable lifecycle records, command idempotency, approvals scoped to a specific action and record revision, and suspension cutoffs. Approval issuance now checks that the signed-in user is the approver; a request cannot nominate another user as approver merely by supplying their ID.

Outbound trip services now support dedicated-vehicle and multi-stop trips. They allocate against the current confirmed Sales quantity commitment and exact destination snapshot; record actual quantities against every native Inventory move line; require an independent verifier; require a separate scoped release approval; and release each represented Inventory operation through the Inventory transfer workflow within one transaction. A partial load records the actual issued quantity and leaves the native backorder remainder available for a later trip. Replaying the same release command returns its saved release batch. Receipt reports are stored as observations and do not by themselves mark a customer order as fulfilled or reconcile accounts.

The focused PostgreSQL 18.1 Logistics suite passed **17 tests and 79 assertions** across evidence, command identity, approvals, suspension, dedicated-vehicle execution and a two-stop trip releasing two separate Sales and Inventory operations. The first 38-file cross-module selection returned **585 passes and one failure** (2,845 assertions); its failure was an Inventory test asserting that ordinary non-stock edits do not perform a stock lock scan. A first lock-boundary adjustment then exposed three Inventory guard regressions, and a broad rerun returned **584 passes and two Logistics failures** (2,831 assertions) because the new scoped guard used Eloquent-only `modelKeys()` with a regular Collection. Both defects were corrected. The final focused Inventory and Logistics selection then passed **167 tests and 956 assertions** on PostgreSQL **18.1**, PHP **8.5.0**, private database `aureuserp_20261016010059_27493_testing`. It included the Inventory stock-mutation precondition, result replay and transfer integrity tests, plus Logistics controls, protected evidence, commands, approvals, suspension, and both outbound journeys. The broad 38-file selection has not been rerun after the final Collection fix, so a single green integrated result remains outstanding. All selected Accounts, Sales, Inventory, Manufacturing and root reconciliation tests passed in the broad run apart from the noted Logistics failures. The runner removed each task-owned cluster; no application database migration was applied.

This implementation is backend-only and has not received operational validation. The Logistics plugin still has no operator UI for trip, stop, allocation, load, verification, release or receipt workflows. Real evidence uploads are blocked until a Company retention policy is configured. Carrier consignment and distributor pickup are not implemented. The approved first live pilot still requires all four modes. Do not describe the two backend journeys as ready for dispatch or pilot use until operator screens, missing business configuration, operational walkthroughs, support procedures and the plan's acceptance gates are complete. No Logistics migration has been applied to the application database; no commit or push was made.


### 2026-10-16 Admin workflows and configurable loading controls

This section supersedes the backend-only and outstanding broad-rerun status immediately above. The original **38-file selection passed 586 tests and 2,849 assertions**, without skipped tests, in **415.56 seconds** on private PostgreSQL **18.1**, PHP **8.5.0**, database `aureuserp_20261016012744_47822_testing`. Its log is `/private/tmp/aureuserp-full38-20261016.log`. This closes the Collection-fix rerun gate for the code before the new admin and loading-control changes. It does not establish a pass for later changes.

Filament now provides Company-scoped outbound trip and evidence screens. Trip actions delegate to the transactional services and retain a command identifier and expected record revision. Demand comes from current confirmed Sales quantity snapshots; searchable results are bounded without excluding older orders from eligibility. Operators select a loading origin, count reserved native Inventory move lines, and record a deadline, transport provider, vehicle, driver, goods custodian, measured cargo weight and vehicle capacity. Volume measurements are required unless the operator records why volume does not limit that load. The total measurement includes goods already aboard at an earlier origin. Product handling rules and approved loading locations are versioned per Company. A new policy revision invalidates earlier loading checks.

Verification and stock issue use the signed-in independent approver and current exact load. They recheck demand, policy, deadline, suspension and required evidence. A typed quality-reference number is not proof of quality release: products configured to require formal quality release remain blocked until authoritative quality integration exists. This is an explicit implementation dependency, not permission to classify controlled products as ordinary goods. Incoming production forecasts do not create available stock.

Each loading origin receives a separate immutable load and issue batch. Earlier issued Inventory operations are not edited or replayed as a new issue. Receipt reports for already-issued batches remain available while later-origin loading is in progress. Receipt observations retain the named reporter and occurrence time, distinguish incremental totals and corrections, and do not themselves confirm customer acceptance or post stock or accounting entries.

The first focused admin and workflow run exposed an unsupported Hidden-field integer method and a PostgreSQL timestamp offset error. Both were corrected; time-zone-aware loading timestamps now preserve their offset when stored. The four-file selection then passed **18 tests and 205 assertions in 33.95 seconds** on private PostgreSQL **18.1**, PHP **8.5.0**, database `aureuserp_20261016015515_64652_testing`. It covered admin journeys for both modes, self-verification rejection, overload rejection, deadline expiry, forged quality-reference rejection, configurable role permissions, protected evidence and scoped approvals. A subsequent admin-only selection passed **7 tests and 178 assertions in 33.40 seconds**, database `aureuserp_20261016015747_65910_testing`, including separate issue batches from two warehouses in one Company and receipt reporting between origin loads. The integrated 39-file rerun adds this admin file to the original 38-file selection. It passed **593 tests and 3,028 assertions, with no failures or skips, in 443.21 seconds**, on PostgreSQL **18.1**, PHP **8.5.0**, private database `aureuserp_20261016015923_67790_testing`. The log is `/private/tmp/aureuserp-full39-ui-20261016.log`. The runner removed its private cluster after completion. Source remained unchanged during this run.

After a read-only preflight confirmed PostgreSQL **18.1**, the configured development database `aureuserp`, the installed Logistics plugin, only two existing Logistics control tables, no conflicting outbound immutability function and no pending-table collisions, only the four selected Logistics migrations numbered `000200` through `000500` were applied. Only the Logistics permission-definition seeder ran. No reset, general plugin installation, application-wide migration, role assignment or operational data seeding ran. Postflight found zero trips and zero retention policies; retention still requires an authorized Company policy action. The current authenticated Admin browser session received a permission denial on the new trip screen. End-to-end browser use remains unverified until the owner authorizes its role access. Livewire action tests use independent test actors and real Inventory stock issue inside the private test database.

The owner requested configurable recommended retention and configurable roles rather than fixed staff names. The proposed starting period is **2,557 days**, at least seven calendar years, configurable per Company and preserved by policy revision. This is a business recommendation, not a determination that every legal requirement has been met. No automatic disposal is enabled. The owner supplied no completed delivery packet; synthetic dedicated, multi-stop and two-origin walkthroughs provide engineering evidence only. Factory mappings, actual product conversions and handling rules, production-availability evidence, deadlines, vehicle limits and staff assignments must be configured from actual operations. No SF1 or SF2 locations were invented in the existing development database.

Remaining bounded dependencies include authoritative quality-release integration, pre-issue cancellation and allocation recovery, departure and verified customer acceptance, issued-goods corrections and returns, carrier consignment, distributor pickup, and real-document acceptance for all four modes. The first live pilot remains blocked until the plan's acceptance gates pass. No commit or push was made.

**Migration recovery restriction.** Migration `000500` permits separate issue batches for one trip, replacing the earlier one-batch-per-trip constraint. Once multiple batches exist, the earlier schema cannot represent them. Use forward recovery; do not delete, combine or rewrite issued Inventory results or immutable Logistics batches to force a rollback. PostgreSQL transactional migration behavior prevents a failed constraint restoration from committing a partial rollback. This documented forward-recovery restriction resolves the review observation without deleting or combining historical batches. No rollback was attempted.


### 2026-10-16 Approved Admin access and saved retention policy

The owner explicitly authorized granting Logistics permissions to the existing Admin role. A read-only PostgreSQL 18.1 preflight identified role 1, guard `web`, and no Company retention policy. The additive transaction granted the exact 24 existing Logistics permission definitions. Twenty-one were new to that role; all 1,895 prior permission assignments were preserved, leaving 1,916 permissions in total and zero missing Logistics permissions. The first attempt's overly restrictive permission filter failed its expected-count guard and rolled back before making changes; the corrected grant succeeded. Permission caches were invalidated using the existing registrars. No other role or guard was changed.

The real browser check then exposed raw trip translation keys. Panel registration had translated custom permission labels before the Logistics translation namespace was registered, causing that translation group to be cached as missing for the request. Permission label initialization now runs after application providers finish booting. Reloading the signed-in trip screen displayed Outbound Trips, Create trip, readable columns and the normal empty state. The evidence and module settings screens also opened normally. This startup correction occurred after the 593-test integrated run; its focused verification is recorded below separately.

Through the signed-in Admin browser session, Company 1 received operational-evidence retention policy revision 1 for **2,557 days**, with the owner's authorization recorded as the reason. Postflight confirmed `approved_by = 1`, all 24 Logistics role permissions, zero operational trips and zero evidence records. The page displayed the saved period and enabled Upload evidence. Existing evidence retention, legal holds, approved disposal requirements and independent load verification were not bypassed. No test shipment or fake operational document was inserted into application data.

The settings screen verified at `/admin/module-settings` still showed Logistics suspended and dispatch rules unconfigured. Neither was silently changed. Configure actual product and location rules and independent staff responsibilities before resuming execution. Browser proof of the saved retention configuration is `/private/tmp/aureuserp-logistics-retention-20261016.png`. No commit or push was made.

After the startup correction, the focused `TripWorkspaceTest.php` and `EvidenceStoreTest.php` selection passed **10 tests and 190 assertions in 43.90 seconds**, with no failures or skips, on private PostgreSQL **18.1**, PHP **8.5.0**, database `aureuserp_20261016105057_93811_testing`. Pint, the 13-document plan validator and Git whitespace checks passed. This focused run plus the real signed-in browser check validates the subsequent access and label changes; the earlier 593-test integrated result predates the startup correction.


### 2026-10-21 Pre-issue cancellation and allocation recovery

The current working tree was reviewed before adding recovery. Existing changes and index state were preserved; no commit, push, reset or branch switch was performed. The repository remains on `production`, the development branch. No deployment target or alternate database engine was introduced.

Added three bounded Logistics commands: release an unused allocation, confirm complete physical unloading of a current unissued load, and cancel a trip before any trip stock issue. Each command uses Company-scoped command identity, current access checks, an expected trip revision and a recorded reason. Unloading additionally requires an affirmative physical confirmation. Recovery appends an immutable event with actor, affected references and before/after revisions. It clears current verification without deleting accepted counts, verification records or issued batches. These commands may execute during suspension, but do not re-enable new activity. Trip cancellation keeps Sales orders and native Inventory reservations available for replanning; native cancellation or reservation release remains a separately authorized Inventory command.

The review identified that permanent historical load-item references blocked ordinary Inventory cancellation even after a load was abandoned. Inventory deletes temporary reservation lines during release, so a foreign key from immutable Logistics history to those rows also prevented safe native recovery. New load items now preserve the original reservation identity and facts as a snapshot. Native movement references and saved Sales commitments remain durable. Current loads, issued batches and legacy records lacking snapshots remain protected. Explicit unloading relinquishes only the current unissued load; historical links alone no longer block a replacement trip or authorized native cancellation. New content hashes include the accepted reservation snapshot; older hashes are untouched. No stock balances are updated by these Logistics recovery commands.

A second regression reproduced an overclaim after a partial native issue and a later Sales quantity revision. With six units issued from ten, releasing the unused four and increasing the Sales order to twelve incorrectly permitted a new seven-unit claim because the earlier issue belonged to another commitment revision. The calculation now retains issued quantities across applicable source revisions. The test rejects seven and accepts the remaining six. Earlier active selections continue consuming planning quantity until explicitly released.

Verification began with four failing recovery tests on private PostgreSQL **18.1**, PHP **8.5.0**, database `aureuserp_20261021032337_40857_testing`: undefined recovery commands, **four failures and two assertions in 16.26 seconds**, `/private/tmp/aureuserp-recovery-red-20261021.log`. Initial implementation then passed three cases and failed a permission-revocation fixture (**20 assertions, 28.64 seconds**). The fixture must remove both web and Sanctum permission assignments created by the shared helper; removing only one leaves the same capability assigned through the other guard. A subsequent three-file run passed fifteen tests and failed that incomplete revocation assertion (**244 assertions, 270.15 seconds**), `/private/tmp/aureuserp-recovery-ui-20261021.log`. After correcting complete revocation, the two-file selection passed eight tests and failed the newly added cross-revision overclaim assertion (**64 assertions, 19.95 seconds**), `/private/tmp/aureuserp-recovery-remainder-red-20261021.log`. These failed runs are retained as failure evidence, not described as passes.

The source-only recovery review found no further actionable defect. It specifically checked that ordinary native mutations without a Logistics release context fail before attempting a Trip lock, while the scoped issue command already holds its Trip lock. No new two-connection unload-versus-native-cancellation race test was executed; existing separate-process suspension and Inventory contention tests remain part of integrated verification, and do not by themselves prove every recovery race.

A read-only development preflight identified database `aureuserp` on PostgreSQL **18.1**, zero trips, zero load items and zero issue batches, no recovery table or snapshot column, and no ledger record for `2026_10_21_000100_add_trip_recovery_history`. Company 1 retains policy revision 1 for 2,557 days; the Admin role retains 24 Logistics permissions. There is no Company activity-control row, so the existing default remains suspended. No dispatch rules, factories, roles, operational records or activity controls were fabricated. The new migration is pending until integrated verification passes.


**Next dependency: onsite issue correction.** Source inspection confirmed that `InventoryManager::createReturn()` delegates to `TransferWorkflow::createReturn()`, which requires a completed source and creates a linked movement from the original destination back to the source. `MoveCanceller` rejects completed moves. Neither is an onsite-abort command. Sales `ProcurementRequester` classifies qualifying customer-origin incoming moves marked `is_refund` as returned quantities; changing that flag does not establish correct onsite fulfillment semantics. The next implementation unit must therefore introduce an Inventory-owned onsite correction result with exact original issue links, cumulative quantity bounds, current onsite custody and recount proof, authorized disposition, replay safety and a defined Sales consumer. It must preserve actual customer-return semantics and cannot invent Accounts entries. Q2 authority details and quality/disposition configuration remain their own gates. This trace is source evidence; it is not an implemented or tested correction workflow.

The first integrated 40-file run completed with **597 passing tests and two failures, 3,036 assertions in 1,048.92 seconds**, on private PostgreSQL **18.1**, PHP **8.5.0**, database `aureuserp_20261021060107_96004_testing`; log `/private/tmp/aureuserp-recovery-full40-20261021.log`. The installer subprocess timed out while its `ALTER TABLE` waited for a relation lock held by the parent test transaction. The parent had read native tables to compare posting counts before installation. The test now releases that read-only transaction before invoking the installer, retains all installation and posting-count assertions, and restores a transaction in `finally` for normal test teardown. Increasing the timeout would not correct this self-blocking boundary. The second failure was an authorization exception following the failed installation; its relationship to that failure must be established by rerunning the affected tests. The test-boundary correction passed source review and Pint. This failed integrated run is not an acceptance pass.

The four-file focused rerun did not complete: `/private/tmp/aureuserp-recovery-logistics-focused-20261021.log` records a PHP **300-second execution-limit fatal**, with no test summary, on private PostgreSQL **18.1**, PHP **8.5.0**, database `aureuserp_20261021061940_24209_testing`. Read-only process samples located the parent in Symfony Process waiting for the installer, and the installer waiting for nested Shield permission generation. No PostgreSQL blocker was present at that stage. `AdminPanelProvider::panel()` sets a 300-second limit even during CLI panel discovery, overriding the runner's unlimited CLI setting. Dependency installers recursively repeat full permission generation. These observations identify an installer performance and CLI-boundary follow-up; they do not establish that every permission-generation invocation hung. Skipping dependency installers solely because an installed marker exists could omit pending dependency migrations, so that shortcut was not applied. No timeout increase, installer bypass or assertion removal was used. The original integrated ordering is being rerun with the read-transaction fix; even a clean result would not resolve cold-install performance.

The exact ordered integrated selection is reproducible with the existing isolated runner:

```bash
rtk proxy bash scripts/run_logistics_phase2a_tests.sh \
  plugins/webkul/sales/tests/Feature/Workflows/QuantityCommitmentTest.php \
  plugins/webkul/sales/tests/Feature/Workflows/OrderConfirmationIntegrityTest.php \
  plugins/webkul/sales/tests/Feature/Workflows/OrderConfirmationConcurrencyTest.php \
  plugins/webkul/sales/tests/Feature/Workflows/IssuedQuantityReductionTest.php \
  plugins/webkul/sales/tests/Feature/Workflows/OneStepSaleOrderTest.php \
  plugins/webkul/sales/tests/Feature/Workflows/OrderInvoicingTest.php \
  plugins/webkul/sales/tests/Feature/API/V1/OrderTest.php \
  plugins/webkul/sales/tests/Feature/Filament/OrderResourceTest.php \
  plugins/webkul/sales/tests/Feature/Filament/QuotationResourceTest.php \
  plugins/webkul/inventories/tests/Feature/Workflows/OperationResultReplayTest.php \
  plugins/webkul/inventories/tests/Feature/Workflows/StockMutationPreconditionTest.php \
  plugins/webkul/inventories/tests/Feature/Workflows/StockDeltaAtomicityTest.php \
  plugins/webkul/inventories/tests/Feature/Workflows/TransferIntegrityTest.php \
  plugins/webkul/inventories/tests/Feature/Workflows/QuantityTest.php \
  plugins/webkul/inventories/tests/Feature/Workflows/ScrapTest.php \
  plugins/webkul/inventories/tests/Feature/API/V1/DeliveryTest.php \
  plugins/webkul/inventories/tests/Feature/API/V1/ReceiptTest.php \
  plugins/webkul/inventories/tests/Feature/API/V1/InternalTransferTest.php \
  plugins/webkul/inventories/tests/Feature/API/V1/DropshipTest.php \
  plugins/webkul/manufacturing/tests/Feature/Workflows/ManufacturingOrderTest.php \
  plugins/webkul/accounts/tests/Feature/Workflows/ReconciliationIntegrityTest.php \
  plugins/webkul/accounts/tests/Feature/Workflows/PaymentCorrectionTest.php \
  plugins/webkul/accounts/tests/Feature/Workflows/PaymentConcurrencyTest.php \
  plugins/webkul/accounts/tests/Feature/Workflows/MoveLifecycleTest.php \
  plugins/webkul/accounts/tests/Feature/Workflows/CompanyCurrencyGuardTest.php \
  plugins/webkul/accounts/tests/Feature/Filament/PaymentResourceTest.php \
  plugins/webkul/accounts/tests/Feature/Filament/InvoiceResourceTest.php \
  plugins/webkul/accounts/tests/Feature/Filament/RefundResourceTest.php \
  plugins/webkul/accounts/tests/Feature/Filament/BillResourceTest.php \
  tests/Feature/Recon/MatchAccountingIntegrationTest.php \
  tests/Feature/Recon/ReconciliationControlsTest.php \
  plugins/shafique/logistics/tests/Feature/FoundationTest.php \
  plugins/shafique/logistics/tests/Feature/RemovalTest.php \
  plugins/shafique/logistics/tests/Feature/SuspensionCutoffTest.php \
  plugins/shafique/logistics/tests/Feature/EvidenceStoreTest.php \
  plugins/shafique/logistics/tests/Feature/CommandIdentityTest.php \
  plugins/shafique/logistics/tests/Feature/ScopedApprovalTest.php \
  plugins/shafique/logistics/tests/Feature/OutboundTripWorkflowTest.php \
  plugins/shafique/logistics/tests/Feature/TripWorkspaceTest.php \
  plugins/shafique/logistics/tests/Feature/TripRecoveryTest.php
```

**Late rollback-history correction.** While the integrated run continued, source review found that `down()` could remove accepted reservation snapshots when there were no recovery events and the original native lines still existed. Version 2 load hashes include those snapshots; a surviving current native line cannot reconstruct their historical values. A seventh recovery test reproduced this defect on private PostgreSQL **18.1**, PHP **8.5.0**, database `aureuserp_20261021065349_63393_testing`: **six tests passed and one failed, 40 assertions in 135.52 seconds**, `/private/tmp/aureuserp-recovery-rollback-red-20261021.log`. The migration now also refuses rollback if any load item has a non-null accepted snapshot. The original integrated process had already registered its 599 tests before this seventh case was added; it cannot be claimed to have run that case. A separate recovery-file run verifies the late guard. Forward workflow behavior did not change.

The final recovery-file run passed **all seven tests and 43 assertions, with no failures or skips, in 18.44 seconds**, on private PostgreSQL **18.1**, PHP **8.5.0**, database `aureuserp_20261021065642_68944_testing`; `/private/tmp/aureuserp-recovery-rollback-green-20261021.log`. It verifies unused-claim release and replay, permission revocation, physical-unloading confirmation, stale revision rejection, cancellation and native reservation release without deleting accepted history, replacement-trip protection, admin recovery during suspension, trip ownership, and refusal to discard accepted snapshot hash inputs during rollback. The runner removed its private cluster. Pint passed for the late migration and test changes; their final source review found no further concrete defect. No new two-connection recovery race was added.

**Integrated result and development application.** The rerun of the original ordered 40-file selection passed **599 tests and 3,076 assertions, with no failures or skips, in 2,562.96 seconds**. The wrapper returned exit zero after cleanup (2,575 seconds elapsed). It used private PostgreSQL **18.1**, PHP **8.5.0**, database `aureuserp_20261021062638_31343_testing`; log `/private/tmp/aureuserp-recovery-full40-retest-20261021.log`. Installation and the later authorization checks passed after the read-transaction correction. This integrated process excludes the subsequently added rollback assertion; the final seven-test run above covers that assertion and the final rollback guard. The long integrated duration and the separately failed cold installer run remain explicit performance evidence; this pass does not repair cold installation or establish full release acceptance.

A fresh read-only preflight again confirmed local environment `local`, development database `aureuserp` on PostgreSQL **18.1**, no recovery migration record or target objects, and zero trips, load items and issue batches. Only `2026_10_21_000100_add_trip_recovery_history.php` was then applied using Artisan's explicit file path. Postflight found exactly one ledger row, the recovery table, a JSONB snapshot column, the enabled immutable-history trigger, and removal of the temporary reservation-line foreign key. Operational counts remain zero. Company 1 still has retention policy revision 1 for 2,557 days; the Admin role still has 24 Logistics permissions. No activity-control row was created, so Logistics remains suspended by default. No application-wide migration, plugin reinstallation, seeding, permission grant, operational record creation or activity resumption ran.

The signed-in Admin browser opened `/admin/logistics/trips` successfully with readable labels and the empty trip state. `/admin/module-settings` showed Suspended and unconfigured dispatch rules. Actual recovery actions were verified through private-database Livewire tests; no real trip was invented for a browser demonstration. Final Pint, the 13-document plan validator and Git whitespace checks passed. No commit or push was made. Next work remains the separate Inventory onsite-correction and Sales fulfillment contract, followed by departure and verified delivery acceptance; actual business configuration, quality integration, both remaining modes and real-document acceptance still gate the all-four-mode first live pilot.

### Installer traversal and console boundary — 2026-10-21

The installer now visits shared dependencies once within each top-level installation. It still checks pending migrations for already installed dependencies, starts a fresh traversal for a later installation, propagates migration and seeder failures, and clears traversal state after failures or cycles. Permission generation and application-cache refresh run once at the root. Permission generation uses Symfony Process with a 60-second wall-clock timeout instead of depending on an optional system timeout utility. Console panel discovery no longer overwrites the caller's PHP execution limit. The existing web-panel limit remains unchanged. This is not an overall installer deadline; permission-generation errors still follow the existing warning behavior.

Focused verification used PHP **8.4.21** and private PostgreSQL **18.1**. The initial two-file selection (`PluginInstallTraversalTest.php` and Logistics `FoundationTest.php`) completed with **37 passing tests and one failure, 208 assertions in 14.92 seconds**, database `aureuserp_20261021081249_70744_testing`, log `/private/tmp/aureuserp-installer-focused-20261021.log`. The failure was the new test's attempt to resolve a service provider through the container; all Logistics foundation cases passed. The fixture now constructs the provider with the application explicitly. Only the affected traversal file was rerun: **four tests passed, 23 assertions in 2.07 seconds**, database `aureuserp_20261021081415_75536_testing`, log `/private/tmp/aureuserp-installer-final-20261021.log`. Both private clusters were removed by the runner. There is no new clean two-file or combined-suite result after that fixture correction, and no forced subprocess-timeout test was run.

The accompanying working-tree review found source-traced blockers in stock release after a physical count, transparent push-rule completion, evidence disposal and legal holds, and rollback of populated historical tables. These findings remain open; passing installer tests do not establish that the Logistics journeys are ready. Complete the bounded fixes and their focused regression checks before spending time on another combined run. No application database changes, commit or push were performed during this review.


## 2026-10-21 Stock release, transparent routing, evidence and historical data safeguards

This change addresses the bounded H1, H2, H3 and H4 review findings and the related missing-evidence disposal finding M6. The other Accounts, command-identity, allocation-screen, receipt-time and PHP-platform findings from the working-tree audit remain separate work. No broader acceptance or pilot approval is implied.

**Stock release.** Inventory now provides a controlled-issue sufficiency check under its existing product, operation, movement and stock-row locks. Logistics invokes it before applying counted quantities and again before line settlement. It checks the exact Company, product, source location, lot and package balance; the current settlement writer supports Company-owned stock, so a restrictive partner-owned movement fails closed. A delivery customer's `MoveLine.partner_id` is not treated as stock ownership. The check converts line-unit counts into product stock units, aggregates quantities sharing one balance, and preserves quantities reserved for other movements. It compares balances at their four-decimal storage precision rather than product-unit rounding, which could otherwise hide a small shortage. Native workflows outside the controlled Logistics issue retain their existing negative-stock policy. A rejected release rolls back approval consumption, native effects and command results; its existing verified load can be reconsidered after the discrepancy is corrected.

**Transparent routing.** Move completion applies push rules after backorder splitting but before stock settlement and the final movement state. Transparent rules therefore select the actual destination and putaway bin before any stock is moved. Ordinary chained operations still receive stock reservations through the existing downstream-reservation step after their source completes. Terminal-history protection remains enabled, and replay returns the original saved result.

**Evidence disposal.** The disposal service must own its database transactions; it refuses an existing caller transaction before changing storage or history, because savepoints cannot provide an independently committed deletion record. A durable pending attempt precedes storage work. A second transaction locks the same evidence record used for legal holds, checks current permissions and holds, verifies the original object, and retains the lock through deletion and the completion record. A hold accepted before that deletion phase prevents deletion; a new hold cannot claim to preserve already disposed bytes. Replays require the original actor, reviewer and reason. An intact object after a failed deletion can be retried. Missing or corrupt bytes, including absence after deletion succeeded but its completion record failed, create an integrity incident and remain unresolved; absence alone never proves approved disposal. Such incidents need investigation, not a fabricated successful result. Disposal does not run automatically at retention expiry.

The evidence lifecycle and legal-hold lookups now replace the relationship's ascending order when selecting the latest event. Previously, appending `latest()` retained the ascending order and could read the first event instead. Released and subsequently replaced legal holds are now evaluated correctly. The new service errors use the existing translation structure. Disposal fixtures use separate synthetic Companies so committed test data cannot configure the seeded Company's retention policy.

**Historical data rollback.** Six owning migrations now lock and check their affected history tables in the same transaction before removing any column, constraint, trigger, function or table. Populated evidence and retention policies, command and approval history, Inventory operation results, Sales quantity commitments, trip execution history and dispatch history require forward recovery. Empty isolated schemas can still reverse all six migrations in dependency order. These changes affect rollback behavior only; no forward schema change or application-database migration was performed.

**Verification and review.** Regression-first PostgreSQL runs reproduced full-load release after a count reduced stock, transparent destination changes being rejected after completion, disposal bypassing a newly accepted hold, missing evidence being marked disposed, and populated rollback attempting destructive DDL. Additional checks cover non-default units, competing reservations, failed storage deletion, replay, an interrupted completion record, released and replaced holds, nested-transaction refusal, and empty-schema rollback. Independent source reviews covered stock/routing, evidence lifecycle and transaction boundaries, and the six rollback guards. The evidence review's transaction-boundary and fixture-isolation findings were corrected before final validation.

Focused verification used PHP **8.4.21** and private PostgreSQL **18.1**. The final stock/routing journey file passed **five tests and 71 assertions in 11.60 seconds**, including the four-decimal shortage regression; `/private/tmp/aureus-stock-precision-green-20261021.log`. The latest focused stock-concurrency and evidence selection passed **24 tests and 229 assertions in 21.80 seconds**; `/private/tmp/aureus-safeguards-refined-focused-20261021.log`. Its stock race uses separate worker processes and a database-lock barrier during the final physical-count application; its evidence race uses distinct PostgreSQL sessions and a bounded lock timeout during deletion. The stock race exercises the Inventory safeguard directly; the journey tests verify its Logistics integration. These are not claims of operational-document acceptance or browser validation.

The first combined attempt was deliberately interrupted when the final precision check showed that product-unit rounding could hide a `0.0001` stock shortage. Its log `/private/tmp/aureus-safeguards-combined-20261021.log` has no acceptance summary. That case was reproduced red, corrected, checked green and reviewed before starting the completed combined selection. There was no timeout increase or assertion weakening.

The completed 16-file combined selection used private PostgreSQL **18.1**, PHP **8.4.21**, database aureuserp_20261021094058_56761_testing. It finished with **315 passing tests and two failures, 1,636 assertions in 220.25 seconds**; /private/tmp/aureus-safeguards-combined-final-20261021.log. The two failures were both in TwoStepDeliveryTest.php: one warehouse fixture collided with an existing Company/name after a Faker generator reset, and one cancellation assertion still expected the older move error instead of the saved terminal-result conflict. No stock/routing/evidence/history safeguard regression failed in that selection. This combined result is not described as an entirely green run.

The warehouse factory now adds a UUID to generated names, so uniqueness does not depend on one Faker process's memory. The completed-shipping cancellation test now expects the existing saved-result conflict and additionally verifies that completed state, saved result identity and both stock balances remain unchanged. A separate source review found no issue in these fixture/assertion corrections. Only the affected two-step delivery file is rerun after them; the full selection is not repeated for these test-only changes.

The final affected-file rerun passed **36 tests and 139 assertions, with no failures or skips, in 27.50 seconds**, on private PostgreSQL **18.1**, PHP **8.4.21**, database aureuserp_20261021094621_71314_testing; /private/tmp/aureus-two-step-delivery-final-20261021.log. The runner removed its private cluster. The complete 16-file selection was not rerun after those test-only corrections; the reported combined and focused results remain distinct. Pint was applied only to task-owned PHP files. Final Git whitespace checks passed, the original index and HEAD were preserved, and no unrelated files were changed. No commit, push, application-database migration, operational record creation or activity resumption was performed. Remaining audit findings and the all-four-mode pilot gates remain open.
