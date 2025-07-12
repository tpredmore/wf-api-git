<?php

namespace WF\API\Accounting\QuickBooks\Services;

use WildFire\QuickBooks\Client;
use MySQL;
use Log;
use Cache;

/**
 * Class Lienholders
 * @package WildFire\QuickBooks
 */
class Lienholders {

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
    public function getAllLienholders(): array {
        try {
            $array = $this->cache->get('quickbooks_lienholders');
            if (!isset($array)) {
                $data = $this->sql->call('wildfire_accounting', 'qb_get_lienholders',0);
                $this->cache->set('quickbooks_lienholders',json_encode($data),true);
            } else {
                $data = json_decode($array, true);
            }

            $success = true;
            $error = '';
        } catch (\Exception $e) {
            $success = false;
            $error = 'ERROR: QuickBooks Get All Lienholders failed with error -  ' . $e->getMessage();
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
    public function syncLienholders(): array {
        $collection = [];
        $startPosition = 1;
        $maxResults = 1000;

        try {
            $allLienholders = $this->client->getDataService()
              ->FindAll('vendor', $startPosition, $maxResults);

            $this->sql->call('wildfire_accounting', 'qb_sync_lienholders', json_encode(self::buildCollection($allLienholders)));

            $this->cache->bulk_delete('quickbooks_lienholders');

            $success = true;
            $error = '';
        } catch (\Exception $e) {
            $success = false;
            $error = 'ERROR: QuickBooks Sync Lienholders failed with error -  ' . $e->getMessage();
            $this->logger->error($error);
        }

        return [
          'success' => $success,
          'error' => $error,
          'data' => $collection
        ];
    }

    /**
     * @return array{success: bool, error: string, data: array}
     */
    public function addLienholder($json): array {
        $data = json_decode($json);

        if ($id = $this->client->getDataService()->query("select * from vendor where DisplayName LIKE '%$data->name%'")) {
            $success = false;
            $error = $data->name . ' already exists in QuickBooks as:  ' . $id[0]->DisplayName . ' Names MUST be unique.';
            $collection = [];
            $this->logger->error($error);
        }
        else {
            try {
                $payload =  [
                  "CompanyName" => $data->name,
                  "DisplayName" => $data->name,
                  "PrintOnCheckName" => $data->name,
                  "Active" => $data->active ? 'true' : 'false',
                  "PrimaryPhone" => ["FreeFormNumber" => $data->phone],
                  "Mobile" => ["FreeFormNumber" => ''],
                  "PrimaryEmailAddr" => ["Address" => ''],
                  "BillAddr" => [
                    "Line1" => $data->attention,
                    "Line2" => $data->address,
                    "City" => $data->city,
                    "CountrySubDivisionCode" => $data->state,
                    "PostalCode" => $data->zip
                  ]];

                $prepare = $this->client->createLienholder($payload);

                $newLienholder[] = $this->client->getDataService()->Add($prepare);
                $this->logger->debug('COCK SUCKER:  '. print_r($newLienholder, true));

                $collection = self::buildCollection($newLienholder);
                $this->logger->debug('COCK SUCKER:  '. print_r($collection, true));

                //TODO add error handling
                $this->sql->call('wildfire_accounting', 'qb_sync_lienholders', json_encode($collection));

                $this->cache->bulk_delete('quickbooks_lienholders');

                $success = true;
                $error = '';
            } catch (\Exception $e) {
                $success = false;
                $error = 'ERROR: QuickBooks Lienholders build collection failed with error -  ' . $e->getMessage();
                $collection = [];
                $this->logger->error($error);
            }
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
     * @return array{success: bool, error: string, data: mixed}
     */
    public function updateLienholder($json): array {
        try {
            $data = $this->sql->call('wildfire_accounting', 'qb_update_lienholders', $json);

            $this->cache->bulk_delete('quickbooks_lienholders');

            $success = true;
            $error = '';
        } catch (\Exception $e) {
            $success = false;
            $error = 'ERROR: QuickBooks Update Lienholder failed with error -  ' . $e->getMessage();
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
    public function toggleActive($json): array {
        try {
            $data = $this->sql->call('wildfire_accounting', 'qb_toggle_lienholders', [$json->id, $json->active]);

            $this->cache->bulk_delete('quickbooks_lienholders');

            $success = true;
            $error = '';
        } catch (\Exception $e) {
            $success = false;
            $error = 'ERROR: QuickBooks Toggle Active Lienholder failed with error -  ' . $e->getMessage();
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
     *
     * @return array
     */
    public function getLienholdersDropdown(): array {
        try {
            $array = $this->cache->get('quickbooks_lienholders_dropdown');
            if (!isset($array)) {
                $dropdowns = $this->sql->call('wildfire_accounting', 'qb_get_lienholders_dropdown');
                $this->cache->set('quickbooks_lienholders_dropdown',json_encode($dropdowns));
            } else {
                $dropdowns = json_decode($array, TRUE);
            }

            $success = true;
            $error = '';
        } catch (\Exception $e) {
            $success = false;
            $error = 'ERROR: QuickBooks getting Lienholders dropdown data failed with error -  ' . $e->getMessage();
            $dropdowns = [];
            $this->logger->error($error);
        }

        return [
          'success' => $success,
          'error' => $error,
          'data' => $dropdowns[0]
        ];
    }

    private function buildCollection($payload): array {
        try {
            $collection = [];
            foreach ($payload as $item) {
                if(empty($item->BillAddr->Line2)) {
                    $attn = '';
                    $address = $item->BillAddr->Line1 ?? '';
                } else {
                    $attn = $item->BillAddr->Line1 ?? '';
                    $address = $item->BillAddr->Line2 ?? '';
                }

                $collection[] = [
                  'id' => $item->Id,
                  'name' => $item->DisplayName,
                  'phone' => $item->PrimaryPhone->FreeFormNumber ?? '',
                  'attention' => $attn,
                  'address' => $address,
                  'city' => $item->BillAddr->City ?? '',
                  'state' => $item->BillAddr->CountrySubDivisionCode ?? '',
                  'zip' => $item->BillAddr->PostalCode ?? '',
                  'sync_token' => $item->SyncToken,
                  'creation_time' => date('Y-m-d H:i:s',strtotime($item->MetaData->CreateTime)),
                  'updated_at' => date('Y-m-d H:i:s',strtotime($item->MetaData->LastUpdatedTime))
                ];
            }
        } catch (\Exception $e) {
            $error = 'ERROR: QuickBooks Lienholders build collection failed with error -  ' . $e->getMessage();
            $collection = [];
            $this->logger->error($error);
        }

        return  $collection;
    }
}
