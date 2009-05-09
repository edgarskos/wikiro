<?php
/**
* This file contains all the code you need to run SxWiki.
* 
* @author en:User:Gutza
* @author en:User:SQL
* @copyright Copyright (c) 2008, User:Gutza
* @copyright Copyright (c) 2007-2008, User:SQL
* @license http://opensource.org/licenses/gpl-license.php GNU Public License
* @package SxWiki
* @version 0.1.0
* @todo Investigate API for {@link getTransclusion}()
* @todo Test code
* @todo Release v1.0
* @todo Failures in implicit attempts to authenticate should not
*   auto-die, even if auto-die is enabled; by contrast, explicit
*   attempts to authenticate should auto-die under the same circumstances.
* @todo Smarter EPM algorithm
* @todo Get/edit section
* @todo Improve documentation (tutorials, mainly)
* @todo Better error management for curl_error()
* @todo Smarter way to sleep (we currently sleep after each call -- instead, we should conditionally wait before every call that requires it)
* @todo In _doSleep(), use usleep() instead of sleep()
* @todo Centralized "maxlag" management
* @todo Improved overall error management, as to be able to programatically interpret detailed stats when an operation fails
*/

// $Id: SxWiki.php,v 1.17 2008/01/29 16:04:50 b_stancescu Exp $

/**
* The main SxWiki class.
*/
class SxWiki
{
  /**
  * Number of edits per minute allowed; zero means no throttling.
  * @var integer
  */
  var $editsPerMinute=10;

  /**
  * Variables $username and $password are the credentials used for
  * getting authenticated in the MediaWiki installation.
  *
  * You SHOULD NOT set these by editing the class itself;
  * you SHOULD set them via PHP code while using the class.
  * @var string
  * @see login()
  */
  var $username='';

  /**
  * Please see SxWiki::$username for specific documentation, and
  * SxWiki::login() for more context.
  * @var string
  * @see SxWiki::$username
  * @see login()
  */
  var $password='';

  /**
  * Whether to automatically try to log in.
  * @var boolean
  * @see login()
  * @see _autoLogin()
  */
  var $autoLogin=true;

  /**
  * Private. Stores the current authentication status.
  * @var boolean
  * @see login()
  * @see $autoLogin
  * @see _autoexec()
  */
  var $_loggedIn=false;

  /**
  * The full URL to the MediaWiki installation's article root.
  *
  * This SHOULD only be used if you're using SxWiki on a custom MediaWiki
  * installation; if you're using it on Wikipedia, see ${@link wikipedia}.
  * (e.g. http://en.wikipedia.org/w/)
  *
  * @var string
  * @see $wikipedia
  * @see _getBaseURL()
  */
  var $url = '';

  /**
  * If you're using SxWiki for use with Wikipedia proper, you only
  * need to set this to your Wikipedia's language code (the default
  * points to the English Wikipedia).
  *
  * If you use SxWiki on a custom MediaWiki installation you don't
  * need to unset this default, just populate ${@link url}.
  *
  * @var string
  * @see $url
  * @see _getBaseURL()
  */
  var $wikipedia='en';

  /**
  * Private; stores the final base URL after parsing $url
  * and/or $wikipedia.
  *
  * @var string
  * @see $url
  * @see $wikipedia
  */
  var $_baseURL;

  /**
  * If set to true (default) then all critical errors related to SxWiki
  * result in killing execution altogether.
  *
  * If set to false, execution of the current script is continued
  * irrespective to failures in SxWiki's methods.
  *
  * Error messages are or are not being output depending on ${@link verbosity}.
  *
  * As a general rule, most of SxWiki's methods which only return status
  * information return true on success, false on failure or NULL if a
  * non-critical operation
  * hasn't been performed.
  *
  * @var boolean
  * @see $verbosity
  */
  var $autoDie;

  /**
  * Verbosity level. By default, only errors are shown.
  *
  * The following values are valid:
  * - -1: quiet -- no messages are shown (not even error messages)
  * -  0: production mode (default) -- only error messages are shown (e.g. "Failed to log in")
  * -  1: script debugging -- only important messages are shown (e.g. "Logged in")
  * -  2: SxWiki debugging -- internal messages are shown (e.g. "Attempting login")
  * -  3: insane detail -- all messages are shown (e.g. "Deciding how to attempt login")
  * -  4: full-on gore -- you don't want this. Ever. It shows internal gore at a post-insane level.
  *
  * @var integer
  * @see _debug()
  */
  var $verbosity=0;

