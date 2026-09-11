<?php

namespace App\Models\Recon;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Account\Models\MoveLine;

class ReconMatchLine extends Model
{
    public const SIDE_BANK = 'bank';

    public const SIDE_LEDGER = 'ledger';

    public const TYPE_STATEMENT_LINE = 'statement_line';

    public const TYPE_MOVE_LINE = 'move_line';

    protected $table = 'recon_match_lines';

    protected $fillable = [
        'company_id', 'match_id', 'lineable_type', 'lineable_id', 'side', 'amount_applied',
        'reversed_at', 'reversed_by',
        'previous_ledger_residual', 'previous_ledger_residual_currency', 'applied_ledger_amount',
        'accounting_partial_id', 'accounting_reversed_at', 'accounting_reversed_by',
    ];

    public function match(): BelongsTo
    {
        return $this->belongsTo(ReconMatch::class, 'match_id');
    }

    /** Resolve the underlying statement line or move line. */
    public function resolve(): StatementLine|MoveLine|null
    {
        return match ($this->lineable_type) {
            self::TYPE_STATEMENT_LINE => StatementLine::find($this->lineable_id),
            self::TYPE_MOVE_LINE      => MoveLine::find($this->lineable_id),
            default                   => null,
        };
    }

    protected function casts(): array
    {
        return [
            'amount_applied'                    => 'decimal:4',
            'reversed_at'                       => 'datetime',
            'previous_ledger_residual'          => 'decimal:4',
            'previous_ledger_residual_currency' => 'decimal:4',
            'applied_ledger_amount'             => 'decimal:4',
            'accounting_reversed_at'            => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $line): void {
            $line->company_id ??= $line->match()->value('company_id');
        });
    }
}
