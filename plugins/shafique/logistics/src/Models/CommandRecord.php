<?php

namespace Shafique\Logistics\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class CommandRecord extends Model
{
    protected $table = 'logistics_command_records';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'result'           => 'array',
            'control_revision' => 'integer',
            'created_at'       => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException(__('logistics::settings.command.immutable')));
        static::deleting(fn (): never => throw new LogicException(__('logistics::settings.command.immutable')));
    }
}
