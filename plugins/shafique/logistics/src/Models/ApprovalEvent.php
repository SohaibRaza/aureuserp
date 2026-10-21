<?php

namespace Shafique\Logistics\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ApprovalEvent extends Model
{
    protected $table = 'logistics_approval_events';

    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException(__('logistics::settings.approval.immutable')));
        static::deleting(fn (): never => throw new LogicException(__('logistics::settings.approval.immutable')));
    }
}
