<?php

namespace Shafique\Logistics\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class Evidence extends Model
{
    protected $table = 'logistics_evidence';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'accepted_at'  => 'immutable_datetime',
            'retain_until' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException(__('logistics::settings.evidence.record_immutable')));
        static::deleting(fn (): never => throw new LogicException(__('logistics::settings.evidence.record_immutable')));
    }

    public function retentionPolicy()
    {
        return $this->belongsTo(EvidenceRetentionPolicy::class, 'retention_policy_id');
    }

    public function lifecycleEvents()
    {
        return $this->hasMany(EvidenceLifecycleEvent::class, 'evidence_id')->orderBy('id');
    }

    public function legalHoldEvents()
    {
        return $this->hasMany(EvidenceLegalHold::class, 'evidence_id')->orderBy('id');
    }
}
