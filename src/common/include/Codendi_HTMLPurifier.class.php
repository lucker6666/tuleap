<?php
/**
 * Copyright (c) Enalean, 2013. All Rights Reserved.
 * Copyright (c) STMicroelectronics, 2007. All Rights Reserved.
 *
 * Originally written by Manuel VACELET, 2007.
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

/**
 * Clean-up HTML code for user output.
 *
 * This class aims to purify the HTML code provided by a user for beeing
 * displayed saftly (remove XSS and make the HTML std compliant).
 * How to use it:
 * <pre>
 * require_once('pre.php');
 * require_once('common/include/Codendi_HTMLPurifier.class.php');
 * $crapy = '<a href="" onmouseover="alert(1);">testé</a>';
 * $hp =& Codendi_HTMLPurifier::instance();
 * $clean = $hp->purify($crapy);
 * </pre>
 */

define('CODENDI_PURIFIER_CONVERT_HTML', 0);
define('CODENDI_PURIFIER_STRIP_HTML', 1);
define('CODENDI_PURIFIER_BASIC',      5);
define('CODENDI_PURIFIER_BASIC_NOBR',      6);
define('CODENDI_PURIFIER_LIGHT',     10);
define('CODENDI_PURIFIER_FULL',      15);
define('CODENDI_PURIFIER_JS_QUOTE', 20);
define('CODENDI_PURIFIER_JS_DQUOTE', 25);
define('CODENDI_PURIFIER_DISABLED', 100);

class Codendi_HTMLPurifier {
    /**
     * Hold an instance of the class
     */
    private static $Codendi_HTMLPurifier_instance;

    /**
     * Constructor
     */
    protected function __construct() {
    }

    /**
     * Singleton access.
     *
     * @return Codendi_HTMLPurifier
     */
    public static function instance() {
        if (!isset(self::$Codendi_HTMLPurifier_instance)) {
            $c = __CLASS__;
            self::$Codendi_HTMLPurifier_instance = new $c;
        }
        return self::$Codendi_HTMLPurifier_instance;
    }

    private function setConfigAttribute(HTMLPurifier_Config $config, $key, $subkey, $value) {
        if (version_compare($config->version, '4.0.0') >= 0) {
            $config->set("$key.$subkey", $value);
        } else {
            $config->set($key, $subkey, $value);
        }
    }

    /**
     * Base configuration of HTML Purifier for codendi.
     */
    protected function getCodendiConfig() {
        $config = HTMLPurifier_Config::createDefault();
        $this->setConfigAttribute($config, 'Core', 'Encoding', 'UTF-8');
        $this->setConfigAttribute($config, 'Cache', 'SerializerPath', $GLOBALS['codendi_cache_dir']);
        return $config;
    }

    /**
     * Allow basic formatting markups and enable some Autoformat attributes
     * @see http://htmlpurifier.org/live/configdoc/plain.html#AutoFormat
     *
     */
    function getLightConfig() {
        $config = $this->getCodendiConfig();
        $this->setConfigAttribute($config, 'HTML', 'Allowed', $this->getLightConfigMarkups());
        $this->setConfigAttribute($config, 'AutoFormat', 'Linkify', true);
        return $config;
    }

    /**
     * Get allowed markups for light config
     *
     * This function defines the markups allowed for a light
     * formatting. This includes markups for lists, for paragraphs, hypertext
     * links, and content-based text.
     * Allowed makups:
     * - 'p', 'br'
     * - 'a[href|title|class]'
     * - 'ul', 'ol', 'li'
     * - 'cite', 'code', 'blockquote', 'strong', 'em', 'pre', 'b', 'i'
     */
    function getLightConfigMarkups() {
        $allowed = 'p,br,'.
                   'a[href|title|class],img[src|alt],'.
                   'ul,ol,li,'.
                   'cite,code,blockquote,strong,em,pre,b,i';
        return $allowed;
    }

    /**
     *
     */
    function getStripConfig() {
        $config = $this->getCodendiConfig();
        $this->setConfigAttribute($config, 'HTML', 'Allowed', '');
        return $config;
    }

    /**
     * HTML Purifier configuration factory
     */
    function getHPConfig($level) {
        $config = null;
        switch($level) {
        case CODENDI_PURIFIER_LIGHT:
            $config = $this->getLightConfig();
            break;

        case CODENDI_PURIFIER_FULL:
            $config = $this->getCodendiConfig();
            break;

        case CODENDI_PURIFIER_STRIP_HTML:
            $config = $this->getStripConfig();
            break;
        }
        return $config;
    }