  /**
  * Private. CURL handle. It is being initialized automatically.
  * @var int
  * @see _curlDo()
  */
  var $_ch=NULL;

  /**
  * The directory for temporary files.
  *
  * The code attempts to determine a meaningful default in {@link SxWiki}.
  *
  * @var string
  * @see SxWiki()
  */
  var $tempDir='';

  /**
  * Private. Stores the location of the cookiejar.
  * @var string
  * @see _curlDo()
  */
  var $_cookiejar=NULL;

  /**
  * Max lag for POST requests (see {@link http://www.mediawiki.org/wiki/Manual:Maxlag_parameter})
  * @var integer
  */
  var $maxlag=5;

  /**
  * The address of the proxy to be used. By default none is used.
  *
  * @var string
  */
  var $proxyaddr=NULL;

  /**
  * How many times should CURL operations be retried if they fail.
  *
  * @var integer
  */
  var $retries=0;

  /**
  * Public. The configuration file for this object.
  *
  * Useful for distributing PHP scripts using this class without the
  * need for sanitization.
  *
  * The configuration file is a plain text file which uses the following
  * format:
  * <code>
  * username=xxxx
  * password="yyyy"
  * wikipedia=zzzz
  * </code>
  *
  * All values are optional. Lines starting with a "#" are ignored.
  * Starting and trailing spaces and TAB characters on each line are ignored.
  * The values can optionally be enclosed in double quotes (e.g. if the
  * password ends with a space). Enclosed double quotes MUST NOT be
  * escaped (the code simply removes the double quote immediately following
  * the equal sign and the one at the end of the line, if both are
  * found to be present).
  *
  * If this variable is set, the configuration file is parsed automatically
  * at the first attempt to communicate with a MediaWiki server, or the
  * parsing can be triggered at any time using {@link readConfigurationFile()}.
  *
  * All values present in the file when it is parsed override existing values
  * in the instantiated object, regardless of whether they are defaults
  * or they have been set explicitly.
  *
  * The configuration file can use either DOS or Unix line endings.
  *
  * The following directives are currently supported:
  * - <kbd>username</kbd> overrides {@link $username}
  * - <kbd>password</kbd> overrides {@link $password}
  * - <kbd>wikipedia</kbd> overrides {@link $wikipedia} 
  * - <kbd>url</kbd> overrides {@link $url}
  * @var string
  */
  var $configFile=NULL;

  /**
  * Private. Used by {@link _readConf()} to determine whether to call
  * {@link readConfigurationFile()}.
  * 
  * @var boolean
  */
  var $_confRead=false;

  /**
  * Constructor. Does a bit of internal stuff:
  * - tries to set ${@link tempDir} to a reasonable default value.
  * @param string $wikipedia if specified,
  *   it is stored in local variable $wikipedia
  * @return NULL unconditionally returns NULL
  * @see $wikipedia
  */
  function SxWiki($wikipedia=NULL)
  {
    if (is_dir("/tmp")) {
      $this->tempDir="/tmp";
    } elseif (is_dir("C:\\")) {
      $wt="C:\\temp";
      if (is_dir($wt) || @mkdir($wt)) {
        $this->tempDir=$wt;
      }
    }
    if ($wikipedia) {
      $this->wikipedia=$wikipedia;
    }
  }

  /**
  * Private. Sleeps between edits, as to respect ${@link editsPerMinute}.
  *
  * @return boolean|NULL True on sleep, NULL if no throttling in place
  */
  function _doSleep()
  {
    // TODO: should be smarter, by using timers to count
    // how many edits it /really/ performs per minute.
    $epm=(int) $this->editsPerMinute;
    if ($epm<=0) {
      return NULL;
    }
    sleep((int) 60 / $epm);
    return true;
  }
 
  /**
  * Private. Tries to haggle between ${@link url} and ${@link wikipedia},
  * and populates ${@link _baseURL} if successful.
  *
  * If both ${@link url} and ${@link wikipedia} are set, ${@link url} has
  * priority.
  *
  * Fails if neither ${@link url} nor ${@link wikipedia} are set.
  *
  * @return boolean|string Boolean false on failure or the base URL on success.
  * @see $url
  * @see $wikipedia
  * @see $_baseURL
  */
  function _getBaseURL()
  {
    if ($this->url) {
      $this->_baseURL=$this->url;
    } elseif ($this->wikipedia) {
      $this->_baseURL="http://".strtolower($this->wikipedia).".wikipedia.org/w/";
    } else {
      $this->_error("Failed determining base URL!");
      return false;
    }
    return $this->_baseURL;
  }

