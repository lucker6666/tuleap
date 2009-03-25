<?php
/*
 * Copyright (c) Xerox, 2009. All Rights Reserved.
 *
 * Originally written by Nicolas Terray, 2009. Xerox Codendi Team.
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Codendi; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 */
class Combined {
    protected function getCombinedScripts() {
        $arr = array(
            '/scripts/prototype/prototype.js',
            '/scripts/scriptaculous/scriptaculous.js',
            '/scripts/scriptaculous/builder.js',
            '/scripts/scriptaculous/effects.js',
            '/scripts/scriptaculous/dragdrop.js',
            '/scripts/scriptaculous/controls.js',
            '/scripts/scriptaculous/slider.js',
            '/scripts/scriptaculous/sound.js',
            '/scripts/codendi/common.js',
            '/scripts/codendi/Tooltip.js',
            '/scripts/codendi/LayoutManager.js',
        );
        return $arr;
    }
    
    public function isCombined($script) {
        return in_array($script, $this->getCombinedScripts());
    }
    
    protected function getDestinationDir() {
        return $GLOBALS['codendi_dir'] .'/src/www/scripts/combined/';
    }
    
    protected function getSourceDir($script) {
        $matches = array();
        if (preg_match('`/plugins/([^/]+)/(.*)`', $script, $matches)) {
            return $GLOBALS['sys_pluginsroot']. $matches[1] . '/www/'. $matches[2];
        }
        return $GLOBALS['codendi_dir'] .'/src/www'. $script;
    }
    
    protected function onTheFly() {
        return true;
    }
    
    public function getScripts($scripts) {
        if ($this->onTheFly()) {
            $this->autoGenerate();
        }
        $html = '';
        if (!is_array($scripts)) {
            $scripts = array($scripts);
        }
        $combined = false;
        $combined_scripts = $this->getCombinedScripts();
        $combined_script  = $this->getLatestCombinedScript();
        foreach($scripts as $script) {
            $src = null;
            if (in_array($script, $combined_scripts)) {
                if (!$combined) {
                    $src = $combined_script;
                    $combined = true;
                }
            } else {
                $src = $script;
            }
            if ($src) {
                $html .= '<script type="text/javascript" src="'. $src .'"></script>';
            }
        }
        return $html;
    }
    
    public function generate() {
        foreach($this->getCombinedScripts() as $script) {
            $file = $this->getSourceDir($script);
            file_put_contents($this->getDestinationDir() . 'codendi-'. $_SERVER['REQUEST_TIME'] .'.js',
                              file_get_contents($file). PHP_EOL,
                              FILE_APPEND);
        }
    }
    
    protected function getLatestCombinedScript() {
        $src = $this->getSourceDir('/scripts/combined/codendi-');
        $files = glob($src .'*.js');
        rsort($files);
        return '/scripts/combined/'. basename($files[0]);
    }
    
    public function autoGenerate() {
        $auto_generate = true;
        $combined_script = $this->getLatestCombinedScript();
        $date = filemtime($this->getSourceDir($combined_script));
        if (filemtime(__FILE__) < $date) {
            $auto_generate = false;
            foreach($this->getCombinedScripts() as $script) {
                $file = $this->getSourceDir($script);
                if (filemtime($file) > $date) {
                    $auto_generate = true;
                    break;
                }
            }
        }
        if ($auto_generate) {
            unlink($this->getSourceDir($combined_script));
            $this->generate();
        }
    }
}
?>