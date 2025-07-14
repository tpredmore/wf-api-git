<?php

declare(strict_types=1);

namespace WF\API\Automation\Providers;

use WF\API\Automation\Exceptions\ValuationException;
use GuzzleHttp\Exception\GuzzleException;

class JDPowerProvider extends AbstractValuationProvider
{

    /**
     * @throws \WF\API\Automation\Exceptions\ValuationException
     */
    protected function makeValuationRequest(string $vin, int $mileage, string $zipCode, string $condition): array
    {
        try {
            $response = $this->httpClient->get(
              $this->config['endpoint'] . '/valuation',
              [
                'vin' => $vin,
                'mileage' => $mileage,
                'zip' => $zipCode,
                'condition' => $condition,
              ],
              [
                'Authorization' => 'ApiKey ' . $this->config['api_key'],
                'Accept' => 'application/json',
              ]
            );

            return json_decode((string) $response->getBody(), true);

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
}
