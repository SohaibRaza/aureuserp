<?php

namespace App\Services\Recon;

use App\Models\Recon\BankReconciliation;
use App\Models\Recon\ReconImportBatch;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Provider-neutral boundary: callbacks may stage data, but only accept() imports it. */
class FeedIngestionService
{
    public function __construct(
        private readonly StatementImporter $importer,
        private readonly ReconciliationMutationGuard $mutationGuard,
    ) {}

    public function stage(BankReconciliation $reconciliation, array $payload): ReconImportBatch
    {
        $this->mutationGuard->assert($reconciliation, lockedMessage: 'Bank-feed data can only be staged for an editable reconciliation.');
        if ((int) ($payload['company_id'] ?? $reconciliation->company_id) !== (int) $reconciliation->company_id
            || (int) ($payload['journal_id'] ?? $reconciliation->journal_id) !== (int) $reconciliation->journal_id) {
            throw new InvalidArgumentException('Provider batch is outside the reconciliation account or company.');
        }
        if (empty($payload['event_id']) || ! array_key_exists('complete', $payload)) {
            throw new InvalidArgumentException('Provider batch requires an event_id and completeness flag.');
        }

        $payloadHash = hash('sha256', json_encode(
            $this->canonicalize($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));

        return DB::transaction(function () use ($reconciliation, $payload, $payloadHash): ReconImportBatch {
            $existing = ReconImportBatch::query()
                ->where('company_id', $reconciliation->company_id)
                ->where('journal_id', $reconciliation->journal_id)
                ->where('source_type', 'bank_feed')
                ->where('source_identifier', (string) $payload['event_id'])
                ->lockForUpdate()
                ->first();
            if ($existing) {
                if ((int) $existing->reconciliation_id !== (int) $reconciliation->id) {
                    throw new InvalidArgumentException('Provider event identity belongs to another reconciliation.');
                }
                if (($existing->source_metadata['payload_hash'] ?? null) !== $payloadHash) {
                    throw new InvalidArgumentException('Provider event identity was reused with different content.');
                }

                return $existing;
            }

            return ReconImportBatch::query()->create([
                'company_id'          => $reconciliation->company_id,
                'journal_id'          => $reconciliation->journal_id,
                'currency_id'         => $reconciliation->currency_id,
                'reconciliation_id'   => $reconciliation->id,
                'status'              => 'staged',
                'source_type'         => 'bank_feed',
                'source_identifier'   => (string) $payload['event_id'],
                'source_storage_path' => 'provider:recon_import_batches.source_metadata',
                'account_identifier'  => (string) ($payload['account_identifier'] ?? $reconciliation->bankAccount?->account_number),
                'currency_code'       => strtoupper((string) ($payload['currency_code'] ?? $reconciliation->currency?->code)),
                'source_metadata'     => ['payload' => $payload, 'payload_hash' => $payloadHash, 'staged_by' => Auth::id()],
                'parser_version'      => (string) ($payload['parser_version'] ?? 'provider-neutral-v1'),
                'imported_by'         => Auth::id(),
            ]);
        });
    }

    public function accept(ReconImportBatch $staged): ReconImportBatch
    {
        $staged = $staged->fresh();
        if ($staged && in_array($staged->status, ['validated', 'quarantined'], true) && ($acceptedId = $staged->source_metadata['accepted_batch_id'] ?? null)) {
            return ReconImportBatch::query()->findOrFail($acceptedId);
        }
        if (! $staged || $staged->status !== 'staged') {
            throw new InvalidArgumentException('Only staged provider batches can be accepted.');
        }
        $payload = $staged->source_metadata['payload'] ?? [];
        if (($payload['complete'] ?? false) !== true) {
            throw new InvalidArgumentException('Provider batch is incomplete and cannot be accepted.');
        }

        $reconciliation = $staged->reconciliation()->firstOrFail();
        $this->mutationGuard->assert($reconciliation, lockedMessage: 'Bank-feed data can only be accepted into an editable reconciliation.');
        $accepted = $this->importer->importCsv(
            $reconciliation,
            $payload['rows'] ?? [],
            (string) ($payload['opening_balance'] ?? '0'),
            (string) ($payload['closing_balance'] ?? '0'),
            (string) ($payload['filename'] ?? 'bank-feed.csv'),
            (string) $staged->source_identifier,
            options: [
                'source_type'               => 'bank_feed',
                'source_storage_path'       => 'provider:recon_import_batches.source_metadata',
                'parser_version'            => (string) ($payload['parser_version'] ?? 'provider-neutral-v1'),
                'account_identifier'        => (string) ($payload['account_identifier'] ?? $reconciliation->bankAccount?->account_number),
                'currency_code'             => (string) ($payload['currency_code'] ?? $reconciliation->currency?->code),
                'statement_start_date'      => $payload['statement_start_date'] ?? null,
                'statement_end_date'        => $payload['statement_end_date'] ?? null,
                'statement_date'            => $payload['statement_date'] ?? null,
                'control_debit_total'       => $payload['control_debit_total'] ?? null,
                'control_credit_total'      => $payload['control_credit_total'] ?? null,
                'control_transaction_count' => $payload['control_transaction_count'] ?? null,
                'source_metadata'           => ['provider_event_id' => (string) $staged->source_identifier],
            ],
        );
        $staged->update([
            'status'          => $accepted->status === 'accepted' ? 'validated' : 'quarantined',
            'source_metadata' => [...$staged->source_metadata, 'accepted_batch_id' => $accepted->id],
        ]);

        return $accepted;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map($this->canonicalize(...), $value);
        }

        ksort($value);

        return array_map($this->canonicalize(...), $value);
    }
}
