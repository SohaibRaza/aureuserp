<?php

namespace Shafique\Logistics\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class EvidenceLegalHold extends Model
{
    protected $table = 'logistics_evidence_legal_holds';

    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException(__('logistics::settings.evidence.history_immutable')));
        static::deleting(fn (): never => throw new LogicException(__('logistics::settings.evidence.history_immutable')));
    }
}
