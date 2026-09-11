<?php

namespace App\Services\Recon;

use App\Models\Recon\BankReconciliation;
use App\Models\Recon\ReconImportBatch;
use App\Models\Recon\StatementLine;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Webkul\Account\Models\BankStatement;

class StatementImporter
{
    public function __construct(
        private readonly ReconciliationSafety $safety,
        private readonly ReconciliationMutationGuard $mutationGuard,
        private readonly ReconciliationCalculator $calculator,
    ) {}

    /** @param array<int, array<string, mixed>> $rows @param array<string, mixed> $options */
    public function importCsv(
        BankReconciliation $reconciliation,
        array $rows,
        string $balanceStart,
        string $balanceEndReal,
        string $filename = 'pasted.csv',
        ?string $sourceIdentifier = null,
        ?string $continuityType = null,
        ?string $continuityReason = null,
        array $options = [],
    ): ReconImportBatch {
        $this->safety->assertFinancialMutationsEnabled($reconciliation->company_id);
        $this->mutationGuard->assert($reconciliation, lockedMessage: 'Statements can only be imported into an editable reconciliation.');
        $this->assertDecimal($balanceStart, 'opening balance');
        $this->assertDecimal($balanceEndReal, 'closing balance');

        $canonical = json_encode([
            'opening'                   => $balanceStart, 'closing' => $balanceEndReal, 'rows' => $rows,
            'source_type'               => $options['source_type'] ?? 'pasted_csv',
            'account_identifier'        => $options['account_identifier'] ?? null,
            'currency_code'             => $options['currency_code'] ?? null,
            'statement_start_date'      => $options['statement_start_date'] ?? null,
            'statement_end_date'        => $options['statement_end_date'] ?? null,
            'statement_date'            => $options['statement_date'] ?? null,
            'control_debit_total'       => $options['control_debit_total'] ?? null,
            'control_credit_total'      => $options['control_credit_total'] ?? null,
            'control_transaction_count' => $options['control_transaction_count'] ?? null,
            'adopt_statement_period'    => (bool) ($options['adopt_statement_period'] ?? false),
            'period_change_reason'      => $options['period_change_reason'] ?? null,
            'continuity_type'           => $continuityType,
            'continuity_reason'         => $continuityReason,
            'parser_version'            => $options['parser_version'] ?? 'mapped-csv-v2',
            'mapping'                   => $options['mapping'] ?? null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $contentHash = hash('sha256', $canonical);
        $companyId = (int) $reconciliation->company_id;
        $journalId = (int) $reconciliation->journal_id;

        if ($existing = $this->existingBatch($companyId, $journalId, $contentHash)) {
            if ((int) $existing->reconciliation_id !== (int) $reconciliation->id) {
                return $this->quarantineDuplicateFile($reconciliation, $existing, $contentHash, $filename, $sourceIdentifier, $options, $canonical);
            }

            return $existing->refresh();
        }
        if ($reconciliation->statement_id) {
            throw new RuntimeException('This reconciliation already has an active statement; import a new reconciliation instead.');
        }

        try {
            return DB::transaction(function () use ($reconciliation, $rows, $balanceStart, $balanceEndReal, $filename, $sourceIdentifier, $continuityType, $continuityReason, $options, $companyId, $journalId, $contentHash, $canonical): ReconImportBatch {
                $reconciliation = BankReconciliation::query()->lockForUpdate()->findOrFail($reconciliation->id);
                $this->mutationGuard->assert($reconciliation, lockedMessage: 'Statements can only be imported into an editable reconciliation.');
                if ($existing = $this->existingBatch($companyId, $journalId, $contentHash)) {
                    if ((int) $existing->reconciliation_id !== (int) $reconciliation->id) {
                        return $this->quarantineDuplicateFile($reconciliation, $existing, $contentHash, $filename, $sourceIdentifier, $options, $canonical);
                    }

                    return $existing->refresh();
                }
                if ($reconciliation->statement_id) {
                    throw new RuntimeException('This reconciliation already has an active statement; import a new reconciliation instead.');
                }

                $expectedAccount = (string) ($reconciliation->bankAccount?->account_number ?? $reconciliation->bank_account_id ?? $journalId);
                $expectedCurrency = strtoupper((string) $reconciliation->currency?->code);
                $sourceAccount = trim((string) ($options['account_identifier'] ?? $expectedAccount));
                $sourceCurrency = strtoupper(trim((string) ($options['currency_code'] ?? $expectedCurrency)));
                $sourceMetadata = [
                    'filename'         => $filename, 'row_count' => count($rows), 'canonical_source' => $canonical,
                    'raw_content_hash' => $options['raw_content_hash'] ?? null,
                    'encoding'         => $options['encoding'] ?? 'UTF-8', 'delimiter' => $options['delimiter'] ?? ',',
                    'mapping'          => $options['mapping'] ?? null, ...($options['source_metadata'] ?? []),
                ];
                $batch = ReconImportBatch::query()->create([
                    'company_id'          => $companyId, 'journal_id' => $journalId, 'reconciliation_id' => $reconciliation->id,
                    'filename'            => $filename, 'original_filename' => $filename, 'format' => 'csv', 'status' => 'staged',
                    'source_type'         => (string) ($options['source_type'] ?? 'pasted_csv'),
                    'source_identifier'   => $sourceIdentifier ?: $contentHash,
                    'source_storage_path' => (string) ($options['source_storage_path'] ?? 'database:recon_import_batches.source_metadata'),
                    'content_hash'        => $contentHash, 'parser_version' => (string) ($options['parser_version'] ?? 'mapped-csv-v2'),
                    'account_identifier'  => $sourceAccount, 'currency_id' => $reconciliation->currency_id,
                    'currency_code'       => $sourceCurrency, 'opening_balance' => $balanceStart, 'closing_balance' => $balanceEndReal,
                    'source_metadata'     => $sourceMetadata, 'lines_total' => count($rows), 'imported_by' => Auth::id(),
                ]);

                $errors = array_values(array_filter(array_map('strval', $options['preflight_errors'] ?? [])));
                if ($this->normalizeIdentifier($sourceAccount) !== $this->normalizeIdentifier($expectedAccount)) {
                    $errors[] = 'Statement account identifier does not match the reconciliation bank account.';
                }
                if ($sourceCurrency !== $expectedCurrency) {
                    $errors[] = 'Statement currency does not match the reconciliation currency.';
                }

                $statementStart = $this->dateOption($options, 'statement_start_date', 'statement start date', $errors) ?? $reconciliation->period_start?->toImmutable();
                $statementEnd = $this->dateOption($options, 'statement_end_date', 'statement end date', $errors) ?? $reconciliation->period_end?->toImmutable();
                $statementDate = $this->dateOption($options, 'statement_date', 'statement date', $errors) ?? $statementEnd;
                if ($statementStart && $statementEnd && $statementEnd->lt($statementStart)) {
                    $errors[] = 'Statement end date must be on or after the statement start date.';
                }
                $adoptPeriod = (bool) ($options['adopt_statement_period'] ?? false);
                $periodChanged = $statementStart && $statementEnd
                    && ($statementStart->toDateString() !== $reconciliation->period_start?->toDateString()
                        || $statementEnd->toDateString() !== $reconciliation->period_end?->toDateString());
                if ($adoptPeriod && $periodChanged && blank($options['period_change_reason'] ?? null)) {
                    $errors[] = 'A reason is required to adopt the statement period.';
                }
                if ($adoptPeriod && $periodChanged && $statementStart && $statementEnd
                    && BankReconciliation::query()
                        ->where('bank_account_id', $reconciliation->bank_account_id)
                        ->whereKeyNot($reconciliation->id)
                        ->where('status', '<>', 'cancelled')
                        ->whereDate('period_start', '<=', $statementEnd->toDateString())
                        ->whereDate('period_end', '>=', $statementStart->toDateString())
                        ->exists()) {
                    $errors[] = 'The statement period overlaps another reconciliation for this bank account.';
                }
                $validationStart = $adoptPeriod ? $statementStart : $reconciliation->period_start?->toImmutable();
                $validationEnd = $adoptPeriod ? $statementEnd : $reconciliation->period_end?->toImmutable();

                $prepared = [];
                $seen = [];
                $debit = '0.0000';
                $credit = '0.0000';
                $running = bcadd($balanceStart, '0', 4);
                if ($rows === []) {
                    $errors[] = 'The statement contains no rows.';
                }
                foreach ($rows as $index => $row) {
                    try {
                        $line = $this->prepareLine($row, $index, $reconciliation, $seen, $validationStart, $validationEnd);
                        $prepared[] = $line;
                        $running = bcadd($running, $line['amount'], 4);
                        if (bccomp($line['amount'], '0', 4) < 0) {
                            $debit = bcadd($debit, ltrim($line['amount'], '-'), 4);
                        } else {
                            $credit = bcadd($credit, $line['amount'], 4);
                        }
                    } catch (InvalidArgumentException $exception) {
                        $errors[] = 'Row '.($index + 1).': '.$exception->getMessage();
                    }
                }
                if (bccomp($running, $balanceEndReal, 4) !== 0) {
                    $errors[] = 'Statement arithmetic mismatch: opening balance plus accepted lines is '.$running.', declared closing balance is '.$balanceEndReal.'.';
                }
                $this->validateDeclaredControls($options, $debit, $credit, count($prepared), $errors);

                $prior = BankStatement::query()->where('company_id', $companyId)->where('journal_id', $journalId)
                    ->where('is_completed', true)->latest('date')->first();
                if ($prior && bccomp((string) $prior->balance_end_real, $balanceStart, 4) !== 0 && $continuityType !== 'discontinuity') {
                    $errors[] = 'Opening balance does not match the prior completed statement; classify the discontinuity before accepting.';
                }
                if ($continuityType === 'discontinuity' && blank($continuityReason)) {
                    $errors[] = 'A discontinuity requires a reason.';
                }

                $duplicateCount = 0;
                foreach ($prepared as $index => $line) {
                    $duplicate = StatementLine::query()->where('company_id', $companyId)->where('journal_id', $journalId)
                        ->where('import_status', 'accepted')
                        ->where(function ($query) use ($line): void {
                            $query->where('source_fingerprint', $line['source_fingerprint']);
                            if ($line['external_id']) {
                                $query->orWhere('external_id', $line['external_id']);
                            }
                        })->exists();
                    if ($duplicate) {
                        $duplicateCount++;
                        $errors[] = 'Row '.($index + 1).': transaction was already imported and requires review.';
                    }
                }

                $batch->update([
                    'status'               => $errors === [] ? 'validated' : 'quarantined', 'lines_imported' => 0,
                    'lines_duplicate'      => $duplicateCount, 'control_debit_total' => $debit,
                    'control_credit_total' => $credit, 'control_transaction_count' => count($prepared),
                    'error_report'         => $errors ?: null,
                    'statement_start_date' => $statementStart?->toDateString() ?? $this->minDate($prepared),
                    'statement_end_date'   => $statementEnd?->toDateString() ?? $this->maxDate($prepared),
                    'statement_date'       => $statementDate?->toDateString() ?? $this->maxDate($prepared),
                ]);
                if ($errors !== []) {
                    return $batch->refresh();
                }

                if ($adoptPeriod && $periodChanged) {
                    $previousStart = $reconciliation->period_start?->toDateString();
                    $previousEnd = $reconciliation->period_end?->toDateString();
                    $reconciliation->update(['period_start' => $statementStart, 'period_end' => $statementEnd]);
                    BankReconciliation::logEvent($reconciliation, 'statement_period_adopted', [
                        'previous_period_start' => $previousStart, 'previous_period_end' => $previousEnd,
                        'period_start'          => $statementStart->toDateString(), 'period_end' => $statementEnd->toDateString(),
                        'reason'                => trim((string) $options['period_change_reason']),
                    ]);
                }

                $statement = BankStatement::query()->create([
                    'company_id'    => $companyId, 'journal_id' => $journalId, 'name' => 'BANK/'.$reconciliation->id.'/'.$batch->id,
                    'date'          => $batch->statement_date?->toDateString() ?? $this->maxDate($prepared),
                    'balance_start' => $balanceStart, 'balance_end' => $running, 'balance_end_real' => $balanceEndReal,
                    'is_completed'  => false, 'creator_id' => Auth::id(),
                ]);
                foreach ($prepared as $line) {
                    StatementLine::query()->create([
                        ...$line, 'statement_id' => $statement->id, 'import_batch_id' => $batch->id,
                        'company_id'             => $companyId, 'journal_id' => $journalId, 'currency_id' => $reconciliation->currency_id,
                        'import_status'          => 'accepted', 'amount_residual' => $line['amount'], 'amount_currency' => $line['amount'],
                        'is_reconciled'          => false, 'creator_id' => Auth::id(),
                    ]);
                }
                $batch->update([
                    'status'          => 'accepted', 'statement_id' => $statement->id, 'accepted_at' => now(), 'accepted_by' => Auth::id(),
                    'lines_imported'  => StatementLine::query()->where('import_batch_id', $batch->id)->where('import_status', 'accepted')->count(),
                    'lines_duplicate' => 0,
                ]);
                $reconciliation->update([
                    'statement_id'      => $statement->id, 'balance_start' => $balanceStart, 'balance_end_real' => $balanceEndReal,
                    'statement_date'    => $batch->statement_date,
                    'continuity_type'   => $continuityType ?: ($prior ? 'continuous' : 'first_time'),
                    'continuity_reason' => $continuityReason,
                ]);
                $calculation = $this->calculator->calculate($reconciliation->refresh());
                $reconciliation->update([
                    'gl_balance_start' => $calculation['gl_balance_start'],
                    'gl_balance_end'   => $calculation['gl_balance_end'],
                    'difference'       => $calculation['difference'],
                ]);
                BankReconciliation::logEvent($reconciliation, 'imported', ['batch_id' => $batch->id, 'content_hash' => $contentHash]);

                return $batch->refresh();
            });
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23505'
                && str_contains($exception->getMessage(), 'recon_import_active_content_hash_unique')
                && ($existing = $this->existingBatch($companyId, $journalId, $contentHash))
                && (int) $existing->reconciliation_id === (int) $reconciliation->id) {
                return $existing->refresh();
            }

            throw $exception;
        }
    }

    private function existingBatch(int $companyId, int $journalId, string $contentHash): ?ReconImportBatch
    {
        return ReconImportBatch::query()->where('company_id', $companyId)->where('journal_id', $journalId)
            ->where('content_hash', $contentHash)->whereIn('status', ['staged', 'validated', 'accepted', 'quarantined', 'rejected'])->first();
    }

    /** @param array<string, mixed> $options */
    private function quarantineDuplicateFile(
        BankReconciliation $reconciliation,
        ReconImportBatch $original,
        string $originalContentHash,
        string $filename,
        ?string $sourceIdentifier,
        array $options,
        string $canonical,
    ): ReconImportBatch {
        $quarantineHash = hash('sha256', 'duplicate-file|'.$originalContentHash.'|'.$reconciliation->id);
        if ($existing = $this->existingBatch((int) $reconciliation->company_id, (int) $reconciliation->journal_id, $quarantineHash)) {
            return $existing;
        }

        return ReconImportBatch::query()->create([
            'company_id'           => $reconciliation->company_id,
            'journal_id'           => $reconciliation->journal_id,
            'currency_id'          => $reconciliation->currency_id,
            'reconciliation_id'    => $reconciliation->id,
            'filename'             => $filename,
            'original_filename'    => $filename,
            'format'               => 'csv',
            'status'               => 'quarantined',
            'source_type'          => (string) ($options['source_type'] ?? 'pasted_csv'),
            'source_identifier'    => $sourceIdentifier ?: $originalContentHash,
            'source_storage_path'  => (string) ($options['source_storage_path'] ?? 'database:recon_import_batches.source_metadata'),
            'content_hash'         => $quarantineHash,
            'parser_version'       => (string) ($options['parser_version'] ?? 'mapped-csv-v2'),
            'account_identifier'   => (string) ($options['account_identifier'] ?? $reconciliation->bankAccount?->account_number),
            'currency_code'        => strtoupper((string) ($options['currency_code'] ?? $reconciliation->currency?->code)),
            'statement_start_date' => $this->safeDateString($options['statement_start_date'] ?? null),
            'statement_end_date'   => $this->safeDateString($options['statement_end_date'] ?? null),
            'statement_date'       => $this->safeDateString($options['statement_date'] ?? null),
            'opening_balance'      => $original->opening_balance,
            'closing_balance'      => $original->closing_balance,
            'source_metadata'      => [
                'canonical_source'      => $canonical,
                'original_content_hash' => $originalContentHash,
                'duplicate_of_batch_id' => $original->id,
            ],
            'lines_total'     => $original->lines_total,
            'lines_imported'  => 0,
            'lines_duplicate' => $original->lines_total,
            'error_report'    => ['This statement file was already imported in reconciliation '.$original->reconciliation_id.'.'],
            'imported_by'     => Auth::id(),
        ]);
    }

    /** @param array<string, mixed> $row */
    private function prepareLine(array $row, int $index, BankReconciliation $reconciliation, array &$seen, ?CarbonImmutable $periodStart, ?CarbonImmutable $periodEnd): array
    {
        $date = $this->date($row['date'] ?? $row['transaction_date'] ?? null, 'transaction date');
        $valueDate = isset($row['value_date']) && $row['value_date'] !== '' ? $this->date($row['value_date'], 'value date') : null;
        if (($periodStart && $date->lt($periodStart)) || ($periodEnd && $date->gt($periodEnd))) {
            throw new InvalidArgumentException('transaction date is outside the reconciliation period.');
        }
        if ($valueDate && (($periodStart && $valueDate->lt($periodStart)) || ($periodEnd && $valueDate->gt($periodEnd)))) {
            throw new InvalidArgumentException('value date is outside the reconciliation period.');
        }
        $amount = $row['amount'] ?? null;
        if (! is_string($amount)) {
            throw new InvalidArgumentException('amount must be a decimal string.');
        }
        $this->assertDecimal($amount, 'amount');
        $amount = bcadd($amount, '0', 4);
        if (bccomp($amount, '0', 4) === 0) {
            throw new InvalidArgumentException('amount must not be zero.');
        }

        $reference = trim((string) ($row['reference'] ?? $row['payment_reference'] ?? ''));
        $externalId = blank($row['external_id'] ?? null) ? null : trim((string) $row['external_id']);
        $code = blank($row['transaction_code'] ?? $row['transaction_type'] ?? null) ? null : trim((string) ($row['transaction_code'] ?? $row['transaction_type']));
        $fingerprint = hash('sha256', implode('|', [
            $reconciliation->company_id, $reconciliation->journal_id, $date->toDateString(),
            $valueDate?->toDateString() ?? '', $amount, mb_strtolower($reference), $externalId ?? '', $code ?? '',
        ]));
        if (isset($seen[$fingerprint])) {
            throw new InvalidArgumentException('duplicate row in file.');
        }
        $seen[$fingerprint] = true;

        $expectedIdentity = [
            'account_identifier' => (string) ($reconciliation->bankAccount?->account_number ?? $reconciliation->bank_account_id ?? $reconciliation->journal_id),
            'currency_code'      => (string) $reconciliation->currency?->code,
        ];
        foreach ($expectedIdentity as $key => $expected) {
            if (isset($row[$key]) && $row[$key] !== '' && $this->normalizeIdentifier((string) $row[$key]) !== $this->normalizeIdentifier($expected)) {
                throw new InvalidArgumentException($key.' does not match the reconciliation.');
            }
        }

        return [
            'transaction_date'    => $date->toDateString(), 'value_date' => $valueDate?->toDateString(),
            'external_id'         => $externalId, 'source_fingerprint' => $fingerprint, 'transaction_code' => $code,
            'payment_reference'   => $reference, 'partner_name' => trim((string) ($row['partner_name'] ?? '')),
            'transaction_details' => json_encode(['row' => $index + 1], JSON_THROW_ON_ERROR),
            'source_payload'      => $row, 'amount' => $amount,
        ];
    }

    private function date(mixed $value, string $label): CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new InvalidArgumentException($label.' must use YYYY-MM-DD.');
        }
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        if (! $date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException($label.' is invalid.');
        }

