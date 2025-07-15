<?php

return [
  'GET' => [
    'api/accounting/applications/{id}' => [
      '\WF\API\Accounting\Services\Applications',
      'get'
    ],

      // Health check for new services WIP
    'api/health' => [
      '\WF\API\Automation\Http\Controllers\HealthController',
      'check'
    ],

      // API documentation endpoint ?? Do we need something like this?
    'api/docs' => function($requestData, $params) {
        return [
          'success' => true,
          'data' => [
            'endpoints' => [
              'POST /api/automation/prequal' => 'Modern pre-qualification API',
              'POST /api/wildfire/prequal' => 'WildFire LOS application processing',
              'POST /api/wildfire/pull' => 'Direct bureau credit pull',
              'POST /api/automation/index' => 'Legacy automation handler',
              'GET /api/health' => 'Service health check'
            ]
          ]
        ];
    }
  ],

  'POST' => [
      // =====================================================
      // New PSR-4 Architecture Routes
      // =====================================================

    'api/accounting/index' => [
      '\WF\API\Accounting\ServiceRouter',
      'handler'
    ],

    'api/automation/index' => [
      '\WF\API\Automation\TestMe',
      'handler'
    ],

    'api/guardrail/index' => [
      '\WF\API\Guardrail\Evaluator',
      'handler'
    ],

      // Modern pre-qualification endpoint (JSON API)
    'api/automation/prequal' => [
      '\WF\API\Automation\Http\Controllers\PreQualController',
      'handlePreQual'
    ],

      // WildFire LOS Integration endpoints
    'api/wildfire/prequal' => [
      '\WF\API\Automation\Http\Controllers\WildFirePreQualController',
      'handleWildFirePreQual'
    ],

    'api/wildfire/pull' => [
      '\WF\API\Automation\Http\Controllers\WildFirePreQualController',
      'handleLegacyPull'
    ],

    'api/valuation/vin' => [
      '\WF\API\Automation\Http\Controllers\ValuationController',
      'handleVinValuation'
    ],

    'api/valuation/ymm' => [
      '\WF\API\Automation\Http\Controllers\ValuationController',
      'handleYmmValuation'
    ],

      // Valuation endpoint
    'api/automation/valuation' => [
      '\WF\API\Automation\Http\Controllers\ValuationController',
      'getValuation'
    ],

      // Rate finding endpoint
    'api/automation/rates' => [
      '\WF\API\Automation\Http\Controllers\RateController',
      'findRates'
    ]
  ]
];

