<?php

namespace UserFrosting\Sprinkle\ElephantWifi\Controller;

use Carbon\Carbon;
use DateTimeZone;
use DeviceDetector\DeviceDetector;
use DeviceDetector\Parser\Device\DeviceParserAbstract;
use DeviceDetector\Cache\Cache;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use UserFrosting\Fortress\RequestDataTransformer;
use UserFrosting\Fortress\RequestSchema;
use UserFrosting\Fortress\ServerSideValidator;
use UserFrosting\Fortress\Adapter\JqueryValidationAdapter;
use UserFrosting\Sprinkle\Account\Controller\Exception\SpammyRequestException;
use UserFrosting\Sprinkle\Account\Facades\Password;
use UserFrosting\Sprinkle\Account\Util\Util as AccountUtil;
use UserFrosting\Sprinkle\Core\Controller\SimpleController;
use UserFrosting\Sprinkle\Core\Mail\EmailRecipient;
use UserFrosting\Sprinkle\Core\Mail\TwigMailMessage;
use UserFrosting\Sprinkle\Core\Util\Captcha;
use UserFrosting\Support\Exception\BadRequestException;
use UserFrosting\Support\Exception\ForbiddenException;
use UserFrosting\Support\Exception\NotFoundException;
use SendinBlue\Client\Configuration as SendinBlueConfiguration;
use SendinBlue\Client\Api\AccountApi as SendinBlueAccountApi;
use SendinBlue\Client\Api\ContactsApi as SendinBlueContactsApi;
use SendinBlue\Client\Model\CreateContact as SendinBlueCreateContact;
use \DrewM\MailChimp\MailChimp;

use Facebook\Facebook;
use Abraham\TwitterOAuth\TwitterOAuth;
use MetzWeb\Instagram\Instagram;

use Jabranr\PostcodesIO\PostcodesIO;

use UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\MacVendor;

use UserFrosting\Sprinkle\GeoSense\Database\Models\VisitorProfile;

use UserFrosting\Sprinkle\ElephantWifi\Database\Models\Session;
use UserFrosting\Sprinkle\ElephantWifi\Database\Models\Device;
use UserFrosting\Sprinkle\ElephantWifi\Database\Models\MarketingList;
use UserFrosting\Sprinkle\ElephantWifi\Database\Models\MarketingListType;
use UserFrosting\Sprinkle\ElephantWifi\Database\Models\Identity;
use UserFrosting\Sprinkle\ElephantWifi\Database\Models\SocialKeys;
use UserFrosting\Sprinkle\ElephantWifi\Database\Models\WifiDailyStatsVenueEmailStatuses;

/**
 * CaptivePortalController Class
 *
 * @package IntelliSense
 * @author Luke Rayner/ElephantWiFi
 * @link http://www.elephantwifi.co.uk
 */
class CaptivePortalController extends SimpleController 
{
    public function previewSplash(Request $request, Response $response, $args)
    {
        /** @var \UserFrosting\Support\Repository\Repository $config */
        $config = $this->ci->config;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * get current venue and it's locale and more..
         */
        $venueQuery = new Venue;
        $venue = $venueQuery->with('venue_wifi.text_labels', 'venue_wifi.free_access_settings', 'venue_wifi.custom_css')
            ->where('id', $currentUser->primary_venue_id)->first();
        $locale = $venue->locale;

        /**
         * Device/user requires registration
         * - determine template based on value of "primary_method" in settings
         * - also check whether the client has requested registration as a fall-back option
         * - we currently support:
         *   - registration_form
         *   - social_auth
         */
        switch ($venue->venue_wifi->free_access_settings['primary_method']) {
            case 'basic':
                // Load validation rules
                $schema = new RequestSchema('schema://requests/basic-registration.yaml');
                $validator = new JqueryValidationAdapter($schema, $this->ci->translator);

                return $this->ci->view->render($response, 'pages/elephantwifi/captive_portal/splash-page-basic.html.twig', [
                    'validators' => $validator->rules('json', true),
                    'portal_session' => session_id(),
                    'unifi_venue' => $venue
                ]);

                break;
            case 'registration_form':
                // Load validation rules
                $schema = new RequestSchema('schema://requests/identity-registration.yaml');

                /**
                 * here we call the private function to dynamically extend the validation rules before loading the final schema
                 */
                $schema = $this->extendIdentityValidationRules($schema, $venue);

                $validator = new JqueryValidationAdapter($schema, $this->ci->translator);

                return $this->ci->view->render($response, 'pages/elephantwifi/captive_portal/splash-page-register.html.twig', [
                    'validators' => $validator->rules('json', true),
                    'portal_session' => session_id(),
                    'unifi_venue' => $venue
                ]);

                break;
            case 'social_auth':
                // Load validation rules
                $schema = new RequestSchema('schema://requests/identity-registration.yaml');
                $validator = new JqueryValidationAdapter($schema, $this->ci->translator);

                return $this->ci->view->render($response, 'pages/elephantwifi/captive_portal/splash-page-social_auth.html.twig', [
                    'validators' => $validator->rules('json', true),
                    'portal_session' => session_id(),
                    'unifi_venue' => $venue
                ]);

                break;
        }
    }

    public function initSession(Request $request, Response $response, $args)
    {
        $local_venue_id = $args['local_venue_id'];
        $mac_address = $args['mac_address'];
        $ap_mac_address = $args['ap_mac_address'];

        // Get the alert message stream
        $ms = $this->ci->alerts;

        $local_venue_id = $args['local_venue_id'];
        $mac_address = $args['mac_address'];
        $ap_mac_address = $args['ap_mac_address'];

        /**
         * place the captured data in an array for further processing
         * NOTE: for now we don't require the AP mac address
         * TODO:
         *  - get the (optional) orig_url and ssid from the referrer url
         *  - force a fresh session id?
         */
        session_regenerate_id(true);
        $data_raw = array('mac_address' => $mac_address,'php_session_id' => session_id(), 'local_venue_id' => $local_venue_id, 'ap_mac_address' => $ap_mac_address);

        //Load the request validation schema
        $schema = new RequestSchema('schema://requests/session-create.yaml');

        // Whitelist and set parameter defaults
        $transformer = new RequestDataTransformer($schema);
        $data = $transformer->transform($data_raw);

        // Validate, and halt on validation errors.  Failed validation attempts do not count towards throttling limit.
        $validator = new ServerSideValidator($schema, $this->ci->translator);
        if (!$validator->validate($data)) {
            $ms->addValidationErrors($validator);

            return $response->withJson([], 400);
        }

        /**
         * find the associated venue details and it's locale
         */
        $venue = Venue::with('venue_wifi.controller', 'venue_wifi.text_labels', 'venue_wifi.free_access_settings', 'venue_wifi.custom_css')
        ->whereHas('venue_wifi', function ($query) use ($data) {
            $query->where('local_venue_id', $data['local_venue_id']);
        })->first();

        $locale = $venue->locale;

        /**
         * detect device attributes using the HTTP User Agent
         * - if any attribute is undetected we use the full HTTP user agent
         */
        $dd = new DeviceDetector($_SERVER['HTTP_USER_AGENT']);
        //$dd->setCache(new Doctrine\Common\Cache\PhpFileCache('./tmp/'));
        $dd->skipBotDetection();
        $dd->parse();
        $clientInfo = $dd->getClient();
        $osInfo = $dd->getOs();

        if(!isset($osInfo['name']) || !isset($osInfo['version'])) {
            $osInfo['name'] = $_SERVER['HTTP_USER_AGENT'];
            $osInfo['version'] = 'unknown';
        }

        if(!isset($clientInfo['name']) || !isset($clientInfo['version'])) {
            $clientInfo['name'] = $_SERVER['HTTP_USER_AGENT'];
            $clientInfo['version'] = 'unknown';
        }

        /**
         * store the session data
         * NOTE: firstOrCreate saves us from checking whether a record already exists with the current values
         * DATA TO STORE:
         * device information:
         *   - mac_address
         *   - os
         *   - browser (will be the latest used browser if device exists)
         *   - brand
         * if new:
         *   - first_seen & last_seen both now()
         * if existing:
         *   - last_seen
         * session data:
         *   - php_session_id
         *   - venue_id
         *   - device_id
         *   - browser
         */
        try {
            /**
             * we try to store session and device data
             */
            $session_store = Session::firstOrNew(['php_session_id' => $data['php_session_id'], 'venue_id' => $venue->id, 'ap_mac_address' => $data['ap_mac_address']]);
            $device_store = Device::firstOrNew(['mac_address' => $data['mac_address'], 'venue_id' => $venue->id, 'device_uuid' => hex2bin(md5($data['mac_address'] . $venue->id))]);
            $device_store->os = $osInfo['name'] . ' ' . $osInfo['version'];
            $device_store->browser = $clientInfo['name'] . ' ' . $clientInfo['version'];
            $device_store->last_seen = time();

            error_log(print_r($device_store, true));

            /**
             * check whether this is the first time we see this device at this venue
             * - if first time we need to get the OUI based on the MAC address and set the first seen time
             */
            if(!$device_store->first_seen) {
                $device_store->first_seen = time();

                /**
                 * get the vendor using the device's mac address
                */
                $macvendor = MacVendor::where('macaddr', substr($data['mac_address'], 0, 8))->first();
                if($macvendor) {
                    $brand = $macvendor->vendor;
                } else {
                    $brand = 'unknown';
                }

                $device_store->brand = $brand;
            }

            // Save the device
            $device_store->save();

            $session_store->device_id = $device_store->id;
            $session_store->ap_mac_address = $data['ap_mac_address'];
            $session_store->browser = $clientInfo['name'] . ' ' . $clientInfo['version'];

            /**
             * save the updated data when done
             */
            $session_store->save();
        } catch (Exception $e) {
            /**
            * in case something goes wrong
             */
            error_log('caught exception in function initSession: ' . $e->getMessage());
            return $response->withJson([], 400);
        }

        return $this->ci->view->render($response, 'pages/elephantwifi/captive_portal/splash-page-init.html.twig', [
            'portal_session' => session_id(),
            'unifi_venue' => $venue
        ]);
    }

