<?php

namespace Shafique\Logistics\Services;

use LogicException;

class ReleaseExecutionContext
{
    /** @var array{trip_id:int,load_revision_id:int,actor_id:int}|null */
    private ?array $current = null;

    public function enter(int $tripId, int $loadRevisionId, int $actorId): void
    {
        if ($this->current !== null) {
            throw new LogicException('A Logistics release command is already active.');
        }

        $this->current = ['trip_id' => $tripId, 'load_revision_id' => $loadRevisionId, 'actor_id' => $actorId];
    }

    /** @return array{trip_id:int,load_revision_id:int,actor_id:int}|null */
    public function current(): ?array
    {
        return $this->current;
    }

    public function leave(): void
    {
        $this->current = null;
    }
}
