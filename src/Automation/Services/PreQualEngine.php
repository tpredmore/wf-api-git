<?php

declare(strict_types=1);

namespace WF\API\Automation\Services;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use WF\API\Automation\Contracts\PreQualEngineInterface;
use WF\API\Automation\Contracts\RiskScorerInterface;
use WF\API\Automation\Exceptions\ValuationException;
use WF\API\Automation\Factories\BureauClientFactory;
use WF\API\Automation\Factories\CreditParserFactory;
use WF\API\Automation\Factories\ValuationProviderFactory;
use WF\API\Automation\Models\Applicant;
use WF\API\Automation\Models\Vehicle;
use WF\API\Automation\Models\CreditProfile;
use WF\API\Automation\Models\PreQualResult;
use WF\API\Automation\Exceptions\AutomationException;
use Log;

class PreQualEngine implements PreQualEngineInterface
{
    public function __construct(
      private RiskScorerInterface $riskScorer,
      private BureauClientFactory $bureauFactory,
      private CreditParserFactory $parserFactory,
      private ValuationProviderFactory $valuationFactory,
      private BureauCacheService $cacheService
    ) {}

    /**
     * @throws AutomationException
     */
    public function evaluate(Applicant $applicant, Vehicle $vehicle, array $additionalData = []): PreQualResult
    {
        $bureau = $additionalData['preferred_bureau'] ?? 'experian';
        $useCache = $additionalData['use_cache'] ?? true;
        $skipBureauPull = $additionalData['skip_bureau_pull'] ?? false;
        $skipValuation = $additionalData['skip_valuation'] ?? false;
        $providedCreditProfile = $additionalData['credit_profile'] ?? null;
        $providedValuation = $additionalData['vehicle_valuation'] ?? null;

        // Track if we used cache
        $fromCache = false;

        try {
            // Step 1: Get credit profile (from provided data, cache, or bureau)
            $result = $this->getCreditProfile(
              $applicant,
              $bureau,
              $useCache,
              $skipBureauPull,
              $providedCreditProfile
            );

            $creditProfile = $result['profile'];
            $fromCache = $result['from_cache'];

            // Step 2: Get vehicle with valuation
            $vehicleWithValue = $this->getVehicleWithValuation(
              $vehicle,
              $skipValuation,
              $providedValuation
            );

            // Step 3: Calculate risk score and match lenders
            $riskScore = $this->riskScorer->calculateScore($applicant, $vehicleWithValue, $creditProfile);
            $riskTier = $this->riskScorer->getRiskTier($riskScore);
            $matchedLenders = $this->matchLenders($applicant, $vehicleWithValue, $creditProfile);

            // Step 4: Determine completeness
            $isComplete = $this->isResultComplete($creditProfile, $applicant, $vehicleWithValue);
            $missingReason = $isComplete ? null : $this->getMissingReason($creditProfile, $applicant, $vehicleWithValue);

            return new PreQualResult(
              approvalScore: $isComplete ? $riskScore : 0.0,
              riskTier: $isComplete ? $riskTier : 'manual_review',
              matchedLenders: $isComplete ? $matchedLenders : [],
              isComplete: $isComplete,
              missingReason: $missingReason,
              creditProfile: $creditProfile,
              ltv: $vehicleWithValue->calculateLTV(),
              dti: $applicant->calculateDTI($creditProfile->estimatedMonthlyDebt),
              metadata: [
                'bureau_used' => $bureau,
                'score_factors' => $this->riskScorer->getScoreFactors(),
                'processing_time' => microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? 0),
                'from_cache' => $fromCache,
                'skipped_bureau_pull' => $skipBureauPull,
                'skipped_valuation' => $skipValuation
              ]
            );

        } catch (\Throwable $e) {
            Log::error("PreQual engine evaluation failed: " . $e->getMessage());
            throw new AutomationException('Failed to evaluate pre-qualification', 0, $e);
        }
    }

    /**
     * Get credit profile from provided data, cache, or bureau
     * Returns array with profile and cache flag
     *
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
            return [
              'profile' => CreditProfile::fromArray($providedCreditProfile),
              'from_cache' => false
            ];
        }

        // Option 2: Check cache if enabled
        if ($useCache && !empty($applicant->ssn)) {
            $cachedProfile = $this->cacheService->get($applicant->ssn, $bureau);
            if ($cachedProfile !== null) {
                Log::info("Using cached credit profile for bureau: $bureau");
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
        $creditProfile = $this->pullCreditReport($applicant, $bureau);

        // Cache the result if enabled
        if ($useCache && !empty($applicant->ssn) && $creditProfile->hasHit) {
            $this->cacheService->set($applicant->ssn, $bureau, $creditProfile);
        }

        return ['profile' => $creditProfile, 'from_cache' => false];
    }

    /**
     * Get vehicle with valuation
     *
     * @throws ValuationException
     * @throws \WF\API\Automation\Exceptions\ValidationException
     */
    private function getVehicleWithValuation(
      Vehicle $vehicle,
      bool $skipValuation,
      ?array $providedValuation
    ): Vehicle {
        // If vehicle already has value, return as-is
        if ($vehicle->vehicleValue !== null && $vehicle->vehicleValue > 0) {
            return $vehicle;
        }

        // If valuation data was provided, use it
        if ($providedValuation !== null) {
            $value = $providedValuation['value'] ?? $providedValuation['vehicle_value'] ?? 0;
            if ($value > 0) {
                Log::info("Using provided vehicle valuation: $value");
                return new Vehicle(
                  vin: $vehicle->vin,
                  year: $vehicle->year,
                  make: $vehicle->make,
                  model: $vehicle->model,
                  mileage: $vehicle->mileage,
                  loanAmount: $vehicle->loanAmount,
                  vehicleValue: (float)$value,
                  condition: $vehicle->condition
                );
            }
        }

        // If skipping valuation, return vehicle as-is
        if ($skipValuation) {
            Log::info("Skipping vehicle valuation");
            return $vehicle;
        }

        // Otherwise, get valuation from provider
        return $this->getVehicleValuation($vehicle);
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
        // RiskScorer already has this method, but it's not in the interface
        // For now, we'll use a simple implementation
        if (method_exists($this->riskScorer, 'matchLenders')) {
            return $this->riskScorer->matchLenders($applicant, $vehicle, $credit);
        }

        // Default implementation
        return [];
    }

    /**
     * @throws AutomationException
     */
    private function pullCreditReport(Applicant $applicant, string $bureau): CreditProfile
    {
        try {
            $client = $this->bureauFactory->create($bureau);
        } catch (NotFoundExceptionInterface|ContainerExceptionInterface|AutomationException $e) {
            Log::error("Loading bureau client factory failed: " . $e->getMessage());
            throw new AutomationException('Failed to loading bureau client factory', 0, $e);
        }

        $parser = $this->parserFactory->create($bureau);

        // Add middleName property to Applicant model or handle it here
        $middleName = '';
        if (property_exists($applicant, 'middleName')) {
            $middleName = $applicant->middleName;
        }

        $consumers = [
          [
            'firstName' => $applicant->firstName,
            'lastName' => $applicant->lastName,
            'middleName' => $middleName,
            'ssn' => $applicant->ssn,
            'address' => $applicant->address,
            'city' => $applicant->city,
            'state' => $applicant->state,
            'zip' => $applicant->zipCode,
            'dob' => $applicant->dateOfBirth,
          ]
        ];

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

        $rawResponse = $client->pullCreditReport($consumers);
        return $parser->parse($rawResponse);
    }

    /**
     * @throws \WF\API\Automation\Exceptions\ValuationException
     * @throws \WF\API\Automation\Exceptions\ValidationException
     */
    private function getVehicleValuation(Vehicle $vehicle): Vehicle
    {
        $provider = $this->valuationFactory->createBest();
        $valuation = $provider->getValuation($vehicle->vin, $vehicle->mileage, '', $vehicle->condition);

        return new Vehicle(
          vin: $vehicle->vin,
          year: $vehicle->year,
          make: $vehicle->make,
          model: $vehicle->model,
          mileage: $vehicle->mileage,
          loanAmount: $vehicle->loanAmount,
          vehicleValue: (float)$valuation['value'],
          condition: $vehicle->condition
        );
    }

    private function isResultComplete(CreditProfile $credit, Applicant $applicant, Vehicle $vehicle): bool
    {
        return $credit->hasValidScore()
          && $applicant->monthlyIncome > 0
          && $vehicle->vehicleValue > 0;
    }

    private function getMissingReason(CreditProfile $credit, Applicant $applicant, Vehicle $vehicle): string
    {
        if (!$credit->hasValidScore()) return 'missing_fico';
        if ($applicant->monthlyIncome <= 0) return 'missing_income';
        if ($vehicle->vehicleValue <= 0) return 'missing_vehicle_value';
        return 'unknown';
    }
}
