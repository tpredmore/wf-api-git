<?php

namespace WF\API\Accounting\QuickBooks\Services;

use WF\API\Accounting\QuickBooks\Traits\LendersTrait;
use WF\API\Accounting\QuickBooks\Traits\LienholderTrait;
use WF\API\Accounting\QuickBooks\Traits\QuickBooksTrait;

use WildFire\QuickBooks\Client;
use MySQL;
use Log;
use Cache;

class Checks {

    use LendersTrait, LienholderTrait, QuickBooksTrait;

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

    /**
     * @param $payload
     *
     * @return array
     */
    public function makeChecks($payload): array {
        $success = true;
        $error = '';

        foreach ($payload->data as $checks) {
            $submit = [];
            $total=0.00;
            $application_id = $checks[0];
            $applicant_name = $checks[2];
            $lender_name = $checks[3];
            $lender_id = $checks[11];
            $lienholder_id = $checks[12];
            $booked_date = $this->formatDate($checks[13]);
            $lender_config = json_decode($this->getLenderConfigById($lender_id));
            $description = $application_id . ' ' . $applicant_name;

            foreach ($checks as $key => $value) {
                if ($value === '0.00') {
                    continue;
                }

                switch($key) {
                    case '4' :  //  PAYOFF
                        if(empty($lender_config->checking_account)) {$success = false; $error = 'No PAYOFF account set for ' . $lender_name . '<br>'; break;} //  If no PAYOFF account, then fail
                        $submit[] = $this->addLine($lender_config->checking_account, $value, 'PAYOFF: ' . $description);
                        $total += $value;
                        break;
                    case '5' :  //  CUSTOMER DEPOSIT
                        if(empty($lender_config->cash_down_account)) {$success = false; $error = 'No CUSTOMER DEPOSIT account set for ' . $lender_name . '<br>'; break;} //  If no CUSTOMER DEPOSIT account, then fail
                        $submit[] = $this->addLine($lender_config->cash_down_account, $value, 'CUSTOMER DEPOSIT: ' . $description);
                        $total += $value;
                        break;
                    case '9' :  //  VIN
                        $submit[] = $this->addLine($lender_config->cash_down_account, 0, 'VIN# ' . $value);
                        break;
                }
            }

            if($success) {
                $submit_results = $this->submitChecks($description, $lienholder_id, $lender_config->checking_account, $booked_date, $total, $submit, $application_id);
                if (!$submit_results['success']) {
                    return [
                      'success' => false,
                      'error' => $description . ':  ' . $submit_results['error'],
                      'data' => []
                    ];
                }
            }
        }

        return [
          'success' => $success,
          'error' => preg_replace('/<br\s*\/?>$/i', '', $error),
          'data' => []
        ];
    }

    /**
     * @param $account
     * @param $amount
     * @param $description
     *
     * @return array
     */
    private function addLine($account, $amount, $description): array {
        return [
          "DetailType" => "AccountBasedExpenseLineDetail",
          "AccountBasedExpenseLineDetail" => [
            "AccountRef" => [
              "name" => "",
              "value" => $account
            ]
          ],
          "Description" => $description,
          "Amount" => number_format($amount,2, '.', ''),
        ];
    }

    /**
     * @param $note
     * @param $vendor_id
     * @param $check_id
     * @param $txn_date
     * @param $total
     * @param $lines
     * @param $app_id
     *
     * @return array
     */
    public function submitChecks($note, $vendor_id, $check_id, $txn_date, $total, $lines, $app_id): array {
        $success = true;
        $error = '';
        $data = '';

        $check = [
          "PaymentType" => "Check",
          "TxnDate" => $txn_date,
          "PrintStatus" => "NeedToPrint",
          "EntityRef" => [
            "type" => "Vendor",
            "value" => $vendor_id
          ],
          "AccountRef" => [
            "name" => "Bank",
            "value" => $check_id
          ],
          "PrivateNote" => $note,
          "TotalAmt" => $total,
          "Line" => $lines
        ];

        $send = $this->client->createPurchase($check);
        $result = $this->client->sendToQuickBooks($send);
        $get_error = $this->client->getLastError();

        if ($get_error) {
            $xml = simplexml_load_string($get_error->getResponseBody('detail'));
            $error = $xml->Fault->Error->Detail;

            $this->logger->error('ERROR: QuickBooks submit failed - '. $error);
            $success = false;
        } else {
            $transaction = $this->recordTransaction($result,$app_id,'Check');
            $this->logger->error('Logging the transaction: '. print_r($transaction,true));

        }

        return [
          'success' => $success,
          'error' => $error,
          'data' => $data
        ];
    }
}