<?php
/*
Plugin Name: WHMCS WordPress Integration
Plugin URI: http://premium.wpmudev.org/project/whmcs-wordpress-integration/
Description: This plugin allows remote control of WHMCS from Wordpress. Now with Pretty permalinks.
Author: Arnold Bailey {Incsub)
Author Uri: http://premium.wpmudev.org/
Text Domain: wcp
Domain Path: languages
Version: 1.2.0.6
Network: false
WDP ID: 263
*/

/*  Copyright 2013  Incsub  (http://incsub.com)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License, version 2, as
published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

if(!function_exists('curl_init'))
exit( __('The WHMCS WordPress Integration plugin requires the PHP Curl extensions.', WHMCS_TEXT_DOMAIN) );

define('WHMCS_INTEGRATION_VERSION','1.2.0.6');
define('WHMCS_SETTINGS_NAME','wcp_settings');
define('WHMCS_TEXT_DOMAIN','wcp');
define('WHMCS_INTEGRATION_URL', plugin_dir_url(__FILE__) );
define('WHMCS_INTEGRATION_DIR', plugin_dir_path(__FILE__) );
define('WHMCS_INTEGRATION_CACHE_URL', plugin_dir_url(__FILE__) . 'cache/');
define('WHMCS_INTEGRATION_CACHE_DIR', plugin_dir_path(__FILE__) . 'cache/');

require(plugin_dir_path(__FILE__) .'lib/url_to_absolute.php');
/* -------------------- WPMU DEV Dashboard Notice -------------------- */
include_once(plugin_dir_path(__FILE__) .'lib/wpmudev-dash-notification.php');

add_filter('widget_text', 'do_shortcode'); // Allows use of shortcodes in widgets

$WHMCS_Wordpress_Integration = new WHMCS_Wordpress_Integration();

class WHMCS_Wordpress_Integration{

	//WHMCS debug flag
	public $debug = false;

	//holds current datapacket from WHMCS
	public $whmcsportal = null;

	//Settings from WHMCS_SETTINGS_NAME);
	public $settings = '';

	//Response values sent back to WHMCS
	public $response = array();

	//Curent method used to WHMCS
	public $method = 'GET';

	//DOMDocument containing the parsed WHMCS page
	public $dom = null;

	//DOMNode containing the scripts
	public $scripts = null;

	//DOMNode containing the css links
	public $css = null;

	//DOMDocument containing the parsed WHMCS content_left piece
	public $content = null;

	//DOMDocument containing the parsed WHMCS top_menu piece
	public $menu = null;

	//DOMDocument containing the parsed WHMCS wecome piece
	public $welcome = null;

	//DOMDocument containing the Quick Nav parsed from WHMCS
	public $quick_nav = null;

	//DOMDocument containing the Account info parsed from WHMCS
	public $account = null;

	//DOMDocument containing the Statistics parsed from WHMCS
	public $statistics = null;

	//WHMCS base url
	public $whmcs_base = '';

	//WHMCS base url
	public $remote_parts = array();

	//WHMCS remote host home.
	public $remote_host = '';

	//WHMCS last requested url after redirects
	public $whmcs_request_url = '';

	//Redirect_request post_fields
	public $post_fields = array();

	public $multipart = false;

	//Current cache file
	public $cache = '';

	//Query filter - query var names added here will be removed from query_vars list
	// This is so input fields names like 'name' don't have side effects in Wordpress
	private $query_filter = array();

	//Body array to get WP to send as Multipart
	public $post_array = null;

	public $content_page_id = 0;

	public $content_page_path = '';

	public $pending_cookies = '';

	public $WHMCS_PORTAL = 'whmcsportal';

	/**
	* Constructor
	*
	*/
	function __construct(){

		$this->init_properties();
		$this->http_patch();

		register_activation_hook(__FILE__,array(&$this,'on_activate'));
		register_deactivation_hook(__FILE__,array(&$this,'on_deactivate'));

		add_action('init', array(&$this,'on_init'));
		add_action('wp_loaded', array(&$this,'on_wp_loaded'));
		add_action('admin_menu', array(&$this,'on_admin_menu'));
		add_action('wp_enqueue_scripts', array(&$this,'on_enqueue_scripts'));

		add_action('admin_enqueue_scripts', array(&$this,'wp_pointer_load'));

		add_filter('query_vars', array(&$this,'on_query_vars'));

		add_action('parse_request', array(&$this,'on_parse_request'));
		add_action('send_headers', array(&$this,'on_send_headers'));
		add_action('parse_query', array(&$this,'on_parse_query'));

		add_filter('request', array(&$this,'on_request'));
		add_filter('http_request_timeout', array(&$this,'on_timeout'));
		add_filter('https_ssl_verify', array(&$this,'ssl_verify'));
		add_filter('https_local_ssl_verify', array(&$this,'ssl_verify'));

		add_filter('whitelist_options', array(&$this,'on_whitelist_options'));

		add_filter('the_content', array(&$this,'on_the_content'),1,9999);

		add_shortcode('wcp_content', array(&$this,'content_shortcode'));
		add_shortcode('wcp_menu', array(&$this,'menu_shortcode'));
		add_shortcode('wcp_welcome', array(&$this,'welcome_shortcode'));
		add_shortcode('wcp_quick_nav', array(&$this,'quick_nav_shortcode'));
		add_shortcode('wcp_account', array(&$this,'account_shortcode'));
		add_shortcode('wcp_statistics', array(&$this,'statistics_shortcode'));

		add_action('wp_ajax_whmcs_ajax',array(&$this,'whmcs_ajax'));
		add_action('wp_ajax_nopriv_whmcs_ajax',array(&$this,'whmcs_ajax'));

		// Need this because WHMCS uses "action" trigger as well
		add_action('wp_ajax_getkbarticles',array(&$this,'whmcs_ajax'));
		add_action('wp_ajax_nopriv_getkbarticles',array(&$this,'whmcs_ajax'));

		add_action('wp_ajax_twitterfeed',array(&$this,'whmcs_ajax'));
		add_action('wp_ajax_nopriv_twitterfeed',array(&$this,'whmcs_ajax'));

	}

	function init_properties(){

		if ( defined('WHMCS_INTEGRATION_DEBUG') && WHMCS_INTEGRATION_DEBUG ) $this->debug = true;

		$this->settings = get_option(WHMCS_SETTINGS_NAME);

		//cleanup
		@$this->remote_host = $this->settings['remote_host'] = url_to_absolute( $this->settings['remote_host'],'./');
		@$this->content_page_id = $this->settings['content_page'] = (is_numeric($this->settings['content_page'])) ?  intval($this->settings['content_page']) : 0;
		$this->settings['encode_url'] = empty($this->settings['encode_url']) ?  $this->settings['remote_host'] : $this->settings['encode_url'];
		$this->settings['http_sig'] = empty($this->settings['http_sig']) ? 0 : $this->settings['http_sig'];

		$this->endpoint = $this->settings['endpoint'] = empty($this->settings['endpoint']) ? 'whmcsportal' : $this->settings['endpoint'];

		update_option(WHMCS_SETTINGS_NAME, $this->settings);

		$this->WHMCS_PORTAL = $this->settings['endpoint'];

		$this->whmcs_base = $this->remote_host;

		$this->remote_parts = split_url($this->whmcs_base);

		$this->http_remote = str_ireplace('https:', 'http:', $this->remote_host);
		$this->https_remote = str_ireplace('http:', 'https:', $this->remote_host);

		return $this->settings;
	}

	function wp_pointer_load(){

		//var_dump(get_current_screen());

		$cookie_content = __('<p>WHMCS WordPress Integration can now sync certain cookies between WHMCS and Wordpress so that downloads of protected files from WHMCS can work correctly in WordPress.</p> <p>This requires copying the "wp-integration.php" file in this plugin to the root of the WHMCS System installation.</p>', WHMCS_TEXT_DOMAIN);

		//Setup any new feature notices
		include WHMCS_INTEGRATION_DIR . 'lib/class-wp-help-pointers.php';
		$pointers = array(
		array(
		'id' => 'wcp_endpoint',   // unique id for this pointer
		'screen' => 'toplevel_page_wcp-settings', // this is the page hook we want our pointer to show on
		'target' => '#wcp-endpoint', // the css selector for the pointer to be tied to, best to use ID's
		'title' => __('NEW - Permalinks Endpoint Slug', WHMCS_TEXT_DOMAIN),
		'content' => __('<p>This is the slug that signals that the following page is to be pulled from the WHMCS site.</p> <p>You can change it to whatever you like to avoid interfering with other pages but like all slugs it should contain Only lowercase alphanumerics and the hyphen.</p>', WHMCS_TEXT_DOMAIN),
		'position' => array(
		'edge' => 'top', //top, bottom, left, right
		'align' => 'middle' //top, bottom, left, right, middle
		)
		),

		array(
		'id' => 'wcp_cookies',   // unique id for this pointer
		'screen' => 'plugins', // this is the page hook we want our pointer to show on
		'target' => '#toplevel_page_wcp-settings', // the css selector for the pointer to be tied to, best to use ID's
		'title' => __('NEW - WHMCS WordPress Integration Cookie syncing', WHMCS_TEXT_DOMAIN),
		'content' => $cookie_content,
		'position' => array(
		'edge' => 'left', //top, bottom, left, right
		'align' => 'right' //top, bottom, left, right, middle
		)
		),

		array(
		'id' => 'wcp_cookies',   // unique id for this pointer
		'screen' => 'toplevel_page_wcp-settings', // this is the page hook we want our pointer to show on
		'target' => '#toplevel_page_wcp-settings', // the css selector for the pointer to be tied to, best to use ID's
		'title' => __('NEW - WHMCS WordPress Integration Cookie syncing', WHMCS_TEXT_DOMAIN),
		'content' => $cookie_content,
		'position' => array(
		'edge' => 'left', //top, bottom, left, right
		'align' => 'right' //top, bottom, left, right, middle
		)
		),

		// more as needed
		);

		new WP_Help_Pointer($pointers);
	}

