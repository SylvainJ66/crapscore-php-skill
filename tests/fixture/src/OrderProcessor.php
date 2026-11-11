<?php

declare(strict_types=1);

namespace Fixture\Billing;

/**
 * process() has 29 separate ifs, so cyclomatic complexity is 30.
 * Nothing calls it. Even 100% coverage would leave CRAP at 30, which is not
 * under the default threshold, so the skill should say "refactor".
 * ping() exists only so this file is loaded and the uncovered method is
 * instrumented. Do not collapse process() into a loop: that drops the complexity.
 */
final class OrderProcessor
{
    public function ping(): bool
    {
        return true;
    }

    public function process(int $code): int
    {
        if ($code === 1) {
            return 1;
        }
        if ($code === 2) {
            return 2;
        }
        if ($code === 3) {
            return 3;
        }
        if ($code === 4) {
            return 4;
        }
        if ($code === 5) {
            return 5;
        }
        if ($code === 6) {
            return 6;
        }
        if ($code === 7) {
            return 7;
        }
        if ($code === 8) {
            return 8;
        }
        if ($code === 9) {
            return 9;
        }
        if ($code === 10) {
            return 10;
        }
        if ($code === 11) {
            return 11;
        }
        if ($code === 12) {
            return 12;
        }
        if ($code === 13) {
            return 13;
        }
        if ($code === 14) {
            return 14;
        }
        if ($code === 15) {
            return 15;
        }
        if ($code === 16) {
            return 16;
        }
        if ($code === 17) {
            return 17;
        }
        if ($code === 18) {
            return 18;
        }
        if ($code === 19) {
            return 19;
        }
        if ($code === 20) {
            return 20;
        }
        if ($code === 21) {
            return 21;
        }
        if ($code === 22) {
            return 22;
        }
        if ($code === 23) {
            return 23;
        }
        if ($code === 24) {
            return 24;
        }
        if ($code === 25) {
            return 25;
        }
        if ($code === 26) {
            return 26;
        }
        if ($code === 27) {
            return 27;
        }
        if ($code === 28) {
            return 28;
        }
        if ($code === 29) {
            return 29;
        }

        return 0;
    }
}
