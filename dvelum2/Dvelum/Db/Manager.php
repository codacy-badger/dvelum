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

namespace  Dvelum\Db;

use Dvelum\Config;
use Dvelum\Config\ConfigInterface;
use Dvelum\Db\ManagerInterface;

class Manager implements ManagerInterface
{
    protected $dbConnections = [];
    protected $dbConfigs = [];

    /**
     * @var ConfigInterface
     */
    protected $appConfig;

    /**
     * @param ConfigInterface $appConfig - Application config (main)
     */
    public function __construct(ConfigInterface $appConfig)
    {
        $this->appConfig = $appConfig;
    }

    /**
     * Get Database connection
     * @param string $name
     * @throws \Exception
     * @return Adapter
     */
    public function getDbConnection(string $name, ?string $workMode = null) : Adapter
    {
        if(empty($workMode)){
            $workMode = $this->appConfig->get('development');
        }

        if(!isset($this->dbConnections[$workMode][$name]))
        {
            $cfg = $this->getDbConfig($name);
            $cfg->set('driver', $cfg->get('adapter'));
            /*
             * Enable Db profiler for development mode Attention! Db Profiler causes
             * memory leaks at background tasks. (Dev mode)
             */
            if($this->appConfig->get('development')){
                $cfg->set('profiler' , true);
            }

            $db = $this->initConnection($cfg->__toArray());
            $this->dbConnections[$workMode][$name] = $db;
        }
        return $this->dbConnections[$workMode][$name];
    }

    /**
     * @param array $cfg
     * @return Adapter
     * @throws \Exception
     */
    public function initConnection(array $cfg) : Adapter
    {
        $db = new Adapter($cfg);

        if($this->appConfig->get('development')){
            $profiler = $db->getProfiler();
            if(!empty($profiler)){
                \Debug::addDbProfiler($profiler);
            }
        }
        /*
         * Set transaction isolation level
         */
        if(isset($cfg['transactionIsolationLevel'])){
            $level = $cfg['transactionIsolationLevel'];
            if(!empty($level) && $level!=='default'){
                $db->query('SET TRANSACTION ISOLATION LEVEL '.$level);
            }
        }
        return $db;
    }
    /**
     * Get Db Connection config
     * @param string $name
     * @throws \Exception
     * @return ConfigInterface
     */
    public function getDbConfig(string $name, string $workMode = null) : ConfigInterface
    {
        if(empty($workMode)){
            $workMode = $this->appConfig->get('development');
        }

        if($workMode == \Dvelum\App\Application::MODE_INSTALL)
            $workMode = \Dvelum\App\Application::MODE_DEVELOPMENT;

        if(!isset($this->dbConfigs[$workMode][$name]))
        {
            $dbConfigPaths = $this->appConfig->get('db_configs');

            if(!isset($dbConfigPaths[$workMode]))
                throw new \Exception('Invalid application work mode ' . $workMode);

            $this->dbConfigs[$workMode][$name] = Config\Factory::storage()->get($dbConfigPaths[$workMode]['dir'].$name.'.php' , true , false);
        }

        return $this->dbConfigs[$workMode][$name];
    }
}