	function ssl_verify($sslverify){
		return false;
	}

	function on_timeout($timeout){
		return 120;
	}

	function on_the_content($content=''){
		return str_replace('&#038;', '&amp;', $content);
	}

	/**
	* on_activate - Called on plugin activation. Does any initial setup
	*
	*/
	function on_activate(){
		//Activation if needed.

		// add endpoints for front end special pages
		add_rewrite_endpoint($this->WHMCS_PORTAL,
		EP_PAGES
		| EP_ROOT
		//| EP_PERMALINK
		);

		flush_rewrite_rules();
	}

	/**
	* on-deactivate - called on deactivating the plugin. Performs any cleanup necessary
	*
	*/
	function on_deactivate(){
		//Deactivation if needed.
		flush_rewrite_rules();
	}

	/**
	* on_init -  Calls init hook functions.
	*
	*/
	function on_init(){

		// add endpoints for front end special pages
		add_rewrite_endpoint($this->WHMCS_PORTAL,
		EP_PAGES
		| EP_ROOT
		//| EP_PERMALINK
		);

		//setup cache files for current session
		if(! session_id()) session_start();
		$sid = session_id();
		$this->pending_cookies = 'whmcs_cookie_' . $sid;

		//Setup session specific cookies for WHMCS
		if(! is_dir(WHMCS_INTEGRATION_CACHE_DIR)) mkdir(WHMCS_INTEGRATION_CACHE_DIR, 0755);
		if(! is_writable(WHMCS_INTEGRATION_CACHE_DIR) ) chmod(WHMCS_INTEGRATION_CACHE_DIR, 0755);
		//if(! is_writable(WHMCS_INTEGRATION_CACHE_DIR) ) chmod(WHMCS_INTEGRATION_CACHE_DIR, 0777);
		$this->cache = WHMCS_INTEGRATION_CACHE_DIR . "$sid.txt";

		//Clean out old cache files
		foreach(glob(WHMCS_INTEGRATION_CACHE_DIR . '*.*') as $fname){
			$age = time() - filemtime($fname);
			if(($age > 24 * 60 * 60 * 7) &&  (basename($fname) != 'index.php')) { //Don't erase our blocking index.php file
				unlink($fname); // more than a week old;
			}
		}

		load_plugin_textdomain( WHMCS_TEXT_DOMAIN, false, '/languages/' );

		$parts = split_url(get_permalink($this->content_page_id));
		$this->content_page_path = $parts['path'];

	}

	function on_enqueue_scripts(){
		wp_enqueue_script('jquery');
		wp_register_style('whmcs_portal', plugin_dir_url(__FILE__) . 'css/whmcs-portal.css', array(), WHMCS_INTEGRATION_VERSION );
		wp_enqueue_style('whmcs_portal');


		//Cookies may have been received from WHMCS which need to be synced
		if ($cookies = get_transient( $this->pending_cookies ) ){
			foreach($cookies as $cookie) {
				$this->sync_cookie($cookie);
			}
			//Use a short expiration rather than delete in case of memcaching.
			set_transient( $this->pending_cookies, array(), 1 );
		}
	}

	/**
	* WHMCS cookies are synced by queuing dummy CSS links to the wp-integration.php file on the remote site.
	*
	*/
	function sync_cookie($cookie){
		//Encrypt the cookie to sync with WP
		//				$key= 'password';

		$cookie->httponly = isset($cookie->httponly) ? 1 : 0;
		$cookie->secure = isset($cookie->secure) ? 1 : 0;

		$key = strtolower($this->remote_parts['host']);
		$ver = urlencode( base64_encode(mcrypt_encrypt(MCRYPT_RIJNDAEL_256, md5($key), json_encode($cookie), MCRYPT_MODE_CBC, md5(md5($key)))) );

		wp_register_style($cookie->name, $this->remote_host . 'wp-integration.php', array(), $ver );
		wp_enqueue_style($cookie->name);
	}

	/**
	* Makes a minor patch to WP_Curl turning off errors when a 301, 302 redirect occurs
	* A redirect is not an error and it messes up catching page changes at WHMCS
	* Also inserts a filter so that headers can be filtered in curl.
	* And a filter to turn off WP_Http's internal redirect when safe_mode or open_basedir are set in PHP.
	*/
	function http_patch($hard = false){

		//Check the file stats for an updated file
		$this->settings = get_option(WHMCS_SETTINGS_NAME);
		$fname = ABSPATH . WPINC . '/class-http.php';
		clearstatcache();
		$stat = stat($fname);
		$result = false;
		//if different update the file and save the new signature
		if($this->settings['http_sig'] != $stat['size'].$stat['mtime'].$stat['ctime'] || $hard){
			$fs = file_get_contents($fname);
			if($fs !== false){

				//obsolete as of WPv3.7
				$fs = preg_replace('#(302\s*\)\s*\)\s*\)\s*)(return)#','$1;//$2'	,$fs); //comment out the error


				//** Header filter necessary to support multipart forms. If not patched genereates a 413: Request Entity Too Large error
				//Header filter for 3.3x
				$fs = preg_replace('#(true\s*\)\s*;\s*)(if\s*\(\s*\!\s*empty\s*\(\s*\$r\[\'headers\'\])#',
				"$1\$r['headers'] = apply_filters('http_curl_headers', \$r['headers']); //Added by WHMCS Integration\n\n\t\t$2", $fs); //Add header filter

				//Header filter for 3.4x
				$fs = preg_replace('#(\}\s*)(if\s*\(\s*\!\s*empty\s*\(\s*\$r\[\'headers\'\])#',
				"$1\$r['headers'] = apply_filters('http_curl_headers', \$r['headers']); //Added by WHMCS Integration\n\n\t\t$2", $fs); //Add header filter

				//obsolete after WPv3.4
				$fs = preg_replace('#(>\s*0\s*\)\s*\{\s*)(return\s*\$this->request\(\s*\$theHeaders)#',
				"$1if(apply_filters('http_api_redirect', true)) //Added by WHMCS Integration\n\t\t\t\t$2", $fs); //Add redirect filter

				//For WPv3.4
				$fs = preg_replace('#(>\s*0\s*\)\s*\{\s*)(return\s*\$this->request\(\s*WP_HTTP\:\:make_absolute_url\(\s*\$theHeaders)#',
				"$1if(apply_filters('http_api_redirect', true)) //Added by WHMCS Integration\n\t\t\t\t$2", $fs); //Add redirect filter

				//For WPv3.5.2
				$fs = preg_replace('#(>\s*0\s*\)\s*\{\s*)(return\s*\wp_remote_request\(\s*WP_HTTP\:\:make_absolute_url\(\s*\$theHeaders)#',
				"$1if(apply_filters('http_api_redirect', true)) //Added by WHMCS Integration\n\t\t\t\t$2", $fs); //Add redirect filter

				//For WPv3.7.1
				$fs = preg_replace('#(\/\/\s*Handle\s*redirects\s*\n)(\s*if.*\n.*;\n)#',
				"$1\t\tif( apply_filters('http_api_redirect', true)){ //Added by WHMCS Integration\n\t$2\t\t}\n", $fs);

				if($fs){
					if(! is_writable($fname) ) chmod($fname, 0666);
					$result = @file_put_contents($fname, $fs);
					clearstatcache();
					$stat = stat($fname);
					$this->settings['http_sig'] = $stat['size'].$stat['mtime'].$stat['ctime'];
					update_option(WHMCS_SETTINGS_NAME, $this->settings);
				}
			}

		}
		return $result;
	}

	function filter_headers($headers){

		if ( count($_FILES) >  0 || $this->multipart ){
			//Multipart post. these will set themselves in curl
			unset($headers['Content-Type']);
			unset($headers['Content-Length']);
		}

		return $headers;
	}

