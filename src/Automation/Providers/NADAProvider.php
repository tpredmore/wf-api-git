<?php

declare(strict_types=1);

namespace WF\API\Automation\Providers;

use WF\API\Automation\Exceptions\ValuationException;
use Curl;
use Log;

class NADAProvider extends AbstractValuationProvider
{

    /**
     * @throws ValuationException
     */
    protected function makeValuationRequest(string $vin, int $mileage, string $zipCode, string $condition): array
    {
        try {
            // If VIN is provided and valid, use VIN lookup
            if (!empty($vin) && strlen($vin) === 17) {
                // Convert zip code to state for NADA
                $state = $this->getStateFromZip($zipCode);
                return $this->getValuationByVin($vin, $mileage, $state);
            }

            throw new ValuationException('VIN required for NADA valuation');

        } catch (\Exception $e) {
            throw new ValuationException('NADA API request failed: ' . $e->getMessage(), 0, $e);
        }
    }

    protected function parseValuationResponse(array $response): array
    {
        return [
          'value' => (float)($response['vehicle_retail_value'] ?? 0),
          'trade_in' => (float)($response['vehicle_trade_value'] ?? 0),
          'loan_value' => (float)($response['vehicle_trade_value'] ?? 0),
          'source' => 'NADA',
          'success' => true,
          'vehicle_details' => [
            'year' => $response['vehicle_year'] ?? null,
            'make' => $response['vehicle_make'] ?? null,
            'model' => $response['vehicle_model'] ?? null,
            'trim' => $response['vehicle_trim'] ?? null,
            'msrp' => $response['vehicle_msrp'] ?? null,
            'curb_weight' => $response['vehicle_curb_weight'] ?? null,
            'doors' => $response['vehicle_doors'] ?? null,
            'drive_train' => $response['vehicle_drive_train'] ?? null,
            'transmission' => $response['vehicle_transmission'] ?? null,
            'engine_config' => $response['vehicle_engine_config'] ?? null
          ],
          'raw_data' => $response
        ];
    }

    public function getProviderName(): string
    {
        return 'NADA';
    }

