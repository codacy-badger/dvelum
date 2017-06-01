<?php
/**
 *  DVelum project https://github.com/dvelum/dvelum
 *  Copyright (C) 2011-2017  Kirill Yegorov
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
declare(strict_types=1);

namespace Dvelum\Orm;

use Dvelum\Config;
use Dvelum\Orm;
use Dvelum\Db;
use Dvelum\Service;

/**
 * Base class for data models
 */
class Model
{
    /**
     * @var Config\ConfigInterface
     *
     * // Global (For all Models) Hard caching time
     * 'hardCacheTime'  => 60,
     * // Default Cache_Interface
     * 'dataCache' => false  ,
     * // Db object storage interface
     * 'dbObjectStore'  => false,
     * // Default Connection manager
     * 'defaultDbManager' => false,
     * // Default error log adapter
     * 'errorLog' =>false
     */
    protected $settings;
    /**
     * DB Object Storage
     * @var Orm\Object\Store
     */
    protected $store;

    /**
     * Database connection
     * @var \Db_Adapter
     */
    protected $db;

    /**
     * Slave DB connection
     * @var Db\Adapter
     */
    protected $dbSlave;

    /**
     * Db_Object config
     * @var Orm\Object\Config
     */
    private $objectConfig = null;

    /**
     * @var Config\ConfigInterface
     */
    private $lightConfig = null;

    /**
     * Object / model name
     * @var string
     */
    protected $name;

    /**
     * Hard caching time (without validation) for frondend , seconds
     * @var int
     */
    protected $cacheTime;

    /**
     * Current Cache_Interface
     * @var \Cache_Interface
     */
    protected $cache;

    /**
     * DB table prefix
     * @var string
     */
    protected $dbPrefix = '';

    /**
     * Global (For all Models) db connection
     * @var Db\Adapter
     */
    static protected $dbConnection = false;


    /**
     * Connection manager
     * @var \Db_Manager_Interface
     */
    protected $dbManager;

    /**
     * Table name
     * @var string
     */
    protected $table;

    /**
     * Current error log adapter
     * @var \Psr\Log\LoggerInterface | false
     */
    protected $log = false;

    /**
     * List of search fields
     * @var array | false
     */
    protected $searchFields = null;


    /**
     * Get DB table prefix
     * @return string
     */
    public function getDbPrefix(): string
    {
        return $this->dbPrefix;
    }

    /**
     * @param string $objectName
     * @throws \Exception
     */
    public function __construct(string $objectName, Config\ConfigInterface $settings)
    {
        $this->settings = $settings;

        $ormConfig = Config\Factory::storage()->get('orm.php', true, false);

        $this->store = $settings->get('dbObjectStore');
        $this->name = strtolower($objectName);
        $this->cacheTime = $settings->get('hardCacheTime');

        if ($settings->offsetExists('dataCache')) {
            $this->cache = $settings->get('dataCache');
        } else {
            $this->cache = false;
        }

        $this->dbManager = $settings->get('defaultDbManager');

        $this->lightConfig = Config\Factory::storage()->get(
            $ormConfig->get('object_configs') . $this->name . '.php',
            true,
            false
        );

        $conName = $this->lightConfig->get('connection');
        $this->db = $this->dbManager->getDbConnection($conName);
        if ($this->lightConfig->offsetExists('slave_connection') && !empty($this->lightConfig->get('slave_connection'))) {
            $this->dbSlave = $this->dbSlave = $this->dbManager->getDbConnection($this->lightConfig->get('slave_connection'));
        } else {
            $this->dbSlave = $this->db;
        }

        if ($this->lightConfig->get('use_db_prefix')) {
            $this->dbPrefix = $this->dbManager->getDbConfig($conName)->get('prefix');
        } else {
            $this->dbPrefix = '';
        }

        $this->table = $this->lightConfig->get('table');

        if ($settings->get('errorLog')) {
            $this->log = $settings->get('errorLog');
        }
    }

