<?php

namespace WF\API\Accounting\QuickBooks\Traits;

use DateTime;

trait QuickBooksTrait {

    /**
     * @param $date
     *
     * @return \DateTime|false|string
     */
    public function formatDate($date): DateTime|false|string {
        // Create a DateTime object from the given date string.
        // 'F j, Y' corresponds to the format: Full month name, day of month without leading zeros, and a four-digit year.
        $date = DateTime::createFromFormat('F j, Y', $date);
        if ($date) {
            return $date->format('Y-m-d');
        } else {
            return $date;
        }

    }

    /**
     * @param $term
     *
     * @return mixed
     */
    public function getTermsId($term): mixed {
        $terms = 'NET ' . $term;
        return $this->client->getDataService()->query("select Id from Term where Name = '$terms'")[0]->Id;
    }


    /**
     * @param $result
     * @param $app_id
     * @param $type
     *
     * @return array
     */
    private function recordTransaction($result, $app_id, $type): array {
        $results = [];
        $lines = [];
        $success = true;
        $error = '';

        if(is_object($result)) {
            $result = simplexml_load_string($this->client->formatXmlResponse($result));
        }

        try {
            $results['txn_type'] = $type;
            $results['txn_id'] = (int)$result->Id;
            $results['txn_date'] = (string)$result->TxnDate;
            $results['bank_id'] = (int)$result->DepositToAccountRef ?? 0;
            $results['vendor_id'] = (int)$result->EntityRef ?? 0;
            $results['total'] = (float)$result->TotalAmt;

            if (!is_array($result->Line)) {
                $result->Line[] = (string)$result->Line;
            }

            switch (strtolower($type)) {
                case 'deposit':
                    foreach ($result->Line as $item) {
                        if (!empty($item->Id)) {
                            $lines[] = [
                              'Id' => (int)$item->Id,
                              'LineNum' => (int)$item->LineNum,
                              'Description' => (string)$item->Description,
                              'CustomerId' => (int)$item->DepositLineDetail->Entity,
                              'Amount' => (float)$item->Amount,
                              'DetailType' => (string)$item->DetailType,
                              'AccountRef' => (int)$item->DepositLineDetail->AccountRef,
                            ];
                        }
                    }
                    break;
                case 'check':
                    foreach ($result->Line as $item) {
                        if (!empty($item->Id)) {
                            $lines[] = [
                              'Id' => (int)$item->Id,
                              'Description' => (string)$item->Description,
                              'CustomerId' => (int)$item->AccountBasedExpenseLineDetail->AccountRef,
                              'Amount' => (float)$item->Amount,
                              'DetailType' => (string)$item->DetailType,
                              'AccountRef' => 0,
                            ];
                        }
                    }
                    break;
                case 'credit':
                    foreach ($result->Line as $item) {
                        if (!empty($item->Id)) {
                            $lines[] = [
                              'Id' => (int)$item->Id,
                              'LineNum' => (int)$item->LineNum,
                              'Description' => (string)$item->Description,
                              'ServiceDate' => (string)$item->SalesItemLineDetail->ServiceDate,
                              'Amount' => (float)$item->Amount,
                              'UnitPrice' => (float)$item->SalesItemLineDetail->UnitPrice,
                              'AccountRef' => (int)$item->DepositLineDetail->AccountRef,
                            ];
                        }
                    }
                    break;
                case 'invoice':
                    foreach ($result->Line as $item) {
                        if (!empty($item->Id)) {
                            $lines[] = [
                              'Id' => (int)$item->Id,
                              'LineNum' => (int)$item->LineNum,
                              'Description' => (string)$item->Description,
                              'ServiceDate' => (string)$item->SalesItemLineDetail->ServiceDate,
                              'Amount' => (float)$item->Amount,
                              'UnitPrice' => (float)$item->SalesItemLineDetail->UnitPrice,
                              'AccountRef' => (int)$item->DepositLineDetail->AccountRef,
                            ];
                        }
                    }
                    break;
            }

            $results['txn_lines'] = json_encode($lines);
            $results['application_id'] = $app_id;

            $payload = '['.json_encode($results).']';
            $record = $this->sql->call('wildfire_accounting', 'qb_add_transaction', $payload);
        } catch (\Exception $e) {
            $success = false;
            $error = 'ERROR: QuickBooks adding transaction to the database -  ' . $e->getMessage();
            $this->logger->error($error);
        }

        return [
          'success' => $success,
          'error' => $error,
          'data' => $record ?? [],
        ];
    }
}