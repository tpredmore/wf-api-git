<?php

namespace WF\API\Guardrail\Service;

use Exception;
use stdClass;

/**
 * Interface Data Source Interface
 *
 * Provides for the ability to add, remove, and leverage alternative sources for consideration either as values
 * or criteria used in evaluation.
 *
 * The GuardrailService depends on your implementation to translate into a flat stdClass object.
 */
interface DataSourceInterface
{
    /**
     * @method fetch()
     * @param mixed $criteria
     * @return stdClass
     * @thows Exception
     */
    public function fetch(mixed $criteria = null): stdClass;

    /**
     * @param mixed $data
     * @return stdClass|null
     * @throws Exception
     */
    function shape(mixed $data): ?stdClass;
}
