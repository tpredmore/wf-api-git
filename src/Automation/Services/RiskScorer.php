<?php

declare(strict_types=1);

namespace WF\API\Automation\Services;

use WF\API\Automation\Contracts\RiskScorerInterface;
use WF\API\Automation\Models\Applicant;
use WF\API\Automation\Models\Vehicle;
use WF\API\Automation\Models\CreditProfile;
use WF\API\Automation\Repositories\LenderRepository;

class RiskScorer implements RiskScorerInterface
{
    private array $scoreFactors = [];

    public function __construct(
      private LenderRepository $lenderRepository
    ) {}

    public function calculateScore(Applicant $applicant, Vehicle $vehicle, CreditProfile $credit): float
    {
        $this->scoreFactors = [];

        if (!$credit->hasValidScore()) {
            return 0.0;
        }

        // FICO Score Factor (40% weight)
        $ficoScore = $this->calculateFicoScore($credit->ficoScore);
        $this->scoreFactors['fico'] = [
          'value' => $credit->ficoScore,
          'score' => $ficoScore,
          'weight' => 0.4
        ];

        // DTI Factor (25% weight)
        $dti = $applicant->calculateDTI($credit->estimatedMonthlyDebt);
        $dtiScore = $this->calculateDtiScore($dti);
        $this->scoreFactors['dti'] = [
          'value' => $dti,
          'score' => $dtiScore,
          'weight' => 0.25
        ];

        // LTV Factor (20% weight)
        $ltv = $vehicle->calculateLTV();
        $ltvScore = $this->calculateLtvScore($ltv);
        $this->scoreFactors['ltv'] = [
          'value' => $ltv,
          'score' => $ltvScore,
          'weight' => 0.20
        ];

        // Employment Factor (10% weight)
        $employmentScore = $this->calculateEmploymentScore($applicant->employmentType);
        $this->scoreFactors['employment'] = [
          'value' => $applicant->employmentType,
          'score' => $employmentScore,
          'weight' => 0.10
        ];

        // Credit History Factor (5% weight)
        $creditHistoryScore = $this->calculateCreditHistoryScore($credit);
        $this->scoreFactors['credit_history'] = [
          'value' => $credit->openTradeCount,
          'score' => $creditHistoryScore,
          'weight' => 0.05
        ];

        // Calculate weighted final score
        $finalScore =
          ($ficoScore * 0.4) +
          ($dtiScore * 0.25) +
          ($ltvScore * 0.20) +
          ($employmentScore * 0.10) +
          ($creditHistoryScore * 0.05);

        return round($finalScore, 3);
    }

    public function getRiskTier(float $score): string
    {
        return match (true) {
            $score >= 0.85 => 'A',
            $score >= 0.70 => 'B',
            $score >= 0.50 => 'C',
            $score >= 0.30 => 'D',
            default => 'E',
        };
    }

    public function getScoreFactors(): array
    {
        return $this->scoreFactors;
    }

    public function matchLenders(Applicant $applicant, Vehicle $vehicle, CreditProfile $credit): array
    {
        $lenderRules = $this->lenderRepository->getActiveRules();
        $matched = [];

        $fico = $credit->ficoScore ?? 0;
        $dti = $applicant->calculateDTI($credit->estimatedMonthlyDebt);
        $ltv = $vehicle->calculateLTV();

        foreach ($lenderRules as $lender) {
            if ($this->doesLenderMatch($lender, $fico, $dti, $ltv, $applicant, $vehicle)) {
                $matched[] = $lender['name'];
            }
        }

        return $matched;
    }

    private function calculateFicoScore(int $fico): float
    {
        // Normalize FICO score (300-850 range)
        return max(0, min(1, ($fico - 300) / 550));
    }

    private function calculateDtiScore(float $dti): float
    {
        if ($dti < 0) return 0.0; // Invalid DTI

        return match (true) {
            $dti <= 0.20 => 1.0,
            $dti <= 0.30 => 0.8,
            $dti <= 0.40 => 0.6,
            $dti <= 0.50 => 0.4,
            default => 0.2
        };
    }

    private function calculateLtvScore(float $ltv): float
    {
        return match (true) {
            $ltv <= 0.80 => 1.0,
            $ltv <= 0.90 => 0.8,
            $ltv <= 1.00 => 0.6,
            $ltv <= 1.10 => 0.4,
            default => 0.2
        };
    }

    private function calculateEmploymentScore(string $employmentType): float
    {
        return match ($employmentType) {
            'W2' => 1.0,
            '1099' => 0.8,
            'self_employed' => 0.6,
            default => 0.4
        };
    }

    private function calculateCreditHistoryScore(CreditProfile $credit): float
    {
        $score = 1.0;

        // Penalize for derogatory marks
        if ($credit->derogatoryMarks > 0) {
            $score -= min(0.3, $credit->derogatoryMarks * 0.1);
        }

        // Penalize for bankruptcies
        if ($credit->bankruptcies > 0) {
            $score -= 0.4;
        }

        // Penalize for high inquiry count
        if ($credit->inquiriesSixMonths > 3) {
            $score -= min(0.2, ($credit->inquiriesSixMonths - 3) * 0.05);
        }

        return max(0, $score);
    }

    private function doesLenderMatch(array $lender, int $fico, float $dti, float $ltv, Applicant $applicant, Vehicle $vehicle): bool
    {
        // Check FICO minimum
        if ($fico < ($lender['min_fico'] ?? 0)) {
            return false;
        }

        // Check DTI maximum
        if ($dti > ($lender['max_dti'] ?? 1.0)) {
            return false;
        }

        // Check LTV maximum
        if ($ltv > ($lender['max_ltv'] ?? 1.0)) {
            return false;
        }

        // Check loan types
        $vehicleLoanType = 'auto'; // Could be derived from vehicle
        if (!in_array($vehicleLoanType, $lender['loan_types'] ?? [])) {
            return false;
        }

        // Check states
        if (!in_array($applicant->state, $lender['states'] ?? [])) {
            return false;
        }

        // Check employment restrictions
        if (($lender['no_self_employed'] ?? false) && $applicant->employmentType === 'self_employed') {
            return false;
        }

        return true;
    }
}
