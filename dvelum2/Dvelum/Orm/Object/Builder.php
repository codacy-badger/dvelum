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

namespace Dvelum\Orm\Object;

use Dvelum\Orm;
use Dvelum\Model;
use Zend\Db\Sql\Ddl;

/**
 * Builder for Orm\Object
 * @package Orm
 * @subpackage Orm\Object
 * @author Kirill Ygorov
 * @license General Public License version 3
 *
 * @todo replace Exceptions, create error messages
 */
class Builder
{
    /**
     *
     * @var \Dvelum\Db\Adapter
     */
    protected $db;

    /**
     * @var string $objectName
     */
    protected $objectName;

    /**
     * @var Config
     */
    protected $objectConfig;

    /**
     *
     * @var Model
     */
    protected $_model;
    protected static $writeLog = false;
    protected static $logPrefix = '0.1';
    protected static $logsPath = './logs/';
    protected static $foreignKeys = false;
    protected $errors = [];

    static public function factory(string $objectName, bool $forceConfig = true) : Builder
    {
        $model = Model::factory($objectName);
        $platform = $model->getDbConnection()->getAdapter()->getPlatform();
        switch ($platform){
            case 'MySQL' :
                return new Builder\MySQL($objectName, $forceConfig);
                break;
            default :
                return new static($objectName, $forceConfig);
                break;
        }
    }
    /**
     *
     * @param string $objectName
     * @param boolean $forceConfig, optional
     */
    protected function __construct($objectName , $forceConfig = true)
    {
        $this->objectName = $objectName;
        $this->objectConfig = Orm\Object\Config::factory($objectName , $forceConfig);
        $this->model = Model::factory($objectName);
        $this->db = $this->model->getDbConnection();
        $this->dbPrefix = $this->model->getDbPrefix();
    }

    public static $numTypes = array(
        'tinyint' ,
        'smallint' ,
        'mediumint' ,
        'int' ,
        'bigint' ,
        'float' ,
        'double' ,
        'decimal' ,
        'bit'
    );
    public static $intTypes = array(
        'tinyint' ,
        'smallint' ,
        'mediumint' ,
        'int' ,
        'bigint' ,
        'bit'
    );
    public static $floatTypes = array(
        'decimal' ,
        'float' ,
        'double'
    );
    public static $charTypes = array(
        'char' ,
        'varchar'
    );
    public static $textTypes = array(
        'tinytext' ,
        'text' ,
        'mediumtext' ,
        'longtext'
    );
    public static $dateTypes = array(
        'date' ,
        'datetime' ,
        'time' ,
        'timestamp'
    );
    public static $blobTypes = array(
        'tinyblob' ,
        'blob' ,
        'mediumblob' ,
        'longblob'
    );

    /**
     * Write SQL log
     * @param boolean $flag
     * @return void
     */
    static public function writeLog($flag) : void
    {
        self::$writeLog = (boolean) $flag;
    }

    /**
     * Set query log file prefix
     * @param string $string
     * @return void
     */
    static public function setLogPrefix(string $string) : void
    {
        self::$logPrefix = strval($string);
    }

    /**
     * Set logs path
     * @param string $string
     * @return void
     */
    static public function setLogsPath(string $string) : void
    {
        self::$logsPath = $string;
    }

    /**
     * Use foreign keys
     * @param bool $flag
     * @return void
     */
    static public function useForeignKeys($flag) : void
    {
        self::$foreignKeys = (bool) $flag;
    }

    /**
     * Check if foreign keys is used
     * @return bool
     */
    static public function foreignKeys() : bool
    {
        return self::$foreignKeys;
    }

    /**
     * Log queries
     * @param string $sql
     * @return bool
     */
    protected function logSql(string $sql) : bool
    {
        if(!self::$writeLog)
            return true;

        $str = "\n--\n--" . date('Y-m-d H:i:s') . "\n--\n" . $sql;
        $filePath = self::$logsPath . $this->objectConfig->get('connection') .'_'. self::$logPrefix;
        $result = @file_put_contents($filePath, $str , FILE_APPEND);

        if($result === false){
            $this->errors[] = 'Cant write to log file ' . $filePath;
            return false;
        }
        return true;
    }

