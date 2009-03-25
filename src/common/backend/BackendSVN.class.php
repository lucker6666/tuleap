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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Codendi; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * 
 */

require_once('common/backend/Backend.class.php');
require_once('common/dao/UGroupDao.class.php');
require_once('common/project/UGroup.class.php');
require_once('common/dao/ServiceDao.class.php');


class BackendSVN extends Backend {


    protected $SVNApacheConfNeedUpdate;

    /**
     * Hold an instance of the class
     */
    protected static $_instance;
    
    /**
     * Backends are singletons
     */
    public static function instance() {
        if (!isset(self::$_instance)) {
            $c = __CLASS__;
            self::$_instance = new $c;
        }
        return self::$_instance;
    }

    /**
     * Constructor
     */
    protected function __construct() {
        parent::__construct();
    }


    // For mocking (unit tests)
    protected function _getUGroupDao() {
        return new UGroupDao(CodendiDataAccess::instance());
    }
    protected function _getUGroupFromRow($row) {
        return new UGroup($row);
    }
    function _getServiceDao() {
        return new ServiceDao(CodendiDataAccess::instance());
    }

    /**
     * Create project SVN repository
     * If the directory already exists, nothing is done.
     * @return true if repo is successfully created, false otherwise
     */
    public function createProjectSVN($group_id) {
        $project=$this->_getProjectManager()->getProject($group_id);
        if (!$project) return false;
        $unix_group_name=$project->getUnixName(false); // May contain upper-case letters
        $svn_dir=$GLOBALS['svn_prefix']."/".$unix_group_name;
        if (!is_dir($svn_dir)) {
            // Let's create a SVN repository for this group
            if (!mkdir($svn_dir)) {
                $this->log("Can't create project SVN dir: $svn_dir");
                return false;
            }
            system($GLOBALS['svnadmin_cmd']." create $svn_dir --fs-type fsfs");

            $this->recurseChownChgrp($svn_dir,$GLOBALS['sys_http_user'],$unix_group_name);
            system("chmod g+rw $svn_dir");
        }


        // Put in place the svn post-commit hook for email notification
        // if not present (if the file does not exist it is created)
        if ($project->isSVNTracked()) {
            $filename = "$svn_dir/hooks/post-commit";
            $update_hook=false;
            if (! is_file($filename)) {
                // File header
                $fp = fopen($filename, 'w');
                fwrite($fp, "#!/bin/sh\n");
                fwrite($fp, "# POST-COMMIT HOOK\n");
                fwrite($fp, "#\n");
                fwrite($fp, "# The post-commit hook is invoked after a commit.  Subversion runs\n");
                fwrite($fp, "# this hook by invoking a program (script, executable, binary, etc.)\n");
                fwrite($fp, "# named 'post-commit' (for which this file is a template) with the \n");
                fwrite($fp, "# following ordered arguments:\n");
                fwrite($fp, "#\n");
                fwrite($fp, "#   [1] REPOS-PATH   (the path to this repository)\n");
                fwrite($fp, "#   [2] REV          (the number of the revision just committed)\n\n");
                fclose($fp);
                $update_hook=true;
            } else {
                $file_array=file($filename);
                if (!in_array($this->block_marker_start,$file_array)) {
                    $update_hook=true;
                }
            }
            if ($update_hook) {
                $command  ='REPOS="$1"'."\n";
                $command .='REV="$2"'."\n";
                $command .=$GLOBALS['codendi_bin_prefix'].'/commit-email.pl "$REPOS" "$REV" 2>&1 >/dev/null';
                $this->addBlock($filename,$command);
                $this->chown($filename,$GLOBALS['sys_http_user']);
                $this->chgrp($filename,$unix_group_name);
                chmod("$filename",0775);
            }
        }

        if (!$this->updateSVNAccess($group_id)) {
            $this->log("Can't update SVN access file");
            return false;
        }

        return true;
    }


    // update Subversion DAV access control file if needed
    public function updateSVNAccess($group_id){
        $project=$this->_getProjectManager()->getProject($group_id);
        if (!$project) return false;
        $unix_group_name=$project->getUnixName(false); // May contain upper-case letters
        $svn_dir=$GLOBALS['svn_prefix']."/".$unix_group_name;
        if (!is_dir($svn_dir)) {
            $this->log("Can't update SVN Access file: project SVN repo is missing: $svn_dir");
            return false;
        }

        $svnaccess_file = $svn_dir."/.SVNAccessFile";
        $svnaccess_file_old = $svnaccess_file.".old";
        $svnaccess_file_new = $svnaccess_file.".new";
        // if you change these block markers also change them in
        // src/www/svn/svn_utils.php
	$default_block_start="# BEGIN CODEX DEFAULT SETTINGS - DO NOT REMOVE";
	$default_block_end="# END CODEX DEFAULT SETTINGS";
        $custom_perms='';
        $public_svn = 1; // TODO
		
        // Retrieve custom permissions, if any
        if (is_file("$svnaccess_file")) {
            $svnaccess_array = file($svnaccess_file);
            $configlines=false;
            foreach($svnaccess_array as $line) {
                if ($configlines) { 
                    $custom_perms .=$line; 
                }
                if (strpos($default_block_end,$line)) { $configlines=1;}
            }
        }

        $fp = fopen($svnaccess_file_new, 'w');
        fwrite($fp, "$default_block_start\n");
        fwrite($fp, "[groups]\n");

        // Special case for project members
        fwrite($fp, "members = ");
        $members_id_array=$project->getMembersUserNames();
        $first=true;
        foreach ($members_id_array as $member){
            if (!$first) fwrite($fp,', ');
            $first=false;
            fwrite($fp, $member['user_name']);
        }
        fwrite($fp,"\n");


        // Get all static ugroups
        $ugroup_dao =& $this->_getUGroupDao();
        $dar =& $ugroup_dao->searchByGroupId($group_id);
        foreach($dar as $row) {
            $ugroup =& $this->_getUGroupFromRow($row);
            // User names must be in lowercase
            $members_list = strtolower(implode(", ",$ugroup->getMembersUserName()));
            if ($ugroup->getName() && $members_list) {
                fwrite($fp, $ugroup->getName()." = ".$members_list."\n");
            }
        }
        fwrite($fp,"\n");
        fwrite($fp,"[/]\n");
        fwrite($fp,"* = r\n");
        //else { print SVNACCESS "* = \n";}
        fwrite($fp,"@members = rw\n");
        fwrite($fp, "$default_block_end\n");
        if ($custom_perms)
        fwrite($fp,$custom_perms);
        fclose($fp);

        // Backup existing file and install new one if they are different
        $this->installNewFileVersion($svnaccess_file_new,$svnaccess_file,$svnaccess_file_old);

        // set group ownership, admin user as owner so that
        // PHP scripts can write to it directly
        $this->chown($svnaccess_file,$GLOBALS['sys_http_user']);
        $this->chgrp($svnaccess_file,$unix_group_name);
        chmod("$svnaccess_file",0775);
        
        return true;
    }


