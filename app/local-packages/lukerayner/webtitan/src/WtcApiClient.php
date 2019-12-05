<?php 
namespace WtcApiClient;


/**
*  A sample class
*
*  Use this section to define what this class is doing, the PHPDocumentator will use this
*  to automatically generate an API documentation using this information.
*
*  @author yourname
*/
include_once __DIR__ . "/oauth-php/library/OAuthStore.php";
include_once __DIR__ . "/oauth-php/library/OAuthRequester.php";

class WtcApiClient {

    private $url;
    private $consumer_key;
    private $consumer_secret;
    private $oauth_token;
    private $oauth_token_secret;
    private $signature_methods;
    private $request_token_uri;
    private $authorize_uri;
    private $access_token_uri;

    
    public function __construct() {}
    
    public function loadAdminCredentials()
    {
        $result = $this->getOAuthCredentials();
        $this->setOAuthCredentials($result['wtc_url'], $result['consumer_key'], $result['consumer_secret'], $result['access_token'], $result['access_token_secret']);
    } 
    
    public function setOAuthCredentials($wtc_url, $consumer_key, $consumer_secret, $oauth_token, $oauth_token_secret)
    {
        $this->url = $wtc_url;
        $this->consumer_key = $consumer_key;
        $this->consumer_secret = $consumer_secret;
        $this->oauth_token = $oauth_token;
        $this->oauth_token_secret = $oauth_token_secret;
        $this->signature_methods = array('HMAC-SHA1');
        $this->request_token_uri = $wtc_url . 'auth/request_token';
        $this->authorize_uri = $wtc_url . 'auth/authorize';
        $this->access_token_uri = $wtc_url . 'auth/access_token';
    }

    public function useOAuthCredentials()
    {
        $this->url = 'https://35.176.129.240:8443/';
        $this->consumer_key = 'e22ed431cd60dda4359793dd2ed679f4';
        $this->consumer_secret = '64d1718bdb5d14ce5380b7c6d7f8947c';
        $this->oauth_token = '73f1219beb6529ec3d4b6e2311db66c8';
        $this->oauth_token_secret = '4331810bb0f32bc787ef2d55e110065f';
        $this->signature_methods = array('HMAC-SHA1');
        $this->request_token_uri = $this->url . 'auth/request_token';
        $this->authorize_uri = $this->url . 'auth/authorize';
        $this->access_token_uri = $this->url . 'auth/access_token';
    }
    
    public function getOAuthCredentials() 
    {
  return array(
      'consumer_key' => $this->consumer_key,
      'consumer_secret' => $this->consumer_secret,
      'oauth_token' => $this->oauth_token,
      'oauth_token_secret' => $this->oauth_token_secret,
      'signature_methods' => $this->signature_methods,
      'request_token_uri' => $this->request_token_uri,
      'authorize_uri' => $this->authorize_uri,
      'access_token_uri' => $this->access_token_uri);
    }
    
    public function makeApiCall($options, $method)
    {
  $oauthcredentials = $this->getOAuthCredentials();
  $options = array_merge($oauthcredentials, $options);
  \OAuthStore::instance("Session", $options);
  \OAuthStore::instance()->addServerToken($this->consumer_key, 'request', $this->oauth_token, $this->oauth_token_secret, 1, array(CURLOPT_HTTPHEADER => 'Content-Type: application/x-www-form-urlencoded'));
  
  $req = new \OAuthRequester($options['server_uri'], $method, $options);
  $result = $req->doRequest(0);

      return $result['body'];
    }

    // WTCApiClient - Users
    // Users - Create
    public function registerExistingnCustomerForOAuth($wtc_customer_id)
    {
  $method = 'POST';
  $options = array(
      'server_uri' => $this->url.'/restapi/auth/adminregister/',
      'id' => $wtc_customer_id
  );
  
  return $this->makeApiCall($options, $method);
    }
    
    public function createNewCustomerAccount($accountname, $email, $description, $license, $password, $timezone) {

  $method = 'POST';
  $options = array(
      'server_uri' => $this->url.'/restapi/users/',
      'accountname' => $accountname,
      'email' => $email,
      'description' => $description,
      'license' => $license,
      'password' => $password,
      'timezone' => $timezone
  );
  
  return $this->makeApiCall($options, $method);
  
    }
    
