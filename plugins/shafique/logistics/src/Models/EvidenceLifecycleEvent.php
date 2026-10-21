<?php

namespace Shafique\Logistics\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class EvidenceLifecycleEvent extends Model
{
    protected $table = 'logistics_evidence_lifecycle_events';

    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException(__('logistics::settings.evidence.history_immutable')));
        static::deleting(fn (): never => throw new LogicException(__('logistics::settings.evidence.history_immutable')));
    }
}