    public function displayIntro(Request $request, Response $response, $args)
    {
        $session_id = $args['session_id'];
        $limited_browser = $args['limited_browser'];

        /**
         * get current session details
         */
        if(session_id() == $session_id) {
            /**
             * we have a match
             */
            $session = Session::where('php_session_id', session_id())->first();
        } else {
            /**
             * we have a mismatch but this could be caused by a browser switch client-side (IOS...)
             * - just deal with it and update the value for php_session_id with the current PHP session ID
             * - carry on...
             */
            $session = Session::where('php_session_id', $session_id)->first();
            if (isset($session)) {
                $session->php_session_id = session_id();
                $session->save();
            } else {
                error_log('we could not find the session_id ' . $session_id . ' in displaySplash');
                return $response->withJson([], 400);
            }
        }

        if($limited_browser == 1) {
            error_log('we have detected a browser with limited capabilities in displaySplash');
            /**
             * store this information with the session
             */
            $session->limited_cna = $limited_browser;
            $session->save();
        }

        /**
         * get current venue and it's locale and more..
         * NOTE: Venue::find()->with does not work...
         */
        $venue = Venue::where('id', $session->venue_id)->with('venue_wifi.controller', 'venue_wifi.text_labels', 'venue_wifi.free_access_settings', 'venue_wifi.custom_css')->first();

        /**
         * get current device
         */
        $device = Device::find($session->device->id);

        /**
         * Get all the marketing types for this venue
         */
        $marketing_options = MarketingList::where('venue_id', $venue->id)->get();
        $type_ids = [];
        foreach($marketing_options as $marketing_option) {
            array_push($type_ids, $marketing_option->marketing_list_type_id);
        }
        
        /**
         * Push all marketing list type and the no marketing list type to the list
         */
        array_push($type_ids, 1000);
        array_push($type_ids, 1001);

        $marketing_types = MarketingListType::whereIn('id', $type_ids)->get();

        // Load validation rules
        $schema = new RequestSchema('schema://requests/captive-portal-email-checker.yaml');
        $validator = new JqueryValidationAdapter($schema, $this->ci->translator);

        if ($device->registration_expiry_date < time()) {
            return $this->ci->view->render($response, 'pages/elephantwifi/captive_portal/splash-page-intro.html.twig', [
                'validators' => $validator->rules('json', true),
                'portal_session' => session_id(),
                'unifi_venue' => $venue,
                'marketing_types' => $marketing_types,
                'purpose' => 'all'
            ]);
        }
        else if ($device->reshow_marketing < time() && $device->reshow_marketing != 0) {
            return $this->ci->view->render($response, 'pages/elephantwifi/captive_portal/splash-page-intro.html.twig', [
                'validators' => $validator->rules('json', true),
                'portal_session' => session_id(),
                'unifi_venue' => $venue,
                'marketing_types' => $marketing_types,
                'purpose' => 'marketing'
            ]);
        } 
        else if ($device->reshow_terms == 1) {
            return $this->ci->view->render($response, 'pages/elephantwifi/captive_portal/splash-page-intro.html.twig', [
                'validators' => $validator->rules('json', true),
                'portal_session' => session_id(),
                'unifi_venue' => $venue,
                'marketing_types' => $marketing_types,
                'purpose' => 'terms'
            ]);
        } else {
            /**
             * Device/user does NOT (yet) require re-registration
             */
            return $this->ci->view->render($response, 'pages/elephantwifi/captive_portal/splash-page-motd.html.twig', [
                'portal_session' => session_id(),
                'unifi_venue' => $venue
            ]);
        }
    }

    public function displaySplash(Request $request, Response $response, $args)
    {
        $session_id = $args['session_id'];

        /**
         * get current session details
         */
        if(session_id() == $session_id) {
            /**
             * we have a match
             */
            $session = Session::where('php_session_id', session_id())->first();
        } else {
            /**
             * we have a mismatch but this could be caused by a browser switch client-side (IOS...)
             * - just deal with it and update the value for php_session_id with the current PHP session ID
             * - carry on...
             */
            error_log('we have a session_id mismatch in displaySplash: ' . session_id() . " versus: " . $session_id);
            $session = Session::where('php_session_id', $session_id)->first();
            if (isset($session)) {
                $session->php_session_id = session_id();
                $session->save();
            } else {
                error_log('we could not find the session_id ' . $session_id . ' in displaySplash');
                return $response->withJson([], 400);
            }
        }

        /**
         * get current venue and it's locale and more..
         * NOTE: Venue::find()->with does not work...
         */
        $venue = Venue::where('id', $session->venue_id)->with('venue_wifi.controller', 'venue_wifi.text_labels', 'venue_wifi.free_access_settings', 'venue_wifi.custom_css')->first();

        /***************************************
         * LOAD FREE ACCESS SETTINGS
         ***************************************/
        /**
         * load the free_access_settings conditionally if they exist so that we can use them
         * in the twig template
         */
        $free_access_settings = $venue->venue_wifi->free_access_settings;

        /**
         * get the primary method from the settings
         * - if device browser cannot handle social auth AND social_auth is enabled, we override the settings
         */
        $primary_method = $free_access_settings['primary_method'];

        if($session->limited_cna == 1 && $primary_method == 'social_auth') {
            $primary_method = 'registration_form';
        }

        switch ($primary_method) {
            case 'basic':
                // Load validation rules
                $schema = new RequestSchema('schema://requests/basic-registration.yaml');
                $validator = new JqueryValidationAdapter($schema, $this->ci->translator);

                $this->_app->render('captive_portal/splash-page-basic.twig', [
                    'validators'     => $validator->rules('json', true),
                    'portal_session' => session_id(),
                    'unifi_venue'    => $venue
                ]);

                break;
            case 'registration_form':
                // Load validation rules
                $schema = new RequestSchema('schema://requests/identity-registration.yaml');

                /**
                 * here we call the private function to dynamically extend the validation rules before loading the final schema
                 */
                $schema = $this->extendIdentityValidationRules($schema, $venue);

                $validator = new JqueryValidationAdapter($schema, $this->ci->translator);

                /**
                 * Get the current year
                 */
                $max_year = Carbon::now()->year;
                $min_year = Carbon::now()->subYears(100)->year;

                return $this->ci->view->render($response, 'pages/elephantwifi/captive_portal/splash-page-register.html.twig', [
                    'validators' => $validator->rules('json', true),
                    'portal_session' => session_id(),
                    'unifi_venue' => $venue,
                    'max_year' => $max_year,
                    'min_year' => $min_year
                ]);

                break;
            case 'social_auth':
                return $this->ci->view->render($response, 'pages/elephantwifi/captive_portal/splash-page-social_auth.html.twig', [
                    'portal_session' => session_id(),
                    'unifi_venue' => $venue
                ]);

                break;
        }
    }

