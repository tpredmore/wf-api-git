<?php

declare(strict_types=1);

namespace WF\API\Automation\Http\Controllers;

use WF\API\Automation\AutomationService;
use WF\API\Automation\Adapters\ApplicationPayloadParser;
use WF\API\Automation\Formatters\WildFireBureauFormatter;
use WF\API\Automation\Models\Applicant;
use WF\API\Automation\Exceptions\AutomationException;
use WF\API\Automation\Exceptions\ValidationException;
use Log;

/**
 * Controller specifically for WildFire LOS integration
 */
class WildFirePreQualController
{
    public function __construct(
      private AutomationService $automationService,
      private ApplicationPayloadParser $payloadParser,
      private WildFireBureauFormatter $wildFireFormatter
    ) {}

    /**
     * Handle pre-qualification request with WildFire application payload
     */
    public function handleWildFirePreQual($request): array
    {
        try {
            // Parse the WildFire application payload
            $applicationData = $this->getApplicationPayload($request);

            // Extract applicant and vehicle data
            $applicant = $this->payloadParser->parseApplicant($applicationData);
            $vehicle = $this->payloadParser->parseVehicle($applicationData);
            $preferredBureau = $this->payloadParser->extractBureauPreference($applicationData);

            // Validate bureau selection
            $preferredBureau = $this->validateBureau($preferredBureau);

            // Build request data for automation service
            $requestData = [
              'applicant' => $applicant->toArray(),
              'vehicle' => $vehicle->toArray(),
              'preferred_bureau' => $preferredBureau,
              'request_id' => $applicationData['application_id'] ?? uniqid()
            ];

            // Extract optional processing flags and pre-populated data
            $this->extractOptionalData($applicationData, $requestData);

            // Process through automation service
            $result = $this->automationService->processPreQual($requestData);

            // Format response in WildFire format
            $wildFireResponse = $this->wildFireFormatter->formatToWildFire(
              $result->creditProfile,
              [], // Raw bureau data would be passed here
              $applicant->hasCoApplicant()
            );

            return [
              'success' => true,
              'data' => $wildFireResponse,
              'error' => ''
            ];

        } catch (ValidationException $e) {
            return [
              'success' => false,
              'data' => '',
              'error' => $e->getMessage()
            ];
        } catch (AutomationException $e) {
            Log::error("WildFire PreQual processing failed: " . $e->getMessage() . "\nContext: " . print_r($e->getContext(), true));

            return [
              'success' => false,
              'data' => '',
              'error' => 'Processing failed'
            ];
        } catch (\Throwable $e) {
            Log::error("Unexpected error in WildFire PreQual: " . $e->getMessage() . "\n" . $e->getTraceAsString());

            return [
              'success' => false,
              'data' => '',
              'error' => 'Internal server error'
            ];
        }
    }