  /**
  * Private. Error management. By default it outputs $message and stops
  * execution.
  *
  * If ${@link autoDie} is false, it returns boolean true instead of stopping
  * execution.
  *
  * If ${@link verbosity} is -1, it doesn't output the error message.
  *
  * @param string $message the error message
  * @return boolean Always true, unless ${@link autoDie} is true, in which case it kills the script
  * @see $autoDie
  * @see $verbosity
  */
  function _error($message)
  {
    $this->_debug(0,$message);
    if ($this->autoDie) {
      exit;
    }
    return true;
  }

  /**
  * Private. Conditionally outputs $message, if ${@link verbosity} is equal to
  * or greater than $level.
  *
  * @param integer $level the minimum level of verbosity for this message to show
  * @param string $message the message itself (EOL is optional)
  * @return boolean|NULL true if the message is output, NULL otherwise
  * @see $verbosity
  */
  function _debug($level,$message)
  {
    if ($this->verbosity>=$level) {
      echo trim($message)."\r\n";
      return true;
    }
    return NULL;
  }

  /**
  * Attempts to get authenticated on the MediaWiki installation.
  *
  * If ${@link autoLogin} is set to true, a login will be attempted before any
  * operation if one hasn't already been successfully performed (explicitly or
  * implicitly) on the same object.
  *
  * If ${@link autoLogin} is false, an explicit call to SxWiki::{@link login}()
  * is required in order to operate as an authenticated user (remember
  * that many operations can usually be performed as an anonymous user,
  * so that might also make sense depending on your purpose).
  *
  * If you choose to get authenticated explicitly via this method, you can
  * pass the credentials as parameters. If no parameters are passed, the method
  * tries to use the object's credentials (${@link username} and 
  * ${@link password}).
  * You CAN NOT pass only one of the parameters to this method -- either you
  * pass both or you pass neither (if you only pass one, it will behave
  * as if you didn't pass any).
  *
  * Credentials passed as parameters to this method are being populated in
  * the object's variables (${@link username} and ${@link password}).
  *
  * @param string $username
  * @param string $password
  * @return boolean true on success, false on failure (also see {@link autoDie})
  *
  * @see $autoLogin
  * @see $username
  * @see $autoDie
  */
  function login($username=NULL, $password=NULL)
  {
    $this->_autoexec("login");
    if ($username && $password) {
      $this->username=$username;
      $this->password=$password;
    }
    if ($username && ($password===NULL)) {
      list($username,$password)=$this->_retrieveCredentials($username);
      if (!$username || !$password) {
        // _retrieveCredentials() has already complained
        return false;
      }
    }
    if (!$url=$this->_getBaseURL()) {
      return false;
    }
    $request=
      $url.'api.php?'.
      'action=login&'.
      'lgname='.urlencode($this->username).'&'.
      'lgpassword='.urlencode($this->password).'&'.
      'format=php';
    $params="wpName=".$this->username."&".
      "wpPassword=".$this->password."&".
      "wpLoginattempt=true";
    $sxLgInput=$this->_curlDo($request,$params);
    $sxLgI = unserialize($sxLgInput);
    $result = $sxLgI[login][result];
    if ($result != "Success") {
      $this->_error("Login failed: $result");
      $this->_debug(2,"Output: $sxLgInput");
    } else {
      $this->_debug(1,"Logged in as ".$this->username.".");
      $this->_loggedIn=true;
    }
    $sxLgIA = array("token" => $sxLgI[login][lgtoken], "uid" => $sxLgI[login][lguserid]);
    return $sxLgIA;
  }

  /**
  * Private. Performs automatic tasks, called internally by most methods in
  * this class.
  *
  * Calls the following methods:
  * - {@link _readConf()} (unless $skip='conf')
  * - {@link _autoLogin()} (unless $skip='login')
  *
  * @param string $skip whether to skip any of the methods
  * @return void
  */
  function _autoexec($skip=NULL)
  {
    if ($skip!='conf') {
      $this->_readConf();
    }
    if ($skip!='login') {
      $this->_autoLogin();
    }
    return NULL;
  }

