<?php

declare(strict_types=1);

namespace WF\API\Automation\Providers;

use WF\API\Automation\Exceptions\ValuationException;

class KelleyBlueBookProvider extends AbstractValuationProvider
{

    /**
     * @throws \WF\API\Automation\Exceptions\ValuationException
     */
    protected function makeValuationRequest(string $vin, int $mileage, string $zipCode, string $condition): array
    {
        try {
            $response = $this->httpClient->get(
              $this->config['endpoint'] . '/vehicle-values',
              [
                'vin' => $vin,
                'mileage' => $mileage,
                'zipcode' => $zipCode,
                'condition' => $condition,
                'valuetype' => 'retail' // or 'trade-in', 'private-party'
              ],
              [
                'Authorization' => 'Bearer ' . $this->config['api_key'],
                'Content-Type' => 'application/json',
              ]
            );

            return json_decode((string) $response->getBody(), true);

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
}
