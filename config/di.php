<?php

declare(strict_types=1);

use DI\Container;
use Psr\Container\ContainerInterface;
use WF\API\Automation\Events\CreditReportPulledEvent;
use WF\API\Automation\Events\EventDispatcher;
use WF\API\Automation\Events\PreQualCompletedEvent;
use WF\API\Automation\Events\PreQualFailedEvent;
use WF\API\Automation\Listeners\MetricsCollectorListener;
use WF\API\Automation\Listeners\MLDataCollectorListener;
use WF\API\Automation\Services\CreditDataCollectionService;
use function DI\autowire;
use function DI\get;
use function DI\create;

use WF\API\Automation\AutomationService;
use WF\API\Automation\Services\PreQualEngine;
use WF\API\Automation\Services\RiskScorer;
use WF\API\Automation\Services\BureauCacheService;
use WF\API\Automation\Services\BureauRequestBuilder;
use WF\API\Automation\Factories\BureauClientFactory;
use WF\API\Automation\Factories\CreditParserFactory;
use WF\API\Automation\Factories\ValuationProviderFactory;
use WF\API\Automation\Repositories\LenderRepository;
use WF\API\Automation\Contracts\PreQualEngineInterface;
use WF\API\Automation\Contracts\RiskScorerInterface;
use WF\API\Automation\Clients\EquifaxClient;
use WF\API\Automation\Clients\ExperianClient;
use WF\API\Automation\Clients\TransUnionClient;
use WF\API\Automation\Providers\NADAProvider;
use WF\API\Automation\Providers\KelleyBlueBookProvider;
use WF\API\Automation\Providers\JDPowerProvider;
use WF\API\Automation\Adapters\ApplicationPayloadParser;
use WF\API\Automation\Formatters\WildFireBureauFormatter;
use WF\API\Automation\Http\Controllers\WildFirePreQualController;
use WF\API\Automation\Http\Controllers\PreQualController;
use WF\API\Automation\Http\Controllers\ValuationController;
use WF\API\Automation\Http\Controllers\HealthController;

function factory(Closure $param) {

}