    public function displaySocialAuthInit(Request $request, Response $response, $args)
    {
        $session_id = $args['session_id'];
        $provider = $args['provider'];

        /** @var \UserFrosting\Support\Repository\Repository $config */
        $config = $this->ci->config;

        /**
         * get current session details
         */
        if(session_id() == $session_id) {
            /**
             * we have a match
             */
            $session = Session::where('php_session_id', session_id())->first();
        } else {
            /**
             * we have a mismatch but this could be caused by a browser switch client-side (iOS...)
             * - just deal with it and update the value for php_session_id with the current PHP session ID
             * - carry on...
             */
            error_log('we have a session_id mismatch in displaySocialAuthInit: ' . session_id() . " versus: " . $session_id);
            $session = Session::where('php_session_id', $session_id)->first();
            error_log(session_id());
            $session->php_session_id = session_id();
            $session->save();
        }

        /**
         * get current venue
         */
        $venue = Venue::where('id', $session->venue_id)->with('venue_wifi.controller', 'venue_wifi.text_labels', 'venue_wifi.free_access_settings', 'venue_wifi.custom_css')->first();

        $_SESSION['session_id'] = $session->php_session_id;
        $_SESSION['provider'] = $provider;

        /**
         * connect to the UniFi controller for the current venue
         */
        if($provider == 'facebook') {

            $facebook_info = SocialKeys::where('provider', 'facebook')->first();

            $fb = new Facebook(array(
                'app_id' => $facebook_info->app_id,
                'app_secret' => $facebook_info->secret_key,
                'default_graph_version' => 'v2.7',
            ));

            // Get redirect login helper
            $helper = $fb->getRedirectLoginHelper();

            $redirectURL = $config['site.uri.public'] . '/captive_portal/social_auth/responder'; //Callback URL
            $fbPermissions = ['email'];  //Optional permissions

            $fbLoginUrl = $helper->getLoginUrl($redirectURL, $fbPermissions);

            return $this->ci->view->render($response, 'pages/elephantwifi/captive_portal/splash-page-social_auth_forwarding.html.twig', [
                'unifi_venue'  => $venue,
                'url' => $fbLoginUrl
            ]);
        }

        if($provider == 'twitter') {

            $twitter_info = SocialKeys::where('provider', 'twitter')->first();

            // create TwitterOAuth object
            $twitteroauth = new TwitterOAuth($twitter_info['public_key'], $twitter_info['secret_key']);

            $redirectURL = $config['site.uri.public'] . '/captive_portal/social_auth/responder'; //Callback URL

            error_log($redirectURL);
            
            // request token of application
            $request_token = $twitteroauth->oauth(
                'oauth/request_token', [
                    'oauth_callback' => $redirectURL
                ]
            );
            
            // throw exception if something gone wrong
            if($twitteroauth->getLastHttpCode() != 200) {
                throw new \Exception('There was a problem performing this request');
            }
            
            // save token of application to session
            $_SESSION['oauth_token'] = $request_token['oauth_token'];
            $_SESSION['oauth_token_secret'] = $request_token['oauth_token_secret'];
            
            // generate the URL to make request to authorize our application
            $url = $twitteroauth->url(
                'oauth/authorize', [
                    'oauth_token' => $request_token['oauth_token']
                ]
            );

            return $this->ci->view->render($response, 'pages/elephantwifi/captive_portal/splash-page-social_auth_forwarding.html.twig', [
                'unifi_venue' => $venue,
                'url' => $url
            ]);
        }

        if($provider == 'instagram') {

            // $instagram_info = SocialKeys::where('provider', 'instagram')->first();

            $redirectURL = $config['site.uri.public'] . '/captive_portal/social_auth/responder'; //Callback URL

            $instagram = new Instagram(array(
                'apiKey' => '12a18a0da5404522920073ab9fdc8870',
                'apiSecret' => '2713e3d42d8149e28b42787968b4350d',
                'apiCallback' => $redirectURL
            ));

            $loginUrl = $instagram->getLoginUrl(array(
              'basic'
            ));

            return $this->ci->view->render($response, 'pages/elephantwifi/captive_portal/splash-page-social_auth_forwarding.html.twig', [
                'unifi_venue' => $venue,
                'url' => $loginUrl
            ]);
        }
    }

    public function socialAuthResponder(Request $request, Response $response, $args)
    {
        // Get the alert message stream
        $ms = $this->ci->alerts;

        /**
         * get current session details
         */
        $session = Session::where('php_session_id', $_SESSION['session_id'])->first();

        /**
         * get current venue and it's locale and more..
         * NOTE: Venue::find()->with does not work...
         */
        $venue = Venue::where('id', $session->venue_id)->with('venue_wifi.controller', 'venue_wifi.text_labels', 'venue_wifi.free_access_settings', 'venue_wifi.custom_css')->first();

        /**
         * Initialise a new Identity
         */
        $identity = new Identity;

        if($_SESSION['provider'] == 'facebook') {
            error_log('facebook: response');
            /*
             * Configuration and setup Facebook SDK
             */
            $facebook_info = SocialKeys::where('provider', 'facebook')->first();

            $fb = new Facebook([
                'app_id' => $facebook_info->app_id,
                'app_secret' => $facebook_info->secret_key,
                'default_graph_version' => 'v2.2',
            ]);

            $helper = $fb->getRedirectLoginHelper();

            try {
                $accessToken = $helper->getAccessToken();
            } catch(Facebook\Exceptions\FacebookResponseException $e) {
                // When Graph returns an error
                echo 'Graph returned an error: ' . $e->getMessage();
                exit;
            } catch(Facebook\Exceptions\FacebookSDKException $e) {
                // When validation fails or other local issues
                echo 'Facebook SDK returned an error: ' . $e->getMessage();
                exit;
            }

            try {
                // Returns a `FacebookResponse` object
                $fb_response = $fb->get('/me?fields=first_name,last_name,email', $accessToken);
            } catch(Facebook\Exceptions\FacebookResponseException $e) {
                echo 'Graph returned an error: ' . $e->getMessage();
                exit;
            } catch(Facebook\Exceptions\FacebookSDKException $e) {
                echo 'Facebook SDK returned an error: ' . $e->getMessage();
                exit;
            }

            $user = $fb_response->getGraphUser();

            $avatar_url = 'https://graph.facebook.com/v2.3/' . $user['id'] . '/picture';
        }
        elseif($_SESSION['provider'] == 'twitter') {
            error_log('we got redirected to the responder');

            $oauth_verifier = filter_input(INPUT_GET, 'oauth_verifier');
 
            if (empty($oauth_verifier) || empty($_SESSION['oauth_token']) || empty($_SESSION['oauth_token_secret'])) {
                error_log('something is missing');
                // something's missing, go and login again
                // header('Location: ' . $config['url_login']);
            }

            $twitter_info = SocialKeys::where('provider', 'twitter')->first();

            $connection = new TwitterOAuth(
                $twitter_info['public_key'], 
                $twitter_info['secret_key'],
                $_SESSION['oauth_token'],
                $_SESSION['oauth_token_secret']
            );

            // request user token
            $token = $connection->oauth(
                'oauth/access_token', [
                    'oauth_verifier' => $oauth_verifier
                ]
            );

            $twitter = new TwitterOAuth(
                $twitter_info['public_key'],
                $twitter_info['secret_key'],
                $token['oauth_token'],
                $token['oauth_token_secret']
            );

            $user = (array)$twitter->get('account/verify_credentials', ['include_email' => 'true']);
            $avatar_url = $user['profile_image_url_https'];
        }
        else {
            //TODO: redirect them back to login page
        }

        /**
         * store the collected profile data in an identity object
         */
        if(empty($user['name'])) {
            $identity->first_name = $user['first_name'];
            $identity->last_name = $user['last_name'];
        } else {
            $nameArray = explode(' ',$user['name']);
            $identity->first_name = $nameArray[0];
            $identity->last_name = $nameArray[1];
        }

        $identity->profile_id = $user['id'];
        $identity->email_address = $user['email'];
        $identity->provider = $_SESSION['provider'];
        $identity->avatar_url = $avatar_url;
        $identity->authenticated_by = 'social_auth';

        $_SESSION['identity'] = $identity;
        $_SESSION['session'] = $session;

        // Load validation rules
        $schema = new RequestSchema('schema://requests/auth-extra-info.yaml');

        /**
         * here we call the private function to dynamically extend the validation rules before loading the final schema
         */
        $schema = $this->extendIdentityExtraInfoValidationRules($schema, $venue);

        $validator = new JqueryValidationAdapter($schema, $this->ci->translator);

        /**
         * Get the current year
         */
        $max_year = Carbon::now()->year;
        $min_year = Carbon::now()->subYears(100)->year;

        return $this->ci->view->render($response, 'pages/elephantwifi/captive_portal/splash-page-social_auth_extra_info.html.twig', [
            'validators' => $validator->rules('json', true),
            'unifi_venue' => $venue,
            'portal_session' => $_SESSION['session_id'],
            'max_year' => $max_year,
            'min_year' => $min_year
        ]);
    }