    /**
     * Get valuation by VIN (legacy compatible)
     *
     * @throws ValuationException
     */
    public function getValuationByVin(string $vin, int $mileage, string $state): array
    {
        $vin = trim(strtoupper($vin));
        $state = trim(strtoupper($state));

        if (strlen($vin) !== 17) {
            throw new ValuationException('Invalid VIN');
        }

        if (!$this->isValidState($state)) {
            throw new ValuationException('Invalid state');
        }

        if (!is_numeric($mileage)) {
            throw new ValuationException('Mileage must be a numeric value');
        }

        try {
            // Step 1: Get vehicle info by VIN
            $vehicleData = $this->fetchVehiclesByVin($vin);

            // Step 2: Get region ID by state
            $regionId = $this->getRegionIdByState($state);

            // Step 3: Get valuation data
            $valuationData = $this->fetchValuationData($vehicleData['ucgvehicleid'], $regionId, $mileage);

            // Step 4: Get accessory data
            $accessoryData = $this->fetchAccessoryData($vehicleData['ucgvehicleid'], $regionId, $mileage, $vin);

            // Combine all data
            return array_merge($vehicleData, $valuationData, $accessoryData);

        } catch (\Throwable $e) {
            Log::error('NADA VIN lookup failed', [
              'vin' => substr($vin, 0, 8) . '...',
              'error' => $e->getMessage()
            ]);
            throw new ValuationException('NADA Error: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get valuation by Year/Make/Model (legacy compatible)
     *
     * @throws ValuationException
     */
    public function getValuationByYMM(int $year, string $make, string $model, ?string $trim, string $state, int $mileage): array
    {
        $state = trim(strtoupper($state));

        if ($year > ((int)date('Y') + 1) || $year < 1970) {
            throw new ValuationException('Year must be between 1970 and ' . ((int)date('Y') + 1));
        }

        if (empty($make)) {
            throw new ValuationException('Make cannot be empty');
        }

        if (empty($model)) {
            throw new ValuationException('Model cannot be empty');
        }

        if (!$this->isValidState($state)) {
            throw new ValuationException('Invalid state');
        }

        if (!is_numeric($mileage)) {
            throw new ValuationException('Mileage must be a numeric value');
        }

        try {
            // Step 1: Get region ID by state
            $regionId = $this->getRegionIdByState($state);

            // Step 2: Get available trims/bodies
            $trimData = $this->fetchTrimsForYMM($year, $make, $model);

            // Step 3: Find matching trim or use first available
            $selectedTrim = $this->selectBestTrim($trimData, $trim);

            // Step 4: Get valuation data
            $valuationData = $this->fetchValuationData($selectedTrim['ucgvehicleid'], $regionId, $mileage);

            // Step 5: Get accessory data
            $accessoryData = $this->fetchAccessoryData($selectedTrim['ucgvehicleid'], $regionId, $mileage);

            // Build response
            $vehicleData = [
              'vehicle_year' => $year,
              'vehicle_make' => $make,
              'vehicle_model' => $model,
              'vehicle_trim' => $selectedTrim['body'],
              'vehicle_vin_decoded' => 'false',
              'vehicle_trims' => json_encode($trimData)
            ];

            if ($trim && strtolower($trim) !== strtolower($selectedTrim['body'])) {
                $vehicleData['vehicle_decode_exact_match'] = false;
            }

            return array_merge($vehicleData, $valuationData, $accessoryData);

        } catch (\Throwable $e) {
            Log::error('NADA YMM lookup failed', [
              'ymm' => "{$year} {$make} {$model}",
              'error' => $e->getMessage()
            ]);
            throw new ValuationException('NADA Error: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @throws ValuationException
     */
    private function fetchVehiclesByVin(string $vin): array
    {
        $queryParams = http_build_query([
          'period' => 0,
          'vin' => $vin
        ]);

        $headers = [
          'api-key: ' . $this->config['api_key'],
          'Accept: application/json'
        ];

        $response = Curl::get(
          $this->config['endpoint'] . '/vehiclesByVin',
          $queryParams,
          $headers,
          null,
          true
        );

        // Handle response
        if (is_array($response) && isset($response['http_code'])) {
            throw new ValuationException(
              'NADA API error (HTTP ' . $response['http_code'] . '): ' . $response['response']
            );
        }

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ValuationException('Invalid JSON response from NADA');
        }

        if (isset($data['error'])) {
            throw new ValuationException($data['error']);
        }

        if (empty($data['result'])) {
            throw new ValuationException('No vehicle found for VIN');
        }

        // Handle multiple results
        $vehicle = $data['result'][0];

        return [
          'vehicle_year' => $vehicle['modelyear'],
          'vehicle_make' => $vehicle['make'],
          'vehicle_model' => $vehicle['model'],
          'vehicle_trim' => $vehicle['body'],
          'vehicle_vin_decoded' => 'true',
          'ucgvehicleid' => $vehicle['ucgvehicleid']
        ];
    }

    /**
     * @throws ValuationException
     */
    private function getRegionIdByState(string $state): int
    {
        $queryParams = http_build_query([
          'statecode' => $state
        ]);

        $headers = [
          'api-key: ' . $this->config['api_key'],
          'Accept: application/json'
        ];

        $response = Curl::get(
          $this->config['endpoint'] . '/regionIdByStateCode',
          $queryParams,
          $headers,
          null,
          true
        );

        if (is_array($response) && isset($response['http_code'])) {
            throw new ValuationException(
              'NADA API error (HTTP ' . $response['http_code'] . '): ' . $response['response']
            );
        }

        $data = json_decode($response, true);

        if (isset($data['error'])) {
            throw new ValuationException($data['error']);
        }

        if (empty($data['result'][0]['regionid'])) {
            throw new ValuationException('Region ID not found for state');
        }

        return (int)$data['result'][0]['regionid'];
    }

    /**
     * @throws ValuationException
     */
    private function fetchValuationData(string $ucgvehicleid, int $regionId, int $mileage): array
    {
        $queryParams = http_build_query([
          'period' => 0,
          'ucgvehicleid' => $ucgvehicleid,
          'region' => $regionId,
          'mileage' => $mileage
        ]);

        $headers = [
          'api-key: ' . $this->config['api_key'],
          'Accept: application/json'
        ];

        $response = Curl::get(
          $this->config['endpoint'] . '/vehicleAndValueByVehicleId',
          $queryParams,
          $headers,
          null,
          true
        );

        if (is_array($response) && isset($response['http_code'])) {
            throw new ValuationException(
              'NADA API error (HTTP ' . $response['http_code'] . '): ' . $response['response']
            );
        }

        $data = json_decode($response, true);

        if (isset($data['error'])) {
            throw new ValuationException($data['error']);
        }

        $vals = $data['result'][0];

        return [
          'vehicle_msrp' => $vals['basemsrp'],
          'vehicle_retail_value' => $vals['adjustedcleanretail'],
          'vehicle_trade_value' => $vals['adjustedcleantrade'],
          'vehicle_curb_weight' => $vals['curbweight'],
          'vehicle_doors' => $vals['doors'],
          'vehicle_drive_train' => $vals['drivetype'],
          'vehicle_transmission' => $vals['transmission'],
          'vehicle_engine_config' => $vals['liters'] . 'L ' . $vals['engineconfiguration'] . '-' . $vals['cylinders'] . ' (' . $vals['inductiontype'] . ')',
          'vehicle_basecleantrade' => $vals['basecleantrade'],
          'vehicle_baseaveragetrade' => $vals['baseaveragetrade'],
          'vehicle_baseroughtrade' => $vals['baseroughtrade'],
          'vehicle_basecleanretail' => $vals['basecleanretail'],
          'vehicle_adjustedcleantrade' => $vals['adjustedcleantrade'],
          'vehicle_adjustedaveragetrade' => $vals['adjustedaveragetrade'],
          'vehicle_adjustedroughtrade' => $vals['adjustedroughtrade'],
          'vehicle_adjustedcleanretail' => $vals['adjustedcleanretail'],
          'vehicle_miles' => $mileage ?: $vals['averagemileage'],
          'vehicle_miles_read_date' => date('Y-m-d'),
          'vehicle_mileageadjustment' => $vals['mileageadjustment']
        ];
    }

    /**
     * @throws ValuationException
     */
    private function fetchAccessoryData(string $ucgvehicleid, int $regionId, int $mileage, ?string $vin = null): array
    {
        $endpoint = $vin ? '/accessoryDataByVinAndVehicleId' : '/accessoryDataByVehicleId';

        $query = [
          'period' => 0,
          'ucgvehicleid' => $ucgvehicleid,
          'region' => $regionId,
          'mileage' => $mileage
        ];

        if ($vin) {
            $query['vin'] = $vin;
        }

        $queryParams = http_build_query($query);

        $headers = [
          'api-key: ' . $this->config['api_key'],
          'Accept: application/json'
        ];

        $response = Curl::get(
          $this->config['endpoint'] . $endpoint,
          $queryParams,
          $headers,
          null,
          true
        );

        if (is_array($response) && isset($response['http_code'])) {
            throw new ValuationException(
              'NADA API error (HTTP ' . $response['http_code'] . '): ' . $response['response']
            );
        }

        $data = json_decode($response, true);

        if (isset($data['error'])) {
            throw new ValuationException($data['error']);
        }

        $accessories = $data['result'];
        $result = [
          'vehicle_options_json' => json_encode($accessories),
          'vehicle_updated_options_json' => json_encode($accessories)
        ];

        // Add individual option data (legacy format)
        foreach ($accessories as $opt) {
            $result['opt-added-' . $opt['acccode']] = $opt['isadded'];
            $result['opt-desc-' . $opt['acccode']] = $opt['accdesc'];
            $result['opt-included-' . $opt['acccode']] = $opt['isincluded'];
            $result['opt-retail-' . $opt['acccode']] = $opt['retail'];
            $result['opt-tradein-' . $opt['acccode']] = $opt['tradein'];
        }

        return $result;
    }

    /**
     * @throws ValuationException
     */
    private function fetchTrimsForYMM(int $year, string $make, string $model): array
    {
        $queryParams = http_build_query([
          'period' => 0,
          'vehicletype' => 'UsedCar',
          'modelyear' => $year,
          'make' => $make,
          'model' => $model
        ]);

        $headers = [
          'api-key: ' . $this->config['api_key'],
          'Accept: application/json'
        ];

        $response = Curl::get(
          $this->config['endpoint'] . '/bodies',
          $queryParams,
          $headers,
          null,
          true
        );

        if (is_array($response) && isset($response['http_code'])) {
            throw new ValuationException(
              'NADA API error (HTTP ' . $response['http_code'] . '): ' . $response['response']
            );
        }

        $data = json_decode($response, true);

        if (isset($data['error'])) {
            throw new ValuationException($data['error']);
        }

        return $data['result'] ?? [];
    }

    /**
     * @throws ValuationException
     */
    private function selectBestTrim(array $trimData, ?string $requestedTrim): array
    {
        if (empty($trimData)) {
            throw new ValuationException('No trims available for this vehicle');
        }

        // If no trim requested, use first available
        if (empty($requestedTrim)) {
            return $trimData[0];
        }

        // Look for exact match
        $searchTrim = strtolower(trim($requestedTrim));
        foreach ($trimData as $trim) {
            if (strtolower($trim['body']) === $searchTrim) {
                return $trim;
            }
        }

        // No exact match, return first available
        return $trimData[0];
    }

    private function isValidState(string $state): bool
    {
        $validStates = [
          'AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'FL', 'GA',
          'HI', 'ID', 'IL', 'IN', 'IA', 'KS', 'KY', 'LA', 'ME', 'MD',
          'MA', 'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV', 'NH', 'NJ',
          'NM', 'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PA', 'RI', 'SC',
          'SD', 'TN', 'TX', 'UT', 'VT', 'VA', 'WA', 'WV', 'WI', 'WY',
          'DC'
        ];

        return in_array(strtoupper($state), $validStates);
    }

    /**
     * Simple zip to state mapping (you'd want a more complete solution)
     */
    private function getStateFromZip(string $zipCode): string
    {
        // This is a simplified example - you'd want a complete mapping
        $firstDigit = substr($zipCode, 0, 1);

        return match($firstDigit) {
            '0' => 'MA', // Northeast
            '1' => 'NY',
            '2' => 'DC',
            '3' => 'GA',
            '4' => 'IN',
            '5' => 'IA',
            '6' => 'IL',
            '7' => 'TX',
            '8' => 'CO',
            '9' => 'CA',
            default => 'TX' // Default
        };
    }
}