	function filter_redirect($doredirect){
		return false;
	}

	function clear_whmcs_session(){
		$cookies = str_getcsv( file_get_contents($this->cache), "\n" ); //Break at lines
		foreach($cookies as $key => $cookie){
			if(stripos($cookie, $this->remote_parts['host']) !== false){  //Our domain?
				if(strpos($cookie, "\t0\tWHMCS") !== false){ //And session cookie?
					$cookies[$key] = '';
				}
			}
		}
		file_put_contents($this->cache, implode("\n", $cookies) );
	}

	function cache_cookies($handle){
		curl_setopt( $handle, CURLOPT_COOKIEJAR, $this->cache );
		curl_setopt( $handle, CURLOPT_COOKIEFILE, $this->cache );
		curl_setopt( $handle, CURLOPT_FOLLOWLOCATION, false ); // Need to follow redirects explicitly
		curl_setopt( $handle, CURLOPT_SSLVERSION, 3 ); //

		//If this is a multipart post this is our chance to fixup curl
		if ( count($_FILES) >  0 || $this->multipart ){
			curl_setopt( $handle, CURLOPT_POSTFIELDS, $this->post_array ); //Multipart post
		}
	}

	function redirect_request($url, $post_fields = ''){

		//grab WP_Http
		add_action('http_api_curl',array(&$this,'cache_cookies'));
		add_filter('http_curl_headers', array(&$this,'filter_headers'));
		add_filter('http_api_redirect', array(&$this,'filter_redirect'));

		if(stripos($url, 'dologin.php') !== false){ //If login then clear the session cookie to force cookie sync
			$this->clear_whmcs_session();
			$url .= apply_filters('whmcs_get_args', 'systpl=portal');
		}

		$this->whmcs_request_url = $url;

		if( isset( $_SERVER['CONTENT_TYPE'] ) ){
			$this->multipart = strpos($_SERVER['CONTENT_TYPE'],'multipart/form-data');
		}

		$body = null;
		$headers = array('X-Forwarded-For' => $_SERVER['REMOTE_ADDR']);

		switch ($this->method){

			case 'POST' : {

				$this->debug_print('$_POST ' . $url);
				$this->debug_print($_POST);

				//Only figure out the post array once
				if(is_null($this->post_array)){
					$this->post_array = (empty($post_fields)) ? $_POST : $post_fields;

					//Pass on the upload files
					foreach($_FILES as $field => $filespecs){
						foreach($filespecs['name'] as $key => $filename){

							if ( empty($filespecs['error'][$key])){ //good file
								$actual_file = WHMCS_INTEGRATION_CACHE_DIR . $filespecs['name'][$key];
								move_uploaded_file($filespecs['tmp_name'][$key], $actual_file);
								$this->post_array[$field . "[$key]"] = "@$actual_file;type=" . $filespecs['type'][$key];
							}
						}
					}
				}
				$args = array(
				'method' => 'POST',
				'headers' => $headers,
				'body' => $this->post_array,
				'timeout' => apply_filters('http_request_timeout',60),
				);
				$this->debug_print('Method: POST ' . $url);
				$this->debug_print($args);

				$response = wp_remote_request($url, $args);
				break;
			}
			case 'GET': {
				$args = array(
				'method' => 'GET',
				'headers' => $headers,
				'timeout' => apply_filters('http_request_timeout',60),
				);
				$this->debug_print('Method: GET ' . $url);
				$this->debug_print($args);
				$response = wp_remote_request($url, $args);
				break;
			}
			case 'HEAD': {
				$this->debug_print('Method: HEAD ' . $url);
				$response = wp_remote_request($url, array(
				'method' => 'HEAD',
				'headers' => $headers,
				'timeout' => apply_filters('http_request_timeout',60),
				));
				break;
			}
			case 'PUT': {
				$this->debug_print('Method: PUT ' . $url);
				$response = wp_remote_put($url, array(
				'headers' => $headers,
				));
				break;
			}
			default: {
				$this->debug_print('Method: ????? ' . $url);
				break;
			}
		}
		//print_r($response); exit;
		//print_r(http_build_query($_POST));
		//Debug display here so we don't get the wrong base
		//remove the <base> tag so it doesn't screw relative urls.
		//$response['body'] = preg_replace("/<base[^>]+\>/i", "", $response['body']);
		$this->debug_print('Response: ' . $url);
		$this->debug_print($response);

		if (is_wp_error($response)){
			$this->debug_print('WP Error: ' . $url);
			$this->debug_print($response);
		}	else {
			//Queue the cookies for syncing

			if( !empty($response['cookies']) ){
				set_transient($this->pending_cookies, $response['cookies'], 120);
			}

			if (in_array($response['response']['code'], array(300, 301, 302, 303, 307) ) ){

				$newurl = url_to_absolute($this->remote_host,$response['headers']['location']);

				$this->debug_print('Redirect: ' . $url);
				$this->debug_print('New URL: ' . $newurl);
				$this->debug_print('Post Fields: ' . $newurl);
				$this->debug_print($this->post_fields);

				remove_filter('http_api_redirect', array($this,'filter_redirect'));
				remove_filter('http_curl_headers', array($this,'filter_headers'));
				remove_action('http_api_curl',array($this,'cache_cookies'));

				if(in_array($response['response']['code'], array(302, 303 ) ) ) $this->method = 'GET';

				if( stripos($newurl,$this->remote_host) !== false){
					$response =  $this->redirect_request( $newurl , $this->post_fields );
				} else {
					wp_redirect($newurl);
					exit;
				}
			}
		}

		// Give WP_Http back
		remove_filter('http_api_redirect', array($this,'filter_redirect'));
		remove_filter('http_curl_headers', array($this,'filter_headers'));
		remove_action('http_api_curl',array($this,'cache_cookies'));
		return $response;
	}

	/**
	* load_whmcs_url - Loads the WHMCS page. Sets $this->response and $this->dom
	* @ $url string - The WHMCS page to be retrieved
	* @ $query_string = A query string for the url
	*
	* @ return true/false
	*/
	function load_whmcs_url($url,$query_string=''){

		$this->post_array = null;

		$this->method = $_SERVER['REQUEST_METHOD'];

		$this->response = $this->redirect_request($url, $query_string);

		if (is_wp_error($this->response)){

			echo '<div class="whmcs_error">WHMCS Integration: ' . $this->response->get_error_message();
			if($this->debug) $this->debug_print($this->response);
			echo "</div>\n";
			exit();
		}
		else
		{
			$loaded = ($this->response['response']['code'] == '200');
			$body = $this->response['body'];

			if(! $loaded){
				echo '<div class="whmcs_error">WHMCS Integration: ' . $this->response['response']['code'] . "&mdash;" . $this->response['response']['message'];
				if($this->debug) $this->debug_print($url);
				if($this->debug) $this->debug_print($this->response);
				echo "</div>\n" ;
			}
		}

		if ($loaded){
			libxml_use_internal_errors(true);
			$this->dom = new DOMDocument('1.0','UTF-8');
			$this->dom->formatOutput = true;
			$this->dom->preserveWhiteSpace = false;

			//It's text so we can take care of javascript now
			$loaded = $this->dom->loadHTML('<?xml encoding="UTF-8">' . $this->redirect_javascript( $body ) );

			$this->parse_whmcs();

			libxml_clear_errors();
		}

		return $loaded;
	}

	function whmcs_api($action = '', $params = array()){

		$postfields = array();

		$url = $this->remote_host . 'includes/api.php';
		$postfields['username'] = $this->settings['whmcs_admin'];
		$postfields['password'] = md5($this->settings['whmcs_password']);
		$postfields['action'] = $action;
		$postfields['responsetype'] = 'xml';

		$postfields = array_merge($postfields, $params);

		$this->method = 'POST';
		$response = $this->redirect_request(	$url, $postfields);
		if(is_wp_error($response) ){

		}
		return $response;

	}


