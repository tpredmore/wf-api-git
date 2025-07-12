<?php

declare(strict_types=1);

namespace WF\API\Automation\Factories;

use WF\API\Automation\Contracts\CreditParserInterface;
use WF\API\Automation\Parsers\EquifaxParser;
use WF\API\Automation\Parsers\ExperianParser;
use WF\API\Automation\Parsers\TransUnionParser;
use WF\API\Automation\Exceptions\AutomationException;

class CreditParserFactory
{

    /**
     * @throws \WF\API\Automation\Exceptions\AutomationException
     */
    public function create(string $bureau): CreditParserInterface
    {
        return match (strtolower($bureau)) {
            'equifax' => new EquifaxParser(),
            'experian' => new ExperianParser(),
            'transunion', 'trans_union' => new TransUnionParser(),
            default => throw new AutomationException("Unsupported bureau parser: {$bureau}")
        };
    }
}
