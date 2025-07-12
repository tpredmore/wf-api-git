<?php

namespace WF\API\Guardrail\Service;


use Exception;
use Log;
use stdClass;
use Throwable;
use WF\API\Guardrail\Service\DataSources\Trait\DataSourceResolver;

/**
 * class Evaluate Method
 *
 * contains ALL operation methods available for evaluation.
 */
class EvaluationMethod
{
    use DataSourceResolver;

    public array $operators = [
        'exists' => 'mixedExists',
        'is_true' => 'boolIsTrue',
        'is_false' => 'boolIsFalse',

        'str_=' => 'strEquals',
        'str_!=' => 'strNotEquals',

        'num_=' => 'numEqual',
        'num_!=' => 'numNotEqual',
        'num_>' => 'numGreaterThan',
        'num_>=' => 'numGreaterOrEqual',
        'num_<' => 'numLessThan',
        'num_<=' => 'numLessOrEqual',
        'regex' => 'valueMatchesPattern',

        // criteria object required
        'in_set' => 'valueInSet',
        'not_in_set' => 'valueNotInSet',
        'between' => 'valueBetween',
        'date_tolerance' => 'dateTolerance'
    ]; // [stdClass, stdClass...]
    private array $dataSources;

    /**
     * Evaluation Method Constructor
     *
     * @param Log $logger
     */
    public function __construct(
        protected Log $logger
    )
    {
    }

    /**
     * @param array $dataSource
     * @return void
     */
    public function setDataSource(array $dataSource): void
    {
        $this->dataSources = $dataSource;
    }

    /**
     * @param int $value
     * @param int $criteria
     * @return bool
     */
    public function numGreaterThan(int $value, int $criteria): bool
    {
        $this->logger->debug("numGreaterThan() $value > $criteria");
        $eval = $value > $criteria;
        $this->logger->debug("numGreaterThan() evals: $eval");
        return $eval;
    }

    /**
     * @param int $value
     * @param int $criteria
     * @return bool
     */
    public function numGreaterOrEqual(int $value, int $criteria): bool
    {
        $this->logger->debug("numGreaterOrEqual() $value > $criteria");
        $eval = $value >= $criteria;
        $this->logger->debug("numGreaterOrEqual() evals: $eval");
        return $eval;
    }

    /**
     * @param int $value
     * @param int $criteria
     * @return bool
     */
    public function numLessThan(int $value, int $criteria): bool
    {
        $this->logger->debug("numLessThan() $value > $criteria");
        $eval = $value < $criteria;
        $this->logger->debug("numLessThan() evals: $eval");
        return $eval;
    }

    /**
     * @param int $value
     * @param int $criteria
     * @return bool
     */
    public function numLessOrEqual(int $value, int $criteria): bool
    {
        $this->logger->debug("numLessOrEqual() $value > $criteria");
        $eval = $value <= $criteria;
        $this->logger->debug("numLessOrEqual() evals: $eval");
        return $eval;
    }

    /**
     * @param int $value
     * @param int $criteria
     * @return bool
     */
    public function numEqual(int $value, int $criteria): bool
    {
        $this->logger->debug("numEqual() $value > $criteria");
        $eval = $value === $criteria;
        $this->logger->debug("numEqual() evals: $$eval");
        return $eval;
    }

    /**
     * @param int $value
     * @param int $criteria
     * @return bool
     */
    public function numNotEqual(int $value, int $criteria): bool
    {
        $this->logger->debug("numNotEqual() $value > $criteria");
        $eval = $value !== $criteria;
        $this->logger->debug("numNotEqual() evals: $$eval");
        return $eval;
    }

    /**
     * @param string $value
     * @param string $criteria
     * @return bool
     */
    public function strEquals(string $value, string $criteria): bool
    {
        $this->logger->debug("strEqual() $value > $criteria");
        $eval = $value === $criteria;
        $this->logger->debug("strEqual() evals: $$eval");
        return $eval;
    }

    /**
     * @param string $value
     * @param string $criteria
     * @return bool
     */
    public function strNotEquals(string $value, string $criteria): bool
    {
        $this->logger->debug("strNotEqual() $value > $criteria");
        $eval = $value !== $criteria;
        $this->logger->debug("strNotEqual() evals: $$eval");
        return $eval;
    }

    /**
     * @param mixed $value
     * @return bool
     */
    public function boolIsTrue(mixed $value): bool
    {
        if ($value === true) {
            $this->logger->debug("boolIsTrue() evals: YES!");
            return true;
        }

        $this->logger->debug("boolIsTrue() evals: NO!");
        return false;
    }

    /**
     * @param mixed $value
     * @return bool
     */
    public function boolIsFalse(bool $value): bool
    {
        if ($value === false) {
            $this->logger->debug("boolIsFalse() evals: YES!");
            return true;
        }

        $this->logger->debug("boolIsFalse() evals: NO!");
        return false;
    }

    /**
     * @param mixed $value
     * @return bool
     */
    public function mixedExists(mixed $value): bool
    {
        $result = $value !== null && $value !== '';

        if ($result === true) {
            $this->logger->debug("mixedExists() evals: YES!");
        } else {
            $this->logger->debug("mixedExists() evals: NO!");
        }

        return $result;
    }