	/**
	* redirect_javascript - redirects any javascript locations or css urls to WHMCS
	* @ $text string the string to be parsed
	*
	* @ returns the modified string
	*/
	function redirect_javascript($text){

		//$text = str_replace( pack("CCC",0xef,0xbb,0xbf), "", $text);

		//javascript location changes
		if( preg_match_all('`window\.location\s*\=\s*\'([^\']*)\'`',$text, $matches) !== false){

			$unique = array_unique($matches[1]); //Only replace once
			sort($unique); //Sort so replacements included go first
			foreach($unique as $s){
				$u = $this->redirect_url($s);

				// Use the same delimiters
				$text = str_replace("'{$s}'", "'{$u}'", $text);
			}
		}

		if( preg_match_all('`window\.location\s*\=\s*\'([^\']*)\'(.*)`',$text, $matches) !== false){
			$unique = array_filter(array_unique($matches[2])); //Only replace once
			sort($unique); //Sort so replacements included go first
			foreach($unique as $s){
				$u = str_replace('&', '%26', $s);
				$text = str_replace($s, $u, $text);
			}

		}

		//javascript location changes
		if( preg_match_all('`window\.open\(\s*\'([^\']*)\'`',$text, $matches) !== false){
			$unique = array_unique($matches[1]); //Only replace once
			sort($unique); //Sort so replacements included go first
			foreach($unique as $s){
				$u = $this->redirect_url($s);
				// Use the same delimiters
				$text = str_replace("'{$s}'", "'{$u}'", $text);
			}
		}

		//javascript and css url() references
		if( preg_match_all('`url\((.*)\)`',$text, $matches) !== false){
			$unique = array_unique($matches[1]);
			sort($unique);
			foreach($unique as $s){
				$text = str_replace('(' . $s . ')', '(' . url_to_absolute($this->remote_host, $s) . ')', $text);
			}
		}

		//PayPal return and cancel references
		if( preg_match_all('`name="return" value=\"(.*)\"`',$text, $matches) !== false){
			$unique = array_unique($matches[1]);
			sort($unique);
			foreach($unique as $s){
				$u = $this->redirect_url($s);
				$text = str_replace($s, $u, $text);
			}
		}

		//PayPal return and cancel references
		if( preg_match_all('`name="cancel_return" value=\"(.*)\"`',$text, $matches) !== false){
			$unique = array_unique($matches[1]);
			sort($unique);
			foreach($unique as $s){
				$u = $this->redirect_url($s);
				$text = str_replace($s, $u, $text);
			}
		}

		//Special cases ==================

		//For testing sandbox DEFINE as the WHMCS setting doesn't work any more
		if ( defined('WHMCS_PAYPAL_SANDBOX') && WHMCS_PAYPAL_SANDBOX ){
			$text = str_replace('https://www.paypal.com/cgi-bin/webscr', 'https://www.sandbox.paypal.com/cgi-bin/webscr', $text);
		}

		//WHMCS v5.2 Extra apostrophe confuses some browsers /templates/portal/clientareadomains.tpl line 34
		$text = preg_replace('#(domaindetails&id=\d+)\'(\">)#','$1$2', $text);

		$text = str_replace('jQuery("#domainresults").slideUp();', '//jQuery("#domainresults").slideUp();', $text );


		// Need to use no conflict style instead of $
		$text = str_replace('$(document)', 'jQuery(document)', $text );
		$text = str_replace('$.post(', 'jQuery.post(', $text );
		$text = str_replace('method="get"', 'method="post"', $text ); //For vertical steps template
		$text = str_replace('$("#', 'jQuery("#', $text );
		$text = str_replace("$('#", "jQuery('#", $text );

		//Fix Twitter jQuery;
		$base = $this->whmcs_base;

		//for double quotes
		$text = preg_replace_callback('`(jQuery.post\(")([\w\d-_]+.php)`u', array($this, 'ajax_loop') , $text);
		//for single quotes
		$text = preg_replace_callback("`(jQuery.post\(')([\w\d-_]+.php)`u", array($this, 'ajax_loop') , $text);

		return $text;
	}

	/**
	* Helper function for preg_replace_callback above
	*
	*/
	function ajax_loop($match) {

		$ajax_url = admin_url('admin-ajax.php');

		$nonce = wp_create_nonce( 'whmcs_nonce' );

		$this->whmcs_url = url_to_absolute($this->remote_host, $match[2]);

		//Substitute whmcs:// for http:// to avoid querystring security filters.
		$this->whmcs_url = str_replace('http://','whmcs://', $this->whmcs_url);
		// Or Secure version
		$this->whmcs_url = str_replace('https://','whmcss://', $this->whmcs_url);
		$result = $match[1] . $ajax_url . "?action=whmcs_ajax&_ajax_nonce=$nonce&whmcsportal[page]=" . urlencode($this->whmcs_url);

		return $result;
	}

	/**
	* Ajax handler for redirected WHMCS javascipt posts.
	*
	*/
	function whmcs_ajax(){
		check_ajax_referer('whmcs_nonce');

		//print_r($_REQUEST); exit;
		$this->whmcsportal = $_REQUEST['whmcsportal'];

		$this->whmcsportal['page'] = str_replace('whmcs://', 'http://', $this->whmcsportal['page']);
		$this->whmcsportal['page'] = str_replace('whmcss://', 'https://', $this->whmcsportal['page']);

		$result = $this->load_whmcs_url(urldecode($this->whmcsportal['page']));

		if($result) {
			//WHMCS doesn't use the correct mime types and sends both text/plain and text/html as text/html in ajax
			if(strip_tags($this->response['body']) == $this->response['body']){
				echo $this->response['body'];
			} else {
				echo $this->dom->saveHTML();
			}
		}

		exit;
	}

	/**
	* on_query_vars - Authorize query vars for this plugin
	*
	*/
	function on_query_vars($vars){

		//Setup Query filters
		if ( stripos($_SERVER['QUERY_STRING'], 'contact.php') !== false) $this->query_filter[] = 'name';
		if ( stripos($_SERVER['QUERY_STRING'], 'submitticket.php') !== false) $this->query_filter[] = 'name';


		//Add any vars your going to be receiving from WHMCS
		//$vars[] = $this->WHMCS_PORTAL; //WHMCS data array

		return $vars;
	}

	function on_request($vars){

		//if(isset($vars[$this->WHMCS_PORTAL]) ) $vars[$this->WHMCS_PORTAL] = true;

		foreach($this->query_filter as $var){

			//remove WP default query_vars that may interfere to avoid 404s
			unset($vars[$var]);
		}

		return $vars;
	}


	function on_send_headers($wp){

		//var_dump($wp->query_vars);
		//var_dump(is_front_page());

	}

	/**
	* on_parse_request - See if the Query is for us
	*
	*/
	function on_parse_request($wp){

		// If no whmcs data then not for us
		if( empty($wp->query_vars[$this->WHMCS_PORTAL]) ) return;

		$this->debug = ($this->debug) ? $this->debug : isset($_REQUEST['debug']) && ($_REQUEST['debug'] == 'true');

		if($this->debug) $this->debug_print($wp);

		//WHMCS equvalent

		$query_string = isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '';

		$this->whmcsportal['page'] = url_to_absolute($this->whmcs_base, $wp->query_vars[$this->WHMCS_PORTAL] .'?' . $query_string );
		$this->whmcsportal['script'] = $wp->query_vars[$this->WHMCS_PORTAL];
		$this->whmcsportal['query'] = $query_string;

		//Remove query_vars variable so it's not confused for a WP function
		$wp->query_vars = array(); //Remove so it doesn't interfer with Static Front Page

		//This has something to do with WHMCS so pull the page now for use later in the shortcodes
		$this->load_whmcs_url(urldecode($this->whmcsportal['page']));
	}

	function on_wp_loaded(){
		//var_dump($_REQUEST);
	}

	/**
	* on_parse_query - Debugging
	*
	*/
	function on_parse_query($wp){
		//var_dump($wp);
		//var_dump($_REQUEST);
	}

	/**
	* redirect_url - Calculates and normalizes the url to redirect to at WHMCS
	* @ $url string - the relative url to be canonacalized if not relative return unchanged
	*
	* @ returns the cannonacalized url at WHMCS or unchaged url if not at WHMCS site
	*/
	function redirect_url($url){

		$s = str_ireplace($this->remote_host, '', $url ); //Make relative if from WHMCS
		//$s = str_ireplace($this->https_remote, '', $s ); //Make relative if from WHMCS

		$relative = stripos( trim($s), 'http') === false;

		$path = url_to_absolute($this->whmcs_base, $s); //whmcs path for this link may be offsite

		if ( !$relative) {
			return $path; //not relative so don't change
		}

		$whmcs_parts = split_url($path);

		//Remove the matching  part of the remote and local path
		@$whmcs_parts['path'] = '/' . implode('', explode($this->remote_parts['path'], $whmcs_parts['path'], 2));

		if($this->content_page_id == 0) $parts = split_url($_SERVER['REQUEST_URI']);
		else $parts = split_url(get_permalink($this->content_page_id));

		$parts['path'] .= $this->WHMCS_PORTAL . trailingslashit( $whmcs_parts['path']);

		$parts['query'] = isset($whmcs_parts['query']) ? $whmcs_parts['query'] : '';

		$result = join_url($parts);
		return $result;
	}

	function cache_javascript($url){

		$cache_name = md5($url) . '.js';
		$cache_file = WHMCS_INTEGRATION_CACHE_DIR . $cache_name;
		$cache_url = WHMCS_INTEGRATION_URL . 'cache/' . $cache_name . '?ver=' .rand();

		if (file_exists($cache_file) ) return $cache_url;

		$text = file_get_contents($url);
		if($text){
			$text = $this->redirect_javascript($text);
			file_put_contents($cache_file, $text);
			return $cache_url;
		}
		return $url.'?ver=2';
	}