    /**
     * Check if DB table has correct structure
     * @return bool
     */
    public function validate() : bool
    {
        if(!$this->tableExists())
            return false;

        if(!$this->checkRelations()){
            return false;
        }

        $updateColumns = $this->prepareColumnUpdates();
        $updateIndexes = $this->prepareIndexUpdates();
        $engineUpdate = $this->prepareEngineUpdate();
        $updateKeys = [];

        if(self::$foreignKeys)
            $updateKeys = $this->prepareKeysUpdate();

        if(!empty($updateColumns) || !empty($updateIndexes) || !empty($updateKeys) || !empty($engineUpdate))
            return false;
        else
            return true;
    }

    /**
     * Prepare DB engine update SQL
     * @return string|null
     */
    public function prepareEngineUpdate() : ?string
    {
        $config = $this->objectConfig->__toArray();
        $conf = $this->db->fetchRow('SHOW TABLE STATUS WHERE `name` = "' . $this->model->table() . '"');

        if(! $conf || ! isset($conf['Engine']))
            return null;

        if(strtolower($conf['Engine']) === strtolower($this->objectConfig->get('engine')))
            return null;

        return $this->changeTableEngine($this->objectConfig->get('engine') , true);
    }

    /**
     * Prepare list of columns to be updated
     * returns [
     *         'name'=>'SomeName',
     *         'action'=>[drop/add/change],
     *         ]
     * @return array
     */
    public function prepareColumnUpdates() : array
    {
        $config = $this->objectConfig->__toArray();
        $updates = array();

        if(! $this->tableExists())
            $fields = [];
        else
            $fields = $this->getExistingColumns()->getColumns();


        /**
         * @var \Zend\Db\Metadata\Object\ColumnObject $column
         */
        $columns = [];
        foreach ($fields as $column){
            $columns[$column->getName()] = $column;
        }

        // except virtual fields
        foreach($config['fields'] as $field=>$cfg){
            if($this->objectConfig->getField($field)->isVirtual()){
                unset($config['fields'][$field]);
            }
        }

        /*
         * Remove deprecated fields
         */
        foreach($columns as $name=>$column)
        {
            if(!isset($config['fields'][$name]))
            {
                $updates[] = array(
                    'name' => $name ,
                    'action' => 'drop' ,
                    'type' => 'field'
                );
            }
        }

        foreach($config['fields'] as $name => $v)
        {
            /*
             * Add new field
             */
            if(!isset($columns[$name]))
            {
                $updates[] = array(
                    'name' => $name ,
                    'action' => 'add'
                );
                continue;
            }

            $column = $columns[$name];

            $dataType = strtolower($column->getDataType());
            /*
             * Field type compare flag
             */
            $typeCmp = false;
            /*
             * Field length compare flag
             */
            $lenCmp = false;
            /*
             * IsNull compare flag
             */
            $nullCmp = false;
            /*
             * Default value compare flag
             */
            $defaultCmp = false;
            /*
             * Unsigned compare flag
             */
            $unsignedCmp = false;
            /**
             * AUTO_INCREMENT compare flag
             *
             * @var bool
             */
            $incrementCmp = false;

            if($v['db_type'] === 'boolean' && $dataType === 'tinyint')
            {
                /*
                 * skip check for booleans
                 */
            }
            else
            {
                if(strtolower($v['db_type']) !== $dataType)
                    $typeCmp = true;

                if(in_array($v['db_type'] , self::$floatTypes , true))
                {
                    /*
                     * @note ZF3 has inverted scale and precision values
                     */
                    if((int) $v['db_scale'] != (int) $column->getNumericPrecision() || (int) $v['db_precision'] != (int) $column->getNumericScale())
                        $lenCmp = true;
                }
                elseif(in_array($v['db_type'] , self::$numTypes , true) && isset(Orm\Object\Field\Property::$numberLength[$v['db_type']]))
                {
                    $lenCmp = (int) Orm\Object\Field\Property::$numberLength[$v['db_type']] != (int) $column->getNumericPrecision();
                }
                else
                {
                    if(isset($v['db_len']))
                        $lenCmp = (int) $v['db_len'] != (int) $column->getCharacterMaximumLength();
                }

                /*
                  Auto set default '' for NOT NULL string properties
                  if(in_array($v['db_type'] , self::$charTypes , true) && (! isset($v['db_isNull']) || ! $v['db_isNull']) && (! isset($v['db_default']) || $v['db_default'] === false))
                  {
                    $v['db_default'] = '';
                  }
                */

                if(in_array($v['db_type'] , self::$textTypes , true))
                {
                    if(isset($v['required']) && $v['required'])
                        $v['db_isNull'] = false;
                    else
                        $v['db_isNull'] = true;
                }

                $nullCmp = (boolean) $v['db_isNull'] !==  $column->isNullable();

                if((!isset($v['db_unsigned']) || !$v['db_unsigned']) && $column->isNumericUnsigned())
                    $unsignedCmp = true;

                if(isset($v['db_unsigned']) && $v['db_unsigned'] && ! $column->isNumericUnsigned())
                    $unsignedCmp = true;
            }

            if(!((boolean) $v['db_isNull']) && ! in_array($v['db_type'] , self::$dateTypes , true) && ! in_array($v['db_type'] , self::$textTypes , true))
            {
                if((!isset($v['db_default']) || $v['db_default'] === false) && !is_null($column->getColumnDefault())){
                    $defaultCmp = true;
                }
                if(isset($v['db_default']))
                {
                    if((is_null($column->getColumnDefault()) && $v['db_default'] !== false) || (! is_null($column->getColumnDefault()) && $v['db_default'] === false))
                        $defaultCmp = true;
                    else
                        $defaultCmp = (string) $v['db_default'] != (string) $column->getColumnDefault();
                }
            }

            /**
             * @todo migrate identity
             */
//            if($fields[$name]['IDENTITY'] && $name != $this->objectConfig->getPrimaryKey())
//                $incrementCmp = true;
//
//            if($name == $this->objectConfig->getPrimaryKey() && ! $fields[$name]['IDENTITY'])
//                $incrementCmp = true;



          /*
           * If not passed at least one comparison then rebuild the the field
           */
            if($typeCmp || $lenCmp || $nullCmp || $defaultCmp || $unsignedCmp || $incrementCmp)
            {
                $updates[] = array(
                    'name' => $name ,
                    'action' => 'change',
                    'info' => [
                        'object' => $this->objectName,
                        'cmp_flags' =>[
                            'type' => (boolean) $typeCmp,
                            'length' => (boolean) $lenCmp,
                            'null' => (boolean) $nullCmp,
                            'default' => (boolean) $defaultCmp,
                            'unsigned' => (boolean) $unsignedCmp,
                            'increment' => (boolean) $incrementCmp
                        ]
                    ]
                );
            }
        }
        return $updates;
    }

