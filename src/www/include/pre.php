<?php
//
// Copyright 2011-2013 (c) Enalean
// Copyright 1999-2000 (c) The SourceForge Crew
//
// SourceForge: Breaking Down the Barriers to Open Source Development
// http://sourceforge.net
//
// 

//various server utilities.
require_once('server.php');

if (version_compare(phpversion(), '5.1.6', '<')) {
    die('Tuleap must be run on a PHP 5.1.6 (or greater) engine');
}

if (server_is_php_version_equal_or_greater_than_53() == true) {
    if (!ini_get('date.timezone')) {
        date_default_timezone_set('Europe/Paris');
    }
}

// Defines all of the settings first (hosts, databases, etc.)
$local_inc = getenv('TULEAP_LOCAL_INC') ? getenv('TULEAP_LOCAL_INC') : getenv('CODENDI_LOCAL_INC');
if ( ! $local_inc ) {
    if (is_file('/etc/tuleap/conf/local.inc')) {
        $local_inc = '/etc/tuleap/conf/local.inc';
    } else {
        $local_inc = '/etc/codendi/conf/local.inc';
    }
}
require($local_inc);
require($GLOBALS['db_config_file']);
require_once('common/include/Config.class.php');
Config::load($GLOBALS['codendi_dir'] .'/src/etc/local.inc.dist'); //load the default settings
Config::load($local_inc);
Config::load($GLOBALS['db_config_file']);
if (isset($GLOBALS['DEBUG_MODE'])) {
    Config::load($GLOBALS['codendi_dir'] .'/src/etc/development.inc.dist');
    Config::load(dirname($local_inc).'/development.inc');
}

// Fix path if needed
if (isset($GLOBALS['htmlpurifier_dir'])) {
    ini_set('include_path', ini_get('include_path').PATH_SEPARATOR.$GLOBALS['htmlpurifier_dir']);
}
if (isset($GLOBALS['jpgraph_dir'])) {
    ini_set('include_path', ini_get('include_path').PATH_SEPARATOR.$GLOBALS['jpgraph_dir']);
}

define('TTF_DIR',isset($GLOBALS['ttf_font_dir']) ? $GLOBALS['ttf_font_dir'] : '/usr/share/fonts/');

require_once('common/autoload_zend.php');
require_once('common/autoload.php');


// Detect whether this file is called by a script running in cli mode, or in normal web mode
if (!defined('IS_SCRIPT')) {
    if (php_sapi_name() == "cli") {
        // Backend scripts should never ends because of lack of time or memory
        ini_set('max_execution_time', 0);
        ini_set('memory_limit', -1);

        define('IS_SCRIPT', true);
    } else {
        define('IS_SCRIPT', false);
    }
}

//{{{ Sanitize $_REQUEST : remove cookies
while(count($_REQUEST)) {
    array_pop($_REQUEST);
}
if (!ini_get('variables_order')) {
        $_REQUEST = array_merge($_GET, $_POST);
} else {
    $g_pos = strpos(strtolower(ini_get('variables_order')), 'g');
    $p_pos = strpos(strtolower(ini_get('variables_order')), 'p');
    if ($g_pos === FALSE) {
        if ($p_pos !== FALSE) {
            $_REQUEST = $_POST;
        }
    } else {
        if ($p_pos === FALSE) {
            $_REQUEST = $_GET;
        } else {
            if ($g_pos < $p_pos) {
                $first = '_GET';
                $second = '_POST';
            } else {
                $first = '_POST';
                $second = '_GET';
            }
            $_REQUEST = array_merge($$first, $$second);
        }
    }
}
//Cast group_id as int.
foreach(array(
        'group_id', 
        'atid', 
        'pv',
    ) as $variable) {
    if (isset($_REQUEST[$variable])) {
        $$variable = $_REQUEST[$variable] = $_GET[$variable] = $_POST[$variable] = (int)$_REQUEST[$variable];
    }
}
//}}}

//{{{ define undefined variables
if (!isset($GLOBALS['feedback'])) {
    $GLOBALS['feedback'] = "";  //By default the feedbak is empty
}

if (!IS_SCRIPT) {
    // Prevent "Pragma: no-cache" to be sent to user (break https & IE)
    session_cache_limiter(false);
    session_start();
    $cookie_manager = new CookieManager();
    $GLOBALS['session_hash'] = $cookie_manager->isCookie('session_hash') ? $cookie_manager->getCookie('session_hash') : false;
}
//}}}

// Create cache directory if needed
if (!file_exists($GLOBALS['codendi_cache_dir'])) {
      // This directory must be world reachable, but writable only by the web-server
      mkdir($GLOBALS['codendi_cache_dir'], 0755);
}

// Instantiate System Event listener
$system_event_manager = SystemEventManager::instance();

//Load plugins
$plugin_manager =& PluginManager::instance();
$plugin_manager->loadPlugins();

