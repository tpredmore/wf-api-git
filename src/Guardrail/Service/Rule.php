<?php

namespace WF\API\Guardrail\Service;

use DateTime;
use Exception;

/**
 * class Rule
 *
 * Provides a consistent Rule Object both for Evaluation and CRUD
 * Every Rule in a Set is constructed by this class
 */
class Rule
{
    const TYPE_ENUM = ['ACTION', 'ASSIGNMENT', 'STATUS', 'TEST'];
    const ON_FAIL_ENUM = ['RESTRICT', 'WARN', 'LOG'];
    const ON_PASS_ENUM = ['CONTINUE', 'WARN', 'LOG'];

    const OPERATOR_ENUM = [
        'exists',
        'is_true',
        'is_false',
        'regex',
        'num_>',
        'num_>=',
        'num_<',
        'num_<=',
        'num_=',
        'num_!=',
        'str_=',
        'str_!=',
        'in_set',
        'not_in_set',
        'between',
        'date_tolerance'
    ];

    protected int $id = 0;
    protected string $type = 'TEST';
    protected string $area = 'TEST';
    protected int $sequence = 1;
    protected string $target;
    protected string $operator_name = 'num_=';
    protected string|null $criteria = null;
    protected string|null $sub_rule = null;
    protected string $on_fail = 'RESTRICT';
    protected string $on_pass = 'CONTINUE';
    protected string $pass = '';
    protected string $fail = '';
    protected string $warn = '';
    protected string $updated_by = 'System User';
    private DateTime|null $updated_at;
    private DateTime|null $created_at;

    /**
     * @throws Exception
     */
    public function __construct(
        int         $id,
        string      $type,
        string      $area,
        int         $sequence,
        string      $target,
        string      $operator_name,
        string|null $criteria,
        string|null $sub_rule,
        string      $on_fail,
        string      $on_pass,
        string      $pass,
        string      $fail,
        string      $warn,
        string      $updated_by,
        string|null $updated_at,
        string|null $created_at
    )
    {
        if ($id > 0) {
            $this->id = $id;
        }

        if (empty($type) || !self::isValidEnum('type', $type)) {
            throw new Exception('Invalid rule value for:type');
        }
        $this->type = $type;

        if (empty($area)) {
            throw new Exception('Invalid rule value for:area');
        }
        $this->area = $area;

        if ($sequence < 1) {
            throw new Exception('Invalid rule value for:sequence');
        }
        $this->sequence = $sequence;

        if (empty($target)) {
            throw new Exception('Invalid rule value for:target');
        }
        $this->target = $target;

        if (empty($operator_name) || !self::isValidEnum('operator_name', $operator_name)) {
            throw new Exception('Invalid rule value for:operator_name');
        }
        $this->operator_name = $operator_name;

        if (!empty($criteria) && ($criteria != 0)) {
            if (!is_numeric($criteria) && (strlen($criteria) > 0)) {
                $this->criteria = addslashes($criteria);
            } else {
                $this->criteria = $criteria;
            }
        }

        if (!empty($sub_rule) && (strlen($sub_rule) > 0)) {
            $sub_rule = addslashes($sub_rule);
        }
        $this->sub_rule = $sub_rule ?? null;

        if (empty($on_fail) || !self::isValidEnum('on_fail', $on_fail)) {
            throw new Exception('Invalid rule value for:on_fail');
        }
        $this->on_fail = $on_fail;

        if (empty($on_pass) || !self::isValidEnum('on_pass', $on_pass)) {
            throw new Exception('Invalid rule value for:on_pass');
        }
        $this->on_pass = $on_pass;

        if (!empty($pass)) {
            $this->pass = addslashes($pass);
        }
        if (!empty($fail)) {
            $this->fail = addslashes($fail);
        }
        if (!empty($warn)) {
            $this->warn = addslashes($warn);
        }
        if ($updated_by >= 1) {
            $this->updated_by = $updated_by;
        }

        $this->updated_at = null;
        if (!empty($updated_at) && strtotime($updated_at)) {
            $datetime = new DateTime();
            $this->updated_at = $datetime->createFromFormat(
                'Y-m-d H:i:s',
                $updated_at
            );
        }

        $this->created_at = null;
        if (!empty($created_at) && strtotime($created_at)) {
            $dateTime = new DateTime();
            $this->created_at = $dateTime::createFromFormat(
                'Y-m-d H:i:s',
                $created_at
            );
        }
    }