    /**
     * Lazy load of ORM\Object\Config
     * @return Object\Config
     * @throws \Exception
     */
    protected function getObjectConfig(): Orm\Object\Config
    {
        if (empty($this->objectConfig)) {
            try {
                $this->objectConfig = Orm\Object\Config::factory($this->name);
            } catch (\Exception $e) {
                throw new \Exception('Object ' . $this->name . ' is not exists');
            }
        }
        return $this->objectConfig;
    }

    /**
     * Get Object Storage
     * @return Orm\Object\Store
     */
    protected function _getObjectsStore(): Orm\Object\Store
    {
        return $this->store;
    }

    /**
     * Set Database connector for concrete model
     * @param Db\Adapter $db
     */
    public function setDbConnection(Db\Adapter $db)
    {
        $this->db = $db;
    }

    /**
     * Set the adapter of the object store
     * @param Orm\Object\Store $store
     */
    public function setObjectsStore(Orm\Object\Store $store)
    {
        $this->store = $store;
    }

    /**
     * Set hardcaching time for concrete model
     * @param integer $time
     */
    public function setHardCacheTitme($time)
    {
        $this->cacheTime = $time;
    }

    /**
     * Get Master Db connector
     * return Db\Adapter
     */
    public function getDbConnection(): Db\Adapter
    {
        return $this->db;
    }

    /**
     * Get Slave Db Connection
     * @return Db\Adapter
     */
    public function getSlaveDbConnection(): Db\Adapter
    {
        return $this->dbSlave;
    }

    /**
     * Get current db manager
     * @return \Db_Manager_Interface
     */
    public function getDbManager(): \Db_Manager_Interface
    {
        return $this->dbManager;
    }

    /**
     * Get storage adapter
     * @return Orm\Object\Store
     */
    public function getStore(): Orm\Object\Store
    {
        return $this->store;
    }

    /**
     * Factory method of model instantiation
     * @param string $objectName — the name of the object in ORM
     * @return Model
     * @deprecated
     */
    static public function factory(string $objectName): Model
    {
        /**
         * @var Orm $service
         */
        $service = Service::get('orm');
        return $service->model($objectName);
    }

    /**
     * Get the name of the object, which the model refers to
     * @return string
     */
    public function getObjectName(): string
    {
        return $this->name;
    }

    /**
     * Get key for cache
     * @param array $params - parameters can not contain arrays, objects and resources
     * @return string
     */
    public function getCacheKey(array $params): string
    {
        return md5($this->getObjectName() . '-' . implode('-', $params));
    }

    /**
     * Get the name of the database table (with prefix)
     * @return string
     */
    public function table(): string
    {
        return $this->dbPrefix . $this->table;
    }

    /**
     * Get record by id
     * @param integer $id
     * @param array|string $fields — optional — the list of fields to retrieve
     * @return array|false
     */
    final public function getItem($id, $fields = '*')
    {
        $sql = $this->dbSlave->select()->from($this->table(), $fields);
        $sql->where($this->dbSlave->quoteIdentifier($this->getPrimaryKey()) . ' = ' . intval($id));
        return $this->dbSlave->fetchRow($sql);
    }

    /**
     *  Get the object data using cache
     * @param integer $id - object identifier
     * @return array
     */
    public function getCachedItem($id)
    {
        if (!$this->cache) {
            return $this->getItem($id);
        }

        $cacheKey = $this->getCacheKey(array('item', $id));
        $data = $this->cache->load($cacheKey);

        if ($data !== false) {
            return $data;
        }

        $data = $this->getItem($id);

        if ($this->cache) {
            $this->cache->save($data, $cacheKey);
        }

        return $data;
    }

    /**
     * Get data record by field value using cache. Returns first occurrence
     * @param string $field - field name
     * @param string $value - field value
     * @return array
     */
    public function getCachedItemByField(string $field, $value)
    {
        $cacheKey = $this->getCacheKey(array('item', $field, $value));
        $data = false;

        if ($this->cache) {
            $data = $this->cache->load($cacheKey);
        }

        if ($data !== false) {
            return $data;
        }

        $data = $this->getItemByField($field, $value);

        if ($this->cache && $data) {
            $this->cache->save($data, $cacheKey);
        }

        return $data;
    }

