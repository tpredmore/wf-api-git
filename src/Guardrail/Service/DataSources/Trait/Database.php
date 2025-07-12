<?php

namespace WF\API\Guardrail\Service\DataSources\Trait;

use Exception;
use Throwable;

/**
 * trait Database
 */
trait Database
{
    /**
     * @param string $sql
     * @param bool $asResource
     * @return mixed
     * @throws Exception
     */
    private function executeSql(
        string $sql,
        bool  $asResource = false,
    ): mixed
    {
        try {
            $queryType = strtoupper(substr($sql, 0, 4));
            if (!in_array($queryType, ['SELE', 'WITH'])) {
                throw new Exception("
                    Trait Database->executeSql() is for SELECTs or WITH() ONLY!"
                );
            }
            $this->dbConn->setHostConnection('');

            $result = $this->dbConn->raw_query($this->db, $sql);
            if (!$result || !is_callable([$result, 'fetch_assoc'])) {
                $this->dbConn->resetHostConnection();
                throw new Exception(
                    "SQL Failed! |$sql"
                );
            }

            if ($asResource === true) {
                return $result;
            }

            $records = [];
            while ($record = $result->fetch_assoc()) {
                $records[] = $record;
            }

            $this->dbConn->resetHostConnection();

            return $records;
        } catch (Throwable $t) {
            throw new Exception("Query Failed!|{$t->getMessage()}");
        }
    }
}