  /**
  * Private. Conditionally tries to authenticate.
  *
  * A call to method {@link login}() is made if both ${@link autoLogin} is
  * enabled and ${@link _loggedIn} is false.
  *
  * @return mixed NULL if authentication is not performed, or the result of
  * the call to {@link login}() if authentication is attempted.
  * @see $autoLogin
  * @see login
  */
  function _autoLogin()
  {
    if ($this->autoLogin && !$this->_loggedIn) {
      return $this->login();
    }
    return NULL;
  }
 
  /**
  * Returns the Wiki source of an article.
  *
  * The name of the article is automatically urlencoded when composing the
  * URL to be accessed. That is, it is assumed that you're passing the actual
  * name of the article, UTF8 compliant.
  *
  * @param string $article the name of the article
  * @return string|false the article's Wiki source, or false on failure
  */
  function getPage($article) {
    $this->_autoexec();
    if (!$url=$this->_getBaseURL()) {
      return false;
    }
    $params=array(
      'action'=>'query',
      'prop'=>'revisions',
      'titles'=>$article,
      'rvprop'=>'content'
    );
    $sxGetA = $this->callAPI($params);
    if (!$sxGetA) {
      $this->_error("Failed retrieving result for article $article.");
      return false;
    }
    $sxGetAID = $sxGetA;
    $sxGetAID = array_shift($sxGetAID);
    $sxGetAID = array_shift($sxGetAID);
    $sxGetAID = array_shift($sxGetAID);
    $sxGetAID = array_shift($sxGetAID);
    $sxAText = $sxGetA[query][pages][$sxGetAID][revisions][0]["*"];
    return $sxAText;
  }
 
    /**
    * Private. Sets up the curl session and does the curl_exec.
    *
    * If $params is set, it performs a POST request, otherwise a GET.
    *
    * @param $ch curl handler (get it with curl_init())
    * @param $cookiejar filename that will hold cookies
    * @param $url the URL (pass GET variables here)
    * @param $params variables to be sent via POST; ex: param1=value1&param2=value2
    * @return string result page
    */
  function _curlDo($url,$params='')
  {
    if (!$this->_ch) {
      $this->_ch=curl_init();
    }
    $ch=&$this->_ch;
    if (!$this->_cookiejar) {
      $this->_cookiejar=tempnam($this->tempDir,"SxWiki_cookiejar_");
    }
    curl_setopt($ch, CURLOPT_COOKIEJAR, $this->_cookiejar);
    curl_setopt($ch, CURLOPT_URL,$url);
    if($params) {
      curl_setopt($ch, CURLOPT_POST, true);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    } else {
      curl_setopt($ch, CURLOPT_POST, false);
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);

    // We do NOT want to execute this conditionally!
    curl_setopt($ch, CURL_PROXY,$this->proxyaddr);

    $attempts=$this->retries+1;
    do {
      $attempts--;
      $ret=curl_exec($ch);
    } while (curl_errno($ch) && $attempts);
    if (curl_errno($ch)) {
      $this->_debug(1,curl_error($ch));
    }
    return $ret;
  }

  /**
  * Retrieves the HTML source of an arbitrary URL.
  *
  * Useful if you want to retrieve specific data, such as logs, as an
  * authenticated user.
  *
  * @param string $url The URL to retrieve data from
  * @return string the HTML source found at that URL.
  */
  function getUrl($url) {
    $this->_autoexec();
    return $this->_curlDo($url);
  }

