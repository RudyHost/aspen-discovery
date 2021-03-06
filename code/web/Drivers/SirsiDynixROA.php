<?php

require_once ROOT_DIR . '/Drivers/HorizonAPI.php';
require_once ROOT_DIR . '/sys/Account/User.php';

class SirsiDynixROA extends HorizonAPI
{
	//TODO: Additional caching of sessionIds by patron
	private static $sessionIdsForUsers = array();

	private function staffOrPatronSessionTokenSwitch(){
		$useStaffAccountForWebServices = true;
		global $configArray;
		if (isset($configArray['Catalog']['useStaffSessionTokens'])) {
			$useStaffAccountForWebServices = $configArray['Catalog']['useStaffSessionTokens'];
		}
		return $useStaffAccountForWebServices;

	}
	// $customRequest is for curl, can be 'PUT', 'DELETE', 'POST'
	public function getWebServiceResponse($url, $params = null, $sessionToken = null, $customRequest = null, $additionalHeaders = null)
	{
		global $logger;
		global $library;
		$logger->log('WebServiceURL :' .$url, Logger::LOG_NOTICE);
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		$clientId = $this->accountProfile->oAuthClientId;
		$headers  = array(
			'Accept: application/json',
			'Content-Type: application/json',
			'SD-Originating-App-Id: Aspen Discovery',
			'SD-Working-LibraryID: ' . $library->subdomain,
			'x-sirs-clientID: ' . $clientId,
		);
		if ($sessionToken != null) {
			$headers[] = 'x-sirs-sessionToken: ' . $sessionToken;
		}
		if (!empty($additionalHeaders) && is_array($additionalHeaders)) {
			$headers = array_merge($headers, $additionalHeaders);
		}
		if (empty($customRequest)) {
			curl_setopt($ch, CURLOPT_HTTPGET, true);
		} elseif ($customRequest == 'POST') {
			curl_setopt($ch, CURLOPT_POST, true);
		}
		else {
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $customRequest);
		}

		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_HEADER, false);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		global $instanceName;
		if (stripos($instanceName, 'localhost') !== false) {
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // TODO: debugging only: comment out for production

		}
		//TODO: need switch to set this option when using on local machine
		if ($params != null) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
		}
		$json = curl_exec($ch);
		curl_close($ch);

		if ($json !== false && $json !== 'false') {
			return json_decode($json);
		} else {
			$logger->log('Curl problem in getWebServiceResponse', Logger::LOG_WARNING);
			return false;
		}
	}

	private static $userPreferredAddresses = array();

	function findNewUser($barcode) {
		// Creates a new user like patronLogin but looks up user by barcode.
		// Note: The user pin is not supplied in the Account Info Lookup call.
		$sessionToken = $this->getStaffSessionToken();
		if (!empty($sessionToken)) {
			$webServiceURL = $this->getWebServiceURL();
			$lookupMyAccountInfoResponse = $this->getWebServiceResponse($webServiceURL . '/user/patron/search?q=ID:' .$barcode . '&rw=1&ct=1&includeFields=firstName,lastName,privilegeExpiresDate,preferredAddress,address1,address2,address3,library,circRecordList,blockList,holdRecordList,primaryPhone', null, $sessionToken);
			if (!empty($lookupMyAccountInfoResponse->result) && $lookupMyAccountInfoResponse->totalResults == 1) {
				$userID = $lookupMyAccountInfoResponse->result[0]->key;
				$lookupMyAccountInfoResponse = $lookupMyAccountInfoResponse->result[0];
				$lastName  = $lookupMyAccountInfoResponse->fields->lastName;
				$firstName = $lookupMyAccountInfoResponse->fields->firstName;

				$fullName = $lastName . ', ' . $firstName;

				$userExistsInDB = false;
				$user = new User();
				$user->source = $this->accountProfile->name;
				$user->username = $userID;
				if ($user->find(true)) {
					$userExistsInDB = true;
				}

				$forceDisplayNameUpdate = false;
				$firstName              = isset($firstName) ? $firstName : '';
				if ($user->firstname != $firstName) {
					$user->firstname        = $firstName;
					$forceDisplayNameUpdate = true;
				}
				$lastName = isset($lastName) ? $lastName : '';
				if ($user->lastname != $lastName) {
					$user->lastname         = isset($lastName) ? $lastName : '';
					$forceDisplayNameUpdate = true;
				}
				if ($forceDisplayNameUpdate) {
					$user->displayName = '';
				}
				$user->_fullname     = isset($fullName) ? $fullName : '';
				$user->cat_username = $barcode;

				$Address1    = "";
				$City        = "";
				$State       = "";
				$Zip         = "";

				if (isset($lookupMyAccountInfoResponse->fields->preferredAddress)) {
					$preferredAddress = $lookupMyAccountInfoResponse->fields->preferredAddress;
					// Set for Account Updating
					self::$userPreferredAddresses[(string)$userID] = $preferredAddress;
					// Used by My Account Profile to update Contact Info
					if ($preferredAddress == 1) {
						$address = $lookupMyAccountInfoResponse->fields->address1;
					} elseif ($preferredAddress == 2) {
						$address = $lookupMyAccountInfoResponse->fields->address2;
					} elseif ($preferredAddress == 3) {
						$address = $lookupMyAccountInfoResponse->fields->address3;
					} else {
						$address = array();
					}
					foreach ($address as $addressField) {
						$fields = $addressField->fields;
						switch ($fields->code->key) {
							case 'STREET' :
								$Address1 = $fields->data;
								break;
							case 'CITY/STATE' :
								$cityState = $fields->data;
								if (substr_count($cityState, ' ') > 1) {
									//Splitting multiple word cities
									$last_space = strrpos($cityState, ' ');
									$City = substr($cityState, 0, $last_space);
									$State = substr($cityState, $last_space+1);

								} else {
									list($City, $State) = explode(' ', $cityState);
								}
								break;
							case 'ZIP' :
								$Zip = $fields->data;
								break;
							case 'PHONE' :
								$phone = $fields->data;
								$user->phone = $phone;
								break;
							case 'EMAIL' :
								$email = $fields->data;
								$user->email = $email;
								break;
						}
					}

				}

				//Get additional information about the patron's home branch for display.
				if (isset($lookupMyAccountInfoResponse->fields->library->key)) {
					$homeBranchCode = strtolower(trim($lookupMyAccountInfoResponse->fields->library->key));
					//Translate home branch to plain text
					$location       = new Location();
					$location->code = $homeBranchCode;
					if (!$location->find(true)) {
						unset($location);
					}
				} else {
					global $logger;
					$logger->log('SirsiDynixROA Driver: No Home Library Location or Hold location found in account look-up. User : ' . $user->id, Logger::LOG_ERROR);
					// The code below will attempt to find a location for the library anyway if the homeLocation is already set
				}

				if (empty($user->homeLocationId) || (isset($location) && $user->homeLocationId != $location->locationId)) { // When homeLocation isn't set or has changed
					if (empty($user->homeLocationId) && !isset($location)) {
						// homeBranch Code not found in location table and the user doesn't have an assigned homelocation,
						// try to find the main branch to assign to user
						// or the first location for the library
						global $library;

						$location            = new Location();
						$location->libraryId = $library->libraryId;
						$location->orderBy('isMainBranch desc'); // gets the main branch first or the first location
						if (!$location->find(true)) {
							// Seriously no locations even?
							global $logger;
							$logger->log('Failed to find any location to assign to user as home location', Logger::LOG_ERROR);
							unset($location);
						}
					}
					if (isset($location)) {
						$user->homeLocationId = $location->locationId;
						if (empty($user->myLocation1Id)) {
							$user->myLocation1Id  = ($location->nearbyLocation1 > 0) ? $location->nearbyLocation1 : $location->locationId;
							//Get display name for preferred location 1
							$myLocation1             = new Location();
							$myLocation1->locationId = $user->myLocation1Id;
							if ($myLocation1->find(true)) {
								$user->_myLocation1 = $myLocation1->displayName;
							}
						}

						if (empty($user->myLocation2Id)){
							$user->myLocation2Id  = ($location->nearbyLocation2 > 0) ? $location->nearbyLocation2 : $location->locationId;
							//Get display name for preferred location 2
							$myLocation2             = new Location();
							$myLocation2->locationId = $user->myLocation2Id;
							if ($myLocation2->find(true)) {
								$user->_myLocation2 = $myLocation2->displayName;
							}
						}
					}
				}

				if (isset($location)) {
					//Get display names that aren't stored
					$user->_homeLocationCode = $location->code;
					$user->_homeLocation     = $location->displayName;
				}

				if (isset($lookupMyAccountInfoResponse->fields->privilegeExpiresDate)) {
					$user->_expires = $lookupMyAccountInfoResponse->fields->privilegeExpiresDate;
					list ($yearExp, $monthExp, $dayExp) = explode("-", $user->_expires);
					$timeExpire   = strtotime($monthExp . "/" . $dayExp . "/" . $yearExp);
					$timeNow      = time();
					$timeToExpire = $timeExpire - $timeNow;
					if ($timeToExpire <= 30 * 24 * 60 * 60) {
						//TODO: the ils also has an expire soon flag in the patronStatusInfo
						if ($timeToExpire <= 0) {
							$user->_expired = 1;
						}
						$user->_expireClose = 1;
					}
				}

				//Get additional information about fines, etc

				$finesVal = 0;
				if (isset($lookupMyAccountInfoResponse->fields->blockList)) {
					foreach ($lookupMyAccountInfoResponse->fields->blockList as $block) {
						// $block is a simplexml object with attribute info about currency, type casting as below seems to work for adding up. plb 3-27-2015
						$fineAmount = (float)$block->fields->owed->amount;
						$finesVal   += $fineAmount;
					}
				}

				$numHoldsAvailable = 0;
				$numHoldsRequested = 0;
				if (isset($lookupMyAccountInfoResponse->fields->holdRecordList)) {
					foreach ($lookupMyAccountInfoResponse->fields->holdRecordList as $hold) {
						if ($hold->fields->status == 'BEING_HELD') {
							$numHoldsAvailable++;
						} elseif ($hold->fields->status != 'EXPIRED') {
							$numHoldsRequested++;
						}
					}
				}

				$user->_address1              = $Address1;
				$user->_address2              = $City . ', ' . $State;
				$user->_city                  = $City;
				$user->_state                 = $State;
				$user->_zip                   = $Zip;
//				$user->phone                 = isset($phone) ? $phone : '';
				$user->_fines                 = sprintf('$%01.2f', $finesVal);
				$user->_finesVal              = $finesVal;
				$user->_numCheckedOutIls      = isset($lookupMyAccountInfoResponse->fields->circRecordList) ? count($lookupMyAccountInfoResponse->fields->circRecordList) : 0;
				$user->_numHoldsIls           = $numHoldsAvailable + $numHoldsRequested;
				$user->_numHoldsAvailableIls  = $numHoldsAvailable;
				$user->_numHoldsRequestedIls  = $numHoldsRequested;
				$user->patronType            = 0; //TODO: not getting this info here?
				$user->_notices               = '-';
				$user->_noticePreferenceLabel = 'Email';
				$user->_web_note              = '';

				if ($userExistsInDB) {
					$user->update();
				} else {
					$user->created = date('Y-m-d');
					$user->insert();
				}

				return $user;

			}
		}
		return false;
	}

	public function patronLogin($username, $password, $validatedViaSSO)
	{
		global $timer;

		//Remove any spaces from the barcode
		$username = trim($username);
		$password = trim($password);

		//Authenticate the user via WebService
		//First call loginUser
		$timer->logTime("Logging in through Symphony APIs");
		list($userValid, $sessionToken, $sirsiRoaUserID) = $this->loginViaWebService($username, $password);
		if ($validatedViaSSO) {
			$userValid = true;
		}
		if ($userValid) {
			$timer->logTime("User is valid in symphony");
			$webServiceURL = $this->getWebServiceURL();

			//  Calls that show how patron-related data is represented
			//	$patronDescribeResponse           = $this->getWebServiceResponse($webServiceURL . '/user/patron/describe', null, $sessionToken);
			//	$patronPhoneDescribeResponse           = $this->getWebServiceResponse($webServiceURL . '/user/patron/phone/describe', null, $sessionToken);
			//	$patronPhoneListDescribeResponse           = $this->getWebServiceResponse($webServiceURL . '/user/patron/phoneList/describe', null, $sessionToken);
			//	$patronStatusInfoDescribeResponse = $this->getWebServiceResponse($webServiceURL . '/user/patronStatusInfo/describe', null, $sessionToken);
			//	$patronAddress1PolicyDescribeResponse = $this->getWebServiceResponse($webServiceURL . '/user/patron/address1/describe', null, $sessionToken);

			$includeFields = urlEncode("firstName,lastName,privilegeExpiresDate,preferredAddress,address1,address2,address3,library,primaryPhone,blockList{owed}");
			$accountInfoLookupURL = $webServiceURL . '/user/patron/key/' . $sirsiRoaUserID . '?includeFields=' . $includeFields;

			// phoneList is for texting notification preferences
			$lookupMyAccountInfoResponse = $this->getWebServiceResponse($accountInfoLookupURL, null, $sessionToken);
			if ($lookupMyAccountInfoResponse && !isset($lookupMyAccountInfoResponse->messageList)) {
				$lastName  = $lookupMyAccountInfoResponse->fields->lastName;
				$firstName = $lookupMyAccountInfoResponse->fields->firstName;


				$fullName = $lastName . ', ' . $firstName;

				$userExistsInDB = false;
				$user = new User();
				$user->source = $this->accountProfile->name;
				$user->username = $sirsiRoaUserID;
				if ($user->find(true)) {
					$userExistsInDB = true;
				}

				$forceDisplayNameUpdate = false;
				$firstName              = isset($firstName) ? $firstName : '';
				if ($user->firstname != $firstName) {
					$user->firstname        = $firstName;
					$forceDisplayNameUpdate = true;
				}
				$lastName = isset($lastName) ? $lastName : '';
				if ($user->lastname != $lastName) {
					$user->lastname         = isset($lastName) ? $lastName : '';
					$forceDisplayNameUpdate = true;
				}
				if ($forceDisplayNameUpdate) {
					$user->displayName = '';
				}
				$user->_fullname     = isset($fullName) ? $fullName : '';
				$user->cat_username = $username;
				$user->cat_password = $password;

				$Address1    = "";
				$City        = "";
				$State       = "";
				$Zip         = "";

				if (isset($lookupMyAccountInfoResponse->fields->preferredAddress)) {
					$preferredAddress = $lookupMyAccountInfoResponse->fields->preferredAddress;
					// Set for Account Updating
					self::$userPreferredAddresses[$sirsiRoaUserID] = $preferredAddress;
					// Used by My Account Profile to update Contact Info
					if ($preferredAddress == 1) {
						$address = $lookupMyAccountInfoResponse->fields->address1;
					} elseif ($preferredAddress == 2) {
						$address = $lookupMyAccountInfoResponse->fields->address2;
					} elseif ($preferredAddress == 3) {
						$address = $lookupMyAccountInfoResponse->fields->address3;
					} else {
						$address = array();
					}
					foreach ($address as $addressField) {
						$fields = $addressField->fields;
						switch ($fields->code->key) {
							case 'STREET' :
								$Address1 = $fields->data;
								break;
							case 'CITY/STATE' :
								$cityState = $fields->data;
								if (substr_count($cityState, ' ') > 1) {
									//Splitting multiple word cities
									$last_space = strrpos($cityState, ' ');
									$City = substr($cityState, 0, $last_space);
									$State = substr($cityState, $last_space+1);

								} else {
									list($City, $State) = explode(' ', $cityState);
								}
								break;
							case 'ZIP' :
								$Zip = $fields->data;
								break;
							case 'PHONE' :
								$phone = $fields->data;
								$user->phone = $phone;
								break;
							case 'EMAIL' :
								$email = $fields->data;
								$user->email = $email;
								break;
						}
					}
				}

				//Get additional information about the patron's home branch for display.
				if (isset($lookupMyAccountInfoResponse->fields->library->key)) {
					$homeBranchCode = strtolower(trim($lookupMyAccountInfoResponse->fields->library->key));
					//Translate home branch to plain text
					$location       = new Location();
					$location->code = $homeBranchCode;
					if (!$location->find(true)) {
						unset($location);
					}
				} else {
					global $logger;
					$logger->log('SirsiDynixROA Driver: No Home Library Location or Hold location found in account look-up. User : ' . $user->id, Logger::LOG_ERROR);
					// The code below will attempt to find a location for the library anyway if the homeLocation is already set
				}

				if (empty($user->homeLocationId) || (isset($location) && $user->homeLocationId != $location->locationId)) { // When homeLocation isn't set or has changed
					if (empty($user->homeLocationId) && !isset($location)) {
						// homeBranch Code not found in location table and the user doesn't have an assigned homelocation,
						// try to find the main branch to assign to user
						// or the first location for the library
						global $library;

						$location            = new Location();
						$location->libraryId = $library->libraryId;
						$location->orderBy('isMainBranch desc'); // gets the main branch first or the first location
						if (!$location->find(true)) {
							// Seriously no locations even?
							global $logger;
							$logger->log('Failed to find any location to assign to user as home location', Logger::LOG_ERROR);
							unset($location);
						}
					}
					if (isset($location)) {
						$user->homeLocationId = $location->locationId;
						if (empty($user->myLocation1Id)) {
							$user->myLocation1Id  = ($location->nearbyLocation1 > 0) ? $location->nearbyLocation1 : $location->locationId;
							//Get display name for preferred location 1
							$myLocation1             = new Location();
							$myLocation1->locationId = $user->myLocation1Id;
							if ($myLocation1->find(true)) {
								$user->_myLocation1 = $myLocation1->displayName;
							}
						}

						if (empty($user->myLocation2Id)){
							$user->myLocation2Id  = ($location->nearbyLocation2 > 0) ? $location->nearbyLocation2 : $location->locationId;
							//Get display name for preferred location 2
							$myLocation2             = new Location();
							$myLocation2->locationId = $user->myLocation2Id;
							if ($myLocation2->find(true)) {
								$user->_myLocation2 = $myLocation2->displayName;
							}
						}
					}
				}

				if (isset($location)) {
					//Get display names that aren't stored
					$user->_homeLocationCode = $location->code;
					$user->_homeLocation     = $location->displayName;
				}

				if (isset($lookupMyAccountInfoResponse->fields->privilegeExpiresDate)) {
					$user->_expires = $lookupMyAccountInfoResponse->fields->privilegeExpiresDate;
					list ($yearExp, $monthExp, $dayExp) = explode("-", $user->_expires);
					$timeExpire   = strtotime($monthExp . "/" . $dayExp . "/" . $yearExp);
					$timeNow      = time();
					$timeToExpire = $timeExpire - $timeNow;
					if ($timeToExpire <= 30 * 24 * 60 * 60) {
						if ($timeToExpire <= 0) {
							$user->_expired = 1;
						}
						$user->_expireClose = 1;
					}
				}

				$finesVal = 0;
				if (isset($lookupMyAccountInfoResponse->fields->blockList)) {
					foreach ($lookupMyAccountInfoResponse->fields->blockList as $block) {
						$fineAmount = (float)$block->fields->owed->amount;
						$finesVal   += $fineAmount;
					}
				}

				$user->_address1              = $Address1;
				$user->_address2              = $City . ', ' . $State;
				$user->_city                  = $City;
				$user->_state                 = $State;
				$user->_zip                   = $Zip;
				$user->_fines                 = sprintf('$%01.2f', $finesVal);
				$user->_finesVal              = $finesVal;
				$user->patronType            = 0; //TODO: not getting this info here?
				$user->_notices               = '-';
				$user->_noticePreferenceLabel = 'Email';
				$user->_web_note              = '';

				if ($userExistsInDB) {
					$user->update();
				} else {
					$user->created = date('Y-m-d');
					$user->insert();
				}

				$timer->logTime("patron logged in successfully");
				return $user;
			} else {
				$timer->logTime("lookupMyAccountInfo failed");
				global $logger;
				$logger->log('Symphony API call lookupMyAccountInfo failed.', Logger::LOG_ERROR);
				return null;
			}
		}
		return null;
	}

	public function getAccountSummary($user, $forceRefresh = false) {
		$summary = [
			'numCheckedOut' => 0,
			'numOverdue' => 0,
			'numAvailableHolds' => 0,
			'numUnavailableHolds' => 0,
			'totalFines' => 0,
			'expires' => '',
			'expired' => 0,
			'expireClose' => 0,
		];

		$webServiceURL = $this->getWebServiceURL();
		$includeFields = urlencode("circRecordList{overdue},blockList{owed},holdRecordList{status},privilegeExpiresDate");
		$accountInfoLookupURL = $webServiceURL . '/user/patron/key/' . $user->username . '?includeFields=' . $includeFields;

		$sessionToken = $this->getSessionToken($user);
		$lookupMyAccountInfoResponse = $this->getWebServiceResponse($accountInfoLookupURL, null, $sessionToken);
		if ($lookupMyAccountInfoResponse && !isset($lookupMyAccountInfoResponse->messageList)) {
			$summary['numCheckedOut'] = count($lookupMyAccountInfoResponse->fields->circRecordList);
			foreach ($lookupMyAccountInfoResponse->fields->circRecordList as $checkout) {
				if ($checkout->fields->overdue){
					$summary['numOverdue']++;
				}
			}

			if (isset($lookupMyAccountInfoResponse->fields->holdRecordList)) {
				foreach ($lookupMyAccountInfoResponse->fields->holdRecordList as $hold) {
					//Get detailed info about the hold
					if ($hold->fields->status == 'BEING_HELD') {
						$summary['numAvailableHolds']++;
					} elseif ($hold->fields->status != 'EXPIRED') {
						$summary['numUnavailableHolds']++;
					}
				}
			}

			$finesVal = 0;
			if (isset($lookupMyAccountInfoResponse->fields->blockList)) {
				foreach ($lookupMyAccountInfoResponse->fields->blockList as $block) {
					$fineAmount = (float)$block->fields->owed->amount;
					$finesVal   += $fineAmount;
				}
			}
			$summary['totalFines'] = $finesVal;

			$summary['expires'] = $lookupMyAccountInfoResponse->fields->privilegeExpiresDate;
			list ($yearExp, $monthExp, $dayExp) = explode("-", $summary['expires']);
			$timeExpire   = strtotime($monthExp . "/" . $dayExp . "/" . $yearExp);
			$timeNow      = time();
			$timeToExpire = $timeExpire - $timeNow;
			if ($timeToExpire <= 30 * 24 * 60 * 60) {
				if ($timeToExpire <= 0) {
					$summary['expired'] = 1;
				}
				$summary['expireClose'] = 1;
			}
		}

		return $summary;
	}

	private function getStaffSessionToken() {
		global $configArray;
		$staffSessionToken = false;
		if (!empty($configArray['Catalog']['selfRegStaffUser']) && !empty( $configArray['Catalog']['selfRegStaffPassword'])) {
			$selfRegStaffUser     = $configArray['Catalog']['selfRegStaffUser'];
			$selfRegStaffPassword = $configArray['Catalog']['selfRegStaffPassword'];
			list(, $staffSessionToken) = $this->staffLoginViaWebService($selfRegStaffUser, $selfRegStaffPassword);
		}
		return $staffSessionToken;
		}

	function selfRegister()
	{
		$selfRegResult = array(
			'success' => false,
		);

		$sessionToken = $this->getStaffSessionToken();
		if (!empty($sessionToken)) {
			$webServiceURL            = $this->getWebServiceURL();

			// $patronDescribeResponse   = $this->getWebServiceResponse($webServiceURL . '/user/patron/describe');
			// $address1DescribeResponse = $this->getWebServiceResponse($webServiceURL . '/user/patron/address1/describe');
			// $addressDescribeResponse  = $this->getWebServiceResponse($webServiceURL . '/user/patron/address/describe');
			// $userProfileDescribeResponse  = $this->getWebServiceResponse($webServiceURL . '/policy/userProfile/describe');

			$createPatronInfoParameters  = array(
				'fields' => array(),
				'resource' => '/user/patron',
			);
			$preferredAddress = 1;

			// Build Address Field with existing data
			$index = 0;

			// Closure to handle the data structure of the address parameters to pass onto the ILS
			$setField = function ($key, $value) use (&$createPatronInfoParameters, $preferredAddress, &$index) {
				static $parameterIndex = array();

				$addressField                = 'address' . $preferredAddress;
				$patronAddressPolicyResource = '/policy/patron' . ucfirst($addressField);

				$l = array_key_exists($key, $parameterIndex) ? $parameterIndex[$key] : $index++;
				$createPatronInfoParameters['fields'][$addressField][$l] = array(
					'resource' => '/user/patron/' . $addressField,
					'fields' => array(
						'code' => array(
							'key' => $key,
							'resource' => $patronAddressPolicyResource
						),
						'data' => $value
					)
				);
				$parameterIndex[$key] = $l;

			};

			$createPatronInfoParameters['fields']['profile'] = array(
				'resource' => '/policy/userProfile',
				'key' => 'VIRTUAL',
			);

			if (!empty($_REQUEST['firstName'])) {
				$createPatronInfoParameters['fields']['firstName'] = trim($_REQUEST['firstName']);
			}
			if (!empty($_REQUEST['middleName'])) {
				$createPatronInfoParameters['fields']['middleName'] = trim($_REQUEST['middleName']);
			}
			if (!empty($_REQUEST['lastName'])) {
				$createPatronInfoParameters['fields']['lastName'] = trim($_REQUEST['lastName']);
			}
			if (!empty($_REQUEST['suffix'])) {
				$createPatronInfoParameters['fields']['suffix'] = trim($_REQUEST['suffix']);
			}
			if (!empty($_REQUEST['birthDate'])) {
				$birthdate = date_create_from_format('m-d-Y', trim($_REQUEST['birthDate']));
				$createPatronInfoParameters['fields']['birthDate'] = $birthdate->format('Y-m-d');
			}
			if (!empty($_REQUEST['pin'])) {
				$pin = trim($_REQUEST['pin']);
				if (!empty($pin) && $pin == trim($_REQUEST['pin1'])) {
					$createPatronInfoParameters['fields']['pin'] = $pin;
				} else {
					// Pin Mismatch
					return array(
						'success' => false,
					);
				}
			} else {
				// No Pin
				return array(
					'success' => false,
				);
			}


			// Update Address Field with new data supplied by the user
			if (isset($_REQUEST['email'])) {
				$setField('EMAIL', $_REQUEST['email']);
			}

			if (isset($_REQUEST['phone'])) {
				$setField('PHONE', $_REQUEST['phone']);
			}

			if (isset($_REQUEST['address'])) {
				$setField('STREET', $_REQUEST['address']);
			}

			if (isset($_REQUEST['city']) && isset($_REQUEST['state'])) {
				$setField('CITY/STATE', $_REQUEST['city'] . ' ' . $_REQUEST['state']);
			}

			if (isset($_REQUEST['zip'])) {
				$setField('ZIP', $_REQUEST['zip']);
			}

			// Update Home Location
			if (!empty($_REQUEST['pickupLocation'])) {
				$homeLibraryLocation = new Location();
				if ($homeLibraryLocation->get('code', $_REQUEST['pickupLocation'])) {
					$homeBranchCode                                  = strtoupper($homeLibraryLocation->code);
					$createPatronInfoParameters['fields']['library'] = array(
						'key' => $homeBranchCode,
						'resource' => '/policy/library'
					);
				}
			}

			$barcode = new Variable();
			if ($barcode->get('name', 'self_registration_card_number')){
				$createPatronInfoParameters['fields']['barcode'] = $barcode->value;

				global $configArray;
				$overrideCode = $configArray['Catalog']['selfRegOverrideCode'];
				$overrideHeaders = array('SD-Prompt-Return:USER_PRIVILEGE_OVRCD/'.$overrideCode);


				$createNewPatronResponse = $this->getWebServiceResponse($webServiceURL . '/user/patron/', $createPatronInfoParameters, $sessionToken, 'POST', $overrideHeaders);

				if (isset($createNewPatronResponse->messageList)) {
					foreach ($createNewPatronResponse->messageList as $message) {
						$updateErrors[] = $message->message;
						if ($message->message == 'User already exists') {
							// This means the barcode counter is off.
							global $logger;
							$logger->log('Sirsi Self Registration response was that the user already exists. Advancing the barcode counter by one.', Logger::LOG_ERROR);
							$barcode->value++;
							if (!$barcode->update()) {
								$logger->log('Sirsi Self Registration barcode counter did not increment when a user already exists!', Logger::LOG_ERROR);
							}
						}
					}
					global $logger;
					$logger->log('Symphony Driver - Patron Info Update Error - Error from ILS : ' . implode(';', $updateErrors), Logger::LOG_ERROR);
				} else {
					$selfRegResult = array(
						'success' => true,
						'barcode' => $barcode->value++
					);
					// Update the card number counter for the next Self-Reg user
					if (!$barcode->update()) {
						// Log Error temp barcode number not
						global $logger;
						$logger->log('Sirsi Self Registration barcode counter not saving incremented value!', Logger::LOG_ERROR);
					}
				}
			} else {
				// Error: unable to set barcode number.
				global $logger;
				$logger->log('Sirsi Self Registration barcode counter was not found!', Logger::LOG_ERROR);
			};
		} else {
			// Error: unable to login in staff user
			global $logger;
			$logger->log('Unable to log in with Sirsi Self Registration staff user', Logger::LOG_ERROR);
		}
		return $selfRegResult;
	}


	protected function loginViaWebService($username, $password)
	{
		global $memCache;
		$memCacheKey = "sirsiROA_session_token_info_$username";
		$session = $memCache->get($memCacheKey);
		if ($session) {
			list(, $sessionToken, $sirsiRoaUserID) = $session;
			SirsiDynixROA::$sessionIdsForUsers[$sirsiRoaUserID] = $sessionToken;
		} else {
			$session = array(false, false, false);
			$webServiceURL = $this->getWebServiceURL();
			//$loginDescribeResponse = $this->getWebServiceResponse($webServiceURL . '/user/patron/login/describe');
			$loginUserUrl      = $webServiceURL . '/user/patron/login';
			$params            = array(
				'login' => $username,
				'password' => $password,
			);
			$loginUserResponse = $this->getWebServiceResponse($loginUserUrl, $params);
			if ($loginUserResponse && isset($loginUserResponse->sessionToken)) {
				//We got at valid user (A bad call will have isset($loginUserResponse->messageList) )
					$sirsiRoaUserID                                     = $loginUserResponse->patronKey;
					$sessionToken                                       = $loginUserResponse->sessionToken;
					SirsiDynixROA::$sessionIdsForUsers[(string)$sirsiRoaUserID] = $sessionToken;
					$session = array(true, $sessionToken, $sirsiRoaUserID);
					global $configArray;
					$memCache->set($memCacheKey, $session, $configArray['Caching']['sirsi_roa_session_token']);
			} elseif (isset($loginUserResponse->messageList)) {
				global $logger;
				$errorMessage = 'Sirsi ROA Webservice Login Error: ';
				foreach ($loginUserResponse->messageList as $error){
					$errorMessage .= $error->message.'; ';
				}
				$logger->log($errorMessage, Logger::LOG_ERROR);
			}
		}
		return $session;
	}

	protected function staffLoginViaWebService($username, $password)
	{
		global $memCache;
		$memCacheKey = "sirsiROA_session_token_info_$username";
		$session = $memCache->get($memCacheKey);
		if ($session) {
			list(, $sessionToken, $sirsiRoaUserID) = $session;
			SirsiDynixROA::$sessionIdsForUsers[$sirsiRoaUserID] = $sessionToken;
		} else {
			$session = array(false, false, false);
			$webServiceURL = $this->getWebServiceURL();
			// $loginDescribeResponse = $this->getWebServiceResponse($webServiceURL . '/user/patron/login/describe');
			$loginUserUrl      = $webServiceURL . '/user/staff/login';
			$params            = array(
				'login' => $username,
				'password' => $password,
			);
			$loginUserResponse = $this->getWebServiceResponse($loginUserUrl, $params);
			if ($loginUserResponse && isset($loginUserResponse->sessionToken)) {
				//We got at valid user (A bad call will have isset($loginUserResponse->messageList) )

					$sirsiRoaUserID                                     = $loginUserResponse->staffKey;
					//this is the same value as patron Key, if user is logged in with that call.
					$sessionToken                                       = $loginUserResponse->sessionToken;
					SirsiDynixROA::$sessionIdsForUsers[(string)$sirsiRoaUserID] = $sessionToken;
					$session = array(true, $sessionToken, $sirsiRoaUserID);
					global $configArray;
					$memCache->set($memCacheKey, $session, $configArray['Caching']['sirsi_roa_session_token']);
			} elseif (isset($loginUserResponse->messageList)) {
				global $logger;
				$errorMessage = 'Sirsi ROA Webservice Login Error: ';
				foreach ($loginUserResponse->messageList as $error){
					$errorMessage .= $error->message.'; ';
				}
				$logger->log($errorMessage, Logger::LOG_ERROR);
			}
		}
		return $session;
	}

	/**
	 * @param User $patron
	 * @param int $page
	 * @param int $recordsPerPage
	 * @param string $sortOption
	 * @return array
	 */
	public function getCheckouts($patron, $page = 1, $recordsPerPage = -1, $sortOption = 'dueDate')
	{
		$checkedOutTitles = array();

		//Get the session token for the user
		$sessionToken = $this->getSessionToken($patron);
		if (!$sessionToken) {
			return $checkedOutTitles;
		}

		//Now that we have the session token, get holds information
		$webServiceURL = $this->getWebServiceURL();
		//Get a list of holds for the user
		$includeFields = urlencode('circRecordList{*,item{bib{title},itemType,call{dispCallNumber}}}');
		$patronCheckouts = $this->getWebServiceResponse($webServiceURL . '/user/patron/key/' . $patron->username . '?includeFields=' . $includeFields, null, $sessionToken);

		if (!empty($patronCheckouts->fields->circRecordList)) {
			$sCount = 0;
			require_once ROOT_DIR . '/RecordDrivers/MarcRecordDriver.php';

			foreach ($patronCheckouts->fields->circRecordList as $checkout) {
				if (empty($checkout->fields->claimsReturnedDate) && $checkout->fields->status != 'INACTIVE') { // Titles with a claims return date will not be displayed in check outs.
					$curTitle = array();
					$curTitle['checkoutSource'] = 'ILS';

					list($bibId) = explode(':', $checkout->key);
					$curTitle['recordId'] = $bibId;
					$curTitle['shortId']  = $bibId;
					$curTitle['id']       = $bibId;
					$curTitle['itemId'] = $checkout->fields->item->key;

					$curTitle['dueDate']      = strtotime($checkout->fields->dueDate);
					$curTitle['checkoutDate'] = strtotime($checkout->fields->checkOutDate);
					// Note: there is an overdue flag
					$curTitle['renewCount']     = $checkout->fields->renewalCount;
					$curTitle['canRenew']       = $checkout->fields->seenRenewalsRemaining > 0;
					$curTitle['renewIndicator'] = $checkout->fields->item->key;

					$curTitle['format'] = 'Unknown';
					$recordDriver = new MarcRecordDriver('a' . $bibId);
					if ($recordDriver->isValid()) {
						$curTitle['coverUrl']      = $recordDriver->getBookcoverUrl('medium', true);
						$curTitle['groupedWorkId'] = $recordDriver->getGroupedWorkId();
						$curTitle['format']        = $recordDriver->getPrimaryFormat();
						$curTitle['title']         = $recordDriver->getTitle();
						$curTitle['title_sort']    = $recordDriver->getSortableTitle();
						$curTitle['author']        = $recordDriver->getPrimaryAuthor();
						$curTitle['link']          = $recordDriver->getLinkUrl();
						$curTitle['ratingData']    = $recordDriver->getRatingData();
					} else {
						// Presumably ILL Items
						$bibInfo                = $checkout->fields->item->fields->bib;
						$curTitle['title']      = $bibInfo->fields->title;
						$simpleSortTitle       = preg_replace('/^The\s|^A\s/i', '', $bibInfo->fields->title); // remove begining The or A
						$curTitle['title_sort'] = empty($simpleSortTitle) ? $bibInfo->fields->title : $simpleSortTitle;
						$curTitle['author']     = $bibInfo->fields->author;
					}
					if ($curTitle['format'] == 'Magazine' && !empty($checkout->fields->item->fields->call->fields->dispCallNumber)) {
						$curTitle['title2'] = $checkout->fields->item->fields->call->fields->dispCallNumber;
					}

					$sCount++;
					$sortTitle = isset($curTitle['title_sort']) ? $curTitle['title_sort'] : $curTitle['title'];
					$sortKey   = $sortTitle;
					if ($sortOption == 'title') {
						$sortKey = $sortTitle;
					} elseif ($sortOption == 'author') {
						$sortKey = (isset($curTitle['author']) ? $curTitle['author'] : "Unknown") . '-' . $sortTitle;
					} elseif ($sortOption == 'dueDate') {
						if (isset($curTitle['dueDate'])) {
							if (preg_match('/.*?(\\d{1,2})[-\/](\\d{1,2})[-\/](\\d{2,4}).*/', $curTitle['dueDate'], $matches)) {
								$sortKey = $matches[3] . '-' . $matches[1] . '-' . $matches[2] . '-' . $sortTitle;
							} else {
								$sortKey = $curTitle['dueDate'] . '-' . $sortTitle;
							}
						}
					} elseif ($sortOption == 'format') {
						$sortKey = (isset($curTitle['format']) ? $curTitle['format'] : "Unknown") . '-' . $sortTitle;
					} elseif ($sortOption == 'renewed') {
						$sortKey = (isset($curTitle['renewCount']) ? $curTitle['renewCount'] : 0) . '-' . $sortTitle;
					} elseif ($sortOption == 'holdQueueLength') {
						$sortKey = (isset($curTitle['holdQueueLength']) ? $curTitle['holdQueueLength'] : 0) . '-' . $sortTitle;
					}
					$sortKey .= "_$sCount";
					$checkedOutTitles[$sortKey] = $curTitle;
				}
			}
		}
		return $checkedOutTitles;
	}
	/**
	 * Get Patron Holds
	 *
	 * This is responsible for retrieving all holds for a specific patron.
	 *
	 * @param User $patron The user to load transactions for
	 *
	 * @return array          Array of the patron's holds
	 * @access public
	 */
	public function getHolds($patron)
	{
		$availableHolds   = array();
		$unavailableHolds = array();
		$holds            = array(
			'available' => $availableHolds,
			'unavailable' => $unavailableHolds
		);

		//Get the session token for the user
		$sessionToken = $this->getSessionToken($patron);
		if (!$sessionToken) {
			return $holds;
		}

		//Now that we have the session token, get holds information
		$webServiceURL = $this->getWebServiceURL();

		//$patronDescribeResponse = $this->getWebServiceResponse($webServiceURL . '/user/patron/describe', null, $sessionToken);
		//$holdRecord  = $this->getWebServiceResponse($webServiceURL . "/circulation/holdRecord/describe", null, $sessionToken);
		//$itemDescribe  = $this->getWebServiceResponse($webServiceURL . "/catalog/item/describe", null, $sessionToken);
		//$callDescribe  = $this->getWebServiceResponse($webServiceURL . "/catalog/call/describe", null, $sessionToken);
		//$copyDescribe  = $this->getWebServiceResponse($webServiceURL . "/catalog/copy/describe", null, $sessionToken);

		//Get a list of holds for the user
		// (Call now includes Item information for when the hold is an item level hold.)
		$includeFields = urlencode("holdRecordList{*,bib{title}}");
		$patronHolds = $this->getWebServiceResponse($webServiceURL . '/user/patron/key/' . $patron->username . '?includeFields=' . $includeFields, null, $sessionToken);
		if ($patronHolds && isset($patronHolds->fields)) {
			require_once ROOT_DIR . '/RecordDrivers/MarcRecordDriver.php';
			foreach ($patronHolds->fields->holdRecordList as $hold) {
				//Get detailed info about the hold
				$curHold               = array();
				$bibId                 = $hold->fields->bib->key;
				$expireDate            = $hold->fields->expirationDate;
				$reactivateDate        = $hold->fields->suspendEndDate;
				$createDate            = $hold->fields->placedDate;
				$fillByDate            = $hold->fields->fillByDate;
				$curHold['id']         = $hold->key;
				$curHold['holdSource'] = 'ILS';
				$curHold['itemId']     = empty($hold->fields->item->key) ? '' : $hold->fields->item->key;
				$curHold['cancelId']   = $hold->key;
				$curHold['position']   = $hold->fields->queuePosition;
				$curHold['recordId']   = $bibId;
				$curHold['shortId']    = $bibId;
				$curPickupBranch       = new Location();
				$curPickupBranch->code = $hold->fields->pickupLibrary->key;
				if ($curPickupBranch->find(true)) {
					$curPickupBranch->fetch();
					$curHold['currentPickupId']   = $curPickupBranch->locationId;
					$curHold['currentPickupName'] = $curPickupBranch->displayName;
					$curHold['location']          = $curPickupBranch->displayName;
				}
				$curHold['currentPickupName']  = $curHold['location'];
				$curHold['status']             = ucfirst(strtolower($hold->fields->status));
				$curHold['create']             = strtotime($createDate);
				$curHold['expire']             = strtotime($expireDate);
				$curHold['automaticCancellation'] = strtotime($fillByDate);
				$curHold['reactivate']         = $reactivateDate;
				$curHold['reactivateTime']     = strtotime($reactivateDate);
				$curHold['cancelable']         = strcasecmp($curHold['status'], 'Suspended') != 0 && strcasecmp($curHold['status'], 'Expired') != 0;
				$curHold['frozen']             = strcasecmp($curHold['status'], 'Suspended') == 0;
				$curHold['canFreeze']         = true;
				if (strcasecmp($curHold['status'], 'Transit') == 0 || strcasecmp($curHold['status'], 'Expired') == 0) {
					$curHold['canFreeze'] = false;
				}
				$curHold['locationUpdateable'] = true;
				if (strcasecmp($curHold['status'], 'Transit') == 0 || strcasecmp($curHold['status'], 'Expired') == 0) {
					$curHold['locationUpdateable'] = false;
				}

				$recordDriver = new MarcRecordDriver('a' . $bibId);
				if ($recordDriver->isValid()) {
					$curHold['title']           = $recordDriver->getTitle();
					$curHold['author']          = $recordDriver->getPrimaryAuthor();
					$curHold['sortTitle']       = $recordDriver->getSortableTitle();
					$curHold['format']          = $recordDriver->getFormat();
					$curHold['isbn']            = $recordDriver->getCleanISBN();
					$curHold['upc']             = $recordDriver->getCleanUPC();
					$curHold['format_category'] = $recordDriver->getFormatCategory();
					$curHold['coverUrl']        = $recordDriver->getBookcoverUrl('medium', true);
					$curHold['link']            = $recordDriver->getLinkUrl();

					//Load rating information
					$curHold['ratingData'] = $recordDriver->getRatingData();

					if ($hold->fields->holdType == 'COPY'){
						$curHold['title2'] = $hold->fields->item->fields->itemType->key . ' - ' . $hold->fields->item->fields->call->fields->callNumber;
					}

				} else {
					// If we don't have good marc record, ask the ILS for title info
					$bibInfo                = $hold->fields->bib;
					$curHold['title']      = $bibInfo->fields->title;
					$simpleSortTitle       = preg_replace('/^The\s|^A\s/i', '', $bibInfo->fields->title); // remove begining The or A
					$curHold['sortTitle'] = empty($simpleSortTitle) ? $bibInfo->fields->title : $simpleSortTitle;
					$curHold['author']     = $bibInfo->fields->author;
				}

				if (!isset($curHold['status']) || strcasecmp($curHold['status'], "being_held") != 0) {
					$holds['unavailable'][] = $curHold;
				} else {
					$holds['available'][] = $curHold;
				}
			}
		}
		return $holds;
	}

	/**
	 * Place Hold
	 *
	 * This is responsible for both placing holds as well as placing recalls.
	 *
	 * @param   User    $patron       The User to place a hold for
	 * @param   string  $recordId     The id of the bib record
	 * @param   string  $pickupBranch The branch where the user wants to pickup the item when available
     * @param   null|string $cancelDate  The date the hold should be automatically cancelled
     * @return  mixed                 True if successful, false if unsuccessful
	 *                                If an error occurs, return a AspenError
	 * @access  public
	 */
	public function placeHold($patron, $recordId, $pickupBranch = null, $cancelDate = null) {
		//For Sirsi ROA we don't really know if a record needs a copy or title level hold.  We determined that we would check
		// the marc record and if the call numbers in the record vary we will place a copy level hold
		$result = array();
		$needsItemHold = false;
		$holdableItems = array();
		/** @var MarcRecordDriver $recordDriver */
		$recordDriver = RecordDriverFactory::initRecordDriverById($this->accountProfile->recordSource . ':' . $recordId);

		if ($recordDriver->isValid()){
			$result['title'] = $recordDriver->getTitle();
			$items = $recordDriver->getCopies();
			$firstCallNumber = null;
			foreach ($items as $item){
				$itemNumber = $item['itemId'];
				if ($itemNumber && $item['holdable']){
					$itemCallNumber = $item['callNumber'];
					if ($firstCallNumber == null){
						$firstCallNumber = $itemCallNumber;
					}else if ($firstCallNumber != $itemCallNumber){
						$needsItemHold = true;
					}

					$holdableItems[] = array(
							'itemNumber' => $item['itemId'],
							'location' => $item['shelfLocation'],
							'callNumber' => $itemCallNumber,
							'status' => $item['status'],
					);
				}
			}
		}

		if (!$needsItemHold){
			$result = $this->placeItemHold($patron, $recordId, null, $pickupBranch, 'request', $cancelDate);
		}else{
			$result['items'] = $holdableItems;
			if (count($holdableItems) > 0){
				$message = 'This title requires item level holds, please select an item to place a hold on.';
			}else{
				$message = 'There are no holdable items for this title.';
			}
			$result['success'] = false;
			$result['message'] = $message;
		}

		return $result;
	}

	/**
	 * Place Item Hold
	 *
	 * This is responsible for both placing item level holds.
	 *
	 * @param User $patron The User to place a hold for
	 * @param string $recordId The id of the bib record
	 * @param string $itemId The id of the item to hold
	 * @param string $pickupBranch The Pickup Location
	 * @param string $type Whether to place a hold or recall
	 * @param null|string $cancelIfNotFilledByDate When to cancel the hold automatically if it is not filled
	 * @return  mixed               True if successful, false if unsuccessful
	 *                              If an error occurs, return a AspenError
	 * @access  public
	 */
	function placeItemHold($patron, $recordId, $itemId, $pickupBranch = null, $type = 'request', $cancelIfNotFilledByDate = null)
	{

		//Get the session token for the user
		$sessionToken = $this->staffOrPatronSessionTokenSwitch() ? $this->getStaffSessionToken() : $this->getSessionToken($patron);
		if (!$sessionToken) {
			return array(
				'success' => false,
				'message' => 'Sorry, it does not look like you are logged in currently.  Please login and try again');
		}

		// Retrieve Full Marc Record
		require_once ROOT_DIR . '/RecordDrivers/RecordDriverFactory.php';
		$record = RecordDriverFactory::initRecordDriverById('ils:' . $recordId);
		if (!$record) {
			$title = null;
		} else {
			$title = $record->getTitle();
		}

		global $offlineMode;
		if ($offlineMode) {
			require_once ROOT_DIR . '/sys/OfflineHold.php';
			$offlineHold                = new OfflineHold();
			$offlineHold->bibId         = $recordId;
			$offlineHold->patronBarcode = $patron->getBarcode();
			$offlineHold->patronId      = $patron->id;
			$offlineHold->timeEntered   = time();
			$offlineHold->status        = 'Not Processed';
			if ($offlineHold->insert()) {
				//TODO: use bib or bid ??
				return array(
					'title' => $title,
					'bib' => $recordId,
					'success' => true,
					'message' => 'The circulation system is currently offline.  This hold will be entered for you automatically when the circulation system is online.');
			} else {
				return array(
					'title' => $title,
					'bib' => $recordId,
					'success' => false,
					'message' => 'The circulation system is currently offline and we could not place this hold.  Please try again later.');
			}

		} else {
			if ($type == 'cancel' || $type == 'recall' || $type == 'update') {
				$result          = $this->updateHold($patron, $recordId, $type/*, $title*/);
				$result['title'] = $title;
				$result['bid']   = $recordId;
				return $result;

			} else {
				if (empty($pickupBranch)) {
					$pickupBranch = $patron->_homeLocationCode;
				}
				//create the hold using the web service
				$webServiceURL = $this->getWebServiceURL();

				$holdData = array(
					'patronBarcode' => $patron->getBarcode(),
					'pickupLibrary' => array(
						'resource' => '/policy/library',
						'key' => strtoupper($pickupBranch)
					),
				);

				if ($itemId) {
					$holdData['itemBarcode'] = $itemId;
					$holdData['holdType']    = 'COPY';
				} else {
					$shortRecordId        = str_replace('a', '', $recordId);
					$holdData['bib']      = array(
						'resource' => '/catalog/bib',
						'key' => $shortRecordId
					);
					$holdData['holdType'] = 'TITLE';
				}

				//TODO: Look into holds for different ranges (Group/Library)
				$holdData['holdRange'] = 'SYSTEM';

				if ($cancelIfNotFilledByDate) {
					$holdData['fillByDate'] = date('Y-m-d', strtotime($cancelIfNotFilledByDate));
				}
				//$holdRecord         = $this->getWebServiceResponse($webServiceURL . "/circulation/holdRecord/describe", null, $sessionToken);
				//$placeHold          = $this->getWebServiceResponse($webServiceURL . "/circulation/holdRecord/placeHold/describe", null, $sessionToken);
				$createHoldResponse = $this->getWebServiceResponse($webServiceURL . "/circulation/holdRecord/placeHold", $holdData, $sessionToken);

				$hold_result = array();
				if (isset($createHoldResponse->messageList)) {
					$hold_result['success'] = false;
					$hold_result['message'] = 'Your hold could not be placed. ';
					if (isset($createHoldResponse->messageList)) {
						$hold_result['message'] .= (string)$createHoldResponse->messageList[0]->message;
						global $logger;
						$errorMessage = 'Sirsi ROA Place Hold Error: ';
						foreach ($createHoldResponse->messageList as $error){
							$errorMessage .= $error->message.'; ';
						}
						$logger->log($errorMessage, Logger::LOG_ERROR);
					}
				} else {
					$hold_result['success'] = true;
					$hold_result['message'] = translate(['text'=>"ils_hold_success", 'defaultText'=>"Your hold was placed successfully."]);
				}

				$hold_result['title'] = $title;
				$hold_result['bid']   = $recordId;
				//Clear the patron profile
				return $hold_result;

			}
		}
	}


 private function getSessionToken($patron)
 {
	 $sirsiRoaUserId = $patron->username;

	 //Get the session token for the user
	 if (isset(SirsiDynixROA::$sessionIdsForUsers[$sirsiRoaUserId])) {
		 return SirsiDynixROA::$sessionIdsForUsers[$sirsiRoaUserId];
	 } else {
		 list(, $sessionToken) = $this->loginViaWebService($patron->cat_username, $patron->cat_password);
		 return $sessionToken;
	 }
 }

	function cancelHold($patron, $recordId, $cancelId = null)
	{
//		$sessionToken = $this->getStaffSessionToken();
		$sessionToken = $this->staffOrPatronSessionTokenSwitch() ? $this->getStaffSessionToken() : $this->getSessionToken($patron);
		if (!$sessionToken) {
			return array(
				'success' => false,
				'message' => 'Sorry, we could not connect to the circulation system.');
		}

		//create the hold using the web service
		$webServiceURL = $this->getWebServiceURL();

		$cancelHoldResponse = $this->getWebServiceResponse($webServiceURL . "/circulation/holdRecord/key/$cancelId", null, $sessionToken, 'DELETE');

		if (empty($cancelHoldResponse)) {
			return array(
				'success' => true,
			  'message' => 'The hold was successfully canceled'
			);
		} else {
			global $logger;
			$errorMessage = 'Sirsi ROA Cancel Hold Error: ';
			foreach ($cancelHoldResponse->messageList as $error){
				$errorMessage .= $error->message.'; ';
			}
			$logger->log($errorMessage, Logger::LOG_ERROR);

			return array(
				'success' => false,
				'message' => 'Sorry, the hold was not canceled');
		}

	}

	function changeHoldPickupLocation($patron, $recordId, $holdId, $newPickupLocation)
	{
		$sessionToken = $this->staffOrPatronSessionTokenSwitch() ? $this->getStaffSessionToken() : $this->getSessionToken($patron);
		if (!$sessionToken) {
			return array(
				'success' => false,
				'message' => 'Sorry, it does not look like you are logged in currently.  Please login and try again');
		}

		//create the hold using the web service
		$webServiceURL = $this->getWebServiceURL();

		$params = array(
			'key' => $holdId,
			'resource' => '/circulation/holdRecord',
			'fields' => array(
				'pickupLibrary' => array(
					'resource' => '/policy/library',
					'key' => strtoupper($newPickupLocation)
				),
			)
		);

		$updateHoldResponse = $this->getWebServiceResponse($webServiceURL . "/circulation/holdRecord/key/$holdId", $params, $sessionToken, 'PUT');
		if (isset($updateHoldResponse->key) && isset($updateHoldResponse->fields->pickupLibrary->key) && ($updateHoldResponse->fields->pickupLibrary->key == strtoupper($newPickupLocation))) {
			return array(
				'success' => true,
			  'message' => 'The pickup location has been updated.'
			);
		} else {
			$messages = array();
			if (isset($updateHoldResponse->messageList)) {
				foreach ($updateHoldResponse->messageList as $message) {
					$messages[] = $message->message;
				}
			}
			global $logger;
			$errorMessage = 'Sirsi ROA Change Hold Pickup Location Error: '. ($messages ? implode('; ', $messages) : '');
			$logger->log($errorMessage, Logger::LOG_ERROR);

			return array(
				'success' => false,
				'message' => 'Failed to update the pickup location : '. implode('; ', $messages)
			);
		}
	}

	function freezeHold($patron, $recordId, $holdToFreezeId, $dateToReactivate)
	{
		$sessionToken = $this->getStaffSessionToken();
		if (!$sessionToken) {
			return array(
				'success' => false,
				'message' => 'Sorry, it does not look like you are logged in currently.  Please login and try again');
		}

		//create the hold using the web service
		$webServiceURL = $this->getWebServiceURL();

		$today = date('Y-m-d');
		$formattedDateToReactivate = $dateToReactivate ? date('Y-m-d', strtotime($dateToReactivate)) : null;

		$params = array(
			'key' => $holdToFreezeId,
			'resource' => '/circulation/holdRecord',
			'fields' => array(
					'suspendBeginDate' => $today,
					'suspendEndDate' => $formattedDateToReactivate
			)
		);

		$updateHoldResponse = $this->getWebServiceResponse($webServiceURL . "/circulation/holdRecord/key/$holdToFreezeId", $params, $sessionToken, 'PUT');

		if (isset($updateHoldResponse->key) && isset($updateHoldResponse->fields->status) && $updateHoldResponse->fields->status == "SUSPENDED") {
			$frozen = translate('frozen');
			return array(
				'success' => true,
				'message' => "The hold has been $frozen."
			);
		} else {
			$messages = array();
			if (isset($updateHoldResponse->messageList)) {
				foreach ($updateHoldResponse->messageList as $message) {
					$messages[] = $message->message;
				}
			}
			$freeze = translate('freeze');

			global $logger;
			$errorMessage = 'Sirsi ROA Freeze Hold Error: '. ($messages ? implode('; ', $messages) : '');
			$logger->log($errorMessage, Logger::LOG_ERROR);

			return array(
				'success' => false,
				'message' => "Failed to $freeze hold : ". implode('; ', $messages)
			);
		}
	}

	function thawHold($patron, $recordId, $holdToThawId)
	{
		$sessionToken = $this->getStaffSessionToken();
		if (!$sessionToken) {
			return array(
				'success' => false,
				'message' => 'Sorry, it does not look like you are logged in currently.  Please login and try again');
		}

		//create the hold using the web service
		$webServiceURL = $this->getWebServiceURL();

		$params = array(
			'key' => $holdToThawId,
			'resource' => '/circulation/holdRecord',
			'fields' => array(
				'suspendBeginDate' => null,
				'suspendEndDate' => null
			)
		);

		$updateHoldResponse = $this->getWebServiceResponse($webServiceURL . "/circulation/holdRecord/key/$holdToThawId", $params, $sessionToken, 'PUT');

		if (isset($updateHoldResponse->key) && is_null($updateHoldResponse->fields->suspendEndDate)) {
			$thawed = translate('thawed');
			return array(
				'success' => true,
				'message' => "The hold has been $thawed."
			);
		} else {
			$messages = array();
			if (isset($updateHoldResponse->messageList)) {
				foreach ($updateHoldResponse->messageList as $message) {
					$messages[] = $message->message;
				}
			}
			global $logger;
			$errorMessage = 'Sirsi ROA Thaw Hold Error: '. ($messages ? implode('; ', $messages) : '');
			$logger->log($errorMessage, Logger::LOG_ERROR);

			$thaw = translate('thaw');
			return array(
				'success' => false,
				'message' => "Failed to $thaw hold : ". implode('; ', $messages)
			);
		}
	}

	/**
	 * @param User $patron
	 * @param string $recordId
	 * @param string $itemId
	 * @param string $itemIndex
	 * @return array
	 */
	public function renewCheckout($patron, $recordId, $itemId = null, $itemIndex = null)
	{
		$sessionToken = $this->staffOrPatronSessionTokenSwitch() ? $this->getStaffSessionToken() : $this->getSessionToken($patron);
		if (!$sessionToken) {
			return array(
				'success' => false,
				'message' => 'Sorry, it does not look like you are logged in currently.  Please login and try again');
		}

		//create the hold using the web service
		$webServiceURL = $this->getWebServiceURL();

		$params = array(
			'item' => array(
				'key' => $itemId,
				'resource' => '/catalog/item'
			)
		);

		$circRenewResponse  = $this->getWebServiceResponse($webServiceURL . "/circulation/circRecord/renew", $params, $sessionToken, 'POST');

		if (isset($circRenewResponse->circRecord->key)) {
			// Success

			return array(
				'itemId'  => $circRenewResponse->circRecord->key,
				'success' => true,
				'message' => "Your item was successfully renewed."
			);
		} else {
			// Error
			$messages = array();
			if (isset($circRenewResponse->messageList)) {
				foreach ($circRenewResponse->messageList as $message) {
					$messages[] = $message->message;
				}
			}
			global $logger;
			$errorMessage = 'Sirsi ROA Renew Error: '. ($messages ? implode('; ', $messages) : '');
			$logger->log($errorMessage, Logger::LOG_ERROR);

			return array(
				'itemId'  => $itemId,
				'success' =>false,
				'message' => "The item failed to renew". ($messages ? ': '. implode(';', $messages) : '')
			);

		}

	}

	/**
	 * @param User $patron
	 * @param $includeMessages
	 * @return array|AspenError
	 */
	public function getFines($patron, $includeMessages = false)
	{
		$fines = array();
		$sessionToken = $this->getSessionToken($patron);
		if ($sessionToken) {

			//create the hold using the web service
			$webServiceURL = $this->getWebServiceURL();

			$includeFields = urlencode("blockList{*,item{bib{title,author}}}");
			$blockList = $this->getWebServiceResponse($webServiceURL . '/user/patron/key/' . $patron->username . '?includeFields=' . $includeFields, null, $sessionToken);
			// Include Title data if available

			if (!empty($blockList->fields->blockList)) {
				foreach ($blockList->fields->blockList as $block) {
					$fine = $block->fields;
					$title = '';
					if (!empty($fine->item) && !empty($fine->item->key)) {
						$bibInfo  = $fine->item->fields->bib;
						$title = $bibInfo->fields->title;
						if (!empty($bibInfo->fields->author)) {
							$title .= '  by '.$bibInfo->fields->author;
						}

					}
					$fines[] = array(
						'reason' => $fine->block->key,
						'amount' => $fine->amount->amount,
						'message' => $title,
						'amountOutstanding' => $fine->owed->amount,
						'date' => $fine->billDate
					);
				}
			}
		}
		return $fines;
	}

	/**
	 * @param User $patron
	 * @param $oldPin
	 * @param $newPin
	 * @return array
	 */
	function updatePin($patron, $oldPin, $newPin)
	{
		$sessionToken = $this->getSessionToken($patron);
		if (!$sessionToken) {
			return ['success' => false, 'message' => 'Sorry, it does not look like you are logged in currently.  Please login and try again'];
		}

		$params = array(
			'currentPin' => $oldPin,
		    'newPin' => $newPin
		);

		$webServiceURL = $this->getWebServiceURL();

		$updatePinResponse = $this->getWebServiceResponse($webServiceURL . "/user/patron/changeMyPin", $params, $sessionToken, 'POST');
		if (!empty($updatePinResponse->patronKey) && $updatePinResponse->patronKey ==  $patron->username) {
			$patron->cat_password = $newPin;
			$patron->update();
			return ['success' => true, 'message' => "Your pin number was updated successfully."];

		} else {
			$messages = array();
			if (isset($updatePinResponse->messageList)) {
				foreach ($updatePinResponse->messageList as $message) {
					$messages[] = $message->message;
					if ($message->message == 'Public access users may not change this user\'s PIN') {
						$staffPinError = 'Staff can not change their PIN through the online catalog.';
					}
				}
				global $logger;
				$logger->log('Symphony ILS encountered errors updating patron pin : '. implode('; ', $messages), Logger::LOG_ERROR);
				if (!empty($staffPinError) ){
					return ['success' => false, 'message' => $staffPinError];
				} else {
					return ['success' => false, 'message' => 'The circulation system encountered errors attempt to update the pin.'];
				}
			}
			return ['success' => false, 'message' =>'Failed to update pin'];
		}
	}

    /**
     * @param User $user
     * @param string $newPin
     * @param string $resetToken
     * @return array
     */
	function resetPin($user, $newPin, $resetToken=null){
		if (empty($resetToken)) {
			global $logger;
			$logger->log('No Reset Token passed to resetPin function', Logger::LOG_ERROR);
			return array(
				'error' => 'Sorry, we could not update your pin. The reset token is missing. Please try again later'
			);
		}

		$changeMyPinAPIUrl = $this->getWebServiceUrl() . '/user/patron/changeMyPin';
		$jsonParameters = array(
			'resetPinToken' => $resetToken,
			'newPin' => $newPin,
		);
		$changeMyPinResponse = $this->getWebServiceResponse($changeMyPinAPIUrl, $jsonParameters, null, 'POST');
		if (is_object($changeMyPinResponse) &&  isset($changeMyPinResponse->messageList)) {
			$errors = array();
			foreach ($changeMyPinResponse->messageList as $message) {
				$errors[] = $message->message;
			}
			global $logger;
			$logger->log('SirsiDynixROA Driver error updating user\'s Pin :'. implode(';',$errors), Logger::LOG_ERROR);
			return array(
				'error' => 'Sorry, we encountered an error while attempting to update your pin. Please contact your local library.'
			);
		} elseif (!empty($changeMyPinResponse->sessionToken)){
			if ($user->username == $changeMyPinResponse->patronKey) { // Check that the ILS user matches the Aspen Discovery user
				$user->cat_password = $newPin;
				$user->update();
			}
			return array(
				'success' => true,
			);
//			return "Your pin number was updated successfully.";
		}else{
			return array(
				'error' => "Sorry, we could not update your pin number. Please try again later."
			);
		}
	}

	function getEmailResetPinResultsTemplate()
	{
		return 'emailResetPinResults.tpl';
	}

	function processEmailResetPinForm()
	{
		$barcode = $_REQUEST['barcode'];

		$patron = new User;
		$patron->get('cat_username', $barcode);
		if (!empty($patron->id)) {
			global $configArray;
			$aspenUserID = $patron->id;

			// If possible, check if ILS has an email address for the patron
			if (!empty($patron->cat_password)) {
				list($userValid, $sessionToken, $userID) = $this->loginViaWebService($barcode, $patron->cat_password);
				if ($userValid) {
					// Yay! We were able to login with the pin Aspen has!

					//Now check for an email address
					$lookupMyAccountInfoResponse = $this->getWebServiceResponse($this->getWebServiceURL() . '/user/patron/key/' . $userID . '?includeFields=preferredAddress,address1,address2,address3', null, $sessionToken);
					if ($lookupMyAccountInfoResponse) {
						if (isset($lookupMyAccountInfoResponse->fields->preferredAddress)){
							$preferredAddress = $lookupMyAccountInfoResponse->fields->preferredAddress;
							$addressField = 'address'. $preferredAddress;
							//TODO: Does Symphony's email reset pin use any email address; or just the one associated with the preferred Address
							if (!empty($lookupMyAccountInfoResponse->fields->$addressField)){
								$addressData = $lookupMyAccountInfoResponse->fields->$addressField;
								$email = '';
								foreach ($addressData as $field) {
									if ($field->fields->code->key == 'EMAIL') {
										$email = $field->fields->data;
										break;
									}
								}
								if (empty($email)) {
									// return an error message because Symphony doesn't have an email.
									return array(
										'success' => false,
										'error' => 'The circulation system does not have an email associated with this card number. Please contact your library to reset your pin.'
									);
								}
							}
						}
					}
				}
			}

			// email the pin to the user
			$resetPinAPIUrl = $this->getWebServiceUrl() . '/user/patron/resetMyPin';
			$jsonPOST       = array(
				'login' => $barcode,
				'resetPinUrl' => $configArray['Site']['url'] . '/MyAccount/ResetPin?resetToken=<RESET_PIN_TOKEN>&uid=' . $aspenUserID
			);

			$resetPinResponse = $this->getWebServiceResponse($resetPinAPIUrl, $jsonPOST, null, 'POST');
			if (is_object($resetPinResponse) && !isset($resetPinResponse->messageList)) {
				// Reset Pin Response is empty JSON on success.
				return array(
					'success' => true,
				);
			} else {
				$result = array(
					'success' => false,
					'error' => "Sorry, we could not email your pin to you.  Please visit the library to reset your pin."
				);
				if (isset($resetPinResponse->messageList)) {
					$errors = array();
					foreach ($resetPinResponse->messageList as $message) {
						$errors[] = $message->message;
					}
					global $logger;
					$logger->log('SirsiDynixROA Driver error updating user\'s Pin :' . implode(';', $errors), Logger::LOG_ERROR);
				}
				return $result;
			}

		} else {
			return array(
				'success' => false,
				'error' => 'Sorry, we did not find the card number you entered or you have not logged into the catalog previously.  Please contact your library to reset your pin.'
			);
		}
	}

	/**
	 * @param User $user
	 * @param bool $canUpdateContactInfo
	 * @return array
	 */
	function updatePatronInfo($user, $canUpdateContactInfo)
	{
		$result = [
			'success' => false,
			'messages' => []
		];
		if ($canUpdateContactInfo) {
			$sessionToken = $this->getSessionToken($user);
			if ($sessionToken) {
				$webServiceURL = $this->getWebServiceURL();
				if ($userID = $user->username) {
					$updatePatronInfoParameters = array(
						'fields' => array(),
                        'key' => $userID,
                        'resource' => '/user/patron',
					);
					if (!empty(self::$userPreferredAddresses[$userID])) {
						$preferredAddress = self::$userPreferredAddresses[$userID];
					} else {
						// TODO: Also set the preferred address in the $updatePatronInfoParameters
						$preferredAddress = 1;
					}

					// Build Address Field with existing data
					$index = 0;

					// Closure to handle the data structure of the address parameters to pass onto the ILS
					$setField = function ($key, $value) use (&$updatePatronInfoParameters, $preferredAddress, &$index) {
						static $parameterIndex = array();

						$addressField = 'address' . $preferredAddress;
						$patronAddressPolicyResource = '/policy/patron' . ucfirst($addressField);

						$l = array_key_exists($key, $parameterIndex) ? $parameterIndex[$key] : $index++;
						$updatePatronInfoParameters['fields'][$addressField][$l] = array(
							'resource' => '/user/patron/'. $addressField,
							'fields' => array(
								'code' => array(
									'key' => $key,
									'resource' => $patronAddressPolicyResource
								),
								'data' => $value
							)
						);
						$parameterIndex[$key] = $l;

					};

					if (!empty($user->email)) {
						$setField('EMAIL', $user->email);
					}

					if (!empty($user->address1)) {
						$setField('STREET', $user->_address1);
					}

					if (!empty($user->zip)) {
						$setField('ZIP', $user->_zip);
					}

					if (!empty($user->phone)) {
						$setField('PHONE', $user->phone);
					}

					if (!empty($user->_city) && !empty($user->_state)) {
						$setField('CITY/STATE', $user->_city .' '. $user->_state);
					}


					// Update Address Field with new data supplied by the user
					if (isset($_REQUEST['email'])) {
						$setField('EMAIL', $_REQUEST['email']);
					}

					if (isset($_REQUEST['phone'])) {
						$setField('PHONE',$_REQUEST['phone']);
					}

					if (isset($_REQUEST['address1'])) {
						$setField('STREET',$_REQUEST['address1']);
					}

					if (isset($_REQUEST['city']) && isset($_REQUEST['state'])) {
						$setField('CITY/STATE',$_REQUEST['city'] . ' ' . $_REQUEST['state']);
					}

					if (isset($_REQUEST['zip'])) {
						$setField('ZIP',$_REQUEST['zip']);
					}

					// Update Home Location
					if (!empty($_REQUEST['pickupLocation'])) {
						$homeLibraryLocation = new Location();
						if ($homeLibraryLocation->get('code', $_REQUEST['pickupLocation'])) {
							$homeBranchCode = strtoupper($homeLibraryLocation->code);
							$updatePatronInfoParameters['fields']['library'] = array(
								'key' => $homeBranchCode,
								'resource' => '/policy/library'
							);
						}
					}

					$updateAccountInfoResponse = $this->getWebServiceResponse($webServiceURL . '/user/patron/key/' . $userID, $updatePatronInfoParameters, $sessionToken, 'PUT');

					if (isset($updateAccountInfoResponse->messageList)) {
						foreach ($updateAccountInfoResponse->messageList as $message) {
							$result['messages'][] = $message->message;
						}
						global $logger;
						$logger->log('Symphony Driver - Patron Info Update Error - Error from ILS : '.implode(';', $result['messages']), Logger::LOG_ERROR);
					}else{
						$result['success'] = true;
						$result['messages'][] = 'Your account was updated successfully.';
					}
				} else {
					global $logger;
					$logger->log('Symphony Driver - Patron Info Update Error: Catalog does not have the circulation system User Id', Logger::LOG_ERROR);
					$result['messages'][] = 'Catalog does not have the circulation system User Id';
				}
			} else {
				$result['messages'][] = 'Sorry, it does not look like you are logged in currently.  Please login and try again';
			}
		} else {
			$result['messages'][] = 'You do not have permission to update profile information.';
		}
		return $result;
	}

    public function showOutstandingFines()
    {
        return true;
    }

	function getForgotPasswordType()
	{
		return 'emailResetLink';
	}

	function getEmailResetPinTemplate()
	{
		return 'sirsiROAEmailResetPinLink.tpl';
	}

	function translateFineMessageType($code){
		switch ($code){

			default:
				return $code;
		}
	}

	public function translateLocation($locationCode){
		$locationCode = strtoupper($locationCode);
		$locationMap = array(

		);
		return isset($locationMap[$locationCode]) ? $locationMap[$locationCode] : "Unknown" ;
	}
	public function translateCollection($collectionCode){
		$collectionCode = strtoupper($collectionCode);
		$collectionMap = array(

		);
		return isset($collectionMap[$collectionCode]) ? $collectionMap[$collectionCode] : "Unknown $collectionCode";
	}
	public function translateStatus($statusCode){
		$statusCode = strtolower($statusCode);
		$statusMap = array(

		);
		return isset($statusMap[$statusCode]) ? $statusMap[$statusCode] : 'Unknown (' . $statusCode . ')';
	}
}