    public function socialAuthExtraInfo(Request $request, Response $response, $args)
    {
        $identity = $_SESSION['identity'];
        $session  = $_SESSION['session'];

        // Get POST parameters
        $params = $request->getParsedBody();

        /**
         * convert date of birth to timestamp
         */
        if (isset($params['birth_date_year']) && isset($params['birth_date_month']) && isset($params['birth_date_day'])) {
            $birth_date = Carbon::createFromDate($params['birth_date_year'], $params['birth_date_month'], $params['birth_date_day'], 'Europe/London')->timestamp;
        }

        // Load validation rules
        $schema = new RequestSchema('schema://requests/auth-extra-info.yaml');

        // Get the alert message stream
        $ms = $this->ci->alerts;    

        // Whitelist and set parameter defaults
        $transformer = new RequestDataTransformer($schema);
        $data = $transformer->transform($params);

        // Validate, and halt on validation errors.  Failed validation attempts do not count towards throttling limit.
        $validator = new ServerSideValidator($schema, $this->ci->translator);
        if (!$validator->validate($data)) {
            $ms->addValidationErrors($validator);

            return $response->withJson([], 400);
        }

        /**
         * get current venue
         */
        $venue = Venue::where('id', $session->venue_id)->with('venue_wifi.controller', 'venue_wifi.text_labels', 'venue_wifi.free_access_settings', 'venue_wifi.custom_css')->first();

        /**
         * get current device
         */
        $device = Device::find($session->device->id);

        /**
         * Checks to see if postcode has been entered
         * Gets the county and hometown from the postcode entered
         */
        if (isset($data['postcode'])) {
            $postcodeFinder = new PostcodesIO();

            $postcodeDetails = $postcodeFinder->find($data['postcode']);
            $county = $postcodeDetails->result->admin_county;
            $hometown = $postcodeDetails->result->parish;
            $postcode = $data['postcode'];

            $data['postcode'] = strtoupper(substr_replace($postcode, ' ' . substr($postcode, -3), -3));
            $data['county'] = $county;
            $data['hometown'] = $hometown;
        }

        if (isset($data['gender']))
            $identity->gender = $data['gender'];
        if (isset($birth_date))
            $identity->birth_date = $birth_date;
        if (isset($data['postcode']))
            $identity->postcode = $data['postcode'];
            $identity->county   = $data['county'];
            $identity->hometown = $data['hometown'];

        /**
         * Use the data and create a user in the uf_user table
         * Then send an email to that user allowing them to create a password
         */
        $user = [];
        $user['first_name'] = $identity->first_name;
        $user['last_name'] = $identity->last_name;
        $user['email'] = $identity->email_address;
        $user['primary_venue_id'] = $session->venue_id;
        $user['locale'] = $venue->locale;

        $userInfo = $this->createUser($user);

        $identity->user_id = $userInfo->id;

        /**
         * save all active objects (identity, session, device) in their updated state and attach the session to the identity
         * and the identity to the venue
         */
        $venue->identities()->save($identity);
        $identity->sessions()->save($session);
        $device->save();

        /**
         * Create the visitor profile in our database
         */
        $visitor_profile = new VisitorProfile();
        $visitor_profile->device_uuid = $device->device_uuid;
        $visitor_profile->gender = $identity->gender;
        $visitor_profile->age = Carbon::createFromTimestamp($identity->birth_date)->age;
        $visitor_profile->postcode = $identity->postcode;
        $visitor_profile->save();

        /**
         * If the user ticked subscribe to all emails add them to all the lists
         */
        $marketing_types = explode(',', $_SESSION['marketing_types']);
        
        /**
         * submit the email address to the mailing list, if available and if so configured
         */
        if (!in_array(1000, $marketing_types)) {
            if (in_array(1001, $marketing_types)) {
                $marketing_lists = MarketingList::where('venue_id', $venue->id)->get();
            } else {
                $marketing_lists = MarketingList::whereIn('marketing_list_type_id', $marketing_types)->where('venue_id', $venue->id)->get();
            }

            $marketing_list_ids = [];
            $list_uids = [];
            foreach($marketing_lists as $marketing_list) {
                array_push($marketing_list_ids, $marketing_list->id);
                array_push($list_uids, $marketing_list->list_uid);
            }

            // Add lists to DB
            if (isset($marketing_list_ids)) {
                $identity->marketing_lists()->sync($marketing_list_ids);
            }

            // Subscribe to the mailing lists
            if ($identity->gender == 0)
                $gender = 'Male';
            elseif($identity->gender == 1)
                $gender = 'Female';
            else
                $gender = null;

            if ($identity->birth_date != null) {
                $dob = Carbon::createFromTimestamp($identity->birth_date)->format('d/m/Y');
                $age = Carbon::createFromTimestamp($identity->birth_date)->diff(Carbon::now())->format('%y');
            } else {
                $age = null;
                $dob = null;
            }

            $details = array(
                'email_address' => $identity->email_address,
                'first_name'    => $identity->first_name,
                'last_name'     => $identity->last_name,
                'gender'        => $gender,
                'location'      => $identity['postcode'],
                'last_seen'     => Carbon::now('Europe/London')->format('d/m/Y'),
                'age'           => $age,
                'dob'           => $dob,
                'list_uids'     => $list_uids
            );
            $this->submitIdentityToMailinglist($details, $venue, $venue->venue_wifi->mail_type);

            // If they had to re-select marketing and chose some lists
            if ($device->reshow_marketing < time() && $device->reshow_marketing == 0) {
                $device->reshow_marketing = 0;
            }
        } else {
            $addTime = $venue->venue_wifi->free_access_settings->marketing_reshow_time;

            // If they had to re-select marketing and didnt choose some lists
            if ($device->reshow_marketing < time() && $device->reshow_marketing == 0) {
                $device->reshow_marketing = time() + $addTime;
            }
        }

        /**
         * now we need to get the "venue_free_access_settings" to send to the controller of this venue together with the auth request
         * - authorise the device for a period based on the "auth_duration" setting
         * - update the "auth_expiry_date" for the device
         * - determine speed/data transfer limits
         */
        if($venue->venue_wifi->free_access_settings->speed_limit_up !== 0) {
            $unifi_payload_up = $venue->venue_wifi->free_access_settings->speed_limit_up;
        } else {
            $unifi_payload_up = NULL;
        }

        if($venue->venue_wifi->free_access_settings->speed_limit_down !== 0) {
            $unifi_payload_down = $venue->venue_wifi->free_access_settings->speed_limit_down;
        } else {
            $unifi_payload_down = NULL;
        }

        if($venue->venue_wifi->free_access_settings->data_transfer_limit !== 0) {
            $unifi_payload_data = $venue->venue_wifi->free_access_settings->data_transfer_limit;
        } else {
            $unifi_payload_data = NULL;
        }

        /**
         * connect to the UniFi controller for the current venue
         */
        $unifidata = $this->connectController($venue);
        $result    = $unifidata->authorize_guest($session->device->mac_address, $venue->venue_wifi->free_access_settings->auth_duration, $unifi_payload_up, $unifi_payload_down, $unifi_payload_data);

        /**
         * - update auth_status to 1 for the session so we can check later whether auth'ed or not
         * - update auth_expiry_date and registration_expiry_date for the device since this is a new registration
         *
         * TODO:
         */
        if($result == 1) {
            $session->auth_status = 1;

            /**
             * determine the auth_expiry_date (minutes) and store it with the device (in seconds)
             */
            $auth_expiry_date         = time() + ($venue->venue_wifi->free_access_settings->auth_duration * 60);
            $device->auth_expiry_date = $auth_expiry_date;
            $device->last_seen  = time();

            /**
             * determine the registration_expiry_date (minutes) and store it with the device (in seconds)
             */
            $registration_expiry_date         = time() + ($venue->venue_wifi->free_access_settings->registration_duration * 60);
            $device->registration_expiry_date = $registration_expiry_date;
        } else {
            error_log('we have encountered an authorisation error');
            /**
             * halt and create an error message
             */
            $ms->addMessageTranslated("danger", "AUTH_ERROR");
            $this->_app->halt(403);
        }

        // Set the accepted_terms datetime
        $device->accepted_terms = time();

        $session->save();
        $device->save();
    }

    public function checkEmail(Request $request, Response $response, $args)
    {
        $session_id = $args['session_id'];
        $marketing_types = $args['marketing_types'];

        // Store the marketing_types in the PHP Session
        $_SESSION['marketing_types'] = $marketing_types;

        // Get POST parameters
        $params = $request->getParsedBody();

        // Load validation rules
        $schema = new RequestSchema('schema://requests/captive-portal-email-checker.yaml');

        // Get the alert message stream
        $ms = $this->ci->alerts;    

        // Whitelist and set parameter defaults
        $transformer = new RequestDataTransformer($schema);
        $data = $transformer->transform($params);

        // Validate, and halt on validation errors.  Failed validation attempts do not count towards throttling limit.
        $validator = new ServerSideValidator($schema, $this->ci->translator);
        if (!$validator->validate($data)) {
            $ms->addValidationErrors($validator);

            return $response->withJson([], 400);
        }

        $identity = Identity::where('email_address', $data['email_address'])->first();

        $session = Session::where('php_session_id', session_id())->first();
        $venue = Venue::where('id', $session->venue_id)->with('venue_wifi.controller', 'venue_wifi.text_labels', 'venue_wifi.free_access_settings', 'venue_wifi.custom_css')->first();
        $device = Device::find($session->device->id);

        /**
         * Ignore email if the user has been accept to reaccept the T&C's
         */
        if(!empty($identity->id)) {
            $_SESSION['email_address'] = $data['email_address'];
            // return $response->withStatus(201)->withHeader('Location', '/captive_portal/password/');
            return $response->withJson(['sub_url' => '/captive_portal/password/'], 201);
        } else {
            // return $response->withStatus(201)->withHeader('Location', '/captive_portal/splash_page/');
            return $response->withJson(['sub_url' => '/captive_portal/splash_page/'], 201);
        }
    }

    public function passwordPage(Request $request, Response $response, $args)
    {
        $session_id = $args['session_id'];

        /**
         * get current session details
         */
        if(session_id() == $session_id) {
            /**
             * we have a match
             */
            $session = Session::where('php_session_id', session_id())->first();
        } else {
            /**
             * we have a mismatch but this could be caused by a browser switch client-side (IOS...)
             * - just deal with it and update the value for php_session_id with the current PHP session ID
             * - carry on...
             */
            error_log('we have a session_id mismatch in displaySplash: ' . session_id() . " versus: " . $session_id);
            $session = Session::where('php_session_id', $session_id)->first();
            if (isset($session)) {
                $session->php_session_id = session_id();
                $session->save();
            } else {
                error_log('we could not find the session_id ' . $session_id . ' in displaySplash');
                return $response->withJson([], 400);
            }
        }

        /**
         * get current venue and device..
         * NOTE: Venue::find()->with does not work...
         */
        $venue = Venue::where('id', $session->venue_id)->with('venue_wifi.controller', 'venue_wifi.text_labels', 'venue_wifi.free_access_settings', 'venue_wifi.custom_css')->first();
        $device = Device::find($session->device->id);

        // Load validation rules
        $schema = new RequestSchema('schema://requests/captive-portal-password.yaml');
        $validator = new JqueryValidationAdapter($schema, $this->ci->translator);

        return $this->ci->view->render($response, 'pages/elephantwifi/captive_portal/splash-page-password.html.twig', [
            'validators' => $validator->rules('json', true),
            'portal_session' => session_id(),
            'unifi_venue' => $venue
        ]);
    }

