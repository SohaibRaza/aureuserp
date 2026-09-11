<?php

namespace App\Models\Recon;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Account\Models\Journal;

class ReconImportBatch extends Model
{
    protected $table = 'recon_import_batches';

    protected $fillable = [
        'company_id', 'journal_id', 'statement_id', 'reconciliation_id', 'currency_id',
        'filename', 'original_filename', 'format', 'status', 'source_type', 'source_identifier',
        'source_storage_path', 'content_hash', 'parser_version', 'account_identifier', 'currency_code',
        'statement_start_date', 'statement_end_date', 'statement_date', 'opening_balance',
        'closing_balance', 'control_debit_total', 'control_credit_total', 'control_transaction_count',
        'source_metadata', 'lines_total', 'lines_imported', 'lines_duplicate', 'error_report',
        'imported_by', 'accepted_at', 'accepted_by',
    ];

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class, 'reconciliation_id');
    }

    public function statementLines(): HasMany
    {
        return $this->hasMany(StatementLine::class, 'import_batch_id');
    }

    protected function casts(): array
    {
        return [
            'statement_start_date' => 'date',
            'statement_end_date'   => 'date',
            'statement_date'       => 'date',
            'opening_balance'      => 'decimal:4',
            'closing_balance'      => 'decimal:4',
            'control_debit_total'  => 'decimal:4',
            'control_credit_total' => 'decimal:4',
            'source_metadata'      => 'array',
            'error_report'         => 'array',
            'accepted_at'          => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $batch): void {
            $reconciliation = $batch->reconciliation()->first(['company_id', 'currency_id']);
            $journal = Journal::query()->find($batch->journal_id);
            $batch->company_id ??= $reconciliation?->company_id ?? $journal?->company_id;
            $batch->currency_id ??= $reconciliation?->currency_id ?? $journal?->currency_id ?? $journal?->company?->currency_id;
        });
    }
}
