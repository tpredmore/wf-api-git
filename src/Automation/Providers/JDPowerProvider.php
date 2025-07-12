<?php

declare(strict_types=1);

namespace WF\API\Automation\Providers;

use WF\API\Automation\Exceptions\ValuationException;
use Curl;
use Log;

class JDPowerProvider extends AbstractValuationProvider
{

    /**
     * @throws ValuationException
     */
    protected function makeValuationRequest(string $vin, int $mileage, string $zipCode, string $condition): array
    {
        try {
            // For now, return mock data until we have real API credentials
            Log::warn("JD Power Provider using mock data - awaiting API credentials");

            // This is what the real implementation would look like:
            /*
            $queryParams = http_build_query([
                'vin' => $vin,
                'mileage' => $mileage,
                'zip' => $zipCode,
                'condition' => $condition,
            ]);

            $headers = [
                'Authorization: ApiKey ' . $this->config['api_key'],
                'Accept: application/json'
            ];

            $response = Curl::get(
                $this->config['endpoint'] . '/valuation',
                $queryParams,
                $headers,
                null,
                true
            );

            if (is_array($response) && isset($response['http_code'])) {
                throw new ValuationException(
                    'JD Power API error (HTTP ' . $response['http_code'] . '): ' . $response['response']
                );
            }

            return json_decode($response, true);
            */

            // Mock response for testing
            return [
              'valuation' => [
                'marketValue' => 24500,
                'tradeInValue' => 21500,
                'auctionValue' => 20000,
                'confidenceScore' => 0.85
              ],
              'vehicle' => [
                'year' => 2020,
                'make' => 'Toyota',
                'model' => 'Camry',
                'trim' => 'LE',
                'style' => '4-Door Sedan'
              ]
            ];

        } catch (\Exception $e) {
            throw new ValuationException('JD Power API request failed: ' . $e->getMessage(), 0, $e);
        }
    }

    protected function parseValuationResponse(array $response): array
    {
        $valuation = $response['valuation'] ?? [];

        return [
          'value' => (float)($valuation['marketValue'] ?? 0),
          'trade_in' => (float)($valuation['tradeInValue'] ?? 0),
          'auction_value' => (float)($valuation['auctionValue'] ?? 0),
          'source' => 'JD Power',
          'success' => true,
          'confidence_score' => $valuation['confidenceScore'] ?? null,
          'vehicle_details' => $response['vehicle'] ?? []
        ];
    }

    public function getProviderName(): string
    {
        return 'JD Power';
    }

    public function isAvailable(): bool
    {
        // For now, return false since we don't have real credentials
        return false;

        // When ready:
        // return !empty($this->config['api_key']) && !empty($this->config['endpoint']);
    }
}
