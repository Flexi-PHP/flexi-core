<?php

declare(strict_types=1);

namespace Flexi\Test\TestData\TestDoubles;

use Flexi\Contracts\Classes\Criteria\Filters;
use Flexi\Contracts\Classes\Criteria\Order;
use Flexi\Contracts\Interfaces\CriteriaInterface;

class DummySearchCriteria implements CriteriaInterface
{
    public function filters(): Filters
    {
        return new Filters([]);
    }

    public function order(): Order
    {
        return new Order('id');
    }

    public function limit(): ?int
    {
        return null;
    }

    public function offset(): ?int
    {
        return null;
    }

    public function pointer(): ?int
    {
        return null;
    }
}
