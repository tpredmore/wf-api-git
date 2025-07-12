<?php

declare(strict_types=1);

namespace WF\API\Automation\Contracts;

interface BureauClientInterface
{
    public function pullCreditReport(array $consumers): array;
    public function getName(): string;
    public function isAvailable(): bool;
}
