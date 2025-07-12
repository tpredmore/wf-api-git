<?php

declare(strict_types=1);

namespace WF\API\Automation\Models;

use WF\API\Automation\Exceptions\ValidationException;

/**
 * Immutable value object representing an applicant.
 */
class Applicant
{

    /**
     * @throws \WF\API\Automation\Exceptions\ValidationException
     */
    public function __construct(
      public int $monthlyIncome,
      public ?int $monthlyDebt,
      public string $employmentType,
      public string $state,
      public string $firstName,
      public string $lastName,
      public string $ssn,
      public string $address,
      public string $city,
      public string $zipCode,
      public string $dateOfBirth,
      public ?array $coApplicant = null
    ) {
        $this->validate();
    }

    /**
     * @throws \WF\API\Automation\Exceptions\ValidationException
     */
    public static function fromArray(array $data): self
    {
        return new self(
          monthlyIncome: (int)($data['monthly_income'] ?? 0),
          monthlyDebt: isset($data['monthly_debt']) ? (int)$data['monthly_debt'] : null,
          employmentType: $data['employment_type'] ?? '',
          state: $data['state'] ?? '',
          firstName: $data['first_name'] ?? '',
          lastName: $data['last_name'] ?? '',
          ssn: $data['ssn'] ?? '',
          address: $data['address'] ?? '',
          city: $data['city'] ?? '',
          zipCode: $data['zip_code'] ?? '',
          dateOfBirth: $data['date_of_birth'] ?? '',
          coApplicant: $data['co_applicant'] ?? null
        );
    }

    public function toArray(): array
    {
        return [
          'monthly_income' => $this->monthlyIncome,
          'monthly_debt' => $this->monthlyDebt,
          'employment_type' => $this->employmentType,
          'state' => $this->state,
          'first_name' => $this->firstName,
          'last_name' => $this->lastName,
          'ssn' => $this->ssn,
          'address' => $this->address,
          'city' => $this->city,
          'zip_code' => $this->zipCode,
          'date_of_birth' => $this->dateOfBirth,
          'co_applicant' => $this->coApplicant
        ];
    }

    /**
     * @throws \WF\API\Automation\Exceptions\ValidationException
     */
    private function validate(): void
    {
        if ($this->monthlyIncome < 0) {
            throw new ValidationException('Monthly income cannot be negative');
        }

        if (empty($this->state) || strlen($this->state) !== 2) {
            throw new ValidationException('State must be a valid 2-character code');
        }

        if (!in_array($this->employmentType, ['W2', 'self_employed', '1099', 'other'])) {
            throw new ValidationException('Invalid employment type');
        }

        if (!empty($this->ssn) && !$this->isValidSSN($this->ssn)) {
            throw new ValidationException('Invalid SSN format');
        }

        if (!empty($this->dateOfBirth) && !$this->isValidDateOfBirth($this->dateOfBirth)) {
            throw new ValidationException('Invalid date of birth format');
        }

        if (!empty($this->zipCode) && !$this->isValidZipCode($this->zipCode)) {
            throw new ValidationException('Invalid zip code format');
        }
    }

    public function hasCoApplicant(): bool
    {
        return !empty($this->coApplicant);
    }

    public function calculateDTI(?int $estimatedDebt = null): float
    {
        if ($this->monthlyIncome <= 0) {
            return 0.0;
        }

        $debt = $this->monthlyDebt ?? $estimatedDebt ?? 0;
        return $debt > 0 ? $debt / $this->monthlyIncome : 0.0;
    }

    public function getFullName(): string
    {
        return trim($this->firstName . ' ' . $this->lastName);
    }

    public function getFormattedSSN(): string
    {
        if (strlen($this->ssn) === 9) {
            return substr($this->ssn, 0, 3) . '-' . substr($this->ssn, 3, 2) . '-' . substr($this->ssn, 5, 4);
        }

        return $this->ssn;
    }

    public function getAge(): ?int
    {
        if (empty($this->dateOfBirth)) {
            return null;
        }

        try {
            $birthDate = new \DateTime($this->dateOfBirth);
            $today = new \DateTime();
            return $today->diff($birthDate)->y;
        } catch (\Exception) {
            return null;
        }
    }

    public function isMinor(): bool
    {
        $age = $this->getAge();
        return $age !== null && $age < 18;
    }

    public function getCoApplicantFullName(): ?string
    {
        if (!$this->hasCoApplicant()) {
            return null;
        }

        $firstName = $this->coApplicant['first_name'] ?? '';
        $lastName = $this->coApplicant['last_name'] ?? '';

        return trim($firstName . ' ' . $lastName) ?: null;
    }

    public function getCombinedMonthlyIncome(): int
    {
        $primaryIncome = $this->monthlyIncome;

        if ($this->hasCoApplicant()) {
            $coAppIncome = (int)($this->coApplicant['monthly_income'] ?? 0);
            return $primaryIncome + $coAppIncome;
        }

        return $primaryIncome;
    }

    public function isEmployed(): bool
    {
        return in_array($this->employmentType, ['W2', '1099', 'self_employed']);
    }

    public function isSelfEmployed(): bool
    {
        return $this->employmentType === 'self_employed';
    }

    public function getAddressString(): string
    {
        $parts = array_filter([
          $this->address,
          $this->city,
          $this->state,
          $this->zipCode
        ]);

        return implode(', ', $parts);
    }

    private function isValidSSN(string $ssn): bool
    {
        // Remove any formatting
        $cleanSSN = preg_replace('/[^0-9]/', '', $ssn);

        // Must be exactly 9 digits
        if (strlen($cleanSSN) !== 9) {
            return false;
        }

        // Cannot be all zeros or common invalid patterns
        $invalidPatterns = [
          '000000000',
          '111111111',
          '222222222',
          '333333333',
          '444444444',
          '555555555',
          '666666666',
          '777777777',
          '888888888',
          '999999999'
        ];

        return !in_array($cleanSSN, $invalidPatterns);
    }

    private function isValidDateOfBirth(string $dob): bool
    {
        try {
            $date = new \DateTime($dob);
            $today = new \DateTime();

            // Must be in the past
            if ($date >= $today) {
                return false;
            }

            // Must be realistic (not more than 120 years ago)
            $maxAge = new \DateTime('-120 years');
            if ($date <= $maxAge) {
                return false;
            }

            return true;
        } catch (\Exception) {
            return false;
        }
    }

    private function isValidZipCode(string $zipCode): bool
    {
        // US ZIP code patterns: 12345 or 12345-6789
        return preg_match('/^\d{5}(-\d{4})?$/', $zipCode) === 1;
    }
}