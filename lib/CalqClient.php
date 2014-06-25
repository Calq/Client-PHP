<?php
//  Copyright 2014 Calq.io
//
//  Licensed under the Apache License, Version 2.0 (the "License"); you may not use this file except in 
//  compliance with the License. You may obtain a copy of the License at
//
//    http://www.apache.org/licenses/LICENSE-2.0
//
//  Unless required by applicable law or agreed to in writing, software distributed under the License is 
//  distributed on an "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or
//  implied. See the License for the specific language governing permissions and limitations under the 
//  License.

require_once(dirname(__FILE__) . "/CalqApiProcessor.php");
require_once(dirname(__FILE__) . "/ReservedActionProperties.php");
require_once(dirname(__FILE__) . "/ReservedApiProperties.php");

if (!function_exists('json_encode')) {
    throw new Exception('The JSON PHP extension is required.');
}
if (!function_exists('base64_encode')) {
    throw new Exception('The URLs PHP extension is required.');
}

/**
 * Main class for interacting with the Calq API using PHP.
 * 
 * Quick Example
 * -------------
 * 
 * $calq = CalqClient::fromCurrentRequest('YOUR_WRITE_KEY_HERE');
 * $calq->track('Product Review', array('Product' => 'XS T-Shirt', 'Rating' => 9.0 ));
 * 
 * About
 * -----
 * 
 * The CalqClient will store api calls in memory until your PHP request has finished. The queue will
 * be flushed automatically at the end of each request (via this class' destructor). Alternatively
 * you can call flush() manually.
 *  
 */
class CalqClient {

    /**
     * The cookie name used by this instance (normally would match JS cookie name)
     * @var string
     */
    private $_cookieName = '_calq_d';
    
    /**
     * The cookie domain used by this instance. If NULL it will not be manually specified in the cookie.
     * @var string
     */
    private $_cookieDomain = NULL;
    
    /**
     * The expiry time for the Calq cookie (days).
     * @var int
     */
    private $_cookieExpires = 180;
    
    /**
     * The actor string (ID) of the user represented by this client instance.
     * @var string
     */
    private $_actor;
    
    /**
     * Whether this client is anonymous or identified.
     * @var boolean
     */
    private $_isAnon;
    
    /**
     * Whether this client has tracked any actions yet.
     * @var boolean
     */
    private $_hasTracked;
    
    /**
     * Global properties that are sent with every event.
     * @var array
     */
    private $_globalProperties;
    
    /**
     * Previous cookie data if we had any.
     * @var array
     */
    private $_previousCookie;
    
    /**
     * The ApiProcessor queueing calls for this instance.
     * @var array
     */
    private $_api;
    
    /**
     * Singleton instance of CalqClient to be reused for this request.
     * @var CalqClient
     */
    private static $_singleton;
    
    /**
     * Instantiates a new CalqClient instance.
     * @param string $actor
     * @param string $write_key
     * @param array $options
     */
    public function __construct($actor, $write_key, $options = array()) {
        if(strlen($actor) == 0) {
            throw new Exception('An $actor parameter must be specified');
        }
        if(strlen($write_key) < 32 /* Might be larger later */) {
            throw new Exception('A valid $write_key parameter must be specified');
        }
        
        $this->_api = new CalqApiProcessor($write_key, $options);
        
        // New users are anon until told otherwise
        $this->_actor = $actor;
        $this->_isAnon = true;
        $this->_hasTracked = false;
        $this->_globalProperties = array();
        
        // Any advanced custom options?
        if(isset($options["cookieName"])) {
            $this->_cookieName = $options["cookieName"];
        }
        if(isset($options["cookieDomain"])) {
            $this->_cookieDomain = $options["cookieDomain"];
        }
		// Any really advanced options? 
		if(isset($options["apiProcessor"])) {
            $this->_api = $options["apiProcessor"];
        }
	}
    
    /**
     * Flushes the current queue when we are destroyed.
     */
    public function __destruct() {
        $this->flush();
    }
    
    /**
     * Gets a CalqClient instance populated from the current request. This will read any
     * state from previous sessions (or the JS client) accordingly. This is the recommended
     * way of getting CalqClient instances.
     *
     * Subsequent calls to this method will return the same instance (singleton). Be aware of this
     * if you need to track different users within the same request.
     * @param string $write_key
     * @param array $options.
     * @return CalqClient
     */
    public static function fromCurrentRequest($write_key, $options = array()) {
        if(!isset(self::$_singleton)) {
            $newActor = self::createAnonymousUserId(); // Id will be overwritten if client has existing state we can read
            $calq = new CalqClient($newActor, $write_key, $options);
            
            if(isset($_COOKIE[$calq->_cookieName])) {
                $calq->parseCookieState($_COOKIE[$calq->_cookieName]);
            } else {
                // New client. Make sure we write this state out
                $calq->writeCookieState();
            }
            $calq->parseRequestState();
            
            self::$_singleton = $calq;
        }
        return self::$_singleton;
    }
    
