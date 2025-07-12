<?php

declare(strict_types=1);

namespace WF\API\Automation\Parsers;

use WF\API\Automation\Models\CreditProfile;

class ExperianParser extends AbstractCreditParser
{
    public function parse(array $rawData): CreditProfile
    {
        // Handle the structure from your existing data
        return CreditProfile::fromArray([
          'fico_score' => $rawData['fico_score'] ?? null,
          'bureau' => 'experian',
          'open_trade_count' => $rawData['open_trade_count'] ?? 0,
          'auto_trade_count' => $rawData['auto_trade_count'] ?? 0,
          'derogatory_marks' => $rawData['derogatory_marks'] ?? 0,
          'bankruptcies' => $rawData['bankruptcies'] ?? 0,
          'revolving_utilization' => $this->calculateRevolvingUtilization($rawData['trade_lines'] ?? []),
          'inquiries_6mo' => $rawData['inquiries_6mo'] ?? 0,
          'estimated_monthly_debt' => $this->estimateMonthlyDebt($rawData['trade_lines'] ?? []),
          'trade_lines' => $rawData['trade_lines'] ?? [],
          'score_factors' => $this->extractScoreFactors($rawData),
          'hit' => $rawData['hit'] ?? true,
          'pulled_at' => $rawData['pulled_at'] ?? null,
        ]);
    }

    public function getSupportedBureau(): string
    {
        return 'experian';
    }
}
