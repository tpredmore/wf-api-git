<?php

declare(strict_types=1);

namespace WF\API\Automation\Repositories;

class LenderRepository
{
    // Your existing methods, keeping them for now...

    public function getActiveRules(): array
    {
        // TODO: Replace with actual database/config retrieval
        return [
          [
            'name' => 'Prime Auto Lender',
            'loan_types' => ['auto'],
            'min_fico' => 680,
            'max_dti' => 0.40,
            'max_ltv' => 0.90,
            'states' => ['TX', 'CA', 'FL', 'NY'],
            'no_self_employed' => false
          ],
          [
            'name' => 'Subprime Specialist',
            'loan_types' => ['auto'],
            'min_fico' => 580,
            'max_dti' => 0.50,
            'max_ltv' => 1.00,
            'states' => ['TX', 'CA', 'FL'],
            'no_self_employed' => true
          ]
        ];
    }

    // Keep your existing buildRules method for migration
    public static function buildRules(array $lenders): array
    {
        // Your existing implementation...
        return [];
    }
}
