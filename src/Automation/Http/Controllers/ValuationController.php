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
      private ValuationProviderFactory $valuationFactory,
      private Log $logger
    ) {}

    /**
     * Handle VIN-based valuation (legacy compatible)
     */
    public function handleVinValuation(array $requestData, array $params): array
    {
        try {
            $nadaData = $requestData['nada'] ?? [];

            // Validate required fields
            $this->validateVinRequest($nadaData);

            $vin = trim(strtoupper($nadaData['VIN'] ?? $nadaData['vin']));
            $state = trim(strtoupper($nadaData['state']));
            $mileage = (int)trim($nadaData['mileage']);

            // Get NADA provider
            $provider = $this->valuationFactory->create('nada');
            if (!($provider instanceof NADAProvider)) {
                throw new ValuationException('NADA provider not available');
            }

            // Perform valuation
            $data = $provider->getValuationByVin($vin, $mileage, $state);

            return [
              'success' => true,
              'error' => '',
              'data' => $data
            ];

        } catch (ValidationException $e) {
            return [
              'success' => false,
              'error' => $e->getMessage(),
              'data' => []
            ];
        } catch (\Throwable $e) {
            $this->logger->error('VIN valuation failed', print_r([
              'error' => $e->getMessage(),
              'trace' => $e->getTraceAsString()
            ],true));

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
    public function handleYmmValuation(array $requestData, array $params): array
    {
        try {
            $nadaData = $requestData['nada'] ?? [];

            // Validate required fields
            $this->validateYmmRequest($nadaData);

            $year = (int)trim($nadaData['year']);
            $make = trim($nadaData['make']);
            $model = trim($nadaData['model']);
            $trim = trim($nadaData['trim'] ?? '');
            $state = trim(strtoupper($nadaData['state']));
            $mileage = (int)trim($nadaData['mileage']);

            // Get NADA provider
            $provider = $this->valuationFactory->create('nada');
            if (!($provider instanceof NADAProvider)) {
                throw new ValuationException('NADA provider not available');
            }

            // Perform valuation
            $data = $provider->getValuationByYMM($year, $make, $model, $trim, $state, $mileage);

            return [
              'success' => true,
              'error' => '',
              'data' => $data
            ];

        } catch (ValidationException $e) {
            return [
              'success' => false,
              'error' => $e->getMessage(),
              'data' => []
            ];
        } catch (\Throwable $e) {
            $this->logger->error('YMM valuation failed', print_r([
              'error' => $e->getMessage(),
              'trace' => $e->getTraceAsString()
            ],true));

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
    public function getValuation(array $requestData, array $params): array
    {
        try {
            $vin = $requestData['vin'] ?? '';
            $mileage = (int)($requestData['mileage'] ?? 0);
            $zipCode = $requestData['zip_code'] ?? '';
            $condition = $requestData['condition'] ?? 'good';
            $provider = $requestData['provider'] ?? 'nada';

            if (empty($vin)) {
                throw new ValidationException('VIN is required');
            }

            if ($mileage <= 0) {
                throw new ValidationException('Valid mileage is required');
            }

            // Get valuation
            $valuationProvider = $this->valuationFactory->create($provider);
            $result = $valuationProvider->getValuation($vin, $mileage, $zipCode, $condition);

            return [
              'success' => true,
              'data' => $result
            ];

        } catch (ValidationException $e) {
            return [
              'success' => false,
              'error' => $e->getMessage()
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Modern valuation failed', [
              'error' => $e->getMessage()
            ]);

            return [
              'success' => false,
              'error' => 'Valuation service error'
            ];
        }
    }

    /**
     * @throws \WF\API\Automation\Exceptions\ValidationException
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
