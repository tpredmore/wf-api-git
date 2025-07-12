<?php

declare(strict_types=1);

namespace WF\API\Automation\Parsers;

use WF\API\Automation\Models\CreditProfile;

class EquifaxParser extends AbstractCreditParser
{
    public function parse(array $rawData): CreditProfile
    {
        $creditProfile = $rawData['creditProfile'][0] ?? [];

        return CreditProfile::fromArray([
          'fico_score' => $this->extractFicoScore($creditProfile),
          'bureau' => 'equifax',
          'open_trade_count' => $this->countOpenTrades($creditProfile),
          'auto_trade_count' => $this->countAutoTrades($creditProfile),
          'derogatory_marks' => $this->countDerogatoryMarks($creditProfile),
          'bankruptcies' => $this->countBankruptcies($creditProfile),
          'revolving_utilization' => $this->calculateRevolvingUtilization($this->extractTradeLines($creditProfile)),
          'inquiries_6mo' => $this->countRecentInquiries($creditProfile),
          'estimated_monthly_debt' => $this->estimateMonthlyDebt($this->extractTradeLines($creditProfile)),
          'trade_lines' => $this->extractTradeLines($creditProfile),
          'score_factors' => $this->extractEquifaxScoreFactors($creditProfile),
          'hit' => $this->hasHit($creditProfile),
          'pulled_at' => $this->extractPullDate($creditProfile),
        ]);
    }

    public function getSupportedBureau(): string
    {
        return 'equifax';
    }

    private function extractFicoScore(array $creditProfile): ?int
    {
        if (isset($creditProfile['riskModel'][0]['score'])) {
            $score = (int)$creditProfile['riskModel'][0]['score'];
            return $score > 0 ? $score : null;
        }
        return null;
    }

    private function countOpenTrades(array $creditProfile): int
    {
        $trades = $creditProfile['tradeline'] ?? [];
        return count(array_filter($trades, fn($trade) => ($trade['openOrClosed'] ?? 'O') === 'O'));
    }

    private function countAutoTrades(array $creditProfile): int
    {
        $trades = $creditProfile['tradeline'] ?? [];
        return count(array_filter($trades, function($trade) {
            $accountType = $trade['accountType'] ?? '';
            return in_array($accountType, ['00', '3A']); // Auto loan types
        }));
    }

    private function countDerogatoryMarks(array $creditProfile): int
    {
        $trades = $creditProfile['tradeline'] ?? [];
        $derogCount = 0;

        foreach ($trades as $trade) {
            $derog30 = (int)($trade['delinquencies30Days'] ?? 0);
            $derog60 = (int)($trade['delinquencies60Days'] ?? 0);
            $derog90 = (int)($trade['delinquencies90to180Days'] ?? 0);

            if ($derog30 > 0 || $derog60 > 0 || $derog90 > 0) {
                $derogCount++;
            }
        }

        return $derogCount;
    }

    private function countBankruptcies(array $creditProfile): int
    {
        // Bankruptcies would be in public records section
        return isset($creditProfile['bankruptcy']) ? count($creditProfile['bankruptcy']) : 0;
    }

    private function countRecentInquiries(array $creditProfile): int
    {
        $inquiries = $creditProfile['inquiry'] ?? [];
        $sixMonthsAgo = new \DateTime('-6 months');

        return count(array_filter($inquiries, function($inquiry) use ($sixMonthsAgo) {
            $inquiryDate = $this->parseEquifaxDate($inquiry['date'] ?? '');
            if ($inquiryDate) {
                return $inquiryDate >= $sixMonthsAgo;
            }
            return false;
        }));
    }