  /**
  * Saves an article
  *
  * @param string $article the name of the article to edit
  * @param string $editsum the edit summary
  * @param string $newtext the new text to be submitted
  * @param boolean $minor whether this is a minor edit
  */
  function putPage($article, $editsum, $newtext, $minor) {
    $this->_autoexec();
    if (!$url=$this->_getBaseURL()) {
      return false;
    }
    $article = $this->toURL($article);
    $postrequest = $url . 'index.php?action=edit&title=' . $article;
    $this->_debug(3,"putPage: getting $postrequest");
    $response = $this->_curlDo($postrequest);
    $this->_debug(4,"putPage:got response:");
    $this->_debug(4,$response);
    preg_match(
      '/\<input type\=\\\'hidden\\\' value\=\"(.*)\" name\=\"wpStarttime\" \/\>/i',
      $response, $starttime
    );
    preg_match(
      '/\<input type\=\\\'hidden\\\' value\=\"(.*)\" name\=\"wpEdittime\" \/\>/i',
      $response, $edittime
    );
    preg_match(
      '/\<input name\=\"wpAutoSummary\" type\=\"hidden\" value\=\"(.*)\" \/\>/i',
      $response, $autosum
    );
    preg_match(
      '/\<input type\=\'hidden\' value\=\"(.*)\" name\=\"wpEditToken\" \/\>/i',
      $response, $token
    );
    $this->_debug(3,"starttime: {$starttime[1]}; edittime: {$edittime[1]}; wpAutoSummary: {$autosum[1]}; token: {$token[1]}");
    if (!$starttime || !$edittime || !$autosum || !$token) {
      $this->_error("Failed retrieving necessary data for editing!");
      return false;
    }
    $postrequest = $url . 'index.php?title=' . $article . '&action=submit&maxlag=' . $this->maxlag;
    $postData['wpScrolltop'] = '';
    $postData['wpSection'] = '';
    $postData['wpEditToken'] = $token[1];
    $postData['wpAutoSummary'] = $autosum[1];
    $postData['wpSummary'] = $editsum;
    $postData['wpTextbox1'] = $newtext;
    $postData['wpSave'] = "Salveaz".chr(196).chr(131)." pagina";
    $postData['wpStarttime'] = $starttime[1];
    $postData['wpEdittime'] = $edittime[1];
    if ($minor) {
      $postData['wpMinoredit'] = 1;
    }
    $response = $this->_curlDo($postrequest,$postData);
    $this->_debug(4,"putPage response:");
    $this->_debug(4,$response);
    if (preg_match('/^Waiting for (.*) seconds lagged/', $response)) {
      $this->_error("Maxlag hit, not posted.");
      $returnval = false;
    } else {
      $returnval = true;
    }
    $this->_doSleep();
    return $returnval;
  }
 
  /**
  * Retrieves the names of the articles in a given category.
  *
  * @param string $categoryname the name of the category to query
  * @param string $ns the namespace to restrict results to
  * @return array the article names, as an indexed array
  */
  function getCat($categoryname, $ns='all')
  {
    $fcat = array();
    $params=array(
      'action'=>'query',
      'list'=>'categorymembers',
      'cmcategory'=>$categoryname,
      'cmlimit'=>500
    );
    $finalcat = $this->callAPI($params);
    if(isset($finalcat['query-continue']['categorymembers']['cmcontinue'])) {
      $firstrun = 1;
      $catfrom = $finalcat['query-continue']['categorymembers']['cmcontinue'];
      $catfrom = urlencode($catfrom);
    } else {
      $firstrun = 0;
    }
    foreach($finalcat[query][categorymembers] as $fcat_l) {
      array_push($fcat, $fcat_l);
    }
    $done=false;
    while(!$done) {
      if(
        isset($cat['query-continue']['categorymembers']['cmcontinue']) ||
        $firstrun == 1
      ) {
        $params['cmcontinue']=$catfrom;
        $cat = $this->callAPI($params);
        $catfrom = $cat['query-continue']['categorymembers']['cmcontinue'];
        $catfrom = urlencode($catfrom);
        foreach($cat[query][categorymembers] as $fcat_l) {
          array_push($fcat, $fcat_l);
        }
        echo ".";
        $firstrun = 0;
      } else {
        $done = true;
      }
    }
    $result = array();
    foreach($fcat as $catmemb) {
      if ($catmemb[ns] == $ns || $ns == "all") {
        array_push($result, $catmemb[title]);
      }
    }
    return $result;
  }

  /**
  * Returns article names starting with a given prefix
  *
  * @param string $prefixname the prefix
  * @return array an indexed array containing the article names
  */
  function getPrefix($prefixname)
  {
    $o_prefixname = $prefixname;
    $result = array();
    $searchpf = '/^' . $o_prefixname . '/';
    $params=array(
      'action'=>'query',
      'list'=>'allpages',
      'apfrom'=>$prefixname,
      'aplimit'=>500
    );
    $finalpre = $this->callAPI($params);
    if(isset($finalpre['query-continue']['allpages']['apfrom'])) {
      $firstrun = "1";
      $prefart = urlencode($finalpre['query-continue']['allpages']['apfrom']);
    } else {
      $firstrun = "0";
    }
    foreach($finalpre[query][allpages] as $finalpre_l) {
      if(!preg_match($searchpf, $finalpre_l[title])) {
        $done = 1;
      } else {
        array_push($result, $finalpre_l[title]);
      }
    }

    $done=false;
    while(!$done) {
      if(isset($pref['query-continue']['allpages']['apfrom']) || $firstrun == "1") {
        $params['apfrom']=$prefart;
        $pref = $this->callAPI($params);
        $prefart = urlencode($pref['query-continue']['allpages']['apfrom']);
        foreach($pref[query][allpages] as $pref_l) {
          if(!preg_match($searchpf, $pref_l[title])) {
            $done = 1;
          } else {
            array_push($result, $pref_l[title]);
          }
        }
        echo ".";
        $firstrun = 0;
      } else {
        $done = true;
      }
    }
    return $result;
  }

