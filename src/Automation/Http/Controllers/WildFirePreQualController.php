<?php

declare(strict_types=1);

namespace WF\API\Automation\Http\Controllers;

use WF\API\Automation\AutomationService;
use WF\API\Automation\Adapters\ApplicationPayloadParser;
use WF\API\Automation\Formatters\WildFireBureauFormatter;
use WF\API\Automation\Models\Applicant;
use WF\API\Automation\Exceptions\AutomationException;
use WF\API\Automation\Exceptions\ValidationException;
use WF\API\Automation\Services\RequestLogger;
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
            // Add breadcrumb for controller entry
            RequestLogger::addBreadcrumb('WildFire PreQual started', [], 'controller');

            // Parse the WildFire application payload
            $startParse = microtime(true);
            $applicationData = $this->getApplicationPayload($request);
            RequestLogger::addMetric('payload.parse.duration', (microtime(true) - $startParse) * 1000);

            // Set context for this application
            RequestLogger::setContext('application_id', $applicationData['application_id'] ?? 'unknown');
            RequestLogger::setContext('has_coapplicant', isset($applicationData['co_applicant_active']) ? 'true' : 'false');

            // Extract applicant and vehicle data
            RequestLogger::addBreadcrumb('Extracting applicant data', [
              'has_ssn' => !empty($applicationData['applicant_ssn']),
              'state' => $applicationData['applicant_state'] ?? 'unknown'
            ], 'controller');

            $applicant = $this->payloadParser->parseApplicant($applicationData);
            $vehicle = $this->payloadParser->parseVehicle($applicationData);
            $preferredBureau = $this->payloadParser->extractBureauPreference($applicationData);

            // Log decision about bureau selection
            RequestLogger::info('Bureau selection', [
              'requested' => $preferredBureau,
              'validated' => $this->validateBureau($preferredBureau)
            ]);

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

            // Add breadcrumb before heavy processing
            RequestLogger::addBreadcrumb('Starting automation processing', [
              'bureau' => $preferredBureau,
              'skip_bureau' => $requestData['skip_bureau_pull'] ?? false,
              'skip_valuation' => $requestData['skip_valuation'] ?? false
            ], 'controller');

            // Process through automation service
            $startProcess = microtime(true);
            $result = $this->automationService->processPreQual($requestData);

            $processDuration = (microtime(true) - $startProcess) * 1000;
            RequestLogger::addMetric('automation.process.duration', $processDuration, [
              'bureau' => $preferredBureau
            ]);

            // Add breadcrumb for formatting
            RequestLogger::addBreadcrumb('Formatting response for WildFire', [
              'has_credit_profile' => $result->creditProfile !== null,
              'fico_score' => $result->creditProfile->ficoScore ?? 'N/A'
            ], 'controller');

            // Format response in WildFire format
            $wildFireResponse = $this->wildFireFormatter->formatToWildFire(
              $result->creditProfile,
              [], // Raw bureau data would be passed here
              $applicant->hasCoApplicant()
            );

            // Log success metrics
            RequestLogger::addMetric('prequal.success', 1, [
              'bureau' => $preferredBureau,
              'risk_tier' => $result->getRiskTier()
            ]);

            RequestLogger::info('WildFire PreQual completed successfully', [
              'risk_tier' => $result->getRiskTier(),
              'matched_lenders' => count($result->matchedLenders)
            ]);

            return [
              'success' => true,
              'data' => $wildFireResponse,
              'error' => ''
            ];

        } catch (ValidationException $e) {
            RequestLogger::error('Validation failed', [
              'error' => $e->getMessage(),
              'context' => $e->getContext()
            ]);

            RequestLogger::addMetric('prequal.validation_error', 1);

            return [
              'success' => false,
              'data' => '',
              'error' => $e->getMessage()
            ];
        } catch (AutomationException $e) {
            RequestLogger::error('Processing failed', [
              'error' => $e->getMessage(),
              'context' => $e->getContext()
            ]);

            RequestLogger::addMetric('prequal.processing_error', 1);

            Log::error("WildFire PreQual processing failed: " . $e->getMessage() . "\nContext: " . print_r($e->getContext(), true));

            return [
              'success' => false,
              'data' => '',
              'error' => 'Processing failed'
            ];
        } catch (\Throwable $e) {
            RequestLogger::logException($e, [
              'controller' => 'WildFirePreQualController',
              'method' => 'handleWildFirePreQual'
            ]);

            RequestLogger::addMetric('prequal.unexpected_error', 1);

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
            // Add breadcrumb for legacy pull
            RequestLogger::addBreadcrumb('Legacy pull started', [], 'controller');

            $requestData = $this->getRequestData($request);

            // Extract PII and bureau information
            $bureau = $requestData['bureau'] ?? 'experian';
            $pii = $requestData['pii'] ?? [];
            $scoreModel = $requestData['score_model'] ?? 'VANTAGE';

            RequestLogger::setContext('bureau', $bureau);
            RequestLogger::setContext('score_model', $scoreModel);

            // Validate bureau
            $bureau = $this->validateBureau($bureau);

            // Build applicant from PII data
            RequestLogger::addBreadcrumb('Building applicant from PII', [
              'has_primary' => !empty($pii['primary']),
              'has_secondary' => !empty($pii['secondary'])
            ], 'controller');

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

            $startProcess = microtime(true);
            $result = $this->automationService->processPreQual($automationRequestData);

            RequestLogger::addMetric('legacy_pull.duration', (microtime(true) - $startProcess) * 1000, [
              'bureau' => $bureau
            ]);

            // Format in legacy WildFire format
            $wildFireResponse = $this->wildFireFormatter->formatToWildFire(
              $result->creditProfile,
              [], // Raw bureau data
              $applicant->hasCoApplicant()
            );

            RequestLogger::info('Legacy pull completed', [
              'bureau' => $bureau,
              'has_score' => $result->creditProfile->hasValidScore()
            ]);

            return [
              'success' => true,
              'data' => $wildFireResponse,
              'error' => ''
            ];

        } catch (\Throwable $e) {
            RequestLogger::logException($e, [
              'controller' => 'WildFirePreQualController',
              'method' => 'handleLegacyPull'
            ]);

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
        RequestLogger::addBreadcrumb('Extracting optional data', [
          'has_skip_flags' => isset($applicationData['skip_bureau_pull']) || isset($applicationData['skip_valuation']),
          'has_prepopulated' => isset($applicationData['credit_profile']) || isset($applicationData['vehicle_valuation'])
        ], 'controller');

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
            RequestLogger::info('Using pre-populated credit profile', [
              'has_score' => isset($applicationData['credit_profile']['fico_score'])
            ]);
        }

        // Pre-populated vehicle valuation
        if (isset($applicationData['vehicle_valuation'])) {
            $requestData['vehicle_valuation'] = $applicationData['vehicle_valuation'];
            RequestLogger::info('Using pre-populated vehicle valuation', [
              'value' => $applicationData['vehicle_valuation']['value'] ?? 'N/A'
            ]);
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
            RequestLogger::info('Unknown bureau requested', [
              'requested' => $bureau,
              'defaulting_to' => 'experian'
            ]);

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
