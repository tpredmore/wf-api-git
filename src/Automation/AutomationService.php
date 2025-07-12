<?php

declare(strict_types=1);

namespace WF\API\Automation;

use WF\API\Automation\Contracts\PreQualEngineInterface;
use WF\API\Automation\Models\Applicant;
use WF\API\Automation\Models\Vehicle;
use WF\API\Automation\Models\PreQualResult;
use WF\API\Automation\Exceptions\ValidationException;
use Log;

class AutomationService
{
    public function __construct(
      private PreQualEngineInterface $preQualEngine
    ) {}

    /**
     * @throws ValidationException|\Throwable
     */
    public function processPreQual(array $requestData): PreQualResult
    {
        try {
            $applicant = Applicant::fromArray($requestData['applicant'] ?? []);
            $vehicle = Vehicle::fromArray($requestData['vehicle'] ?? []);

            // Build additional data array with all optional parameters
            $additionalData = [
              'preferred_bureau' => $requestData['preferred_bureau'] ?? 'experian',
              'use_cache' => $requestData['use_cache'] ?? true,
              'skip_bureau_pull' => $requestData['skip_bureau_pull'] ?? false,
              'skip_valuation' => $requestData['skip_valuation'] ?? false,
              'credit_profile' => $requestData['credit_profile'] ?? null,
              'vehicle_valuation' => $requestData['vehicle_valuation'] ?? null,
              'request_id' => $requestData['request_id'] ?? uniqid(),
              'metadata' => $requestData['metadata'] ?? []
            ];

            Log::info("Processing PreQual request", [
              'request_id' => $additionalData['request_id'],
              'bureau' => $additionalData['preferred_bureau'],
              'skip_bureau' => $additionalData['skip_bureau_pull'],
              'skip_valuation' => $additionalData['skip_valuation'],
              'has_prepopulated_credit' => !is_null($additionalData['credit_profile']),
              'has_prepopulated_valuation' => !is_null($additionalData['vehicle_valuation'])
            ]);

            return $this->preQualEngine->evaluate($applicant, $vehicle, $additionalData);

        } catch (ValidationException $e) {
            Log::error("Validation error in AutomationService: " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            Log::error("Unexpected error in AutomationService: " . $e->getMessage());
            throw $e;
        }
    }
}