    /**
     * Transform links and emails from text to html links
     *
     * @param String $data
     *
     * @return String
     */
    function makeLinks($data = '', $group_id = 0) {
        if(empty($data)) { return $data; }

        // www.yahoo.com => http://www.yahoo.com
        $data = preg_replace("/([ \t\n])www\./i","\\1http://www.",$data);

        // http://www.yahoo.com => <a href="...">...</a>

        // Special case for urls between brackets or double quotes
        // e.g. <http://www.google.com> or "http://www.google.com"
        // In some places (e.g. tracker follow-ups) the text is already encoded, so the brackets are replaced by &lt; and &gt; See SR #652.
        $data = preg_replace("/([[:alnum:]]+):\/\/([^[:space:]<]*)([[:alnum:]#?\/&=])&quot;/i", "\\1://\\2\\3\"", $data);
        $data = preg_replace("/([[:alnum:]]+):\/\/([^[:space:]<]*)([[:alnum:]#?\/&=])&#039;/i", "\\1://\\2\\3'", $data);
        $data = preg_replace("/([[:alnum:]]+):\/\/([^[:space:]<]*)([[:alnum:]#?\/&=])&gt;/i", "\\1://\\2\\3>", $data);
        // Now, replace
        $data = preg_replace("/([[:alnum:]]+):\/\/([^[:space:]<]*)([[:alnum:]#?\/&=])/i", "<a href=\"\\1://\\2\\3\" target=\"_blank\" target=\"_new\">\\1://\\2\\3</a>", $data);

	    // john.doe@yahoo.com => <a href="mailto:...">...</a>
        $data = preg_replace("/(([a-z0-9_]|\\-|\\.)+@([^[:space:]<&>]*)([[:alnum:]-]))/i", "<a href=\"mailto:\\1\" target=\"_new\">\\1</a>", $data);

        if ($group_id) {
            $reference_manager = $this->getReferenceManager();
            $reference_manager->insertReferences($data,$group_id);
        }

        return $data;
    }

    /**
     * Perform HTML purification depending of level purification required.
     *
     * There are 5 level of purification, from the most restrictive to most
     * permissive:
     * - CODENDI_PURIFIER_CONVERT_HTML (default)
     *   Transform HTML markups it in entities.
     *
     * - CODENDI_PURIFIER_STRIP_HTML
     *   Removes all HTML markups. Note: as we relly on HTML Purifier to
     *   perform this operation this option is not considered as secure as
     *   CONVERT_HTML. If you are looking for the most secure option please
     *   consider CONVERT_HTML.
     *
     * - CODENDI_PURIFIER_BASIC (need $groupId to be set for automagic links)
     *   Removes all user submitted HTML markups but:
     *    - transform typed URLs into clickable URLs.
     *    - transform autmagic links.
     *    - transform carrige return into HTML br markup.
     *
     * - CODENDI_PURIFIER_LIGHT
     *   First set of HTML formatting (@see getLightConfig() for allowed
     *   markups) plus all what is allowed by CODENDI_PURIFIER_BASIC.
     *
     * - CODENDI_PURIFIER_FULL
     *   Clean-up plain HTML using HTML Purifier rules (remove forms,
     *   javascript, ...). Warning: there is no longer codendi facilities
     *   (neither automagic links nor carrige return to br transformation).
     *
     * - CODENDI_PURIFIER_DISABLED
     *   No filter at all.
     */
    function purify($html, $level=0, $groupId=0) {
        $clean = '';
        switch($level) {
        case CODENDI_PURIFIER_DISABLED:
            $clean = $html;
            break;

        case CODENDI_PURIFIER_LIGHT:
            if (empty($html)) {
                $clean = $html;
                break;
            }
            if ($groupId) {
                $referenceManager = $this->getReferenceManager();
                $referenceManager->insertReferences($html,$groupId);
            }
        case CODENDI_PURIFIER_STRIP_HTML:
        case CODENDI_PURIFIER_FULL:
            require_once('HTMLPurifier.auto.php');
            $hp = HTMLPurifier::getInstance();

            $config = $this->getHPConfig($level);
            $clean = $hp->purify($html, $config);
            // Quite big object, it's better to unset it (memory).
            unset($config);
            break;

        case CODENDI_PURIFIER_BASIC:
            $clean = nl2br($this->makeLinks(htmlentities($html, ENT_QUOTES, 'UTF-8'), $groupId));
            break;
        case CODENDI_PURIFIER_BASIC_NOBR:
            $clean = $this->makeLinks(htmlentities($html, ENT_QUOTES, 'UTF-8'), $groupId);
            break;

        case CODENDI_PURIFIER_JS_QUOTE:
            $clean = preg_replace('/\<\/script\>/umsi', "</'+'script>", addslashes(preg_replace('/\\\n/ums', "
", $html)));
            break;
        case CODENDI_PURIFIER_JS_DQUOTE:
            $clean = preg_replace('/\<\/script\>/umsi', '</"+"script>', addslashes(preg_replace('/\\\n/ums', '
', $html)));
            break;
        case CODENDI_PURIFIER_CONVERT_HTML:
        default:
            $clean = htmlentities($html, ENT_QUOTES, 'UTF-8');
            break;
        }
        return $clean;
    }

    /**
     * Purify HTML and insert references
     *
     * @param String  $html Content to filter
     * @param Integer $group_id
     *
     * @return String
     */
    public function purifyHTMLWithReferences($html, $group_id) {
        return $this->makeLinks($this->purify($html, CODENDI_PURIFIER_FULL), $group_id);
    }

    public function purifyTextWithReferences($html, $group_id) {
        return $this->makeLinks($this->purify($html, CODENDI_PURIFIER_BASIC), $group_id);
    }

    function purifyMap($array, $level=0, $groupId=0) {
        return array_map(array(&$this, "purify"), $array, array($level), array($groupId));
    }

    /**
     * Returns an instance of ReferenceManager
     *
     * @return ReferenceManager
     */
    public function getReferenceManager() {
        return ReferenceManager::instance();
    }

}

?>
