<?php

namespace App\Models\Recon;

use App\Enums\Recon\ReconcilingItemClassification;
use App\Enums\Recon\ReconcilingItemSide;
use App\Enums\Recon\ReconcilingItemStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Account\Models\MoveLine;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

class ReconcilingItem extends Model
{
    protected $table = 'recon_reconciling_items';

    protected $fillable = [
        'company_id', 'reconciliation_id', 'originating_reconciliation_id',
        'resolved_reconciliation_id', 'statement_line_id', 'move_line_id', 'side',
        'classification', 'amount', 'origin_period_end', 'status', 'owner_id',
        'evidence', 'notes', 'resolved_at', 'resolved_by',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class);
    }

    public function originatingReconciliation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class, 'originating_reconciliation_id');
    }

    public function resolvedReconciliation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class, 'resolved_reconciliation_id');
    }

    public function statementLine(): BelongsTo
    {
        return $this->belongsTo(StatementLine::class);
    }

    public function moveLine(): BelongsTo
    {
        return $this->belongsTo(MoveLine::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    protected function casts(): array
    {
        return [
            'side'              => ReconcilingItemSide::class,
            'classification'    => ReconcilingItemClassification::class,
            'amount'            => 'decimal:4',
            'origin_period_end' => 'date',
            'status'            => ReconcilingItemStatus::class,
            'evidence'          => 'array',
            'resolved_at'       => 'datetime',
        ];
    }
}