    private function extractTradeLines(array $creditProfile): array
    {
        $trades = $creditProfile['tradeline'] ?? [];
        $formattedTrades = [];

        foreach ($trades as $trade) {
            $isOpen = ($trade['openOrClosed'] ?? 'O') === 'O';
            $isRevolving = ($trade['revolvingOrInstallment'] ?? 'I') === 'R';

            $formattedTrades[] = [
              'bureau' => 'equifax',
              'opened' => $this->formatEquifaxDate($trade['openDate'] ?? ''),
              'is_open' => $isOpen,
              'closed' => $isOpen ? '' : $this->formatEquifaxDate($trade['statusDate'] ?? ''),
              'type' => $this->mapAccountType($trade['accountType'] ?? ''),
              'creditor' => $trade['subscriberName'] ?? 'UNKNOWN',
              'subscriber_number' => $trade['subscriberCode'] ?? '',
              'kind_of_business' => $this->mapKindOfBusiness($trade['kob'] ?? ''),
              'joint' => ($trade['ecoa'] ?? '1') !== '1',
              'ecoa' => $this->mapEcoaCode($trade['ecoa'] ?? '1'),
              'account_num' => $trade['accountNumber'] ?? '',
              'status' => $this->mapEquifaxStatus($trade['status'] ?? ''),
              'status_date' => $this->formatEquifaxDate($trade['statusDate'] ?? ''),
              'standing' => $this->calculateStanding($trade),
              'months_reported' => (int)($trade['monthsHistory'] ?? 0),
              'balance' => $this->parseEquifaxAmount($trade['balanceAmount'] ?? '0'),
              'balance_date' => $this->formatEquifaxDate($trade['balanceDate'] ?? ''),
              'credit_limit' => $isRevolving ? $this->parseEquifaxAmount($trade['amount1'] ?? '0') : 0,
              'payment' => $this->parseEquifaxAmount($trade['monthlyPaymentAmount'] ?? '0'),
              'actual_payment' => $this->parseEquifaxAmount($trade['enhancedPaymentData']['actualPaymentAmount'] ?? $trade['monthlyPaymentAmount'] ?? '0'),
              'past_due' => $this->parseEquifaxAmount($trade['amountPastDue'] ?? '0'),
              'original_amount' => $isRevolving ? 0 : $this->parseEquifaxAmount($trade['amount1'] ?? '0'),
              'high_credit' => $this->parseEquifaxAmount($trade['amount2'] ?? '0'),
              'terms' => $trade['terms'] ?? ($isRevolving ? 'REV' : ''),
              'last_payment_date' => $this->formatEquifaxDate($trade['lastPaymentDate'] ?? ''),
              'payment_history' => $trade['paymentHistory'] ?? '',
              'derogatory_30' => $trade['delinquencies30Days'] ?? '0',
              'derogatory_60' => $trade['delinquencies60Days'] ?? '0',
              'derogatory_90' => $trade['delinquencies90to180Days'] ?? '0',
              'first_delinquency' => $this->formatEquifaxDate($trade['enhancedPaymentData']['firstDelinquencyDate'] ?? ''),
              'second_delinquency' => $this->formatEquifaxDate($trade['enhancedPaymentData']['secondDelinquencyDate'] ?? ''),
              'extra' => $this->buildExtraInfo($trade)
            ];
        }

        return $formattedTrades;
    }

    private function extractEquifaxScoreFactors(array $creditProfile): array
    {
        $factors = [];

        if (isset($creditProfile['riskModel'][0]['scoreFactors'])) {
            foreach ($creditProfile['riskModel'][0]['scoreFactors'] as $factor) {
                $factors[] = [
                  'priority' => $factor['importance'] ?? '',
                  'code' => $factor['code'] ?? '',
                  'description' => $this->getScoreFactorDescription($factor['code'] ?? '')
                ];
            }
        }

        return $factors;
    }

    private function hasHit(array $creditProfile): bool
    {
        // Check if we have valid data - if we have tradelines or score, it's a hit
        return !empty($creditProfile['tradeline']) ||
          (!empty($creditProfile['riskModel']) &&
            (int)($creditProfile['riskModel'][0]['score'] ?? 0) > 0);
    }

