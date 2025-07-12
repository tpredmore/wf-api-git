<?php

declare(strict_types=1);

namespace WF\API\Automation\Providers;

use WF\API\Automation\Exceptions\ValuationException;
use Curl;
use Log;

class KelleyBlueBookProvider extends AbstractValuationProvider
{

    /**
     * @throws ValuationException
     */
    protected function makeValuationRequest(string $vin, int $mileage, string $zipCode, string $condition): array
    {
        try {
            // For now, return mock data until we have real API credentials
            Log::warn("KBB Provider using mock data - awaiting API credentials");

            // This is what the real implementation would look like:
            /*
            $queryParams = http_build_query([
                'vin' => $vin,
                'mileage' => $mileage,
                'zipcode' => $zipCode,
                'condition' => $condition,
                'valuetype' => 'retail' // or 'trade-in', 'private-party'
            ]);

            $headers = [
                'Authorization: Bearer ' . $this->config['api_key'],
                'Content-Type: application/json',
                'Accept: application/json'
            ];

            $response = Curl::get(
                $this->config['endpoint'] . '/vehicle-values',
                $queryParams,
                $headers,
                null,
                true
            );

            if (is_array($response) && isset($response['http_code'])) {
                throw new ValuationException(
                    'KBB API error (HTTP ' . $response['http_code'] . '): ' . $response['response']
                );
            }

            return json_decode($response, true);
            */

            // Mock response for testing
            return [
              'values' => [
                'retail' => 25000,
                'tradein' => 22000,
                'privateparty' => 23500
              ],
              'vehicle' => [
                'year' => 2020,
                'make' => 'Toyota',
                'model' => 'Camry',
                'trim' => 'LE'
              ]
            ];

        } catch (\Exception $e) {
            throw new ValuationException('KBB API request failed: ' . $e->getMessage(), 0, $e);
        }
    }

    protected function parseValuationResponse(array $response): array
    {
        return [
          'value' => (float)($response['values']['retail'] ?? 0),
          'trade_in' => (float)($response['values']['tradein'] ?? 0),
          'private_party' => (float)($response['values']['privateparty'] ?? 0),
          'source' => 'Kelley Blue Book',
          'success' => true,
          'vehicle_details' => [
            'year' => $response['vehicle']['year'] ?? null,
            'make' => $response['vehicle']['make'] ?? null,
            'model' => $response['vehicle']['model'] ?? null,
            'trim' => $response['vehicle']['trim'] ?? null,
          ]
        ];
    }

    public function getProviderName(): string
    {
        return 'Kelley Blue Book';
    }

    public function isAvailable(): bool
    {
        // For now, return false since we don't have real credentials
        return false;

        // When ready:
        // return !empty($this->config['api_key']) && !empty($this->config['endpoint']);
    }
}
