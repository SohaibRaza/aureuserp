<?php

namespace Shafique\Logistics\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ScopedApproval extends Model
{
    protected $table = 'logistics_scoped_approvals';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'limits'           => 'array',
            'scope_id'         => 'integer',
            'scope_revision'   => 'integer',
            'control_revision' => 'integer',
            'valid_until'      => 'immutable_datetime',
            'created_at'       => 'immutable_datetime',
        ];
    }

    public function events()
    {
        return $this->hasMany(ApprovalEvent::class, 'approval_id')->orderBy('id');
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException(__('logistics::settings.approval.immutable')));
        static::deleting(fn (): never => throw new LogicException(__('logistics::settings.approval.immutable')));
    }
}
