<?php

declare(strict_types=1);

namespace WF\API\Automation\Services;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use WF\API\Automation\Contracts\PreQualEngineInterface;
use WF\API\Automation\Contracts\RiskScorerInterface;
use WF\API\Automation\Factories\BureauClientFactory;
use WF\API\Automation\Factories\CreditParserFactory;
use WF\API\Automation\Factories\ValuationProviderFactory;
use WF\API\Automation\Models\Applicant;
use WF\API\Automation\Models\Vehicle;
use WF\API\Automation\Models\CreditProfile;
use WF\API\Automation\Models\PreQualResult;
use WF\API\Automation\Exceptions\AutomationException;
use WF\API\Automation\Exceptions\ValidationException;
use WF\API\Automation\Exceptions\BureauApiException;
use WF\API\Automation\Exceptions\ValuationException;
use WF\API\Automation\Events\EventDispatcher;
use WF\API\Automation\Events\PreQualStartedEvent;
use WF\API\Automation\Events\CreditReportPulledEvent;
use WF\API\Automation\Events\PreQualCompletedEvent;
use WF\API\Automation\Events\PreQualFailedEvent;
use WF\API\Automation\Events\VehicleValuationCompletedEvent;
use Log;

/**
 * Main engine for processing pre-qualification requests
 * Orchestrates credit pulls, valuations, and risk scoring
 */
class PreQualEngine implements PreQualEngineInterface
{
    /**
     * @param RiskScorerInterface $riskScorer Risk scoring service
     * @param BureauClientFactory $bureauFactory Factory for creating bureau clients
     * @param CreditParserFactory $parserFactory Factory for creating credit parsers
     * @param ValuationProviderFactory $valuationFactory Factory for valuation providers
     * @param BureauCacheService $cacheService Cache service for bureau responses
     * @param EventDispatcher|null $eventDispatcher Optional event dispatcher for decoupled operations
     */
    public function __construct(
      private RiskScorerInterface $riskScorer,
      private BureauClientFactory $bureauFactory,
      private CreditParserFactory $parserFactory,
      private ValuationProviderFactory $valuationFactory,
      private BureauCacheService $cacheService,
      private ?EventDispatcher $eventDispatcher = null
    ) {}

