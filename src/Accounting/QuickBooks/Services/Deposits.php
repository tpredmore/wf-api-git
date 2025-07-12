<?php

namespace WF\API\Accounting\QuickBooks\Services;

use WF\API\Accounting\QuickBooks\Traits\LendersTrait;
use WF\API\Accounting\QuickBooks\Traits\LienholderTrait;
use WF\API\Accounting\QuickBooks\Traits\QuickBooksTrait;

use WildFire\QuickBooks\Client;
use MySQL;
use Log;
use Cache;

class Deposits {

    use LendersTrait, LienholderTrait, QuickBooksTrait;

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
     * @param $payload
     *
     * @return array
     */
    public function makeDeposits($payload): array {
        $success = true;
        $error = '';
        foreach($payload->data as $deposit) {
            $submit = [];
            $split_submit = [];
            $application_id = $deposit[0];
            $applicant_name = $deposit[2];
            $lender_name = $deposit[3];
            $booked_date = $this->formatDate($deposit[18]);
            $lender_id = $deposit[20];
            $lender_config = json_decode($this->getLenderConfigById($lender_id));
            $lender_product_config = json_decode($this->getLenderProductConfigById($lender_id));
            $description = $application_id . ' ' . $applicant_name;

            foreach ($deposit as $key => $value) {
                if ($value === '0.00') {
                    continue;
                }

                switch($key) {
                    case '4' :  // PAYOFF
                        if(empty($lender_config->adjusted_payoff)) {$success = false; $error = 'No PAYOFF account set for ' . $lender_name . '<br>'; break;} //  If no PAYOFF account, then fail
                        $submit[] = $this->addLine($lender_config->customer_account, $lender_config->adjusted_payoff, $value, $description . ' - PAYOFF');
                        break;
                    case '5' : // MEMBERSHIP FEE
                        if($lender_config->exclude_membership) break;
                        if(empty($lender_config->membership_account)) {$success = false; $error = 'No MEMBERSHIP FEE account set for ' . $lender_name . '<br>'; break;} //  If no MEMBERSHIP FEE account, then fail
                        if ($lender_config->split_deposits && !$lender_config->move_membership_fee) {
                            $submit[] = $this->addLine($lender_config->customer_account, $lender_config->membership_account, $value, $description . ' - MEMBERSHIP FEE');
                        } else {
                            $split_submit[] = $this->addLine($lender_config->customer_account, $lender_config->membership_account, $value, $description . ' - MEMBERSHIP FEE');
                        }
                        break;
                    case '6' :  // LENDER FEES
                        if(empty($lender_config->income_account)) {$success = false; $error = 'No LENDER FEE account set for ' . $lender_name . '<br>'; break;} //  If no LENDER FEE account, then fail
                        if(!$lender_config->split_deposits) {
                            $submit[] = $this->addLine($lender_config->customer_account, $lender_config->income_account, $value, $description . ' - LENDER FEE');
                        } else {
                            $split_submit[] = $this->addLine($lender_config->customer_account, $lender_config->income_account, $value, $description . ' - LENDER FEE');
                        }
                        if($lender_config->broker_exception !== 0) {
                            if(empty($lender_config->exception_account)) {$success = false; $error = 'No BROKER EXCEPTION account set for ' . $lender_name . '<br>'; break;} //  If no BROKER EXCEPTION account, then fail
                            $split_submit[] = $this->addLine($lender_config->customer_account, $lender_config->exception_account, -abs($lender_config->broker_exception), $description . ' - BROKER EXCEPTION');
                        }
                        if($lender_config->broker_fee !== 0) {
                            if(empty($lender_config->fees_account)) {$success = false; $error = 'No BROKER FEE account set for ' . $lender_name . '<br>'; break;} //  If no BROKER FEE account, then fail
                            $submit[] = $this->addLine($lender_config->customer_account, $lender_config->fees_account, $lender_config->broker_fee, $description . ' - BROKER FEE');
                        }
                        break;
                    case '7' :  //  VSI FEES
//                        $submit[] = $this->addLine($lender_config->customer_account, $lender_config->title_account, $value, 'VSI: '. $description);
                        break;
                    case '8' :  //  TITLE FEES
                        if($lender_config->exclude_title_fee) break;
                        if(empty($lender_config->title_account)) {$success = false; $error = 'No TITLE FEE account set for ' . $lender_name . '<br>'; break;} //  If no TITLE FEE account, then fail
                        $submit[] = $this->addLine($lender_config->customer_account, $lender_config->title_account, $value, $description . ' - TITLE FEE');
                        break;
                    case '9' :  // STAMP TAX
                        if($lender_config->exclude_stamp_tax) break;
                        if(empty($lender_config->stamp_tax_account)) {$success = false; $error = 'No STAMP TAX account set for ' . $lender_name . '<br>'; break;} //  If no STAMP TAX account, then fail
                        $submit[] = $this->addLine($lender_config->customer_account, $lender_config->stamp_tax_account, $value, $description . ' - STAMP TAX');
                        break;
                    case '10' :  // SALES TAX
                        if(empty($lender_config->tax_account)) {$success = false; $error = 'No SALES TAX account set for ' . $lender_name . '<br>'; break;} //  If no SALES TAX account, then fail
                        $submit[] = $this->addLine($lender_config->customer_account, $lender_config->tax_account, $value, $description . ' - SALES TAX');
                        break;
                    case '11' : // DOC FEES
                        if(empty($lender_config->gravity_doc_account)) {$success = false; $error = 'No DOC FEE account set for ' . $lender_name . '<br>'; break;} //  If no DOC FEE account, then fail
                        $submit[] = $this->addLine($lender_config->customer_account, $lender_config->gravity_doc_account, $value, $description . ' - DOC FEE');
                        break;
                    case '12' :  // GAP
                        if(empty($lender_product_config->gap_account)) {$success = false; $error = 'No GAP account set for ' . $lender_name . '<br>'; break;} //  If no GAP account, then fail
                        $submit[] = $this->addLine($lender_config->customer_account, $lender_product_config->gap_account, $value, $description . ' - GAP');
                        break;
                    case '13' :  // VSC
                        if(empty($lender_product_config->vsc_account)) {$success = false; $error = 'No VSC account set for ' . $lender_name . '<br>'; break;} //  If no VSC account, then fail
                        $submit[] = $this->addLine($lender_config->customer_account, $lender_product_config->vsc_account, $value, $description . ' - VSC');
                        break;
                    case '14' :  // EDGE
                        if(empty($lender_product_config->edge_account)) {$success = false; $error = 'No EDGE account set for ' . $lender_name . '<br>'; break;} //  If no EDGE account, then fail
                        $submit[] = $this->addLine($lender_config->customer_account, $lender_product_config->edge_account, $value, $description . ' - EDGE');
                        break;
                    case '15' :  // LIFE
                        if(empty($lender_product_config->life_account)) {$success = false; $error = 'No LIFE account set for ' . $lender_name . '<br>'; break;} //  If no LIFE account, then fail
                        $submit[] = $this->addLine($lender_config->customer_account, $lender_product_config->life_account, $value, $description . ' - LIFE');
                        break;
                    case '16' :  // ADR
                        if(empty($lender_product_config->adr_account)) {$success = false; $error = 'No ADR account set for ' . $lender_name . '<br>'; break;} //  If no ADR account, then fail
                        $submit[] = $this->addLine($lender_config->customer_account, $lender_product_config->adr_account, $value, $description . ' - ADR');
                        break;
                }
            }
            $this->logger->debug(print_r($submit, true));


            if($success) {
                $submit_results = $this->submitDeposit($description, $lender_config->deposit_account, $booked_date, $submit, $application_id);
                if (!$submit_results['success']) {
                    return [
                      'success' => false,
                      'error' => $description . ':  ' . $submit_results['error'],
                      'data' => []
                    ];
                }
                else {
                    if (!empty($split_submit)) {
                        $split_submit_results = $this->submitDeposit($description, $lender_config->deposit_account, $booked_date, $split_submit, $application_id);
                        if (!$split_submit_results['success']) {
                            return [
                              'success' => false,
                              'error' => $description . ':  ' . $split_submit_results['error'],
                              'data' => []
                            ];
                        }
                    }
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
     * @param $entity
     * @param $account
     * @param $amount
     * @param $description
     *
     * @return array
     */
    private function addLine($entity, $account, $amount, $description): array {
        return [
          "Amount" => number_format($amount, 2, '.', ''),
          "DetailType" => "DepositLineDetail",
          "DepositLineDetail" =>
            [
              "AccountRef" =>
                [
                  "value" => $account,
                ],
              "Entity" =>
                [
                  "value" => $entity,
                ]
            ],
          "Description" => $description
        ];
    }

    /**
     * @param $note
     * @param $bank_id
     * @param $txn_date
     * @param $lines
     * @param $app_id
     *
     * @return array
     */
    public function submitDeposit($note, $bank_id, $txn_date, $lines, $app_id): array {
        $success = true;
        $error = '';
        $data = '';

        $deposit = [
          "DepositToAccountRef" =>
            [
              "value" => $bank_id,
            ],
          "TxnDate" => $txn_date,
          "PrivateNote" => $note,
          "Line" => $lines
        ];

        $send = $this->client->createDeposit($deposit);
        $result = $this->client->sendToQuickBooks($send);
        $get_error = $this->client->getLastError();

        if ($get_error) {
            $xml = simplexml_load_string($get_error->getResponseBody('detail'));
            $error = $xml->Fault->Error->Detail;

            $this->logger->error('ERROR: QuickBooks submit failed - '. $error);
            $success = false;
        } else {
            $transaction = $this->recordTransaction($result,$app_id,'Deposit');
            $this->logger->error('Logging the transaction: '. print_r($transaction,true));

        }

        return [
          'success' => $success,
          'error' => $error,
          'data' => $data
        ];
    }
}