	/**
	* Parse and save the various page pieces
	*
	*/
	function parse_whmcs(){

		$xpath = new DOMXPath($this->dom);

		//redirect WHMCS images
		$srcs = $xpath->query('//@src');
		foreach($srcs as $src){
			$url = url_to_absolute($this->remote_host, $src->textContent);
			if(stripos($url, 'verifyimage.php') !== false) {
				$url = $this->get_captcha_url();
			}
			$src->parentNode->setAttribute('src', $url) ;
		}

		//redirect WHMCS hrefs
		$hrefs = $xpath->query('//@href');
		foreach($hrefs as $href){
			$hr = $href->textContent;

			//take care of special cases
			if ( $hr == '#') continue;

			if ($href->ownerElement->tagName == 'link' || stripos($hr, 'dl.php') !== false){
				//Download direct from the WHMCS page which also takes care of css images
				$href->parentNode->setAttribute('href', url_to_absolute($this->remote_host, htmlspecialchars_decode($hr)));
				continue;
			}

			$href->parentNode->setAttribute('href', $this->redirect_url($hr));
		}

		//redirect form action
		$actions = $xpath->query('//@action');
		foreach($actions as $action){
			$action->parentNode->setAttribute('action', $this->redirect_url($action->textContent));
		}

		//Collect the css
		$this->css = $xpath->query('//link[@rel="stylesheet"]');
		foreach($this->css as $css) {
			$href = $css->getAttribute('href');
			if( (stripos( $href, '/jscript/css/') !== false)
			||  (stripos( $href, '/invoicestyle') !== false)
			||  (stripos( $href, '/orderforms/') !== false)
			) {
				$handle = str_replace('/', '-', str_replace(array($this->remote_host, '.css'), '', $href) );
				wp_enqueue_style($handle, $href);

			}
		}

		/**
		* Collect and queue the Content
		*/

		$this->content = new DOMDocument('1.0', 'utf-8');
		$this->content->formatOutput = true;

		$root = $this->content->createElement('div');
		$this->content->appendChild($root);

		$root->setAttribute('id', 'whmcs_portal');
		$root->setAttribute('class', 'whmcs_portal');

		$comment = $this->content->createComment("Begin WHMCS Integration");
		$root->appendChild($comment);

		//Collect the scripts
		$this->scripts = $xpath->query('//head//script');
		foreach($this->scripts as $script) {
			$src = $script->getAttribute('src');

			if(empty($url) ) { //Inline scripts
				$root->appendChild($this->content->importNode($script, true));
			}

			$handle = str_replace('/', '-', str_replace(array($this->remote_host, '.js'), '', $href) );
			if( (stripos( $src, '/templates/') !== false) )
			{
				$script->setAttribute('src', $this->cache_javascript($src));
				wp_enqueue_script($handle, $src);
			}

			if(stripos( $url, '/jscript/') !== false) {
				wp_enqueue_script($handle, $src);
			}
		}

		$this->scripts = $xpath->query('//body//script');
		foreach($this->scripts as $script) {
			$src = $script->getAttribute('src');
			if(stripos( $src, '/templates/') !== false )
			{
				$script->setAttribute('src', $this->cache_javascript($src));
			}
		}

		$nodes = $this->dom->getElementsByTagName('body');

		//Un parsed pages
		if(stripos($this->whmcs_request_url, 'viewinvoice.php') !== false
		|| stripos($this->whmcs_request_url, 'viewemail.php') !== false
		|| stripos($this->whmcs_request_url, 'viewquote.php') !== false
		|| stripos($this->whmcs_request_url, 'whois.php') !== false
		){
			$content = $nodes->item(0);
		} else {
			$content = $this->dom->getElementById('content_left');
		}
		if($content){
			$root->appendChild($this->content->importNode($content, true));
		} else {

			// Doesn't look like a Portal page return an error in all shortcodes
			$error = $this->content->createElement('div');
			$error->setAttribute('class', 'whmcs_error');

			$error_text = new DOMText( sprintf( __('Sorry this doesn\'t look like a WHMCS site at [%1$s]',WHMCS_TEXT_DOMAIN), $this->remote_host)
			. __('  Make sure your WHMCS Integration settings are pointing to the correct URL and that the WHMCS site is set for the Portal template in Setup | General.',WHMCS_TEXT_DOMAIN));

			$error->appendChild($error_text);

			$this->content->appendChild($error);

			$this->welcome = $this->content;
			$this->menu = $this->content;
			$this->account = $this->content;
			$this->statistics = $this->content;
			$this->quick_nav = $this->content;

			return;
		}

		$comment = $this->content->createComment('End WHMCS Integration');
		$root->appendChild($comment);

		/*
		*	Collect the Top Menu
		*/
		$this->menu = new DOMDocument('1.0', 'UTF-8');

		$root = $this->menu->createElement('div');
		$this->menu->appendChild($root);

		$root->setAttribute('id', 'whmcs_menu');
		$root->setAttribute('class', 'whmcs_menu');

		$comment = $this->menu->createComment('Begin WHMCS Menu');
		$root->appendChild($comment);

		$menu = $this->dom->getElementById('top_menu');

		if($menu){
			$root->appendChild($this->menu->importNode($menu, true));
		}

		$comment = $this->menu->createComment('End WHMCS Menu');
		$this->menu->appendChild($comment);


		/**
		*	Collect the Welcome Box
		*/
		$this->welcome = new DOMDocument('1.0', 'UTF-8');

		$root = $this->welcome->createElement('div');
		$this->welcome->appendChild($root);

		$root->setAttribute('id', 'whmcs_welcome');
		$root->setAttribute('class', 'whmcs_welcome');

		$comment = $this->welcome->createComment('Begin WHMCS Welcome');
		$root->appendChild($comment);

		$welcome = $this->dom->getElementById('welcome_box');

		if($welcome){
			$root->appendChild($this->welcome->importNode($welcome, true));
		}

		$comment = $this->welcome->createComment('End WHMCS Welcome');
		$this->welcome->appendChild($comment);

		/**
		* Collect the Quick Nav
		*/
		$sidebar = $this->dom->getElementById('side_menu');


		$this->quick_nav = new DOMDocument('1.0', 'UTF-8');

		$root = $this->quick_nav->createElement('div');
		$this->quick_nav->appendChild($root);

		$root->setAttribute('id', 'whmcs_quick_nav');
		$root->setAttribute('class', 'whmcs_quick_nav');

		$comment = $this->quick_nav->createComment('Begin WHMCS Quick Nav');
		$root->appendChild($comment);

		if(! empty($sidebar)){


			$nodes = $xpath->query('ul', $sidebar); // should be the quick nav
			$qn = $nodes->item(0);

			$nodes = $xpath->query('preceding-sibling::p', $qn); // should be the header

			if($nodes->length > 0){
				$root->appendChild($this->quick_nav->importNode($nodes->item(0),true));
				$root->appendChild($this->quick_nav->importNode($qn,true));
			}
		}

		$comment = $this->quick_nav->createComment('End WHMCS Quick Nav');
		$root->appendChild($comment);


		/*
		* Collect the Account Info and Login form
		*/

		$this->account = new DOMDocument('1.0', 'UTF-8');
		$aroot = $this->account->createElement('div');
		$this->account->appendChild($aroot);
		$aroot->setAttribute('id', 'whmcs_account');
		$aroot->setAttribute('class', 'whmcs_account');

		$comment = $this->account->createComment('Begin WHMCS Account');
		$aroot->appendChild($comment);


		$this->statistics = new DOMDocument('1.0', 'UTF-8');
		$sroot = $this->statistics->createElement('div');
		$this->statistics->appendChild($sroot);
		$sroot->setAttribute('id', 'whmcs_statistics');
		$sroot->setAttribute('class', 'whmcs_statistics');

		$comment = $this->statistics->createComment('Begin WHMCS Statistics');
		$sroot->appendChild($comment);

		if(! empty($sidebar)){

			$nodes = $xpath->query('form', $sidebar); // should be the login form

			if($nodes->length > 0){
				for ($i=0; $i < $nodes->length; $i++){
					$aroot->appendChild($this->account->importNode($nodes->item($i), true)); // May or may not exist
				}
			} else {
				$nodes = $xpath->query('p', $sidebar); // should be the account info
				for ($i=1; $i < min($nodes->length,3); $i++){
					$aroot->appendChild($this->account->importNode($nodes->item($i),true)); // May or may not exist
				}
				for ($i=3; $i < $nodes->length; $i++){
					$sroot->appendChild($this->statistics->importNode($nodes->item($i),true)); // May or may not exist
				}
			}
		}
		$comment = $this->statistics->createComment('End WHMCS Statistics');
		$sroot->appendChild($comment);

		$comment = $this->account->createComment('End WHMCS Account');
		$aroot->appendChild($comment);

	}