    /**
     * Get object by unique field
     * @deprecated
     * @param string $fieldName
     * @param string $value
     * @param mixed $fields - optional
     * @throws \Exception
     * @return array
     */
    public function getItemByUniqueField(string $fieldName, $value, $fields = '*')
    {
        return $this->getItemByField($fieldName, $value, $fields);
    }

    /**
     * Get Item by field value. Returns first occurrence
     * @param string $fieldName
     * @param $value
     * @param string $fields
     * @return array|null
     */
    public function getItemByField(string $fieldName, $value, $fields = '*')
    {
        $sql = $this->dbSlave->select()->from($this->table(), $fields);
        $sql->where($this->dbSlave->quoteIdentifier($fieldName) . ' = ?', $value)->limit(1);
        return $this->dbSlave->fetchRow($sql);
    }

    /**
     * Get a number of entries a list of IDs
     * @param array $ids - list of IDs
     * @param mixed $fields - optional - the list of fields to retrieve
     * @param bool $useCache - optional, defaul false
     * @return array / false
     */
    final public function getItems(array $ids, $fields = '*', $useCache = false)
    {
        $data = false;

        if (empty($ids)) {
            return [];
        }

        if ($useCache && $this->cache) {
            $cacheKey = $this->getCacheKey(array('list', serialize(func_get_args())));
            $data = $this->cache->load($cacheKey);
        }

        if ($data === false) {
            $sql = $this->dbSlave->select()
                ->from($this->table(), $fields)
                ->where($this->dbSlave->quoteIdentifier($this->getPrimaryKey()) . ' IN(' . \Utils::listIntegers($ids) . ')');
            $data = $this->dbSlave->fetchAll($sql);

            if (!$data) {
                $data = [];
            }

            if ($useCache && $this->cache) {
                $this->cache->save($data, $cacheKey, $this->cacheTime);
            }

        }
        return $data;
    }

    /**
     * Add filters (where) to the query
     * @param Db\Select | string
     * @param array $filters the key - the field name, value
     * @return void
     */
    public function queryAddFilters(Db\Select $sql, $filters): void
    {
        if (!is_array($filters) || empty($filters)) {
            return;
        }

        foreach ($filters as $k => $v) {

            if ($v instanceof Db\Select\Filter) {
                $v->applyTo($this->db, $sql);
            } else {
                if (is_array($v) && !empty($v)) {
                    $sql->where($this->db->quoteIdentifier($k) . ' IN(?)', $v);
                } elseif (is_bool($v)) {
                    $sql->where($this->db->quoteIdentifier($k) . ' = ' . intval($v));
                } elseif ((is_string($v) && strlen($v)) || is_numeric($v)) {
                    $sql->where($this->db->quoteIdentifier($k) . ' =?', $v);
                } elseif (is_null($v)) {
                    $sql->where($this->db->quoteIdentifier($k) . ' IS NULL');
                }
            }
        }
    }

    /**
     * Add author selection join to the query.
     * Used with rev_control objects
     * @param Db\Select $sql
     * @param string $fieldAlias
     * @return void
     */
    protected function queryAddAuthor(Db\Select $sql, string $fieldAlias): void
    {
        $sql->joinLeft(
            array('u1' => Model::factory('User')->table()),
            'author_id = u1.id',
            array($fieldAlias => 'u1.name')
        );
    }

    /**
     * Add editor selection join to the query.
     * Used with rev_control objects
     * @param Db\Select $sql
     * @param string $fieldAlias
     * @return void
     */
    protected function queryAddEditor(Db\Select $sql, $fieldAlias): void
    {
        $sql->joinLeft(
            array('u2' => Model::factory('User')->table()),
            'editor_id = u2.id',
            array($fieldAlias => 'u2.name')
        );
    }

