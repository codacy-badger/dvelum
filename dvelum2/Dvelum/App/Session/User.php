<?php

namespace Dvelum\App\Session;

class User extends \User
{
    /**
     * @return User
     */
    static public function factory()
    {
       return static::getInstance();
    }
}