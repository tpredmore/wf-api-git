<?php

declare(strict_types=1);

namespace WF\API\Automation\Models;

use WF\API\Automation\Exceptions\ValidationException;

class Vehicle
{

    /**
     * @throws \WF\API\Automation\Exceptions\ValidationException
     */
    public function __construct(
      public string $vin,
      public int $year,
      public string $make,
      public string $model,
      public int $mileage,
      public float $loanAmount,
      public ?float $vehicleValue = null,
      public string $condition = 'good'
    ) {
        $this->validate();
    }

    public static function fromArray(array $data): self
    {
        return new self(
          vin: $data['vin'] ?? '',
          year: (int)($data['year'] ?? 0),
          make: $data['make'] ?? '',
          model: $data['model'] ?? '',
          mileage: (int)($data['mileage'] ?? 0),
          loanAmount: (float)($data['loan_amount'] ?? 0),
          vehicleValue: isset($data['vehicle_value']) ? (float)$data['vehicle_value'] : null,
          condition: $data['condition'] ?? 'good'
        );
    }

    public function toArray(): array
    {
        return [
          'vin' => $this->vin,
          'year' => $this->year,
          'make' => $this->make,
          'model' => $this->model,
          'mileage' => $this->mileage,
          'loan_amount' => $this->loanAmount,
          'vehicle_value' => $this->vehicleValue,
          'condition' => $this->condition,
          'ltv' => $this->calculateLTV(),
          'age_in_years' => $this->getAgeInYears()
        ];
    }

    /**
     * @throws \WF\API\Automation\Exceptions\ValidationException
     */
    private function validate(): void
    {
        if (strlen($this->vin) !== 17) {
            throw new ValidationException('VIN must be exactly 17 characters');
        }

        if ($this->year < 1980 || $this->year > ((int)date('Y') + 1)) {
            throw new ValidationException('Invalid vehicle year');
        }

        if ($this->loanAmount <= 0) {
            throw new ValidationException('Loan amount must be greater than 0');
        }

        if ($this->mileage < 0) {
            throw new ValidationException('Mileage cannot be negative');
        }

        if (!in_array($this->condition, ['excellent', 'good', 'fair', 'poor'])) {
            throw new ValidationException('Invalid vehicle condition');
        }
    }

    public function calculateLTV(): float
    {
        if ($this->vehicleValue === null || $this->vehicleValue <= 0) {
            return 0.0;
        }

        return round($this->loanAmount / $this->vehicleValue, 4);
    }

    public function getAgeInYears(): int
    {
        return (int)date('Y') - $this->year;
    }

    public function isHighMileage(): bool
    {
        $avgMileagePerYear = 12000;
        $expectedMileage = $this->getAgeInYears() * $avgMileagePerYear;
        return $this->mileage > ($expectedMileage * 1.5);
    }

    public function requiresValuation(): bool
    {
        return $this->vehicleValue === null || $this->vehicleValue <= 0;
    }
}