return [
    // Services
  AutomationService::class => autowire()
    ->constructor(
      get(PreQualEngineInterface::class)
    ),

  PreQualEngineInterface::class => autowire(PreQualEngine::class)
    ->constructor(
      get(RiskScorerInterface::class),
      get(BureauClientFactory::class),
      get(CreditParserFactory::class),
      get(ValuationProviderFactory::class),
      get(BureauCacheService::class),
      get(CreditDataCollectionService::class),
      get(EventDispatcher::class)  // Add this
    ),

  RiskScorerInterface::class => autowire(RiskScorer::class)
    ->constructor(
      get(LenderRepository::class)
    ),

    // Factories
  BureauClientFactory::class => autowire(),
  CreditParserFactory::class => autowire(),
  ValuationProviderFactory::class => autowire(),

    // Services
  BureauCacheService::class => autowire(),
  BureauRequestBuilder::class => autowire(),

    // Repositories
  LenderRepository::class => autowire(),

    // Adapters and Formatters
  ApplicationPayloadParser::class => autowire(),
  WildFireBureauFormatter::class => autowire(),

    // Bureau Clients with configuration
  EquifaxClient::class => create()
    ->constructor([
      'client_id' => $_ENV['EQUIFAX_CLIENT_ID'] ?? '',
      'client_secret' => $_ENV['EQUIFAX_CLIENT_SECRET'] ?? '',
      'token_endpoint' => $_ENV['EQUIFAX_TOKEN_ENDPOINT'] ?? '',
      'report_endpoint' => $_ENV['EQUIFAX_REPORT_ENDPOINT'] ?? '',
      'member_number' => $_ENV['EQUIFAX_MEMBER_NUMBER'] ?? '',
      'security_code' => $_ENV['EQUIFAX_SECURITY_CODE'] ?? '',
      'model_id' => $_ENV['EQUIFAX_MODEL_ID'] ?? '05402',
      'optional_feature' => $_ENV['EQUIFAX_OPTIONAL_FEATURE'] ?? 'Z',
      'client_scope' => $_ENV['EQUIFAX_CLIENT_SCOPE'] ?? 'https://api.equifax.com/business/consumer-credit/v1'
    ]),

  ExperianClient::class => create()
    ->constructor([
      'client_id' => $_ENV['EXPERIAN_CLIENT_ID'] ?? '',
      'client_secret' => $_ENV['EXPERIAN_CLIENT_SECRET'] ?? '',
      'experian_user' => $_ENV['EXPERIAN_USER'] ?? '',
      'experian_password' => $_ENV['EXPERIAN_PASS'] ?? '',
      'token_endpoint' => $_ENV['EXPERIAN_TOKEN_EP'] ?? 'https://us-api.experian.com/oauth2/v1/token',
      'report_endpoint' => $_ENV['EXPERIAN_EP'] ?? 'https://us-api.experian.com/consumerservices/credit-profile/v2/credit-report',
      'subscriber_code' => $_ENV['EXPERIAN_SUBSCRIBER_CODE'] ?? '',
      'model_indicator' => $_ENV['EXPERIAN_MODEL_INDICATOR'] ?? 'V4'
    ]),

  TransUnionClient::class => create()
    ->constructor([
      'endpoint' => $_ENV['TU_ENDPOINT'] ?? '',
      'industry_code' => $_ENV['TU_INDUSTRY'] ?? '',
      'member_code' => $_ENV['TU_MEMBER_CODE'] ?? '',
      'subscriber_prefix' => $_ENV['TU_SUBSCRIBER_CODE_PREFIX_SOFTPULL'] ?? '',
      'password' => $_ENV['TU_PWD'] ?? '',
      'certificate_path' => $_ENV['TU_CERTIFICATE_PATH'] ?? '',
      'certificate_password' => $_ENV['TU_CERTIFICATE_PWD'] ?? '',
      'processing_environment' => $_ENV['TU_PROCESSING_ENVIRONMENT'] ?? ''
    ]),

    // Valuation Providers
  NADAProvider::class => create()
    ->constructor([
      'api_key' => $_ENV['NADA_API_KEY'] ?? '',
      'endpoint' => $_ENV['NADA_ENDPOINT'] ?? 'https://api.nada.com/v1'
    ]),

  KelleyBlueBookProvider::class => create()
    ->constructor([
      'api_key' => $_ENV['KBB_API_KEY'] ?? '',
      'endpoint' => $_ENV['KBB_ENDPOINT'] ?? ''
    ]),

  JDPowerProvider::class => create()
    ->constructor([
      'api_key' => $_ENV['JDPOWER_API_KEY'] ?? '',
      'endpoint' => $_ENV['JDPOWER_ENDPOINT'] ?? ''
    ]),

    // Controllers
  WildFirePreQualController::class => autowire()
    ->constructor(
      get(AutomationService::class),
      get(ApplicationPayloadParser::class),
      get(WildFireBureauFormatter::class)
    ),

  PreQualController::class => autowire()
    ->constructor(
      get(AutomationService::class)
    ),

  ValuationController::class => autowire()
    ->constructor(
      get(ValuationProviderFactory::class)
    ),

    // Event Dispatcher with all listeners configured
  EventDispatcher::class => factory(function (ContainerInterface $container) {
      $dispatcher = new EventDispatcher(
        enableAsync: $_ENV['EVENT_ASYNC_ENABLED'] ?? false
      );

      // ML Data Collection Listener
      if ($_ENV['ML_COLLECTION_ENABLED'] ?? true) {
          $mlListener = $container->get(MLDataCollectorListener::class);

          // Register for multiple events
          $dispatcher->addListener(
            PreQualCompletedEvent::class,
            [$mlListener, 'handlePreQualCompleted'],
            priority: 100 // High priority
          );

          $dispatcher->addListener(
            CreditReportPulledEvent::class,
            [$mlListener, 'handleCreditReportPulled'],
            priority: 50
          );

          $dispatcher->addListener(
            PreQualFailedEvent::class,
            [$mlListener, 'handlePreQualFailed'],
            priority: 50
          );
      }

      // Metrics Collection Listener
      if ($_ENV['METRICS_ENABLED'] ?? true) {
          $metricsListener = $container->get(MetricsCollectorListener::class);

          $dispatcher->addAsyncListener(
            PreQualCompletedEvent::class,
            [$metricsListener, 'handlePreQualCompleted']
          );

          $dispatcher->addAsyncListener(
            CreditReportPulledEvent::class,
            [$metricsListener, 'handleCreditReportPulled']
          );
      }

      // Add more listeners as needed
      // Example: Notification listener, Audit listener, etc.

      return $dispatcher;
  }),

    // ML Data Collector Listener
  MLDataCollectorListener::class => autowire()
    ->constructor(
      get(CreditDataCollectionService::class),
      $_ENV['ML_COLLECTION_ENABLED'] ?? true,
      (float)($_ENV['ML_COLLECTION_SAMPLE_RATE'] ?? 1.0)
    ),

    // Credit Data Collection Service
  CreditDataCollectionService::class => create()
    ->constructor(
      $_ENV['ENCRYPTION_KEY'] ?? 'default-key',
      $_ENV['ML_DATABASE'] ?? 'wildfire_automation'
    ),

  HealthController::class => autowire()
];