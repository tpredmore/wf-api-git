<?php

declare(strict_types=1);

namespace WF\API\Automation\Providers;

use WF\API\Automation\Contracts\ValuationProviderInterface;
use Log;

abstract class AbstractValuationProvider implements ValuationProviderInterface
{
    protected array $config;

    public function __construct(
      array $config = []
    ) {
        $this->config = $config;
    }

    abstract protected function makeValuationRequest(string $vin, int $mileage, string $zipCode, string $condition): array;
    abstract protected function parseValuationResponse(array $response): array;

    public function getValuation(string $vin, int $mileage, string $zipCode, string $condition = 'good'): array
    {
        try {
            Log::info("Getting valuation from {$this->getProviderName()}",
              "VIN: " . substr($vin, 0, 8) . "..., Mileage: $mileage, Condition: $condition"
            );

            $response = $this->makeValuationRequest($vin, $mileage, $zipCode, $condition);
            $result = $this->parseValuationResponse($response);

            Log::info("Valuation successful from {$this->getProviderName()}",
              "Value: $" . number_format($result['value'] ?? 0, 2)
            );

            return $result;

        } catch (\Throwable $e) {
            Log::warn("Valuation failed for {$this->getProviderName()}: " . $e->getMessage());

            return [
              'value' => 0,
              'source' => $this->getProviderName(),
              'error' => $e->getMessage(),
              'success' => false
            ];
        }
    }

    public function isAvailable(): bool
    {
        return !empty($this->config['api_key']) && !empty($this->config['endpoint']);
    }
}
