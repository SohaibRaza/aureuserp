<?php

namespace Webkul\Inventory\Support;

use Illuminate\Support\Collection;
use Webkul\Inventory\Models\Move;
use Webkul\Inventory\Models\Operation;

interface StockMutationPrecondition
{
    /**
     * @param  Collection<int, Operation>  $operations
     * @param  Collection<int, Move>  $moves
     * @param  array<string, mixed>  $changes
     */
    public function assertAllowed(string $action, Collection $operations, Collection $moves, array $changes): void;
}
