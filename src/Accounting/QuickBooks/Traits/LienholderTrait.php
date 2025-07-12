<?php

namespace WF\API\Accounting\QuickBooks\Traits;

trait LienholderTrait {

    /**
     * @param $id
     *
     * @return mixed|null
     */
    public function getLienholderById($id): mixed {
        $data = $this->setLienholders();

        foreach ($data as $record) {
            if ($record['id'] === $id) {
                return $record;
            }
        }
        return 'Lienholder not found in Wildfire';
    }


    /**
     * @return mixed
     */
    private function setLienholders(): mixed {
        $array = $this->cache->get('quickbooks_lienholders');
        if (!isset($array)) {
            $data = $this->sql->call('wildfire_accounting', 'qb_get_lienholders',0);
            $this->cache->set('quickbooks_lienholders',json_encode($data),true);
        } else {
            $data = json_decode($array, true);
        }

        return $data;
    }
}