    /**
     * Add pagination parameters to a query
     * Used in CRUD-controllers for list pagination and sorting
     * @param Db\Select $sql
     * @param array $params — possible keys: start,limit,sort,dir
     * @return void
     */
    public function queryAddPagerParams(Db\Select $sql, $params): void
    {
        if (isset($params['limit']) && !isset($params['start'])) {
            $sql->limit(intval($params['limit']));
        } elseif (isset($params['start']) && isset($params['limit'])) {
            $sql->limit(intval($params['limit']), intval($params['start']));
        }

        if (!empty($params['sort']) && !empty($params['dir'])) {

            if (is_array($params['sort']) && !is_array($params['dir'])) {
                $sort = array();

                foreach ($params['sort'] as $key => $field) {
                    if (!is_int($key)) {
                        $order = trim(strtolower($field));
                        if ($order == 'asc' || $order == 'desc') {
                            $sort[$key] = $order;
                        }
                    } else {
                        $sort[$field] = $params['dir'];
                    }
                }
                $sql->order($sort);
            } else {
                $sql->order(array($params['sort'] => $params['dir']));
            }
        }
    }

    /**
     * Get a list of records
     * @param array|null $filters - optional filters (where) the key - the field name, value
     * @param array|null $params - optional parameters array('start'=>0,'limit'=>10,'sort'=>'fieldname','dir'=>'DESC')
     * @param string|null $query - optional string for search
     * @param array|null $fields - optional  list of fields to retrieve
     * it is necessary to remember that hard cache gets invalidated only at the end of its life cycle (configs / main.php),
     * is used in case update triggers can’t be applied
     * @param array|null $joins - optional, inclusion config for Zend_Select:
     * array(
     *          array(
     *                'joinType'=> joinLeft/left, joinRight/right, joinInner/inner
     *                'table' => array / string
     *                'fields => array / string
     *                'condition'=> string
     *          )...
     * )
     * @return array
     */
    public function find(?array $filters, ?array $params, ?string $query, ?array $fields, ?array $joins) : array
    {
        if(empty($fields)){
            $fields = '*';
        }

        $sql = $this->dbSlave->select()->from($this->table(), $fields);

        if (!empty($filters)) {
            $this->queryAddFilters($sql, $this->clearFilters($filters));
        }

        if (!empty($params)) {
            $this->queryAddPagerParams($sql, $params);
        }

        if (!empty($query)) {
            $this->queryAddQuery($sql, $query);
        }

        if (!empty($joins)) {
            $this->queryAddJoins($sql, $joins);
        }

        $data = $this->dbSlave->fetchAll($sql);

        if (!$data) {
            $data = [];
        }

        return $data;
    }

    /**
     * Get a number of objects (rows in a table)
     * @param array|bool $filters — optional - filters (where) the key - the field name, value
     * @param string|bool $query - optional - search query — search query
     * @param boolean $useCache — use hard cache
     * it is necessary to remember that hard cache gets invalidated only at the end of its life cycle (configs / main.php),
     * is used in case update triggers can’t be applied
     * @return int
     */
    public function getCount($filters = false, $query = false, $useCache = false)
    {
        $cParams = '';
        $data = false;
        if ($useCache && $this->cache) {
            if ($filters) {
                $cParams .= serialize($filters);
            }

            if ($query) {
                $cParams .= $query;
            }

            $cacheKey = $this->getCacheKey(array('count', $cParams));
            $data = $this->cache->load($cacheKey);
        }

        if ($data === false) {
            $sql = $this->dbSlave->select();
            $sql->from($this->table(), array('count' => 'COUNT(*)'));

            $this->queryAddFilters($sql, $filters);

            if ($query && strlen($query)) {
                $this->queryAddQuery($sql, $query);
            }

            $data = $this->dbSlave->fetchOne($sql);

            if ($useCache && $this->cache) {
                $this->cache->save($data, $cacheKey, self::$defaults['hardCacheTime']);
            }

        }
        return $data;
    }