        return $date;
    }

    private function dateOption(array $options, string $key, string $label, array &$errors): ?CarbonImmutable
    {
        if (! array_key_exists($key, $options) || $options[$key] === null || $options[$key] === '') {
            return null;
        }
        try {
            return $this->date($options[$key], $label);
        } catch (InvalidArgumentException $exception) {
            $errors[] = $exception->getMessage();

            return null;
        }
    }

    private function safeDateString(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }

    private function assertDecimal(string $value, string $label): void
    {
        if (! preg_match('/^-?(?:0|[1-9]\d{0,17})(?:\.\d{1,4})?$/', $value)) {
            throw new InvalidArgumentException($label.' must be a plain decimal string with up to 4 places.');
        }
    }

    private function normalizeIdentifier(string $value): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/i', '', trim($value)) ?? '');
    }

    private function validateDeclaredControls(array $options, string $debit, string $credit, int $count, array &$errors): void
    {
        foreach (['control_debit_total' => ['debit', $debit], 'control_credit_total' => ['credit', $credit]] as $key => [$label, $actual]) {
            if (! array_key_exists($key, $options) || $options[$key] === null || $options[$key] === '') {
                continue;
            }
            $declared = (string) $options[$key];
            try {
                $this->assertDecimal($declared, "statement {$label} control total");
            } catch (InvalidArgumentException $exception) {
                $errors[] = $exception->getMessage();

                continue;
            }
            if (bccomp($declared, $actual, 4) !== 0) {
                $errors[] = "Statement {$label} control total does not match the imported rows.";
            }
        }
        if (array_key_exists('control_transaction_count', $options) && $options['control_transaction_count'] !== null && $options['control_transaction_count'] !== '') {
            $declared = filter_var($options['control_transaction_count'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($declared === false) {
                $errors[] = 'Statement transaction count must be a non-negative integer.';
            } elseif ($declared !== $count) {
                $errors[] = 'Statement transaction count does not match the imported rows.';
            }
        }
    }

    private function minDate(array $lines): ?string
    {
        return $lines ? collect($lines)->min('transaction_date') : null;
    }

    private function maxDate(array $lines): ?string
    {
        return $lines ? collect($lines)->max('transaction_date') : null;
    }
}