	/**
	* have_whmcs_page - Tries to retrieve a WHMCS page based on the whmcsportal[page] query_var or root of WHMCS if whmcsportal not available
	*
	* @ returns true/false
	*/
	function have_whmcs_page(){

		if (empty($this->dom)){
			$this->settings = get_option(WHMCS_SETTINGS_NAME);

			if( get_query_var($this->WHMCS_PORTAL) ){
				$result = $this->load_whmcs_url(urldecode($this->whmcsportal['page']));
			} else{
				$result = $this->load_whmcs_url($this->settings['remote_host'] . '?' . apply_filters('whmcs_get_args', 'systpl=portal') );
			}
		} else {
			$result = true;
		}

		return $result;
	}


	/**
	* Shortcode functions -  Return various portions of the parsed WHMCS page
	*
	*/

	function content_shortcode($attrs){
		return ( $this->have_whmcs_page() ) ? $this->content->saveHTML() : '';
	}

	function menu_shortcode($attrs){
		return ( $this->have_whmcs_page() ) ? $this->menu->saveHTML() : '';
	}

	function quick_nav_shortcode($attrs){
		return ( $this->have_whmcs_page() ) ? $this->quick_nav->saveHTML() : '';
	}

	function account_shortcode($attrs){
		return ( $this->have_whmcs_page() ) ? $this->account->saveHTML() : '';
	}

	function statistics_shortcode($attrs){
		return ( $this->have_whmcs_page() ) ? $this->statistics->saveHTML() : '';
	}

	function welcome_shortcode($attrs){
		return ( $this->have_whmcs_page() ) ? $this->welcome->saveHTML() : '';
	}

	/**
	* Debug dump utility function
	*/
	function debug_print($s, $return=false) {

		if( !$this->debug) return;

		$x = "<pre>";
		$x .= "<b>Debug Print WHMCS integration version " . WHMCS_INTEGRATION_VERSION . "</b>\n";

		$t = htmlspecialchars(print_r($s, true));
		if(empty($t) ) { // UTF-8 encoding broken
			$t = htmlspecialchars(print_r($s, true),ENT_SUBSTITUTE);
			$x .= "\n<b style=\"color: #c00\">Character encoding is incorrect on this page.\nCannot parse it properly.</b>\n\n";
		}
		$x .= $t;
		$x .= "</pre>";

		if ($return) return $x;
		else print $x;
	}

	function tabs(){
		screen_icon('options-general');
		?>
		<h2  id="wcp-top"><?php _e('WHMCS Wordpress Integration ',WHMCS_TEXT_DOMAIN); echo WHMCS_INTEGRATION_VERSION; ?> </h2>
		<br />
		<?php return; ?>
		<h2 class="nav-tab-wrapper">
			<a id="wcp-setting" class="nav-tab <?php if ( $this->tab == 'settings') echo 'nav-tab-active'; ?>" href="<?php echo esc_attr('admin.php?page=wcp-settings&tab=settings');?>"><?php _e( 'Settings', WHMCS_TEXT_DOMAIN); ?></a>
			<a id="wcp-sso" class="nav-tab <?php if ( $this->tab == 'sso') echo 'nav-tab-active'; ?>" href="<?php echo esc_attr('admin.php?page=wcp-settings&tab=sso');?>"><?php _e( 'Single Sign On', WHMCS_TEXT_DOMAIN); ?></a>
		</h2>
		<br />
		<?php
	}

	/**
	* on_admin_menu - Add network menu for this plugin
	*
	*/
	function on_admin_menu() {
		$this->top_menu = add_menu_page(__('WHMCS Integration',WHMCS_TEXT_DOMAIN), __('WHMCS Integration ',WHMCS_TEXT_DOMAIN), 'manage_options', 'wcp-settings', array($this,'admin_pages') );

		//Register the settings array
		register_setting( $this->top_menu, WHMCS_SETTINGS_NAME);
	}

	/**
	* admin_pages - Displays the Admin settings page.
	*
	*/
	function admin_pages(){

		if( isset($_GET['settings-updated']) ){
			$this->on_activate();
			echo '<div class="updated fade"><p>WHMCS Integration Settings Updated</p></div>';
		}

		$this->admin_settings_page(); return;

		$this->tab = (empty($_GET['tab']) ) ? 'settings' : $_GET['tab'];
		switch ($this->tab){
			case 'settings' : $this->admin_settings_page(); break;
			case 'sso' : $this->admin_sso_page(); break;
			default: $this->admin_settings_page(); break;
		}
	}

	function on_whitelist_options( $whitelist_options){

		if(isset( $_POST[WHMCS_SETTINGS_NAME] ) )
		$_POST[WHMCS_SETTINGS_NAME] = array_replace( $this->settings, $_POST[WHMCS_SETTINGS_NAME]);

		return $whitelist_options;
	}

