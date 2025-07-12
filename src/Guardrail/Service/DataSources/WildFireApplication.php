<?php

namespace WF\API\Guardrail\Service\DataSources;

use Cache;
use Exception;
use MySql;
use stdClass;
use WF\API\Guardrail\Service\DataSourceInterface;
use WF\API\Guardrail\Service\DataSources\Trait\Database;

/**
 * class WildFire Application
 * provides retrieval of the root datasource for our implementation the Wildfire JSON Application Payload
 *
 * @implements DataSourceInterface
 */
class WildFireApplication implements DataSourceInterface
{
    use Database;

    public stdClass $application;
    private int $applicationId;
    private string $sourceDBHost = 'DB_HOST';
    private string $sourceDbName = 'wildfire_applications';
    private string $sourceTableName = 'wildfire_applications';

    /**
     * @param MySql $dbConn
     * @param Cache $cache
     * @param mixed $variables
     * @throws Exception
     */
    public function __construct(
        protected MySql $dbConn,
        protected Cache $cache,
        protected mixed $variables
    )
    {
        if (empty($variables) || !is_int($variables)) {
            throw new Exception('WildFireApplication Requires an ApplicationId');
        }

        $this->applicationId = $variables;
    }

    /**
     * @inheritDoc
     * @throws Exception
     */
    public function fetch(mixed $criteria = null): stdClass
    {
        $application = $this->dbConn::call(
            'wildfire_applications',
            'wf_applications_get',
            [$this->applicationId]
        );
        if (empty($application[0]['payload'])) {
            throw new Exception(
                "Retrieve Failed! ApplicationID: {$this->applicationId}"
            );
        }

        $application = json_decode($application[0]['payload']);
        if (empty($application) || !($application instanceof stdClass)) {
            throw new Exception(
                "InValid DATA! ApplicationID: {$this->applicationId}
            ");
        }

        $this->application = $application;
        $this->applicationId = $application->application_id;

        return $this->application;
    }

    /**
     * @return int
     */
    public function getApplicationId(): int
    {
        return $this->applicationId ?? 0;
    }

    /**
     * @inheritDoc
     * @throws Exception
     */
    function shape(mixed $data): ?stdClass
    {
        throw new Exception("Not implemented");
    }
}