    /**
     * Rename object field
     * @param string $oldName
     * @param string $newName
     * @return bool
     */
    public function renameField($oldName , $newName) : bool
    {
        if($this->objectConfig->isLocked() || $this->objectConfig->isReadOnly())
        {
            $this->errors[] = 'Can not build locked object ' . $this->objectConfig->getName();
            return false;
        }

        $fieldConfig = $this->objectConfig->getFieldConfig($newName);

        $sql = ' ALTER TABLE ' . $this->model->table() . ' CHANGE `' . $oldName . '` ' . $this->_proppertySql($newName , $fieldConfig);

        try
        {
            $this->db->query($sql);
            $this->logSql($sql);
            return true;
        }
        catch(\Exception $e)
        {
            $this->errors[] = $e->getMessage() . ' <br>SQL: ' . $sql;
            return false;
        }
    }

    /**
     * Create / alter db table
     * @param bool $buildKeys
     * @return boolean
     */
    public function build(bool $buildKeys = true) : bool
    {
        $this->errors = array();
        if($this->objectConfig->isLocked() || $this->objectConfig->isReadOnly())
        {
            $this->errors[] = 'Can not build locked object ' . $this->objectConfig->getName();
            return false;
        }
        /*
         * Create table if not exists
         */
        if(! $this->tableExists())
        {
            $sql = '';
            try
            {
                $sql = $this->_sqlCreate();
                $this->db->query($sql);
                $this->logSql($sql);
                if($buildKeys)
                    return $this->buildForeignKeys();
                else
                    return true;
            }
            catch(\Exception $e)
            {
                $this->errors[] = $e->getMessage() . ' <br><b>SQL:</b> ' . $sql;
                return false;
            }
        }

        $engineUpdate = $this->prepareEngineUpdate();
        $colUpdates = $this->prepareColumnUpdates();
        $indexUpdates = $this->prepareIndexUpdates();

        /*
         * Remove invalid foreign keys
         */
        if($buildKeys && ! $this->buildForeignKeys(true , false))
            return false;

        /*
         * Update comands
         */
        $cmd = array();

        if(! empty($colUpdates))
        {
            $fieldsConfig = $this->objectConfig->getFieldsConfig();
            foreach($colUpdates as $info)
            {
                switch($info['action'])
                {
                    case 'drop' :
                        $cmd[] = "\n" . 'DROP `' . $info['name'] . '`';
                        break;
                    case 'add' :
                        $cmd[] = "\n" . 'ADD ' . $this->_proppertySql($info['name'] , $fieldsConfig[$info['name']]);
                        break;
                    case 'change' :
                        $cmd[] = "\n" . 'CHANGE `' . $info['name'] . '`  ' . $this->_proppertySql($info['name'] , $fieldsConfig[$info['name']]);
                        break;
                }
            }
        }

        if(!empty($indexUpdates))
        {
            $indexConfig = $this->objectConfig->getIndexesConfig();

            foreach($indexUpdates as $info)
            {
                switch($info['action'])
                {
                    case 'drop' :
                        if($info['name'] == 'PRIMARY')
                            $cmd[] = "\n" . 'DROP PRIMARY KEY';
                        else
                            $cmd[] = "\n" . 'DROP INDEX `' . $info['name'] . '`';
                        break;
                    case 'add' :
                        $cmd[] = $this->_prepareIndex($info['name'] , $indexConfig[$info['name']]);
                        break;
                }
            }
        }

        if(!empty($engineUpdate))
        {
            try
            {
                $this->db->query($engineUpdate);
                $this->logSql($engineUpdate);
            }
            catch(\Exception $e)
            {
                $this->errors[] = $e->getMessage() . ' <br>SQL: ' . $engineUpdate;
            }
        }

        if(!empty($cmd))
        {
            $dbCfg = $this->db->getConfig();
            try
            {
                $sql = 'ALTER TABLE `' . $dbCfg['dbname'] . '`.`' . $this->model->table() . '` ' . implode(',' , $cmd) . ';';
                $this->db->query($sql);
                $this->logSql($sql);
                if($buildKeys)
                    return $this->buildForeignKeys(false , true);
                else
                    return true;
            }
            catch(\Exception $e)
            {
                $this->errors[] = $e->getMessage() . ' <br>SQL: ' . $sql;
                return false;
            }
        }

        $ralationsUpdate = $this->getObjectsUpdatesInfo();
        if(!empty($ralationsUpdate)){
            try{
                $this->updateRelations($ralationsUpdate);
            }catch (\Exception $e){
                $this->errors[] = $e->getMessage();
                return false;
            }
        }

        if(empty($this->errors))
            return true;
        else
            return true;
    }