  /**
  * Returns data about the last edit of an article
  *
  * @param string $article the name of the article
  * @return array information about the last edit as an associative array
  */
  function lastEdited($article)
  {
    $params=array(
      'action'=>'query',
      'prop'=>'revisions',
      'titles'=>$article,
      'rvprop'=>'user|comment',
      'rvlimit'=>1
    );
    $sxGetA = $this->callAPI($params);
    $sxGetAID = $sxGetA;
    $sxGetAID = array_shift($sxGetAID);
    $sxGetAID = array_shift($sxGetAID);
    $sxGetAID = array_shift($sxGetAID);
    $sxGetAID = array_shift($sxGetAID);
    $sxAText = array();
    $sxAText['user'] = $sxGetA[query][pages][$sxGetAID][revisions][0][user];
    $sxAText['editsum'] = $sxGetA[query][pages][$sxGetAID][revisions][0][comment];
    return $sxAText;
  }
 
  /**
  * Performs a call to the MediaWiki API and returns the result in a
  * native PHP format (typically as an array).
  *
  * You can pass the parameters to the API call either as an associative
  * array or as a string. If you pass them as an associative array, both
  * keys and values will be urlescaped; if you pass them as a string, you
  * have to take care of that.
  *
  * You do not have to pass the format parameter explicitly.
  *
  * @see http://www.mediawiki.org/wiki/API
  * @param array|string $params the parameters to be passed to the MW API
  * @return mixed the API call result in native PHP format (see API documentation for details
  */
  function callAPI($params)
  {
    $this->_autoexec();
    if (!$url=$this->_getBaseURL()) {
      return false;
    }
    if (is_array($params)) {
      $p='';
      foreach($params as $k=>$v) {
        if ($k=='format') {
          continue;
        }
        $p.='&'.urlencode($k).'='.urlencode($v);
      }
      $params=substr($p,1);
    }
    $params.='&format=php';
    $result=$this->_curlDo($url.'api.php?'.$params);
    return unserialize($result);
  }

  /**
  * ???
  */
  function getTransclusion($templatename, $ns)
  {
    $params=array(
      'action'=>'query',
      'list'=>'embedded',
      'eititle'=>$templatename,
      'eilimit'=>500
    );
    $result=$this->callAPI($params);
    $pages = array();
    $oresult = $result;
    foreach ($result['query']['embeddedin'] as $single_result) {
      if ($single_result['ns'] == $ns && $ns != "all") {
        array_push($pages, $single_result['title']);
      } else {
        array_push($pages, $single_result['title']);
      }
    }
    $done=false;
    while (!$done) {
      if(isset($result['query-continue']['embeddedin']['eicontinue'])) {
        $params['eicontinue']=
          $result['query-continue']['embeddedin']['eicontinue'];
        $result=$this->callAPI($params);
        foreach ($result['query']['embeddedin'] as $single_result) {
          if ($single_result['ns'] == $ns && $ns != "all") {
            array_push($pages, $single_result['title']);
          } else {
            array_push($pages, $single_result['title']);
          }
        }
      } else {
        $done = true;
      }
    }
    return $pages;
  }

