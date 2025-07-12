<?php

namespace WF\API\Guardrail;


use Cache;
use Exception;
use Log;
use MySql;
use stdClass;
use Throwable;
use WF\API\Guardrail\Service\DataSources\WildFireApplication;
use WF\API\Guardrail\Service\DataSources\WildFireLenderConfiguration;
use WF\API\Guardrail\Service\EvaluationMethod;
use WF\API\Guardrail\Service\GuardrailService;
use WF\API\Guardrail\Service\RuleManager;

class Evaluator
{
    protected MySql $dbConn;
    protected Log $logger;
    protected Cache $cacheService;

    public function __construct()
    {
        $this->dbConn = new MySql();
        $this->logger = new Log();
        $this->cacheService = new Cache();
    }

    /**
     * @throws Exception
     */
    public function handler(stdClass $json): array
    {
        try {
            if (
                !isset($json->application_id, $json->type, $json->area) ||
                !((int)($json->application_id) > 0)
            ) {
                throw new Exception('Invalid Request Envelope!');
            }

            $applicationId = $json->application_id;
            $ruleType = $json->type;
            $ruleArea = $json->area;
            $guardrailDataSources = [];

            /* LOAD UP THE RULE SET */
            $ruleManager = new RuleManager($this->logger, $this->dbConn, $this->cacheService);
            $ruleSet = $ruleManager->getRuleSetByTypeAndArea($ruleType, $ruleArea);
            if (empty($ruleSet)) {
                throw new Exception(
                    "No RuleSet Found! type: $ruleType area: $ruleArea"
                );
            }

            /* TESTING */
            if (
                property_exists($json, 'testing') &&
                ($json->testing === true)
            ) {
                if (
                    property_exists($json, 'datasets') &&
                    is_object($json->datasets)
                ) {
                    foreach ($json->datasets as $key => $value) {
                        if ($value instanceof stdClass) {
                            $guardrailDataSources[$key] = $value;
                        } else {
                            throw new Exception("Dataset '$key' must be a stdClass object!");
                        }
                    }
                    $this->logger::debug("TEST DATA LOADED:" . json_encode($json->datasets, JSON_PRETTY_PRINT));
                } else {
                    $application = new WildFireApplication(
                        $this->dbConn,
                        $this->cacheService,
                        $applicationId
                    );
                    $guardrailDataSources['application'] = $application->fetch('');
                    $this->logger::debug("Application Loaded...");
                }

            } else {
                $application = new WildFireApplication(
                    $this->dbConn,
                    $this->cacheService,
                    $applicationId
                );
                $guardrailDataSources['application'] = $application->fetch('');
                $this->logger::debug("Application Loaded...");

                $variables = new stdClass();
                $variables->application_id = $applicationId;
                $variables->lender_id = (int)($application->application->selected_decision_lender_id ?? 0);

                $lenderConfiguration = new WildFireLenderConfiguration(
                    $this->dbConn,
                    $this->cacheService,
                    $this->logger,
                    $variables
                );
                $guardrailDataSources['lender_configuration'] = $lenderConfiguration->fetch();
                $this->logger::debug("Lender Configurations Loaded...");
            }

            $guardrailService = new GuardrailService(
                new EvaluationMethod($this->logger),
                $this->logger,
                $ruleSet,
                $guardrailDataSources
            );

            $result = $guardrailService->runEvaluation();
            $result = json_decode($result, true);

            $success = $result['success'];
            $err = 'Evaluation Complete!';
            $data = $result;
        } catch (Throwable $t) {
            $success = false;
            $err = $t->getMessage();
            $data = [];
        }

        return [
            'success' => $success,
            'error' => $err,
            'data' => $data
        ];
    }
}