    public function authorisePage(Request $request, Response $response, $args)
    {
        /** @var \UserFrosting\Sprinkle\Core\Util\ClassMapper $classMapper */
        $classMapper = $this->ci->classMapper;

        $session_id = $args['session_id'];

        /**
         * get current session details
         */
        if(session_id() == $session_id) {
            /**
             * we have a match
             */
            $session = Session::where('php_session_id', session_id())->first();
        } else {
            /**
             * we have a mismatch but this could be caused by a browser switch client-side (IOS...)
             * - just deal with it and update the value for php_session_id with the current PHP session ID
             * - carry on...
             */
            error_log('we have a session_id mismatch in displaySplash: ' . session_id() . " versus: " . $session_id);
            $session = Session::where('php_session_id', $session_id)->first();
            if (isset($session)) {
                $session->php_session_id = session_id();
                $session->save();
            } else {
                error_log('we could not find the session_id ' . $session_id . ' in displaySplash');
                return $response->withJson([], 400);
            }
        }

        $venue = Venue::where('id', $session->venue_id)->with('venue_wifi.controller', 'venue_wifi.text_labels', 'venue_wifi.free_access_settings', 'venue_wifi.custom_css')->first();
        $device = Device::find($session->device->id);

        /**
         * Reset registration time if this is the reason they had to re-authorise
         */
        if ($device->registration_expiry_date < time()) {
            $registration_expiry_date = time() + ($venue->venue_wifi->free_access_settings->registration_duration * 60);
            $device->registration_expiry_date = $registration_expiry_date;
        }

        $identity = Identity::where('email_address', $_SESSION['email_address'])->first();

        $user = $classMapper->getClassMapping('user')
            ::where('users.id', $identity->user_id)
            ->first();

        // Set the accepted_terms datetime
        $device->accepted_terms = time();

        /**
         * The device needs to re-accept the terms 
         */
        if ($device->reshow_terms == 1) {
            $device->reshow_terms = 0;
        }

        /**
         * If the user ticked subscribe to all emails add them to all the lists
         */
        $marketing_types = explode(',', $_SESSION['marketing_types']);

        /**
         * submit the email address to the mailing list, if available and if so configured
         */
        if (!in_array(1000, $marketing_types)) {
            if (in_array(1001, $marketing_types)) {
                $marketing_lists = MarketingList::where('venue_id', $venue->id)->get();
            } else {
                $marketing_lists = MarketingList::whereIn('marketing_list_type_id', $marketing_types)->where('venue_id', $venue->id)->get();
            }

            $marketing_list_ids = [];
            $list_uids = [];
            foreach($marketing_lists as $marketing_list) {
                array_push($marketing_list_ids, $marketing_list->id);
                array_push($list_uids, $marketing_list->list_uid);
            }

            // Add lists to DB
            if (isset($marketing_list_ids)) {
                $identity->marketing_lists()->sync($marketing_list_ids);
            }

            // Subscribe to the mailing lists
            if ($identity['gender'] == 0)
                $gender = 'Male';
            elseif($identity['gender'] == 1)
                $gender = 'Female';
            else
                $gender = null;

            if ($identity['birth_date'] != null) {
                $dob = Carbon::createFromTimestamp($identity['birth_date'])->format('d/m/Y');
                $age = Carbon::createFromTimestamp($identity['birth_date'])->diff(Carbon::now())->format('%y');
            } else {
                $age = null;
                $dob = null;
            }

            $details = array(
                'email_address' => $identity['email_address'],
                'first_name' => $identity['first_name'],
                'last_name' => $identity['last_name'],
                'gender' => $gender,
                'location' => $identity['postcode'],
                'last_seen' => Carbon::now('Europe/London')->format('d/m/Y'),
                'age' => $age,
                'dob' => $dob,
                'list_uids' => $list_uids
            );
            $this->submitIdentityToMailinglist($details, $venue, $venue->venue_wifi->mail_type);

            // If they had to re-select marketing and chose some lists
            if ($device->reshow_marketing < time() && $device->reshow_marketing != 0) {
                $device['reshow_marketing'] = 0;
            }
        } else {
            $addTime = $venue->venue_wifi->free_access_settings->marketing_reshow_time;

            // If they had to re-select marketing and didnt choose some lists
            if ($device->reshow_marketing < time() && $device->reshow_marketing != 0) {
                $device['reshow_marketing'] = time() + $addTime;
            }
        }

        $identity->save();
        $identity->sessions()->save($session);
        $device->save();

        /**
         * Add the new venue to the wifi_user
         */
        $userVenueIds = $user->getWifiUserVenueIds();
        array_push($userVenueIds, $venue->id);
        $user->wifiUserVenues()->sync($userVenueIds);

        return $this->ci->view->render($response, 'pages/elephantwifi/captive_portal/splash-page-motd.html.twig', [
            'portal_session' => session_id(),
            'unifi_venue' => $venue
        ]);
    }

    public function redirectUponSuccess(Request $request, Response $response, $args)
    {
        $session_id = $args['session_id'];

        /**
         * get current session details
         */
        if(session_id() === $session_id) {
            /**
             * we have a match
             */
            $session = Session::where('php_session_id', session_id())->first();
        } else {
            error_log('we have a session_id mismatch in redirectUponSuccess: ' . $session_id . ' and ' . session_id());
            $session = Session::where('php_session_id', $session_id)->first();
        }

        /**
         * find the associated venue details, it's locale and it's settings
         */
        $venue = Venue::where('id', $session->venue_id)->with('venue_wifi.text_labels', 'venue_wifi.free_access_settings', 'venue_wifi.custom_css')->first();

        /**
         * Halt if auth_status is not 1
         */
        if ($session->auth_status != 1) {
            error_log('we have an auth issue in function redirectUponSuccess: ' . $session->auth_status);
            return $response->withJson([], 400);
        }

        error_log('redirectUponSuccess: we are redirecting a user to: ' . $venue->venue_wifi->free_access_settings->redirect_url);

        return $this->ci->view->render($response, 'pages/elephantwifi/captive_portal/splash-page-redirect.html.twig', [
            'unifi_venue' => $venue
        ]);
    }

    public function passwordCheck(Request $request, Response $response, $args)
    {
        /** @var \UserFrosting\Sprinkle\Core\Util\ClassMapper $classMapper */
        $classMapper = $this->ci->classMapper;

        // Get POST parameters
        $params = $request->getParsedBody();

        // Load validation rules
        $schema = new RequestSchema('schema://requests/captive-portal-password.yaml');

        // Get the alert message stream
        $ms = $this->ci->alerts;    

        // Whitelist and set parameter defaults
        $transformer = new RequestDataTransformer($schema);
        $data = $transformer->transform($params);

        // Validate, and halt on validation errors.  Failed validation attempts do not count towards throttling limit.
        $validator = new ServerSideValidator($schema, $this->ci->translator);
        if (!$validator->validate($data)) {
            $ms->addValidationErrors($validator);

            return $response->withJson([], 400);
        }

        $user = $classMapper->getClassMapping('user')
            ::where('email', $_SESSION['email_address'])
            ->first();

        // Check that the user exists
        if (empty($user)) {
            $ms->addMessageTranslated("danger", "This email is not assigned to an account");
            throw new ForbiddenException();
        }

        // Check that the user has a password set (so, rule out newly created accounts without a password)
        if (!$user->password) {
            $ms->addMessageTranslated("danger", "ACCOUNT_USER_OR_PASS_INVALID");
            throw new ForbiddenException();
        }

        // Check that the user's account is enabled
        if ($user->flag_enabled == 0){
            $ms->addMessageTranslated("danger", "ACCOUNT_DISABLED");
            throw new ForbiddenException();
        }

        // Check that the user's account is activated
        if ($user->flag_verified == 0) {
            $ms->addMessageTranslated("danger", "ACCOUNT_INACTIVE");
            throw new ForbiddenException();
        }

        /** @var \UserFrosting\Sprinkle\Account\Authenticate\Authenticator $authenticator */
        $authenticator = $this->ci->authenticator;

        $authenticator->login($user);

        $ms->addMessageTranslated('success', 'WELCOME', $user->export());

        return $response->withJson([], 200);
    }

    public function displayRegistrationForm(Request $request, Response $response, $args)
    {
        $session_id = $args['session_id'];

        /**
         * get current session details
         */
        if(session_id() == $session_id) {
            /**
             * we have a match
             */
            $session = Session::where('php_session_id', session_id())->first();
        } else {
            error_log('we have a session_id mismatch in displayRegistrationForm: ' . session_id() . " versus: " . $session_id);
            $session = Session::where('php_session_id', $session_id)->first();
        }

        /**
         * get current venue and it's locale and more..
         * NOTE: Venue::find()->with does not work...
         */
        $venue = Venue::where('id', $session->venue_id)->with('venue_wifi.controller', 'venue_wifi.text_labels', 'venue_wifi.free_access_settings', 'venue_wifi.custom_css')->first();

        // Get current device
        $device = Device::find($session->device->id);

        // Load validation rules
        $schema = new RequestSchema('schema://requests/identity-registration.yaml');

        /**
         * here we call the private function to dynamically extend the validation rules before loading the final schema
         */
        $schema = $this->extendIdentityValidationRules($schema, $venue);
        $validator = new JqueryValidationAdapter($schema, $this->ci->translator);

        /**
         * Get the current year
         */
        $max_year = Carbon::now()->year;
        $min_year = Carbon::now()->subYears(100)->year;

        return $this->ci->view->render($response, 'pages/elephantwifi/captive_portal/splash-page-register.html.twig', [
            'validators' => $validator->rules('json', true),
            'portal_session' => $session_id,
            'unifi_venue' => $venue,
            'max_year' => $max_year,
            'min_year' => $min_year
        ]);
    }