  /**
  * Blocks a user.
  *
  * @param string $user the username of the user to be blocked
  * @param string $expiry the expiry date for the block
  * @param string $reason the reason for blocking
  * @param boolean $ao anon only
  * @param boolean $acb account creation blocked
  * @param boolean $autoblock autoblock
  * @param boolean $emailban email ban
  * @return boolean true on success or false on failure
  */
  function blockUser($user, $expiry, $reason, $ao, $acb, $autoblock, $emailban)
  {
    $this->_autoexec();
    if (!$url=$this->_getBaseURL()) {
      return false;
    }
    $euser = urlencode($user);
    $postrequest = $url . 'index.php?title=Special:Blockip/' . $euser;
    $response = $this->_curlDo($postrequest);
    preg_match('/\<input name\=\"wpEditToken\" type\=\"hidden\" value\=\"(.*)\" \/\>/i', $response, $token);
    if (!$token) {
      $this->_error("Failed retrieving token for blocking!");
      $this->_debug(2,$response);
      return false;
    }
    $token=$token[1];
    $this->_debug(2,"Retrieved token for blocking: $token");
    $this->_debug(3,$response);
    $postrequest = $url . 'index.php?title=Special:Blockip/' . $euser . '&action=submit&maxlag=' . $this->maxlag;
    $postData['wpEditToken'] = urlencode($token);
    $postData['wpBlockAddress'] = $euser;
    $postData['wpBlockOther'] = urlencode($expiry);
    $postData['wpBlockReason'] = urlencode($reason);
    $postData['wpBlock'] = "Block";
    $postData['wpBlockExpiry']='other';
    $postData['wpBlockReasonList']='other';
    if ($ao != null) {
      $postData['wpAnonOnly'] = $ao;
    }
    if ($acb != null) {
      $postData['wpCreateAccount'] = $acb;
    }
    if ($autoblock != null) {
      $postData['wpEnableAutoblock'] = $autoblock;
    }
    if ($emailban != null) {
      $postData['wpEmailBan'] = $emailban;
    }
    $this->_debug(3,"\$postData: ".$this->_varDump($postData));
    $response=$this->_curlDo($postrequest,$postData);
    if (preg_match('/^Waiting for (.*) seconds lagged/', $response)) {
      $this->_error("Maxlag hit, not posted.");
      return false;
    }
    $this->_debug(3,$response);
    $this->_doSleep();
    return true;
  }

  function _varDump($var)
  {
    ob_start();
    var_dump($var);
    return ob_get_clean();
  }
  /**
  * Award or remove the rollbacker right for a user
  *
  * @param string $user the username of the user to affect
  * @param string $reason the reason for the change in rights
  * @param string $action add to add the right, or del to remove it
  * @return boolean true on success or false on failure
  */
  function modRollback($user, $reason, $action)
  {
    $this->_autoexec();
    if (!$url=$this->_getBaseURL()) {
      return false;
    }
    $user2 = urlencode($user);
    $postrequest = $url . 'index.php?title=Special:Userrights&user=' . $user2;
    $response = $this->_curlDo($postrequest);
    preg_match('/\<input name\=\"wpEditToken\" type\=\"hidden\" value\=\"(.*)\" \/\>/i', $response, $token);
    $postrequest = $url . 'index.php?title=Special:Userrights&user=' . $user2 . '&maxlag=' . $this->maxlag;
    $postData['wpEditToken'] = $token[1];
    $postData['user'] = $user;
    switch ($action) {
      case "add":
        $postData['available[]'] = "rollbacker";
        $postData['removable[]'] = "";
        break;
      case "del":
        $postData['removable[]'] = "rollbacker";
        $postData['available[]'] = "";
        break;
    }
    $postData['user-reason'] = $reason;
    $postData['saveusergroups'] = "Save User Groups";
    $response = $this->_curlDo($postrequest,$postData);
    if (preg_match('/^Waiting for (.*) seconds lagged/', $response)) {
      $this->_error("Maxlag hit, not posted.");
      return false;
    }
    $this->_doSleep();
    return true;
  }

  /**
  * Unblock a user
  *
  * @param string $user the username of the user to unblock
  * @param string $reason the reason for unblocking
  * @return boolean true on success or false on failure
  */
  function unblockUser($user, $reason)
  {
    $this->_autoexec();
    if (!$url=$this->_getBaseURL()) {
      return false;
    }
    $postrequest = $url . 'index.php?title=Special:Ipblocklist&action=unblock&ip=' . urlencode($user);
    $response = $this->_curlDo($postrequest);
    preg_match('/\<input name\=\"wpEditToken\" type\=\"hidden\" value\=\"(.*)\" \/\>/i', $response, $token);
    $postrequest = $url . 'index.php?title=Special:Ipblocklist&action=submit'  . '&action=submit&maxlag=' . $this->maxlag;
    $postData['wpEditToken'] = $token[1];
    $postData['wpUnblockAddress'] = $user;
    $postData['wpUnblockReason'] = $reason;
    $postData['wpBlock'] = "Unblock";
    $response = $this->_curlDo($postrequest,$postData);
    if (preg_match('/^Waiting for (.*) seconds lagged/', $response)) {
      $this->_error("Maxlag hit, not posted.");
      return false;
    }
    $this->_doSleep();
    return $returnval;
  }

