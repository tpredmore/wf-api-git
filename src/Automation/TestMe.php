<?php


declare(strict_types=1);

namespace WF\API\Automation;


use DI\ContainerBuilder;

class TestMe {

    public function handler(): array {
global $container;
        // Build container
        try {
            $automationService = $container->get(AutomationService::class);
        } catch (\Exception $e) {
            return [
              'success' => false,
              'error' => $e->getMessage(),
              'data' => []
            ];
        }

        // Prepare request data
        $requestData = [
          'applicant' => [
            'monthly_income' => 5000,
            'employment_type' => 'W2',
            'state' => 'TX',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'ssn' => '123-45-6789',
            'address' => '123 Main St',
            'city' => 'Austin',
            'zip_code' => '78701',
            'date_of_birth' => '1985-06-15'
          ],
          'vehicle' => [
            'vin' => '1HGBH41JXMN109186',
            'year' => 2020,
            'make' => 'Honda',
            'model' => 'Civic',
            'mileage' => 35000,
            'loan_amount' => 25000
          ],
          'preferred_bureau' => 'transunion'
        ];

        try {
            $result = $automationService->processPreQual($requestData);

            return [
              'success' => true,
              'error' => '',
              'data' => [
                "Approval Score" => $result->approvalScore,
                "Risk Tier" => $result->getRiskTier(),
                "FICO Score" => $result->creditProfile,
                "Matched Lenders" => implode(', ', $result->getMatchedLenders())
              ]
            ];

        } catch (\Exception $e) {
            return [
              'success' => false,
              'error' => $e->getMessage(),
              'data' => []
            ];
        }
    }
}