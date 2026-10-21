<?php

namespace Webkul\Inventory\Services;

use Illuminate\Support\Collection;
use Webkul\Inventory\Enums\MoveState;
use Webkul\Inventory\Enums\ProcureMethod;
use Webkul\Inventory\Models\Move;
use Webkul\Inventory\Models\Operation;
use Webkul\Inventory\Support\StockMutationGuard;

class MoveCanceller
{
    public function cancel(Collection $moves): bool
    {
        return (new Move)->getConnection()->transaction(function () use ($moves): bool {
            $moves = Move::lockForStockMutation($moves, validateMutation: false);

            if ($moves->some(fn (Move $move) => $move->state === MoveState::DONE && ! $move->is_scraped)) {
                throw new \Exception(__('inventories::system.inventory-manager.cancel-move.already-done'));
            }

            $cancellable = $moves->filter(
                fn (Move $move) => $move->state !== MoveState::CANCELED
                    && ! ($move->state === MoveState::DONE && $move->is_scraped)
            );

            if ($cancellable->isEmpty()) {
                return true;
            }
            $operations = Operation::query()->whereKey($cancellable->pluck('operation_id')->filter()->unique())->orderBy('id')->get();
            app(StockMutationGuard::class)->assertAllowed('cancel', $operations, $cancellable);

            $cancellable->each->update(['is_picked' => false]);

            app(MoveReserver::class)->release($cancellable);

            $cancellable->each->update(['state' => MoveState::CANCELED]);

            foreach ($cancellable as $move) {
                $this->propagate($move);
            }

            $cancellable->each(function (Move $move) {
                $move->update(['procure_method' => ProcureMethod::MAKE_TO_STOCK]);

                $move->moveOrigins()->detach();
            });

            return true;
        });
    }

    protected function propagate(Move $move): void
    {
        $siblingStates = $move->moveDestinations
            ->flatMap
            ->moveOrigins
            ->diff(collect([$move]))
            ->pluck('state');

        if ($move->propagate_cancel) {
            if ($siblingStates->every(fn ($state) => $state === MoveState::CANCELED)) {
                $this->cancel(
                    $move->moveDestinations->filter(
                        fn (Move $destination) => $destination->state !== MoveState::DONE
                            && $destination->destination_location_id === $destination->moveDestinations->first()?->source_location_id
                    )
                );
            }

            return;
        }

        if (! $siblingStates->every(fn ($state) => in_array($state, [MoveState::DONE, MoveState::CANCELED]))) {
            return;
        }

        $move->moveDestinations->each(function (Move $destination) use ($move) {
            $destination->update(['procure_method' => ProcureMethod::MAKE_TO_STOCK]);

            $destination->moveOrigins()->detach($move->id);
        });
    }
}