    public function registerIdentity(Request $request, Response $response, $args)
    {
        $session_id = $args['session_id'];

        // Get POST parameters
        $post_data_raw = $request->getParsedBody();

        // Get the alert message stream
        $ms = $this->ci->alerts; 

        /**
         * convert date of birth to timestamp
         */
        if (isset($post_data_raw['birth_date_year']) && isset($post_data_raw['birth_date_month']) && isset($post_data_raw['birth_date_day'])) {
            $birth_date = Carbon::createFromDate($post_data_raw['birth_date_year'], $post_data_raw['birth_date_month'], $post_data_raw['birth_date_day'], 'Europe/London')->timestamp;

            /**
             * Halt if birth date is too young
             */
            if (Carbon::now()->timestamp - $birth_date < 410240376 ) {
                $ms->addMessageTranslated('danger', 'UNDER_AGE');
                return $response->withJson([], 400);
            }
        }

        /**
         * get current session details
         */
        if(session_id() === $session_id) {
            /**
             * we have a match
             */
            $session = Session::where('php_session_id', session_id())->first();
        } else {
            error_log('we have a session_id mismatch in registerIdentity: ' . $session_id . ' and ' . session_id());
            $session = Session::where('php_session_id', $session_id)->first();
        }        

        /**
         * get current venue and it's locale
         */
        $venue = Venue::where('id', $session->venue_id)->with('venue_wifi.controller', 'venue_wifi.text_labels', 'venue_wifi.free_access_settings', 'venue_wifi.custom_css')->first();

        /**
         * get current device
         */
        $device = Device::find($session->device->id);

        // Load validation rules
        $schema = new RequestSchema('schema://requests/identity-registration.yaml');

        /**
         * here we call the private function to dynamically extend the validation rules before loading the final schema
         */
        $schema = $this->extendIdentityValidationRules($schema, $venue);

        // Whitelist and set parameter defaults
        $transformer = new RequestDataTransformer($schema);
        $data = $transformer->transform($post_data_raw);

        // Validate, and halt on validation errors.  Failed validation attempts do not count towards throttling limit.
        $validator = new ServerSideValidator($schema, $this->ci->translator);
        if (!$validator->validate($data)) {
            $ms->addValidationErrors($validator);

            return $response->withJson([], 400);
        }

        /**
         * Email Checker API
         * Uses CURL to call the API
         * Checks to see if the entered email address is valid
         */
        $apiKey = $venue->venue_wifi->free_access_settings->email_checker_api_key;
        $emailToValidate = $data['email_address'];
        $url = 'https://api.zerobounce.net/v1/validate?apikey='.$apiKey.'&email='.urlencode($emailToValidate);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15); 
        curl_setopt($ch, CURLOPT_TIMEOUT, 150); 
        $response = curl_exec($ch);
        curl_close($ch);

        $json = json_decode($response, true);
        $status = $json['status'];

        /**
         * Update the email_statuses table
         */
        try {
            $emailTb = WifiDailyStatsVenueEmailStatuses::where('venue_id', $venue->id)->orderBy('day_epoch', 'desc')->first();
            $startToday = Carbon::now()->startOfDay()->timestamp;
            
            if (empty($emailTb) || $emailTb->day_epoch != $startToday) {
                $emailTb = new WifiDailyStatsVenueEmailStatuses();
                $emailTb->venue_id  = $venue->id;
                $emailTb->day_epoch = $startToday;
                switch($status) {
                    case 'Valid':
                        $emailTb->valid = 1;
                        break;
                    case 'Invalid':
                        $emailTb->invalid = 1;
                        break;
                    case 'Catch-All':
                        $emailTb->catch_all = 1;
                        break;
                    default:
                        $emailTb->unknown = 1;
                }
            }
            else {
                switch($status) {
                    case 'Valid':
                        $emailTb->increment('valid');
                        break;
                    case 'Invalid':
                        $emailTb->increment('invalid');
                        break;
                    case 'Catch-All':
                        $emailTb->increment('catch_all');
                        break;
                    default:
                        $emailTb->increment('unknown');
                }
            }

            $emailTb->save();
        } catch (Exception $e) {
            error_log('Email statuses db hasnt been updated: ' . $e);
        }

        // Alert user the email address is invalid
        if ($status == 'Invalid') {
            $ms->addMessageTranslated("danger", "EMAIL_INVALID_ERROR");
            throw new ForbiddenException();
        }

        /**
         * Checks to see if the birth_date has been entered
         */
        if (isset($birth_date)) {
            $data['birth_date'] = $birth_date;
        }

        /**
         * Checks to see if postcode has been entered
         * Gets the county and hometown from the postcode entered
         */
        if (isset($data['postcode'])) {
            $postcodeFinder = new PostcodesIO();

            $postcodeDetails = $postcodeFinder->find($data['postcode']);
            $county = $postcodeDetails->result->admin_county;
            $hometown = $postcodeDetails->result->parish;
            $postcode = $data['postcode'];

            $data['postcode'] = strtoupper(substr_replace($postcode, ' ' . substr($postcode, -3), -3));
            $data['county'] = $county;
            $data['hometown'] = $hometown;
        }

        /**
         * Halt on any validation errors
         */
        if ($error) {
            error_log('we have a validation error in function registerIdentity');
            return $response->withJson([], 400);
        }

        /**
         * Create the new identity object
         */
        $identity = new Identity($data);
        $identity->authenticated_by = 'registration_form';

        $data['venue'] = $session->venue_id;
        $data['locale'] = $locale;

        /**
         * Use the data and create a user in the uf_user table
         * Then send an email to that user allowing them to create a password
         */
        $user = [];
        $user['first_name'] = $identity->first_name;
        $user['last_name'] = $identity->last_name;
        $user['email'] = $identity->email_address;
        $user['primary_venue_id'] = $session->venue_id;
        $user['locale'] = $locale;

        $userInfo = $this->createUser($user);

        $identity->user_id = $userInfo->id;

        /**
         * save all active objects (identity, session, device) in their updated state and attach the session to the identity
         * and the identity to the venue
         */
        $venue->identities()->save($identity);
        $identity->sessions()->save($session);
        $device->save();

        /**
         * Create the visitor profile in our database
         */
        $visitor_profile = new VisitorProfile();
        $visitor_profile->device_uuid = $device->device_uuid;
        $visitor_profile->gender = $identity->gender;
        $visitor_profile->age = Carbon::createFromTimestamp($identity->birth_date)->age;
        $visitor_profile->postcode = $identity->postcode;
        $visitor_profile->save();

        /**
         * If the user ticked subscribe to all emails add them to all the lists
         */
        $marketing_types = explode(',', $_SESSION['marketing_types']);
        
        /**
         * submit the email address to the mailing list, if available and if so configured
         */
        if (!in_array(1000, $marketing_types)) {
            if (in_array(1001, $marketing_types)) {
                $marketing_lists = MarketingList::where('venue_id', $venue->id)->get();
            } else {
                $marketing_lists = MarketingList::whereIn('marketing_list_type_id', $marketing_types)->where('venue_id', $venue->id)->get();
            }

            $marketing_list_ids = [];
            $list_uids = [];
            foreach($marketing_lists as $marketing_list) {
                array_push($marketing_list_ids, $marketing_list->id);
                array_push($list_uids, $marketing_list->list_uid);
            }

            // Add lists to DB
            if (isset($marketing_list_ids)) {
                $identity->marketing_lists()->sync($marketing_list_ids);
            }

            // Subscribe to the mailing lists
            if ($identity['gender'] == 0)
                $gender = 'Male';
            elseif($identity['gender'] == 1)
                $gender = 'Female';
            else
                $gender = null;

            if ($identity['birth_date'] != null) {
                $dob = Carbon::createFromTimestamp($identity['birth_date'])->format('d/m/Y');
                $age = Carbon::createFromTimestamp($identity['birth_date'])->diff(Carbon::now())->format('%y');
            } else {
                $age = null;
                $dob = null;
            }

            $details = array(
                'email_address' => $identity['email_address'],
                'first_name' => $identity['first_name'],
                'last_name' => $identity['last_name'],
                'gender' => $gender,
                'location' => $identity['postcode'],
                'last_seen' => Carbon::now('Europe/London')->format('d/m/Y'),
                'age' => $age,
                'dob' => $dob,
                'list_uids' => $list_uids
            );

            $this->submitIdentityToMailinglist($details, $venue, $venue->venue_wifi->mail_type);

            // If they had to re-select marketing and chose some lists
            if ($device->reshow_marketing < time() && $device->reshow_marketing == 0) {
                $device->reshow_marketing = 0;
            }
        } else {
            $addTime = $venue->venue_wifi->free_access_settings->marketing_reshow_time;

            // If they had to re-select marketing and didnt choose some lists
            if ($device->reshow_marketing < time() && $device->reshow_marketing == 0) {
                $device->reshow_marketing = time() + $addTime;
            }
        }