    /**
     * Build Foreign Keys
     * @param bool $remove - remove keys
     * @param bool $create - create keys
     * @return boolean
     */
    public function buildForeignKeys($remove = true , $create = true) : bool
    {
        if($this->objectConfig->isLocked() || $this->objectConfig->isReadOnly())
        {
            $this->errors[] = 'Can not build locked object ' . $this->objectConfig->getName();
            return false;
        }

        $keysUpdates = array();
        $cmd = array();

        if(self::$foreignKeys)
            $keysUpdates = $this->prepareKeysUpdate();
        else
            $keysUpdates = $this->prepareKeysUpdate(true);

        if(!empty($keysUpdates))
        {
            foreach($keysUpdates as $info)
            {
                switch($info['action'])
                {
                    case 'drop' :
                        if($remove)
                            $cmd[] = "\n" . 'DROP FOREIGN KEY `' . $info['name'] . '`';
                        break;
                    case 'add' :
                        if($create)
                            $cmd[] = 'ADD CONSTRAINT `' . $info['name'] . '`
        						FOREIGN KEY (`' . $info['config']['curField'] . '`)
    				      		REFERENCES `' . $info['config']['toDb'] . '`.`' . $info['config']['toTable'] . '` (`' . $info['config']['toField'] . '`)
    				      		ON UPDATE ' . $info['config']['onUpdate'] . '
    				      		ON DELETE ' . $info['config']['onDelete'];
                        break;
                }
            }
        }

        if(!empty($cmd))
        {
            $dbCfg = $this->db->getConfig();
            try
            {
                $sql = 'ALTER TABLE `' . $dbCfg['dbname'] . '`.`' . $this->model->table() . '` ' . implode(',' , $cmd) . ';';
                $this->db->query($sql);
                $this->logSql($sql);
                return true;
            }
            catch(\Exception $e)
            {
                $this->errors[] = $e->getMessage() . ' <br>SQL: ' . $sql;
                return false;
            }
        }

        return true;
    }