    /**
     * Handle legacy pull request (direct bureau pull)
     */
    public function handleLegacyPull($request): array
    {
        try {
            $requestData = $this->getRequestData($request);

            // Extract PII and bureau information
            $bureau = $requestData['bureau'] ?? 'experian';
            $pii = $requestData['pii'] ?? [];
            $scoreModel = $requestData['score_model'] ?? 'VANTAGE';

            // Validate bureau
            $bureau = $this->validateBureau($bureau);

            // Build applicant from PII data
            $applicant = $this->buildApplicantFromPII($pii);

            // Process through automation service
            $automationRequestData = [
              'applicant' => $applicant->toArray(),
              'vehicle' => ['vin' => '', 'year' => 2020, 'make' => '', 'model' => '', 'mileage' => 0, 'loan_amount' => 0],
              'preferred_bureau' => $bureau
            ];

            // Extract optional flags and data from request
            if (isset($requestData['skip_bureau_pull'])) {
                $automationRequestData['skip_bureau_pull'] = (bool)$requestData['skip_bureau_pull'];
            }
            if (isset($requestData['use_cache'])) {
                $automationRequestData['use_cache'] = (bool)$requestData['use_cache'];
            }
            if (isset($requestData['credit_profile'])) {
                $automationRequestData['credit_profile'] = $requestData['credit_profile'];
            }

            $result = $this->automationService->processPreQual($automationRequestData);

            // Format in legacy WildFire format
            $wildFireResponse = $this->wildFireFormatter->formatToWildFire(
              $result->creditProfile,
              [], // Raw bureau data
              $applicant->hasCoApplicant()
            );

            return [
              'success' => true,
              'data' => $wildFireResponse,
              'error' => ''
            ];

        } catch (\Throwable $e) {
            Log::error("Legacy pull failed: " . $e->getMessage());

            return [
              'success' => false,
              'data' => '',
              'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Extract optional processing flags and pre-populated data
     */
    private function extractOptionalData(array $applicationData, array &$requestData): void
    {
        // Processing flags
        if (isset($applicationData['skip_bureau_pull'])) {
            $requestData['skip_bureau_pull'] = (bool)$applicationData['skip_bureau_pull'];
        }

        if (isset($applicationData['skip_valuation'])) {
            $requestData['skip_valuation'] = (bool)$applicationData['skip_valuation'];
        }

        if (isset($applicationData['use_cache'])) {
            $requestData['use_cache'] = (bool)$applicationData['use_cache'];
        } else {
            // Default to using cache
            $requestData['use_cache'] = true;
        }

        // Pre-populated credit profile
        if (isset($applicationData['credit_profile'])) {
            $requestData['credit_profile'] = $applicationData['credit_profile'];
        }

        // Pre-populated vehicle valuation
        if (isset($applicationData['vehicle_valuation'])) {
            $requestData['vehicle_valuation'] = $applicationData['vehicle_valuation'];
        }

        // Additional options for testing/debugging
        if (isset($applicationData['force_bureau'])) {
            $requestData['preferred_bureau'] = $this->validateBureau($applicationData['force_bureau']);
        }

        // Pass through any metadata
        if (isset($applicationData['metadata'])) {
            $requestData['metadata'] = $applicationData['metadata'];
        }
    }

    /**
     * Validate and normalize bureau name
     */
    private function validateBureau(string $bureau): string
    {
        $bureau = strtolower(trim($bureau));

        // Handle various bureau name formats
        $bureauMap = [
          'equifax' => 'equifax',
          'experian' => 'experian',
          'transunion' => 'transunion',
          'trans_union' => 'transunion',
          'trans union' => 'transunion',
          'tu' => 'transunion',
          'eq' => 'equifax',
          'exp' => 'experian'
        ];

        if (!isset($bureauMap[$bureau])) {
            Log::warn("Unknown bureau requested: $bureau, defaulting to Experian");
            return 'experian';
        }

        return $bureauMap[$bureau];
    }

    /**
     * @throws ValidationException
     */
    private function getApplicationPayload($request): array
    {
        $data = json_decode($request->data, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ValidationException('Invalid JSON in request body');
        }

        return $data;
    }

    private function getRequestData($request): array
    {
        // Try to parse as JSON first
        $data = json_decode($request->data, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $data;
        }

        // Fallback to parsing legacy format
        return $this->parseLegacyRequest($request->data);
    }

    private function parseLegacyRequest(string $body): array
    {
        // Handle legacy request format if needed
        // This would parse the old WildFire format
        return [];
    }

    /**
     * @throws ValidationException
     */
    private function buildApplicantFromPII(array $pii): Applicant
    {
        $primary = $pii['primary'] ?? [];
        $secondary = $pii['secondary'] ?? [];
        $hasCoApp = !empty($secondary);

        $coApplicantData = null;
        if ($hasCoApp) {
            $coApplicantData = [
              'first_name' => $secondary['first'] ?? '',
              'last_name' => $secondary['last'] ?? '',
              'ssn' => $secondary['ssn'] ?? '',
              'dob' => $secondary['dob'] ?? '',
              'address' => $secondary['address'] ?? '',
              'city' => $secondary['city'] ?? '',
              'state' => $secondary['state'] ?? '',
              'zip' => $secondary['zip'] ?? ''
            ];
        }

        return Applicant::fromArray([
          'monthly_income' => 0, // Not in PII
          'employment_type' => 'other',
          'state' => $primary['state'] ?? '',
          'first_name' => $primary['first'] ?? '',
          'last_name' => $primary['last'] ?? '',
          'ssn' => $primary['ssn'] ?? '',
          'address' => $primary['address'] ?? '',
          'city' => $primary['city'] ?? '',
          'zip_code' => $primary['zip'] ?? '',
          'date_of_birth' => $primary['dob'] ?? '',
          'co_applicant' => $coApplicantData
        ]);
    }
}
