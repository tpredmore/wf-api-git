<?php

declare(strict_types=1);

use DI\Container;
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
      get(BureauCacheService::class)
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

    // Controllers - No Logger injection needed, they use static Log class
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

  HealthController::class => autowire()
];