    // Users Read
    public function getCustomerAccount($wtc_customer_id)
    {
  $method = 'GET';
  $options = array(
      'server_uri' => $this->url.'/restapi/users/'.$wtc_customer_id,
  );
  
  return $this->makeApiCall($options, $method);
    }
    
    public function listCustomerAccounts($offset = 0, $limit = 'all')
    {
  $method = 'GET';
  $options = array(
      'server_uri' => $this->url.'/restapi/users/',
      'offset' => $offset,
      'limit' => $limit
  );
  
  return $this->makeApiCall($options, $method);
    }
    
    // Users Update
    public function updateCustomerAccount($wtc_customer_id, $accountname, $email, $description, $license, $password, $timezone)
    {
  $method = 'POST';
  $options = array(
      'server_uri' => $this->url.'/restapi/users/'.$wtc_customer_id,
      'accountname' => $accountname,
      'email' => $email,
      'description' => $description,
      'license' => $license,
      'password' => $password,
      'timezone' => $timezone
  );
  
  return $this->makeApiCall($options, $method);
    }
    
    // Users Delete
    public function deleteCustomerAccount($wtc_customer_id)
    {
  $method = 'DELETE';
  $options = array(
      'server_uri' => $this->url.'/restapi/users/'.$wtc_customer_id,
  );
  
  return $this->makeApiCall($options, $method);
    }

    // WTCApiClient - Locations
    // Locations - Create
    public function createNewLocation($wtc_customer_id, $type, $ip, $name, $hostname = 'none')
    {
  //http://api.webtitan.com/#api-Locations-CreateLocation
  
  $method = 'POST';
  $options = array(
      'server_uri' => $this->url.'/restapi/users/'.$wtc_customer_id.'/locations/'.$type,
      'ip' => $ip,
      'name' => $name,
      'hostname' => $hostname
  );
  
  return $this->makeApiCall($options, $method);
    }
    
    // Locations - Read
    public function getLocation($wtc_customer_id, $type, $location_id)
    {
  //http://api.webtitan.com/#api-Locations-GetLocation
  $method = 'GET';
  $options = array(
      'server_uri' => $this->url.'/restapi/users/' . $wtc_customer_id . '/locations/' . $type . '/'. $location_id
  );
  
  return $this->makeApiCall($options, $method);
    }
    
    public function listCustomerLocations($wtc_customer_id, $type, $offset = '0', $limit = 'all')
    {
  //http://api.webtitan.com/#api-Locations-GetLocations
  $method = 'GET';
  $options = array(
      'server_uri' => $this->url.'/restapi/users/'.$wtc_customer_id.'/locations/'.$type,
      'offset' => $offset,
      'limit' => $limit
  );
  
  return $this->makeApiCall($options, $method);
    }
    
    // Locations - Updae
    public function updateLocation($wtc_customer_id, $type, $locationid, $name = 'optional', $ip = 'optional', $hostname = 'optional')
    {
  //http://api.webtitan.com/#api-Locations-UpdateLocation
  $method = 'POST';
  $options = array(
      'server_uri' => $this->url.'/restapi/users/'.$wtc_customer_id.'/locations/'.$type.'/'.$locationid,
      'ip' => $ip,
      'name' => $name,
      'hostname' => $hostname
  );
  
  return $this->makeApiCall($options, $method);
    }
    
    // Locations - Delete
    public function deleteLocation($wtc_customer_id, $type, $location_id)
    {
  //http://api.webtitan.com/#api-Locations-DeleteLocation
  $method = 'DELETE';
  $options = array(
      'server_uri' => $this->url.'/restapi/users/'.$wtc_customer_id.'/locations/'.$type.'/'.$location_id,
  );
  
  return $this->makeApiCall($options, $method);
    }
    
    // WTCApiClient - Policies
    // Policies - Create
    
    // Policies - Read
    // CUSTOMER DEFAULT POLICY
    public function getCustomerDefaultPolicy($wtc_customer_id)
    {
  //http://api.webtitan.com/#api-Customer_Default_Policy-GetRestapiUsersIdPoliciesDefault
  
  $method = 'GET';
  $options = array(
      'server_uri' => $this->url.'/restapi/users/'.$wtc_customer_id.'/policies/default'
  );
  
  return $this->makeApiCall($options, $method);
    }
    