    /**
     * Get a list of records (is used by CRUD_VC controllers)
     * @param array|bool $params - parameters array('start'=>0,'limit'=>10,'sort'=>'fieldname','dir'=>'DESC')
     * @param array|bool $filters - filters
     * @param string|bool $query — optional string for search
     * @param mixed $fields — optional list of fields
     * @param string|bool $author - optional key for storing entry author id
     * @param string|bool $lastEditor - optional key  for storing the last editor’s ID
     * @param array|bool $joins - optional, inclusion config for Zend_Select:
     * array(
     *          array(
     *                'joinType'=> joinLeft/left, joinRight/right, joinInner/inner
     *                'table' => array / string
     *                'fields => array / string
     *                'condition'=> string
     *          )...
     * )
     * @return array
     */
    public function getListVc($params = false, $filters = false, $query = false, $fields = '*', $author = false, $lastEditor = false, $joins = false): array {
        if (is_array($filters) && !empty($filters)) {
            $filters = $this->clearFilters($filters);
        }

        if ($this->dbSlave === Model::factory('User')->getSlaveDbConnection()) {
            return $this->getListVcLocal($params, $filters, $query, $fields, $author, $lastEditor, $joins);
        } else {
            return $this->getListVcRemote($params, $filters, $query, $fields, $author, $lastEditor, $joins);
        }
    }

    /**
     * Prepare filter values , clean empty filters
     * @param array $filters
     * @return array
     */
    protected function clearFilters(array $filters)
    {
        $fields = $this->lightConfig->get('fields');
        foreach ($filters as $field => $val) {
            if (!($val instanceof Db\Select\Filter) && !is_null($val) && (!is_array($val) && !strlen((string)$val))) {
                unset($filters[$field]);
                continue;
            }

            if (isset($fields[$field]) && isset($fields[$field]['db_type']) && $fields[$field]['db_type'] === 'boolean') {
                $filters[$field] = \Filter::filterValue(\Filter::FILTER_BOOLEAN, $val);
            }
        }
        return $filters;
    }

    protected function getListVcLocal($params = false, $filters = false, $query = false, $fields = '*', $author = false, $lastEditor = false, $joins = false) {
        $sql = $this->dbSlave->select()->from($this->table(), $fields);

        if ($filters) {
            $this->queryAddFilters($sql, $filters);
        }

        if ($author) {
            $this->queryAddAuthor($sql, (string)$author);
        }

        if ($lastEditor) {
            $this->queryAddEditor($sql, $lastEditor);
        }

        if ($query && strlen($query)) {
            $this->queryAddQuery($sql, $query);
        }

        if ($params) {
            $this->queryAddPagerParams($sql, $params);
        }

        if (is_array($joins) && !empty($joins)) {
            $this->queryAddJoins($sql, $joins);
        }

        return $this->dbSlave->fetchAll($sql);
    }

    protected function getListVcRemote($params = false, $filters = false, $query = false, $fields = '*', $author = false, $lastEditor = false, $joins = false) {
        if ($fields !== '*') {
            if ($author) {
                if (!in_array('author_id', $fields, true)) {
                    $fields[] = 'author_id';
                }
            }

            if ($lastEditor) {
                if (!in_array('editor_id', $fields, true)) {
                    $fields[] = 'editor_id';
                }
            }
        }

        $sql = $this->dbSlave->select()->from($this->table(), $fields);

        if ($filters) {
            $this->queryAddFilters($sql, $filters);
        }

        if ($query && strlen($query)) {
            $this->queryAddQuery($sql, $query);
        }

        if ($params) {
            $this->queryAddPagerParams($sql, $params);
        }

        if (is_array($joins) && !empty($joins)) {
            $this->queryAddJoins($sql, $joins);
        }

        $data = $this->dbSlave->fetchAll($sql);

        if (!$author && !$lastEditor) {
            return $data;
        }

        $ids = array();

        foreach ($data as $row) {
            if ($author) {
                $ids[] = $row['author_id'];
            }

            if ($lastEditor) {
                $ids[] = $row['editor_id'];
            }
        }

        if (!empty($ids)) {
            array_unique($ids);
            $usersData = Model::factory('User')->getList(false, array('id' => $ids), array('id', 'name'));
            if (!empty($usersData)) {
                $usersData = \Utils::rekey('id', $usersData);
            }
        }

        foreach ($data as $key => &$row) {
            if ($author) {
                if (isset($usersData[$row['author_id']])) {
                    $row[$author] = $usersData[$row['author_id']]['name'];
                } else {
                    $row[$author] = '';
                }
            }

            if ($lastEditor) {
                if (isset($usersData[$row['editor_id']])) {
                    $row[$lastEditor] = $usersData[$row['editor_id']]['name'];
                } else {
                    $row[$lastEditor] = '';
                }
            }
        }
        return $data;
    }

