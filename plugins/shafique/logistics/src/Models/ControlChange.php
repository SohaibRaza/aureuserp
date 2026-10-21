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
        'revision',
        'actor_id',
        'old_value',
        'new_value',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'revision'   => 'integer',
            'old_value'  => 'boolean',
            'new_value'  => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function save(array $options = []): bool
    {
        if ($this->exists && $this->isDirty()) {
            throw new LogicException(__('logistics::settings.history_immutable'));
        }

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        if ($this->exists) {
            throw new LogicException(__('logistics::settings.history_immutable'));
        }

        return parent::delete();
    }

    protected function incrementOrDecrement(mixed $column, mixed $amount, mixed $extra, mixed $method): never
    {
        throw new LogicException(__('logistics::settings.history_immutable'));
    }

    protected function incrementOrDecrementEach(array $columns, array $extra, string $method): never
    {
        throw new LogicException(__('logistics::settings.history_immutable'));
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
