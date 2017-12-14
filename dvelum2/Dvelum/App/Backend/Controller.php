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

namespace Dvelum\App\Backend;

use Dvelum\App;
use Dvelum\Config;
use Dvelum\Config\ConfigInterface;
use Dvelum\Orm\Model;
use Dvelum\App\Session;
use Dvelum\Lang;
use Dvelum\View;
use Dvelum\Request;
use Dvelum\Resource;
use Dvelum\Response;

class Controller extends App\Controller
{
    /**
     * Controller configuration
     * @var ConfigInterface
     */
    protected $config;
    /**
     * Localization adapter
     * @var Lang
     */
    protected $lang;
    /**
     * Module id assigned to controller;
     * Is to be defined in child class
     * Is used for controlling access permissions
     *
     * @var string
     */
    protected $module;
    /**
     * Current Orm\Object name
     * @var string
     */
    protected $objectName = false;

    /**
     * @var Config\Adapter
     */
    protected $backofficeConfig;

    /**
     * @var App\Module\Acl
     */
    protected $moduleAcl;

    /**
     * Link to User object (current user)
     * @var Session\User
     */
    protected $user;

    /**
     * Controller constructor.
     * @param Request $request
     * @param Response $response
     */
    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);

        $this->backofficeConfig = Config::storage()->get('backend.php');
        $this->config = $this->getConfig();
        $this->module = $this->getModule();
        $this->lang = Lang::lang();
        $this->objectName = $this->getObjectName();

        $this->initSession();
    }

    protected function initSession() : bool
    {
        $auth = new App\Auth($this->request, $this->appConfig);

        if($this->request->get('logout' , 'boolean' , false)){
            $auth->logout();
            if(!$this->request->isAjax()){
                $this->response->redirect($this->request->url([$this->appConfig->get('adminPath')]));
                return false;
            }
        }

        $this->user = $auth->auth();

        if(!$this->user->isAuthorized() || !$this->user->isAdmin())
        {
            if($this->request->isAjax()){
                $this->response->error($this->lang->get('MSG_AUTHORIZE'));
                return false;
            }else{
                $this->loginAction();
                return true;
            }
        }
        $this->moduleAcl = $this->user->getModuleAcl();
        
        /*
         * Check is valid module requested
         */
        if(!$this->validateModule()){
            return false;
        }

       /*
        * Check CSRF token
        */
        if($this->backofficeConfig->get('use_csrf_token') && $this->request->hasPost()) {
           if(!$this->validateCsrfToken()){
               return false;
           }
        }

        if(!$this->checkCanView()){
            return false;
        }

        return true;
    }

    /**
     * Check view permissions
     * @return bool
     */
    protected function checkCanView() : bool
    {
        if(!$this->moduleAcl->canView($this->module)){
            $this->response->error($this->lang->get('CANT_VIEW'));
            return false;
        }
        return true;
    }

    /**
     * Check edit permissions
     * @return bool
     */
    protected function checkCanEdit() : bool
    {
        if(!$this->moduleAcl->canEdit($this->module)){
            $this->response->error($this->lang->get('CANT_MODIFY'));
            return false;
        }
        return true;
    }

    /**
     * Check delete permissions
     * @return bool
     */
    protected function checkCanDelete() : bool
    {
        if(!$this->moduleAcl->canDelete($this->module)){
            $this->response->error($this->lang->get('CANT_DELETE'));
            return false;
        }
        return true;
    }

    /**
     * Check publish permissions
     * @return bool
     */
    protected function checkCanPublish() : bool
    {
        if(!$this->moduleAcl->canPublish($this->module)){
            $this->response->error($this->lang->get('CANT_PABLISH'));
            return false;
        }
        return true;
    }

    /**
     * Validate CSRF security token
     * @return bool
     */
    protected function validateCsrfToken() : bool
    {
        $csrf = new \Security_Csrf();
        $csrf->setOptions([
            'lifetime' => $this->backofficeConfig->get('use_csrf_token_lifetime'),
            'cleanupLimit' => $this->backofficeConfig->get('use_csrf_token_garbage_limit')
        ]);

        if(!$csrf->checkHeader() && !$csrf->checkPost()){
            $this->response->error($this->lang->get('MSG_NEED_CSRF_TOKEN'));
            return false;
        }
        return true;
    }

    protected function validateModule() : bool
    {
        $moduleManager = new \Modules_Manager();

        if(in_array($this->module, $this->backofficeConfig->get('system_controllers'),true) || $this->module == 'index'){
            return true;
        }

        /*
         * Redirect for undefined module
         */
        if(!$moduleManager->isValidModule($this->module)){
            $this->response->error($this->lang->get('WRONG_REQUEST'));
            return false;
        }

        $moduleCfg = $moduleManager->getModuleConfig($this->module);

        /*
         * disabled module
         */
        if($moduleCfg['active'] == false){
            $this->response->error($this->lang->get('CANT_VIEW'));
            return false;
        }

        /*
         * dev module at production
         */
        if($moduleCfg['dev'] && ! $this->appConfig['development']){
            $this->response->error($this->lang->get('CANT_VIEW'));
            return false;
        }

        return true;
    }



    /**
     * Get controller configuration
     * @return ConfigInterface
     */
    protected function getConfig() : ConfigInterface
    {
        return Config::storage()->get('backend/controller.php');
    }

    /**
     * Get module name of the current class
     * @throws \Exception
     * @return string
     */
    public function getModule() : string
    {
        $manager = new \Modules_Manager();
        $module =  $manager->getControllerModule(get_called_class());
        if(empty($module)){
            throw new \Exception('Undefined module');
        }
        return $module;
    }

    /**
     * Get name of the object, which edits the controller
     * @return string
     */
    public function getObjectName() : string
    {
        return str_replace(array('Backend_', '_Controller','\\Backend\\','\\Controller') , '' , get_called_class());
    }

    /**
     * Default action
     */
    public function indexAction()
    {
        $this->includeScripts();

        $this->resource->addInlineJs('
	        var canEdit = ' . intval($this->moduleAcl->canEdit($this->module)) . ';
	        var canDelete = ' . intval($this->moduleAcl->canDelete($this->module)) . ';
	    ');

        $objectName = $this->getObjectName();
        if(!empty($objectName)){
            $objectConfig = \Dvelum\Orm\Object\Config::factory($this->getObjectName());

            if($objectConfig->isRevControl()){
                $this->resource->addInlineJs('
                    var canPublish = ' . intval($this->moduleAcl->canPublish($this->module)) . ';
                ');
            }
        }

        $this->includeScripts();

        $modulesConfig = Config\Factory::config(Config\Factory::File_Array , $this->appConfig->get('backend_modules'));
        $moduleCfg = $modulesConfig->get($this->module);

        if(strlen($moduleCfg['designer']))
            $this->runDesignerProject($moduleCfg['designer']);
        else
            if(file_exists($this->appConfig->get('jsPath').'app/system/crud/' . strtolower($this->module) . '.js'))
                $this->resource->addJs('/js/app/system/crud/' . strtolower($this->module) .'.js' , 4);
    }

    /**
     * Include required JavaScript files defined in the configuration file
     */
    public function includeScripts()
    {
        $media = Model::factory('Medialib');
        $media->includeScripts();
        $cfg = Config::storage()->get('js_inc_backend.php');

        if($cfg->getCount())
        {
            $js = $cfg->get('js');
            if(!empty($js))
                foreach($js as $file => $config)
                    $this->resource->addJs($file , $config['order'] , $config['minified']);

            $css = $cfg->get('css');
            if(!empty($css))
                foreach($css as $file => $config)
                    $this->resource->addCss($file , $config['order']);
        }
    }

    /**
     * Run designer project
     * @param string $project - path to project file
     * @param string | boolean $renderTo
     */
    protected function runDesignerProject($project , $renderTo = false)
    {
        $manager = new \Designer_Manager($this->appConfig);
        $project = $manager->findWorkingCopy($project);
        $manager->renderProject($project, $renderTo, $this->module);
    }


    /**
     * Show login form
     */
    protected function loginAction()
    {
        $template = new View();
        $template->set('wwwRoot' , $this->appConfig->get('wwwroot'));
        $this->response->put($template->render('system/'.$this->backofficeConfig->get('theme') . '/login.php'));
        $this->response->send();
    }

    public function showPage()
    {
        $controllerCode = $this->request->getPart(1);
        $templatesPath = 'system/' . $this->backofficeConfig->get('theme') . '/';

        $page = \Page::getInstance();
        $page->setTemplatesPath($templatesPath);

        $wwwRoot = $this->appConfig->get('wwwRoot');
        $adminPath = $this->appConfig->get('adminPath');
        $urlDelimiter = $this->appConfig->get('urlDelimiter');

        /*
         * Define frontend JS variables
         */
        $res = Resource::factory();
        $res->addInlineJs('
            app.wwwRoot = "' . $wwwRoot . '";
        	app.admin = "' . $this->request->url([$adminPath]) . '";
        	app.delimiter = "' . $urlDelimiter . '";
        	app.root = "' . $this->request->url([$adminPath, $controllerCode,'']) . '";
        ');

        $modulesManager = new \Modules_Manager();
        /*
         * Load template
         */
        $template = new View();
        $template->disableCache();
        $template->setProperties(array(
            'wwwRoot' => $this->appConfig->get('wwwroot'),
            'page' => $page,
            'urlPath' => $controllerCode,
            'resource' => $res,
            'path' => $templatesPath,
            'adminPath' => $this->appConfig->get('adminPath'),
            'development' => $this->appConfig->get('development'),
            'version' => Config::storage()->get('versions.php')->get('core'),
            'lang' => $this->appConfig->get('language'),
            'modules' => $modulesManager->getList(),
            'userModules' => Session\User::factory()->getModuleAcl()->getAvailableModules(),
            'useCSRFToken' => $this->backofficeConfig->get('use_csrf_token'),
            'theme' => $this->backofficeConfig->get('theme')
        ));

        $this->response->put($template->render($templatesPath . 'layout.php'));
    }
}