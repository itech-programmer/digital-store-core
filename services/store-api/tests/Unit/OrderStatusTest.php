<?php

namespace Tests\Unit;

use App\Enums\OrderStatus;
use PHPUnit\Framework\TestCase;

class OrderStatusTest extends TestCase
{
    public function test_main_path_transitions(): void
    {
        $this->assertTrue(OrderStatus::Created->canTransitionTo(OrderStatus::Paid));
        $this->assertTrue(OrderStatus::Paid->canTransitionTo(OrderStatus::Delivering));
        $this->assertTrue(OrderStatus::Delivering->canTransitionTo(OrderStatus::Delivered));
        $this->assertFalse(OrderStatus::Delivered->canTransitionTo(OrderStatus::Paid));
    }
}
