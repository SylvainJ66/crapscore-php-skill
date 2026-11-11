<?php

declare(strict_types=1);

namespace Fixture\Billing;

/**
 * Eleven branches, so cyclomatic complexity is 12 (under the default threshold of 30).
 * BillingTest only takes the first branch. The assignments in that branch are there so
 * the executed line count is half of the method: coverage lands on 50%, CRAP equals 30,
 * and more tests are still required to get strictly under the threshold.
 * Do not fold the branches into a loop or a map, and do not delete the assignments.
 */
final class PricingEngine
{
    public function quote(string $code): int
    {
        if ($code === 'a') {
            // These assignments are executed. They make this branch half of the
            // method's lines, so the single covered call lands on 50% coverage.
            $price = 1;
            $price = 2;
            $price = 3;
            $price = 4;
            $price = 5;
            $price = 6;
            $price = 7;
            $price = 8;
            $price = 9;
            $price = 10;
            $price = 11;
            $price = 12;
            $price = 13;
            $price = 14;
            $price = 15;
            $price = 16;
            $price = 17;
            $price = 18;
            $price = 19;
            return $price;
        }
        if ($code === 'b') {
            return 2;
        }
        if ($code === 'c') {
            return 3;
        }
        if ($code === 'd') {
            return 4;
        }
        if ($code === 'e') {
            return 5;
        }
        if ($code === 'f') {
            return 6;
        }
        if ($code === 'g') {
            return 7;
        }
        if ($code === 'h') {
            return 8;
        }
        if ($code === 'i') {
            return 9;
        }
        if ($code === 'j') {
            return 10;
        }
        if ($code === 'k') {
            return 11;
        }

        return 0;
    }
}
