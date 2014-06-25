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

if (!function_exists('curl_init')) {
    throw new Exception('The cURL PHP extension is required.');
}

/**
 * This class dispatches API calls to Calq's API server.
 */
class CalqApiProcessor {
    
    /**
     * The max number of actions we let queue up before we force a flush anyway.
     * @var int
     */
    private $_maxQueueSize = 100;
    
    /**
     * The max number of times we retry an API call before giving up (connection errors only).
     * @var int
     */
    private $_maxRetries = 5;
    
    /**
     * Whether or not to use HTTPS when communicating with the API server.
     * @var bool
     */
    private $_useSecure = false;        // Default to off as it's quicker
    
    /**
     * The host for the API server (just hostname, no protocol)
     * @var int
     */
    private $_apiServerHost = 'api.calq.io';
    
    /**
     * The write key this ApiProcessor is using to send actions.
     * @var string
     */
    private $_writeKey;
    
    /**
     * The queue of API calls to that have not yet been sent.
     * @var array
     */
    private $_queue;
    
    /**
     * Instantiates a new CalqApiProcessor instance.
     * @param string $write_key
     * @param array $options
     */
    public function __construct($write_key, $options = array()) {
        $this->_writeKey = $write_key;
        $this->_queue = array();
    }
    
    /**
     * Makes a Track API call with the given properties.
     * @param string $actor
     * @param string $action
     * @param array $apiParams
     * @param array $userProperties
     */
    public function track($actor, $action, $apiParams, $userProperties) {
        if ($apiParams === null) {
            throw new Exception('$apiParams must be specified');
        }
        if ($userProperties === null) {
            throw new Exception('$userProperties must be specified');
        }
        
        $apiParams[ReservedApiProperties::$actor] = $actor;
        $apiParams[ReservedApiProperties::$actionName] = $action;
        $apiParams[ReservedApiProperties::$writeKey] = $this->_writeKey;
        $apiParams[ReservedApiProperties::$userProperties] = 
			empty($userProperties) ? new stdClass() : $userProperties;	// json_encode gives array if empty
        $apiParams[ReservedApiProperties::$timestamp] = gmdate(DATE_ISO8601);

        $this->enqueue('Track', $apiParams);
    }
    
    /**
     * Makes a call to the Profile endpoint to save information about a user.
     * @param string $actor
     * @param array $userProperties
     */
    public function profile($actor, $userProperties) {
        if ($userProperties === null || count($userProperties) == 0) {
            throw new Exception('$userProperties must be specified');
        }
            
        $apiParams = array(
            ReservedApiProperties::$actor => $actor,
            ReservedApiProperties::$writeKey => $this->_writeKey,
            ReservedApiProperties::$userProperties => $userProperties
        );

        $this->enqueue('Profile', $apiParams);
    }
    
    /**
     * Makes a call to the Transfer endpoint to associate anonymous actions with new actions.
     * @param string $oldActor
     * @param string $newActor
     */
    public function transfer($oldActor, $newActor) {
        if (strlen($oldActor) == 0) {
            throw new Exception('$oldActor must be specified');
        }
        if (strlen($newActor) == 0) {
            throw new Exception('$newActor must be specified');
        }
            
        $apiParams = array(
            ReservedApiProperties::$oldActor => $oldActor,
            ReservedApiProperties::$newActor => $newActor,
            ReservedApiProperties::$writeKey => $this->_writeKey
        );

        $this->enqueue('Transfer', $apiParams);
    }
    
    /**
     * Forces any currently queued API calls to be flushed immediately.
     */
    public function flush() {
        while(count($this->_queue) > 0) {
            // TODO: Check for multiple actions and batch together rather than one at a time
            $call = array_shift($this->_queue);
            
            $payload = json_encode($call->payload);
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);    
            curl_setopt($ch, CURLOPT_TIMEOUT, 15); 
            curl_setopt($ch, CURLOPT_URL, ($this->_useSecure ? 'https' : 'http') . '://' . $this->_apiServerHost . '/' . $call->endpoint);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');                                                                     
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);                                                                  
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);                                                                      
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json',
                'Content-Length: '.strlen($payload))                                                                       
            );
            // TODO: Implement forking as an option on suitable platforms
            $response = curl_exec($ch);
            
            if($response === false) {
                // No response. We can possibly retry this one 
                if($call->retries < $this->_maxRetries) {
                    $call->retries ++;
                    array_push($this->_queue, $call);
                } else {
                    throw new Exception('Failed to reach Calq API server (' . $this->_apiServerHost . ') after ' . $call->retries . ' retries');
                }
            } else {
                // Got a response. Could still be an error code
                $info = curl_getinfo($ch);
                curl_close($ch);
                if ($info['http_code'] !== 200) {
                    // Api exception. Can't retry these!
                    $decodedResponse = json_decode($response);
                    throw new Exception($decodedResponse->error, $info['http_code']);
                }
            }
        }
    }
    
    /**
     * Enqueues the given API call.
     * @param string $endpoint
     * @param array $payload
     */
    private function enqueue($endpoint, $payload) {
        array_push($this->_queue, new ApiCall($endpoint, $payload));
        if(count($this->_queue) >= $this->_maxQueueSize) {
            $this->flush();
        }
    }

}
/**
 * Helper class wrapping up API call data
 */
class ApiCall {
    public $endpoint;
    public $payload;
    public $retries;
    
    function __construct($endpoint, $payload) {
        $this->endpoint = $endpoint;
        $this->payload = $payload;
        $this->retries = 0;
    }
}