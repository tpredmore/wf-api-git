<?php

declare(strict_types=1);

namespace WF\API\Automation\Contracts;

interface ValuationProviderInterface
{
    public function getValuation(string $vin, int $mileage, string $zipCode, string $condition = 'good'): array;
    public function getProviderName(): string;
    public function isAvailable(): bool;
}
