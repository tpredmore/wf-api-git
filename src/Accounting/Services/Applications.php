<?php

namespace WF\API\Accounting\Services;

use WF\API\Accounting\Traits\AccountingTrait;
use WF\API\Accounting\Traits\ApplicationsTrait;

use MySQL;
use Log;
use Exception;

class Applications{

    use ApplicationsTrait, AccountingTrait;

    private array $keys_collection = [
      'original_lead_id',
      'application_type',
      'accounting_booked_date',
      'deal_sales_price_less_cash_down',
      'deal_cash_down',
      'deal_sales_tax_total',
      'deal_membership_deposit',
      'deal_fl_stamp_tax',
      'deal_title_fee',
      'deal_gap_customer_price',
      'deal_gap_profit',
      'deal_vsc_cost',
      'deal_vsc_profit',
      'deal_deductible_reimbursement_price',
      'deal_deductible_reimbursement_cost',
      'deal_deductible_reimbursement_profit',
      'deal_debt_cancellation_cost',
      'deal_debt_cancellation_profit',
      'deal_edge_cost',
      'deal_edge_profit',
      'deal_legacy_lender_fee_revenue',
      'deal_amount_to_gravity',
      'deal_amount_financed',
      'deal_usalliance_amount_financed',
      'deal_full_payoff',
      'deal_document_fee',
      'deal_vsi',
      'deal_lender',
      'deal_contract_apr',
      'deal_contract_rate',
      'quotes_lienholder_qb_id',
      'selected_decision_lender_id',
      'applicant_first_name',
      'applicant_last_name',
      'applicant_middle_name',
      'applicant_suffix',
      'vehicle_vin',
      'deal_lienholder',
      'quotes_lienholder_address',
      'quotes_lienholder_phone',
      'assigned_sales_user_name',
      'partner_loan_id'
    ];
    private string $user_email;
    private string $user_name;
    protected MySQL $sql;
    protected Log $logger;

    public function __construct($user_email) {
        $this->user_email = $user_email;
        $this->sql = new MySQL;
        $this->logger = new Log;

        if (filter_var($this->user_email, FILTER_VALIDATE_EMAIL)) {
            $this->user_name = $this->getUserNameAttribute($this->user_email);
        } else {
            $this->user_name = $this->user_email;
        }
    }

    /**
     * @param $json
     *
     * @return array
     */
    public function getAllApplications($json): array {
        try {
            $query = $this->sql->call('wildfire_accounting', 'qb_get_applications', $json->queue);
            $success = true;
            $error = '';
        } catch (Exception $e) {
            $success = false;
            $error = 'ERROR: Get Application failed with error -  ' . $e->getMessage();
            $query = [];
            $this->logger->error($error);
        }

        return [
          'success' => $success,
          'error' => $error,
          'data' => $query
        ];
    }


    /**
     * Insert or Update application in the quickbooks_applications table.
     *
     * @param string $json The JSON string to filter.
     *
     * @return array The filtered JSON string.
     */
    public function putApplication(mixed $json): array {
        $allowedKeys = $this->keys_collection;

        if(isset($json->data)) {
            $json = $json->data;
        }

        if(isset($json->application)) {
            $json = $json->application;
        }

        if(is_object($json)) {
            $json = json_encode($json);
        }


        $payload = $this->filterJsonKeys($json, $allowedKeys);

        $data = [
          $payload['original_lead_id'],
          json_encode($payload),
          $this->user_name
        ];

        try {
            $query =  $this->sql->call('wildfire_accounting', 'qb_update_application',$data);
            $success = true;
            $error = '';
        } catch (Exception $e) {
            $success = false;
            $error = 'ERROR: Application Put failed with error -  ' . $e->getMessage();
            $query = [];
            $this->logger->error($error);
        }

        return [
          'success' => $success,
          'error' => $error,
          'data' => $query
          ];
    }

    /**
     * @return array{success: true, error: string, data: mixed}
     */
    public function updateQueues($json): array {

        try {
            $query =  $this->sql->call('wildfire_accounting', 'qb_update_queue', json_encode($json->data));
            $success = true;
            $error = '';
        } catch (Exception $e) {
            $success = false;
            $error = 'ERROR: Update application queue failed with error -  ' . $e->getMessage();
            $query = [];
            $this->logger->error($error);
        }

        return [
          'success' => $success,
          'error' => $error,
          'data' => $query
        ];
    }

    /**
     * @return array
     */
    public function getApplicationCounts(): array {
        try {
            $query =  $this->sql->call('wildfire_accounting', 'qb_get_queue_counts');
            $success = true;
            $error = '';
        } catch (Exception $e) {
            $success = false;
            $error = 'ERROR: Application queue counts failed with error -  ' . $e->getMessage();
            $query = [];
            $this->logger->error($error);
        }

        return [
          'success' => $success,
          'error' => $error,
          'data' => $query
        ];
    }

    /**
     * @param mixed $json
     * @return array
     */
    public function upsertLendingTreeFundingNotification(mixed $json): array
    {
        try {
            if (isset($json->data)) {
                $json = $json->data;
            }
            if (isset($json->application)) {
                $json = $json->application;
            }
            if (is_object($json)) {
                $json = json_encode($json);
            }

            $payload = $this->filterJsonKeys($json, $this->keys_collection);

            $expectedFields = [
                'original_lead_id',
                'partner_loan_id',
                'deal_contract_rate',
                'deal_contract_apr',
                'deal_amount_financed'
            ];
            $payload = $this->filterData($payload, $expectedFields);

            $data[] = [
                'application_id' => (int)$payload['original_lead_id'],
                'partner_loan_id' => (string)$payload['partner_loan_id'],
                'purpose' => 4, // hard coded per LT Documentation
                'type' => 4, // hard coded per LT Documentation
                'amount' => (float)$payload['deal_amount_financed'],
                'rate' => (float)$payload['deal_contract_rate'],
                'apr' => (float)$payload['deal_contract_apr']
            ];

            $data = json_encode($data);

            $query =  $this->sql->call('wildfire_accounting', 'lt_notification_upsert', $data);
            if ($query === false) {
                throw new Exception(
                    'On ApplicationId: ' . $data['application_id']
                );
            }
            $success = true;
            $error = '';
        } catch (Throwable $t) {
            $success = false;
            $error = 'ERROR: LT Notification Upsert SPROC FAILED! ' . $t->getMessage();
            $query = [];
            $this->logger->error($error);
        }

        return [
            'success' => $success,
            'error' => $error,
            'data' => $query
        ];
    }
}
