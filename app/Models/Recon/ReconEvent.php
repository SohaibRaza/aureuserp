<?php

namespace App\Models\Recon;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReconEvent extends Model
{
    protected $table = 'recon_events';

    public $timestamps = false;

    protected $fillable = [
        'company_id', 'reconciliation_id', 'actor_id', 'event', 'payload', 'created_at',
    ];

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class, 'reconciliation_id');
    }

    protected function casts(): array
    {
        return [
            'payload'    => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            $event->company_id ??= $event->reconciliation()->value('company_id');
        });

        static::updating(function (): void {
            throw new \RuntimeException('recon_events is append-only.');
        });

        static::deleting(function (): void {
            throw new \RuntimeException('recon_events is append-only.');
        });
    }
}