        /**
         * now we need to get the "venue_free_access_settings" to send to the controller of this venue together with the auth request
         * - authorise the device for a period based on the "auth_duration" setting
         * - update the "auth_expiry_date" for the device
         * - determine speed/data transfer limits
         */
        if($venue->venue_wifi->free_access_settings->speed_limit_up !== 0) {
            $unifi_payload_up = $venue->venue_wifi->free_access_settings->speed_limit_up;
        } else {
            $unifi_payload_up = NULL;
        }

        if($venue->venue_wifi->free_access_settings->speed_limit_down !== 0) {
            $unifi_payload_down = $venue->venue_wifi->free_access_settings->speed_limit_down;
        } else {
            $unifi_payload_down = NULL;
        }

        if($venue->venue_wifi->free_access_settings->data_transfer_limit !== 0) {
            $unifi_payload_data = $venue->venue_wifi->free_access_settings->data_transfer_limit;
        } else {
            $unifi_payload_data = NULL;
        }

        /**
         * connect to the UniFi controller for the current venue
         */
        $unifidata = $this->connectController($venue);
        $result = $unifidata->authorize_guest($session->device->mac_address, $venue->venue_wifi->free_access_settings->auth_duration, $unifi_payload_up, $unifi_payload_down, $unifi_payload_data);

        /**
         * - update auth_status to 1 for the session so we can check later whether auth'ed or not
         * - update auth_expiry_date and registration_expiry_date for the device since this is a new registration
         *
         * TODO:
         */
        if($result == 1) {
            $session->auth_status = 1;

            /**
             * determine the auth_expiry_date (minutes) and store it with the device (in seconds)
             */
            $auth_expiry_date = time() + ($venue->venue_wifi->free_access_settings->auth_duration * 60);
            $device->auth_expiry_date = $auth_expiry_date;
            $device->last_seen  = time();

            /**
             * determine the registration_expiry_date (minutes) and store it with the device (in seconds)
             */
            $registration_expiry_date = time() + ($venue->venue_wifi->free_access_settings->registration_duration * 60);
            $device->registration_expiry_date = $registration_expiry_date;
        } 
        else {
            error_log('we have encountered an authorisation error');
            /**
             * halt and create an error message
             */
            $ms->addMessageTranslated("danger", "AUTH_ERROR");
            throw new ForbiddenException();
        }

        // Set the accepted_terms datetime
        $device->accepted_terms = time();