$feedback=''; // Initialize global var

//library to determine browser settings
if(!IS_SCRIPT) {
    require_once('browser.php');
}

//Language
if (!$GLOBALS['sys_lang']) {
    $GLOBALS['sys_lang']="en_US";
}
$Language = new BaseLanguage($GLOBALS['sys_supported_languages'], $GLOBALS['sys_lang']);

//various html utilities
require_once('utils.php');

//database abstraction
require_once('database.php');
db_connect();

//security library
require_once('session.php');

//user functions like get_name, logged_in, etc
require_once('user.php');
$current_user = UserManager::instance()->getCurrentUser();


//library to set up context help
require_once('help.php');

//exit_error library
require_once('exit.php');

//various html libs like button bar, themable
require_once('html.php');

//left-hand nav library, themable
require_once('menu.php');



//insert this page view into the database
if(!IS_SCRIPT) {
    require_once('logger.php');
}

/*

	Timezone must come after logger to prevent messups


*/
if ($current_user->isLoggedIn()) {
    if (server_is_php_version_equal_or_greater_than_53() == true) {
        date_default_timezone_set($current_user->getTimezone());
    } else {
        putenv('TZ='.$current_user->getTimezone());
    }
}


//Set up the vars and theme functions 
require_once('theme.php');


// HTML layout class, may be overriden by the Theme class

if ($GLOBALS['sys_is_theme_custom']) {
    $GLOBALS['path_to_theme'] = $GLOBALS['sys_custom_themeroot'].'/'.$GLOBALS['sys_user_theme'];
} else {
    $GLOBALS['path_to_theme'] = $GLOBALS['sys_themeroot'].'/'.$GLOBALS['sys_user_theme'];
}
$name_of_theme_class = $GLOBALS['sys_user_theme'].'_Theme';

if (!file_exists($GLOBALS['path_to_theme'].'/'.$name_of_theme_class.'.class.php')) {
    //User wants a theme which doesn't exist
    //We're looking for default theme
    $GLOBALS['sys_user_theme'] = $GLOBALS['sys_themedefault'];
    $name_of_theme_class       = $GLOBALS['sys_user_theme'].'_Theme';
    if (is_dir($GLOBALS['sys_themeroot'].'/'.$GLOBALS['sys_user_theme'])) {
        $GLOBALS['sys_is_theme_custom'] = false;
        $GLOBALS['path_to_theme']       = $GLOBALS['sys_themeroot'].'/'.$GLOBALS['sys_user_theme'];
    } else {
        $GLOBALS['sys_is_theme_custom'] = true;
        $GLOBALS['path_to_theme']       = $GLOBALS['sys_custom_themeroot'].'/'.$GLOBALS['sys_user_theme'];
    }
}
require_once($GLOBALS['path_to_theme'].'/'.$name_of_theme_class.'.class.php');
$root_for_theme = ($GLOBALS['sys_is_theme_custom']?'/custom/':'/themes/').$GLOBALS['sys_user_theme'];
$HTML = new $name_of_theme_class($root_for_theme);
$GLOBALS['Response'] =& $HTML;

// If the Codendi Software license was declined by the site admin
// so stop all accesses to the site. Use exlicit path to avoid
// loading the license.php file in the register directory when
// invoking project/register.php
if(!IS_SCRIPT) {
require_once(dirname(__FILE__).'/license.php');
if (license_already_declined()) {
    exit_error($Language->getText('global','error'),$Language->getText('include_pre','site_admin_declines_license',$GLOBALS['sys_email_admin']));
}
}
// Check if anonymous user is allowed to browse the site
// Bypass the test for:
// a) all scripts where you are not logged in by definition
// b) if it is a local access from localhost 

/*
print "<p>DBG: SERVER_NAME = ".$_SERVER['SERVER_NAME'];
print "<p>DBG: sys_allow_anon= ".$GLOBALS['sys_allow_anon'];
print "<p>DBG: user_isloggedin= ".user_isloggedin();
print "<p>DBG: SCRIPT_NAME = ".$_SERVER['SCRIPT_NAME'];
*/

// Check URL for valid hostname and valid protocol

if (!IS_SCRIPT) {
    $urlVerifFactory = new URLVerificationFactory();
    $urlVerif = $urlVerifFactory->getURLVerification($_SERVER);
    $urlVerif->assertValidUrl($_SERVER);
}
$request = HTTPRequest::instance();

//Check post max size
if ($request->exist('postExpected') && !$request->exist('postReceived')) {
    $e = 'You tried to upload a file that is larger than the Codendi post_max_size setting.';
    exit_error('Error', $e);
}
if (Config::get('DEBUG_MODE')) {
    $GLOBALS['DEBUG_TIME_IN_PRE'] = microtime(1) - $GLOBALS['debug_time_start'];
}
?>
