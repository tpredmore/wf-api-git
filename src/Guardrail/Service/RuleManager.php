<?php

namespace WF\API\Guardrail\Service;

use Exception;
use Log;
use MySql;
use Cache;
use Throwable;

/**
 * class RuleManager
 *
 * Provides for Rule CRUD activities
 */
class RuleManager
{
    /**
     * @param Log $logger
     * @param MySql $dbConnection
     * @param Cache $cacheService
     */
    public function __construct(
        protected Log $logger,
        protected Mysql $dbConnection,
        protected Cache $cacheService
    ) {
    }

    /**
     * @param Rule $rule
     * @return bool
     * @throws Exception
     */
    public function addNewRule(Rule $rule): bool
    {
        try {
            $params = $rule->toArray();
            array_shift($params); // pull off the ID DB will default Auto Increment
            array_pop($params); // pull off the created_at date DEFAULT CURRENT_TIMESTAMP
            array_pop($params); // pull off the updated_at date DEFAULT CURRENT_TIMESTAMP

            $success = $this->dbConnection->call(
                'wildfire_configuration',
                'wf_guardrail_insert',
                $params
            );

            if ($success === false) {
                throw new Exception($this->dbConnection->last_error());
            }

            return true;
        } catch (Throwable $t) {
            $this->logger->error($t->getMessage());

            return false;
        }
    }

    /**
     * @param int $id
     * @return Rule|false
     * @throws Exception
     */
    public function getRuleById(int $id): Rule|false
    {
        try {
            $result = $this->dbConnection->call(
                'wildfire_configuration',
                'wf_guardrail_get_by_id',
                [$id]
            );

            if ($result === false) {
                throw new Exception($this->dbConnection->last_error());
            }

            if (!empty($result)) {
                return Rule::fromArray($result);
            } else {
                throw new Exception("Invalid Result From DB::call()");
            }
        } catch (Throwable $t) {
            $this->logger->error($t->getMessage());

            return false;
        }
    }

    /**
     * @param string $type
     * @param string $area
     * @return array|null
     * @throws Exception
     */
    public function getRuleSetByTypeAndArea(string $type, string $area): array|null
    {
        try {
            if (!in_array($type, ['STATUS', 'ASSIGNMENT', 'ACTION', 'TEST'])) {
                throw new Exception("Invalid RuleSet Type Argument!");
            }

            $cacheKey = strtoupper("$type-$area");
            $cacheHit = $this->cacheService->get($cacheKey);
            if (empty($cacheHit)) {
                $result = $this->dbConnection->call(
                    'wildfire_configuration',
                    'wf_guardrail_get_by_type_and_area',
                    [$type, $area]
                );

                if ($result === false) {
                    $msg = "wf_guardrail_get_by_type_and_area FAILED! |"
                        . $this->dbConnection->last_error();

                    throw new Exception($msg);
                } elseif (!empty($result) && is_array($result)) {
                    $ruleCacheSet = [];
                    $resultRuleSet = [];

                    foreach ($result as $row) {
                        $ruleCacheSet[] = json_decode(json_encode($row));
                        $resultRuleSet[] = Rule::fromArray($row);
                    }

                    if ((count($resultRuleSet) >= 1) && (count($ruleCacheSet) >= 1)) {
                        $cacheSet = json_encode($ruleCacheSet);
                        $cached = $this->cacheService->set($cacheKey, $cacheSet);
                        if ($cached === false) {
                            $this->logger->error("RuleSet Cache Write FAILED!");
                        }
                    }
                }
            } else {
                $cacheHit = json_decode($cacheHit, true);
                foreach ($cacheHit as $row) {
                    $ruleRecord = json_decode(json_encode($row), true);
                    $resultRuleSet[] = Rule::fromArray($ruleRecord);
                }
            }

            return $resultRuleSet ?? null;
        } catch (Throwable $t) {
            $this->logger->error($t->getMessage());

            return null;
        }
    }
}