    /**
     * Build indexes for "create" query
     *
     * @return array - sql parts
     */
    protected function _createIndexes()
    {
        $cmd = array();
        $configIndexes = $this->objectConfig->getIndexesConfig();

        foreach($configIndexes as $index => $config)
            $cmd[] = $this->_prepareIndex($index , $config , true);

        return $cmd;
    }

    /**
     * Prepare list of indexes to be updated
     * @return array (
     *         'name'=>'indexname',
     *         'action'=>[drop/add],
     *         )
     */
    public function prepareIndexUpdates() : array
    {
        $updates = array();
        /*
         * Get indexes form database table
         */
        $indexes = $this->db->fetchAll('SHOW INDEX FROM `' . $this->model->table() . '`');
        $realIndexes = array();

        if(empty($indexes))
            return array();

        foreach($indexes as $k => $v)
        {

            $isFulltext = (boolean) ($v['Index_type'] === 'FULLTEXT');

            if(!isset($realIndexes[$v['Key_name']]))
                $realIndexes[$v['Key_name']] = array(
                    'columns' => array() ,
                    'fulltext' => $isFulltext ,
                    'unique' => (boolean) (! $v['Non_unique'])
                );

            $realIndexes[$v['Key_name']]['columns'][] = $v['Column_name'];
        }
        /*
         * Get indexes from object config
         */
        $configIndexes = $this->objectConfig->getIndexesConfig();
        $cmd = array();

        /*
         * Get indexes for Foreign Keys
         */
        $foreignKeys = $this->getOrmForeignKeys();
        /*
         * Drop invalid indexes
         */
        foreach($realIndexes as $index => $conf)
            if(!isset($configIndexes[$index]) && ! isset($foreignKeys[$index]))
                $updates[] = array(
                    'name' => $index ,
                    'action' => 'drop'
                );

        /*
       * Compare DB and Config indexes, create if not exist, drop and create if
       * invalid
       */
        if(!empty($configIndexes))
        {
            foreach($configIndexes as $index => $config)
            {
                if(! array_key_exists((string) $index , $realIndexes))
                {
                    $updates[] = array(
                        'name' => $index ,
                        'action' => 'add'
                    );
                }
                else
                {
                    if(!$this->_isSameIndexes($config , $realIndexes[$index]))
                    {
                        $updates[] = array(
                            'name' => $index ,
                            'action' => 'drop'
                        );
                        $updates[] = array(
                            'name' => $index ,
                            'action' => 'add'
                        );
                    }
                }
            }
        }
        return $updates;
    }

    /**
     * Get object foreign keys
     *
     * @return array
     */
    public function getOrmForeignKeys()
    {
        if(!self::$foreignKeys)
            return array();

        $data = $this->objectConfig->getForeignKeys();
        $keys = array();

        if(!empty($data))
        {
            foreach($data as $item)
            {
                $keyName = md5(implode(':' , $item));
                $keys[$keyName] = $item;
            }
        }
        return $keys;
    }

