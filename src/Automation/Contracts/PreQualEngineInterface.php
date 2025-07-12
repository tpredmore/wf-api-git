<?php

declare(strict_types=1);

namespace WF\API\Automation\Contracts;

use WF\API\Automation\Models\Applicant;
use WF\API\Automation\Models\Vehicle;
use WF\API\Automation\Models\PreQualResult;

interface PreQualEngineInterface
{
    public function evaluate(Applicant $applicant, Vehicle $vehicle, array $additionalData = []): PreQualResult;
}