    // CATEGORIES
    public function getSpecificCategorySettingsDefaultPolicy($wtc_customer_id, $category_id)
    {
  //http://api.webtitan.com/#api-Categories-GetRestapiUsersIdPoliciesDefaultCategoriesCategoryid
  $method = 'GET';
  $options = array(
      'server_uri' => $this->url .'/restapi/users/'. $wtc_customer_id .'/policies/default/categories/'. $category_id
  );
  
  return $this->makeApiCall($options, $method);
    }
    
    // CATEGORIES
    public function listAllCustomersCategorySettingsDefaultPolicy($wtc_customer_id, $offset = '0', $limit = 'all')
    {
  //http://api.webtitan.com/#api-Categories-GetRestapiUsersIdPoliciesDefaultCategories
  $method = 'GET';
  $options = array(
      'server_uri' => $this->url .'/restapi/users/'. $wtc_customer_id .'/policies/default/categories',
      //'offset' => $offset,
      //'limit' => $limit
  );
  
  return $this->makeApiCall($options, $method);
    }
    
    // Poliices - Update
    // CUSTOMER DEFAULT POLICY
    public function updateCustomerDefaultPolicy($wtc_customer_id, $emailnotifications = 'none', $allowunclassified = true, $safesearch = 'off')
    {
  //http://api.webtitan.com/#api-Customer_Default_Policy-PostRestapiUsersIdPoliciesDefault
  $method = 'POST';
  $options = array(
      'server_uri' => $this->url .'/restapi/users/'. $wtc_customer_id .'/policies/default',
      'emailnotifications' => $emailnotifications,
      'allowunclassified' => $allowunclassified,
      'safesearch' => $safesearch
  );
  
  return $this->makeApiCall($options, $method);
    }
    
    // CATEGORIES
    public function updateSpecificCategoryCustomerDefaultPolicy($wtc_customer_id, $category_id, $allowed = true, $notify = false )
    {
  //http://api.webtitan.com/#api-Categories-PostRestapiUsersIdPoliciesDefaultCategoriesCategoryid
  $method = 'POST';
  $options = array(
      'server_uri' => $this->url .'/restapi/users/'. $wtc_customer_id .'/policies/default/categories/' . $category_id,
      'allowed' => $allowed,
      'notify' => $notify
  );
  
  return $this->makeApiCall($options, $method);
    }
    
    // CATEGORIES
    public function updateAllCategorySettingsCustomerDefaultPolicy($wtc_customer_id, $allowed = true, $notify = false )
    {
  //http://api.webtitan.com/#api-Categories-PostRestapiUsersIdPoliciesDefaultCategories
  $method = 'POST';
  $options = array(
      'server_uri' => $this->url .'/restapi/users/'. $wtc_customer_id .'/policies/default/categories',
      'allowed' => $allowed,
      'notify' => $notify
  );
  
  return $this->makeApiCall($options, $method);
    }
    
    // Policies - Delete
    //TODO:  Policies Delete prototypes and implementation
    
    // WTCApiClient - Policies - WhiteList
    // Whitelist - Create
    public function createNewWhitelistEntryForCustomer($wtc_customer_id, $domain, $subdomains = true, $comment = 'none')
    {
  //http://api.webtitan.com/#api-Whitelist-PostRestapiUsersIdWhitelist
  $method = 'POST';
  $options = array(
      'server_uri' => $this->url .'/restapi/users/'. $wtc_customer_id .'/whitelist',
      'domain' => $domain,
      'subdomains' => $subdomains,
      'comment' => $comment
  );
  
  return $this->makeApiCall($options, $method);
    }
    
    // Whitelist - Read
    public function getCustomerWhitelistEntry($wtc_customer_id, $whitelist_id)
    {
  //http://api.webtitan.com/#api-Whitelist-GetRestapiUsersIdWhitelistWhitelistid
  $method = 'GET';
  $options = array(
      'server_uri' => $this->url .'/restapi/users/'. $wtc_customer_id .'/whitelist/'.$whitelist_id,
  );
  
  return $this->makeApiCall($options, $method);
    }
    
    public function listCustomerWhitelist($wtc_customer_id, $offset = 0, $limit = 'all')
    {
  //http://api.webtitan.com/#api-Whitelist-GetRestapiUsersIdWhitelist
  $method = 'GET';
  $options = array(
      'server_uri' => $this->url .'/restapi/users/'. $wtc_customer_id .'/whitelist',
      'offset' => $offset,
      'limit' => $limit
  );
  
  return $this->makeApiCall($options, $method);
    }
    