    public function prepareKeysUpdate($dropOnly = false)
    {
        $updates = array();
        $curTable = $this->model->table();

        /*
         * Get foreign keys form ORM
         */
        $configForeignKeys = $this->getOrmForeignKeys();

        /*
         * Get foreign keys form database table
         */
        $realKeys = $this->getForeignKeys($this->model->table());
        $realKeysNames = array();

        if(!empty($realKeys))
            $realKeys = \Utils::rekey('CONSTRAINT_NAME' , $realKeys);

        if(!empty($configForeignKeys))
        {
            foreach($configForeignKeys as $keyName => $item)
            {
                $realKeysNames[] = $keyName;
                if(! isset($realKeys[$keyName]) && ! $dropOnly)
                    $updates[] = array(
                        'name' => $keyName ,
                        'action' => 'add' ,
                        'config' => $item
                    );
            }
        }

        if(!empty($realKeys))
            foreach($realKeys as $name => $config)
                if(! in_array($name , $realKeysNames , true))
                    $updates[] = array(
                        'name' => $name ,
                        'action' => 'drop'
                    );

        return $updates;
    }

    /**
     * Get list of foreign keys for DB Table
     * @param string $dbTable
     * @return array
     * @todo refactor into Zend Metadata
     */
    public function getForeignKeys(string $dbTable)
    {
        $dbConfig = $this->db->getConfig();
        $sql = $this->db->select()
            ->from($this->db->quoteIdentifier('information_schema.TABLE_CONSTRAINTS'))
            ->where('`CONSTRAINT_SCHEMA` =?' , $dbConfig['dbname'])
            ->where('`TABLE_SCHEMA` =?' , $dbConfig['dbname'])
            ->where('`TABLE_NAME` =?' , $dbTable)
            ->where('`CONSTRAINT_TYPE` = "FOREIGN KEY"');

        return $this->db->fetchAll($sql);
    }

    /**
     * Compare existed index and its system config
     *
     * @param array $cfg1
     * @param array $cfg2
     * @return boolean
     */
    protected function _isSameIndexes(array $cfg1 , array $cfg2)
    {
        $colDiff = array_diff($cfg1['columns'] , $cfg2['columns']);
        $colDiffReverse = array_diff($cfg2['columns'] , $cfg1['columns']);

        if($cfg1['fulltext'] !== $cfg2['fulltext'] || $cfg1['unique'] !== $cfg2['unique'] || ! empty($colDiff) || !empty($colDiffReverse))
            return false;

        return true;
    }

    /**
     * Prepare Add INDEX command
     *
     * @param string $index
     * @param array $config
     * @param boolean $create - optional use create table mode
     * @return string
     */
    protected function _prepareIndex($index , array $config , $create = false)
    {
        if(isset($config['primary']) && $config['primary'])
        {
            if(! isset($config['columns'][0]))
                trigger_error('Invalid index config');

            if($create)
                return "\n" . ' PRIMARY KEY (`' . $config['columns'][0] . '`)';
            else
                return "\n" . ' ADD PRIMARY KEY (`' . $config['columns'][0] . '`)';
        }

        $createType = '';
        /*
         * Set key length for text column index
         */
        foreach($config['columns'] as &$col)
        {
            if($this->objectConfig->getField($col)->isText())
                $col = '`' . $col . '`(32)';
            else
                $col = '`' . $col . '`';
        }
        unset($col);

        $str = '`' . $index . '` (' . implode(',' , $config['columns']) . ')';

        if(isset($config['unique']) && $config['unique'])
            $createType = $indexType = 'UNIQUE';
        elseif(isset($config['fulltext']) && $config['fulltext'])
            $createType = $indexType = 'FULLTEXT';
        else
            $indexType = 'INDEX';

        if($create)
            return "\n" . ' ' . $createType . ' KEY ' . $str;
        else
            return "\n" . ' ADD ' . $indexType . ' ' . $str;
    }

    /**
     * Get property SQL query
     * @param Orm\Object\Config\Field $field
     * @return string
     */
    protected function _proppertySql($name , Orm\Object\Config\Field $field) : string
    {
        $property = new Orm\Object\Field\Property($name);
        $property->setData($field->__toArray());
        return $property->__toSql();
    }

