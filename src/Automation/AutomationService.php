<?php

declare(strict_types=1);

namespace WF\API\Automation;

use WF\API\Automation\Contracts\PreQualEngineInterface;
use WF\API\Automation\Models\Applicant;
use WF\API\Automation\Models\Vehicle;
use WF\API\Automation\Models\PreQualResult;
use WF\API\Automation\Models\CreditProfile;
use WF\API\Automation\Exceptions\ValidationException;
use WF\API\Automation\Exceptions\AutomationException;
use WF\API\Automation\Exceptions\RateLimitException;
use WF\API\Automation\Services\BureauCacheService;
use WF\API\Automation\Factories\BureauClientFactory;
use WF\API\Automation\Factories\CreditParserFactory;
use WF\API\Automation\Events\EventDispatcher;
use WF\API\Automation\Events\ServiceRequestEvent;
use WF\API\Automation\Events\ServiceResponseEvent;
use Cache;
use Log;

/**
 * Main service class for automation operations
 * Provides high-level API for pre-qualification and related services
 */
class AutomationService
{
    private array $rateLimits;
    private bool $enableRateLimiting;
    private bool $enableRequestValidation;

    /**
     * @param PreQualEngineInterface $preQualEngine The pre-qualification engine
     * @param BureauCacheService|null $cacheService Optional cache service
     * @param BureauClientFactory|null $bureauFactory Optional bureau factory for direct operations
     * @param CreditParserFactory|null $parserFactory Optional parser factory
     * @param EventDispatcher|null $eventDispatcher Optional event dispatcher
     * @param array $config Service configuration
     */
    public function __construct(
      private PreQualEngineInterface $preQualEngine,
      private ?BureauCacheService $cacheService = null,
      private ?BureauClientFactory $bureauFactory = null,
      private ?CreditParserFactory $parserFactory = null,
      private ?EventDispatcher $eventDispatcher = null,
      array $config = []
    ) {
        $this->rateLimits = $config['rate_limits'] ?? [
          'per_minute' => 60,
          'per_hour' => 1000,
          'per_day' => 10000
        ];
        $this->enableRateLimiting = $config['enable_rate_limiting'] ?? false;
        $this->enableRequestValidation = $config['enable_validation'] ?? true;
    }

    /**
     * Process pre-qualification request
     *
     * @param array $requestData The request data
     * @return PreQualResult The pre-qualification result
     * @throws ValidationException|AutomationException
     */
    public function processPreQual(array $requestData): PreQualResult
    {
        $startTime = microtime(true);
        $requestId = $requestData['request_id'] ?? uniqid('pq_', true);

        // Dispatch request event
        $this->dispatchEvent(new ServiceRequestEvent(
          'processPreQual',
          $requestData,
          $requestId
        ));

        try {
            // Check rate limits
            if ($this->enableRateLimiting) {
                $this->checkRateLimit($requestData['user_id'] ?? 'anonymous');
            }

            // Validate request data
            if ($this->enableRequestValidation) {
                $this->validatePreQualRequest($requestData);
            }

            // Transform and create models
            $applicant = $this->createApplicant($requestData['applicant'] ?? []);
            $vehicle = $this->createVehicle($requestData['vehicle'] ?? []);

            // Build additional data array with all optional parameters
            $additionalData = $this->buildAdditionalData($requestData, $requestId);

            Log::info("Processing PreQual request", [
              'request_id' => $requestId,
              'bureau' => $additionalData['preferred_bureau'],
              'skip_bureau' => $additionalData['skip_bureau_pull'],
              'skip_valuation' => $additionalData['skip_valuation'],
              'has_prepopulated_credit' => !is_null($additionalData['credit_profile']),
              'has_prepopulated_valuation' => !is_null($additionalData['vehicle_valuation'])
            ]);

            // Process through engine
            $result = $this->preQualEngine->evaluate($applicant, $vehicle, $additionalData);

            // Log success metrics
            $this->logMetrics('prequal.success', [
              'processing_time' => microtime(true) - $startTime,
              'risk_tier' => $result->getRiskTier(),
              'approved' => $result->isApproved()
            ]);

            // Dispatch response event
            $this->dispatchEvent(new ServiceResponseEvent(
              'processPreQual',
              $requestData,
              $result->toArray(),
              $requestId,
              microtime(true) - $startTime
            ));

            return $result;

        } catch (ValidationException $e) {
            Log::warn("Validation error in AutomationService", [
              'request_id' => $requestId,
              'error' => $e->getMessage(),
              'context' => $e->getContext()
            ]);

            $this->logMetrics('prequal.validation_error', [
              'error' => $e->getMessage()
            ]);

            throw $e;

        } catch (\Throwable $e) {
            Log::error("Unexpected error in AutomationService", [
              'request_id' => $requestId,
              'error' => $e->getMessage(),
              'trace' => $e->getTraceAsString()
            ]);

            $this->logMetrics('prequal.error', [
              'error_type' => get_class($e)
            ]);

            throw new AutomationException(
              'Failed to process pre-qualification: ' . $e->getMessage(),
              0,
              $e,
              ['request_id' => $requestId]
            );
        }
    }

