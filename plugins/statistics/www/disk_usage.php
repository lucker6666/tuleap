<?php
/**
 * Copyright (c) STMicroelectronics, 2009. All Rights Reserved.
 *
 * Originally written by Manuel VACELET, 2009
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

require 'pre.php';
require_once dirname(__FILE__).'/../include/Statistics_DiskUsageHtml.class.php';

// First, check plugin availability
$pluginManager = PluginManager::instance();
$p = $pluginManager->getPluginByName('statistics');
if (!$p || !$pluginManager->isPluginAvailable($p)) {
    header('Location: '.get_server_url());
}

// Grant access only to site admin
if (!UserManager::instance()->getCurrentUser()->isSuperUser()) {
    header('Location: '.get_server_url());
}

$duMgr  = new Statistics_DiskUsageManager();
$duHtml = new Statistics_DiskUsageHtml($duMgr);

$vFunc = new Valid_WhiteList('func', array('show_one_project', 'show_top_projects', 'show_service', 'show_top_users', 'show_one_user'));
$vFunc->required();
if ($request->valid($vFunc)) {
    $func = $request->get('func');
} else {
    $func = '';
}

$vStartDate = new Valid('start_date');
$vStartDate->addRule(new Rule_Date());
$vStartDate->required();
if ($request->valid($vStartDate)) {
    $startDate = $request->get('start_date');
} else {
    $startDate = date('Y-m-d', strtotime('-3 month'));
}

$vEndDate = new Valid('end_date');
$vEndDate->addRule(new Rule_Date());
$vEndDate->required();
if ($request->valid($vStartDate)) {
    $endDate = $request->get('end_date');
} else {
    $endDate = date('Y-m-d');
}

if (strtotime($startDate) >= strtotime($endDate)) {
    $GLOBALS['Response']->addFeedback('error', 'You made a mistake in selecting period. Please try again!');
}

$vGroupId = new Valid_UInt('group_id');
$vGroupId->required();
if ($request->valid($vGroupId)) {
    $groupId = $request->get('group_id');
} else {
    $groupId = '';
}

$vUserId = new Valid_UInt('user_id');
$vUserId->required();
if ($request->valid($vUserId)) {
    $userId = $request->get('user_id');
} else {
    $userId = '';
}

$vServices = new Valid_WhiteList('services', $duMgr->getProjectServices());
$vServices->required();
if ($request->validArray($vServices)) {
    $selectedServices = $request->get('services');
} else {
    $selectedServices = array(Statistics_DiskUsageManager::SVN);
}

$groupByDate = array('Day', 'Week', 'Month', 'Year');
$vGroupBy = new Valid_WhiteList('group_by', $groupByDate);
$vGroupBy->required();
if ($request->valid($vGroupBy)) {
    $selectedGroupByDate = $request->get('group_by');
} else {
    $selectedGroupByDate = 'Week';
}

$vRelative = new Valid_WhiteList('relative', array('true'));
$vRelative->required();
if ($request->valid($vRelative)) {
    $relative = true;
} else {
    $relative = false;
}

$vOrder = new Valid_WhiteList('order', array('start_size', 'end_size', 'evolution', 'evolution_rate'));
$vOrder->required();
if ($request->valid($vOrder)) {
    $order = $request->get('order');
} else {
    $order = 'end_size';
}

$title = 'Disk usage';
$GLOBALS['HTML']->includeCalendarScripts();
$GLOBALS['HTML']->header(array('title' => $title));
echo '<h1>'.$title.'</h1>';

echo '<p>
<ul>
  <li><a href="?">Summary</a></li>
  <li><a href="?func=show_top_projects">Top projects</a></li>
  <li><a href="?func=show_one_project">One project details</a></li>
  <li><a href="?func=show_top_users">Top users</a></li>
  <li><a href="?func=show_one_user">One user details</a></li>
  <li><a href="?func=show_service">Services</a></li>
</ul>
</p>
<p><a href="'.$p->getPluginPath().'/">&lt;&lt;Back to all statistics</a></p>';


switch ($func) {
    case 'show_service':
        echo '<h2>Usage per service</h2>';
        $duHtml->getDataPerService();

        // Prepare params
        $selected = array();
        $urlParam    = '';
        $first    = true;
        foreach ($selectedServices as $serv) {
            if ($first != true) {
                $urlParam .= '&';
            }
            $urlParam           .= 'services[]='.$serv;
            $selected[$serv] = true;
            $first           = false;
        }
        
        echo '<h2>Service growth over the time</h2>';
        
        echo '<form name="progress_by_service" method="get" action="?">';
        echo '<input type="hidden" name="func" value="show_service" />';

        foreach ($duMgr->getProjectServices() as $service) {
            $sel = '';
            if (isset($selected[$service])) {
                $sel = ' checked="checked"';
            }
            echo '<input type="checkbox" name="services[]" value="'.$service.'"'.$sel.'/>'.$duHtml->getServiceTitle($service).'<br/>';
        }
        echo '<label>Group by:</label>';
        echo html_build_select_box_from_array($groupByDate, 'group_by', $selectedGroupByDate, 1).'<br />';

        echo '<label>Start:</label>';
        echo (html_field_date('start_date', $startDate, false, 10, 10, 'progress_by_service', false)).'<br />';

        echo '<label>End:</label>';
        echo (html_field_date('end_date', $endDate, false, 10, 10, 'progress_by_service', false)).'<br />';

        $sel = '';
        if ($relative) {
            $sel = ' checked="checked"';
            $urlParam .= '&relative=true';
        }
        echo '<input type="checkbox" name="relative" value="true" '.$sel.'/>';
        echo '<label>Relative Y-axis (depend of data set values):</label><br/>';
        
        echo '<input type="submit" value="'.$GLOBALS['Language']->getText('global', 'btn_submit').'"/>';
        echo '</form>';

        $urlParam .= '&start_date='.$startDate.'&end_date='.$endDate;
        $urlParam .= '&group_by='.$selectedGroupByDate;
        $urlParam .= '&graph_type=graph_service';
        echo '<p><img src="disk_usage_graph.php?'.$urlParam.'"  title="Test result" /></p>';

        $duHtml->getServiceEvolutionForPeriod($startDate , $endDate);
        
        break;

    case 'show_top_projects':
        $urlParam = '';
        $urlParam .= '?func=show_top_projects&start_date='.$startDate.'&end_date='.$endDate;

        echo '<h2>Usage per projects</h2>';
        echo '<form name="top_projects" method="get" action="?">';
        echo '<input type="hidden" name="func" value="show_top_projects" />';

        echo '<label>Start:</label>';
        echo (html_field_date('start_date', $startDate, false, 10, 10, 'top_projects', false)).'<br />';

        echo '<label>End:</label>';
        echo (html_field_date('end_date', $endDate, false, 10, 10, 'top_projects', false)).'<br />';

        echo '<input type="submit" value="'.$GLOBALS['Language']->getText('global', 'btn_submit').'"/>';
        echo '</form>';

        $duHtml->getTopProjects($startDate, $endDate, $order, $urlParam);

        break;

    case 'show_one_project':
        // Prepare params
        $urlParam    = '';
                
        echo '<h2>Project growth over the time</h2>';
        
        echo '<form name="progress_by_project" method="get" action="?">';
        echo '<input type="hidden" name="func" value="show_one_project" />';

        echo '<label> Project: </label>';
        echo '<input type="text" name="group_id" id="plugin_statistics_project" value="'.$groupId.'" />';
       
        echo '<label>Group by:</label>';
        echo html_build_select_box_from_array($groupByDate, 'group_by', $selectedGroupByDate, 1).'<br />';

        echo '<label>Start:</label>';
        echo (html_field_date('start_date', $startDate, false, 10, 10, 'progress_by_project', false)).'<br />';

        echo '<label>End:</label>';
        echo (html_field_date('end_date', $endDate, false, 10, 10, 'progress_by_project', false)).'<br />';
       
        
        $sel = '';
        if ($relative) {
            $sel = ' checked="checked"';
            $urlParam .= '&relative=true';
        }
        echo '<input type="checkbox" name="relative" value="true" '.$sel.'/>';
        echo '<label>Relative Y-axis (depend of data set values):</label><br/>';
        
        echo '<input type="submit" value="'.$GLOBALS['Language']->getText('global', 'btn_submit').'"/>';
        echo '</form>';
        
        if (($groupId) && ($startDate) && ($endDate)) {
            echo '<h3>Project details</h3>';
            $duHtml->getProject($groupId);
            $duHtml->getProjectEvolutionForPeriod($groupId , $startDate, $endDate);
            
            $urlParam .= 'start_date='.$startDate.'&end_date='.$endDate;
            $urlParam .= '&group_by='.$selectedGroupByDate;
            $urlParam .= '&group_id='.$groupId;
            $urlParam .= '&graph_type=graph_project';
            
            echo '<p><img src="disk_usage_graph.php?'.$urlParam.'"  title="Test result" /></p>';
        }    
    
        break;

    case 'show_top_users':
        $urlParam = '';
        $urlParam .= '?func=show_top_users&start_date='.$startDate.'&end_date='.$endDate;

        echo '<h2>Top Users</h2>';
        echo '<form name="top_users" method="get" action="?">';
        echo '<input type="hidden" name="func" value="show_top_users" />';

        echo '<label>Start:</label>';
        echo (html_field_date('start_date', $startDate, false, 10, 10, 'top_users', false)).'<br />';

        echo '<label>End:</label>';
        echo (html_field_date('end_date', $endDate, false, 10, 10, 'top_users', false)).'<br />';

        echo '<input type="submit" value="'.$GLOBALS['Language']->getText('global', 'btn_submit').'"/>';
        echo '</form>';

        $duHtml->getTopUsers($startDate, $endDate, $order, $urlParam);
        break;

    case 'show_one_user':
                  
        // Prepare params
        $urlParam    = '';
                
        echo '<h2>User growth over the time</h2>';
        
        echo '<form name="progress_by_user" method="get" action="?">';
        echo '<input type="hidden" name="func" value="show_one_user" />';

        echo '<label> User: </label>';
        echo '<input type="text" name="user_id" id="plugin_statistics_project" value="'.$userId.'" />';
       
        echo '<label>Group by:</label>';
        echo html_build_select_box_from_array($groupByDate, 'group_by', $selectedGroupByDate, 1).'<br />';

        echo '<label>Start:</label>';
        echo (html_field_date('start_date', $startDate, false, 10, 10, 'progress_by_user', false)).'<br />';

        echo '<label>End:</label>';
        echo (html_field_date('end_date', $endDate, false, 10, 10, 'progress_by_user', false)).'<br />';
       
        
        $sel = '';
        if ($relative) {
            $sel = ' checked="checked"';
            $urlParam .= '&relative=true';
        }
        echo '<input type="checkbox" name="relative" value="true" '.$sel.'/>';
        echo '<label>Relative Y-axis (depend of data set values):</label><br/>';
        
        echo '<input type="submit" value="'.$GLOBALS['Language']->getText('global', 'btn_submit').'"/>';
        echo '</form>';
        
        if (($userId) && ($startDate) && ($endDate)) {
            echo '<h3>User details</h3>';
            $duHtml->getUserDetails($userId);
            $duHtml->getUserEvolutionForPeriod($userId, $startDate, $endDate);
            
            $urlParam .= 'start_date='.$startDate.'&end_date='.$endDate;
            $urlParam .= '&group_by='.$selectedGroupByDate;
            $urlParam .= '&user_id='.$userId;
            $urlParam .= '&graph_type=graph_user';
            
            echo '<p><img src="disk_usage_graph.php?'.$urlParam.'"  title="Test result" /></p>';
        }    
        break;

    default:
}

$GLOBALS['HTML']->footer(array());

?>