    /**
     * Evaluate pre-qualification for an applicant and vehicle
     *
     * @param Applicant $applicant The applicant information
     * @param Vehicle $vehicle The vehicle information
     * @param array $additionalData Additional processing options:
     *   - preferred_bureau: string (default: 'experian')
     *   - use_cache: bool (default: true)
     *   - skip_bureau_pull: bool (default: false)
     *   - skip_valuation: bool (default: false)
     *   - credit_profile: array|null Pre-populated credit data
     *   - vehicle_valuation: array|null Pre-populated valuation data
     *   - request_id: string Unique request identifier
     *   - metadata: array Additional metadata
     *
     * @return PreQualResult The evaluation result
     * @throws AutomationException If evaluation fails
     */
    public function evaluate(Applicant $applicant, Vehicle $vehicle, array $additionalData = []): PreQualResult
    {
        $startTime = microtime(true);
        $requestId = $additionalData['request_id'] ?? uniqid('pq_', true);

        // Extract processing options
        $bureau = $additionalData['preferred_bureau'] ?? 'experian';
        $useCache = $additionalData['use_cache'] ?? true;
        $skipBureauPull = $additionalData['skip_bureau_pull'] ?? false;
        $skipValuation = $additionalData['skip_valuation'] ?? false;
        $providedCreditProfile = $additionalData['credit_profile'] ?? null;
        $providedValuation = $additionalData['vehicle_valuation'] ?? null;

        // Build event metadata
        $eventMetadata = array_merge($additionalData['metadata'] ?? [], [
          'request_id' => $requestId,
          'source' => $additionalData['source'] ?? 'api',
          'user' => $_SERVER['HTTP_X_GRAVITY_USER'] ?? 'system',
          'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
          'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);

        // Dispatch start event
        $this->dispatch(new PreQualStartedEvent(
          $applicant,
          $vehicle,
          $bureau,
          $eventMetadata
        ));

        Log::info("Starting pre-qualification evaluation", [
          'request_id' => $requestId,
          'applicant' => $applicant->getFullName(),
          'bureau' => $bureau,
          'skip_bureau' => $skipBureauPull,
          'skip_valuation' => $skipValuation
        ]);

        try {
            // Step 1: Get credit profile
            $creditResult = $this->getCreditProfile(
              $applicant,
              $bureau,
              $useCache,
              $skipBureauPull,
              $providedCreditProfile
            );

            $creditProfile = $creditResult['profile'];
            $fromCache = $creditResult['from_cache'];

            // Dispatch credit report event if we have a hit
            if ($creditProfile->hasHit) {
                $this->dispatch(new CreditReportPulledEvent(
                  $applicant,
                  $creditProfile,
                  $bureau,
                  $fromCache,
                  array_merge($eventMetadata, [
                    'pull_time' => microtime(true) - $startTime,
                    'has_score' => $creditProfile->hasValidScore()
                  ])
                ));
            }

            // Step 2: Get vehicle with valuation
            $vehicleWithValue = $this->getVehicleWithValuation(
              $vehicle,
              $skipValuation,
              $providedValuation,
              $eventMetadata
            );

            // Step 3: Calculate risk score and match lenders
            $riskScore = $this->riskScorer->calculateScore($applicant, $vehicleWithValue, $creditProfile);
            $riskTier = $this->riskScorer->getRiskTier($riskScore);
            $matchedLenders = $this->matchLenders($applicant, $vehicleWithValue, $creditProfile);

            // Step 4: Determine completeness
            $isComplete = $this->isResultComplete($creditProfile, $applicant, $vehicleWithValue);
            $missingReason = $isComplete ? null : $this->getMissingReason($creditProfile, $applicant, $vehicleWithValue);

            // Step 5: Build result metadata
            $resultMetadata = [
              'bureau_used' => $bureau,
              'score_factors' => $this->riskScorer->getScoreFactors(),
              'processing_time' => microtime(true) - $startTime,
              'from_cache' => $fromCache,
              'skipped_bureau_pull' => $skipBureauPull,
              'skipped_valuation' => $skipValuation,
              'request_id' => $requestId,
              'has_co_applicant' => $applicant->hasCoApplicant()
            ];

            // Step 6: Create result
            $result = new PreQualResult(
              approvalScore: $isComplete ? $riskScore : 0.0,
              riskTier: $isComplete ? $riskTier : 'manual_review',
              matchedLenders: $isComplete ? $matchedLenders : [],
              isComplete: $isComplete,
              missingReason: $missingReason,
              creditProfile: $creditProfile,
              ltv: $vehicleWithValue->calculateLTV(),
              dti: $applicant->calculateDTI($creditProfile->estimatedMonthlyDebt),
              metadata: $resultMetadata
            );

            // Dispatch completion event
            $this->dispatch(new PreQualCompletedEvent(
              $applicant,
              $vehicleWithValue,
              $creditProfile,
              $result,
              array_merge($eventMetadata, [
                'processing_time' => microtime(true) - $startTime,
                'completed_at' => date('Y-m-d H:i:s')
              ])
            ));

            Log::info("Pre-qualification evaluation completed", [
              'request_id' => $requestId,
              'is_approved' => $result->isApproved(),
              'risk_tier' => $result->getRiskTier(),
              'processing_time' => microtime(true) - $startTime
            ]);

            return $result;

        } catch (\Throwable $e) {
            // Dispatch failure event
            $this->dispatch(new PreQualFailedEvent(
              $applicant,
              $vehicle,
              $e->getMessage(),
              $e,
              array_merge($eventMetadata, [
                'processing_time' => microtime(true) - $startTime,
                'failed_at' => date('Y-m-d H:i:s')
              ])
            ));

            Log::error("PreQual engine evaluation failed", [
              'request_id' => $requestId,
              'error' => $e->getMessage(),
              'trace' => $e->getTraceAsString()
            ]);

            throw new AutomationException(
              'Failed to evaluate pre-qualification: ' . $e->getMessage(),
              0,
              $e,
              ['request_id' => $requestId]
            );
        }
    }

    /**
     * Get credit profile from provided data, cache, or bureau
     *
     * @return array{profile: CreditProfile, from_cache: bool}
     * @throws AutomationException
     */
    private function getCreditProfile(
      Applicant $applicant,
      string $bureau,
      bool $useCache,
      bool $skipBureauPull,
      ?array $providedCreditProfile
    ): array {
        // Option 1: Use provided credit profile data
        if ($providedCreditProfile !== null) {
            Log::info("Using provided credit profile data");

            // If it's already a CreditProfile object
            if ($providedCreditProfile instanceof CreditProfile) {
                return ['profile' => $providedCreditProfile, 'from_cache' => false];
            }

            // If it's an array, convert to CreditProfile
            try {
                $profile = CreditProfile::fromArray($providedCreditProfile);
                return ['profile' => $profile, 'from_cache' => false];
            } catch (\Exception $e) {
                Log::warn("Failed to parse provided credit profile: " . $e->getMessage());
                throw new ValidationException("Invalid credit profile data provided");
            }
        }

        // Option 2: Check cache if enabled
        if ($useCache && !empty($applicant->ssn)) {
            Log::debug("Checking cache for credit profile", [
              'bureau' => $bureau,
              'ssn_suffix' => substr($applicant->ssn, -4)
            ]);

            $cachedProfile = $this->cacheService->get($applicant->ssn, $bureau);
            if ($cachedProfile !== null) {
                Log::info("Using cached credit profile", [
                  'bureau' => $bureau,
                  'cached_at' => $cachedProfile->pulledAt
                ]);
                return ['profile' => $cachedProfile, 'from_cache' => true];
            }
        }

        // Option 3: Skip bureau pull if requested (return no-hit profile)
        if ($skipBureauPull) {
            Log::info("Skipping bureau pull, returning no-hit profile");
            return [
              'profile' => $this->createNoHitProfile($bureau),
              'from_cache' => false
            ];
        }

        // Option 4: Pull from bureau
        Log::info("Pulling credit report from bureau", ['bureau' => $bureau]);
        $creditProfile = $this->pullCreditReport($applicant, $bureau);

        // Cache the result if enabled and we got a hit
        if ($useCache && !empty($applicant->ssn) && $creditProfile->hasHit) {
            try {
                $this->cacheService->set($applicant->ssn, $bureau, $creditProfile);
                Log::debug("Cached credit profile", ['bureau' => $bureau]);
            } catch (\Exception $e) {
                Log::warn("Failed to cache credit profile: " . $e->getMessage());
            }
        }

        return ['profile' => $creditProfile, 'from_cache' => false];
    }

    /**
     * Get vehicle with valuation
     *
     * @throws ValidationException|\WF\API\Automation\Exceptions\AutomationException
     */
    private function getVehicleWithValuation(
      Vehicle $vehicle,
      bool $skipValuation,
      ?array $providedValuation,
      array $eventMetadata = []
    ): Vehicle {
        // If vehicle already has value, return as-is
        if ($vehicle->vehicleValue !== null && $vehicle->vehicleValue > 0) {
            Log::debug("Vehicle already has value", ['value' => $vehicle->vehicleValue]);
            return $vehicle;
        }

        // If valuation data was provided, use it
        if ($providedValuation !== null) {
            $value = $providedValuation['value'] ?? $providedValuation['vehicle_value'] ?? 0;
            if ($value > 0) {
                Log::info("Using provided vehicle valuation", ['value' => $value]);

                $vehicleWithValue = new Vehicle(
                  vin: $vehicle->vin,
                  year: $vehicle->year,
                  make: $vehicle->make,
                  model: $vehicle->model,
                  mileage: $vehicle->mileage,
                  loanAmount: $vehicle->loanAmount,
                  vehicleValue: (float)$value,
                  condition: $vehicle->condition
                );

                // Dispatch valuation event with provided data
                $this->dispatch(new VehicleValuationCompletedEvent(
                  $vehicleWithValue,
                  $providedValuation,
                  'provided',
                  array_merge($eventMetadata, ['source' => 'provided'])
                ));

                return $vehicleWithValue;
            }
        }

        // If skipping valuation, return vehicle as-is
        if ($skipValuation) {
            Log::info("Skipping vehicle valuation");
            return $vehicle;
        }

        // Otherwise, get valuation from provider
        Log::info("Getting vehicle valuation", [
          'vin' => substr($vehicle->vin, 0, 8) . '...',
          'mileage' => $vehicle->mileage
        ]);

        try {
            $provider = $this->valuationFactory->createBest();
            $valuationData = $provider->getValuation(
              $vehicle->vin,
              $vehicle->mileage,
              '', // ZIP code - could be passed in eventMetadata
              $vehicle->condition
            );

            $vehicleWithValue = new Vehicle(
              vin: $vehicle->vin,
              year: $vehicle->year,
              make: $vehicle->make,
              model: $vehicle->model,
              mileage: $vehicle->mileage,
              loanAmount: $vehicle->loanAmount,
              vehicleValue: (float)$valuationData['value'],
              condition: $vehicle->condition
            );

            // Dispatch valuation completed event
            $this->dispatch(new VehicleValuationCompletedEvent(
              $vehicleWithValue,
              $valuationData,
              $provider->getProviderName(),
              array_merge($eventMetadata, ['source' => 'api'])
            ));

            Log::info("Vehicle valuation completed", [
              'provider' => $provider->getProviderName(),
              'value' => $valuationData['value']
            ]);

            return $vehicleWithValue;

        } catch (ValuationException $e) {
            Log::error("Vehicle valuation failed: " . $e->getMessage());
            throw new AutomationException("Failed to get vehicle valuation", 0, $e);
        }
    }

    /**
     * Pull credit report from bureau
     *
     * @throws AutomationException
     */
    private function pullCreditReport(Applicant $applicant, string $bureau): CreditProfile
    {
        try {
            $client = $this->bureauFactory->create($bureau);
            $parser = $this->parserFactory->create($bureau);
        } catch (NotFoundExceptionInterface|ContainerExceptionInterface|AutomationException $e) {
            Log::error("Failed to create bureau client or parser", [
              'bureau' => $bureau,
              'error' => $e->getMessage()
            ]);
            throw new AutomationException("Bureau service unavailable: $bureau", 0, $e);
        }

        // Build consumer data array
        $consumers = [$this->buildConsumerData($applicant)];

        // Add co-applicant if present
        if ($applicant->hasCoApplicant()) {
            $coApp = $applicant->coApplicant;
            $consumers[] = [
              'firstName' => $coApp['first_name'],
              'lastName' => $coApp['last_name'],
              'middleName' => $coApp['middle_name'] ?? '',
              'ssn' => $coApp['ssn'],
              'address' => $coApp['address'],
              'city' => $coApp['city'],
              'state' => $coApp['state'],
              'zip' => $coApp['zip'],
              'dob' => $coApp['dob'],
            ];
        }

        try {
            Log::debug("Pulling credit report", [
              'bureau' => $bureau,
              'consumer_count' => count($consumers)
            ]);

            $rawResponse = $client->pullCreditReport($consumers);
            $creditProfile = $parser->parse($rawResponse);

            Log::info("Credit report pulled successfully", [
              'bureau' => $bureau,
              'has_score' => $creditProfile->hasValidScore(),
              'score' => $creditProfile->ficoScore
            ]);

            return $creditProfile;

        } catch (BureauApiException $e) {
            Log::error("Bureau API error", [
              'bureau' => $bureau,
              'error' => $e->getMessage()
            ]);
            throw new AutomationException("Credit bureau error: " . $e->getMessage(), 0, $e);
        } catch (\Exception $e) {
            Log::error("Unexpected error pulling credit", [
              'bureau' => $bureau,
              'error' => $e->getMessage()
            ]);
            throw new AutomationException("Failed to pull credit report", 0, $e);
        }
    }

    /**
     * Build consumer data for bureau request
     */
    private function buildConsumerData(Applicant $applicant): array
    {
        $middleName = '';
        if (property_exists($applicant, 'middleName')) {
            $middleName = $applicant->middleName;
        }

        return [
          'firstName' => $applicant->firstName,
          'lastName' => $applicant->lastName,
          'middleName' => $middleName,
          'ssn' => $applicant->ssn,
          'address' => $applicant->address,
          'city' => $applicant->city,
          'state' => $applicant->state,
          'zip' => $applicant->zipCode,
          'dob' => $applicant->dateOfBirth,
        ];
    }

    /**
     * Create a no-hit credit profile
     */
    private function createNoHitProfile(string $bureau): CreditProfile
    {
        return CreditProfile::fromArray([
          'fico_score' => null,
          'bureau' => $bureau,
          'open_trade_count' => 0,
          'auto_trade_count' => 0,
          'derogatory_marks' => 0,
          'bankruptcies' => 0,
          'revolving_utilization' => 0,
          'inquiries_6mo' => 0,
          'estimated_monthly_debt' => 0,
          'trade_lines' => [],
          'score_factors' => [],
          'hit' => false,
          'pulled_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Match lenders based on criteria
     */
    private function matchLenders(Applicant $applicant, Vehicle $vehicle, CreditProfile $credit): array
    {
        // Check if RiskScorer has matchLenders method
        if (method_exists($this->riskScorer, 'matchLenders')) {
            return $this->riskScorer->matchLenders($applicant, $vehicle, $credit);
        }

        // Default implementation if method doesn't exist
        Log::warn("RiskScorer doesn't implement matchLenders method");
        return [];
    }

    /**
     * Check if result is complete
     */
    private function isResultComplete(CreditProfile $credit, Applicant $applicant, Vehicle $vehicle): bool
    {
        // Must have valid credit score
        if (!$credit->hasValidScore()) {
            Log::debug("Result incomplete: no valid credit score");
            return false;
        }

        // Must have income
        if ($applicant->monthlyIncome <= 0) {
            Log::debug("Result incomplete: no monthly income");
            return false;
        }

        // Must have vehicle value for LTV calculation
        if ($vehicle->vehicleValue === null || $vehicle->vehicleValue <= 0) {
            Log::debug("Result incomplete: no vehicle value");
            return false;
        }

        return true;
    }

    /**
     * Get reason why result is not complete
     */
    private function getMissingReason(CreditProfile $credit, Applicant $applicant, Vehicle $vehicle): string
    {
        if (!$credit->hasValidScore()) {
            return 'missing_fico';
        }

        if ($applicant->monthlyIncome <= 0) {
            return 'missing_income';
        }

        if ($vehicle->vehicleValue === null || $vehicle->vehicleValue <= 0) {
            return 'missing_vehicle_value';
        }

        return 'unknown';
    }

    /**
     * Dispatch event if dispatcher is available
     */
    private function dispatch(object $event): void
    {
        if ($this->eventDispatcher !== null) {
            try {
                $this->eventDispatcher->dispatch($event);
            } catch (\Exception $e) {
                // Log but don't fail the main process
                Log::error("Event dispatch failed", [
                  'event' => get_class($event),
                  'error' => $e->getMessage()
                ]);
            }
        }
    }
}
