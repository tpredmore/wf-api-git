<?php

namespace WF\API\Accounting\QuickBooks\Services;

use WildFire\QuickBooks\Client;
use MySQL;
use Log;
use Cache;

/**
 * Class Accounts
 * @package WildFire\QuickBooks
 */
class Accounts {

    protected $client;
    protected $sql;
    protected $cache;
    protected $logger;

    public function __construct() {
        $this->client = new Client;
        $this->sql = new MySQL;
        $this->cache = new Cache;
        $this->logger = new Log;
    }

    /**
     * @return array{success: bool, error: string, data: mixed}
     */
    public function getAllAccounts(): array {
        try {
            $array = $this->cache->get('quickbooks_accounts');
            if (!isset($array)) {
                $data = $this->sql->call('wildfire_accounting', 'qb_get_accounts');
                $this->cache->set('quickbooks_accounts', json_encode($data));
            }
            else {
                $data = json_decode($array, TRUE);
            }

            $success = true;
            $error = '';
        } catch (\Exception $e) {
            $success = false;
            $error = 'ERROR: QuickBooks Get All Accounts failed with error -  ' . $e->getMessage();
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
     * @return array{success: bool, error: string, data: array}
     */
    public function syncAccounts(): array {
        $collection = [];
        $startPosition = 1;
        $maxResults = 1000;

        try {
            $allAccounts = $this->client->getDataService()
              ->FindAll('account', $startPosition, $maxResults);

            foreach ($allAccounts as $account) {
                if($account->Active !== 'true') {
                    continue;
                }

                $collection[] = [
                  'id' => $account->Id,
                  'parent_id' => $account->ParentRef,
                  'sub_account' => $account->SubAccount,
                  'account_name' => str_replace(":"," - ",$account->FullyQualifiedName),
                  'active' => $account->Active,
                  'account_type' => $account->AccountType . ' - ' . $account->AccountSubType,
                  'classification' => $account->Classification,
                  'sync_token' => $account->SyncToken,
                  'creation_time' => date('Y-m-d H:i:s',strtotime($account->MetaData->CreateTime)),
                  'updated_at' => date('Y-m-d H:i:s',strtotime($account->MetaData->LastUpdatedTime))
                ];
            }

            $this->sql->call('wildfire_accounting', 'qb_sync_accounts', json_encode($collection));

            $this->cache->bulk_delete('quickbooks_accounts');

            $success = true;
            $error = '';
        } catch (\Exception $e) {
            $success = false;
            $error = 'ERROR: QuickBooks Sync Accounts failed with error -  ' . $e->getMessage();
            $collection = [];
            $this->logger->error($error);
        }

        return [
          'success' => $success,
          'error' => $error,
          'data' => $collection
        ];
    }

    /**
     * @param $json
     *
     * @return array
     */
    public function getAccountsDropdown($json): array {
        try {
            $array = $this->cache->get('quickbooks_accounts_dropdown');
            if (!isset($array)) {
                $dropdowns = $this->sql->call('wildfire_accounting', 'qb_get_accounts');
                $this->cache->set('quickbooks_accounts_dropdown', json_encode($dropdowns));
            }
            else {
                $dropdowns = json_decode($array, TRUE);
            }

            $groupedData = [];
            // Loop through each dropdown record and group by type
            foreach ($dropdowns as $dropdown) {
                $type = $dropdown['classification'];

                // Initialize the type if not set
                if (!isset($groupedData[$type])) {
                    $groupedData[$type] = [
                      'id' => [],
                      'name' => []
                    ];
                }

                // Append the current id and name to the corresponding type
                $groupedData[$type]['id'][] = $dropdown['id'];
                $groupedData[$type]['name'][] = $dropdown['account_name'];
            }
            // Now, transform the grouped data into the desired format
            $output = array_map(function ($data) {
                return [
                  'id' => implode(",", $data['id']),
                  'name' => implode(",", $data['name'])
                ];
            }, $groupedData);

            $success = true;
            $error = '';
        } catch (\Exception $e) {
            $success = false;
            $error = 'ERROR: QuickBooks get Account Dropdown failed with error -  ' . $e->getMessage();
            $output = [];
            $this->logger->error($error);
        }

        return [
          'success' => $success,
          'error' => $error,
          'data' => $output
        ];
    }
}
