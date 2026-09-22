<?php

namespace Shafique\Logistics\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

class ControlChange extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'logistics_control_changes';

    protected $fillable = [
        'company_id',
        'actor_id',
        'old_value',
        'new_value',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'old_value'  => 'boolean',
            'new_value'  => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Logistics control history is immutable.'));
        static::deleting(fn () => throw new LogicException('Logistics control history is immutable.'));
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