    /**
     * Get SQL for table creation
     * @throws \Exception
     * @return string
     */
    protected function _sqlCreate()
    {
        $config = Config::factory($this->objectName);

        $fields = $config->get('fields');

        $sql = ' CREATE TABLE  `' . $this->model->table() . '` (';

        if(empty($fields))
            throw new \Exception('_sqlCreate :: empty properties');
        /*
       * Add columns
       */
        foreach($fields as $k => $v)
            $sql .= $this->_proppertySql($k , $v) . ' ,  ' . "\n";

        $indexes = $this->_createIndexes();

        /*
         * Add indexes
         */
        if(! empty($indexes))
            $sql .= ' ' . implode(', ' , $indexes);

        $sql .= "\n" . ') ENGINE=' . $config->get('engine') . '  DEFAULT CHARSET=utf8 ;';

        return $sql;
    }

    /**
     * Get Existing Columns
     *
     * @return \Zend\Db\Metadata\Object\TableObject
     */
    protected function getExistingColumns()
    {
        return $this->db->describeTable($this->model->table());
    }

    /**
     * Check if table exists
     * @param string $name - optional, table name,
     * @param boolean $addPrefix - optional append prefix, default false
     * @return boolean
     */
    public function tableExists(string $name = '', bool $addPrefix = false) : bool
    {
        if(empty($name))
            $name = $this->model->table();

        if($addPrefix)
            $name = $this->model->getDbPrefix() . $name;

        try{
            $tables = $this->db->listTables();
        }catch(\Exception $e){
            return false;
        }

        return in_array($name , $tables , true);
    }

    /**
     * Rename database table
     * @param string $newName - new table name (without prefix)
     * @return boolean
     */
    public function renameTable(string $newName) : bool
    {
        if($this->objectConfig->isLocked() || $this->objectConfig->isReadOnly()) {
            $this->errors[] = 'Can not build locked object ' . $this->objectConfig->getName();
            return false;
        }

        $sql = 'RENAME TABLE `' . $this->model->table() . '` TO `' . $this->model->getDbPrefix() . $newName . '` ;';

        try
        {
            $this->db->query($sql);
            $this->logSql($sql);
            $this->objectConfig->getConfig()->set('table' , $newName);
            $this->model->refreshTableInfo();
            return true;
        }
        catch(\Exception $e)
        {
            $this->errors[] = $e->getMessage() . ' <br>SQL: ' . $sql;
            return false;
        }
    }

    /**
     * Remove object
     * @return boolean
     */
    public function remove()
    {
        if($this->objectConfig->isLocked() || $this->objectConfig->isReadOnly()){
            $this->errors[] = 'Can not remove locked object table ' . $this->objectConfig->getName();
            return false;
        }

        try
        {
            $model = Model::factory($this->objectName);

            if(!$this->tableExists())
                return true;

            $db = $model->getDbConnection();

            $ddl = new Ddl\DropTable($model->table());
            $sql = $db->sql()->buildSqlString($ddl);
            $db->query($sql);
            $this->logSql($sql);
            return true;
        }
        catch(\Exception $e)
        {
            $this->errors[] = $e->getMessage() . ' <br>SQL: ' . $sql;
            return false;
        }
    }

    /**
     * Get error messages
     *
     * @return array
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * Check for broken object links
     * return array | boolean false
     */
    public function hasBrokenLinks()
    {
        $links = $this->objectConfig->getLinks();
        $brokenFields = array();

        if(!empty($links))
        {
            $brokenFields = array();
            foreach($links as $o => $fieldList)
                if(! Config::configExists($o))
                    foreach($fieldList as $field => $cfg)
                        $brokenFields[$field] = $o;
        }

        if(empty($brokenFields))
            return false;
        else
            return $brokenFields;
    }

    /**
     * Check relation objects
     */
    protected function checkRelations()
    {
        $list = $this->objectConfig->getManyToMany();
        if(!$list){
            return true;
        }

        foreach($list as $objectName=>$fields)
        {
            if(!empty($fields)){
                foreach($fields as $fieldName=>$linkType){
                    $relationObjectName = $this->objectConfig->getRelationsObject($fieldName);
                    if(!Config::configExists($relationObjectName)){
                        return false;
                    }
                }
            }
        }
        return true;
    }

