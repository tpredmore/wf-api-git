<?php

declare(strict_types=1);

namespace WF\API\Automation\Http\Controllers;

use WF\API\Automation\Providers\NADAProvider;
use WF\API\Automation\Factories\ValuationProviderFactory;
use WF\API\Automation\Exceptions\ValuationException;
use WF\API\Automation\Exceptions\ValidationException;
use Log;

class ValuationController
{
    public function __construct(
      private ValuationProviderFactory $valuationFactory
    ) { }

    /**
     * Handle VIN-based valuation (legacy compatible)
     */
    public function handleVinValuation($requestData, array $params): array
    {
        try {
            // Handle both object and array request formats
            if (is_object($requestData) && property_exists($requestData, 'data')) {
                $data = json_decode($requestData->data, true);
            } else {
                $data = $requestData;
            }

            $nadaData = $data['nada'] ?? [];

            // Validate required fields
            $this->validateVinRequest($nadaData);

            $vin = trim(strtoupper($nadaData['VIN'] ?? $nadaData['vin']));
            $state = trim(strtoupper($nadaData['state']));
            $mileage = (int)trim($nadaData['mileage']);

            Log::info("Processing VIN valuation request", [
              'vin' => substr($vin, 0, 8) . '...',
              'state' => $state,
              'mileage' => $mileage
            ]);

            // Get NADA provider
            $provider = $this->valuationFactory->create('nada');
            if (!($provider instanceof NADAProvider)) {
                throw new ValuationException('NADA provider not available');
            }

            // Perform valuation
            $data = $provider->getValuationByVin($vin, $mileage, $state);

            Log::info("VIN valuation successful", json_encode([
              'retail_value' => $data['vehicle_retail_value'] ?? 0,
              'trade_value' => $data['vehicle_trade_value'] ?? 0
            ]));

            return [
              'success' => true,
              'error' => '',
              'data' => $data
            ];

        } catch (ValidationException $e) {
            Log::warn("VIN valuation validation failed: " . $e->getMessage());
            return [
              'success' => false,
              'error' => $e->getMessage(),
              'data' => []
            ];
        } catch (\Throwable $e) {
            Log::error("VIN valuation failed: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            return [
              'success' => false,
              'error' => 'Internal server error',
              'data' => []
            ];
        }
    }

    /**
     * Handle Year/Make/Model valuation (legacy compatible)
     */
    public function handleYmmValuation($requestData, array $params): array
    {
        try {
            // Handle both object and array request formats
            if (is_object($requestData) && property_exists($requestData, 'data')) {
                $data = json_decode($requestData->data, true);
            } else {
                $data = $requestData;
            }

            $nadaData = $data['nada'] ?? [];

            // Validate required fields
            $this->validateYmmRequest($nadaData);

            $year = (int)trim($nadaData['year']);
            $make = trim($nadaData['make']);
            $model = trim($nadaData['model']);
            $trim = trim($nadaData['trim'] ?? '');
            $state = trim(strtoupper($nadaData['state']));
            $mileage = (int)trim($nadaData['mileage']);

            Log::info("Processing YMM valuation request", [
              'year' => $year,
              'make' => $make,
              'model' => $model,
              'trim' => $trim,
              'state' => $state,
              'mileage' => $mileage
            ]);

            // Get NADA provider
            $provider = $this->valuationFactory->create('nada');
            if (!($provider instanceof NADAProvider)) {
                throw new ValuationException('NADA provider not available');
            }

            // Perform valuation
            $data = $provider->getValuationByYMM($year, $make, $model, $trim, $state, $mileage);

            Log::info("YMM valuation successful", [
              'retail_value' => $data['vehicle_retail_value'] ?? 0,
              'trade_value' => $data['vehicle_trade_value'] ?? 0
            ]);

            return [
              'success' => true,
              'error' => '',
              'data' => $data
            ];

        } catch (ValidationException $e) {
            Log::warn("YMM valuation validation failed: " . $e->getMessage());
            return [
              'success' => false,
              'error' => $e->getMessage(),
              'data' => []
            ];
        } catch (\Throwable $e) {
            Log::error("YMM valuation failed: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            return [
              'success' => false,
              'error' => 'Internal server error',
              'data' => []
            ];
        }
    }

    /**
     * Modern valuation endpoint
     */
    public function getValuation($requestData, array $params): array
    {
        try {
            // Handle both object and array request formats
            if (is_object($requestData) && property_exists($requestData, 'data')) {
                $data = json_decode($requestData->data, true);
            } else {
                $data = $requestData;
            }

            $vin = $data['vin'] ?? '';
            $mileage = (int)($data['mileage'] ?? 0);
            $zipCode = $data['zip_code'] ?? '';
            $condition = $data['condition'] ?? 'good';
            $provider = $data['provider'] ?? 'nada';

            if (empty($vin)) {
                throw new ValidationException('VIN is required');
            }

            if ($mileage <= 0) {
                throw new ValidationException('Valid mileage is required');
            }

            Log::info("Processing modern valuation request", json_encode([
              'vin' => substr($vin, 0, 8) . '...',
              'mileage' => $mileage,
              'provider' => $provider
            ]));

            // Get valuation
            $valuationProvider = $this->valuationFactory->create($provider);
            $result = $valuationProvider->getValuation($vin, $mileage, $zipCode, $condition);

            Log::info("Modern valuation successful", json_encode([
              'provider' => $provider,
              'value' => $result['value'] ?? 0
            ]));

            return [
              'success' => true,
              'data' => $result,
              'error' => ''
            ];

        } catch (ValidationException $e) {
            Log::warn("Modern valuation validation failed: " . $e->getMessage());
            return [
              'success' => false,
              'error' => $e->getMessage(),
              'data' => []
            ];
        } catch (\Throwable $e) {
            Log::error("Modern valuation failed: " . $e->getMessage());
            return [
              'success' => false,
              'error' => 'Valuation service error',
              'data' => []
            ];
        }
    }

    /**
     * @throws ValidationException
     */
    private function validateVinRequest(array $data): void
    {
        if (!isset($data['VIN']) && !isset($data['vin'])) {
            throw new ValidationException('NADA Error: missing VIN');
        }

        if (!isset($data['state'])) {
            throw new ValidationException('NADA Error: missing state');
        }

        if (!isset($data['mileage'])) {
            throw new ValidationException('NADA Error: missing mileage');
        }

        $vin = trim(strtoupper($data['VIN'] ?? $data['vin']));
        if (strlen($vin) !== 17) {
            throw new ValidationException('NADA Error: invalid VIN');
        }

        if (!is_numeric($data['mileage'])) {
            throw new ValidationException('NADA Error: mileage must be a numeric value');
        }
    }

    /**
     * @throws ValidationException
     */
    private function validateYmmRequest(array $data): void
    {
        if (!isset($data['year'])) {
            throw new ValidationException('NADA Error: missing year');
        }

        if (!isset($data['make'])) {
            throw new ValidationException('NADA Error: missing make');
        }

        if (!isset($data['model'])) {
            throw new ValidationException('NADA Error: missing model');
        }

        if (!isset($data['state'])) {
            throw new ValidationException('NADA Error: missing state');
        }

        if (!isset($data['mileage'])) {
            throw new ValidationException('NADA Error: missing mileage');
        }

        if (!is_numeric($data['mileage'])) {
            throw new ValidationException('NADA Error: mileage must be a numeric value');
        }
    }
}
