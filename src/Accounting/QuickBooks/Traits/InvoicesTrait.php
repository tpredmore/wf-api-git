<?php

namespace WF\API\Accounting\QuickBooks\Traits;

trait InvoicesTrait {

    /**
     * @param $month
     * @param $vendor_id
     *
     * @return mixed
     */
    public function getInvoice($month, $vendor_id): mixed {
        return $this->sql->call('wildfire_accounting', 'qb_get_invoice', [$month, $vendor_id]);
    }

    /**
     * Returns the month number from a given date string.
     *
     * @param string $date A valid date string (e.g., "2023-10-05").
     * @return int The month number (1-12).
     */
    public function getCurrentMonthNumber(string $date): int {
        $timestamp = strtotime($date);

        return (int) date('n', $timestamp);
    }

    /**
     * @param $data
     *
     * @return array
     */
    public function getLines($data): array {
        $lines = [];
        if(!is_array($data)) {
            $data[] = $data;
        }
        foreach ($data as $item) {
            if($item->DetailType === 'SubTotalLineDetail') continue;
            $lines[] = [
              "Amount" => (float)$item->Amount,
              "Description" =>  (string)$item->Description,
              "DetailType" => "SalesItemLineDetail",
              "SalesItemLineDetail" => [
                "ItemRef" => [
                  "value" => 4
                ],
                "Qty" => 1.00,
                "UnitPrice" => (float)$item->SalesItemLineDetail->UnitPrice,
                "ServiceDate" => (string)$item->SalesItemLineDetail->ServiceDate
              ],
            ];
        }
        return $lines;
    }

}