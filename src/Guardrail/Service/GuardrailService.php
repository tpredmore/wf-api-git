<?php

namespace WF\API\Guardrail\Service;

use Exception;
use Log;
use stdClass;
use Throwable;
use WF\API\Guardrail\Service\DataSources\Trait\DataSourceResolver;

/**
 * class GuardrailService
 * Provides for the evaluation of RuleSets
 */
class GuardrailService
{
    use DataSourceResolver;

    /**
     * @param EvaluationMethod $evalMethods
     * @param Log $logger
     * @param array $ruleSet
     * @param array $dataSources
     * @throws Exception
     */
    public function __construct(
        protected EvaluationMethod $evalMethods,
        protected Log              $logger,
        protected array            $ruleSet,
        public array               $dataSources
    )
    {
        foreach ($this->dataSources as $dataSource) {
            if (!($dataSource instanceof stdClass)) {
                throw new Exception("Data source must be a stdClass object");
            }
        }

        $this->evalMethods->setDataSource($this->dataSources);
    }

    /**
     * Orchestrates Evaluation of a rule set and provides the conclusion
     *
     * @return string
     * @throws Exception
     */
    public function runEvaluation(): string
    {
        try {
            $finalEvaluation = [
                'success' => true,
                'evaluations' => [],
                'conclusion_by' => null,
                'conclusion_notice' => null,
            ];

            /**
             * @var Rule $rule
             */
            foreach ($this->ruleSet as $rule) {
                $result = $this->evaluateRule($rule);
                $finalEvaluation['evaluations'][] = json_decode($result);
            }

            foreach ($finalEvaluation['evaluations'] as $evaluation) {
                if ($evaluation->passed === false) {
                    /* parent rule has failed and there are no sub_rules.
                        conclusion is this evaluation */

                    $finalEvaluation['success'] = false;
                    $finalEvaluation['conclusion_by'] = $evaluation->sequence;
                    $finalEvaluation['conclusion_notice'] = $evaluation->fail;

                    return json_encode($finalEvaluation, JSON_THROW_ON_ERROR);
                }

                /* parent rule has passed and there are sub_rule/s */
                if (!empty($evaluation->sub_rule)) {
                    $subRules = is_array($evaluation->sub_rule)
                        ? $evaluation->sub_rule
                        : [$evaluation->sub_rule];

                    foreach ($subRules as $subRule) {
                        if ($subRule->passed === false) {
                            /* First Failing Sub Rule -- conclusion is this evaluation */

                            $finalEvaluation['success'] = $subRule->passed;
                            $finalEvaluation['conclusion_by'] = $evaluation->sequence;
                            $finalEvaluation['conclusion_notice'] = $subRule->fail;

                            return json_encode($finalEvaluation, JSON_THROW_ON_ERROR);
                        }
                    }
                }

                /* if all parent and sub-rules passed then we set conclusion ALL PASSED */
                if (
                    empty($finalEvaluation['conclusion_notice']) &&
                    empty($finalEvaluation['conclusion_by'])
                ) {
                    $finalEvaluation['success'] = true;
                    $finalEvaluation['conclusion_by'] = 'RULE_SET';
                    $finalEvaluation['conclusion_notice'] = 'No Restriction Imposed All Rules Passed';

                    return json_encode($finalEvaluation, JSON_THROW_ON_ERROR);
                }
            }
            $this->logger->error("Evaluation failed! should not be possible to arrive here");
            return json_encode($finalEvaluation, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * Evaluates each base level rule and drills into sub_rules as necessary.
     * Individual BASE and its related Sub Rule/s are and collected here.
     *
     * @param Rule $rule
     * @return string
     * @throws Exception
     */
    public function evaluateRule(Rule $rule): string
    {
        try {
            $ruleId = $rule->getId();
            $target = $rule->getTarget();
            $operator = $rule->getOperatorName();
            $criteria = $rule->getCriteria();
            $sequence = $rule->getSequence();

            /* BASE RULE EVALUATION */
            $baseRuleTargetValue = $this->getValueFromDataSource($target);

            $operatorMethod = $this->evalMethods->operators[$operator];

            $baseRuleEvalResult = $this->evalMethods->$operatorMethod($baseRuleTargetValue, $criteria);

            $this->logger->debug(
                "$target `$operator`  vals:[$baseRuleTargetValue] crit:[$criteria]"
            );

            $subRuleEval = null;
            if ($rule->getSubRule()) {
                /* SUB-RULE EVALUATION */
                $subRuleEval = $this->evaluateSubRule($rule);
            }

            $evaluation = [
                'sequence' => $sequence,
                'target' => $target,
                'value' => $baseRuleTargetValue,
                'operator' => $operator,
                'criteria' => json_encode($criteria),
                'passed' => $baseRuleEvalResult,
                'sub_rule' => $subRuleEval,
                'on_fail' => $rule->getOnFail(),
                'on_pass' => $rule->getOnPass(),
                'pass' => $rule->getPassMsg(),
                'fail' => $rule->getFailMsg(),
                'warn' => $rule->getWarnMsg()
            ];

            return json_encode($evaluation, JSON_THROW_ON_ERROR);
        } catch (Throwable $t) {
            $msg = "Evaluate FAILURE!: RuleID:"
                . $ruleId
                . ' Target:'
                . $target
                . ' |' . $t->getMessage() . ' |' . $t->getTraceAsString();

            $this->logger->info($msg);

            throw new Exception($msg);
        }
    }

    /**
     * Evaluates the discrete sub_rule/s defined on the parent and reports back.
     * Called in evaluateRule()
     *
     * @param Rule $rule
     * @return stdClass|array
     * @throws Exception
     */
    private function evaluateSubRule(Rule $rule): stdClass|array
    {
        try {
            $subRuleEval = [];
            $subRules = $rule->getSubRule();
            foreach ($subRules as $subRule) {
                if (!property_exists($subRule, 'criteria')) {
                    throw new Exception("SubRule criteria property is required!");
                }
                $criteria = $subRule->criteria;

                if (!property_exists($subRule, 'operator_name')) {
                    throw new Exception("SubRule operator property is required!");
                }
                $operatorName = $subRule->operator_name;

                if (!array_key_exists($operatorName, $this->evalMethods->operators)) {
                    throw new Exception("Operator $operatorName is not defined.");
                }

                $evalMethod = $this->evalMethods->operators[$operatorName];
                if (!method_exists($this->evalMethods, $evalMethod)) {
                    throw new Exception("Evaluation method for operator '$operatorName' does not exist.");
                }

                if (!property_exists($subRule, 'depends')) {
                    throw new Exception("SubRule depends property is required!");
                }
                $depends = $subRule->depends;

                if (!property_exists($subRule, 'fail')) {
                    throw new Exception("SubRule fail property is required!");
                }
                if (!property_exists($subRule, 'on_fail')) {
                    throw new Exception("SubRule on_fail property is required!");
                }

                $resolvedValues = [];
                foreach ((array)$depends as $dep) {
                    $pathParts = explode('.', $dep);
                    $depth = count($pathParts);
                    if (empty($pathParts) || $depth < 2) {
                        throw new Exception("SubRule depends INVALID! Requires `source.property`");
                    }

                    $searchPath = json_encode($depends);
                    if (count($depends) === 1) {
                        $resolvedValues[$dep] = $this->getValueFromDataSource($searchPath);
                    } else {
                        $resolvedValues = $this->getMultipleValuesFromMultipleDataSources($searchPath);
                    }
                }

                $value = count($resolvedValues) === 1 ? reset($resolvedValues) : $resolvedValues;

                $subRuleResult = $this->evalMethods->$evalMethod($value, $criteria);

                $subRuleEval[] = [
                    'passed' => $subRuleResult,
                    'criteria' => json_encode($criteria),
                    'operator_name' => $operatorName,
                    'depends' => $resolvedValues,
                    'on_fail' => $rule->getOnFail(),
                    'fail' => $rule->getFailMsg()
                ];
            }

            return json_decode(json_encode($subRuleEval, JSON_THROW_ON_ERROR));
        } catch (Throwable $t) {
            $msg = "Evaluate FAILURE!: Rule: " . json_encode($rule) . '|' . $t->getMessage();
            $this->logger->info($msg);

            throw new Exception($msg);
        }
    }
}
