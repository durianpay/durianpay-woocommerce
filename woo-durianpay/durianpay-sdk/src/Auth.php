<?php

namespace Durianpay\Api;

class Auth extends Entity
{
    public function login($attributes = array())
    {
        return $this->request('POST', 'merchants/token/');    
    }
}
