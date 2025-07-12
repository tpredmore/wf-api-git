<?php

declare(strict_types=1);

namespace WF\API\Automation\Models;

class CreditProfile
{
    public function __construct(
      public ?int $ficoScore,
      public string $bureau,
      public int $openTradeCount,
      public int $autoTradeCount,
      public int $derogatoryMarks,
      public int $bankruptcies,
      public float $revolvingUtilization,
      public int $inquiriesSixMonths,
      public ?int $estimatedMonthlyDebt,
      public array $tradeLines = [],
      public array $scoreFactors = [],
      public bool $hasHit = true,
      public ?string $pulledAt = null
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
          ficoScore: isset($data['fico_score']) ? (int)$data['fico_score'] : null,
          bureau: $data['bureau'] ?? '',
          openTradeCount: (int)($data['open_trade_count'] ?? 0),
          autoTradeCount: (int)($data['auto_trade_count'] ?? 0),
          derogatoryMarks: (int)($data['derogatory_marks'] ?? 0),
          bankruptcies: (int)($data['bankruptcies'] ?? 0),
          revolvingUtilization: (float)($data['revolving_utilization'] ?? 0),
          inquiriesSixMonths: (int)($data['inquiries_6mo'] ?? 0),
          estimatedMonthlyDebt: isset($data['estimated_monthly_debt']) ? (int)$data['estimated_monthly_debt'] : null,
          tradeLines: $data['trade_lines'] ?? [],
          scoreFactors: $data['score_factors'] ?? [],
          hasHit: (bool)($data['hit'] ?? true),
          pulledAt: $data['pulled_at'] ?? null
        );
    }

    public function hasValidScore(): bool
    {
        return $this->ficoScore !== null && $this->ficoScore >= 300 && $this->ficoScore <= 850;
    }

    public function getRiskCategory(): string
    {
        if (!$this->hasValidScore()) {
            return 'no_score';
        }

        return match (true) {
            $this->ficoScore >= 740 => 'super_prime',
            $this->ficoScore >= 680 => 'prime',
            $this->ficoScore >= 620 => 'near_prime',
            $this->ficoScore >= 580 => 'subprime',
            default => 'deep_subprime'
        };
    }
}