    // Whitelist - Update
    public function updateWhitelistEntry($wtc_customer_id, $whitelist_id, $domain, $subdomains = true, $comment ='none')
    {
  //http://api.webtitan.com/#api-Whitelist-PostRestapiUsersIdWhitelistWhitelistid
  $method = 'POST';
  $options = array(
      'server_uri' => $this->url .'/restapi/users/'. $wtc_customer_id .'/whitelist/' . $whitelist_id,
      'domain' => $domain,
      'subdomains' => $subdomains,
      'comment' => $comment
  );
  
  return $this->makeApiCall($options, $method);
    }
    
    // Whitelist - Delete
    public function deleteWhitelistEntry($wtc_customer_id, $whitelistid)
    {
  //http://api.webtitan.com/#api-Whitelist-DeleteRestapiUsersIdWhitelistWhitelistid
  $method = 'DELETE';
  $options = array(
      'server_uri' => $this->url .'/restapi/users/'. $wtc_customer_id .'/whitelist' . $whitelistid,
  );
  
  return $this->makeApiCall($options, $method);
    }
    
    // WTCApiClient - Policies - WhiteList
    // Blacklist - Create
    public function createNewBlacklistEntryForCustomer($wtc_customer_id, $domain, $subdomains = true, $comment = 'none')
    {
  //http://api.webtitan.com/#api-Blacklist-PostRestapiUsersIdBlacklist
  $method = 'POST';
  $options = array(
      'server_uri' => $this->url .'/restapi/users/'. $wtc_customer_id .'/blacklist/',
      'domain' => $domain,
      'subdomains' => $subdomains,
      'comment' => $comment
  );
  
  return $this->makeApiCall($options, $method);
    }
    
    // Blacklist - Read
    public function getCustomerBlacklistEntry($wtc_customer_id, $blacklist_id)
    {
  //http://api.webtitan.com/#api-Blacklist-GetRestapiUsersIdBlacklistBlacklistid
  $method = 'GET';
  $options = array(
      'server_uri' => $this->url .'/restapi/users/'. $wtc_customer_id .'/blacklist/' . $blacklist_id,
  );
  
  return $this->makeApiCall($options, $method);
    }
    
    public function listCustomerBlacklist($wtc_customer_id, $offset = 0, $limit = 'all')
    {
  //http://api.webtitan.com/#api-Blacklist-GetRestapiUsersIdBlacklist
  $method = 'GET';
  $options = array(
      'server_uri' => $this->url .'/restapi/users/'. $wtc_customer_id .'/blacklist',
      'offset' => $offset,
      'limit' => $limit
  );
  
  return $this->makeApiCall($options, $method);
    }
    
    // Blacklist - Update
    public function updateBlacklistEntry($wtc_customer_id, $blacklist_id, $domain, $subdomains = true, $comment ='none')
    {
  //http://api.webtitan.com/#api-Blacklist-PostRestapiUsersIdBlacklistBlacklistid
  
  $method = 'POST';
  $options = array(
      'server_uri' => $this->url . '/restapi/users/' . $wtc_customer_id .'/blacklist/' . $blacklist_id,
      'domain' => $domain,
      'subdomains' => $subdomains,
      'comment' => $comment
  );
  
  return $this->makeApiCall($options, $method);
    }
    
    // Blacklist - Delete
    public function deleteBlacklistEntry($wtc_customer_id, $blacklist_id)
    {
  //TODO:  WtcApiClient deleteBlacklistEntry Implement
  $method = 'DELETE';
  $options = array(
      'server_uri' => $this->url . '/restapi/users/' . $wtc_customer_id .'/blacklist/' . $blacklist_id,
  );

  return $this->makeApiCall($options, $method);
    }

  // Get System Top 10 Domains
    public function getTop10SiteDomains($user_id, $type, $date)
    {
	    $method = 'GET';
	    $options = array(
	        'server_uri' => $this->url . '/restapi/users/' . $user_id . '/stats/domains/' . $type . '/' . $date,
	    );
	    
	    return $this->makeApiCall($options, $method);
    }

    // Get System Top 10 Categories
    public function getTop10SiteCategories($user_id, $type, $date)
    {
	    $method = 'GET';
	    $options = array(
	        'server_uri' => $this->url . '/restapi/users/' . $user_id . '/stats/categories/' . $type . '/' . $date,
    );
    
    	return $this->makeApiCall($options, $method);
    }
}