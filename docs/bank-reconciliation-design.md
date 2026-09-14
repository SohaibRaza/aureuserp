# Bank Reconciliation — Design Document

> **Historical design warning (2026-09-15):** This document records the original 2026-09-07 design and is not the canonical description of the completed U1-U18 architecture. In particular, its candidate-line and match-accounting sections predate the decision to match posted liquidity lines and to keep allocation-only bank clearing separate from AR/AP partial/full reconciliation. Use [the accounting specification](bank-reconciliation-accounting-specification.md), [architecture](bank-reconciliation-architecture.md), and [ADRs](adr/README.md) for current decisions. The implementation plan remains the requirement/change history.

**Status:** Historical design; retained for traceability · **Date:** 2026-09-07
**Scope:** Full bank reconciliation for AureusERP, built in the app namespace (no `plugins/webkul/*` edits), integrated with the existing Odoo-style ledger primitives (`accounts_account_moves`, `accounts_account_move_lines`, `accounts_partial_reconciles`, `accounts_full_reconciles`, `accounts_bank_statements[_lines]`).

---

## 1. Standard accounting principles applied

1. **Independence check (the point of the exercise).** The bank's view of the account (statement) must be independently agreed to the company's ledger view (the bank GL account). Difference = timing items (outstanding checks, deposits in transit) or errors/adjustments. The reconciliation *proves* both.
2. **Statement balance math.** `balance_end_real` (bank's closing) must equal `balance_start + Σ(statement line amounts)`. Ledger side: GL balance of the bank account at statement end-date = reconciled lines ± unreconciled ledger items. The **difference of the day** = `balance_end_real − (balance_start + Σ lines)` must be 0 before completion, else the statement is arithmetically invalid.
3. **Every adjustment is a real journal entry.** Bank fees, interest, direct debits, NSF checks, errors — none are "flags". Each posts a proper move in the bank journal (debit/credit bank account vs the correct counterpart: expense account for fees, income for interest, receivable for NSF reversal, etc.). Book balance moves; bank balance doesn't.
4. **No touching of the bank account for timing items.** Deposits in transit and outstanding checks exist in the *ledger* but not the *statement*. They need no entries — they simply remain unreconciled on the ledger side until they clear.
5. **Amounts must net exactly.** A match (1-1, 1-N, N-1, N-M) is only valid when Σ debits−credits of the matched ledger side equals the statement line amount (within a configurable tolerance for rounding, default 0.00).
6. **Immutability after finalization.** A completed reconciliation is locked: lines can't be un-matched, statements can't be edited, adjustments can't be added. Corrections require **reopen** — a permissioned, logged action that reverts to in-progress without deleting history.
7. **One statement per journal per period, sequential.** `balance_start` of statement N+1 must equal `balance_end_real` of statement N (continuity check).
8. **Complete audit trail.** Every action — import, auto-match, manual match, unmatch, adjustment creation, finalization, reopen — is recorded (actor, timestamp, payload) in an append-only events table.

## 2. Data model (all new tables in `recon_` namespace, app migrations)

| Table | Purpose |
|---|---|
| `recon_bank_reconciliations` | One reconciliation session per (journal, statement). Fields: `journal_id`, `statement_id` (nullable until statement imported), `company_id`, `creator_id`, `status` (`draft|in_progress|review|completed|reopened`), `balance_start`, `balance_end_real` (book copy), `gl_balance_start`, `gl_balance_end`, `difference`, `completed_at`, `completed_by`, `reopened_at`, `reopened_by`, `reopen_reason`. |
| `recon_matches` | A matching group: `reconciliation_id`, `status` (`proposed|confirmed|rejected`), `match_type` (`auto|manual`), `amount`, `notes`. |
| `recon_match_lines` | Participation of one line (statement line OR move line) in a match: `match_id`, `lineable_type` (`statement_line|move_line`), `lineable_id`, `side` (`bank|ledger`), `amount_applied`. |
| `recon_adjustments` | Adjustment entries: `reconciliation_id`, `type` (`bank_fee|interest|direct_charge|nsf|fx_difference|correction|other`), `amount`, `counterpart_account_id`, `move_id` (posted), `statement_line_id` (nullable — may be attached to an imported line), `notes`, `created_by`. |
| `recon_events` | Append-only audit: `reconciliation_id`, `actor_id`, `event` (`imported|auto_matched|match_confirmed|match_rejected|unmatched|adjustment_posted|completed|reopened|...`), `payload` (JSON), `created_at`. |
| `recon_import_batches` | Statement file imports: `journal_id`, `filename`, `format` (`csv|mt940|camt`), `lines_total`, `lines_imported`, `lines_duplicate`, `statement_id`, `error_report` (JSON), `imported_by`. |

Existing tables used as-is (no alteration): `accounts_bank_statements` (`balance_start`, `balance_end`, `balance_end_real`, `is_completed`), `accounts_bank_statement_lines` (`amount`, `is_reconciled`, `partner_name`, `payment_reference`, `transaction_details` JSON, `move_id`), `accounts_account_move_lines` (`reconciled`, `amount_residual`, `full_reconcile_id`), `accounts_partial_reconciles`, `accounts_full_reconciles`.

## 3. Candidate ledger lines (what can be matched)

Ledger candidates for a bank-journal reconciliation are **receivable/payable/liquidity move lines** that are:
- posted, in the bank journal OR counterpart journals, dated ≤ statement end-date,
- on accounts marked reconcilable (receivable/payable) with `amount_residual != 0`, **plus** bank-journal lines not yet `reconciled`,
- not already part of a completed reconciliation.

This covers: customer payments deposited (receivable lines), vendor checks issued (payable lines), prior bank-journal entries (fees/interest/transfers), unapplied deposits.

## 4. Matching engine

### 4.1 Scoring (auto-match)
Each (statement line, ledger line) pair scores on:
- **Amount**: exact (weight 10) / within tolerance (6).
- **Reference**: `payment_reference` vs move `ref`/`payment_reference`/line `name` normalized (strip non-alphanumerics, lowercase) — weight 8.
- **Partner**: `partner_id`/`partner_name` vs move partner — weight 4.
- **Date proximity**: ≤ 5 days (weight 2) — deposits in transit/late clearing.
- **Check number**: extracted from reference vs `check_number` in transaction_details — weight 6.

Auto-match threshold: score ≥ 12 with amount exact + (reference OR partner) → auto-confirm. 8–12 → propose (review queue). < 8 → unmatched.

### 4.2 Multi-line matching
- **1-N / N-1**: manual selection in the workspace; engine validates Σ(amounts) equals the single-side amount within tolerance before allowing confirmation. E.g., one deposit covering 3 customer invoices (N-1), or one statement fee line matching two ledger lines (1-N).
- **N-M (batch)**: allowed for same-currency groups where total bank = total ledger.
- **Duplicates**: importer detects identical (date, amount, reference) within a statement and against prior statements → marks `is_duplicate` in transaction_details, imports but flags; matching UI warns before confirming a flagged line.

### 4.3 Confirmation effects
On confirm: statement line(s) `is_reconciled = true`; ledger move line(s) `reconciled = true`; create `accounts_partial_reconciles` rows between matched debit/credit lines (respecting sign), roll up `full_reconcile_id` when residuals hit zero; statement line `move_id` links to the counter-move when the ledger side came from another journal.

## 5. Adjustments (proper entries, posted in bank journal)

| Type | Bank GL | Counterpart | When |
|---|---|---|---|
| Bank fee | Credit | `accounts_accounts` type `expense` (configurable default: 6200 Bank Charges) | Statement shows charge not in books |
| Interest earned | Debit | `income_other` (default 4900 Interest Income) | Statement shows credit not in books |
| NSF / returned check | Credit | `asset_receivable` partner line (re-instates AR) | Customer check bounced |
| Correction (book error) | per sign | chosen account | Book recorded wrong amount |
| FX difference | per sign | `exchange` journal or chosen account | Statement vs book currency rounding |

Each adjustment creates `accounts_account_moves` (state `posted`) with balanced lines, links `move_id` onto the statement line when attached, and logs a `recon_events` row. Adjustments are reconciled like any other bank-journal line, so they consume their own statement line.

## 6. Workflow & status machine

```
draft → in_progress → review → completed ──(reopen)──→ reopened → in_progress
```
- **draft**: created (with or without statement). Statement import allowed; matching locked.
- **in_progress**: import done; auto-match runs; manual matching/adjustments allowed. Difference must be tracked live.
- **review**: matching locked; difference + exception list reviewed; can return to in_progress.
- **completed**: allowed only when (a) statement arithmetic valid (`balance_start + Σ lines = balance_end_real`), (b) difference = 0 after adjustments, (c) no unmatched statement lines, (d) no `proposed` matches. Sets statement `is_completed = true`, locks everything, stamps completed_by/at.
- **reopened**: permissioned (`recon_reopen`); requires reason; un-completes statement, keeps all history; status → in_progress.

## 7. Exceptions handling

Exception queue (derived, not stored): unmatched statement lines after auto-match, proposed matches awaiting review, duplicates flagged, statement arithmetic mismatch, stale proposals (ledger line consumed elsewhere meanwhile). Each exception blocks completion until resolved (matched, adjusted, or explicitly deferred by creating an adjustment entry).

## 8. UI/UX (Filament, app namespace)

1. **Bank Reconciliations list** (under Accounting cluster): sessions with status badges, difference column, journal filter.
2. **Reconcile workspace** (the core screen), three panes:
   - Left: **statement lines** (unmatched by default) with running balance from `balance_start`.
   - Right: **ledger candidates** (filterable by partner/amount/date/reference; search).
   - Bottom: **match basket** — multi-select lines from both sides, live Σ vs Σ validation, Confirm/Reject; proposals pre-seeded by auto-match.
   - Header: balances (bank start/end, GL start/end, reconciled total, **difference**), status actions (Start review, Complete, Reopen).
3. **Adjustment modal**: type → counterpart account (defaults per type), amount (sign-aware), notes; posts the move on save.
4. **Reports tab** per reconciliation: reconciled items, unreconciled bank lines, outstanding ledger items (deposits in transit, outstanding checks), adjustments list, difference of the day; CSV export of the full reconciliation summary.

## 9. Auditability

- `recon_events` append-only (no update/delete paths in code); UI shows a timeline.
- Move/line edits remain governed by existing `HasLogActivity` on `Move`.
- Reopen requires reason, stored in both the event and the session row.
- Import batches retain the original file's per-line result (imported/duplicate/error) for traceability.

## 10. File map (app namespace)

```
app/Models/Recon/            BankReconciliation, ReconMatch, ReconMatchLine,
                             ReconAdjustment, ReconEvent, ReconImportBatch
app/Services/Recon/          StatementImporter, MatchingEngine, MatchService,
                             AdjustmentService, FinalizationService, ReportService
app/Filament/Pages/Recon/    ListReconciliations, ReconcileWorkspace, ImportStatement (action), ReconReport
database/migrations/         2026_09_07_*_create_recon_*_table.php (6)
resources/views/filament/    pages/recon/{workspace,report}.blade.php
```

**Permissions** (Shield, generated then granted): `page_filament_recon_*` set; `recon_reopen` capability enforced in FinalizationService, granted to Admin + Accountant.
