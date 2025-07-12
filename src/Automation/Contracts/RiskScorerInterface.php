<?php

declare(strict_types=1);

namespace WF\API\Automation\Contracts;

use WF\API\Automation\Models\Applicant;
use WF\API\Automation\Models\Vehicle;
use WF\API\Automation\Models\CreditProfile;

interface RiskScorerInterface
{
    public function calculateScore(Applicant $applicant, Vehicle $vehicle, CreditProfile $credit): float;
    public function getRiskTier(float $score): string;
    public function getScoreFactors(): array;
}
