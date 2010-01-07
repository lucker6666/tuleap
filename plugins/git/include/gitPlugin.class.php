<?php

/**
  * Copyright (c) Xerox Corporation, Codendi Team, 2001-2009. All rights reserved
  *
  * This file is a part of Codendi.
  *
  * Codendi is free software; you can redistribute it and/or modify
  * it under the terms of the GNU General Public License as published by
  * the Free Software Foundation; either version 2 of the License, or
  * (at your option) any later version.
  *
  * Codendi is distributed in the hope that it will be useful,
  * but WITHOUT ANY WARRANTY; without even the implied warranty of
  * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
  * GNU General Public License for more details.
  *
  * You should have received a copy of the GNU General Public License
  * along with Codendi. If not, see <http://www.gnu.org/licenses/
  */

require_once('common/plugin/Plugin.class.php');
require_once('common/system_event/SystemEvent.class.php');
/**
 * GitPlugin
 */
class GitPlugin extends Plugin {


    public function __construct($id) {
        $this->Plugin($id);
        $this->setScope(Plugin::SCOPE_PROJECT);
        $this->_addHook('site_admin_option_hook', 'siteAdminHooks', false);
        $this->_addHook('cssfile', 'cssFile', false);
        $this->_addHook('javascript_file', 'jsFile', false);
        $this->_addHook(Event::GET_SYSTEM_EVENT_CLASS, 'getSystemEventClass', false);
    }

    public function getPluginInfo() {
        if (!is_a($this->pluginInfo, 'GitPluginInfo')) {
            require_once('GitPluginInfo.class.php');
            $this->pluginInfo = new GitPluginInfo($this);
        }
        return $this->pluginInfo;
    }

    public function siteAdminHooks($params) {
        echo '<li><a href="'.$this->getPluginPath().'/">Git</a></li>';
    }

    public function cssFile($params) {
        // Only show the stylesheet if we're actually in the Git pages.
        // This stops styles inadvertently clashing with the main site.
        if (strpos($_SERVER['REQUEST_URI'], $this->getPluginPath()) === 0) {
            echo '<link rel="stylesheet" type="text/css" href="'.$this->getThemePath().'/css/style.css" />';
        }
    }

    public function jsFile() {        
        // Only show the javascript if we're actually in the Git pages.       
        if (strpos($_SERVER['REQUEST_URI'], $this->getPluginPath()) === 0) {            
            echo '<script type="text/javascript" src="'.$this->getPluginPath().'/git.js"></script>';
        }
    }

    /**
     *This callback make SystemEvent manager knows about git plugin System Events
     * @param <type> $params
     */
    public function getSystemEventClass($params) {
        switch($params['type']) {
            case 'GIT_REPO_CREATE' :
                require_once(dirname(__FILE__).'/events/SystemEvent_GIT_REPO_CREATE.class.php');
                $params['class'] = 'SystemEvent_GIT_REPO_CREATE';
                break;
            case 'GIT_REPO_CLONE' :
                require_once(dirname(__FILE__).'/events/SystemEvent_GIT_REPO_CLONE.class.php');
                $params['class'] = 'SystemEvent_GIT_REPO_CLONE';
                break;
            case 'GIT_REPO_DELETE' :
                require_once(dirname(__FILE__).'/events/SystemEvent_GIT_REPO_DELETE.class.php');
                $params['class'] = 'SystemEvent_GIT_REPO_DELETE';
                break;
            case 'GIT_REPO_ACCESS':
                require_once(dirname(__FILE__).'/events/SystemEvent_GIT_REPO_ACCESS.class.php');
                $params['class'] = 'SystemEvent_GIT_REPO_ACCESS';
                break;
            default:
                break;
        }
    }

    public function process() {
        require_once('Git.class.php');
        $controler = new Git($this);
        $controler->process();
    }
}

?>