<?php

namespace WF\API\Accounting\QuickBooks\Services;

use WildFire\QuickBooks\Client;
use MySQL;
use Log;
use Cache;

/**
 * Class Customers
 * @package WildFire\QuickBooks
 */
class Customers {

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

    public function getAllCustomers(): array {
        try {
            $array = $this->cache->get('quickbooks_customers');
            if (!isset($array)) {
                $data = $this->sql->call('wildfire_accounting', 'qb_get_customers');
                $this->cache->set('quickbooks_customers',json_encode($data));
            } else {
                $data = json_decode($array, TRUE);
            }

            $success = true;
            $error = '';
        } catch (\Exception $e) {
            $success = false;
            $error = 'ERROR: QuickBooks Get All Customers failed with error -  ' . $e->getMessage();
            $data = [];
            $this->logger->error($error);
        }

        return [
          'success' => $success,
          'error' => $error,
          'data' => $data
        ];
    }

    function syncCustomers(): array {
        $collection = [];
        $startPosition = 1;
        $maxResults = 1000;

        try {
            $allCustomers = $this->client->getDataService()
              ->FindAll('customer', $startPosition, $maxResults);

            foreach ($allCustomers as $customer) {
                if($customer->Active !== 'true') {
                    continue;
                }
                $collection[] = [
                  'id' => $customer->Id,
                  'customer_name' => $customer->DisplayName,
                  'primary_phone' => $customer->PrimaryPhone->FreeFormNumber ?? '',
                  'email_address' => $customer->PrimaryEmailAddr->Address ?? '',
                  'address' => $customer->BillAddr->Line1 ?? '',
                  'city' => $customer->BillAddr->City ?? '',
                  'state' => $customer->BillAddr->CountrySubDivisionCode ?? '',
                  'zip' => $customer->BillAddr->PostalCode ?? '',
                  'sync_token' => $customer->SyncToken,
                  'creation_time' => date('Y-m-d H:i:s',strtotime($customer->MetaData->CreateTime)),
                  'updated_at' => date('Y-m-d H:i:s',strtotime($customer->MetaData->LastUpdatedTime))
                ];
            }

            $this->sql->call('wildfire_accounting', 'qb_sync_customers', json_encode($collection));

            $this->cache->bulk_delete('quickbooks_customers');

            $success = true;
            $error = '';
        } catch (\Exception $e) {
            $success = false;
            $error = 'ERROR: QuickBooks Get All Customers failed with error -  ' . $e->getMessage();
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
     *
     * @return array
     */
    public function getCustomersDropdown(): array {
        try {
            $array = $this->cache->get('quickbooks_customers_dropdown');
            if (!isset($array)) {
                $dropdowns = $this->sql->call('wildfire_accounting', 'qb_get_customers_dropdown');
                $this->cache->set('quickbooks_customers_dropdown',json_encode($dropdowns));
            } else {
                $dropdowns = json_decode($array, true);
            }

            $success = true;
            $error = '';
        } catch (\Exception $e) {
            $success = false;
            $error = 'ERROR: QuickBooks getting Customers dropdown data failed with error -  ' . $e->getMessage();
            $dropdowns = [];
            $this->logger->error($error);
        }

        return [
          'success' => $success,
          'error' => $error,
          'data' => $dropdowns[0]
        ];
    }
}
