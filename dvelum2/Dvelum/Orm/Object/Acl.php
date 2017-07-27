<?php

namespace Dvelum\Orm\Object;

use Dvelum\Orm\ObjectInterface;
use Dvelum\Orm\Object;

abstract class Acl
{
    const ACCESS_VIEW = 'view';
    const ACCESS_EDIT = 'edit';
    const ACCESS_CREATE = 'create';
    const ACCESS_DELETE = 'delete';
    const ACCESS_PUBLISH = 'publish';

    public function __construct(){}

    /**
     * Current user
     * @var \User
     */
    protected $_user = false;

    /**
     * Check create permissions
     * @param ObjectInterface $object
     * @return boolean
     */
    abstract public function canCreate(ObjectInterface $object);

    /**
     * Check update permissions
     * @param ObjectInterface $object
     * @return boolean
     */
    abstract public function canEdit(ObjectInterface $object);

    /**
     * Check delete permissions
     * @param ObjectInterface $object
     * @return boolean
     */
    abstract public function canDelete(ObjectInterface $object);

    /**
     * Check publish permissions
     * @param ObjectInterface $object
     * @return boolean
     */
    abstract public function canPublish(ObjectInterface $object);

    /**
     * Check read permissions
     * @param ObjectInterface $object
     * @return boolean
     */
    abstract public function canRead(ObjectInterface $object);

    /**
     * Set current User
     * @param mixed $user
     */
    public function setUser($user)
    {
        $this->_user = $user;
    }

    /**
     * Check permissions for action
     * @param string $operation - const  Object\Acl::ACCESS_VIEW,ACCESS_EDIT,ACCESS_CREATE,ACCESS_DELETE,ACCESS_PUBLISH
     * @param string $objectName
     * @return boolean
     */
    abstract public function can($operation , $objectName);

    /**
     * Create ACL adapter object
     * @param string $class
     * @throws \Exception
     * @return Object\Acl
     */
    static public function factory($class)
    {
        $object = new $class;

        if(!$object instanceof Object\Acl)
            throw new \Exception('Invalid ACL adapter '.$class);

        return $object;
    }
}