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
 * class WildFire User Authorization Matrix
 *
 * Loads and caches a materialized mapping of users → roles, titles, and groups.
 * Also builds reverse indexes (e.g., roles → [emails], groups → [emails]).
 *
 * @implements DataSourceInterface
 */
class WildFireUserAuthorizationMatrix implements DataSourceInterface
{
    use Database;

    private string $sourceDbName = 'wildfire_settings';
    private string $redisKey = 'Guardrail:UserAuthorizationMatrix';

    public function __construct(
        protected MySql $dbConn,
        protected Cache $cache,
        protected Log   $logger,
    ) {
    }

    /**
     * @inheritdoc
     *
     * @throws Exception
     */
    public function fetch(mixed $criteria = null): stdClass
    {
        return $this->getFromCacheOrLoad();
    }

    /**
     * @return stdClass
     * @throws Exception
     */
    private function getFromCacheOrLoad(): stdClass
    {
        try {
            $cached = $this->cache->get($this->redisKey, $returnAsArray = true);
            if (is_array($cached) && !empty($cached)) {
                $this->logger->debug("User Authorization Matrix READ from cache");
                return json_decode(json_encode($cached));
            }

            $matrix = $this->loadMatrix();
            $this->cache->set($this->redisKey, json_encode($matrix));
            $this->logger->debug("User Authorization Matrix cache SET");

            return $matrix;
        } catch (Throwable $e) {
            throw new Exception("UserAuthorizationMatrix cache failed: " . $e->getMessage());
        }
    }

    /**
     * @return stdClass
     * @throws Exception
     */
    private function loadMatrix(): stdClass
    {
        $data = $this->dbConn->call($this->sourceDbName, 'wf_guardrail_user_Authorization_matrix', []);
        if (!$data || !is_array($data)) {
            throw new Exception("SPROC wf_guardrail_user_Authorization_matrix returned no data");
        }

        $users = [];
        $roles = [];
        $groups = [];
        $titles = [];

        foreach ($data as $row) {
            $email = strtolower(trim($row['email'] ?? ''));
            if (!$email) continue;

            $role = trim($row['role'] ?? '');
            $group = trim($row['group_name'] ?? '');
            $title = trim($row['title'] ?? '');

            if (!isset($users[$email])) {
                $users[$email] = [
                    'role' => [],
                    'group' => [],
                    'title' => []
                ];
            }

            if ($role && !in_array($role, $users[$email]['role'])) {
                $users[$email]['role'][] = $role;
                $roles[$role][] = $email;
            }

            if ($group && !in_array($group, $users[$email]['group'])) {
                $users[$email]['group'][] = $group;
                $groups[$group][] = $email;
            }

            if ($title && !in_array($title, $users[$email]['title'])) {
                $users[$email]['title'][] = $title;
                $titles[$title][] = $email;
            }
        }

        return (object)[
            'users' => $users,
            'roles' => $roles,
            'groups' => $groups,
            'titles' => $titles
        ];
    }

    /**
     * @inheritdoc
     */
    public function shape(mixed $data): ?stdClass
    {
        throw new Exception('Not implemented');
    }
}
