<?php

namespace App\Models\Recon;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Account\Models\BankStatementLine as BaseBankStatementLine;

/**
 * The plugin model does not declare its table name (Laravel would guess
 * "bank_statement_lines"). This proxy pins the real table while inheriting
 * the plugin model's behavior.
 */
class StatementLine extends BaseBankStatementLine
{
    protected $table = 'accounts_bank_statement_lines';

    protected $fillable = [
        'sort', 'journal_id', 'company_id', 'statement_id', 'partner_id',
        'currency_id', 'foreign_currency_id', 'creator_id', 'account_number',
        'partner_name', 'transaction_type', 'payment_reference', 'internal_index',
        'transaction_details', 'amount', 'amount_currency', 'is_reconciled',
        'amount_residual', 'move_id', 'transaction_date', 'value_date', 'external_id',
        'import_batch_id', 'source_fingerprint', 'transaction_code', 'import_status',
        'duplicate_of_statement_line_id', 'source_payload',
        'terminal_treatment', 'terminal_reference_type', 'terminal_reference_id',
        'terminal_treatment_reason', 'terminal_treatment_at', 'terminal_treatment_by',
    ];

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ReconImportBatch::class, 'import_batch_id');
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_statement_line_id');
    }

    protected function casts(): array
    {
        return [
            'is_reconciled'         => 'boolean',
            'transaction_date'      => 'date',
            'value_date'            => 'date',
            'source_payload'        => 'array',
            'terminal_treatment_at' => 'datetime',
        ];
    }
}
