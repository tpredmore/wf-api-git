<?php

declare(strict_types=1);

namespace WF\API\Automation\Parsers;

use WF\API\Automation\Models\CreditProfile;
use WF\API\Automation\Constants\TransUnionConstants;
use DateTime;

class TransUnionParser extends AbstractCreditParser
{
    public function parse(array $rawData): CreditProfile
    {
        // Check if we have an error
        if (isset($rawData['product']['error'])) {
            return $this->createNoHitProfile($rawData['product']['error']['description'] ?? 'Unknown error');
        }

        // Extract the main credit data
        $product = $rawData['product'] ?? [];
        $subject = $product['subject']['subjectRecord'] ?? [];
        $custom = $subject['custom']['credit'] ?? [];

        // Check if we have a valid hit
        $hasHit = !empty($custom['trade']) || !empty($subject['addOnProduct']);

        if (!$hasHit) {
            return $this->createNoHitProfile();
        }

        return CreditProfile::fromArray([
          'fico_score' => $this->extractScore($subject),
          'bureau' => 'transunion',
          'open_trade_count' => $this->countOpenTrades($custom),
          'auto_trade_count' => $this->countAutoTrades($custom),
          'derogatory_marks' => $this->countDerogatoryMarks($custom),
          'bankruptcies' => $this->countBankruptcies($custom),
          'revolving_utilization' => $this->calculateRevolvingUtilization($this->extractTradeLines($custom, $subject)),
          'inquiries_6mo' => $this->countRecentInquiries($custom),
          'estimated_monthly_debt' => $this->estimateMonthlyDebt($this->extractTradeLines($custom, $subject)),
          'trade_lines' => $this->extractTradeLines($custom, $subject),
          'score_factors' => $this->extractScoreFactors($subject),
          'hit' => true,
          'pulled_at' => $this->extractPullDate($rawData)
        ]);
    }

    public function getSupportedBureau(): string
    {
        return 'transunion';
    }

