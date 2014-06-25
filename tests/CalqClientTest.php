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

require_once("../lib/CalqClient.php");

class CalqClientTest extends PHPUnit_Framework_TestCase
{
	/**
	 * Dummy write key we use for end to end tests.
     */
    private $_writeKey = '55ebeaebfcd351e0b69e6cc99dbb081d';
	
	protected function setUp()
    {
		// Mock setCookie
		runkit_function_redefine('setcookie', 
			'$name, $value = null, $expire = null, $path = null, $domain = null, $secure = null, $httponly = null', 
			'$_COOKIE["_calq_d"] = $value;');
		// We don't have headers in tests (no output)
		runkit_function_redefine('headers_sent', '', 'return false;');
    }
	
	/**
	 * Tests creating CalqClient instances and that the singleton instance is correctly populated.
	 */
	public function  testClientSharedInstance() {
		$this->resetCookieState();
	
		$calq1 = CalqClient::fromCurrentRequest($this->_writeKey);
		$calq2 = CalqClient::fromCurrentRequest($this->_writeKey);
		
		$this->assertNotNull($calq1, 'Client returned from fromCurrentRequest was null');
		$this->assertEquals($calq1, $calq2, 'Clients did not match');
	}

	/**
	 * Tests that identify is correctly updating the instance.
	 */
	public function testIdentifyUpdatesInstance() {
		$this->resetCookieState();
	
		$actor = $this->generateTestActor();
		
		$calq = new CalqClient(CalqClient::createAnonymousUserId(), $this->_writeKey);
		$calq->identify($actor);
		
		$this->assertEquals($actor, $calq->getActor(), 'Actor did not match after identify');
	}
	
	/**
	 * Tests that calling identify twice doesn't update the 2nd time.
	 * @expectedException Exception
	 */
	public function testIdentifyFailsOnMultipleCalls() {
		$this->resetCookieState();
	
		$calq = new CalqClient(CalqClient::createAnonymousUserId(), $this->_writeKey);
		
		$actor = $this->generateTestActor();
		$actor2 = $this->generateTestActor();
		$this->assertNotEquals($actor, $actor2, 'Actors should not match');
		
		$calq->identify($actor);
		$calq->identify($actor2);	// Should throw. Really need to get identify to return a specific exception here
	}
	
	/**
	 * Tests that state is correctly being persisted and read back.
	 */
	public function testStatePersistence() {
		$this->resetCookieState();
	
		$actor = $this->generateTestActor();

		$calq = new CalqClient(CalqClient::createAnonymousUserId(), $this->_writeKey);
		$calq->identify($actor);
		
		$calq2 = new CalqClient(CalqClient::createAnonymousUserId(), $this->_writeKey);
		$calq2->parseCookieState($_COOKIE["_calq_d"]);
		
		$this->assertEquals($calq->getActor(), $calq2->getActor(), 'Actors should match after loading state');
	}
	
	/**
	 * Tests that clear is working correctly.
	 */
	public function testClear() {
		$this->resetCookieState();
		
		$calq = new CalqClient(CalqClient::createAnonymousUserId(), $this->_writeKey);
		$actor = $calq->getActor();
		
		$calq->clear();
		
		$this->assertNotEquals($calq->getActor(), $actor, 'Actors should not match after clear');
	}
	
	/**
	 * Tests some end to end API calls.
	 */
	public function testEndToEnd() {
		$this->resetCookieState();
		
		$calq = new CalqClient(CalqClient::createAnonymousUserId(), $this->_writeKey);
		$calq->track('PHP Test Action (Anon)');
		
		$calq->identify($this->generateTestActor());
		
		$calq->track('PHP Test Action');
		$calq->trackSale('PHP Test Sale', null, 'USD', 9.99);
		
		$calq->profile(array( '$email' => 'test@notarealemail.com'));
		
		$calq->flush();
	}

	/**
	 * Generates a test actor Id for use in our tests.
	 */
    private function generateTestActor() {
		$random = rand(0, 100000);
		return "TestActor" . $random;
	}
	
	/**
	 * Resets the cookie state between tests.
	 */
    private function resetCookieState() {
		$_COOKIE["_calq_d"] = null;
	}
}