  /**
  * Check whether a user is blocked
  *
  * @param string $user the username of the user to check
  * @return boolean true if the user is blocked or false otherwise
  */
  function isBlocked($user)
  {
    $this->_autoexec();
    if (!$url=$this->_getBaseURL()) {
      return false;
    }
    $user=urlencode($user);
    $postrequest=$url."index.php?title=Special%3AIpblocklist&ip=".$user;
    $cpage=$this->_curlDo($postrequest);
    $findblock = 'The requested IP address or username is not blocked.';
    return (bool) strstr($cpage,$findblock);
  }

  /**
  * Public. Convert an article name from URL-format to UTF8 proper.
  *
  * @see toURL()
  *
  * @param string $string the name of the article, urlencoded
  * @return string the UTF8 name of the same article
  */
  function fromURL($string)
  {
    return str_replace("_"," ",urldecode($string));
  }

  /**
  * Public. Convert an article name from UTF8 to URL format
  *
  * @see fromURL()
  *
  * @param string $string the UTF8 name of the article
  * @return string the name of the same article, urlencoded
  */
  function toURL($string)
  {
    return
      str_replace("%2F","/",
        str_replace("%3A",":",
          urlencode(
            str_replace(" ","_",$string)
          )
        )
      );
  }

  /**
  * Explicit destructor, for PHP4.x
  *
  * Deletes the cookiejar file and closes the CURL session.
  * You don't need to call this explicitly if you're using PHP 5.x, see
  * {@link __destruct}().
  */
  function destroy()
  {
    if ($this->_ch) {
      curl_close($this->_ch);
      unset($this->_ch);
    }
    if ($this->_cookiejar) {
      @unlink($this->_cookiejar);
      unset($this->_cookiejar);
    }
  }

  /**
  * Destructor.
  *
  * Calls {@link destroy}(). Gets called automatically in PHP 5.x
  */
  function __destruct()
  {
    $this->destroy();
  }

  /**
  * Public. Read the configuration file.
  *
  * Reads a configuration file (see {@link $configFile} for details on its
  * format). If parameter file is specified, that file is used and its
  * name is stored in {$link $configFile}. If it is not specified,
  * {@link $configFile} is used. If neither is specified, the method returns
  * NULL without triggering any errors.
  *
  * This method is called automatically via {@link _readConf()}, or it can
  * be called explicitly at any time.
  *
  * @param string $file the configuration file
  * @return boolean|NULL true on success, false on failure or NULL if
  *   no configuration file specified
  */
  function readConfigurationFile($file=NULL)
  {
    if ($file!==NULL) {
      $this->configFile=$file;
    } else {
      $file=$this->configFile;
    }
    if (!$file) {
      return NULL;
    }
    if (!is_file($file) || !is_readable($file)) {
      $this->_error(
        "Configuration file \"$file\" doesn't exist or can't be read."
      );
      return false;
    }
    $lines=explode("\n",trim(file_get_contents($file)));
    foreach($lines as $line) {
      $line=trim($line);
      if (!$line) {
        // Skip empty lines
        continue;
      }
      if (substr($line,0,1)=='#') {
        // Skip comments
        continue;
      }
      if (($pos=strpos($line,"="))===false) {
        $this->_error("Unknown configuration directive: $line!");
        continue;
      }
      $var=substr($line,0,$pos);
      $val=substr($line,$pos+1);
      if (
        (substr($val,0,1)=='"') &&
        (substr($val,-1)=='"')
      ) {
        // Enclosed in double quotes -- removing them
        $val=substr($val,1,-1);
      }
      if (in_array($var,array('username','password','wikipedia','url'))) {
        $this->$var=$val;
      } else {
        $this->_error("Unknown configuration variable: $var!");
        continue;
      }
    }
    return true;
  }

  /**
  * Private. Makes the initial, automatic call to
  * {@link readConfigurationFile()}.
  *
  * Calls {@link readConfigurationFile()} without parameters.
  * Uses {@link $_confRead} to determine whether this automatic call
  * has already been performed, and sets it if not already set.
  *
  * This method is called unconditionally every time {@link _autoexec()} is
  * called.
  *
  * @return boolean|NULL if the configuration has already been read,
  *   it returns NULL without calling {@link readConfigurationFile()};
  *   otherwise it returns the result of the call to
  *   {@link readConfigurationFile()}, whichever that may be.
  */
  function _readConf()
  {
    if ($this->_confRead) {
      return NULL;
    }
    $this->_confRead=true;
    return $this->readConfigurationFile();
  }
}
?>