    /**
     * Get a list of records
     * @param array|boolean $params - optional parameters array('start'=>0,'limit'=>10,'sort'=>'fieldname','dir'=>'DESC')
     * @param array|boolean $filters - optional filters (where) the key - the field name, value
     * @param array|string $fields - optional  list of fields to retrieve
     * @param boolean $useCache - use hard cache
     * @param string|boolean $query - optional string for search (since 0.9)
     * it is necessary to remember that hard cache gets invalidated only at the end of its life cycle (configs / main.php),
     * is used in case update triggers can’t be applied
     * @param array|boolean $joins - optional, inclusion config for Zend_Select:
     * array(
     *          array(
     *                'joinType'=> joinLeft/left, joinRight/right, joinInner/inner
     *                'table' => array / string
     *                'fields => array / string
     *                'condition'=> string
     *          )...
     * )
     * @return array
     */
    public function getList($params = false, $filters = false, $fields = '*', $useCache = false, $query = false, $joins = false) {
        $data = false;

        if ($useCache && $this->cache) {
            $cacheKey = $this->getCacheKey(array('list', serialize(func_get_args())));
            $data = $this->cache->load($cacheKey);
        }

        if ($data === false) {
            $sql = $this->dbSlave->select()->from($this->table(), $fields);

            if (is_array($filters) && !empty($filters)) {
                $this->queryAddFilters($sql, $this->clearFilters($filters));
            }

            if ($params) {
                $this->queryAddPagerParams($sql, $params);
            }

            if ($query && strlen($query)) {
                $this->queryAddQuery($sql, $query);
            }

            if (is_array($joins) && !empty($joins)) {
                $this->queryAddJoins($sql, $joins);
            }

            $data = $this->dbSlave->fetchAll($sql);

            if (!$data) {
                $data = array();
            }

            if ($useCache && $this->cache) {
                $this->cache->save($data, $cacheKey, $this->cacheTime);
            }
        }
        return $data;
    }

    /**
     * Get object title
     * @param Orm\Object $object - object for getting title
     * @return mixed|string - object title
     * @throws \Exception
     */
    public function getTitle(Orm\Object $object)
    {
        $objectConfig = $object->getConfig();
        $title = $objectConfig->getLinkTitle();
        if (strpos($title, '{') !== false) {
            $fields = $objectConfig->getFieldsConfig(true);
            foreach ($fields as $name => $cfg) {
                $value = $object->get($name);
                if (is_array($value)) {
                    $value = implode(', ', $value);
                }
                $title = str_replace('{' . $name . '}', (string)$value, $title);
            }
        } else {
            if ($object->fieldExists($title)) {
                $title = $object->get($title);
            }
        }
        return $title;
    }

