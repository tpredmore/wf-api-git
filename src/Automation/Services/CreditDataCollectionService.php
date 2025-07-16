<?php

namespace WF\API\Automation\Services;

use WF\API\Automation\Models\CreditProfile;
use WF\API\Automation\Models\Applicant;
use WF\API\Automation\Models\PreQualResult;
use WF\API\Automation\Constants\GenderMapConstants;
use MySql;
use Log;

class CreditDataCollectionService
{
    private string $encryptionKey;
    private string $database;

    public function __construct(string $encryptionKey, string $database = 'wildfire_automation')
    {
        $this->encryptionKey = $encryptionKey;
        $this->database = $database;
    }

    /**
     * @throws \WF\API\Automation\Exceptions\ValidationException
     */
    public function collectAndStore(
      Applicant $applicant,
      CreditProfile $creditProfile,
      PreQualResult $result,
      array $requestData
    ): void {
        try {
            // Note: MySql class doesn't support transactions directly
            // You may need to implement transaction support in stored procedures
            // or handle it at the database level

            // 1. Create or update person
            $personId = $this->upsertPerson($applicant, $requestData);

            // 2. Record demographics
            $this->recordDemographics($personId, $applicant);

            // 3. Record address
            $this->recordAddress($personId, $applicant);

            // 4. Record credit pull
            $pullId = $this->recordCreditPull($personId, $creditProfile, $requestData);

            // 5. Record ML training data
            $this->recordMLData($personId, $pullId, $creditProfile, $result);

            // 6. Check for co-applicant
            if ($applicant->hasCoApplicant()) {
                $this->processCoApplicant($applicant->coApplicant, $creditProfile, $result, $requestData);
            }

        } catch (\Exception $e) {
            Log::error("Failed to collect credit data: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * @throws \Exception
     */
    private function upsertPerson(Applicant $applicant, array $requestData): int
    {
        $gender = $this->inferGender($applicant->firstName);

        $params = [
          $applicant->ssn,
          $applicant->firstName,
          $applicant->lastName,
          $applicant->middleName ?? '',
          $applicant->dateOfBirth,
          $gender,
          $this->encryptionKey,
          $_SERVER['HTTP_X_GRAVITY_USER'] ?? 'system',
          $_SERVER['REMOTE_ADDR'] ?? ''
        ];

        $result = MySql::call($this->database, 'sp_upsert_person', $params);

        if (!$result || empty($result)) {
            throw new \Exception("Failed to upsert person");
        }

        // The stored procedure should return the person_id
        return (int)($result[0]['person_id'] ?? 0);
    }

    /**
     * @throws \Exception
     */
    private function recordDemographics(int $personId, Applicant $applicant): void
    {
        $params = [
          $personId,
          $applicant->employmentType,
          $applicant->monthlyIncome,
          $applicant->monthlyDebt,
          '', // marital_status - not in current model
          '', // dependents_count
          '', // housing_status
          '', // education_level
          '', // occupation
          '', // employer_name
          ''  // employment_years
        ];

        $result = MySql::call($this->database, 'sp_record_demographics', $params);

        if ($result === false) {
            throw new \Exception("Failed to record demographics: " . MySql::last_error());
        }
    }

    private function recordAddress(int $personId, Applicant $applicant): void
    {
        // First, mark any existing current addresses as not current
        $updateQuery = "UPDATE person_addresses 
                        SET is_current = FALSE 
                        WHERE person_id = %1 AND is_current = TRUE";

        $updateResult = MySql::escaped_query($this->database, $updateQuery, [$personId]);

        if ($updateResult === false) {
            throw new \Exception("Failed to update existing addresses: " . MySql::last_error());
        }

        // Insert new current address
        $county = $this->getCountyFromZip($applicant->zipCode);

        $insertQuery = "INSERT INTO person_addresses (
                            person_id, address_type, street_address, city, state, zip_code, 
                            county, is_current, move_in_date
                        ) VALUES (%1, 'current', %2, %3, %4, %5, %6, TRUE, NULL)";

        $insertParams = [
          $personId,
          $applicant->address,
          $applicant->city,
          $applicant->state,
          $applicant->zipCode,
          $county ?? ''
        ];

        $insertResult = MySql::escaped_query($this->database, $insertQuery, $insertParams);

        if ($insertResult === false) {
            throw new \Exception("Failed to insert new address: " . MySql::last_error());
        }
    }

    private function recordCreditPull(int $personId, CreditProfile $creditProfile, array $requestData): int
    {
        // Prepare credit analytics data
        $creditData = $this->extractCreditAnalytics($creditProfile);

        $params = [
          $personId,
          $requestData['request_id'] ?? uniqid(),
          $creditProfile->bureau,
          'soft',
          $creditProfile->ficoScore,
          '', // vantage score if available
          '', // bureau risk score
          $creditProfile->hasHit ? '1' : '0',
          $creditProfile->pulledAt ?? date('Y-m-d H:i:s'),
          json_encode($creditData)
        ];

        $result = MySql::call($this->database, 'sp_record_credit_pull', $params);

        if (!$result || empty($result)) {
            throw new \Exception("Failed to record credit pull");
        }

        // The stored procedure should return the pull_id
        return (int)($result[0]['pull_id'] ?? 0);
    }

    private function recordMLData(int $personId, int $pullId, CreditProfile $creditProfile, PreQualResult $result): void
    {
        $features = $this->extractMLFeatures($creditProfile, $result);
        $refinanceData = $this->calculateRefinanceOpportunity($creditProfile, $result);

        $params = [
          $personId,
          $pullId,
          json_encode($features),
          $result->approvalScore,
          $result->riskTier,
          count($result->matchedLenders),
          $this->getLowestAPR($result->matchedLenders) ?? '0',
          $refinanceData['eligible'] ? '1' : '0',
          $refinanceData['monthly_savings'],
          $refinanceData['apr_reduction'],
          'v1.0.0', // model version
          $this->calculateConfidence($result)
        ];

        $result = MySql::call($this->database, 'sp_record_ml_training_data', $params);

        if ($result === false) {
            throw new \Exception("Failed to record ML training data: " . MySql::last_error());
        }
    }

    /**
     * @throws \WF\API\Automation\Exceptions\ValidationException
     */
    private function processCoApplicant(array $coApplicant, CreditProfile $creditProfile, PreQualResult $result, array $requestData): void
    {
        // Create a temporary Applicant object for co-applicant
        $coApplicantObj = Applicant::fromArray([
          'monthly_income' => $coApplicant['monthly_income'] ?? 0,
          'employment_type' => $coApplicant['employment_type'] ?? 'other',
          'state' => $coApplicant['state'] ?? '',
          'first_name' => $coApplicant['first_name'] ?? '',
          'last_name' => $coApplicant['last_name'] ?? '',
          'ssn' => $coApplicant['ssn'] ?? '',
          'address' => $coApplicant['address'] ?? '',
          'city' => $coApplicant['city'] ?? '',
          'zip_code' => $coApplicant['zip'] ?? '',
          'date_of_birth' => $coApplicant['dob'] ?? ''
        ]);

        // Process co-applicant data
        $coPersonId = $this->upsertPerson($coApplicantObj, $requestData);
        $this->recordDemographics($coPersonId, $coApplicantObj);
        $this->recordAddress($coPersonId, $coApplicantObj);

        // Link co-applicant to same credit pull
        // You might want to add a linking table for co-applicants
    }

    private function extractCreditAnalytics(CreditProfile $creditProfile): array
    {
        $analytics = [
          'open_accounts_count' => $creditProfile->openTradeCount,
          'total_accounts_count' => count($creditProfile->tradeLines),
          'auto_loans_count' => $creditProfile->autoTradeCount,
          'derogatory_marks_count' => $creditProfile->derogatoryMarks,
          'bankruptcies_count' => $creditProfile->bankruptcies,
          'revolving_utilization' => $creditProfile->revolvingUtilization * 100,
          'inquiries_last_6_months' => $creditProfile->inquiriesSixMonths,
          'total_monthly_debt' => $creditProfile->estimatedMonthlyDebt
        ];

        // Extract auto loan details
        $autoLoanDetails = $this->extractAutoLoanDetails($creditProfile->tradeLines);

        // Extract additional analytics
        $creditAge = $this->calculateCreditAge($creditProfile->tradeLines);
        $paymentHistory = $this->analyzePaymentHistory($creditProfile->tradeLines);
        $accountDiversity = $this->calculateAccountDiversity($creditProfile->tradeLines);

        return array_merge($analytics, $autoLoanDetails, $creditAge, $paymentHistory, $accountDiversity);
    }

    private function extractAutoLoanDetails(array $tradeLines): array
    {
        $details = [
          'open_auto_loans_count' => 0,
          'current_auto_balance' => null,
          'current_auto_payment' => null,
          'current_auto_apr' => null,
          'auto_loan_months_remaining' => null,
          'auto_payment_history_score' => null,
          'revolving_credit_limit' => 0,
          'revolving_balance' => 0,
          'late_payments_30_days' => 0,
          'late_payments_60_days' => 0,
          'late_payments_90_plus' => 0,
          'collections_count' => 0
        ];

        foreach ($tradeLines as $trade) {
            // Count late payments
            $details['late_payments_30_days'] += (int)($trade['derogatory_30'] ?? 0);
            $details['late_payments_60_days'] += (int)($trade['derogatory_60'] ?? 0);
            $details['late_payments_90_plus'] += (int)($trade['derogatory_90'] ?? 0);

            // Count collections
            if (stripos($trade['type'] ?? '', 'collection') !== false) {
                $details['collections_count']++;
            }

            // Process revolving accounts
            if ($this->isRevolvingAccount($trade)) {
                $details['revolving_credit_limit'] += (float)($trade['credit_limit'] ?? 0);
                $details['revolving_balance'] += (float)($trade['balance'] ?? 0);
            }

            // Process auto loans
            if ($this->isAutoLoan($trade) && $trade['is_open']) {
                $details['open_auto_loans_count']++;

                // Use the most recent/highest balance auto loan
                if ($details['current_auto_balance'] === null ||
                  $trade['balance'] > $details['current_auto_balance']) {

                    $details['current_auto_balance'] = $trade['balance'];
                    $details['current_auto_payment'] = $trade['payment'];
                    $details['current_auto_apr'] = $this->extractAPR($trade);
                    $details['auto_loan_months_remaining'] = $this->calculateRemainingMonths($trade);
                    $details['auto_payment_history_score'] = $this->calculatePaymentScore($trade);
                }
            }
        }

        return $details;
    }

    private function extractMLFeatures(CreditProfile $creditProfile, PreQualResult $result): array
    {
        return [
            // Basic credit metrics
          'fico_score' => $creditProfile->ficoScore,
          'fico_tier' => $this->getFicoTier($creditProfile->ficoScore),
          'bureau' => $creditProfile->bureau,

            // Account metrics
          'total_accounts' => count($creditProfile->tradeLines),
          'open_accounts' => $creditProfile->openTradeCount,
          'account_diversity_score' => $this->calculateAccountDiversityScore($creditProfile->tradeLines),

            // Auto loan specific
          'has_auto_loan' => $creditProfile->autoTradeCount > 0,
          'auto_loan_count' => $creditProfile->autoTradeCount,

            // Risk indicators
          'derogatory_marks' => $creditProfile->derogatoryMarks,
          'bankruptcies' => $creditProfile->bankruptcies,
          'recent_inquiries_6mo' => $creditProfile->inquiriesSixMonths,

            // Utilization
          'revolving_utilization' => $creditProfile->revolvingUtilization,
          'debt_to_income_estimated' => $result->dti,

            // Age factors
          'credit_history_months' => $this->getOldestAccountAge($creditProfile->tradeLines),
          'avg_account_age_months' => $this->getAverageAccountAge($creditProfile->tradeLines),

            // Payment patterns
          'on_time_payment_percentage' => $this->calculateOnTimePaymentPercentage($creditProfile->tradeLines),
          'recent_delinquency_months' => $this->getMonthsSinceLastDelinquency($creditProfile->tradeLines),

            // Risk assessment
          'risk_score' => $result->approvalScore,
          'risk_tier' => $result->riskTier,
          'approval_probability' => $this->calculateApprovalProbability($result),

            // Market conditions (could be enhanced with external data)
          'pulled_date' => $creditProfile->pulledAt,
          'day_of_week' => date('w', strtotime($creditProfile->pulledAt)),
          'month' => date('n', strtotime($creditProfile->pulledAt))
        ];
    }

    private function calculateRefinanceOpportunity(CreditProfile $creditProfile, PreQualResult $result): array
    {
        $currentAutoLoan = $this->getCurrentAutoLoan($creditProfile->tradeLines);

        if (!$currentAutoLoan || !isset($currentAutoLoan['apr'])) {
            return [
              'eligible' => false,
              'monthly_savings' => 0,
              'apr_reduction' => 0
            ];
        }

        // Calculate potential new rate based on credit score and market
        $potentialAPR = $this->calculatePotentialAPR($creditProfile->ficoScore, $result->riskTier);

        if ($potentialAPR >= $currentAutoLoan['apr']) {
            return [
              'eligible' => false,
              'monthly_savings' => 0,
              'apr_reduction' => 0
            ];
        }

        // Calculate savings
        $remainingBalance = $currentAutoLoan['balance'];
        $remainingMonths = $currentAutoLoan['months_remaining'] ?? 36;

        $currentPayment = $currentAutoLoan['payment'];
        $newPayment = $this->calculateMonthlyPayment($remainingBalance, $potentialAPR, $remainingMonths);

        $monthlySavings = $currentPayment - $newPayment;

        return [
          'eligible' => $monthlySavings >= 25, // Minimum $25/month savings
          'monthly_savings' => round($monthlySavings, 2),
          'apr_reduction' => round($currentAutoLoan['apr'] - $potentialAPR, 2)
        ];
    }

    private function isAutoLoan(array $trade): bool
    {
        $type = strtolower($trade['type'] ?? '');
        return stripos($type, 'auto') !== false ||
          stripos($type, 'vehicle') !== false ||
          stripos($type, 'car') !== false ||
          in_array($trade['type'] ?? '', ['00', '3A']); // Common auto loan codes
    }

    private function isRevolvingAccount(array $trade): bool
    {
        $type = strtolower($trade['type'] ?? '');
        return stripos($type, 'credit card') !== false ||
          stripos($type, 'revolving') !== false ||
          stripos($type, 'charge account') !== false;
    }

    private function extractAPR(array $trade): ?float
    {
        // Try to extract APR from various fields
        if (isset($trade['apr'])) {
            return (float)$trade['apr'];
        }

        // Parse from terms field
        if (isset($trade['terms'])) {
            if (preg_match('/(\d+\.?\d*)%/', $trade['terms'], $matches)) {
                return (float)$matches[1];
            }
        }

        // Parse from extra field
        if (isset($trade['extra'])) {
            if (preg_match('/APR[:\s]+(\d+\.?\d*)/', $trade['extra'], $matches)) {
                return (float)$matches[1];
            }
        }

        // Estimate based on payment and balance if available
        if (isset($trade['payment']) && isset($trade['balance']) && isset($trade['original_amount'])) {
            return $this->estimateAPR($trade['balance'], $trade['payment'], $trade['terms']);
        }

        return null;
    }

    private function calculateRemainingMonths(array $trade): ?int
    {
        if (!isset($trade['balance']) || !isset($trade['payment']) || $trade['payment'] <= 0) {
            return null;
        }

        // Simple calculation - could be enhanced
        return (int)ceil($trade['balance'] / $trade['payment']);
    }

    private function calculatePaymentScore(array $trade): float
    {
        $score = 1.0;

        // Deduct for late payments
        $late30 = (int)($trade['derogatory_30'] ?? 0);
        $late60 = (int)($trade['derogatory_60'] ?? 0);
        $late90 = (int)($trade['derogatory_90'] ?? 0);

        $score -= ($late30 * 0.1);
        $score -= ($late60 * 0.2);
        $score -= ($late90 * 0.3);

        // Check payment history string if available
        if (isset($trade['payment_history'])) {
            $history = $trade['payment_history'];
            $totalMonths = strlen($history);
            $onTimeMonths = substr_count($history, '0') + substr_count($history, 'C');

            if ($totalMonths > 0) {
                $score = $onTimeMonths / $totalMonths;
            }
        }

        return max(0, min(1, $score));
    }

    /**
     * @throws \DateMalformedStringException
     */
    private function calculateCreditAge(array $tradeLines): array
    {
        $ages = [];
        $now = new \DateTime();

        foreach ($tradeLines as $trade) {
            if (isset($trade['opened']) && !empty($trade['opened'])) {
                $opened = new \DateTime($trade['opened']);
                $diff = $now->diff($opened);
                $months = ($diff->y * 12) + $diff->m;
                $ages[] = $months;
            }
        }

        if (empty($ages)) {
            return [
              'oldest_account_months' => 0,
              'average_account_age_months' => 0,
              'newest_account_months' => 0
            ];
        }

        return [
          'oldest_account_months' => max($ages),
          'average_account_age_months' => (int)round(array_sum($ages) / count($ages)),
          'newest_account_months' => min($ages)
        ];
    }

    private function analyzePaymentHistory(array $tradeLines): array
    {
        $totalAccounts = count($tradeLines);
        $accountsNeverLate = 0;
        $recentDelinquencies = 0;

        foreach ($tradeLines as $trade) {
            $hasLatePayment =
              ($trade['derogatory_30'] ?? 0) > 0 ||
              ($trade['derogatory_60'] ?? 0) > 0 ||
              ($trade['derogatory_90'] ?? 0) > 0;

            if (!$hasLatePayment) {
                $accountsNeverLate++;
            }

            // Check for recent delinquencies (last 12 months)
            if (isset($trade['payment_history'])) {
                $recentHistory = substr($trade['payment_history'], 0, 12);
                if (preg_match('/[1-9]/', $recentHistory)) {
                    $recentDelinquencies++;
                }
            }
        }

        return [
          'perfect_payment_percentage' => $totalAccounts > 0 ?
            round(($accountsNeverLate / $totalAccounts) * 100, 2) : 0,
          'recent_delinquent_accounts' => $recentDelinquencies
        ];
    }

    private function calculateAccountDiversity(array $tradeLines): array
    {
        $types = [];
        $lenders = [];

        foreach ($tradeLines as $trade) {
            $types[] = $trade['type'] ?? 'unknown';
            $lenders[] = $trade['creditor'] ?? 'unknown';
        }

        return [
          'unique_account_types' => count(array_unique($types)),
          'unique_lenders' => count(array_unique($lenders)),
          'has_mix_of_credit' => $this->hasGoodCreditMix($types)
        ];
    }

    private function hasGoodCreditMix(array $types): bool
    {
        $hasRevolving = false;
        $hasInstallment = false;
        $hasMortgage = false;

        foreach ($types as $type) {
            $typeLower = strtolower($type);
            if (stripos($typeLower, 'credit card') !== false || stripos($typeLower, 'revolving') !== false) {
                $hasRevolving = true;
            }
            if (stripos($typeLower, 'auto') !== false || stripos($typeLower, 'personal') !== false) {
                $hasInstallment = true;
            }
            if (stripos($typeLower, 'mortgage') !== false || stripos($typeLower, 'real estate') !== false) {
                $hasMortgage = true;
            }
        }

        return $hasRevolving && $hasInstallment;
    }

    private function getCurrentAutoLoan(array $tradeLines): ?array
    {
        foreach ($tradeLines as $trade) {
            if ($this->isAutoLoan($trade) && $trade['is_open']) {
                return [
                  'balance' => $trade['balance'],
                  'payment' => $trade['payment'],
                  'apr' => $this->extractAPR($trade),
                  'months_remaining' => $this->calculateRemainingMonths($trade)
                ];
            }
        }
        return null;
    }

    private function calculatePotentialAPR(int $ficoScore, string $riskTier): float
    {
        // Base rates by tier (you'd want to make these configurable)
        $baseRates = [
          'A' => 3.5,
          'B' => 5.5,
          'C' => 8.5,
          'D' => 12.5,
          'E' => 18.5
        ];

        $baseRate = $baseRates[$riskTier] ?? 15.0;

        // Adjust for FICO score
        if ($ficoScore >= 750) {
            $baseRate -= 1.0;
        } elseif ($ficoScore >= 700) {
            $baseRate -= 0.5;
        } elseif ($ficoScore < 600) {
            $baseRate += 2.0;
        }

        return max(2.99, $baseRate); // Minimum APR floor
    }

    private function calculateMonthlyPayment(float $principal, float $apr, int $months): float
    {
        if ($apr <= 0 || $months <= 0) {
            return 0;
        }

        $monthlyRate = $apr / 100 / 12;
        $payment = $principal * ($monthlyRate * pow(1 + $monthlyRate, $months)) /
          (pow(1 + $monthlyRate, $months) - 1);

        return round($payment, 2);
    }

    private function getCountyFromZip(string $zipCode): ?string
    {
        // This would integrate with a ZIP to county lookup service
        // For now, return null
        return null;
    }

    private function inferGender(string $firstName): string
    {
        return GenderMapConstants::getGenderByName($firstName);
    }

    private function getFicoTier(int $ficoScore): string
    {
        return match(true) {
            $ficoScore >= 800 => 'exceptional',
            $ficoScore >= 740 => 'very_good',
            $ficoScore >= 670 => 'good',
            $ficoScore >= 580 => 'fair',
            $ficoScore >= 300 => 'poor',
            default => 'no_score'
        };
    }

    private function getLowestAPR(array $matchedLenders): ?float
    {
        // This would look up actual APRs from lender configuration
        // For now, return a placeholder
        if (empty($matchedLenders)) {
            return null;
        }

        // Mock implementation
        return 5.99;
    }

    private function calculateConfidence(PreQualResult $result): float
    {
        $confidence = 0.5; // Base confidence

        // Adjust based on data completeness
        if ($result->creditProfile->hasValidScore()) {
            $confidence += 0.2;
        }

        if ($result->isComplete) {
            $confidence += 0.2;
        }

        if (count($result->matchedLenders) > 0) {
            $confidence += 0.1;
        }

        return min(1.0, $confidence);
    }

    private function getOldestAccountAge(array $tradeLines): int
    {
        $ages = [];
        $now = new \DateTime();

        foreach ($tradeLines as $trade) {
            if (isset($trade['opened']) && !empty($trade['opened'])) {
                $opened = new \DateTime($trade['opened']);
                $diff = $now->diff($opened);
                $months = ($diff->y * 12) + $diff->m;
                $ages[] = $months;
            }
        }

        return empty($ages) ? 0 : max($ages);
    }

    private function getAverageAccountAge(array $tradeLines): int
    {
        $ages = [];
        $now = new \DateTime();

        foreach ($tradeLines as $trade) {
            if (isset($trade['opened']) && !empty($trade['opened'])) {
                $opened = new \DateTime($trade['opened']);
                $diff = $now->diff($opened);
                $months = ($diff->y * 12) + $diff->m;
                $ages[] = $months;
            }
        }

        return empty($ages) ? 0 : (int)round(array_sum($ages) / count($ages));
    }

    private function calculateAccountDiversityScore(array $tradeLines): float
    {
        $types = [];
        foreach ($tradeLines as $trade) {
            $types[] = $trade['type'] ?? 'unknown';
        }

        $uniqueTypes = count(array_unique($types));
        $totalAccounts = count($types);

        if ($totalAccounts == 0) {
            return 0;
        }

        // Diversity score: unique types / total accounts, capped at 0.5
        // Plus bonus for having good mix
        $diversityScore = min(0.5, $uniqueTypes / $totalAccounts);

        if ($this->hasGoodCreditMix($types)) {
            $diversityScore += 0.5;
        }

        return $diversityScore;
    }

    private function calculateOnTimePaymentPercentage(array $tradeLines): float
    {
        $totalPayments = 0;
        $onTimePayments = 0;

        foreach ($tradeLines as $trade) {
            if (isset($trade['payment_history'])) {
                $history = $trade['payment_history'];
                $totalPayments += strlen($history);
                $onTimePayments += substr_count($history, '0') + substr_count($history, 'C');
            }
        }

        return $totalPayments > 0 ? round(($onTimePayments / $totalPayments) * 100, 2) : 100.0;
    }

    private function getMonthsSinceLastDelinquency(array $tradeLines): ?int
    {
        $mostRecentDelinquency = null;

        foreach ($tradeLines as $trade) {
            if (isset($trade['first_delinquency']) && !empty($trade['first_delinquency'])) {
                $delinquencyDate = new \DateTime($trade['first_delinquency']);
                if ($mostRecentDelinquency === null || $delinquencyDate > $mostRecentDelinquency) {
                    $mostRecentDelinquency = $delinquencyDate;
                }
            }
        }

        if ($mostRecentDelinquency === null) {
            return null; // Never delinquent
        }

        $now = new \DateTime();
        $diff = $now->diff($mostRecentDelinquency);
        return ($diff->y * 12) + $diff->m;
    }

    private function calculateApprovalProbability(PreQualResult $result): float
    {
        // Simple probability based on risk score
        return min(1.0, max(0.0, $result->approvalScore));
    }

    private function estimateAPR(float $balance, float $payment, string $terms): ?float
    {
        // Extract term length
        if (preg_match('/(\d+)/', $terms, $matches)) {
            $months = (int)$matches[1];

            // Use Newton's method to estimate APR
            // This is a simplified estimation
            if ($months > 0 && $balance > 0) {
                $estimatedRate = (($payment * $months) - $balance) / $balance / $months * 12 * 100;
                return round($estimatedRate, 2);
            }
        }

        return null;
    }
}