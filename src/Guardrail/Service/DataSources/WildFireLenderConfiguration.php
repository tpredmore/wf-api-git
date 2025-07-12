<?php

namespace WF\API\Guardrail\Service\DataSources;

use Cache;
use Exception;
use Log;
use MySql;
use stdClass;
use Throwable;
use WF\API\Guardrail\Service\DataSourceInterface;
use WF\API\Guardrail\Service\DataSources\Trait\Database;

/**
 * class WildFire Lender Configuration
 *
 * Provides for loading cache with ALL LENDER configuration records. And helper to
 * obtain only a single lenders configuration from that cache.
 *
 * @implements DataSourceInterface
 */
class WildFireLenderConfiguration implements DataSourceInterface
{
    use Database;

    protected string $db = 'wildfire_configuration';
    private string $sourceDBHost = 'DB_HOST';
    private string $sourceDbName = 'wildfire_configuration';
    private string $sourceTableName = 'lender_config';
    private string $redisKey = "Guardrail:LenderConfigs";
    private stdClass $lenderConfiguration;

    /**
     * @param MySql $dbConn
     * @param Cache $cache
     * @param Log $logger
     * @param mixed $variables
     * @throws Throwable
     */
    public function __construct(
        protected MySql $dbConn,
        protected Cache $cache,
        protected Log   $logger,
        protected mixed $variables
    )
    {
        if (
            empty($variables->application_id) ||
            empty($variables->lender_id)
        ) {
            throw new Exception("application_id && lender_id REQUIRED!");
        }
    }

    /**
     * @inheritDoc
     * @throws Exception
     */
    public function fetch(mixed $criteria = null): stdClass
    {
        return $this->getLenderConfigFromCache();
    }

    /**
     * @return stdClass|null
     * @throws Exception
     */
    private function getLenderConfigFromCache(): stdClass|null
    {
        try {
            $cacheHit = $this->cache->get($this->redisKey, $returnAsArray = true);
            if (empty($cacheHit) || !is_array($cacheHit)) {
                $lenderConfigs = $this->dbConn->call('wildfire_configuration', 'wf_lender_config_get_active', []);
                if (!$lenderConfigs) {
                    throw new Exception("Data Retrieve Failed! Lender Configuration Not Found!");
                }

                $allConfigs = [];
                foreach ($lenderConfigs as $config) {
                    $allConfigs[$config['lender_id']] = [
                        'lender_id' => $config['lender_id'],
                        'lender_name' => $config['lender_name'],
                        'config' => json_decode($config['config'], true)
                    ];
                }

                $this->cache->set($this->redisKey, json_encode($allConfigs));
                $this->logger->debug("ALL Lender Configuration Cache Has Been SET!");
            } else {
                $allConfigs = [];
                foreach ($cacheHit as $config) {
                    $allConfigs[$config['lender_id']] = [
                        'lender_id' => $config['lender_id'],
                        'lender_name' => $config['lender_name'],
                        'config' => $config['config']
                    ];
                }
            }

            if (
                !empty($this->variables->lender_id) &&
                array_key_exists($this->variables->lender_id, $allConfigs)
            ) {
                $this->logger->debug("One Lender Configuration READ from Cache!");

                return json_decode(json_encode($allConfigs[$this->variables->lender_id], JSON_THROW_ON_ERROR));
            } else {
                throw new Exception("Lender Configuration READ Cache FAILED!");
            }
        } catch (Throwable $t) {
            $this->logger->error($t->getMessage());
            throw new Exception($t->getMessage());
        }
    }

    /**
     * @inheritDoc
     */
    function shape(mixed $data): ?stdClass
    {
        throw new Exception('Not implemented');
    }
}
