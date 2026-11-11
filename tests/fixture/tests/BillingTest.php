<?php

declare(strict_types=1);

namespace Fixture\Billing\Tests;

use Fixture\Billing\OrderProcessor;
use Fixture\Billing\PricingEngine;
use Fixture\Billing\SimpleCalculator;
use PHPUnit\Framework\TestCase;

final class BillingTest extends TestCase
{
    public function test_add_is_covered(): void
    {
        if (getenv('CRAP_FORCE_FAIL') === '1') {
            $this->fail('Intentional failure so coverage is skewed.');
        }

        $calculator = new SimpleCalculator();

        $this->assertSame(3, $calculator->add(1, 2));
    }

    public function test_quote_covers_only_the_first_plan(): void
    {
        $pricing = new PricingEngine();

        $this->assertSame(19, $pricing->quote('a'));
    }

    public function test_order_processor_is_loaded_but_process_is_not_called(): void
    {
        $processor = new OrderProcessor();

        $this->assertTrue($processor->ping());
    }
}
