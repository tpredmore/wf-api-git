<?php

declare(strict_types=1);

namespace WF\API\Automation\Parsers;

use WF\API\Automation\Constants\ExperianConstants;
use WF\API\Automation\Contracts\CreditParserInterface;
use WF\API\Automation\Models\CreditProfile;

abstract class AbstractCreditParser implements CreditParserInterface
{
    public function parse(array $rawData): CreditProfile
    {
        // Get the credit profile from the response
        $creditProfile = $rawData['creditProfile'][0] ?? [];

        // Check if we have a valid report
        $hasHit = isset($creditProfile['riskModel']) && isset($creditProfile['tradeline']);

        if (!$hasHit) {
            // No hit scenario
            return CreditProfile::fromArray([
              'fico_score' => null,
              'bureau' => 'experian',
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

        return CreditProfile::fromArray([
          'fico_score' => $this->extractScore($creditProfile),
          'bureau' => 'experian',
          'open_trade_count' => $this->countOpenTrades($creditProfile),
          'auto_trade_count' => $this->countAutoTrades($creditProfile),
          'derogatory_marks' => $this->countDerogatoryMarks($creditProfile),
          'bankruptcies' => $this->countBankruptcies($creditProfile),
          'revolving_utilization' => $this->calculateRevolvingUtilization($this->extractTradeLines($creditProfile)),
          'inquiries_6mo' => $this->countRecentInquiries($creditProfile),
          'estimated_monthly_debt' => $this->estimateMonthlyDebt($this->extractTradeLines($creditProfile)),
          'trade_lines' => $this->extractTradeLines($creditProfile),
          'score_factors' => $this->extractScoreFactors($creditProfile),
          'hit' => true,
          'pulled_at' => date('Y-m-d H:i:s')
        ]);
    }

    public function getSupportedBureau(): string
    {
        return 'experian';
    }

    private function extractScore(array $creditProfile): ?int
    {
        $model = $creditProfile['riskModel'][0] ?? null;

        if ($model !== null && isset($model['score'])) {
            return (int)ltrim($model['score'], '0');
        }

        return null;
    }

    private function countOpenTrades(array $creditProfile): int
    {
        $trades = $creditProfile['tradeline'] ?? [];
        return count(array_filter($trades, fn($trade) => ($trade['openOrClosed'] ?? 'C') === 'O'));
    }

    private function countAutoTrades(array $creditProfile): int
    {
        $trades = $creditProfile['tradeline'] ?? [];
        return count(array_filter($trades, function($trade) {
            $accountType = $trade['accountType'] ?? '';
            return in_array($accountType, ExperianConstants::AUTO_ACCOUNT_TYPES);
        }));
    }

    private function countDerogatoryMarks(array $creditProfile): int
    {
        $trades = $creditProfile['tradeline'] ?? [];
        $derogCount = 0;

        foreach ($trades as $trade) {
            // Count derog counter
            if (isset($trade['derogCounter'])) {
                $derogCount += (int)$trade['derogCounter'];
            }

            // Also check for current delinquencies
            if ((int)($trade['delinquencies30Days'] ?? 0) > 0) {
                $derogCount++;
            }
        }

        return $derogCount;
    }

    private function countBankruptcies(array $creditProfile): int
    {
        $bankruptcies = 0;

        // Check public records
        if (isset($creditProfile['publicRecord'])) {
            foreach ($creditProfile['publicRecord'] as $record) {
                if (in_array($record['status'] ?? 0, ExperianConstants::PUBLIC_RECORDS_MAP['bankruptcy'])) {
                    $bankruptcies++;
                }
            }
        }

        // Check tradeline statuses
        $trades = $creditProfile['tradeline'] ?? [];
        foreach ($trades as $trade) {
            if (in_array($trade['status'] ?? '', ['67', '69'])) {
                $bankruptcies++;
            }
        }

        return $bankruptcies;
    }

    private function countRecentInquiries(array $creditProfile): int
    {
        $inquiries = $creditProfile['inquiry'] ?? [];
        $sixMonthsAgo = new \DateTime('-6 months');
        $count = 0;

        foreach ($inquiries as $inquiry) {
            if (!isset($inquiry['date'])) continue;

            // Convert MMDDYYYY to DateTime
            $dateStr = $inquiry['date'];
            $date = \DateTime::createFromFormat('mdY', $dateStr);

            if ($date && $date >= $sixMonthsAgo) {
                $count++;
            }
        }

        return $count;
    }

    private function extractTradeLines(array $creditProfile): array
    {
        $trades = $creditProfile['tradeline'] ?? [];
        $formattedTrades = [];

        foreach ($trades as $trade) {
            $isOpen = ($trade['openOrClosed'] ?? 'C') === 'O';
            $isRevolving = ($trade['revolvingOrInstallment'] ?? 'I') === 'R';

            // Determine standing
            $standing = 0; // closed
            if ($isOpen) {
                $standing = 1; // open/current
                if ((int)($trade['delinquencies30Days'] ?? 0) > 0) {
                    $standing = 2; // open/delinquent
                }
            }

            // Parse dates
            $opened = $this->formatExperianDate($trade['openDate'] ?? '');
            $closed = '';
            if (!$isOpen && isset($trade['statusDate'])) {
                $closed = $this->formatExperianDate($trade['statusDate']);
            }

            // Get credit limit
            $creditLimit = 0;
            if ($isRevolving) {
                if (isset($trade['enhancedPaymentData']['creditLimitAmount'])) {
                    $creditLimit = (float)ltrim($trade['enhancedPaymentData']['creditLimitAmount'], '0');
                } else {
                    $creditLimit = (float)ltrim($trade['enhancedPaymentData']['originalLoanAmount'] ?? '0', '0');
                }
            }

            $formattedTrades[] = [
              'bureau' => 'experian',
              'opened' => $opened,
              'is_open' => $isOpen,
              'closed' => $closed,
              'type' => ExperianConstants::ACCOUNT_CODE_MAP[$trade['accountType'] ?? ''] ?? 'Unknown',
              'creditor' => $trade['subscriberName'] ?? 'UNKNOWN',
              'subscriber_number' => $trade['subscriberCode'] ?? '',
              'kind_of_business' => ExperianConstants::KIND_OF_BUSINESS_MAP[$trade['kob'] ?? ''] ?? 'Unknown',
              'joint' => in_array($trade['ecoa'] ?? '1', ['2', '4']),
              'ecoa' => ExperianConstants::ECOA_MAP[$trade['ecoa'] ?? '1'] ?? 'Individual',
              'account_num' => $trade['accountNumber'] ?? '',
              'status' => ExperianConstants::ACCOUNT_STATUS_MAP[$trade['status'] ?? ''] ?? 'Unknown',
              'status_date' => $this->formatExperianDate($trade['statusDate'] ?? ''),
              'standing' => $standing,
              'months_reported' => (int)ltrim($trade['monthsHistory'] ?? '0', '0'),
              'balance' => (float)ltrim($trade['balanceAmount'] ?? '0', '0'),
              'balance_date' => $this->formatExperianDate($trade['balanceDate'] ?? ''),
              'credit_limit' => $creditLimit,
              'payment' => (float)ltrim($trade['monthlyPaymentAmount'] ?? '0', '0'),
              'actual_payment' => (float)ltrim($trade['enhancedPaymentData']['actualPaymentAmount'] ?? $trade['monthlyPaymentAmount'] ?? '0', '0'),
              'past_due' => (float)ltrim($trade['amountPastDue'] ?? '0', '0'),
              'original_amount' => $isRevolving ? 0 : (float)ltrim($trade['enhancedPaymentData']['originalLoanAmount'] ?? '0', '0'),
              'high_credit' => (float)ltrim($trade['enhancedPaymentData']['highBalanceAmount'] ?? '0', '0'),
              'terms' => ltrim($trade['terms'] ?? '', '0'),
              'last_payment_date' => $this->formatExperianDate($trade['lastPaymentDate'] ?? ''),
              'payment_history' => $trade['paymentHistory'] ?? '',
              'derogatory_30' => (int)ltrim($trade['delinquencies30Days'] ?? '0', '0'),
              'derogatory_60' => (int)ltrim($trade['delinquencies60Days'] ?? '0', '0'),
              'derogatory_90' => (int)ltrim($trade['delinquencies90to180Days'] ?? '0', '0'),
              'first_delinquency' => $this->formatExperianDate($trade['enhancedPaymentData']['firstDelinquencyDate'] ?? ''),
              'second_delinquency' => $this->formatExperianDate($trade['enhancedPaymentData']['secondDelinquencyDate'] ?? ''),
              'extra' => $this->buildExtraInfo($trade)
            ];
        }

        return $formattedTrades;
    }

    private function extractScoreFactors(array $creditProfile): array
    {
        $factors = [];
        $model = $creditProfile['riskModel'][0] ?? null;

        if ($model !== null && isset($model['scoreFactors'])) {
            // Determine score model type
            $modelIndicator = $model['modelIndicator'] ?? 'V4';
            $scoreModel = in_array($modelIndicator, ['AB', 'AD', 'AA', 'AF', 'F9', 'FX', 'FT']) ? 'FICO' : 'VANTAGE';

            foreach ($model['scoreFactors'] as $factor) {
                $code = $factor['code'] ?? '';
                $description = ExperianConstants::SCORE_FACTOR_MAP[$scoreModel][$code] ?? "Experian score code: $code";

                $factors[] = [
                  'priority' => $factor['importance'] ?? '',
                  'code' => $code,
                  'description' => $description
                ];
            }
        }

        return $factors;
    }

    private function formatExperianDate(string $date): string
    {
        if (empty($date) || strlen($date) < 8) {
            return '';
        }

        // Experian dates are MMDDYYYY format
        $parsed = \DateTime::createFromFormat('mdY', $date);
        if ($parsed) {
            return $parsed->format('Y-m-d');
        }

        return '';
    }

    private function buildExtraInfo(array $trade): string
    {
        $extra = [];

        if (isset($trade['evaluation'])) {
            $extra[] = 'Evaluation Code: "' . $trade['evaluation'] . '"';
        }

        if (isset($trade['enhancedPaymentData']['complianceCondition'])) {
            $extra[] = 'Compliance Condition: "' . $trade['enhancedPaymentData']['complianceCondition'] . '"';
        }

        if (isset($trade['enhancedPaymentData']['maxDelinquencyCode'])) {
            $extra[] = 'Max Delinquency Code: "' . $trade['enhancedPaymentData']['maxDelinquencyCode'] . '"';
        }

        if (isset($trade['specialComment'])) {
            $comment = ExperianConstants::SPECIAL_COMMENT_MAP[$trade['specialComment']] ??
              'Special Comment: UNKNOWN VALUE [' . $trade['specialComment'] . ']';
            $extra[] = 'Special Comment: "' . $comment . '"';
        }

        return implode(', ', $extra);
    }

    /**
     * Calculate revolving utilization for Experian
     */
    protected function calculateRevolvingUtilization(array $tradeLines): float
    {
        $totalBalance = 0;
        $totalLimit = 0;

        foreach ($tradeLines as $trade) {
            // Check if it's a revolving account
            if (in_array(strtolower($trade['type'] ?? ''), ['credit card', 'charge account', 'revolving'])) {
                $totalBalance += (float)$trade['balance'];
                $totalLimit += (float)$trade['credit_limit'];
            }
        }

        return $totalLimit > 0 ? $totalBalance / $totalLimit : 0.0;
    }

    protected function estimateMonthlyDebt(array $tradeLines): int
    {
        $totalPayment = 0;

        foreach ($tradeLines as $trade) {
            if (($trade['is_open'] ?? false) && isset($trade['payment'])) {
                $payment = (float)$trade['payment'];
                if ($payment > 0) {
                    $totalPayment += $payment;
                }
            }
        }

        return (int)$totalPayment;
    }
}