    /**
     * @param string $fieldName
     * @param string $value
     * @return bool
     */
    private function isValidEnum(string $fieldName, string $value): bool
    {
        $found = false;

        switch ($fieldName) {
            case 'type':
                $found = in_array($value, self::TYPE_ENUM);
                break;
            case 'on_fail':
                $found = in_array($value, self::ON_FAIL_ENUM);
                break;
            case 'on_pass':
                $found = in_array($value, self::ON_PASS_ENUM);
                break;
            case 'operator_name':
                $found = in_array($value, self::OPERATOR_ENUM);
                break;
        }

        return $found;
    }

    /**
     * @param array $array
     * @return Rule
     * @throws Exception
     */
    public static function fromArray(array $array): Rule
    {
        return new Rule(
            ($array['id'] ?? 0),
            ($array['type'] ?? 'TEST'),
            ($array['area'] ?? 'TEST'),
            ($array['sequence'] ?? 1),
            ($array['target'] ?? ''),
            ($array['operator_name'] ?? 'str_='),
            ($array['criteria'] ?? null),
            ($array['sub_rule'] ?? null),
            ($array['on_fail'] ?? 'LOG'),
            ($array['on_pass'] ?? 'LOG'),
            ($array['pass'] ?? ''),
            ($array['fail'] ?? ''),
            ($array['warn'] ?? ''),
            ($array['updated_by'] ?? 'System User'),
            ($array['updated_at'] ?? null),
            ($array['created_at'] ?? null)
        );
    }

    /**
     * @return string
     */
    public function getArea(): string
    {
        return $this->area;
    }

    /**
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return int
     */
    public function getSequence(): int
    {
        return $this->sequence;
    }

    /**
     * @return string
     */
    public function getTarget(): string
    {
        return $this->target;
    }

    /**
     * @return string
     */
    public function getOperatorName(): string
    {
        return $this->operator_name;
    }

    /**
     * @return string|null
     */
    public function getCriteria(): ?string
    {
        return $this->criteria;
    }

    /**
     * @return array|null
     * @throws Exception
     */
    public function getSubRule(): array|null
    {
        if (empty($this->sub_rule)) {
            return null;
        }

        $cleansed = stripslashes($this->sub_rule);
        $decoded = json_decode($cleansed, false, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [$decoded];
    }

    /**
     * @return string
     */
    public function getOnFail(): string
    {
        return $this->on_fail;
    }

    /**
     * @return string
     */
    public function getOnPass(): string
    {
        return $this->on_pass;
    }

    /**
     * @return string
     */
    public function getPassMsg(): string
    {
        return $this->pass;
    }

    /**
     * @return string
     */
    public function getFailMsg(): string
    {
        return $this->fail;
    }

    /**
     * @return string
     */
    public function getWarnMsg(): string
    {
        return $this->warn;
    }

    /**
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @return array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'area' => $this->area,
            'sequence' => $this->sequence,
            'target' => $this->target,
            'operator_name' => $this->operator_name,
            'criteria' => $this->criteria,
            'sub_rule' => $this->sub_rule,
            'on_fail' => $this->on_fail,
            'on_pass' => $this->on_pass,
            'pass' => $this->pass,
            'fail' => $this->fail,
            'warn' => $this->warn,
            'updated_by' => $this->updated_by,
            'updated_at' => ($this->updated_at ? $this->updated_at->format('Y-m-d H:i:s') : null),
            'created_at' => ($this->created_at ? $this->created_at->format('Y-m-d H:i:s') : null)
        ];
    }
}