    /**
     * Delete record
     * @param int $recordId record ID
     * @return  boolean
     */
    public function remove(int $recordId): bool
    {
        $object = Orm\Object::factory($this->name, $recordId);
        if (self::_getObjectsStore()->delete($object)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Add joins to the query
     * @param Db\Select $sql
     * @param array $joins - config for ZendDb join method:
     * array(
     *        array(
     *            'joinType'=>   jonLeft/left , jonRight/right , joinInner/inner
     *            'table' => array / string
     *            'fields => array / string
     *            'condition'=> string
     *        )...
     * )
     */
    protected function queryAddJoins(Db\Select $sql, array $joins)
    {
        foreach ($joins as $config) {
            switch ($config['joinType']) {
                case 'joinLeft' :
                case 'left':
                    $sql->joinLeft($config['table'], $config['condition'], $config['fields']);
                    break;
                case 'joinRight' :
                case 'right':
                    $sql->joinRight($config['table'], $config['condition'], $config['fields']);
                    break;
                case 'joinInner':
                case 'inner':
                    $sql->joinInner($config['table'], $config['condition'], $config['fields']);
                    break;
            }
        }
    }

    /**
     * @param Db\Select $sql
     * @param array $joins
     * @deprecated
     */
    protected function _queryAddJoins(Db\Select $sql, array $joins)
    {
        $this->queryAddJoins($sql, $joins);
    }

    /**
     * Add Like where couse for query
     * @param Db\Select $sql
     * @param string $query
     * @param string $alias - table name alias, optional
     * @return void
     */
    protected function queryAddQuery(Db\Select $sql, $query, ?string $alias = null): void
    {
        if (!empty($alias)) {
            $alias = $this->table();
        }

        $searchFields = $this->getSearchFields();

        if (empty($searchFields)) {
            return;
        }

        $q = array();

        foreach ($searchFields as $v) {
            $q[] = $alias . "." . $v . " LIKE(" . $this->db->quote('%' . $query . '%') . ")";
        }

        $sql->where('(' . implode(' OR ', $q) . ')');
    }

    /**
     * @param Db\Select $sql
     * @param string $query
     * @param null|string $alias
     */
    protected function _queryAddQuery(Db\Select $sql, string $query, ?string $alias): void
    {
        $this->queryAddQuery($sql, $query, $alias);
    }

    /**
     * Check whether the field value is unique
     * Returns true if value $fieldValue is unique for $fieldName field
     * otherwise returns false
     * @param int $recordId — record ID
     * @param string $fieldName — field name
     * @param mixed $fieldValue — field value
     * @return boolean
     */
    public function checkUnique(int $recordId, string $fieldName, $fieldValue): bool
    {
        return !(boolean)$this->dbSlave->fetchOne(
            $this->dbSlave->select()
                ->from($this->table(), array('count' => 'COUNT(*)'))
                ->where($this->dbSlave->quoteIdentifier($this->getPrimaryKey()) . ' != ?', $recordId)
                ->where($this->dbSlave->quoteIdentifier($fieldName) . ' =?', $fieldValue)
        );
    }

    /**
     * Get primary key name
     * @return string
     */
    public function getPrimaryKey(): string
    {
        $key = '';

        if ($this->lightConfig->offsetExists('primary_key')) {
            $key = $this->lightConfig->get('primary_key');
        }

        if (empty($key)) {
            return 'id';
        } else {
            return $key;
        }
    }

    /**
     * Set DB connections manager (since 0.9.1)
     * @param \Db_Manager_Interface $manager
     * @return void
     */
    public function setDbManager(\Db_Manager_Interface $manager): void
    {
        $conName = $this->lightConfig->get('connection');
        $this->dbManager = $manager;
        $this->db = $this->dbManager->getDbConnection($conName);
        $this->dbSlave = $this->dbManager->getDbConnection($this->lightConfig->get('slave_connection'));
        $this->refreshTableInfo();
    }

    public function refreshTableInfo()
    {
        $conName = $this->lightConfig->get('connection');
        $this->db = $this->dbManager->getDbConnection($conName);

        if ($this->objectConfig->hasDbPrefix()) {
            $this->dbPrefix = $this->dbManager->getDbConfig($conName)->get('prefix');
        } else {
            $this->dbPrefix = '';
        }

        $this->table = $this->lightConfig->get('table');
    }

    /**
     * Set current log adapter
     * @param mixed \Log | false  $log
     */
    public function setLog($log): void
    {
        $this->log = $log;
    }

    /**
     * Get logs Adapter
     * @return \Log
     */
    public function getLogsAdapter()
    {
        return $this->log;
    }

    /**
     * Log error message
     * @param string $message
     * @return void
     */
    public function logError(string $message): void
    {
        if (!$this->log) {
            return;
        }

        $this->log->log(\Psr\Log\LogLevel::ERROR, get_called_class() . ': ' . $message);
    }

    /**
     * Insert multiple rows (not safe but fast)
     * @param array $data
     * @param int $chunkSize
     * @param boolean $ignore - optional default false
     * @return boolean
     */
    public function multiInsert(array $data, int $chunkSize = 300, bool $ignore = false): bool
    {
        if (empty($data)) {
            return true;
        }

        $chunks = array_chunk($data, $chunkSize);

        $keys = array_keys($data[key($data)]);

        foreach ($keys as &$key) {
            $key = $this->db->quoteIdentifier($key);
        }
        unset($key);

        $keys = implode(',', $keys);

        foreach ($chunks as $rowset) {
            foreach ($rowset as &$row) {
                foreach ($row as &$colValue) {
                    if (is_bool($colValue)) {
                        $colValue = intval($colValue);
                    } elseif (is_null($colValue)) {
                        $colValue = 'NULL';
                    } else {
                        $colValue = $this->db->quote($colValue);
                    }
                }
                unset($colValue);
                $row = implode(',', $row);
            }
            unset($row);

            $sql = 'INSERT ';

            if ($ignore) {
                $sql .= 'IGNORE ';
            }

            $sql .= 'INTO ' . $this->table() . ' (' . $keys . ') ' . "\n" . ' VALUES ' . "\n" . '(' . implode(')' . "\n" . ',(',
                    array_values($rowset)) . ') ' . "\n" . '';

            try {
                $this->db->query($sql);
            } catch (\Exception $e) {
                $this->logError('multiInsert: ' . $e->getMessage());
                return false;
            }
        }
        return true;
    }

    /**
     * Insert single record on duplicate key update
     * @param $data
     * @return boolean
     */
    public function insertOnDuplicateKeyUpdate($data)
    {
        $keys = array_keys($data);

        foreach ($keys as &$val) {
            $val = $this->db->quoteIdentifier($val);
        }
        unset($val);

        $values = array_values($data);
        foreach ($values as &$val) {
            $val = $this->db->quote($val);
        }
        unset($val);

        $sql = 'INSERT INTO ' . $this->db->quoteIdentifier($this->table()) . ' (' . implode(',',
                $keys) . ') VALUES (' . implode(',', $values) . ') ON DUPLICATE KEY UPDATE ';

        $updates = [];
        foreach ($keys as $key) {
            $updates[] = $key . ' = VALUES(' . $key . ') ';
        }

        $sql .= implode(', ', $updates) . ';';

        try {
            $this->db->query($sql);
            return true;
        } catch (\Exception $e) {
            $this->logError($e->getMessage() . ' SQL: ' . $sql);
            return false;
        }
    }

    /**
     * Get list of search fields (get from ORM)
     */
    protected function getSearchFields()
    {
        if (is_null($this->searchFields)) {
            $this->searchFields = $this->getObjectConfig()->getSearchFields();
        }
        return $this->searchFields;
    }

    /**
     * Set
     * @param array $fields
     * @return void
     */
    public function setSearchFields(array $fields): void
    {
        $this->searchFields = $fields;
    }

    /**
     * Reset search fields list (get from ORM)
     * @return void
     */
    public function resetSearchFields(): void
    {
        $this->searchFields = null;
    }


    /**
     * Clear runtime cache
     * @param $name , Object name
     * @return void
     */
    static public function removeInstance($name): void
    {
        $name = strtolower($name);
        if (isset(static::$instances[$name])) {
            unset(static::$instances[$name]);
        }
    }
}