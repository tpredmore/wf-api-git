<?php

namespace WF\API\Accounting\QuickBooks\Traits;

trait LendersTrait {

    public function getLenderConfigById(int $id, bool $decode = false) {
        $data = $this->setLenderConfigs();

        foreach ($data as $record) {
            if ($record['lender_id'] === $id) {
                return $decode && !empty($record['lender_configs']) ? json_decode($record['lender_configs'], true) : $record['lender_configs'];
            }
        }
        return null;
    }

    public function getLenderProductConfigById(int $id, bool $decode = false) {
        $data = $this->setLenderConfigs();

        foreach ($data as $record) {
            if ($record['lender_id'] === $id) {
                return $decode && !empty($record['product_configs']) ? json_decode($record['product_configs'], true) : $record['product_configs'];
            }
        }
        return null;
    }

    private function setLenderConfigs() {
        $array = $this->cache->get('quickbooks_lender_configs');
        if (!isset($array)) {
            $data = $this->sql->call('wildfire_accounting', 'qb_get_lenders');
            $this->cache->set('quickbooks_lender_configs',json_encode($data),true);
        } else {
            $data = json_decode($array, true);
        }

        return $data;
    }

}