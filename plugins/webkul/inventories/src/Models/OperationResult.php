<?php

namespace Webkul\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Webkul\Support\Traits\BelongsToCompany;

class OperationResult extends Model
{
    use BelongsToCompany;

    protected $table = 'inventories_operation_results';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['intent' => 'array', 'result' => 'array', 'created_at' => 'datetime'];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw ValidationException::withMessages(['operation' => __('inventories::system.inventory-manager.result-immutable')]);
        }

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        throw ValidationException::withMessages(['operation' => __('inventories::system.inventory-manager.result-immutable')]);
    }
}