    private function extractPullDate(array $creditProfile): string
    {
        if (isset($creditProfile['headerRecord'][0]['y2kReportedDate'])) {
            $date = $creditProfile['headerRecord'][0]['y2kReportedDate'];
            // Convert MMDDYYYY to YYYY-MM-DD HH:MM:SS
            if (strlen($date) === 8) {
                $formatted = '20' . substr($date, 4, 2) . '-' . substr($date, 0, 2) . '-' . substr($date, 2, 2);

                // Add time if available
                if (isset($creditProfile['headerRecord'][0]['reportTime'])) {
                    $time = $creditProfile['headerRecord'][0]['reportTime'];
                    if (strlen($time) === 6) {
                        $formatted .= ' ' . substr($time, 0, 2) . ':' . substr($time, 2, 2) . ':' . substr($time, 4, 2);
                    }
                }

                return $formatted;
            }
        }

        return date('Y-m-d H:i:s');
    }

    private function parseEquifaxDate(string $date): ?\DateTime
    {
        if (empty($date) || strlen($date) < 8) {
            return null;
        }

        // Equifax dates are typically MMDDYYYY
        if (strlen($date) === 8) {
            $formatted = '20' . substr($date, 4, 2) . '-' . substr($date, 0, 2) . '-' . substr($date, 2, 2);
            return \DateTime::createFromFormat('Y-m-d', $formatted) ?: null;
        }

        return null;
    }

    private function formatEquifaxDate(string $date): string
    {
        if (empty($date) || strlen($date) < 8) {
            return '';
        }

        // Convert MMDDYYYY to YYYY-MM-DD
        if (strlen($date) === 8) {
            return '20' . substr($date, 4, 2) . '-' . substr($date, 0, 2) . '-' . substr($date, 2, 2);
        }

        return $date;
    }

    private function parseEquifaxAmount(string $amount): float
    {
        return (float)ltrim($amount, '0') ?: 0.0;
    }

    private function mapAccountType(string $type): string
    {
        return match ($type) {
            '00' => 'Auto Loan',
            '01' => 'Personal Loan',
            '07' => 'Credit Card',
            '18' => 'Credit Card',
            '26' => 'Conventional Real Estate Loan',
            '27' => 'Real Estate Mortgage',
            '3A' => 'Auto Lease',
            default => 'Other'
        };
    }

    private function mapKindOfBusiness(string $kob): string
    {
        return match ($kob) {
            'BC' => 'Bank Credit Cards',
            'BB' => 'Banks',
            'FA' => 'Auto Finance',
            'FP' => 'Finance/Personal',
            'DC' => 'Department/Clothing Store',
            'ZR' => 'Home Improvement',
            'CG' => 'Clothing',
            'SZ' => 'Sporting Goods',
            'DV' => 'Variety Store',
            default => 'Other'
        };
    }

    private function mapEcoaCode(string $ecoa): string
    {
        return match ($ecoa) {
            '1' => 'Individual Account',
            '2' => 'Joint Account',
            '3' => 'Authorized User',
            default => 'Undesignated'
        };
    }

    private function mapEquifaxStatus(string $status): string
    {
        return match ($status) {
            '11' => 'This is an account in good standing',
            '71' => '30 days past due',
            '78' => '60 days past due',
            '84' => '90 days past due',
            default => 'Unknown status'
        };
    }

    private function calculateStanding(array $trade): int
    {
        $isOpen = ($trade['openOrClosed'] ?? 'O') === 'O';
        $status = $trade['status'] ?? '11';

        if (!$isOpen) {
            return 0; // Closed
        }

        if ($status === '11') {
            return 1; // Open/Current
        }

        return 2; // Open/Delinquent
    }

    private function buildExtraInfo(array $trade): string
    {
        $extra = [];

        if (isset($trade['evaluation'])) {
            $extra[] = 'Evaluation Code: "' . $trade['evaluation'] . '"';
        }

        if (isset($trade['enhancedPaymentData']['enhancedAccountCondition'])) {
            $extra[] = 'Account Condition: "' . $trade['enhancedPaymentData']['enhancedAccountCondition'] . '"';
        }

        return implode(', ', $extra);
    }

