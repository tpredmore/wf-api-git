<?php

declare(strict_types=1);

namespace WF\API\Automation\Adapters;

use WF\API\Automation\Models\Applicant;
use WF\API\Automation\Models\Vehicle;
use WF\API\Automation\Exceptions\ValidationException;

/**
 * Parses WildFire application payload into standardized models
 */
class ApplicationPayloadParser
{

    /**
     * @throws \WF\API\Automation\Exceptions\ValidationException
     */
    public function parseApplicant(array $payload): Applicant
    {
        // Extract primary applicant data
        $coApplicantData = null;

        // Check if co-applicant is active
        if (($payload['co_applicant_active'] ?? false) || ($payload['manual_app_co_active'] ?? false)) {
            $coApplicantData = [
              'first_name' => $payload['co_applicant_first_name'] ?? '',
              'last_name' => $payload['co_applicant_last_name'] ?? '',
              'middle_name' => $payload['co_applicant_middle_name'] ?? '',
              'ssn' => $payload['co_applicant_ssn'] ?? '',
              'dob' => $payload['co_applicant_dob'] ?? '',
              'address' => $payload['co_applicant_address'] ?? '',
              'city' => $payload['co_applicant_city'] ?? '',
              'state' => $payload['co_applicant_state'] ?? '',
              'zip' => $payload['co_applicant_zip'] ?? '',
              'employment_type' => $this->mapEmploymentType($payload['co_applicant_employer_employment_type'] ?? ''),
              'monthly_income' => $this->parseIncome($payload['co_applicant_employer_income'] ?? '0'),
            ];
        }

        return Applicant::fromArray([
          'monthly_income' => $this->parseIncome($payload['total_primary_income'] ?? '0'),
          'monthly_debt' => $this->parseDebt($payload['total_debt'] ?? '0'),
          'employment_type' => $this->mapEmploymentType($payload['applicant_employer_employment_type'] ?? ''),
          'state' => $payload['applicant_state'] ?? '',
          'first_name' => $payload['applicant_first_name'] ?? '',
          'last_name' => $payload['applicant_last_name'] ?? '',
          'ssn' => $this->formatSSN($payload['applicant_ssn'] ?? ''),
          'address' => $payload['applicant_address'] ?? '',
          'city' => $payload['applicant_city'] ?? '',
          'zip_code' => $payload['applicant_zip'] ?? '',
          'date_of_birth' => $this->formatDateOfBirth($payload['applicant_dob'] ?? ''),
          'co_applicant' => $coApplicantData
        ]);
    }

    public function parseVehicle(array $payload): Vehicle
    {
        return Vehicle::fromArray([
          'vin' => $payload['vehicle_vin'] ?? '',
          'year' => (int)($payload['vehicle_year'] ?? 0),
          'make' => $payload['vehicle_make'] ?? '',
          'model' => $payload['vehicle_model'] ?? '',
          'mileage' => (int)($payload['vehicle_miles'] ?? 0),
          'loan_amount' => (float)($payload['data_requested_amt'] ?? 0),
          'vehicle_value' => $this->parseVehicleValue($payload),
          'condition' => $this->mapVehicleCondition($payload['vehicle_condition'] ?? 'clean')
        ]);
    }

    public function extractBureauPreference(array $payload): string
    {
        // Check if there's a specific bureau preference in the payload
        $activeBureau = $payload['bureau_primary_active_bureau'] ?? '';

        if (!empty($activeBureau)) {
            return strtolower($activeBureau);
        }

        // Default to Experian if no preference specified
        return 'experian';
    }

    private function parseIncome(string $income): int
    {
        return (int)str_replace([',', '$'], '', $income);
    }

    private function parseDebt(string $debt): ?int
    {
        $cleanDebt = (int)str_replace([',', '$'], '', $debt);
        return $cleanDebt > 0 ? $cleanDebt : null;
    }

    private function mapEmploymentType(string $type): string
    {
        return match (strtoupper($type)) {
            'EMPLOYED' => 'W2',
            'SELF_EMPLOYED', 'SELF-EMPLOYED' => 'self_employed',
            'CONTRACT', '1099' => '1099',
            default => 'other'
        };
    }

    private function formatSSN(string $ssn): string
    {
        return str_replace(['-', ' '], '', $ssn);
    }

    private function formatDateOfBirth(string $dob): string
    {
        // Convert from various formats to YYYY-MM-DD
        if (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $dob)) {
            return $dob; // Already in correct format
        }

        if (preg_match('/(\d{2})\/(\d{2})\/(\d{4})/', $dob, $matches)) {
            return $matches[3] . '-' . $matches[1] . '-' . $matches[2];
        }

        return $dob;
    }

    private function parseVehicleValue(array $payload): ?float
    {
        // Try multiple value fields from the payload
        $valueFields = [
          'vehicle_retail_value',
          'vehicle_adjustedcleanretail',
          'vehicle_basecleanretail',
          'vehicle_trade_value',
          'vehicle_adjustedcleantrade'
        ];

        foreach ($valueFields as $field) {
            if (!empty($payload[$field])) {
                return (float)str_replace([',', '$'], '', $payload[$field]);
            }
        }

        return null;
    }

    private function mapVehicleCondition(string $condition): string
    {
        return match (strtolower($condition)) {
            'excellent' => 'excellent',
            'good', 'clean' => 'good',
            'fair', 'average' => 'fair',
            'poor', 'rough' => 'poor',
            default => 'good'
        };
    }
}
