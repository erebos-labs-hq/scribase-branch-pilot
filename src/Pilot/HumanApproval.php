<?php

declare(strict_types=1);

namespace App\Pilot;

/**
 * Asks a human a yes/no question. Returns null when no human can be reached.
 */
interface HumanApproval
{
    public function confirm(string $question): ?bool;
}