	/**
	* admin_settings_page - Displays the Admin settings page.
	*
	*/
	function admin_settings_page(){
		?>
		<div class="wrap">
			<?php if(ini_get('safe_mode')) _e('<div class="error">PHP safe_mode is on </div>',WHMCS_TEXT_DOMAIN) . ini_get('safe_mode'); ?>
			<?php if(ini_get('open_basedir')) _e('<div class="error">PHP open_basedir is on </div>',WHMCS_TEXT_DOMAIN) . ini_get('open_basedir'); ?>
			<?php if( ! $this->http_patch(true)) _e('<div class="error">WHMCS Integration cannot patch file wp-contents/class-http.php. Make sure it is writable</div>',WHMCS_TEXT_DOMAIN) . ini_get('open_basedir'); ?>

			<?php $this->tabs(); ?>
			<div id="poststuff" class="metabox-holder">
				<form method="POST" action="options.php">
					<div class="postbox">
						<h3 class="hndle"><?php _e('WHMCS Wordpress Integration',WHMCS_TEXT_DOMAIN); ?></h3>
						<div class="inside">
							<?php settings_fields( $this->top_menu ); ?>
							<table class="form-table">
								<thead>
								</thead>
								<tbody>
									<tr>
										<th>
											<?php _e('Remote WHMCS Host:',WHMCS_TEXT_DOMAIN); ?>
										</th>
										<td>
											<input type="text" name="wcp_settings[remote_host]" size="40" value="<?php echo esc_attr($this->settings['remote_host']); ?>" />
											<?php echo 'IP: ' . gethostbyname(parse_url($this->settings['remote_host'], PHP_URL_HOST)); ?>
											<br /><span class="description"><?php _e('The URL you set in THE WHMCS System URL. If you use https: you must use the same mode in both the plugin and WHMCS.<br />Whether you use http: or https: leave the SSL System URL blank in WHMCS.', WHMCS_TEXT_DOMAIN); ?>  </span>
										</td>
									</tr>
									<tr>
										<th>
											<?php _e('Default Content Page:',WHMCS_TEXT_DOMAIN); ?>
										</th>
										<td>
											<select name="wcp_settings[content_page]">
												<?php
												$pages = get_pages();
												echo '<option value="0">' . __('--Select the default content display page--', WHMCS_TEXT_DOMAIN) . "</option>\n";
												foreach($pages as $page){
													$option = '<option value="' . $page->ID . '"' . selected($page->ID, $this->settings['content_page'], false) . ' >' . $page->post_title . "</option>\n";
													echo $option;
												}
												?>
											</select>
											<br /><span class="description"><?php _e('This is your default content page which contains the [wcp_content] shortcode.', WHMCS_TEXT_DOMAIN); ?>  </span>
										</td>
									</tr>
									<tr>
										<th>
											<?php _e('Endpoint Slug:',WHMCS_TEXT_DOMAIN); ?>
										</th>
										<td>
											<input type="text" id="wcp-endpoint" name="wcp_settings[endpoint]" size="40" value="<?php esc_attr_e( $this->settings['endpoint']); ?>" />
											<br /><span class="description"><?php _e('The endpoint slug to use to signal reference to a WHMCS page',WHMCS_TEXT_DOMAIN); ?>
											<br /><?php _e('Permalinks will be of the form <br />http://YOUR.DOMAIN/DEFAULT_PAGE/ENDPOINT/clientarea.php',WHMCS_TEXT_DOMAIN); ?></span>
										</td>
									</tr>
									<tr>
										<th>
											<input type="submit" class="button-primary" name="submit" value="<?php _e(esc_attr('Save Settings'),WHMCS_TEXT_DOMAIN); ?>" />
										</th>
										<td>

										</td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>
					<div class="postbox">
						<h3 class="hndle"><?php _e('WHMCS URL Encoder Tool',WHMCS_TEXT_DOMAIN); ?></h3>
						<div class="inside">
							<table class="form-table">
								<thead>
								</thead>
								<tbody>
									<tr>
										<th>
											<?php _e('URL at WHMCS to encode:',WHMCS_TEXT_DOMAIN); ?>
										</th>
										<td>
											<input type="text" name="wcp_settings[encode_url]" value="<?php echo esc_attr($this->settings['encode_url']); ?>" style="width:100%" />
											<br /><span class="description"><?php _e('All base urls at WHMCS must use the Remote WHMCS base url defined above.<br />Do not mix www.example.com and example.com urls.',WHMCS_TEXT_DOMAIN); ?>  </span>
										</td>
									</tr>
									<tr>
										<th>
											<?php _e('WordPress Encoded URL:',WHMCS_TEXT_DOMAIN); ?>
										</th>
										<td>
											<?php
											$encoded = $this->settings['encode_url'];
											$encoded = str_replace($this->settings['remote_host'], '', $encoded);
											$encoded = $this->redirect_url($encoded, false);
											?>
											<input type="text" id="whmcs_url" value="<?php echo $encoded; ?>"  readonly="readonly" onmouseover="this.select();" style="width:100%" /><br />
										</td>
									</tr>
								</tbody>
							</table>
							<input type="submit" class="button" name="submit" value="<?php _e(esc_attr('Encode the URL'),WHMCS_TEXT_DOMAIN); ?>" />
						</div>
					</div>
					<div class="postbox">
						<h3 class="hndle"><?php _e('WHMCS Integration Shortcodes',WHMCS_TEXT_DOMAIN); ?></h3>
						<div class="inside">
							<table class="form-table">
								<thead>
								</thead>
								<tbody>
									<tr>
										<th><?php _e('WHMCS Main Content:',WHMCS_TEXT_DOMAIN); ?></th>
										<td>
											<input type="text" size="20" value="[wcp_content]" readonly="readonly" onmouseover="this.select();"/>
										</td>
										<td>
											<?php _e('Displays the primary content of a WHMCS page.', WHMCS_TEXT_DOMAIN); ?>
										</td>
									</tr>
									<tr>
										<th><?php _e('WHMCS Welcome Box:',WHMCS_TEXT_DOMAIN); ?></th>
										<td>
											<input type="text" size="20" value="[wcp_welcome]" readonly="readonly" onmouseover="this.select();"/>
										</td>
										<td>
											<?php _e('Displays the current WHMCS user, my details and WHMCS logout link, If not logged in prompts for the user to login.', WHMCS_TEXT_DOMAIN); ?>
										</td>
									</tr>
									<tr>
										<th><?php _e('WHMCS Top Menu:',WHMCS_TEXT_DOMAIN); ?></th>
										<td>
											<input type="text" size="20" value="[wcp_menu]" readonly="readonly" onmouseover="this.select();"/>
										</td>
										<td>
											<?php _e('Displays the top menu from WHMCS. Note that you can style it with css as either a vertical or horizontal menu. In a sidebar it would default to vertical.',WHMCS_TEXT_DOMAIN); ?>
										</td>
									</tr>
									<tr>
										<th><?php _e('WHMCS Quick Navigation:',WHMCS_TEXT_DOMAIN); ?></th>
										<td>
											<input type="text" size="20" value="[wcp_quick_nav]" readonly="readonly" onmouseover="this.select();"/>
										</td>
										<td>
											<?php _e('Displays the Quick Navigation links. Note that you can style it with css as either a vertical or horizontal menu. In a sidebar it would default to vertical.',WHMCS_TEXT_DOMAIN); ?>
										</td>
									</tr>
									<tr>
										<th><?php _e('WHMCS Account Information:',WHMCS_TEXT_DOMAIN); ?></th>
										<td>
											<input type="text" size="20" value="[wcp_account]" readonly="readonly" onmouseover="this.select();"/>
										</td>
										<td>
											<?php _e("Displays the current logged in WHMCS user's account information. If no user is logged in it will display a login form and Knowledgebase search.",WHMCS_TEXT_DOMAIN); ?>
										</td>
									</tr>
									<tr>
										<th><?php _e('WHMCS Statistics:',WHMCS_TEXT_DOMAIN); ?></th>
										<td>
											<input type="text" size="20" value="[wcp_statistics]" readonly="readonly" onmouseover="this.select();"/>
										</td>
										<td>
											<?php _e("Displays the current logged in WHMCS user's product statistics. If no user is logged in it will not be displayed.",WHMCS_TEXT_DOMAIN); ?>
										</td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	* admin_sso_page - Displays the Admin settings page.
	*
	*/
	function admin_sso_page(){

		//var_dump($this->whmcs_api("getclientsdetails", array( 'clientid'=> 3  ) ) );
		?>
		<div class="wrap">
			<?php $this->tabs(); ?>

			<form method="POST" action="#">
				<div class="postbox">
					<h3 class="hndle"><?php _e('WHMCS Single Sign On',WHMCS_TEXT_DOMAIN); ?></h3>
					<div class="inside">
						<?php wp_nonce_field('wcp_admin','wcp_wpnonce'); ?>
						<table class="form-table">
							<tbody>
								<tr>
									<th><?php _e('WHMCS Administrator ID:',WHMCS_TEXT_DOMAIN); ?></th>
									<td>
										<input type="text" name="wcp_settings[whmcs_admin]" size="40" value="<?php echo esc_attr($this->settings['whmcs_admin']); ?>" />
									</td>
								</tr>
								<tr>
									<th><?php _e('WHMCS Administrator Password:',WHMCS_TEXT_DOMAIN); ?></th>
									<td>
										<input type="password" name="wcp_settings[whmcs_password]" size="40" value="**********" autocomplete="off" />
									</td>
								</tr>
								<tr>
									<th>
										<input type="submit" class="button-primary" name="submit" value="<?php _e(esc_attr('Save Settings'),WHMCS_TEXT_DOMAIN); ?>" />
									</th>
									<td>

									</td>
								</tr>
							</tbody>
						</table>
					</div>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	* get_captcha_url - Retrieves a captcha image from WHMCS and saves it for display at the browser so it will match the server session context.
	*
	*/
	function get_captcha_url(){
		$this->method = 'GET';

		$fname = "verify-" . session_id() . '.png';

		@unlink(WHMCS_INTEGRATION_CACHE_DIR . $fname);

		$url = $this->remote_host . 'includes/verifyimage.php?ver=' . uniqid() ;
		$response = $this->redirect_request(	$url);

		if(is_wp_error($response) ){
			return '';
		} elseif($response['response']['code'] == 200){
			file_put_contents(WHMCS_INTEGRATION_CACHE_DIR . $fname, $response['body'] );
		}

		return WHMCS_INTEGRATION_CACHE_URL . $fname . '?ver=' . uniqid();
	}

}


add_action( 'widgets_init', create_function( '', 'register_widget( "WHMCS_Content_Widget" );' ) );
class WHMCS_Content_Widget extends WP_Widget{

	public function __construct(){

		parent::__construct(
		'whmcs-content-widget',
		'WHMCS Content' ,
		array( 'description' => __('Displays the primary content of a WHMCS page.',WHMCS_TEXT_DOMAIN) ) );

	}

	public function widget( $args, $instance ) {
		extract( $args );

		$title = apply_filters('widget_title', $instance['title'] );

		echo $before_widget;

		if ( $title ) echo $before_title . $title . $after_title;

		echo do_shortcode( '[wcp_content]' );

		echo $after_widget;
	}

	public function form( $instance ) {
		if ( isset( $instance[ 'title' ] ) ) {
			$title = $instance[ 'title' ];
		}
		else {
			$title = __( 'New title', 'text_domain' );
		}
		?>
		<p>
			<label for="<?php echo $this->get_field_id( 'title' ); ?>"><?php _e( 'Title:' ); ?></label>
			<input class="widefat" id="<?php echo $this->get_field_id( 'title' ); ?>" name="<?php echo $this->get_field_name( 'title' ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
		</p>
		<?php
	}


	public function update( $new_instance, $old_instance ) {
		$instance = $old_instance;

		/* Strip tags (if needed) and update the widget settings. */
		$instance['title'] = strip_tags( $new_instance['title'] );

		return $instance;
	}

}

add_action( 'widgets_init', create_function( '', 'register_widget( "WHMCS_Welcome_Widget" );' ) );
class WHMCS_Welcome_Widget extends WP_Widget{

	public function __construct(){

		parent::__construct(
		'whmcs-welcome-widget',
		'WHMCS Welcome' ,
		array( 'description' => __('Displays the current WHMCS user, my details and WHMCS logout link, If not logged in prompts for the user to login.',WHMCS_TEXT_DOMAIN) ) );

	}

	public function widget( $args, $instance ) {
		extract( $args );

		$title = apply_filters('widget_title', $instance['title'] );

		echo $before_widget;

		if ( $title ) echo $before_title . $title . $after_title;

		echo do_shortcode( '[wcp_welcome]' );

		echo $after_widget;
	}

	public function form( $instance ) {
		if ( isset( $instance[ 'title' ] ) ) {
			$title = $instance[ 'title' ];
		}
		else {
			$title = __( 'New title', 'text_domain' );
		}
		?>
		<p>
			<label for="<?php echo $this->get_field_id( 'title' ); ?>"><?php _e( 'Title:' ); ?></label>
			<input class="widefat" id="<?php echo $this->get_field_id( 'title' ); ?>" name="<?php echo $this->get_field_name( 'title' ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
		</p>
		<?php
	}

	public function update( $new_instance, $old_instance ) {
		$instance = $old_instance;

		/* Strip tags (if needed) and update the widget settings. */
		$instance['title'] = strip_tags( $new_instance['title'] );

		return $instance;
	}

}

add_action( 'widgets_init', create_function( '', 'register_widget( "WHMCS_Menu_Widget" );' ) );
class WHMCS_Menu_Widget extends WP_Widget{

	public function __construct(){

		parent::__construct(
		'whmcs-menu-widget',
		'WHMCS Menu' ,
		array( 'description' => __('Displays the top menu from WHMCS. Note that you can style it with css as either a vertical or horizontal menu. In a sidebar it would default to vertical.',WHMCS_TEXT_DOMAIN) ) );

	}

	public function widget( $args, $instance ) {
		extract( $args );

		$title = apply_filters('widget_title', $instance['title'] );

		echo $before_widget;

		if ( $title ) echo $before_title . $title . $after_title;

		echo do_shortcode( '[wcp_menu]' );

		echo $after_widget;
	}

	public function form( $instance ) {
		if ( isset( $instance[ 'title' ] ) ) {
			$title = $instance[ 'title' ];
		}
		else {
			$title = __( 'New title', 'text_domain' );
		}
		?>
		<p>
			<label for="<?php echo $this->get_field_id( 'title' ); ?>"><?php _e( 'Title:' ); ?></label>
			<input class="widefat" id="<?php echo $this->get_field_id( 'title' ); ?>" name="<?php echo $this->get_field_name( 'title' ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
		</p>
		<?php
	}

	public function update( $new_instance, $old_instance ) {
		$instance = $old_instance;

		/* Strip tags (if needed) and update the widget settings. */
		$instance['title'] = strip_tags( $new_instance['title'] );

		return $instance;
	}

}

add_action( 'widgets_init', create_function( '', 'register_widget( "WHMCS_Statistics_Widget" );' ) );
class WHMCS_Statistics_Widget extends WP_Widget{

	public function __construct(){

		parent::__construct(
		'whmcs-statictics-widget',
		'WHMCS Statistics' ,
		array( 'description' => __("Displays the current logged in WHMCS user's product statistics. If no user is logged in it will not be displayed.",WHMCS_TEXT_DOMAIN) ) );

	}

	public function widget( $args, $instance ) {
		extract( $args );

		$title = apply_filters('widget_title', $instance['title'] );

		echo $before_widget;

		if ( $title ) echo $before_title . $title . $after_title;

		echo do_shortcode( '[wcp_statistics]' );

		echo $after_widget;
	}

	public function form( $instance ) {
		if ( isset( $instance[ 'title' ] ) ) {
			$title = $instance[ 'title' ];
		}
		else {
			$title = __( 'New title', 'text_domain' );
		}
		?>
		<p>
			<label for="<?php echo $this->get_field_id( 'title' ); ?>"><?php _e( 'Title:' ); ?></label>
			<input class="widefat" id="<?php echo $this->get_field_id( 'title' ); ?>" name="<?php echo $this->get_field_name( 'title' ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
		</p>
		<?php
	}

	public function update( $new_instance, $old_instance ) {
		$instance = $old_instance;

		/* Strip tags (if needed) and update the widget settings. */
		$instance['title'] = strip_tags( $new_instance['title'] );

		return $instance;
	}

}

add_action( 'widgets_init', create_function( '', 'register_widget( "WHMCS_Account_Widget" );' ) );
class WHMCS_Account_Widget extends WP_Widget{

	public function __construct(){

		parent::__construct(
		'whmcs-account-widget',
		'WHMCS Account' ,
		array( 'description' => __("Displays the current logged in WHMCS user's account information. If no user is logged in it will display a login form and Knowledgebase search.",WHMCS_TEXT_DOMAIN) ) );
	}

	public function widget( $args, $instance ) {
		extract( $args );

		$title = apply_filters('widget_title', $instance['title'] );

		echo $before_widget;

		if ( $title ) echo $before_title . $title . $after_title;

		echo do_shortcode( '[wcp_account]' );

		echo $after_widget;
	}

	public function form( $instance ) {
		if ( isset( $instance[ 'title' ] ) ) {
			$title = $instance[ 'title' ];
		}
		else {
			$title = __( 'New title', 'text_domain' );
		}
		?>
		<p>
			<label for="<?php echo $this->get_field_id( 'title' ); ?>"><?php _e( 'Title:' ); ?></label>
			<input class="widefat" id="<?php echo $this->get_field_id( 'title' ); ?>" name="<?php echo $this->get_field_name( 'title' ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
		</p>
		<?php
	}

	public function update( $new_instance, $old_instance ) {
		$instance = $old_instance;

		/* Strip tags (if needed) and update the widget settings. */
		$instance['title'] = strip_tags( $new_instance['title'] );

		return $instance;
	}

}

add_action( 'widgets_init', create_function( '', 'register_widget( "WHMCS_Quick_Navigation_Widget" );' ) );
class WHMCS_Quick_Navigation_Widget extends WP_Widget{

	public function __construct(){

		parent::__construct(
		'whmcs-quick-nav-widget',
		'WHMCS Quick Navigation' ,
		array( 'description' => __('Displays the Quick Navigation links. Note that you can style it with css as either a vertical or horizontal menu. In a sidebar it would default to vertical.',WHMCS_TEXT_DOMAIN) ) );

	}

	public function widget( $args, $instance ) {
		extract( $args );

		$title = apply_filters('widget_title', $instance['title'] );

		echo $before_widget;

		if ( $title ) echo $before_title . $title . $after_title;

		echo do_shortcode( '[wcp_quick_nav]' );

		echo $after_widget;
	}

	public function form( $instance ) {
		if ( isset( $instance[ 'title' ] ) ) {
			$title = $instance[ 'title' ];
		}
		else {
			$title = __( 'New title', 'text_domain' );
		}
		?>
		<p>
			<label for="<?php echo $this->get_field_id( 'title' ); ?>"><?php _e( 'Title:' ); ?></label>
			<input class="widefat" id="<?php echo $this->get_field_id( 'title' ); ?>" name="<?php echo $this->get_field_name( 'title' ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
		</p>
		<?php
	}

	public function update( $new_instance, $old_instance ) {
		$instance = $old_instance;

		/* Strip tags (if needed) and update the widget settings. */
		$instance['title'] = strip_tags( $new_instance['title'] );

		return $instance;
	}


}


/**
* array_replace() substitute for PHP < 5.3
*
* @$array
*	@$$array1 [, $... ]
*/
if (!function_exists('array_replace') ):
function array_replace(){
	$array=array();
	$n=func_num_args();
	while ($n-- >0) {
		$array+=func_get_arg($n);
	}
	return $array;
}
endif;

/**
* array_replace_recursive() substitute for PHP < 5.3
*
* @$array
*	@$$array1 [, $... ]
*/
if(! function_exists('array_replace_recursive') ):
function array_replace_recursive($base, $replacements)
{
	foreach (array_slice(func_get_args(), 1) as $replacements) {
		$bref_stack = array(&$base);
		$head_stack = array($replacements);
		do {
			end($bref_stack);
			$bref = &$bref_stack[key($bref_stack)];
			$head = array_pop($head_stack);
			unset($bref_stack[key($bref_stack)]);
			foreach (array_keys($head) as $key) {
				if (isset($key, $bref) && is_array($bref[$key]) && is_array($head[$key])) {
					$bref_stack[] = &$bref[$key];
					$head_stack[] = $head[$key];
				} else {
					$bref[$key] = $head[$key];
				}
			}
		} while(count($head_stack));
	}
	return $base;
}
endif;

/**
* str_getcsv() substitute for PHP < 5.3
*
* @$input string - string to parse
*	@$delimiter char - default ','
* @$enclosure - default '"'
* @$escape - character to escape enclosures or delimeters
* @$eol - End of line character.
*/
if (!function_exists('str_getcsv')):
function str_getcsv($input, $delimiter=',', $enclosure='"', $escape=null, $eol=null) {
	$temp=fopen("php://memory", "rw");
	fwrite($temp, $input);
	fseek($temp, 0);
	$r = array();
	while (($data = fgetcsv($temp, 4096, $delimiter, $enclosure)) !== false) {
		$r[] = $data;
	}
	fclose($temp);
	return $r;
}
endif;