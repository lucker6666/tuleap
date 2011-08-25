<?php
/**
 * Copyright (c) STMicroelectronics, 2011. All Rights Reserved.
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

class RequestHelpDBDriver {
    protected $dbh;
    protected $dsn;
    protected $user;
    protected $password;

    /**
     * Constructor
     *
     * @throws Exception
     *
     * @return void
     */
    public function __construct() {
        $pluginManager = PluginManager::instance();
        $p = $pluginManager->getPluginByName('requesthelp');
        if ($p->getProperty('db_host') && $p->getProperty('db_name')&& $p->getProperty('db_port') && $p->getProperty('db_user') && $p->getProperty('db_passwd')) {
            $this->dsn      = '//'.$p->getProperty('db_host').':'.$p->getProperty('db_port').'/'.$p->getProperty('db_name');
            $this->user     = $p->getProperty('db_user');
            $this->password = $p->getProperty('db_passwd');
        } else {
            throw new Exception('Unable to find valid connexion parameters, please check requesthelp conf file');
        }
    }

    /**
     * Setup the oci object to be used for DB connexion
     *
     * The DB connexion will be used to insert tickets in RIF remedy DB.
     *
     * @return dbh
     */
    public function getdbh() {
        if (!$this->dbh) {
            $this->dbh = oci_connect($this->user, $this->password, $this->dsn);
        }
        return $this->dbh;
    }

    /**
     * Insert the ticket in RIF DB
     *
     * @param String $summary     Ticket summary
     * @param String $description Ticket description
     * @param String $item        Type of the request
     * @param String $severity    Ticket severity
     * @param String $cc          People in CC
     * @param String $requester   Ticket requester
     *
     * @return String Remedy ticket id
     */
    public function createTicket($summary, $description, $item, $severity, $cc, $requester) {
        $pluginManager = PluginManager::instance();
        $p = $pluginManager->getPluginByName('requesthelp');
        $category  = $p->getProperty('remedy_category');
        $type      = $p->getProperty('remedy_type');

        if ($requester && $category && $type) {
            // This is the old way of using RIF table use it in case the procedure wont work
            /*$sql = "INSERT INTO RIF_REQUEST
                   (
                   CATEGORY,
                   TYPE,
                   ITEM,
                   REQUESTER_NAME,
                   SUMMARY,
                   DESCRIPTION,
                   SEVERITY,
                   INSERTION_DATE,
                   REQUEST_STATUS,
                   REQUESTER_LOGIN,
                   CC_MAIL_IDS,
                   RIF_ID
                   ) VALUES (
                   '".$category."',
                   '".$type."',
                   '".$item."',
                   '".$requester."',
                   '".$this->escapeString($summary)."',
                   '".$this->escapeString($description)."',
                   '".$severity."',
                   sysdate,
                   'NEW',
                   '".$requester."',
                   '".$this->escapeString($cc)."',
                   RIF_REQUEST_SEQ.NEXTVAL
                   )";*/
            $sql = "BEGIN
                        INSERT_RIF_ENTRY(
                            '".$category."',
                            '".$type."',
                            '".$item."',
                            '".$requester."',
                            '".$this->escapeString($summary)."',
                            '".$this->escapeString($description)."',
                            '".$severity."',
                            '".$this->escapeString($cc)."',
                            '".$requester."',
                            :OUT
                        );
                    END;";
            $stid = @oci_parse($this->dbh, $sql);
            if ($stid) {
                $rifId = 0;
                @oci_bind_by_name($stid, ":OUT", &$rifId, 30);
                if (@oci_execute($stid)) {
                    $sql= 'SELECT TICKET_ID, REQUEST_STATUS FROM RIF_REQUEST WHERE RIF_ID = '.$rifId;
                    $stid = @oci_parse($this->dbh, $sql);
                    if ($stid) {
                        $inserted = false;
                        while (!$inserted) {
                            if (@oci_execute($stid)) {
                                $row = @oci_fetch_array($stid, OCI_ASSOC);
                                if ($row && $row['REQUEST_STATUS'] != 'NEW') {
                                    $inserted = true;
                                    if ($row['REQUEST_STATUS'] == 'CLOSE') {
                                        return $row['TICKET_ID'];
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        return false;
    }

    /**
     * Escape string for Oracle
     * /!\ replacing ' by '' may not be sufficient to escape strings for Oracle /!\
     *
     * @param String $str String to escape
     *
     * @return String
     */
    function escapeString($str) {
        return str_replace("'", "''", $str);
    }
}
?>