    /**
     * Parses any state which can be read from the request rather than the cookie (such as new UTM params, user agent, etc).
     */
    public function parseRequestState() {
        // Always overwrite agent for current request in case it changes
        if(isset($_SERVER['HTTP_USER_AGENT'])) {
            $this->setGlobalProperty(ReservedActionProperties::$device_agent, $_SERVER['HTTP_USER_AGENT']);
        }
    
        // Only overwrite UTM params if not already set
        $request = array_merge($_GET, $_POST);    // Favour POST on collision
        $params = array(    // Reserverd property => query string mapping
            ReservedActionProperties::$utm_campaign => 'utm_campaign',
            ReservedActionProperties::$utm_source => 'utm_source',
            ReservedActionProperties::$utm_medium => 'utm_medium',
            ReservedActionProperties::$utm_content => 'utm_content',
            ReservedActionProperties::$utm_term => 'utm_term',
        );
        foreach ($params as $reserved_key => $qs_key) {
            if(!isset($this->_globalProperties[$reserved_key]) && isset($request[$qs_key])) {
                $this->setGlobalProperty($reserved_key, $request[$qs_key]);
            }
        }
    }
    
    /**
     * Parses the given cookie and reads the state into this CalqClient instance. Used to maintain state
     * between requests (such as the user's identity) and to communicate back to the JS library if in use.
     * @param string $cookie
     */
    public function parseCookieState($cookie) {
        // Careful changing here. The cookie format needs to match the format in the JS client lib.
        //
        // Current cookie format is Base64 encoded JSON in format:
        //
        //  {
        //        actor: "some_id",
        //        hasAction: true,
        //        isAnon: true,
        //        actionGlobal: {
        //              someGlobalProperty: "someValue"
        //        }
        //  }
        //
        
        // If either part of decoding fails there isn't much we can do. Just treat as new user
        $decoded = base64_decode($cookie);
        if(!$decoded) {
            return;
        }
        $json = json_decode($decoded, true);
        if(!$json || count($json) == 0) {
            return;
        }
    
        // Decode properties we understand
        if(isset($json['actor']) && strlen($json['actor']) > 0) {
            // We MUST have recognised the actor node if we going to parse rest (else we would assign custom data to random ID)
            $this->_actor = $json['actor'];
            
            if(isset($json['hasAction'])) {
                $this->_hasTracked = $json['hasAction'];
            }
            
            if(isset($json['isAnon'])) {
                $this->_isAnon = $json['isAnon'];
            }
            
            // Parse global properties
            if(isset($json['actionGlobal'])) {
                $this->_globalProperties = $json['actionGlobal'];    
            }
            
            // Save entire cookie. Then we can overwrite bits we understand, and ignore the rest. Makes backwards compatible
            $this->_previousCookie = $json;
        }
    }
    
    /**
     * Writes the state of this CalqClient instance to the cookie.
     */
    public function writeCookieState() {
        // Note: See parseCookieState for cookie format information.
         
        $json = $this->_previousCookie ? $this->_previousCookie : array();
        $json['actor'] = $this->_actor;
        $json['hasAction'] = $this->_hasTracked;
        $json['isAnon'] = $this->_isAnon;
        $json['actionGlobal'] = $this->_globalProperties;
         
        $encoded_json = base64_encode(json_encode($json));

        // Can't set if headers are already sent
        if(headers_sent()) {
            throw new Exception("CalqClient is unable to write cookie state as headers have already been sent");
        } else {
            setcookie($this->_cookieName, $encoded_json, time() + ($this->_cookieExpires * 24 * 60 * 60), '/', $this->_cookieDomain);
        }
    }
    
    /**
     * Tracks an action for the actor this client belongs to. Call this every time your actor does something
     * that you care about and might want to analyze later.
     * @param string $action
     * @param array $properties
     */
    public function track($action, $properties = array()) {
        if(!isset($properties)) {
            $properties = array();
        }
		
        $merged_properties = array_merge($this->_globalProperties, $properties);
    
        $ipAddress = self::getSourceIpAddress();
        $apiProperties = array( ReservedApiProperties::$ipAddress => $ipAddress );

        $this->_api->track($this->_actor, $action, $apiProperties, $merged_properties);
        
        if(!$this->_hasTracked) {
            $this->_hasTracked = true;
            $this->writeCookieState();
        }
    }
    