    public function setSVNApacheConfNeedUpdate() {
        $this->SVNApacheConfNeedUpdate=true;
    }

    public function getSVNApacheConfNeedUpdate() {
        return $this->SVNApacheConfNeedUpdate;
    }


    // Add Subversion DAV definition for all projects in a dedicated Apache configuration file
    public function generateSVNApacheConf(){

        $svn_root_file = $GLOBALS['svn_root_file'];
        $svn_root_file_old = $svn_root_file.".old";
        $svn_root_file_new = $svn_root_file.".new";
        

        if (!$fp = fopen($svn_root_file_new, 'w')) {
            $this->log("Can't open file for writing: $svn_root_file_new");
            return false;
        }


        $service_dao =& $this->_getServiceDao();
        $dar =& $service_dao->searchActiveUnixGroupByUsedService('svn');
        foreach($dar as $row) {

            // Replace double quotes by single quotes in project name (conflict with Apache realm name)
            $group_name = strtr($row['group_name'],"\"","'");

            // Write repository definition
            fwrite($fp,"<Location /svnroot/".$row['unix_group_name'].">\n");
            fwrite($fp,"    DAV svn\n");
            fwrite($fp,"    SVNPath ".$GLOBALS['svn_prefix']."/".$row['unix_group_name']."\n");
            fwrite($fp,"    AuthzSVNAccessFile ".$GLOBALS['svn_prefix']."/".$row['unix_group_name']."/.SVNAccessFile\n");
            fwrite($fp,"    Require valid-user\n");
            fwrite($fp,"    AuthType Basic\n");
            fwrite($fp,"    AuthName \"Subversion Authorization (".$group_name.")\"\n");
            fwrite($fp,"    AuthMYSQLEnable on\n");
            fwrite($fp,"    AuthMySQLUser ".$GLOBALS['sys_dbauth_user']."\n");
            fwrite($fp,"    AuthMySQLPassword ".$GLOBALS['sys_dbauth_passwd']."\n");
            fwrite($fp,"    AuthMySQLDB codex\n");
            fwrite($fp,"    AuthMySQLUserTable \"user, user_group\"\n");
            fwrite($fp,"    AuthMySQLNameField user.user_name\n");
            fwrite($fp,"    AuthMySQLPasswordField user.unix_pw\n");
            fwrite($fp,"    AuthMySQLUserCondition \"(user.status='A' or (user.status='R' AND user_group.user_id=user.user_id and user_group.group_id=".$row['group_id']."))\"\n");
            fwrite($fp,"    SVNIndexXSLT \"/svn/repos-web/view/repos.xsl\"\n");
            if (!fwrite($fp,"</Location>\n\n")) {
                $this->log("Error while writing to $svn_root_file_new");
                return false;
            }
        }
        fclose($fp);


        // Backup existing file and install new one
        $this->installNewFileVersion($svn_root_file_new,$svn_root_file,$svn_root_file_old,true);

        //$this->chown($svn_root_file,$GLOBALS['sys_http_user']);
        //$this->chgrp($svn_root_file,$unix_group_name);
        //chmod("$svn_root_file",0775);
        
        return true;
    }



    /**
     * Archive SVN repository: stores a tgz in temp dir, and remove the directory
     */
    public function archiveProjectSVN($group_id) {
        $project=$this->_getProjectManager()->getProject($group_id);
        if (!$project) return false;
        $mydir=$GLOBALS['svn_prefix']."/".$project->getUnixName(false);
        $backupfile=$GLOBALS['tmp_dir']."/".$project->getUnixName(false)."-svn.tgz";

        if (is_dir($mydir)) {
            system("cd ".$GLOBALS['svn_prefix']."; tar cfz $backupfile ".$project->getUnixName(false));
            chmod($backupfile,0600);
            $this->recurseDeleteInDir($mydir);
            rmdir($mydir);
            return true;
       } else return false;
     }

}

?>