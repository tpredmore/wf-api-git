<?php

declare(strict_types=1);

namespace WF\API\Automation\Http\Controllers;

use WF\API\Automation\AutomationService;
use WF\API\Automation\Models\Applicant;
use WF\API\Automation\Models\Vehicle;
use WF\API\Automation\Exceptions\AutomationException;
use WF\API\Automation\Exceptions\ValidationException;
use Log;

class PreQualController
{
    public function __construct(
      private AutomationService $automationService,
      private Log $logger
    ) {}

    public function handlePreQual( $request): array {
        try {
            // Get request data
            $requestData = $request->data;

            // Process pre-qualification
            $result = $this->automationService->processPreQual($requestData);

            return [
              'success' => true,
              'data' => $result->toArray(),
              'error' => ''
            ];

        } catch (ValidationException $e) {
            return [
              'success' => false,
              'data' => '',
              'error' => $e->getMessage()
            ];
        } catch (AutomationException $e) {
            $this->logger->error('PreQual processing failed', [
              'error' => $e->getMessage(),
              'context' => $e->getContext()
            ]);
            return [
              'success' => false,
              'data' => '',
              'error' => 'Processing failed - ' . $e->getMessage()
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Unexpected error in PreQual', [
              'error' => $e->getMessage(),
              'trace' => $e->getTraceAsString()
            ]);
            return [
              'success' => false,
              'data' => '',
              'error' => 'Internal server error - ' . $e->getMessage()
            ];
        }
    }
}

