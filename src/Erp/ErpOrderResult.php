<?php

namespace App\Erp;

final readonly class ErpOrderResult
{
    public function __construct(public string $erpOrderNumber, public \DateTimeImmutable $expectedShipDate)
    {
    }
}