    /**
     * @param mixed $value
     * @param mixed $criteria
     * @return bool
     * @throws Exception
     */
    public function valueInSet(mixed $value, mixed $criteria): bool
    {
        try {
            $eval = false;

            $list = json_decode(stripslashes($criteria), true);
            if (empty($list) || !is_array($list) || (count($list) < 1)) {
                throw new Exception(" Property 'list' Invalid!");
            }

            $this->logger->debug(
                "valueInSet() value: "
                . json_encode($value)
                . ' criteria: '
                . json_encode($criteria)
            );

            if (in_array($value, $list)) {
                $eval = true;
            }

            $this->logger->debug("valueInSet() evals: " . json_encode($eval));

            return $eval;
        } catch (Exception $e) {
            throw new Exception("Evaluation Method `valueInSet()` Failed!" . $e->getMessage());
        }
    }

    /**
     * @param mixed $value
     * @param mixed $criteria
     * @return bool
     * @throws Exception
     */
    public function valueNotInSet(mixed $value, mixed $criteria): bool
    {
        try {
            $eval = false;

            $list = json_decode(stripslashes($criteria), true);
            if (empty($list) || !is_array($list) || (count($list) < 1)) {
                throw new Exception(" Property 'list' Invalid!");
            }

            $this->logger->debug(
                "valueNotInSet() value: "
                . json_encode($value)
                . ' criteria: '
                . json_encode($criteria)
            );

            if (!in_array($value, $list)) {
                $eval = true;
            }

            $this->logger->debug("valueNotInSet() evals: " . json_encode($eval));

            return $eval;
        } catch (Exception $e) {
            throw new Exception("Evaluation Method `valueNotInSet()` Failed!" . $e->getMessage());
        }
    }

    /**
     * @param mixed $value
     * @param mixed $criteria
     * @return bool
     */
    public function valueBetween(mixed $value, mixed $criteria): bool
    {
        $this->logger->debug(
            "valueBetween() value: "
            . json_encode($value)
            . ' criteria: '
            . json_encode($criteria)
        );

        if (!($criteria instanceof stdClass)) {
            $this->logger->debug(
                "Evaluate Between: Expects Criteria as a stdClass with `from` and `to` properties!"
            );

            return false;
        }

        if (($value >= $criteria->from) && ($value <= $criteria->to)) {
            return true;
        }

        return false;
    }

    /**
     * @param mixed $value
     * @param string $pattern
     * @return bool
     * @throws Exception
     */
    public function valueMatchesPattern(mixed $value, string $pattern): bool
    {
        $pattern = trim($pattern, "\"'");
        if (@preg_match($pattern, '') === false) {
            throw new Exception("Invalid regular expression pattern: $pattern");
        }

        $valueStr = (string)$value;
        $matches = preg_match($pattern, $valueStr) === 1;

        $this->logger->debug(
            "valueMatchesPattern() value: "
            . json_encode($valueStr)
            . ' pattern: '
            . json_encode($pattern)
            . ' evals: '
            . json_encode($matches)
        );

        return $matches;
    }

    /**
     * @param mixed $value
     * @param mixed $criteria
     * @return bool
     * @throws Exception
     */
    public function dateTolerance(mixed $value, mixed $criteria): bool
    {
        try {
            if (!is_array($value) || count($value) !== 2) {
                throw new Exception(' Requires Two Date Properties!');
            }

            $values = array_values($value);
            if (empty($values[0]) || empty($values[1])) {
                throw new Exception("One or both date values are missing or empty.");
            }

            $valsMsg = "[$values[0], $values[1]]";
            $date1 = strtotime($values[0]);
            $date2 = strtotime($values[1]);

            if (!$date1 || !$date2) {
                throw new Exception("Invalid date format provided for evaluateTolerance.");
            }

            $dayDifference = abs(($date2 - $date1) / 86400);
            $this->logger->debug("Evaluate DateTolerance: dates: $valsMsg diff:{$dayDifference} days");

            if (is_array($criteria)) {
                $resolved = [];
                foreach ($criteria as $key) {
                    if (is_string($key) && str_contains($key, '.')) {
                        $resolved[] = $this->getValueFromDataSource(json_encode([$key]));
                    } else {
                        $resolved[] = (int)$key;
                    }
                }

                $count = count($resolved);
                if ($count === 1) {
                    $minimum = $resolved[0];
                    $this->logger->debug("Evaluate DateTolerance Minimum: $minimum");
                    $passes = ($dayDifference >= $minimum);
                } elseif ($count === 2) {
                    [$min, $max] = $resolved;
                    $this->logger->debug("Evaluate DateTolerance Between: $min and $max");
                    $passes = ($dayDifference >= $min && $dayDifference <= $max);
                } else {
                    throw new Exception("criteria array must contain 1 or 2 elements.");
                }
            } else {
                throw new Exception("expects criteria to be an array of one or two values.");
            }

            $this->logger->debug('Evaluate DateTolerance: Passed: ' . ($passes ? 'true' : 'false'));

            return $passes;
        } catch (Throwable $t) {
            $this->logger->error(
                'Evaluate DateTolerance: Passed: '
                . (($passes ?? false) ? 'true' : 'false') . ' | ' . $t->getMessage()
                . ' | ' . $t->getTraceAsString()
            );

            throw new Exception("Evaluate DateTolerance: " . $t->getMessage());
        }
    }
}
