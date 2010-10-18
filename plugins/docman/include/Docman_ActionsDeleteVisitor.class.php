<?php
/**
 * Copyright (c) STMicroelectronics, 2006. All Rights Reserved.
 *
 * Originally written by Manuel Vacelet, 2006
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
 * along with Codendi. If not, see <http://www.gnu.org/licenses/>.
 */
require_once('Docman_FileStorage.class.php');
require_once('Docman_VersionFactory.class.php');
class Docman_ActionsDeleteVisitor /* implements Visitor */ {
    
    function Docman_ActionsDeleteVisitor(&$file_storage, &$docman) {
        //More coherent to have only one delete date for a whole hierarchy.
        $this->deleteDate   = time();
        $this->file_storage =& $file_storage;
        $this->docman       =& $docman;
    }
    
    function visitFolder(&$item, $params = array()) {
        //delete all sub items before
        $items = $item->getAllItems();
        $parent =& $params['parent'];
        $one_item_has_not_been_deleted = false;
        if ($items->size()) {
            $it =& $items->iterator();
            while($it->valid()) {
                $o =& $it->current();
                $params['parent'] =& $item;
                if (!$o->accept($this, $params)) {
                    $one_item_has_not_been_deleted = true;
                }
                $it->next();
            }
        }
        
        if ($one_item_has_not_been_deleted) {
            $this->docman->feedback->log('error', $GLOBALS['Language']->getText('plugin_docman', 'error_delete_notempty', $item->getTitle()));
            return false;
        } else {
            //Mark the folder as deleted;
            $params['parent'] =& $parent;
            return $this->_deleteItem($item, $params);
        }
    }
    function visitDocument($item, $params = array(), $version = false) {
        if ($version) {
            return $this->_deleteVersion($item, $params);
        }
        //Mark the document as deleted
        return $this->_deleteItem($item, $params);
    }

    /**
    * Handles wiki page deletion with two different behaviors:
    * 1- User decides to keep wiki page in wiki service. In this case, we restrict access to that wiki page to wiki admins only.
    * 2- User decides to cascade deletion of the wiki page to wiki service too. In that case, we completely remove the wiki page from wiki service.
    *
    * @param Docman_Item $item
    * @param array $params params.
    *
    * @return boolean $deleted. True if there is no error.  False otherwise.
    */
    function visitWiki(&$item, $params = array()) {
        // delete the document.
        $deleted = $this->visitDocument($item, $params);

        if($deleted) {
            if(!$params['cascadeWikiPageDeletion']) {
                // grant a wiki permission only to wiki admins on the corresponding wiki page.
                $this->restrictAccess($item, $params);
            } else { // User have choosen to delete wiki page from wiki service too
                $dIF =& $this->_getItemFactory();
                if($dIF->deleteWikiPage($item->getPageName(), $item->getGroupId())){
                    $this->docman->feedback->log('info', $GLOBALS['Language']->getText('plugin_docman', 'docman_wiki_delete_wiki_page_success'));
                } else {
                    $this->docman->feedback->log('info', $GLOBALS['Language']->getText('plugin_docman', 'docman_wiki_delete_wiki_page_failed'));
                }
            }
        }
        return $deleted;
    }
    function visitLink(&$item, $params = array()) {
        return $this->visitDocument($item, $params);
    }
    function visitFile($item, $params = array()) {
        //delete a given version
        if ($params['version'] !== false) {
            if ($this->docman->userCanWrite($item->getId())) {
                $version_factory = $this->_getVersionFactory();
                if ($version = $version_factory->getSpecificVersion($item, $params['version'])) {
                    $this->file_storage->delete($version->getPath());
                }
               return $this->visitDocument($item, $params, true);
            } else {
                $this->docman->feedback->log('error', $GLOBALS['Language']->getText('plugin_docman', 'error_perms_delete_item', $item->getTitle()));
                return false;
            }
        } else {
            if ($this->docman->userCanWrite($item->getId())) {
                //Delete all versions before
                $version_factory =& $this->_getVersionFactory();
                if ($versions = $version_factory->getAllVersionForItem($item)) {
                    if (count($versions)) {
                        foreach ($versions as $key => $nop) {
                            $this->file_storage->delete($versions[$key]->getPath());
                        }
                    }
                }
                return $this->visitDocument($item, $params);
            } else {
                $this->docman->feedback->log('error', $GLOBALS['Language']->getText('plugin_docman', 'error_perms_delete_item', $item->getTitle()));
                return false;
            }
        }
    }
    function visitEmbeddedFile(&$item, $params = array()) {
        return $this->visitFile($item, $params);
    }

