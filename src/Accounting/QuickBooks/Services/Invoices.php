<?php

namespace WF\API\Accounting\QuickBooks\Services;

use WF\API\Accounting\QuickBooks\Traits\LendersTrait;
use WF\API\Accounting\QuickBooks\Traits\InvoicesTrait;
use WF\API\Accounting\QuickBooks\Traits\QuickBooksTrait;
use WildFire\QuickBooks\Client;
use MySQL;
use Log;
use Cache;


class Invoices {
    use InvoicesTrait, LendersTrait, QuickBooksTrait;

    protected Client $client;
    protected MySQL $sql;
    protected Cache $cache;
    protected Log $logger;

    /**
     * Constructor
     */
    public function __construct() {
        $this->client = new Client;
        $this->sql = new MySQL;
        $this->cache = new Cache;
        $this->logger = new Log;
    }

    public function makeInvoices($invoices) {
        $collection = [];
        $termsId = $this->getTermsId(30);

        foreach($invoices->data as $invoice) {
            $lender_config = json_decode($this->getLenderConfigById($invoice[20]));

            if(!$lender_config->invoice_broker_fee) continue;

            $application_id = $invoice[0];
            $applicant_name = $invoice[2];

            $collection[$this->getCurrentMonthNumber($invoice[22])][$lender_config->customer_account][] = [
              "ID" => (int)$application_id,
              "Amount" => (float)$invoice[14],
              "Description" =>  $application_id . ' ' . $applicant_name,
              "DetailType" => "SalesItemLineDetail",
              "SalesItemLineDetail" => [
                "ItemRef" => [
                  "value" => 4
                ],
                "Qty" => 1.00,
                "UnitPrice" => (float)$invoice[14],
                "ServiceDate" => $invoice[22]
              ],
            ];
        }

        Log::debug('FUCKWAD FUCKERY:  '.print_r($result,true));
        if (!empty($collection)) {
            foreach($collection as $month => $invoices) {
                foreach($invoices as $customer => $lines) {
                    $app_id = (int)$lines->ID;
                    unset($lines->ID);

                    $update = $this->getInvoice($month, $customer);
                    $txn = null;

                    if($update) {
                        $txn = $this->client->find('invoice', $update['txn_id']);
                        if (is_null($txn)) {
                            $update = false;
                        }
                    }

                    $this->submitInvoice($termsId, $customer, $update, $txn, $lines, $app_id);

                }
            }
        }

    }

    public function submitInvoice($termsId, $customer, $update, $txn, $lines, $app_id): array {
        $success = true;
        $error = '';
        $data = '';

        if ($update) {
            $total_lines = $this->getLines($txn->Line);
            foreach ($lines as $line) {
                array_push($total_lines, $line);
            }

            $invoice = $this->client->updateInvoice($txn, [
              "SyncToken" => (int)$txn->SyncToken + 1,
              "sparse" => true,
              "TxnDate" => $update['txn_date'],
              "CustomerRef" => [
                "value" => $customer
              ],
              "SalesTermRef" => [
                "value" => $termsId
              ],
              "Line" => $total_lines
            ]);
            $result = $this->client->updateQuickBooks($invoice);
        } else {
            $invoice = [
              "CustomerRef" => [
                "value" => $customer
              ],
              "SalesTermRef" => [
                "value" => $termsId
              ],
              "Line" => $lines
            ];

            $send = $this->client->createInvoice($invoice);
            $result = $this->client->sendToQuickBooks($send);
            Log::debug('TRANS MUTHA FUCKIN RESULTS:  '.print_r($result,true));
        }
        $get_error = $this->client->getLastError();

        if ($get_error) {
            $xml = simplexml_load_string($get_error->getResponseBody('detail'));
            $error = $xml->Fault->Error->Detail;

            $this->logger->error('ERROR: QuickBooks submit failed - '. $error);
            $success = false;
        } else {
            $transaction = $this->recordTransaction($result,$app_id,'Invoice');
            $this->logger->error('Logging the transaction: '. print_r($transaction,true));
        }

        return [
          'success' => $success,
          'error' => $error,
          'data' => $data
        ];
    }
}