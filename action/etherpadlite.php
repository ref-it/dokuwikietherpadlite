<?php
/**
 * DokuWiki Plugin etherpadlite (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Michael Braun <michael-dev@fami-braun.de>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

if (!defined('DOKU_LF')) define('DOKU_LF', "\n");
if (!defined('DOKU_TAB')) define('DOKU_TAB', "\t");
if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');

require_once DOKU_PLUGIN.'action.php';
require_once DOKU_PLUGIN.'etherpadlite/externals/etherpad-lite-client/etherpad-lite-client.php';

class action_plugin_etherpadlite_etherpadlite extends DokuWiki_Action_Plugin {
    public $domain;
    public $ep_url;
    public $ep_instance;
    public $ep_group;
    public $ep_url_args;
    public $groupid;
    public $client;
    public $clientname;

    public function register(Doku_Event_Handler $controller) {
        $controller->register_hook('TPL_METAHEADER_OUTPUT', 'BEFORE', $this, 'handle_tpl_metaheader_output');
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, 'handle_ajax');
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handle_logoutconvenience');
    }

    private function createEPInstance() {
        if (isset($this->instance)) {
            return;
        }
        $this->domain = trim($this->getConf('etherpadlite_domain'));
        if ($this->domain == "") {
            $this->domain = $_SERVER["HTTP_HOST"];
        }
        $this->ep_url = rtrim(trim($this->getConf('etherpadlite_url')),"/");
        $ep_key = trim($this->getConf('etherpadlite_apikey'));
        $this->ep_instance = new EtherpadLiteClient($ep_key, $this->ep_url."/api");
        $this->ep_group = trim($this->getConf('etherpadlite_group'));
        $this->ep_url_args = trim($this->getConf('etherpadlite_urlargs'));
        $this->groupid = $this->ep_instance->createGroupIfNotExistsFor($this->ep_group);
        $this->groupid = (string) $this->groupid->groupID;
        return;
    }

    private function getPageID() {
        global $meta, $rev;
        assert(is_array($meta[$rev]));
        if (!empty($this->ep_group)) {
            return $this->groupid."\$".$meta[$rev]["pageid"];
        } else {
            return $meta[$rev]["pageid"];
        }
    }

    private function renameCurrentPage() {
        global $meta, $rev, $ID, $pageid;

        assert(is_array($meta[$rev]));
        $pageid = $this->getPageID();

        $text = $this->ep_instance->getText($pageid);
        $text = (string) $text->text;

        $newpageid = md5(uniqid("dokuwiki:".md5($ID).":$rev:", true));
        if (!empty($this->ep_group)) {
            $this->ep_instance->createGroupPad($this->groupid, $newpageid, $text);
        } else {
            $this->ep_instance->createPad($newpageid, $text);
        }
        $this->ep_instance->deletePad($pageid);

        $meta[$rev]["pageid"] = $newpageid;
        $pageid = $this->getPageID();
    }

    public function handle_logoutconvenience(&$event,$param) {
        global $ACT;
        if ($ACT=='logout' && isset($_SESSION["ep_sessionID"])) {
             $this->createEPInstance();
             if (!empty($this->ep_group)) {
                 $this->ep_instance->deleteSession($_SESSION["ep_sessionID"]);
                 unset($_SESSION["ep_sessionID"]);
             }
        }
    }

    public function handle_ajax(&$event, $param) {
        if (class_exists("action_plugin_ipgroup")) {
          $plugin = new action_plugin_ipgroup();
          $plugin->start($event, $param);
        }

        $call = $event->data;
        //var_dump($call);
        if(method_exists($this, "handle_ajax_$call")) {
           header('Content-Type: application/json');
           try {
               $ret = $this->handle_ajax_inner($call);
           } catch (Exception $e) {
               $ret = [
                   "file" => __FILE__,
                   "line" => __LINE__,
                   "error" => "Server-Fehler (Pad-Plugin): ".$e->getMessage(),
                   "trace" => $e->getTraceAsString(),
                   "url" => $this->ep_url
               ];
           }
           print json_encode($ret);
           $event->preventDefault();
        }
    }

    private function handle_ajax_inner($call) {
        global $conf, $ID, $REV, $INFO, $rev, $meta, $pageid, $USERINFO;
        $this->createEPInstance();

        $this->client = $_SERVER['REMOTE_USER'];
        if(!$this->client) {
            $this->client = clientIP(true);
        }

        $this->clientname = $USERINFO["name"];
        if (empty($this->clientname)) {
            $this->clientname = $this->client;
        }

        $ID = cleanID($_POST['id']);
        if(empty($ID)) return;
        if (auth_quickaclcheck($ID) < AUTH_READ) {
            return [
                "file" => __FILE__,
                "line" => __LINE__,
                "error" => $this->getLang('Permission denied')
            ];
        }

        $REV = (int) $_POST["rev"];
        $INFO = pageinfo();
        $rev = (int) (($INFO['currentrev'] == '') ? $INFO['lastmod'] : $INFO['currentrev']);
        if ($rev == 0) {
            return [
                "file" => __FILE__,
                "line" => __LINE__,
                "error" => $this->getLang('You need to create (save) the non-empty page first.')
            ];
        }

        $meta = p_get_metadata($ID, "etherpadlite", METADATA_DONT_RENDER);
        $oldmeta = $meta;
        if (!is_array($meta)) {
            $meta = Array();
        }

        if (isset($meta[$rev])) {
            $pageid = $this->getPageID();
        } else {
            $pageid = NULL;
        }

        if (isset($_POST["isSaveable"])) {
            $_POST["isSaveable"] = ($_POST["isSaveable"] == "true");
        } else {
            $_POST["isSaveable"] = false;
        }

        if (!isset($_POST["accessPassword"])) {
            $_POST["accessPassword"] = "";
        }

        if (isset($_POST["readOnly"])) {
            $_POST["readOnly"] = ($_POST["readOnly"] == "true");
        }

        if (isset($meta[$rev]) && ($meta[$rev]["owner"] != $this->client)) {
            # PAD exists and is not owned by us
            $canWrite = ((!isset($meta[$rev]["writepw"]) || ($meta[$rev]["writepw"] == (string) $_POST["accessPassword"]))
                        && $INFO['writable']);
            $canRead = (((($meta[$rev]["readMode"] == "wikiread") || $INFO['writable'])
                        && (!isset($meta[$rev]["readpw"]) || $meta[$rev]["readpw"] == (string) $_POST["accessPassword"])
                        ) || $canWrite);
        } else { # no such pad or pad alread owned by me
            $canWrite = $_POST["isSaveable"] && $INFO['writable'];
            $canRead  = $INFO['writable'];
            $_POST["readOnly"] = !$canWrite;
        }

        # default to write-access request if pad not exists, otherwise prefer write-access over readonly-access
        if (!isset($_POST["readOnly"])) {
            if ($pageid !== NULL) {
                $_POST["readOnly"] = !$canWrite;
            } else {
                $_POST["readOnly"] = false;
            }
        }

        # the master editor is always editable
        $_POST["readOnly"] = $_POST["readOnly"] && !$_POST["isSaveable"];
        
        # check if pad is owned by somebody else than how can save it (wikilock)
        if (isset($meta[$rev]) && ($meta[$rev]["owner"] != $this->client) && $_POST["isSaveable"]) {
            return [
                "file" => __FILE__,
                "line" => __LINE__,
                "error" => sprintf($this->getLang('Permission denied - pad is owned by %s, who needs to lock (edit) the page.'), $meta[$rev]["owner"])
            ];
        }

        if ((!$canWrite) && (!$canRead || (!$_POST["readOnly"]))) {
            return [
                "file" => __FILE__,
                "line" => __LINE__,
                "error" => $this->getLang('Permission denied'),
                "askPassword" => (isset($meta[$rev]["readpw"]) || isset($meta[$rev]["writepw"]))
            ];
        }

        if($_POST["isSaveable"] && checklock($ID)) {
            return [
                "file" => __FILE__,
                "line" => __LINE__,
                "error" => $this->getLang('Permission denied - page locked by somebody else')
            ];
        }

        if ($_POST["isSaveable"]) {
            lock($ID);
        }

        $ret = $this->{"handle_ajax_$call"}();
        if ($meta != $oldmeta)
          p_set_metadata($ID, Array("etherpadlite" => $meta));
        return $ret;
    }

    private function getPageInfo() {
        global $conf, $ID, $REV, $INFO, $rev, $meta, $pageid;
        if (!empty($this->ep_group)) {
            $canPassword = ($meta[$rev]["owner"] == $this->client);
        } else {
            $canPassword = false;
        }

        // 2021-02-02: disable password functionality as dropped from etherpad lite, see https://github.com/michael-dev/dokuwikietherpadlite/issues/22
        $ret = [ "canPassword" => $canPassword ];
        $ret["encAMode"] = $meta[$rev]["encAMode"];
        $ret["readMode"] = $meta[$rev]["readMode"];
        $ret["writeMode"] = "wikiwrite";

        if (isset($meta[$rev]["readpw"])) {
            $ret["readpw"] = "***";
            $ret["readMode"] .= "+password";
        } else {
            $ret["readpw"] = "";
        }

        if (isset($meta[$rev]["writepw"])) {
            $ret["writepw"] = "***";
            $ret["writeMode"] .= "+password";
        } else {
            $ret["writepw"] = "";
        }

        $ret["name"] = "$pageid";

        if ($_POST['readOnly']) {
            $roid = (string) $this->ep_instance->getReadOnlyID($pageid)->readOnlyID;
            $ret["url"] = $this->ep_url."/ro/".$roid;
        } else {
            $ret["url"] = $this->ep_url."/p/".$pageid;
        }
        $ret["url"] .= "?".$this->ep_url_args;

        $isOwner = ($meta[$rev]["owner"] == $this->client);
        $ret["isOwner"] = $isOwner;

        $ret["isReadonly"] = $_POST["readOnly"];

        return $ret;
    }

    public function handle_ajax_pad_security() {
        global $conf, $ID, $REV, $INFO, $rev, $meta, $pageid;

        if(!checkSecurityToken()) {
            return [
                "file" => __FILE__,
                "line" => __LINE__,
                "error" => $this->getLang("CSRF protection.")
            ];
        }

        if (!is_array($meta)) {
            return [
                "file" => __FILE__,
                "line" => __LINE__,
                "error" => $this->getLang("Permission denied")
            ];
        }

        if (!isset($meta[$rev])) {
            return [
                "file" => __FILE__,
                "line" => __LINE__,
                "error" => $this->getLang("Permission denied")
           ];
        }

        if ($meta[$rev]["owner"] != $this->client) {
            return [
                "file" => __FILE__,
                "line" => __LINE__,
                "error" => $this->getLang("Permission denied")
            ];
        }

        if (strpos($_POST["readMode"],"password") === false) {
            $_POST["readpw"] = "";
        }

        if (strpos($_POST["writeMode"],"password") === false) {
            $_POST["writepw"] = "";
        }

        $_POST["readMode"] = str_replace("+password","",$_POST["readMode"]);

        $this->renameCurrentPage();

        $password = $_POST["readpw"];
        if ($password != "***") {
            if ($password == "") {
                unset($meta[$rev]["readpw"]);
            } else {
                $meta[$rev]["readpw"] = $password;
            }
        }

        $password = $_POST["writepw"];
        if ($password != "***") {
            if ($password == "") {
                unset($meta[$rev]["writepw"]);
            } else {
                $meta[$rev]["writepw"] = $password;
            }
        }

        $meta[$rev]["encAMode"] = $_POST["encAMode"];
        $meta[$rev]["readMode"] = $_POST["readMode"];

        return $this->getPageInfo();
    }

    public function handle_ajax_pad_getText() {
        global $conf, $ID, $REV, $INFO, $rev, $meta, $pageid;

        if (!is_array($meta)) {
            return [
                "file" => __FILE__,
                "line" => __LINE__,
                "error" => $this->getLang("Permission denied")
            ];
        }

        if (!isset($meta[$rev])) {
            return [
                "file" => __FILE__,
                "line" => __LINE__,
                "error" => $this->getLang("Permission denied")
            ];
        }

        $text = $this->ep_instance->getText($pageid);
        $text = (string) $text->text;

        return [
            "status" => "OK",
            "text" => $text
        ];
    }

    public function handle_ajax_pad_close() {
        global $conf, $ID, $REV, $INFO, $rev, $meta, $pageid;

        if(!checkSecurityToken()) {
            return [
                "file" => __FILE__,
                "line" => __LINE__,
                "error" => $this->getLang("CSRF protection.")
            ];
        }

        if (!is_array($meta)) {
            return [
                "file" => __FILE__,
                "line" => __LINE__,
                'error' => $this->getLang("Permission denied")
            ];
        }

        if (!isset($meta[$rev])) {
            return [
                "file" => __FILE__,
                "line" => __LINE__,
                'error' => $this->getLang("Permission denied")
            ];
        }

        if ($meta[$rev]["owner"] != $this->client) {
            return [
                "file" => __FILE__,
                "line" => __LINE__,
                'error' => $this->getLang("Permission denied")
            ];
        }

        $text = $this->ep_instance->getText($pageid);
        $text = (string) $text->text;

        # save as draft before deleting
        if($conf['usedraft']) {
          $draft = [
            'id'     => $ID,
            'prefix' => substr($_POST['prefix'], 0, -1),
            'text'   => $text,
            'suffix' => $_POST['suffix'],
            'date'   => (int) $_POST['date'],
            'client' => $this->client,
          ];
          $cname = getCacheName($draft['client'].$ID,'.draft');
          if (!io_saveFile($cname,serialize($draft))) {
              return [
                  "file" => __FILE__,
                  "line" => __LINE__,
                  'error' => $this->getLang("pad could not be safed as draft")
              ];
          }
        }
        $this->ep_instance->deletePad($pageid);

        unset($meta[$rev]);

        return [
            "status" => "OK",
            "text" => $text
        ];
    }

    public function handle_ajax_has_pad() {
        global $conf, $ID, $REV, $INFO, $rev, $meta, $pageid, $USERINFO;

        return [ "exists" => isset($meta[$rev]) ];
    }

    public function handle_ajax_pad_open() {
        global $conf, $ID, $REV, $INFO, $rev, $meta, $pageid, $USERINFO;

        if(!checkSecurityToken()) {
            return [
                'file' => __FILE__,
                'line' => __LINE__,
                'error' => $this->getLang("CSRF protection.")
            ];
        }

        if (!empty($this->ep_group)) {
            if (!isset($_SESSION["ep_sessionID"])) {
                $authorid = $this->ep_instance->createAuthorIfNotExistsFor($this->client, $this->clientname);
                $authorid = (string) $authorid->authorID;
                $cookies = $this->ep_instance->createSession($this->groupid, $authorid, time() + 7 * 24 * 60 * 60);
                $sessionID = (string) $cookies->sessionID;
                $_SESSION["ep_sessionID"] = $sessionID;
            }
            $host = parse_url($this->ep_url, PHP_URL_HOST);

            $cookie_options = [
                'expires' => '0',
                'path' => '/',
                'domain' => $host,
                'secure' => true,
                'httponly' => false,
                'samesite' => 'None',
            ];
            //setcookie("sessionID",$_SESSION["ep_sessionID"], $cookie_options);
            $cookie_options['domain'] = $this->domain;
            setcookie("sessionID",$_SESSION["ep_sessionID"], $cookie_options);
        }
        
        if (!isset($meta[$rev])) {
            if (!$_POST['isSaveable'] || $_POST["readOnly"]) {
                return [
                    'file' => __FILE__,
                    'line' => __LINE__,
                    'error' => $this->getLang("There is no such pad.")
                ];
            }

            /** new pad */
            if (isset($_POST["text"])) {
                $text = $_POST["text"];
            } else {
                $text = rawWiki($ID,$rev);
                if(!$text) {
                    $text = pageTemplate($ID);
                }
            }
            $pageid = md5(uniqid("dokuwiki:".md5($ID).":$rev:", true));
            if (!empty($this->ep_group)) {
                $this->ep_instance->createGroupPad($this->groupid, $pageid, $text);
            } else {
                $this->ep_instance->createPad($pageid, $text);
            }
            $meta[$rev] = [];
            $meta[$rev]["pageid"] = $pageid;
            $meta[$rev]["owner"] = $this->client;
            $meta[$rev]["encAMode"] = "wikiwrite";
            $meta[$rev]["readMode"] = "wikiwrite";
        } else {
            $pageid = $meta[$rev]["pageid"];
            /* in case pad is already deleted, recreate it. Should not happen, but this resolves this kind of conflict. */
            try {
                if (!empty($this->ep_group)) {
                    $this->ep_instance->createGroupPad($this->groupid, $pageid, "");
                } else {
                    $this->ep_instance->createPad($pageid, "");
                }
            } catch (Exception $e) {
            }
        }
        $pageid = $this->getPageID();

        $ret = $this->getPageInfo();
        $ret = array_merge($ret, [
            "sessionID" => $_SESSION["ep_sessionID"],
            "domain" => $this->domain
        ]);

        //var_dump($ret);

        return $ret;
    }

    public function handle_tpl_metaheader_output(Doku_Event &$event, $param) {
        global $ACT, $INFO;
        //echo $this->domain;
        $code = 'document.domain = "'.trim($this->getConf('etherpadlite_domain')).'";';
        $this->include_script($event, $code);

        if (!in_array($ACT, [ 'edit', 'create', 'preview', 'locked', 'recover' ])) {
            return;
        }
        $config = [
            'id' => $INFO['id'],
            'rev' => (($INFO['currentrev'] == '') ? $INFO['lastmod'] : $INFO['currentrev']),
            'base' => DOKU_BASE.'lib/plugins/etherpadlite/',
            'act' => $ACT
        ];
        $path = 'scripts/etherpadlite.js';

        $this->include_script($event, 'var etherpad_lite_config = '.json_encode($config));
        $this->link_script($event, DOKU_BASE.'lib/plugins/etherpadlite/'.$path);
    }

    private function include_script($event, $code) {
        $event->data['script'][] = [
            'type' => 'text/javascript',
            'charset' => 'utf-8',
            '_data' => $code,
        ];
    }

    private function link_script($event, $url) {
        $event->data['script'][] = [
            'type' => 'text/javascript',
            'charset' => 'utf-8',
            'src' => $url,
            'defer' => true,
        ];
    }
}