        $session->save();
        $device->save();
    }

    public function authRegisteredDevice(Request $request, Response $response, $args)
    {
        $session_id = $args['session_id'];

        // Get the alert message stream
        $ms = $this->ci->alerts; 

        /**
         * get current session details
         */
        if(session_id() === $session_id) {
            // we have a match
            $session = Session::where('php_session_id', session_id())->first();
        } else {
            error_log('we have a session_id mismatch in authRegisteredDevice: ' . $session->auth_status);
            $ms->addMessageTranslated("danger", "SESSION_ERROR");
            return $response->withJson([], 400);
        }

        /**
         * get current venue and it's locale
         */
        $venue = Venue::where('id', $session->venue_id)->with('venue_wifi.controller', 'venue_wifi.text_labels', 'venue_wifi.free_access_settings', 'venue_wifi.custom_css')->first();

        /**
         * get current device
         */
        $device = Device::find($session->device->id);

        /**
         * validate whether registration (unix time) has not yet expired (add a few minutes to give the
         * user time to process the calling page)
         */
        if(($device->registration_expiry_date + 120) < time()) {
            $ms->addMessageTranslated("danger", "REGISTRATION_EXPIRED_ERROR");
            return $response->withJson([], 400);
        }

        /**
         * now we need to get the venue_free_access_settings for the controller related to this venue
         * - authorise the device for the auth_duration setting time frame
         * - update the auth_expiry_date for the device
         * - determine speed/data transfer limits
         */
        if($venue->venue_wifi->free_access_settings->speed_limit_up !== 0) {
            $unifi_payload_up = $venue->venue_wifi->free_access_settings->speed_limit_up;
        } else {
            $unifi_payload_up = NULL;
        }

        if($venue->venue_wifi->free_access_settings->speed_limit_down !== 0) {
            $unifi_payload_down = $venue->venue_wifi->free_access_settings->speed_limit_down;
        } else {
            $unifi_payload_down = NULL;
        }

        if($venue->venue_wifi->free_access_settings->data_transfer_limit !== 0) {
            $unifi_payload_data = $venue->venue_wifi->free_access_settings->data_transfer_limit;
        } else {
            $unifi_payload_data = NULL;
        }

        /**
         * connect to the UniFi controller for the current venue
         */
        $unifidata = $this->connectController($venue);
        $result = $unifidata->authorize_guest($session->device->mac_address, $venue->venue_wifi->free_access_settings->auth_duration, $unifi_payload_up, $unifi_payload_down, $unifi_payload_data);

        /**
         * - update auth_status to 1 for the session so we can check later whether auth'ed or not
         * - update auth_expiry_date with device
         *
         * TODO:
         */
        if($result == 1) {
            $session->auth_status = 1;

            /**
             * determine the auth_expiry_date (minutes) and store it with the device (in seconds)
             */
            $auth_expiry_date         = time() + ($venue->venue_wifi->free_access_settings->auth_duration * 60);
            $device->auth_expiry_date = $auth_expiry_date;
            $device->last_seen  = time();
        } 
        else {
            /**
             * halt and create an error message
             */
            $ms->addMessageTranslated("danger", "AUTH_ERROR");
            throw new ForbiddenException();
        }

        /**
         * save all active objects in their updated state
         */
        $session->save();
        $device->save();
    }

    public function customCSS(Request $request, Response $response, $args)
    {
        $venue_id = $args['venue_id'];

        // Get current venue
        $venue = Venue::where('id', $venue_id)->with('venue_wifi.custom_css')->first();

        /** @var \UserFrosting\Support\Repository\Repository $config */
        $config = $this->ci->config;

        /**
         * generate the CSS output:
         * - when additional CSS atributes are stored in the custom_css object,
         * - we have to construct the CSS content based on that
         * - render this before the raw CSS
         *
         * TODO:
         * first check whether an attribute has a value or not, only work with it if set
         * probably not necessary
         */
        $initial_css_content = "
        /**
         * Custom CSS
         */
        .panel, .panel-heading, .modal-dialog, .modal-body, .modal-footer {
            background: {$venue->venue_wifi->custom_css->panel_bg_color} !important;
            color: {$venue->venue_wifi->custom_css->text_color} !important;
            border-color: {$venue->venue_wifi->custom_css->panel_border_color} !important;
        }

        .panel-heading, .modal-header {
            background: {$venue->venue_wifi->custom_css->panel_header_bg_color} !important;
            border-color: {$venue->venue_wifi->custom_css->panel_border_color} !important;
        }

        a, a:link, a:visited, a:hover, a.focus {
            color: blue;
        }

        #social_buttons a {
            color: {$venue->venue_wifi->custom_css->hyperlink_color};
        }

        a.btn, a.btn:link, a.btn:visited, a.btn:hover, a.btn.focus {
            color: {$venue->venue_wifi->custom_css->hyperlink_color};
        }

        *:not(.btn) {
            border-radius: {$venue->venue_wifi->custom_css->border_radius}px !important;
        }

        .btn {
            border-radius: {$venue->venue_wifi->custom_css->button_radius}px !important;
        }

        ";

        /**
         * here we set the url for the background image depending on whether there
         * is a custom image or not
         */
        if ($venue->venue_wifi->custom_css->custom_background_file_uuid != '' && $venue->venue_wifi->custom_css->custom_background_file_name != '') {
            $background_image_url = $config['site.uri.public']
                . '/assets-raw/images/captive_portal/custom/background/' . $venue->id
                . '/' . $venue->venue_wifi->custom_css->custom_background_file_uuid
                . '/' . $venue->venue_wifi->custom_css->custom_background_file_name;
        } else {
            $background_image_url = $config['site.uri.public'] . '/assets-raw/images/captive_portal/bg.png';
        }

        error_log($background_image_url);

        $css_content_for_background = "
        html, body {
            height :100%;
            background: url({$background_image_url}) no-repeat center center fixed;
            -webkit-background-size: cover;
            -moz-background-size: cover;
            -o-background-size: cover;
            background-size: cover;
            -moz-border-image: url({$background_image_url}) 0;
        }

        ";

        if (isset($venue->venue_wifi->custom_css->css)) {
            return $response->withStatus(200)
                ->withHeader('Content-Type', 'text/css')
                ->write($initial_css_content . $css_content_for_background . $venue->venue_wifi->custom_css->css);
        } 
        else {
            return $response->withStatus(200)
                ->withHeader('Content-Type', 'text/css')
                ->write('//empty CSS');
        }
    }

    private function extendIdentityValidationRules($schema, $venue)
    {
        if ($venue->venue_wifi->free_access_settings->required_firstname ==  1) {
            $schema->addValidator("first_name", "required", [
                "message" => "IDENTITY_SPECIFY_FIRST_NAME"
            ]);
        }

        if ($venue->venue_wifi->free_access_settings->required_lastname ==  1) {
            $schema->addValidator("last_name", "required", [
                "message" => "IDENTITY_SPECIFY_LAST_NAME"
            ]);
        }

        if ($venue->venue_wifi->free_access_settings->required_email ==  1) {
            $schema->addValidator("email_address", "required", [
                "message" => "ACCOUNT_SPECIFY_EMAIL"
            ]);
        }

        if ($venue->venue_wifi->free_access_settings->required_gender ==  1) {
            $schema->addValidator("gender", "required", [
                "message" => "GENDER_REQUIRED"
            ]);
        }

        if ($venue->venue_wifi->free_access_settings->required_birth_date ==  1) {
            $schema->addValidator("birth_date_day", "required", [
                "message" => "BIRTH_DAY_REQUIRED"
            ]);
            $schema->addValidator("birth_date_month", "required", [
                "message" => "BIRTH_MONTH_REQUIRED"
            ]);
            $schema->addValidator("birth_date_year", "required", [
                "message" => "BIRTH_YEAR_REQUIRED"
            ]);
        }

        if ($venue->venue_wifi->free_access_settings->required_postcode ==  1) {
            $schema->addValidator("postcode", "required", [
                "message" => "POSTCODE_REQUIRED"
            ]);
        }

        return $schema;
    }

    private function extendIdentityExtraInfoValidationRules($schema, $venue)
    {
        if ($venue->venue_wifi->free_access_settings->required_gender ==  1) {
            $schema->addValidator("gender", "required", [
                "message" => "GENDER_REQUIRED"
            ]);
        }
        if ($venue->venue_wifi->free_access_settings->required_birth_date ==  1) {
            $schema->addValidator("birth_date_day", "required", [
                "message" => "BIRTH_DAY_REQUIRED"
            ]);
            $schema->addValidator("birth_date_month", "required", [
                "message" => "BIRTH_MONTH_REQUIRED"
            ]);
            $schema->addValidator("birth_date_year", "required", [
                "message" => "BIRTH_YEAR_REQUIRED"
            ]);
        }
        if ($venue->venue_wifi->free_access_settings->required_postcode ==  1) {
            $schema->addValidator("postcode", "required", [
                "message" => "POSTCODE_REQUIRED"
            ]);
        }

        return $schema;
    }

    private function createUser($data)
    {
        // Get the alert message stream
        $ms = $this->ci->alerts; 

        /** @var \UserFrosting\Sprinkle\Core\Util\ClassMapper $classMapper */
        $classMapper = $this->ci->classMapper;

        /** @var \UserFrosting\Support\Repository\Repository $config */
        $config = $this->ci->config;

        // $currentUser = User::where('email', $data['email_address'])->first();
        $currentUser = $classMapper->getClassMapping('user')::where('email', $data['email'])->first();

        $venue = Venue::where('id', $data['venue'])->first();

        /**
         * Check if the User is already in the database
         * If not add the new user to the database
         */
        if(!isset($currentUser)) {
            // Set default values
            $data['group_id'] = 3;
            $data['full_venue_view_allowed'] = 0;
            $data['user_name'] = $data['email'];
            $data['company_id'] = 3;
            $data['locale'] = 'en_US';
            $data['password'] = '';

            $user = $classMapper->createInstance('user', $data);
            $user->save();

            $user->roles()->sync($data['group_id']);

            // Create activity record
            $this->ci->userActivityLogger->info("User {$user->user_name} created a new account.", [
                'type' => 'account_create',
                'user_id' => $user->id,
            ]);

            // Save user
            $user->save();

            // Add user to venue
            $user->wifiUserVenues()->sync([$data['primary_venue_id']]);

            // Save user again
            $user->save();

            // Try to generate a new password request
            $passwordRequest = $this->ci->repoPasswordReset->create($user, $config['password_reset.timeouts.create']);

            // If the password_mode is manual, do not send an email to set it. Else, send the email.
            if ($data['password'] === '') {
                // Create and send welcome email with password set link
                $message = new TwigMailMessage($this->ci->view, 'mail/password-create.html.twig');

                $message->from($config['address_book.admin'])
                    ->addEmailRecipient(new EmailRecipient($user->email, $user->full_name))
                    ->setFromEmail($config['address_book.admin'])
                    ->setReplyEmail($config['address_book.admin'])
                    ->addParams([
                        'user' => $user,
                        'create_password_expiration' => $config['password_reset.timeouts.create'] / 3600 . ' hours',
                        'token' => $passwordRequest->getToken(),
                    ]);

                $this->ci->mailer->send($message);
            }

            $ms->addMessageTranslated('success', 'USER.CREATED', $data);
        }
        else {
            $user = $classMapper->getClassMapping('user')
                ::where('users.id', $currentUser['id'])
                ->first();

            /**
             * Check if the user is part of the WiFi User group (3)
             */
            $roleIds = $user->getRoleIds();

            if(!in_array(3, $roleIds)) {
                array_push($roleIds, 3);
                $user->roles()->sync($roleIds);
                $user->save();
            }

            /**
             * Check if the user is part of the current venue
             */
            $venueIds = $user->getWifiUserVenueIds();

            if(!in_array($data['primary_venue_id'], $venueIds)) {                
                array_push($venueIds, $data['primary_venue_id']);

                // Add user to venue
                $user->wifiUserVenues()->sync($venueIds);
                $user->save();
            }

            // Save the user for the final time
            $user->save();

            // Get the venue info that the user has signed up to
            $venue = Venue::where('id', $data['primary_venue_id'])->first();

            // Create and send welcome email with password set link
            $message = new TwigMailMessage($this->ci->view, 'mail/existing-wifi-user.html.twig');

            $message->from($config['address_book.admin'])
                ->addEmailRecipient(new EmailRecipient($user->email, $user->full_name))
                ->setFromEmail($config['address_book.admin'])
                ->setReplyEmail($config['address_book.admin'])
                ->addParams([
                    'user' => $user,
                    'wifi_venue' => $venue
                ]);

            $this->ci->mailer->send($message);

            $ms->addMessageTranslated('success', 'USER.CREATED', $data);
        }

        /**
         * Update the email_statuses table
         */
        try {
            $emailTb = WifiDailyStatsVenueEmailStatuses::where('venue_id', $data['primary_venue_id'])->orderBy('day_epoch', 'desc')->first();
            $startToday = Carbon::now()->startOfDay()->timestamp;

            if (empty($emailTb) || $emailTb->day_epoch != $startToday) {
                $emailTb = new WifiDailyStatsVenueEmailStatuses();
                $emailTb->venue_id    = $data['primary_venue_id'];
                $emailTb->day_epoch   = $startToday;
                $emailTb->emails_sent = 1;
            }
            else {
                $emailTb->increment('emails_sent');
            }

            $emailTb->save();

        } catch (Exception $e) {
            error_log('Email statuses count db hasnt been updated: ' . $e);
        }

        return $user;
    }

    private function submitIdentityToMailinglist($details, $venue, $mail_type = 'sendinblue')
    {
        if ($mail_type == 'sendinblue') {
            // Configure API key authorization: api-key
            $config = SendinBlueConfiguration::getDefaultConfiguration()->setApiKey('api-key', $venue->venue_wifi->marketing_public_key);

            $apiInstance = new SendinBlueContactsApi(
                new \GuzzleHttp\Client(),
                $config
            );
            $createContact = new \SendinBlue\Client\Model\CreateContact();

            $list_ids = [];
            foreach($details['list_uids'] as $list_uid) {
                $list_ids[] = (int)$list_uid;
            }

            $createContact['email'] = $details['email_address'];
            $createContact['attributes'] = [
                'FIRSTNAME' => $details['first_name'],
                'LASTNAME' => $details['last_name'],
                'GENDER' => $details['gender'],
                'LOCATION' => $details['location'],
                'AGE' => $details['age'],
                'DOB' => $details['dob']
            ];
            $createContact['listIds'] = $list_ids;

            try {
                $result = $apiInstance->createContact($createContact);
            } catch (Exception $e) {
                error_log('Caught general exception in private function submitIdentityToMailinglist(): ' . $e->getMessage());
            }
        }
        else if ($mail_type == 'mailchimp') {
            try {
                $MailChimp = new MailChimp($venue->venue_wifi['marketing_public_key']);
                foreach($details['list_uids'] as $list_uid) {
                    $create_result = $MailChimp->post("lists/$list_uid/members", [
                        'email_address' => $details['email_address'],
                        'status'        => 'subscribed'
                    ]);

                    $subscriber_hash = $MailChimp->subscriberHash($details['email_address']);
                    $update_result = $MailChimp->patch("lists/$list_uid/members/$subscriber_hash", [
                        'merge_fields' => [
                            'FNAME'    => $details['first_name'],
                            'LNAME'    => $details['last_name'],
                            'GENDER'   => $details['gender'],
                            'LOCATION' => $details['location'],
                            'AGE'      => $details['age'],
                            'DOB'      => $details['dob']
                        ]
                    ]);
                }
            }
            catch (Exception $e) {
                error_log('Caught general exception in private function submitIdentityToMailinglist(): ' . $e->getMessage());
            }
        }
        else {
            error_log('This venue: ' . $venue . ' doesn\'t have a mail type');
        }
    }

    private function connectController($venue)
    {
        /**
         * we use credentials from the controller object
         */
        $unifidata = new UnifiController($venue->venue_wifi->controller->user_name, $venue->venue_wifi->controller->password, $venue->venue_wifi->controller->url, $venue->venue_wifi->controller_venue_id, $venue->venue_wifi->controller->version, $this->ci);
        $loginresults = $unifidata->login();

        /**
         * if we have an error we need to stop
         */
        if ($loginresults !== true) {
            return $response->withJson([], 400);
        }

        return $unifidata;
    }
}