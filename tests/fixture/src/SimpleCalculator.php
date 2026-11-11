<?php

declare(strict_types=1);

namespace Fixture\Billing;

/**
 * One decision-free method, covered by BillingTest.
 * CRAP collapses to the complexity (1) once coverage is 100%.
 */
final class SimpleCalculator
{
    public function add(int $left, int $right): int
    {
        return $left + $right;
    }
}