    /**
     * Tracks an sale action for the actor this client belongs to. Sale actions are actions which have an associated
     * monetary value (in the form of amount and currency).
     * @param string $action
     * @param array $properties
     * @param string $currency
     * @param float $amount
     */
    public function trackSale($action, $properties, $currency, $amount) {
        if(strlen($currency) != 3) {
            throw new Exception('The $currency parameter must be a 3 letter currency code (fictional or otherwise)');
        }
        
        if(!isset($properties)) {
            $properties = array();
        }
        
        $properties[ReservedActionProperties::$sale_currency] = $currency;
        $properties[ReservedActionProperties::$sale_value] = $amount;
    
        $this->track($action, $properties);
    }
    
    /**
     * Sets a global property to be sent with all actions (calls to track(...)). This will also
     * be written back to the client in the client cookie (thus you must call this before
     * andy HTTP headers have been sent)
     * @param string $property
     * @param mixed $value
     */
    public function setGlobalProperty($property, $value) {
        // Avoid rewriting unless changed (as we have to dump state again)
        if(!isset($this->_globalProperties[$property]) || $this->_globalProperties[$property] != $value) {
            $this->_globalProperties[$property] = $value;
            $this->writeCookieState();
        }
    }
    
    /**
     * Sets the ID of this client to something else. This should be called if you register/sign-in 
     * a user and want to associate previously anonymous actions with this new identity.
     * @param string $actor
     */
    public function identify($actor) {
        if($actor != $this->_actor) {
            if(!$this->_isAnon) {
                throw new Exception('identify(...) must not be called more than once for the same user.');
            }
            $oldActor = $this->_actor;
            $this->_actor = $actor;
            
            // Only transfer if actions have already been sent by us
            if ($this->_hasTracked) {
                $this->_api->transfer($oldActor, $actor);
            }
            
            // State has changed
            $this->_isAnon = false;
            $this->_hasTracked = false;
            $this->writeCookieState();
        }
    }
    
    /**
     * Sets profile properties for the current user. These are not the same as global properties.
     * A user must be identified before calling profile.
     * @param array $properties
     */
    public function profile($properties) {
        
        if(!isset($properties) || count($properties) == 0) {
            throw new Exception('You must pass some information to profile(...) (or else there isn\'t much point)');
        }
        if($this->_isAnon) {
            throw new Exception('A client must be identified (call identify(...)) before calling profile(...)');
        }

        $this->_api->profile($this->_actor, $properties);
    }
    
    /**
     * Requests that outstanding API calls are flushed immediately.
     */
    public function flush() {
        $this->_api->flush();
    }
    
    /**
     * Clears the current session and resets to being an anonymous user.
     */
    public function clear() {
        $this->_actor = self::createAnonymousUserId();
        $this->_hasTracked = false;
        $this->_isAnon = true;
        $this->_globalProperties = array();
        $this->_previousCookie = NULL;    // Kill previous cookie, else we will merge with that on save
        
        $this->writeCookieState();
    }
	
	///////////////////////////// Properties /////////////////////////////
	
	/**
	 * Gets the actor this instance is for.
	 */
	public function getActor() {
		return $this->_actor;
	}
    
    ///////////////////////////// Internal util methods /////////////////////////////

    /**
     * Generates a v4 UUID to identify this Calq user (until identify(...) is called).
     */
    public static function createAnonymousUserId() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',

            // 32 bits for "time_low"
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),

            // 16 bits for "time_mid"
            mt_rand(0, 0xffff),

            // 16 bits for "time_hi_and_version", four most significant bits holds version number 4
            mt_rand(0, 0x0fff) | 0x4000,

            // 16 bits, 8 bits for "clk_seq_hi_res",
            // 8 bits for "clk_seq_low",
            // two most significant bits holds zero and one for variant DCE1.1
            mt_rand(0, 0x3fff) | 0x8000,

            // 48 bits for "node"
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
    
    /**
     * Gets the IP address of the current request. Source IP often shouldn't be used in web environment as we
     * will be behind proxy / caches / load balancers etc. Used for Calq's geolocation.
     */
    private static function getSourceIpAddress() {
        $ipAddress = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;	// Won't be set in unit tests, so check
        
        if(isset($_SERVER['HTTP_X_REAL_IP'])) {
            $ipAddress = $_SERVER['HTTP_X_REAL_IP'];
        }

        if(isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // Favour this over x-real-ip
            $split = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ipAddress = $split[count($split) - 1];
        }
        
        // Test that it is actually sane (could still be spoofed, but as a lib we can't do anything about that)
        if(!filter_var($ipAddress, FILTER_VALIDATE_IP)) {
            return "none";    // Special value that tells client to not geo this request
        }
        
        return $ipAddress;
    }
}