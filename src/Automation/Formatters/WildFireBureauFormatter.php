<?php

declare(strict_types=1);

namespace WF\API\Automation\Formatters;

use WF\API\Automation\Constants\ExperianConstants;
use WF\API\Automation\Models\CreditProfile;

/**
 * Formats credit data into WildFire LOS compatible format
 */
class WildFireBureauFormatter
{
    private const EQUIFAX_SCORE_FACTOR_MAP = [
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

    public function formatToWildFire(CreditProfile $creditProfile, array $rawBureauData, bool $hasCoApplicant = false): array
    {

        return [
          "bureau" => $creditProfile->bureau,
          "raw" => json_encode($rawBureauData),
          "hit" => $creditProfile->hasHit,
          "pulled_at" => $creditProfile->pulledAt ?? date('Y-m-d H:i:s'),
          "fico_score" => $creditProfile->ficoScore ?? 0,
          "has_coapp" => $hasCoApplicant,
          "dti" => 0, // Will be calculated separately
          "open_trade_count" => $creditProfile->openTradeCount,
          "auto_trade_count" => $creditProfile->autoTradeCount,
          "open_auto_trade_count" => $this->countOpenAutoTrades($creditProfile->tradeLines),
          "derogatory_marks" => $creditProfile->derogatoryMarks,
          "now_delinquent" => $this->countCurrentDelinquencies($creditProfile->tradeLines),
          "was_delinquent" => $this->countPastDelinquencies($creditProfile->tradeLines),
          "bankruptcies" => $creditProfile->bankruptcies,
          "past_due_amount" => $this->calculatePastDueAmount($creditProfile->tradeLines),
          "satisfactory_accounts" => $this->countSatisfactoryAccounts($creditProfile->tradeLines),
          "install_balance" => $this->calculateInstallmentBalance($creditProfile->tradeLines),
          "scheduled_payment" => $this->calculateScheduledPayments($creditProfile->tradeLines),
          "real_estate_balance" => $this->calculateRealEstateBalance($creditProfile->tradeLines),
          "real_estate_payment" => $this->calculateRealEstatePayment($creditProfile->tradeLines),
          "revolving_balance" => $this->calculateRevolvingBalance($creditProfile->tradeLines),
          "revolving_limit" => $this->calculateRevolvingLimit($creditProfile->tradeLines),
          "revolving_available" => $this->calculateRevolvingUtilizationPercent($creditProfile),
          "paid_accounts" => $this->countPaidAccounts($creditProfile->tradeLines),
          "inquiries_6mo" => $creditProfile->inquiriesSixMonths,
          "oldest_trade" => $this->getOldestTradeDate($creditProfile->tradeLines),
          "score_data" => $this->formatScoreData($creditProfile),
          "identity_records" => $this->formatIdentityRecords($rawBureauData),
          "address_records" => $this->formatAddressRecords($rawBureauData),
          "employment_records" => $this->formatEmploymentRecords($rawBureauData),
          "public_records" => $this->formatPublicRecords($rawBureauData, $creditProfile->bureau),
          "inquiries" => $this->formatInquiries($rawBureauData),
          "collections" => $this->formatCollections($rawBureauData, $creditProfile->bureau),
          "trade_lines" => $this->formatTradeLines($creditProfile->tradeLines, $creditProfile->bureau),
          "bureau_errors" => $this->extractBureauErrors($rawBureauData),
          "bureau_alerts" => $this->extractBureauAlerts($rawBureauData),
          "report_date" => $this->extractReportDate($rawBureauData),
          "bureau_address" => $this->getBureauAddress($creditProfile->bureau),
          "bureau_phone" => $this->extractBureauPhone($rawBureauData),
          "is_coapp" => false // Always false for primary applicant
        ];
    }

    private function countOpenAutoTrades(array $tradeLines): int
    {
        return count(array_filter($tradeLines, function($trade) {
            return ($trade['is_open'] ?? false) &&
              (stripos($trade['type'] ?? '', 'auto') !== false ||
                stripos($trade['type'] ?? '', 'vehicle') !== false);
        }));
    }

    private function countCurrentDelinquencies(array $tradeLines): int
    {
        return count(array_filter($tradeLines, function($trade) {
            return ($trade['is_open'] ?? false) &&
              (($trade['derogatory_30'] ?? 0) > 0 ||
                ($trade['derogatory_60'] ?? 0) > 0 ||
                ($trade['derogatory_90'] ?? 0) > 0);
        }));
    }

    private function countPastDelinquencies(array $tradeLines): int
    {
        return count(array_filter($tradeLines, function($trade) {
            $paymentHistory = $trade['payment_history'] ?? '';
            return preg_match('/[2-9]/', $paymentHistory) > 0;
        }));
    }

    private function calculatePastDueAmount(array $tradeLines): float
    {
        return array_sum(array_map(function($trade) {
            return (float)($trade['past_due'] ?? 0);
        }, $tradeLines));
    }

    private function countSatisfactoryAccounts(array $tradeLines): int
    {
        return count(array_filter($tradeLines, function($trade) {
            return stripos($trade['status'] ?? '', 'good standing') !== false ||
              stripos($trade['status'] ?? '', 'current') !== false ||
              stripos($trade['status'] ?? '', 'paid') !== false;
        }));
    }

    private function calculateInstallmentBalance(array $tradeLines): float
    {
        return array_sum(array_filter(array_map(function($trade) {
            $type = strtolower($trade['type'] ?? '');
            if (stripos($type, 'installment') !== false ||
              stripos($type, 'auto') !== false ||
              stripos($type, 'personal') !== false) {
                return (float)($trade['balance'] ?? 0);
            }
            return 0;
        }, $tradeLines)));
    }

    private function calculateScheduledPayments(array $tradeLines): float
    {
        return array_sum(array_map(function($trade) {
            return (float)($trade['payment'] ?? 0);
        }, array_filter($tradeLines, function($trade) {
            return $trade['is_open'] ?? false;
        })));
    }

    private function calculateRealEstateBalance(array $tradeLines): float
    {
        return array_sum(array_filter(array_map(function($trade) {
            $type = strtolower($trade['type'] ?? '');
            if (stripos($type, 'real estate') !== false ||
              stripos($type, 'mortgage') !== false ||
              stripos($type, 'home equity') !== false) {
                return (float)($trade['balance'] ?? 0);
            }
            return 0;
        }, $tradeLines)));
    }

    private function calculateRealEstatePayment(array $tradeLines): float
    {
        return array_sum(array_filter(array_map(function($trade) {
            $type = strtolower($trade['type'] ?? '');
            if (stripos($type, 'real estate') !== false ||
              stripos($type, 'mortgage') !== false ||
              stripos($type, 'home equity') !== false) {
                return (float)($trade['payment'] ?? 0);
            }
            return 0;
        }, $tradeLines)));
    }

    private function calculateRevolvingBalance(array $tradeLines): float
    {
        return array_sum(array_filter(array_map(function($trade) {
            $type = strtolower($trade['type'] ?? '');
            if (stripos($type, 'credit card') !== false ||
              stripos($type, 'revolving') !== false) {
                return (float)($trade['balance'] ?? 0);
            }
            return 0;
        }, $tradeLines)));
    }

    private function calculateRevolvingLimit(array $tradeLines): float
    {
        return array_sum(array_filter(array_map(function($trade) {
            $type = strtolower($trade['type'] ?? '');
            if (stripos($type, 'credit card') !== false ||
              stripos($type, 'revolving') !== false) {
                return (float)($trade['credit_limit'] ?? 0);
            }
            return 0;
        }, $tradeLines)));
    }

    private function calculateRevolvingUtilizationPercent(CreditProfile $creditProfile): int
    {
        return (int)($creditProfile->revolvingUtilization * 100);
    }

    private function countPaidAccounts(array $tradeLines): int
    {
        return count(array_filter($tradeLines, function($trade) {
            return !($trade['is_open'] ?? true) &&
              stripos($trade['status'] ?? '', 'paid') !== false;
        }));
    }

    private function getOldestTradeDate(array $tradeLines): string
    {
        $oldestDate = '';
        foreach ($tradeLines as $trade) {
            $opened = $trade['opened'] ?? '';
            if (!empty($opened) && (empty($oldestDate) || $opened < $oldestDate)) {
                $oldestDate = $opened;
            }
        }

        if (empty($oldestDate)) {
            return '';
        }

        // Convert to MM/YYYY format
        return date('m/Y', strtotime($oldestDate));
    }

    private function formatScoreData(CreditProfile $creditProfile): array
    {
        $factors = [];
        foreach ($creditProfile->scoreFactors as $index => $factor) {
            $factors[] = [
              "prio" => (string)($index + 1),
              "code" => $factor['code'] ?? '',
              "desc" => $this->getScoreFactorDescription($factor['code'] ?? '', $creditProfile->bureau)
            ];
        }

        return [
          "score" => (string)($creditProfile->ficoScore ?? 0),
          "percentile" => "N/A",
          "model" => $this->getScoreModel($creditProfile->bureau),
          "evaluation" => "N/A",
          "factors" => $factors
        ];
    }

    private function getScoreFactorDescription(string $code, string $bureau): string
    {
        if ($bureau === 'equifax' && isset(self::EQUIFAX_SCORE_FACTOR_MAP[$code])) {
            return self::EQUIFAX_SCORE_FACTOR_MAP[$code];
        }

        return "Score factor code: $code";
    }

    private function getScoreModel(string $bureau): string
    {
        return match ($bureau) {
            'equifax' => 'FICO Score 8 based on Equifax Data',
            'experian' => 'FICO Score 8 based on Experian Data',
            'transunion' => 'VantageScore 4',
            default => 'Unknown Score Model'
        };
    }

    private function getBureauAddress(string $bureau): string
    {
        return match ($bureau) {
            'equifax' => 'P.O. Box 740241, Atlanta, GA 30374',
            'experian' => 'P.O. Box 2104, Allen, TX 75013',
            'transunion' => 'P.O. Box 1000, Chester, PA 19016',
            default => ''
        };
    }

    private function formatIdentityRecords(array $rawData): array
    {
        $identities = [];

        // Handle Equifax format
        if (isset($rawData['creditProfile'][0]['consumerIdentity']['name'])) {
            foreach ($rawData['creditProfile'][0]['consumerIdentity']['name'] as $name) {
                $identities[] = [
                  "first_name" => $name['firstName'] ?? '',
                  "last_name" => $name['surname'] ?? '',
                  "middle_name" => $name['middleName'] ?? '',
                  "dob" => '', // DOB not typically in name records
                  "ssn" => ''  // SSN not in name records for privacy
                ];
            }
        }

        return $identities;
    }

    private function formatAddressRecords(array $rawData): array
    {
        $addresses = [];

        // Handle Equifax format
        if (isset($rawData['creditProfile'][0]['addressInformation'])) {
            foreach ($rawData['creditProfile'][0]['addressInformation'] as $addr) {
                $address = ($addr['streetPrefix'] ?? '') . ' ' .
                  ($addr['streetName'] ?? '') . ' ' .
                  ($addr['streetSuffix'] ?? '');

                if (!empty($addr['unitType']) && !empty($addr['unitId'])) {
                    $address .= ' ' . $addr['unitType'] . ' ' . $addr['unitId'];
                }

                $addresses[] = [
                  "city" => $addr['city'] ?? '',
                  "state" => $addr['state'] ?? '',
                  "zip" => $addr['zipCode'] ?? '',
                  "first_reported" => $this->formatEquifaxDate($addr['firstReportedDate'] ?? ''),
                  "source" => $addr['source'] ?? '',
                  "address" => trim($address),
                  "current" => ($addr['dwellingType'] ?? '') === 'C' // C = Current
                ];
            }
        }

        return $addresses;
    }

    private function formatEmploymentRecords(array $rawData): array
    {
        $employment = [];

        // Handle Equifax format
        if (isset($rawData['creditProfile'][0]['employmentInformation'])) {
            foreach ($rawData['creditProfile'][0]['employmentInformation'] as $emp) {
                $employment[] = [
                  "occupation" => '', // Not provided in this format
                  "name" => $emp['name'] ?? ''
                ];
            }
        }

        return $employment;
    }

    private function formatPublicRecords(array $rawData, string $bureau): array
    {
        $publicRecords = [];

        // Handle Equifax format - bankruptcies, liens, judgments would be in separate sections
        // This example data doesn't show public records, but structure would be similar
        if (isset($rawData['creditProfile'][0]['publicRecord'])) {
            foreach ($rawData['creditProfile'][0]['publicRecord'] as $record) {
                $publicRecords[] = [
                  "bureau" => $bureau,
                  "court_name" => $record['court'] ?? 'UNKNOWN',
                  "court_code" => $record['courtCode'] ?? 'UNK',
                  "filed" => $this->formatEquifaxDate($record['dateFiled'] ?? ''),
                  "reference_number" => $record['customerNumber'] ?? '',
                  "joint" => ($record['ecoa'] ?? '') === '2' ? "Yes" : "No",
                  "status" => $record['status'] ?? '',
                  "status_date" => $this->formatEquifaxDate($record['statusDate'] ?? ''),
                  "evaluation" => $record['evaluation'] ?? ''
                ];
            }
        }

        return $publicRecords;
    }

    private function formatInquiries(array $rawData): array
    {
        $inquiries = [];

        // Handle Equifax format
        if (isset($rawData['creditProfile'][0]['inquiry'])) {
            foreach ($rawData['creditProfile'][0]['inquiry'] as $inquiry) {
                $inquiries[] = [
                  "name" => $inquiry['subscriberName'] ?? 'UNKNOWN',
                  "date" => $this->formatEquifaxDate($inquiry['date'] ?? ''),
                  "number" => $inquiry['subscriberCode'] ?? '',
                  "code" => $inquiry['kob'] ?? 'UNK', // Kind of Business
                  "type" => $inquiry['type'] ?? 'UNK'
                ];
            }
        }

        return $inquiries;
    }

    private function formatCollections(array $rawData, string $bureau): array
    {
        $collections = [];

        // Handle Equifax format - collections would typically be in a separate section
        // or marked as collection accounts in tradelines
        if (isset($rawData['creditProfile'][0]['collection'])) {
            foreach ($rawData['creditProfile'][0]['collection'] as $collection) {
                $collections[] = [
                  "bureau" => $bureau,
                  "assigned" => $this->formatEquifaxDate($collection['dateAssigned'] ?? ''),
                  "type" => "COLLECTIONS ACCT FOR " . ($collection['creditorName'] ?? 'UNKNOWN'),
                  "creditor" => $collection['creditorName'] ?? 'UNKNOWN',
                  "code" => $collection['kob'] ?? 'UNK',
                  "subscriber_number" => $collection['subscriberCode'] ?? '',
                  "joint" => ($collection['ecoa'] ?? '1') !== '1',
                  "ecoa" => $this->mapEcoaCode($collection['ecoa'] ?? '1'),
                  "status" => $collection['status'] ?? 'UNKNOWN',
                  "status_date" => $this->formatEquifaxDate($collection['statusDate'] ?? ''),
                  "balance" => $this->parseEquifaxAmount($collection['balanceAmount'] ?? '0'),
                  "balance_date" => $this->formatEquifaxDate($collection['balanceDate'] ?? ''),
                  "first_delinquency" => $this->formatEquifaxDate($collection['firstDelinquencyDate'] ?? ''),
                  "original_amount" => $this->parseEquifaxAmount($collection['originalAmount'] ?? '0'),
                  "high_credit" => $this->parseEquifaxAmount($collection['originalAmount'] ?? '0')
                ];
            }
        }

        return $collections;
    }

    private function formatTradeLines(array $tradeLines, string $bureau): array
    {
        // If tradeLines is already processed from CreditProfile, just add bureau
        if (!empty($tradeLines) && isset($tradeLines[0]['bureau'])) {
            return $tradeLines;
        }

        $formattedTrades = [];

        // Handle raw Equifax tradeline format
        if (isset($tradeLines[0]['accountType'])) {
            foreach ($tradeLines as $trade) {
                $formattedTrades[] = $this->formatEquifaxTradeLine($trade, $bureau);
            }
        } else {
            // Already formatted, just add bureau
            foreach ($tradeLines as $trade) {
                $formattedTrades[] = array_merge($trade, ['bureau' => $bureau]);
            }
        }

        return $formattedTrades;
    }

    private function formatEquifaxTradeLine(array $trade, string $bureau): array
    {
        $isOpen = ($trade['openOrClosed'] ?? 'O') === 'O';
        $isRevolving = ($trade['revolvingOrInstallment'] ?? 'I') === 'R';

        return [
          "bureau" => $bureau,
          "opened" => $this->formatEquifaxDate($trade['openDate'] ?? ''),
          "is_open" => $isOpen,
          "closed" => $isOpen ? '' : $this->formatEquifaxDate($trade['statusDate'] ?? ''),
          "type" => $this->mapEquifaxAccountType($trade['accountType'] ?? ''),
          "creditor" => $trade['subscriberName'] ?? 'UNKNOWN',
          "subscriber_number" => $trade['subscriberCode'] ?? '',
          "kind_of_business" => $this->mapKindOfBusiness($trade['kob'] ?? ''),
          "joint" => ($trade['ecoa'] ?? '1') !== '1',
          "ecoa" => $this->mapEcoaCode($trade['ecoa'] ?? '1'),
          "account_num" => $trade['accountNumber'] ?? '',
          "status" => $this->mapEquifaxStatus($trade['status'] ?? ''),
          "status_date" => $this->formatEquifaxDate($trade['statusDate'] ?? ''),
          "standing" => $this->calculateStanding($trade),
          "months_reported" => (int)($trade['monthsHistory'] ?? 0),
          "balance" => $this->parseEquifaxAmount($trade['balanceAmount'] ?? '0'),
          "balance_date" => $this->formatEquifaxDate($trade['balanceDate'] ?? ''),
          "credit_limit" => $isRevolving ? $this->parseEquifaxAmount($trade['amount1'] ?? '0') : '',
          "payment" => $this->parseEquifaxAmount($trade['monthlyPaymentAmount'] ?? '0'),
          "actual_payment" => $this->parseEquifaxAmount($trade['enhancedPaymentData']['actualPaymentAmount'] ?? $trade['monthlyPaymentAmount'] ?? '0'),
          "past_due" => $this->parseEquifaxAmount($trade['amountPastDue'] ?? '0'),
          "original_amount" => $isRevolving ? '' : $this->parseEquifaxAmount($trade['amount1'] ?? '0'),
          "high_credit" => $this->parseEquifaxAmount($trade['amount2'] ?? '0'),
          "terms" => $trade['terms'] ?? ($isRevolving ? 'REV' : ''),
          "last_payment_date" => $this->formatEquifaxDate($trade['lastPaymentDate'] ?? ''),
          "payment_history" => $trade['paymentHistory'] ?? '',
          "derogatory_30" => $trade['delinquencies30Days'] ?? '0',
          "derogatory_60" => $trade['delinquencies60Days'] ?? '0',
          "derogatory_90" => $trade['delinquencies90to180Days'] ?? '0',
          "first_delinquency" => $this->formatEquifaxDate($trade['enhancedPaymentData']['firstDelinquencyDate'] ?? ''),
          "second_delinquency" => $this->formatEquifaxDate($trade['enhancedPaymentData']['secondDelinquencyDate'] ?? ''),
          "extra" => $this->buildExtraInfo($trade)
        ];
    }

    private function extractBureauErrors(array $rawData): array
    {
        $errors = [];

        // Handle Equifax format
        if (isset($rawData['creditProfile'][0]['informationalMessage'])) {
            foreach ($rawData['creditProfile'][0]['informationalMessage'] as $message) {
                if (stripos($message['messageText'] ?? '', 'error') !== false ||
                  stripos($message['messageText'] ?? '', 'not allowed') !== false) {
                    $errors[] = $message['messageText'] ?? '';
                }
            }
        }

        return $errors;
    }

    private function extractBureauAlerts(array $rawData): array
    {
        $alerts = [];

        // Handle Equifax format
        if (isset($rawData['creditProfile'][0]['informationalMessage'])) {
            foreach ($rawData['creditProfile'][0]['informationalMessage'] as $message) {
                $messageText = $message['messageText'] ?? '';
                if (stripos($messageText, 'factor') !== false ||
                  stripos($messageText, 'alert') !== false ||
                  stripos($messageText, 'fraud') !== false) {
                    $alerts[] = $messageText;
                }
            }
        }

        // Check fraud shield indicators
        if (isset($rawData['creditProfile'][0]['fraudShield'])) {
            foreach ($rawData['creditProfile'][0]['fraudShield'] as $fraud) {
                if (isset($fraud['fraudShieldIndicators']['indicator'])) {
                    foreach ($fraud['fraudShieldIndicators']['indicator'] as $indicator) {
                        $alerts[] = "Fraud Shield Indicator: " . $indicator;
                    }
                }
            }
        }

        return $alerts;
    }

    private function extractReportDate(array $rawData): string
    {
        // Handle Equifax format
        if (isset($rawData['creditProfile'][0]['headerRecord'][0]['reportDate'])) {
            return $rawData['creditProfile'][0]['headerRecord'][0]['reportDate'];
        }

        return date('mdy');
    }

    private function extractBureauPhone(array $rawData): string
    {
        // Phone numbers typically in consumer contact info - not in this example
        // Would need to check specific sections for contact information
        return '';
    }

    // Helper methods for Equifax data formatting

    private function formatEquifaxDate(string $date): string
    {
        if (empty($date) || strlen($date) < 8) {
            return '';
        }

        // Equifax dates are typically MMDDYYYY
        if (strlen($date) === 8) {
            return '20' . substr($date, 4, 2) . '-' . substr($date, 0, 2) . '-' . substr($date, 2, 2);
        }

        return $date;
    }

    private function parseEquifaxAmount(string $amount): float
    {
        // Equifax amounts are typically zero-padded strings
        return (float)ltrim($amount, '0') ?: 0.0;
    }

    private function mapEquifaxAccountType(string $type): string
    {
        return match ($type) {
            '00' => 'Auto Loan',
            '01' => 'Personal Loan',
            '07' => 'Credit Card',
            '18' => 'Credit Card',
            '26' => 'Conventional Real Estate Loan',
            '27' => 'Real Estate Mortgage',
            default => 'Other'
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

    private function mapEcoaCode(string $ecoa): string
    {
        return match ($ecoa) {
            '1' => 'Individual Account',
            '2' => 'Joint Account',
            '3' => 'Authorized User',
            default => 'Undesignated'
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
    /**
     * Format Experian-specific data
     */
    private function formatExperianData(array $rawData, CreditProfile $creditProfile): array
    {
        $result = [];
        $report = $rawData['creditProfile'][0] ?? [];

        // Handle TTY response
        if (!empty($rawData['tty']['ttyResponse'])) {
            $result['tty'] = $rawData['tty']['ttyResponse'];
        }

        // Format identity records
        $result['identity_records'] = $this->formatExperianIdentityRecords($report);

        // Format address records
        $result['address_records'] = $this->formatExperianAddressRecords($report);

        // Format employment records
        $result['employment_records'] = $this->formatExperianEmploymentRecords($report);

        // Format public records
        $result['public_records'] = $this->formatExperianPublicRecords($report, 'experian');

        // Format inquiries
        $result['inquiries'] = $this->formatExperianInquiries($report);

        // Format collections (if any)
        $result['collections'] = $this->formatExperianCollections($report, 'experian');

        // Extract bureau messages and alerts
        $messages = $this->extractExperianMessages($report, $rawData);
        $result['bureau_errors'] = $messages['errors'];
        $result['bureau_alerts'] = $messages['alerts'];

        // Report date
        $result['report_date'] = $report['headerRecord'][0]['reportDate'] ?? date('mdy');

        return $result;
    }

    private function formatExperianIdentityRecords(array $report): array
    {
        $identities = [];

        if (isset($report['consumerIdentity']['name'])) {
            foreach ($report['consumerIdentity']['name'] as $name) {
                $identities[] = [
                  'first_name' => $name['firstName'] ?? '',
                  'last_name' => $name['surname'] ?? '',
                  'middle_name' => $name['middleName'] ?? '',
                  'dob' => '',
                  'ssn' => ''
                ];
            }
        }

        return $identities;
    }

    private function formatExperianAddressRecords(array $report): array
    {
        $addresses = [];

        if (isset($report['addressInformation'])) {
            foreach ($report['addressInformation'] as $addr) {
                $address = trim(
                  ($addr['streetPrefix'] ?? '') . ' ' .
                  ($addr['streetName'] ?? '') . ' ' .
                  ($addr['streetSuffix'] ?? '')
                );

                $addresses[] = [
                  'city' => $addr['city'] ?? '',
                  'state' => $addr['state'] ?? '',
                  'zip' => $addr['zipCode'] ?? '',
                  'first_reported' => $this->formatExperianDateToYMD($addr['firstReportedDate'] ?? ''),
                  'source' => $addr['source'] ?? '',
                  'address' => $address,
                  'current' => ($addr['dwellingType'] ?? '') === 'S' // S = Single
                ];
            }
        }

        return $addresses;
    }

    private function formatExperianEmploymentRecords(array $report): array
    {
        $employment = [];

        if (isset($report['employmentInformation'])) {
            foreach ($report['employmentInformation'] as $emp) {
                $employment[] = [
                  'occupation' => '',
                  'name' => $emp['name'] ?? ''
                ];
            }
        }

        return $employment;
    }

    private function formatExperianPublicRecords(array $report, string $bureau): array
    {
        $publicRecords = [];

        if (isset($report['publicRecord'])) {
            foreach ($report['publicRecord'] as $rec) {
                $ecoa = $rec['ecoa'] ?? '0';
                $joint = in_array($ecoa, ['2', '4']);

                $publicRecords[] = [
                  'bureau' => $bureau,
                  'court_name' => $rec['courtName'] ?? 'UNKNOWN',
                  'court_code' => $rec['courtCode'] ?? '',
                  'filed' => $this->formatExperianDateToYMD($rec['filingDate'] ?? ''),
                  'reference_number' => $rec['referenceNumber'] ?? '',
                  'joint' => $joint ? 'Yes' : 'No',
                  'ecoa' => ExperianConstants::ECOA_MAP[$ecoa] ?? 'Undesignated',
                  'status' => $rec['status'] ?? '',
                  'status_date' => $this->formatExperianDateToYMD($rec['statusDate'] ?? ''),
                  'evaluation' => $rec['evaluation'] ?? ''
                ];
            }
        }

        return $publicRecords;
    }

    private function formatExperianInquiries(array $report): array
    {
        $inquiries = [];

        if (isset($report['inquiry'])) {
            foreach ($report['inquiry'] as $inq) {
                $inquiries[] = [
                  'name' => $inq['subscriberName'] ?? 'UNKNOWN',
                  'date' => $this->formatExperianDateToYMD($inq['date'] ?? ''),
                  'number' => $inq['subscriberCode'] ?? '',
                  'code' => $inq['kob'] ?? '',
                  'type' => $inq['type'] ?? ''
                ];
            }
        }

        return $inquiries;
    }

    private function formatExperianCollections(array $report, string $bureau): array
    {
        $collections = [];

        // In Experian, collections might be in tradelines with specific statuses
        // or in a separate collection section
        if (isset($report['collection'])) {
            foreach ($report['collection'] as $coll) {
                $ecoa = $coll['ecoa'] ?? '0';
                $joint = in_array($ecoa, ['2', '4']);

                $collections[] = [
                  'bureau' => $bureau,
                  'assigned' => $this->formatExperianDateToYMD($coll['dateAssigned'] ?? ''),
                  'type' => 'COLLECTIONS ACCT FOR ' . ($coll['creditorName'] ?? 'UNKNOWN'),
                  'creditor' => $coll['creditorName'] ?? 'UNKNOWN',
                  'code' => $coll['kob'] ?? '',
                  'subscriber_number' => $coll['subscriberCode'] ?? '',
                  'joint' => $joint,
                  'ecoa' => ExperianConstants::ECOA_MAP[$ecoa] ?? 'Undesignated',
                  'status' => $coll['status'] ?? '',
                  'status_date' => $this->formatExperianDateToYMD($coll['statusDate'] ?? ''),
                  'balance' => (float)ltrim($coll['balanceAmount'] ?? '0', '0'),
                  'balance_date' => $this->formatExperianDateToYMD($coll['balanceDate'] ?? ''),
                  'first_delinquency' => $this->formatExperianDateToYMD($coll['firstDelinquencyDate'] ?? ''),
                  'original_amount' => (float)ltrim($coll['originalAmount'] ?? '0', '0'),
                  'high_credit' => (float)ltrim($coll['originalAmount'] ?? '0', '0')
                ];
            }
        }

        return $collections;
    }

    private function extractExperianMessages(array $report, array $rawData): array
    {
        $errors = [];
        $alerts = [];

        // Informational messages
        if (isset($report['informationalMessage'])) {
            foreach ($report['informationalMessage'] as $msg) {
                $messageNumber = $msg['messageNumber'] ?? '';
                $messageText = $msg['messageText'] ?? '';

                // Skip certain messages
                if (in_array($messageNumber, ['84', '92'])) {
                    continue;
                }

                // Categorize as error or alert
                if (stripos($messageText, 'error') !== false || stripos($messageText, 'not allowed') !== false) {
                    $errors[] = $messageText;
                } else {
                    $alerts[] = $messageText;
                }
            }
        }

        // MLA messages
        if (isset($report['mla']) && $report['mla']['messageNumber'] != '1204') {
            $alerts[] = $report['mla']['messageText'];
        }

        // Fraud shield indicators
        if (isset($report['fraudShield'])) {
            foreach ($report['fraudShield'] as $fraud) {
                if (isset($fraud['fraudShieldIndicators']['indicator'])) {
                    foreach ($fraud['fraudShieldIndicators']['indicator'] as $ind) {
                        $alerts[] = 'FraudShield: ' . (ExperianConstants::FRAUD_SHIELD_MAP[$ind] ?? $ind);
                    }
                }
            }
        }

        // Statement messages (errors)
        if (isset($report['statement'])) {
            foreach ($report['statement'] as $statement) {
                $errors[] = $statement['statementText'];
            }
        }

        return [
          'errors' => $errors,
          'alerts' => $alerts
        ];
    }

    private function formatExperianDateToYMD(string $date): string
    {
        if (empty($date) || strlen($date) < 8) {
            return '';
        }

        // Convert MMDDYYYY to YYYY-MM-DD
        $parsed = \DateTime::createFromFormat('mdY', $date);
        if ($parsed) {
            return $parsed->format('Y-m-d');
        }

        return '';
    }

    /**
     * Get bureau phone number
     */
    private function getBureauPhone(string $bureau): string
    {
        return match ($bureau) {
            'equifax' => '',
            'experian' => '888-397-3742',
            'transunion' => '',
            default => ''
        };
    }
}