    public function getObjectsUpdatesInfo()
    {
        $updates = [];
        $list = $this->objectConfig->getManyToMany();
        foreach($list as $objectName=>$fields)
        {
            if(!empty($fields)){
                foreach($fields as $fieldName=>$linkType){
                    $relationObjectName = $this->objectConfig->getRelationsObject($fieldName);
                    if(!Config::configExists($relationObjectName)){
                        $updates[$fieldName] = ['name' => $relationObjectName, 'action'=>'add'];
                    }
                }
            }
        }
        return $updates;
    }

    /**
     * Create Db_Object`s for relations
     * @throw Exception
     * @param $list
     * @return bool
     */
    protected function updateRelations($list) : bool
    {
        $lang = \Lang::lang();
        $usePrefix = true;
        $connection = $this->objectConfig->get('connection');

        $objectModel = Model::factory($this->objectName);
        $db = $objectModel->getDbConnection();
        $tablePrefix = $objectModel->getDbPrefix();

        $oConfigPath = Config::getConfigPath();
        $configDir  = \Dvelum\Config::storage()->getWrite() . $oConfigPath;

        $fieldList = \Dvelum\Config::storage()->get('objects/relations/fields.php');
        $indexesList = \Dvelum\Config::storage()->get('objects/relations/indexes.php');

        if(empty($fieldList)){
            $this->errors[] = 'Cannot get relation fields: ' . 'objects/relations/fields.php';
            return false;
        }

        if(empty($indexesList)){
            $this->errors[] = 'Cannot get relation indexes: ' . 'objects/relations/indexes.php';
            return false;
        }

        $fieldList= $fieldList->__toArray();
        $indexesList = $indexesList->__toArray();

        $fieldList['source_id']['link_config']['object'] = $this->objectName;


        foreach($list as $fieldName=>$info)
        {
            $newObjectName = $info['name'];
            $tableName = $newObjectName;

            $linkedObject = $this->objectConfig->getField($fieldName)->getLinkedObject();

            $fieldList['target_id']['link_config']['object'] = $linkedObject;

            $objectData = [
                'parent_object' => $this->objectName,
                'connection'=>$connection,
                'use_db_prefix'=>$usePrefix,
                'disable_keys' => false,
                'locked' => false,
                'readonly' => false,
                'primary_key' => 'id',
                'table' => $newObjectName,
                'engine' => 'InnoDB',
                'rev_control' => false,
                'link_title' => 'id',
                'save_history' => false,
                'system' => true,
                'fields' => $fieldList,
                'indexes' => $indexesList,
            ];

            $tables = $db->listTables();

            if($usePrefix){
                $tableName = $tablePrefix . $tableName;
            }

            if(in_array($tableName, $tables ,true)){
                $this->errors[] = $lang->get('INVALID_VALUE').' Table Name: '.$tableName .' '.$lang->get('SB_UNIQUE');
                return false;
            }

            if(file_exists($configDir . strtolower($newObjectName).'.php')){
                $this->errors[] = $lang->get('INVALID_VALUE').' Object Name: '.$newObjectName .' '.$lang->get('SB_UNIQUE');
                return false;
            }

            if(!is_dir($configDir) && !@mkdir($configDir, 0755, true)){
                $this->errors[] = $lang->get('CANT_WRITE_FS').' '.$configDir;
                return false;
            }

            /*
             * Write object config
             */
            if(!\Dvelum\Config\File\AsArray::create($configDir. $newObjectName . '.php')){
                $this->errors[] = $lang->get('CANT_WRITE_FS') . ' ' . $configDir . $newObjectName . '.php';
                return false;
            }

            $cfg = \Dvelum\Config::storage()->get($oConfigPath. strtolower($newObjectName).'.php' , false , false);

            if(!$cfg){
                $this->errors[] = 'Undefined config file '.$oConfigPath. strtolower($newObjectName).'.php';
                return false;
            }
            /**
             * @var \Dvelum\Config\File $cfg
             */
            $cfg->setData($objectData);
            $cfg->save();

            $objectConfig = Config::factory($newObjectName);
            $objectConfig->setObjectTitle($lang->get('RELATIONSHIP_MANY_TO_MANY').' '.$this->objectName.' & '.$linkedObject);

            if(!$objectConfig->save()){
                $this->errors[] = $lang->get('CANT_WRITE_FS');
                return false;
            }
            /*
             * Build database
            */
            $builder = new Builder($newObjectName);
            $builder->build();
        }
    }
}