    private function createNoHitProfile(string $error = ''): CreditProfile
    {
        return CreditProfile::fromArray([
          'fico_score' => null,
          'bureau' => 'transunion',
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

    private function extractScore(array $subject): ?int
    {
        // TransUnion uses addOnProduct for score models
        if (isset($subject['addOnProduct'])) {
            foreach ($subject['addOnProduct'] as $product) {
                if (isset($product['scoreModel']['score']['results'])) {
                    $score = ltrim($product['scoreModel']['score']['results'], '+');
                    return (int)$score;
                }
            }
        }
        return null;
    }

    private function countOpenTrades(array $credit): int
    {
        $count = 0;

        // Count regular trades
        $trades = $credit['trade'] ?? [];
        foreach ($trades as $trade) {
            if (!isset($trade['dateClosed'])) {
                $count++;
            }
        }

        // Count collections
        $collections = $credit['collection'] ?? [];
        $count += count($collections);

        return $count;
    }

    private function countAutoTrades(array $credit): int
    {
        $count = 0;
        $trades = $credit['trade'] ?? [];

        foreach ($trades as $trade) {
            $accountType = $trade['account']['type'] ?? '';
            if (in_array($accountType, TransUnionConstants::AUTO_ACCOUNT_TYPES)) {
                $count++;
            }
        }

        return $count;
    }

    private function countDerogatoryMarks(array $credit): int
    {
        $count = 0;

        // Count derogatory trades
        $trades = $credit['trade'] ?? [];
        foreach ($trades as $trade) {
            $rating = $trade['accountRating'] ?? '';
            if (in_array($rating, TransUnionConstants::DEROGATORY_MOP_CODES)) {
                $count++;
            }

            // Check payment history
            if (isset($trade['paymentHistory']['maxDelinquency'])) {
                $count++;
            }
        }

        // Add collections
        $count += count($credit['collection'] ?? []);

        return $count;
    }

    private function countBankruptcies(array $credit): int
    {
        $publicRecords = $credit['publicRecord'] ?? [];
        $bankruptcies = 0;

        foreach ($publicRecords as $record) {
            $type = $record['type'] ?? '';
            // Check if it's a bankruptcy type
            if (strpos($type, 'BANKRUPTCY') !== false ||
              in_array($type, ['1F', '1D', '1V', '1X', '2F', '2D', '2V', '2X', '3F', '3D', '3V', '3X', '7F', '7D', '7V', '7X'])) {
                $bankruptcies++;
            }
        }

        return $bankruptcies;
    }

    private function countRecentInquiries(array $credit): int
    {
        $inquiries = $credit['inquiry'] ?? [];
        $sixMonthsAgo = new DateTime('-6 months');
        $count = 0;

        foreach ($inquiries as $inquiry) {
            if (isset($inquiry['date'])) {
                $inquiryDate = DateTime::createFromFormat('Y-m-d', $inquiry['date']);
                if ($inquiryDate && $inquiryDate >= $sixMonthsAgo) {
                    $count++;
                }
            }
        }

        return $count;
    }

    private function extractTradeLines(array $credit, array $subject): array
    {
        $tradeLines = [];
        $trades = $credit['trade'] ?? [];

        foreach ($trades as $trade) {
            $isOpen = !isset($trade['dateClosed']);
            $portfolioType = $trade['portfolioType'] ?? '';
            $accountType = $trade['account']['type'] ?? '';
            $mopCode = $trade['accountRating'] ?? '';

            // Determine standing
            $standing = 0; // closed
            if ($isOpen) {
                $standing = 1; // open/current
                if (in_array($mopCode, TransUnionConstants::DEROGATORY_MOP_CODES)) {
                    $standing = 2; // open/delinquent
                }
            }

            // Get payment history info
            $paymentHistory = '';
            $derog30 = 0;
            $derog60 = 0;
            $derog90 = 0;
            $firstDelinquency = '';

            if (isset($trade['paymentHistory'])) {
                foreach ($trade['paymentHistory'] as $history) {
                    if (isset($history['paymentPattern']['text'])) {
                        $paymentHistory .= $history['paymentPattern']['text'];
                    }

                    if (isset($history['historicalCounters'])) {
                        $counters = $history['historicalCounters'];
                        $derog30 = (int)($counters['late30DaysTotal'] ?? 0);
                        $derog60 = (int)($counters['late60DaysTotal'] ?? 0);
                        $derog90 = (int)($counters['late90DaysTotal'] ?? 0);
                    }

                    if (isset($history['maxDelinquency']['date']) && $history['maxDelinquency']['earliest'] === 'true') {
                        $firstDelinquency = $history['maxDelinquency']['date'];
                    }
                }
            }

            $tradeLines[] = [
              'bureau' => 'transunion',
              'opened' => $trade['dateOpened'] ?? '',
              'is_open' => $isOpen,
              'closed' => $trade['dateClosed'] ?? '',
              'type' => TransUnionConstants::ACCOUNT_TYPE_MAP[$accountType] ?? $accountType,
              'creditor' => $trade['subscriber']['name']['unparsed'] ?? 'UNKNOWN',
              'subscriber_number' => $trade['subscriber']['memberCode'] ?? '',
              'kind_of_business' => TransUnionConstants::INDUSTRY_CODE_MAP[$trade['subscriber']['industryCode'] ?? ''] ?? 'Unknown',
              'joint' => in_array(strtolower($trade['ECOADesignator'] ?? ''), ['jointcontractliability', 'cosigner']),
              'ecoa' => $trade['ECOADesignator'] ?? 'Undesignated',
              'account_num' => $trade['accountNumber'] ?? '',
              'status' => TransUnionConstants::MOP_MAP[$mopCode] ?? 'Unknown',
              'status_date' => $trade['dateEffective'] ?? '',
              'standing' => $standing,
              'months_reported' => (int)($trade['paymentHistory'][0]['monthsReviewedCount'] ?? 0),
              'balance' => (float)($trade['currentBalance'] ?? 0),
              'balance_date' => $trade['dateEffective'] ?? '',
              'credit_limit' => (float)($trade['creditLimit'] ?? 0),
              'payment' => (float)($trade['terms']['scheduledMonthlyPayment'] ?? 0),
              'actual_payment' => (float)($trade['terms']['scheduledMonthlyPayment'] ?? 0),
              'past_due' => (float)($trade['pastDue'] ?? 0),
              'original_amount' => $this->getOriginalAmount($trade, $portfolioType),
              'high_credit' => (float)($trade['highCredit'] ?? 0),
              'terms' => $trade['terms']['paymentScheduleMonthCount'] ?? '',
              'last_payment_date' => $trade['mostRecentPayment']['date'] ?? '',
              'payment_history' => $paymentHistory,
              'derogatory_30' => $derog30,
              'derogatory_60' => $derog60,
              'derogatory_90' => $derog90,
              'first_delinquency' => $firstDelinquency,
              'second_delinquency' => '',
              'extra' => $this->buildExtraInfo($trade)
            ];
        }

        // Add collections as trade lines
        $collections = $credit['collection'] ?? [];
        foreach ($collections as $collection) {
            $tradeLines[] = $this->formatCollectionAsTradeLine($collection);
        }

        return $tradeLines;
    }

    private function extractScoreFactors(array $subject): array
    {
        $factors = [];

        if (isset($subject['addOnProduct'])) {
            foreach ($subject['addOnProduct'] as $product) {
                if (isset($product['scoreModel']['score']['factors']['factor'])) {
                    $scoreFactors = $product['scoreModel']['score']['factors']['factor'];

                    // Handle single factor or array of factors
                    if (isset($scoreFactors['code'])) {
                        $scoreFactors = [$scoreFactors];
                    }

                    foreach ($scoreFactors as $factor) {
                        $code = $factor['code'] ?? '';
                        $rank = $factor['rank'] ?? '1';

                        $factors[] = [
                          'priority' => $rank,
                          'code' => $code,
                          'description' => TransUnionConstants::SCORE_FACTOR_MAP[$code] ?? "TransUnion score code: $code"
                        ];
                    }
                }
            }
        }

        return $factors;
    }

    private function extractPullDate(array $rawData): string
    {
        if (isset($rawData['transactionControl']['tracking']['transactionTimeStamp'])) {
            return date('Y-m-d H:i:s', strtotime($rawData['transactionControl']['tracking']['transactionTimeStamp']));
        }
        return date('Y-m-d H:i:s');
    }

    private function getOriginalAmount(array $trade, string $portfolioType): float
    {
        $original = $trade['additionalTradeAccount']['original'] ?? [];

        if (isset($original['chargeOff'])) {
            return (float)$original['chargeOff'];
        } elseif (isset($original['balance'])) {
            return (float)$original['balance'];
        } elseif (in_array($portfolioType, TransUnionConstants::INSTALLMENT_TYPES)) {
            return (float)($trade['highCredit'] ?? 0);
        }

        return 0;
    }

    private function formatCollectionAsTradeLine(array $collection): array
    {
        $mopCode = $collection['accountRating'] ?? '';
        $isOpen = in_array($mopCode, TransUnionConstants::OPEN_MOP_CODES);
        $joint = in_array(strtolower($collection['ECOADesignator'] ?? ''), ['jointcontractliability', 'cosigner']);

        return [
          'bureau' => 'transunion',
          'opened' => $collection['dateOpened'] ?? '',
          'is_open' => $isOpen,
          'closed' => $collection['dateClosed'] ?? '',
          'type' => 'Collection',
          'creditor' => $collection['original']['creditGrantor']['unparsed'] ?? $collection['subscriber']['name']['unparsed'] ?? 'UNKNOWN',
          'subscriber_number' => $collection['subscriber']['memberCode'] ?? '',
          'kind_of_business' => TransUnionConstants::INDUSTRY_CODE_MAP[$collection['subscriber']['industryCode'] ?? ''] ?? 'Collection',
          'joint' => $joint,
          'ecoa' => $collection['ECOADesignator'] ?? 'Undesignated',
          'account_num' => '',
          'status' => TransUnionConstants::MOP_MAP[$mopCode] ?? 'Collection',
          'status_date' => $collection['dateEffective'] ?? $collection['dateOpened'] ?? '',
          'standing' => $isOpen ? 2 : 0, // Collections are derogatory
          'months_reported' => 0,
          'balance' => (float)($collection['currentBalance'] ?? 0),
          'balance_date' => $collection['mostRecentPayment']['date'] ?? '',
          'credit_limit' => 0,
          'payment' => 0,
          'actual_payment' => 0,
          'past_due' => (float)($collection['currentBalance'] ?? 0),
          'original_amount' => (float)($collection['original']['balance'] ?? 0),
          'high_credit' => (float)($collection['original']['balance'] ?? 0),
          'terms' => '',
          'last_payment_date' => $collection['mostRecentPayment']['date'] ?? '',
          'payment_history' => '',
          'derogatory_30' => 0,
          'derogatory_60' => 0,
          'derogatory_90' => 0,
          'first_delinquency' => $collection['paymentHistory']['maxDelinquency']['date'] ?? '',
          'second_delinquency' => '',
          'extra' => 'Collection Account'
        ];
    }

    private function buildExtraInfo(array $trade): string
    {
        $extra = [];

        if (isset($trade['updateMethod'])) {
            $extra[] = 'Automated Update Indicator: "' . $trade['updateMethod'] . '"';
        }

        if (isset($trade['evaluation'])) {
            $extra[] = 'Evaluation: "' . $trade['evaluation'] . '"';
        }

        return implode(', ', $extra);
    }
}