    private function getScoreFactorDescription(string $code): string
    {
        $factorMap = [
          "1" => "AMOUNT OWED ON ACCOUNTS IS TOO HIGH",
          "2" => "LEVEL OF DELINQUENCY ON ACCOUNTS",
          "3" => "TOO FEW BANK REVOLVING ACCOUNTS",
          "4" => "TOO MANY BANK OR NATIONAL REVOLVING ACCOUNTS",
          "5" => "TOO MANY ACCOUNTS WITH BALANCES",
          "6" => "TOO MANY CONSUMER FINANCE COMPANY ACCOUNTS",
          "7" => "ACCOUNT PAYMENT HISTORY IS TOO NEW TO RATE",
          "8" => "TOO MANY RECENT INQUIRIES LAST 12 MONTHS",
          "9" => "TOO MANY ACCOUNTS RECENTLY OPENED",
          "10" => "PROPORTION OF BALANCES TO CREDIT LIMITS IS TOO HIGH ON BANK REVOLVING OR OTHER REVOLVING ACCOUNTS",
          "11" => "AMOUNT OWED ON REVOLVING ACCOUNTS IS TOO HIGH",
          "12" => "LENGTH OF TIME REVOLVING ACCOUNTS HAVE BEEN ESTABLISHED",
          "13" => "TIME SINCE DELINQUENCY IS TOO RECENT OR UNKNOWN",
          "14" => "LENGTH OF TIME ACCOUNTS HAVE BEEN ESTABLISHED",
          "15" => "LACK OF RECENT BANK REVOLVING INFORMATION",
          "16" => "LACK OF RECENT REVOLVING ACCOUNT INFORMATION",
          "17" => "NO RECENT NON-MORTGAGE BALANCE INFORMATION",
          "18" => "NUMBER OF ACCOUNTS WITH DELINQUENCY",
          "19" => "TOO FEW ACCOUNTS CURRENTLY PAID AS AGREED",
          "20" => "TIME SINCE DEROGATORY PUBLIC RECORD OR COLLECTION IS TOO SHORT",
          "21" => "AMOUNT PAST DUE ON ACCOUNTS",
          "22" => "SERIOUS DELINQUENCY, DEROGATORY PUBLIC RECORD OR COLLECTION FILED",
          "23" => "NUMBER OF BANK OR NATIONAL REVOLVING ACCOUNTS WITH BALANCES",
          "24" => "NO RECENT REVOLVING BALANCES",
          "25" => "LENGTH OF TIME INSTALLMENT LOANS HAVE BEEN ESTABLISHED",
          "26" => "NUMBER OF REVOLVING ACCOUNTS",
          "28" => "NUMBER OF ESTABLISHED ACCOUNTS",
          "30" => "TIME SINCE MOST RECENT ACCOUNT OPENING TOO SHORT",
          "31" => "TOO FEW ACCOUNTS WITH RECENT PAYMENT INFORMATION",
          "32" => "LACK OF RECENT INSTALLMENT LOAN INFORMATION",
          "33" => "PROPORTION OF LOAN BALANCES TO LOAN AMOUNTS IS TOO HIGH",
          "34" => "AMOUNT OWED ON DELINQUENT ACCOUNTS",
          "38" => "SERIOUS DELINQUENCY AND PUBLIC RECORD OR COLLECTION FILED",
          "39" => "SERIOUS DELINQUENCY",
          "40" => "DEROGATORY PUBLIC RECORD OR COLLECTION FILED",
          "43" => "LACK OF RECENT REVOLVING ACCOUNT INFORMATION",
          "45" => "TIME SINCE MOST RECENT ACCOUNT OPENING TOO SHORT",
          "68" => "NUMBER OF ACCOUNTS WITH BALANCES",
          "84" => "NUMBER OF INQUIRIES WAS A FACTOR IN DETERMINING THE SCORE",
          "98" => "LACK OF RECENT AUTO FINANCE LOAN INFORMATION",
          "99" => "LACK OF RECENT CONSUMER FINANCE COMPANY ACCOUNT INFORMATION"
        ];

        return $factorMap[$code] ?? "Score factor code: $code";
    }
}