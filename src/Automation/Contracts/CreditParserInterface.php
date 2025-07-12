<?php

declare(strict_types=1);

namespace WF\API\Automation\Contracts;

use WF\API\Automation\Models\CreditProfile;

interface CreditParserInterface
{
    public function parse(array $rawData): CreditProfile;
    public function getSupportedBureau(): string;
}
