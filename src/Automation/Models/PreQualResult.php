<?php

declare(strict_types=1);

namespace WF\API\Automation\Models;

class PreQualResult
{
    public function __construct(
      public float $approvalScore,
      public string $riskTier,
      public array $matchedLenders,
      public bool $isComplete,
      public ?string $missingReason,
      public CreditProfile $creditProfile,
      public float $ltv,
      public float $dti,
      public array $metadata = []
    ) {}

    public function isApproved(): bool
    {
        return $this->approvalScore >= 0.5 && $this->isComplete;
    }

    public function getRiskTier(): string
    {
        return $this->riskTier;
    }

    public function getMatchedLenders(): array
    {
        return $this->matchedLenders;
    }

    public function toArray(): array
    {
        return [
          'approval_score' => $this->approvalScore,
          'risk_tier' => $this->riskTier,
          'matched_lenders' => $this->matchedLenders,
          'is_complete' => $this->isComplete,
          'missing_reason' => $this->missingReason,
          'fico_score' => $this->creditProfile->ficoScore,
          'ltv' => $this->ltv,
          'dti' => $this->dti,
          'is_approved' => $this->isApproved(),
          'metadata' => $this->metadata
        ];
    }
}
