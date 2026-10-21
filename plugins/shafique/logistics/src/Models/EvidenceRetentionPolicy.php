<?php

namespace Shafique\Logistics\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class EvidenceRetentionPolicy extends Model
{
    protected $table = 'logistics_evidence_retention_policies';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'approved_at' => 'immutable_datetime',
            'created_at'  => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException(__('logistics::settings.evidence.policy_immutable')));
        static::deleting(fn (): never => throw new LogicException(__('logistics::settings.evidence.policy_immutable')));
    }
}
