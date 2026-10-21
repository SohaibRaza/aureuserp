<?php

namespace Webkul\Inventory\Support;

use Illuminate\Support\Collection;
use LogicException;
use Webkul\Inventory\Models\Move;
use Webkul\Inventory\Models\Operation;

class StockMutationGuard
{
    public const TAG = 'inventory.stock-mutation-preconditions';

    /**
     * @param  Collection<int, Operation>  $operations
     * @param  Collection<int, Move>  $moves
     * @param  array<string, mixed>  $changes
     */
    public function hasApplicablePreconditions(string $action, Collection $operations, Collection $moves, array $changes): bool
    {
        foreach (app()->tagged(self::TAG) as $precondition) {
            if (! $precondition instanceof StockMutationPrecondition) {
                throw new LogicException('A stock mutation precondition must implement StockMutationPrecondition.');
            }

            if (! $precondition instanceof ScopedStockMutationPrecondition
                || $precondition->appliesTo($action, $operations, $moves, $changes)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, Operation>  $operations
     * @param  Collection<int, Move>  $moves
     * @param  array<string, mixed>  $changes
     */
    public function assertAllowed(string $action, Collection $operations, Collection $moves, array $changes = []): void
    {
        if ((new Move)->getConnection()->transactionLevel() === 0) {
            throw new LogicException('Stock mutation preconditions require an active transaction.');
        }

        foreach (app()->tagged(self::TAG) as $precondition) {
            if (! $precondition instanceof StockMutationPrecondition) {
                throw new LogicException('A stock mutation precondition must implement StockMutationPrecondition.');
            }

            $precondition->assertAllowed($action, $operations, $moves, $changes);
        }
    }
}