    function visitEmpty(&$item, $params = array()) {
        return $this->visitDocument($item, $params);
    }

    function restrictAccess($item, $params = array()) {
        // Check whether there is other references to this wiki page.
        $dao =& $this->_getItemDao();
        $referenced = $dao->isWikiPageReferenced($item->getPageName(), $item->getGroupId());
        if(!$referenced) {
            $dIF =& $this->_getItemFactory();
            $id_in_wiki = $dIF->getIdInWikiOfWikiPageItem($item->getPageName(), $item->getGroupId());
            // Restrict access to wiki admins if the page already exists in wiki.
            if($id_in_wiki !== null) {
                permission_clear_all($item->getGroupId(), 'WIKIPAGE_READ', $id_in_wiki, false);
                permission_add_ugroup($item->getGroupId(), 'WIKIPAGE_READ', $id_in_wiki, $GLOBALS['UGROUP_WIKI_ADMIN']);
            }
        }
    }

    function _deleteItem($item, $params) {
       if ($this->docman->userCanWrite($item->getId())) {

            // The event must be processed before the item is deleted
            $em =& $this->_getEventManager();
            $em->processEvent('plugin_docman_event_del', array(
                'group_id' => $item->getGroupId(),
                'item'     => &$item,
                'parent'   => &$params['parent'],
                'user'     => &$params['user'])
            );

            // Delete Lock if any
            $lF = $this->_getLockFactory();
            if($lF->itemIsLocked($item)) {
                $lF->unlock($item);
            }

            $item->setDeleteDate($this->deleteDate);
            $dIF =& $this->_getItemFactory();
            $dIF->delCutPreferenceForAllUsers($item->getId());
            $dIF->delCopyPreferenceForAllUsers($item->getId());
            $dao = $this->_getItemDao();
            $dao->updateFromRow($item->toRow());
            return true;
        } else {
            $this->docman->feedback->log('error', $GLOBALS['Language']->getText('plugin_docman', 'error_perms_delete_item', $item->getTitle()));
            return false;
        }
    }
    
    function _deleteVersion($item, $params) {
       if ($this->docman->userCanWrite($item->getId())) {

            // The event must be processed before the item is deleted
            $em =& $this->_getEventManager();
            $em->processEvent('plugin_docman_event_del_version', array(
                'group_id' => $item->getGroupId(),
                'item'     => &$item,
                'old_value'  => $params['label'].' (Version: '.$params['version'].')',
                'user'     => &$params['user'])
            );
          $version_factory = $this->_getVersionFactory();
                return $version_factory->deleteSpecificVersion($item, $params['version']);
        } else {
            $this->docman->feedback->log('error', $GLOBALS['Language']->getText('plugin_docman', 'error_perms_delete_item', $item->getTitle()));
            return false;
        }
    }
    function &_getEventManager() {
        return EventManager::instance();
    }
    var $version_factory;
    function &_getVersionFactory() {
        if (!$this->version_factory) {
        $this->version_factory = new Docman_VersionFactory();
        }
        return $this->version_factory;
    }
    var $item_factory;
    function &_getItemFactory() {
        if (!$this->item_factory) {
            $this->item_factory =& new Docman_ItemFactory();
        }
        return $this->item_factory;
    }
    var $lock_factory;
    function &_getLockFactory() {
        if (!$this->lock_factory) {
            $this->lock_factory =& new Docman_LockFactory();
        }
        return $this->lock_factory;
    }    
    function &_getFileStorage() {
        $fs = new Docman_FileStorage();
        return $fs;
    }
    function &_getItemDao() {
        $dao = new Docman_ItemDao(CodendiDataAccess::instance());
        return $dao;
    }
}
?>
