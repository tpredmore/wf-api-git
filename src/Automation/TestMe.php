<?php


declare(strict_types=1);

namespace WF\API\Automation;


use DI\ContainerBuilder;
use WF\API\Automation\Models\Applicant;

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
            'first_name' => 'Test',
            'last_name' => 'User',
            'ssn' => '123456789',
            'address' => '123 Test St',
            'city' => 'Dallas',
            'zip_code' => '75201',
            'date_of_birth' => '1990-01-01'
          ],
          'vehicle' => [
            'vin' => '1HGBH41JXMN109186',
            'year' => 2020,
            'make' => 'Honda',
            'model' => 'Civic',
            'mileage' => 35000,
            'loan_amount' => 20000
          ],
          'skip_bureau_pull' => true,
          'skip_valuation' => true,
          'credit_profile' => [
            'fico_score' => 720,
            'bureau' => 'equifax',
            'hit' => true,
            'open_trade_count' => 5,
            'auto_trade_count' => 1,
            'derogatory_marks' => 0,
            'bankruptcies' => 0,
            'revolving_utilization' => 0.3,
            'inquiries_6mo' => 1,
            'estimated_monthly_debt' => 500
          ],
          'vehicle_valuation' => [
            'value' => 25000
          ]
        ];
//        $requestData = [
//          'applicant' => [
//            'monthly_income' => 5000,
//            'employment_type' => 'W2',
//            'state' => 'TX',
//            'first_name' => 'John',
//            'last_name' => 'Doe',
//            'ssn' => '123-45-6789',
//            'address' => '123 Main St',
//            'city' => 'Austin',
//            'zip_code' => '78701',
//            'date_of_birth' => '1985-06-15'
//          ],
//          'vehicle' => [
//            'vin' => '1HGBH41JXMN109186',
//            'year' => 2020,
//            'make' => 'Honda',
//            'model' => 'Civic',
//            'mileage' => 35000,
//            'loan_amount' => 25000
//          ],
//          'preferred_bureau' => 'transunion'
//        ];

        try {
            $applicant = Applicant::fromArray([
              'monthly_income' => 5000,
              'employment_type' => 'W2',
              'state' => 'TX',
              'first_name' => 'John',
              'middle_name' => 'James',
              'last_name' => 'Doe',
              'ssn' => '123456789',
              'address' => '123 Main St',
              'city' => 'Dallas',
              'zip_code' => '75201',
              'date_of_birth' => '1985-01-15'
            ]);
            $applicant_test = "Applicant created with middle name: " . $applicant->getFullName() . "\n";

            $result = $automationService->processPreQual($requestData);

            return [
              'success' => true,
              'error' => '',
              'data' => [
                "Approval Score" => $result->approvalScore,
                "Risk Tier" => $result->getRiskTier(),
                "FICO Score" => $result->creditProfile,
                "Matched Lenders" => implode(', ', $result->getMatchedLenders()),
                  "applicant Stuff" => $applicant_test
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