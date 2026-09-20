<?php

namespace App\Services;

use App\Models\Installation;

/** @deprecated Agent state snapshots replaced expiring leases in Central 1.2. */
final class LeaseService
{
    public function __construct(private AgentStateService $states) {}

    public function issue(Installation $installation): array
    {
        return $this->states->issue($installation);
    }
}
