<?php

namespace WF\API\Accounting\Traits;

trait AccountingTrait {

    private mixed $user = '';

    public function getUserNameAttribute($email)
    {
        if (!$this->user) {
                $this->getUser($email);
            }
        return $this->user['full_name'];
    }

    public function getUser($email)
    {
        $this->user = $this->sql->call('wildfire_settings', 'wf_web_get_user_details_by_email', $email)[0];
        return $this->user;
    }

}
