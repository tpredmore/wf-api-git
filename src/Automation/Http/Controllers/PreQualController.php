<?php

declare(strict_types=1);

namespace WF\API\Automation\Http\Controllers;

use WF\API\Automation\AutomationService;
use WF\API\Automation\Exceptions\AutomationException;
use WF\API\Automation\Exceptions\ValidationException;
use Log;

class PreQualController
{
    public function __construct(
      private AutomationService $automationService
    ) { }

    public function handlePreQual($request, array $params = []): array
    {
        try {
            // Get request data - handle both object and array formats
            $requestData = is_object($request) && property_exists($request, 'data')
              ? $request->data
              : $request;

            // If it's a JSON string, decode it
            if (is_string($requestData)) {
                $requestData = json_decode($requestData, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new ValidationException('Invalid JSON in request: ' . json_last_error_msg());
                }
            }

            Log::info("Processing PreQual request: " . json_encode([
                'has_applicant' => isset($requestData['applicant']),
                'has_vehicle' => isset($requestData['vehicle']),
                'preferred_bureau' => $requestData['preferred_bureau'] ?? 'not specified'
              ]));

            // Process pre-qualification
            $result = $this->automationService->processPreQual($requestData);

            Log::info("PreQual processing completed", json_encode([
              'approved' => $result->isApproved(),
              'risk_tier' => $result->getRiskTier(),
              'fico_score' => $result->creditProfile->ficoScore ?? 'N/A'
            ]));

            return [
              'success' => true,
              'data' => $result->toArray(),
              'error' => ''
            ];

        } catch (ValidationException $e) {
            Log::warn("PreQual validation failed: " . $e->getMessage());
            return [
              'success' => false,
              'data' => [],
              'error' => $e->getMessage()
            ];
        } catch (AutomationException $e) {
            Log::error("PreQual processing failed: " . $e->getMessage() . "\nContext: " . print_r($e->getContext(), true));
            return [
              'success' => false,
              'data' => [],
              'error' => 'Processing failed - ' . $e->getMessage()
            ];
        } catch (\Throwable $e) {
            Log::error("Unexpected error in PreQual: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            return [
              'success' => false,
              'data' => [],
              'error' => 'Internal server error'
            ];
        }
    }
}