    /**
     * Get credit report only (without full pre-qualification)
     *
     * @param array $applicantData Applicant information
     * @param string $bureau Bureau to use
     * @param bool $useCache Whether to use cache
     * @return CreditProfile
     * @throws AutomationException
     */
    public function getCreditReport(array $applicantData, string $bureau = 'experian', bool $useCache = true): CreditProfile
    {
        if (!$this->bureauFactory || !$this->parserFactory) {
            throw new AutomationException('Bureau services not configured');
        }

        $requestId = uniqid('cr_', true);

        try {
            $applicant = $this->createApplicant($applicantData);

            // Check cache first
            if ($useCache && $this->cacheService && !empty($applicant->ssn)) {
                $cached = $this->cacheService->get($applicant->ssn, $bureau);
                if ($cached !== null) {
                    Log::info("Returning cached credit report", [
                      'request_id' => $requestId,
                      'bureau' => $bureau
                    ]);
                    return $cached;
                }
            }

            // Pull from bureau
            $client = $this->bureauFactory->create($bureau);
            $parser = $this->parserFactory->create($bureau);

            $consumers = [[
              'firstName' => $applicant->firstName,
              'lastName' => $applicant->lastName,
              'ssn' => $applicant->ssn,
              'address' => $applicant->address,
              'city' => $applicant->city,
              'state' => $applicant->state,
              'zip' => $applicant->zipCode,
              'dob' => $applicant->dateOfBirth
            ]];

            $rawResponse = $client->pullCreditReport($consumers);
            $creditProfile = $parser->parse($rawResponse);

            // Cache if enabled
            if ($useCache && $this->cacheService && !empty($applicant->ssn) && $creditProfile->hasHit) {
                $this->cacheService->set($applicant->ssn, $bureau, $creditProfile);
            }

            return $creditProfile;

        } catch (\Exception $e) {
            Log::error("Failed to get credit report", [
              'request_id' => $requestId,
              'error' => $e->getMessage()
            ]);
            throw new AutomationException('Failed to get credit report: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Process batch pre-qualification requests
     *
     * @param array $requests Array of request data
     * @param array $options Batch processing options
     * @return array Array of results
     */
    public function processBatch(array $requests, array $options = []): array
    {
        $results = [];
        $batchId = uniqid('batch_', true);
        $concurrency = $options['concurrency'] ?? 1;
        $stopOnError = $options['stop_on_error'] ?? false;

        Log::info("Processing batch pre-qualification", [
          'batch_id' => $batchId,
          'request_count' => count($requests),
          'concurrency' => $concurrency
        ]);

        foreach ($requests as $index => $request) {
            try {
                $request['batch_id'] = $batchId;
                $request['batch_index'] = $index;

                $result = $this->processPreQual($request);
                $results[] = [
                  'index' => $index,
                  'success' => true,
                  'result' => $result->toArray(),
                  'error' => null
                ];

            } catch (\Exception $e) {
                $results[] = [
                  'index' => $index,
                  'success' => false,
                  'result' => null,
                  'error' => $e->getMessage()
                ];

                if ($stopOnError) {
                    Log::error("Batch processing stopped due to error", [
                      'batch_id' => $batchId,
                      'index' => $index,
                      'error' => $e->getMessage()
                    ]);
                    break;
                }
            }

            // Simple rate limiting between requests
            if ($concurrency === 1 && $index < count($requests) - 1) {
                usleep(100000); // 100ms delay
            }
        }

        Log::info("Batch processing completed", [
          'batch_id' => $batchId,
          'total' => count($requests),
          'successful' => count(array_filter($results, fn($r) => $r['success'])),
          'failed' => count(array_filter($results, fn($r) => !$r['success']))
        ]);

        return $results;
    }

    /**
     * Clear cache for an applicant
     *
     * @param string $ssn SSN to clear cache for
     * @param string|null $bureau Specific bureau or null for all
     */
    public function clearCache(string $ssn, ?string $bureau = null): void
    {
        if (!$this->cacheService) {
            throw new AutomationException('Cache service not configured');
        }

        $this->cacheService->clear($ssn, $bureau);

        Log::info("Cache cleared", [
          'ssn_suffix' => substr($ssn, -4),
          'bureau' => $bureau ?? 'all'
        ]);
    }

    /**
     * Get available bureaus
     *
     * @return array List of available bureau names
     */
    public function getAvailableBureaus(): array
    {
        if (!$this->bureauFactory) {
            return [];
        }

        return $this->bureauFactory->getAvailableBureaus();
    }

    /**
     * Validate request for specific bureau
     *
     * @param array $requestData Request to validate
     * @param string $bureau Bureau to validate for
     * @return array Validation result with 'valid' and 'errors' keys
     */
    public function validateRequest(array $requestData, string $bureau = 'experian'): array
    {
        $errors = [];

        try {
            // Validate applicant data
            if (empty($requestData['applicant'])) {
                $errors[] = 'Applicant data is required';
            } else {
                $this->createApplicant($requestData['applicant']);
            }

            // Validate vehicle data
            if (empty($requestData['vehicle'])) {
                $errors[] = 'Vehicle data is required';
            } else {
                $this->createVehicle($requestData['vehicle']);
            }

            // Bureau-specific validation
            if ($bureau === 'experian' && empty($requestData['applicant']['zip_code'])) {
                $errors[] = 'ZIP code is required for Experian';
            }

        } catch (ValidationException $e) {
            $errors[] = $e->getMessage();
        }

        return [
          'valid' => empty($errors),
          'errors' => $errors
        ];
    }

    /**
     * Get service health status
     *
     * @return array Health status information
     */
    public function getHealthStatus(): array
    {
        $health = [
          'status' => 'healthy',
          'timestamp' => date('Y-m-d H:i:s'),
          'checks' => []
        ];

        // Check bureau availability
        if ($this->bureauFactory) {
            $availableBureaus = $this->bureauFactory->getAvailableBureaus();
            $health['checks']['bureaus'] = [
              'healthy' => count($availableBureaus) > 0,
              'available' => $availableBureaus
            ];
        }

        // Check cache service
        if ($this->cacheService) {
            try {
                $testKey = 'health_check_' . time();
                Cache::set($testKey, 'test', false, 1);
                $value = Cache::get($testKey);
                Cache::del($testKey);

                $health['checks']['cache'] = [
                  'healthy' => $value === 'test',
                  'message' => 'Cache operational'
                ];
            } catch (\Exception $e) {
                $health['checks']['cache'] = [
                  'healthy' => false,
                  'message' => 'Cache error: ' . $e->getMessage()
                ];
                $health['status'] = 'degraded';
            }
        }

        return $health;
    }

    /**
     * Create applicant model with validation
     *
     * @throws ValidationException
     */
    private function createApplicant(array $data): Applicant
    {
        // Add default values for optional fields
        $data = array_merge([
          'monthly_debt' => null,
          'employment_type' => 'other',
          'co_applicant' => null
        ], $data);

        return Applicant::fromArray($data);
    }

    /**
     * Create vehicle model with validation
     *
     * @throws ValidationException
     */
    private function createVehicle(array $data): Vehicle
    {
        // Add default values for optional fields
        $data = array_merge([
          'vehicle_value' => null,
          'condition' => 'good'
        ], $data);

        return Vehicle::fromArray($data);
    }

    /**
     * Build additional data for engine
     */
    private function buildAdditionalData(array $requestData, string $requestId): array
    {
        return [
          'preferred_bureau' => $requestData['preferred_bureau'] ?? 'experian',
          'use_cache' => $requestData['use_cache'] ?? true,
          'skip_bureau_pull' => $requestData['skip_bureau_pull'] ?? false,
          'skip_valuation' => $requestData['skip_valuation'] ?? false,
          'credit_profile' => $requestData['credit_profile'] ?? null,
          'vehicle_valuation' => $requestData['vehicle_valuation'] ?? null,
          'request_id' => $requestId,
          'source' => $requestData['source'] ?? 'api',
          'user_id' => $requestData['user_id'] ?? null,
          'metadata' => array_merge($requestData['metadata'] ?? [], [
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'api_version' => $_SERVER['HTTP_X_GRAVITY_API_VERSION'] ?? '1.0'
          ])
        ];
    }

    /**
     * Validate pre-qualification request
     *
     * @throws ValidationException
     */
    private function validatePreQualRequest(array $requestData): void
    {
        $errors = [];

        // Required fields
        if (empty($requestData['applicant'])) {
            $errors['applicant'] = 'Applicant data is required';
        }

        if (empty($requestData['vehicle'])) {
            $errors['vehicle'] = 'Vehicle data is required';
        }

        // Validate bureau if specified
        if (isset($requestData['preferred_bureau'])) {
            $validBureaus = ['equifax', 'experian', 'transunion'];
            if (!in_array(strtolower($requestData['preferred_bureau']), $validBureaus)) {
                $errors['preferred_bureau'] = 'Invalid bureau specified';
            }
        }

        // Validate skip flags
        if (isset($requestData['skip_bureau_pull']) &&
          $requestData['skip_bureau_pull'] &&
          empty($requestData['credit_profile'])) {
            $errors['credit_profile'] = 'Credit profile data required when skipping bureau pull';
        }

        if (!empty($errors)) {
            throw new ValidationException(
              'Request validation failed',
              0,
              null,
              $errors
            );
        }
    }

    /**
     * Check rate limits
     *
     * @throws RateLimitException
     */
    private function checkRateLimit(string $userId): void
    {
        $key = "rate_limit:{$userId}";

        // Check per-minute limit
        $minuteKey = "{$key}:minute:" . date('Y-m-d-H-i');
        $minuteCount = (int)Cache::get($minuteKey) ?: 0;

        if ($minuteCount >= $this->rateLimits['per_minute']) {
            throw new RateLimitException('Rate limit exceeded: per minute');
        }

        // Increment counters
        Cache::incr($minuteKey);
        Cache::expire($minuteKey, 60);

        // Similar checks for hourly and daily limits...
    }

    /**
     * Log metrics
     */
    private function logMetrics(string $metric, array $data = []): void
    {
        // This would integrate with your metrics system
        // For now, just log
        Log::info("Metric: $metric", $data);
    }

    /**
     * Dispatch event if dispatcher available
     */
    private function dispatchEvent(object $event): void
    {
        if ($this->eventDispatcher) {
            try {
                $this->eventDispatcher->dispatch($event);
            } catch (\Exception $e) {
                Log::error("Failed to dispatch event: " . $e->getMessage());
            }
        }
    }
}
