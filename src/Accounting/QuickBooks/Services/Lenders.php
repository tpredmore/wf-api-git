<?php

namespace WF\API\Accounting\QuickBooks\Services;

use WildFire\QuickBooks\Client;
use MySQL;
use Log;
use Cache;

/**
 * Class Lenders
 * @package WildFire\QuickBooks
 */
class Lenders {

    protected Client $client;
    protected MySQL $sql;
    protected Cache $cache;
    protected Log $logger;

    public function __construct() {
        $this->client = new Client;
        $this->sql = new MySQL;
        $this->cache = new Cache;
        $this->logger = new Log;
    }

    /**
     * @return array{success: bool, error: string, data: mixed}
     */
    public function syncLenders(): array {
        try {
            $data = $this->sql->call('wildfire_accounting', 'qb_sync_lenders');

            $this->cache->bulk_delete('quickbooks_lender');

            $success = true;
            $error = '';
        } catch (\Exception $e) {
            $success = false;
            $error = 'ERROR: QuickBooks Sync Lenders failed with error -  ' . $e->getMessage();
            $data = [];
            $this->logger->error($error);
        }

        return [
          'success' => $success,
          'error' => $error,
          'data' => $data
        ];
    }

    /**
     * @return array{success: bool, error: string, data: mixed}
     */
    public function getAllLenders(): array {
        try {
        $array = $this->cache->get('quickbooks_lenders');
        if (!isset($array)) {
            $data = $this->sql->call('wildfire_accounting', 'qb_get_lenders');
            $this->cache->set('quickbooks_lenders',json_encode($data),true);
        } else {
            $data = json_decode($array, true);
        }

            $success = true;
            $error = '';
        } catch (\Exception $e) {
            $success = false;
            $error = 'ERROR: QuickBooks Get All Lenders failed with error -  ' . $e->getMessage();
            $data = [];
            $this->logger->error($error);
        }

        return [
          'success' => $success,
          'error' => $error,
          'data' => $data
        ];
    }

    /**
     * @param $json
     *
     * @return array{success: bool, error: string, data: mixed}
     */
    public function updateLenderConfigs($json): array {
        try {
            $data = $this->sql->call('wildfire_accounting', 'qb_update_lender_configs',[$json->id,$json->data,$json->username]);

            $this->cache->bulk_delete('quickbooks_lender');

            $success = true;
            $error = '';
        } catch (\Exception $e) {
            $success = false;
            $error = 'ERROR: QuickBooks Update Lender configs failed with error -  ' . $e->getMessage();
            $data = [];
            $this->logger->error($error);
        }

        return [
          'success' => $success,
          'error' => $error,
          'data' => $data
        ];
    }

    /**
     * @param $json
     *
     * @return array{success: bool, error: string, data: mixed}
     */
    public function updateProductConfigs($json): array {
        try {
            $data = $this->sql->call('wildfire_accounting', 'qb_update_lender_products',[$json->id,$json->data,$json->username]);

            $this->cache->bulk_delete('quickbooks_lender');

            $success = true;
            $error = '';
        } catch (\Exception $e) {
            $success = false;
            $error = 'ERROR: QuickBooks Update Lender product configs failed with error -  ' . $e->getMessage();
            $data = [];
            $this->logger->error($error);
        }

        return [
          'success' => $success,
          'error' => $error,
          'data' => $data
        ];
    }
}
