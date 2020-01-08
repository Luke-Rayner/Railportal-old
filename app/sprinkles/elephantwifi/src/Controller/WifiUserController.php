<?php

namespace UserFrosting\Sprinkle\ElephantWifi\Controller;

use Carbon\Carbon;
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

use UserFrosting\Sprinkle\ElephantWifi\Database\Models\Identity;
use UserFrosting\Sprinkle\ElephantWifi\Database\Models\MarketingList;
use UserFrosting\Sprinkle\ElephantWifi\Database\Models\Session;
use UserFrosting\Sprinkle\ElephantWifi\Database\Models\Device;
use UserFrosting\Sprinkle\ElephantWifi\Database\Models\VenueWifi;

use UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue;

/**
 * WifiUserController Class
 *
 * @package ElephantWifi
 * @author Luke Rayner/ElephantWiFi
 * @link http://www.elephantwifi.co.uk
 */
class WifiUserController extends SimpleController
{
    /**
     * Generate the page to display a form containing the main sites settings
     */
    public function showWifiUserDashboard(Request $request, Response $response, $args)
    {
        // Get the alert message stream
        $ms = $this->ci->alerts;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_user')) {
            throw new NotFoundException($request, $response);
        }

        // Load validation rules
        $schema = new RequestSchema('schema://requests/identity-registration.yaml');
        $validator = new JqueryValidationAdapter($schema, $this->ci->translator);

        /**
         * find the logged in users details that we hold
         */
        $identities = Identity::with('marketing_lists.marketing_list_type')->where('user_id', $currentUser->id)->orderBy('created_at', 'DESC')->groupBy('user_id')->get();

        /**
         * redefine these properties to make them human readable
         */
        foreach ($identities as $identity) {
            $identity['age'] = Carbon::createFromTimestamp($identity->birth_date)->age;
        }

        return $this->ci->view->render($response, 'pages/elephantwifi/wifi_user/dashboard.html.twig', [
            'validators' => $validator->rules('json', true),
            'identities' => $identities
        ]);
    }

    /**
     * Generate splash page confirming data has been deleted
     */
    public function showWifiUserSplash(Request $request, Response $response, $args)
    {
        return $this->ci->view->render($response, 'pages/elephantwifi/wifi_user/GDPR_deleted_splash.html.twig');
    }

    /**
     * Update the wifi_user marketing consent information
     */
    public function updateWifiUser(Request $request, Response $response, $args)
    {
        // Get POST parameters
        $params = $request->getParsedBody();

        /**
         * Get the marketing lists opt-in/out data
         */
        if(isset($params['marketing_consent'])) {
            $marketing_consent_array = [];
            foreach ($params['marketing_consent'] as $marketing_list_id => $marketing_consent) {
                array_push($marketing_consent_array, [$marketing_list_id, $marketing_consent]);
            }
        }

        // Get the alert message stream
        $ms = $this->ci->alerts;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Load validation rules
        $schema = new RequestSchema('schema://requests/identity-registration.yaml');

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_user')) {
            throw new NotFoundException($request, $response);
        }

        // Whitelist and set parameter defaults
        $transformer = new RequestDataTransformer($schema);
        $data = $transformer->transform($params);

        // Validate, and halt on validation errors.  Failed validation attempts do not count towards throttling limit.
        $validator = new ServerSideValidator($schema, $this->ci->translator);
        if (!$validator->validate($data)) {
            $ms->addValidationErrors($validator);

            return $response->withJson([], 400);
        }

        $identity = Identity::where('id', $data['identity_id'])->first();
        $venue = Venue::with('venue_wifi')->where('id', $data['venue_id'])->first();

        /**
         * submit the email address to the mailing list, if available and if so configured
         */
        foreach ($marketing_consent_array as $marketing_consent) {
            if ($marketing_consent[1] == 0) {
                // Get the marketing list
                $marketing_list = MarketingList::where('id', $marketing_consent[0])->first();

                // $unsubscribeUser = $this->deleteIdentityFromMailinglist($identity['email_address'], $venue, $marketing_list->mail_type, $marketing_list->list_uid);

                // if ($unsubscribeUser) {
                //     $identity->marketing_lists()->wherePivot('marketing_list_id', $marketing_consent[0])->detach();
                // }
            }
        }

        // send message back to the user
        $ms->addMessageTranslated('success', 'User was successfully updated');

        return $response->withJson([], 200);
    }

    /**
     * Delete the wifi_user information
     */
    public function deleteWifiUser(Request $request, Response $response, $args)
    {
        // Get the alert message stream
        $ms = $this->ci->alerts;

        /** @var \UserFrosting\Support\Repository\Repository $config */
        $config = $this->ci->config;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_user')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * get the identities to be deleted
         */
        $identities = Identity::where('user_id', $currentUser->id)->get();

        $identities_to_delete = Identity::where('user_id', $currentUser->id)->get();
        $identity_ids = [];
        foreach ($identities_to_delete as $identity_to_delete) {
            array_push($identity_ids, $identity_to_delete->id);
            $identity_to_delete->delete();
        }

        $sessions = Session::whereIn('identity_id', $identity_ids)->get();
        $device_ids = [];
        foreach ($sessions as $session) {
            array_push($device_ids, $session->device_id);
        }

        $sessions_to_delete = Session::whereIn('device_id', $device_ids)->get();
        foreach ($sessions_to_delete as $session) {
            $session->delete();
        }

        $devices_to_delete = Device::whereIn('id', $device_ids)->get();
        $mac_addresses = [];
        foreach($devices_to_delete as $device) {
            $mac = $device['mac_address'];
            array_push($mac_addresses, $mac);
            $device->delete();
        }

        $venues = $currentUser->getWifiUserVenueIds();
        foreach ($venues as $venue) {
            $unifidata = $this->connectController($venue);
            foreach ($mac_addresses as $mac_address) {
                $unauth_device = $unifidata->unauthorize_guest($mac_address);
            }
        }

        // Destroy the session
        // log the user out before we delete their uf user
        $this->ci->authenticator->logout();

        // Delete user if they are only in wifi_user group
        if(in_array(3, $currentUser->getRoleIds()) && count($currentUser->getRoleIds()) <= 1 ) {
            $currentUser->delete();
        }
        // If the users primary venue is venue user group change it to portal user group
        // and remove wifi group and detach wifi user venues
        else if($currentUser->group_id == 3) {
            $roleIds = $currentUser->getRoleIds();

            if(in_array(3, $roleIds)) {
                // Remove the wifi_user role
                if (($key = array_search(3, $roleIds)) !== false) {
                    unset($roleIds[$key]);
                }
                $currentUser->roles()->sync($roleIds);
                $currentUser->save();
            }

            $currentUser->wifiUserVenues()->detach();

            $currentUser->group_id = 1;
            $currentUser->save();
        }
        // Delete wifi_user group from the user and detach wifi user venues
        else {
            $roleIds = $currentUser->getRoleIds();

            if(in_array(3, $roleIds)) {
                // Remove the wifi_user role
                if (($key = array_search(3, $roleIds)) !== false) {
                    unset($roleIds[$key]);
                }
                $currentUser->roles()->sync($roleIds);
                $currentUser->save();
            }
            
            $currentUser->wifiUserVenues()->detach();
            $currentUser->save();
        }

        return $response->withJson([], 200);
    }

    /**
     * private function to create a connection with the UniFi controller for the current user
     *
     * TODO:
     * - add cookie expiry timeout check
     */
    private function connectController($venue)
    {
        /**
         * Get the login credentials for primary_venue_id of logged in user
         */
        $venueQuery = new VenueWifi;
        $venue_details = $venueQuery->with('controller')->where('venue_id', $venue)->first();

        /**
         * we use credentials from the controller object
         */
        $controlleruser = $venue_details->controller->user_name;
        $controllerpassword = $venue_details->controller->password;
        $controllerurl = $venue_details->controller->url;
        $controllerversion  = $venue_details->controller->version;
        $venueid = $venue_details->controller_venue_id;
        $unifidata = new UnifiController($controlleruser, $controllerpassword, $controllerurl, $venueid, $controllerversion, $this->ci);

        $loginresults = $unifidata->login();

        /**
         * if we have an error we need to stop, else carry on
         */
        if ($loginresults !== true) {
            $unifidata->is_loggedin = false;
            return $response->withJson([], 400);
        } 
        else {
            $unifidata->is_loggedin = true;
            /**
             * check when controller version was last updated,
             * if longer than 60*60*24*7 seconds (7 days) ago, we fetch version and update the controller object
             */
            if ((time() - $venue_details->controller->version_last_check) > 60*60*24*7) {
                /**
                 * we need to update the controller version
                 */
                $sys_info = $unifidata->stat_sysinfo();
                if (isset($sys_info[0]->version)) {
                    /**
                     * we received a controller version, now store it, also update the value of version_last_check
                     */
                    $venue_details->controller->update(['version' => $sys_info[0]->version, 'version_last_check' => time()]);
                }
            }
        }

        return $unifidata;
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

    // /**
    //  * private function to delete identity email address from mailinglist
    //  * if so configured
    //  */
    // private function deleteIdentityFromMailinglist($email, $venue, $mail_type, $marketing_list_uid){
    //     if ($mail_type == 'mailwizz') {
    //         try {
    //             $config = new MailWizzConfig([
    //                 'apiUrl'        => 'http://marketing.elephantwifi.co.uk/api/index.php/',
    //                 'publicKey'     => $venue->venue_wifi['marketing_public_key'],
    //                 'privateKey'    => $venue->venue_wifi['marketing_private_key'],
    //                 'components' => array(
    //                     'cache' => array(
    //                         'class'     => MailWizzFile::class,
    //                         'filesPath' => dirname(__FILE__) . '/../MailWizzApi/Cache/data/cache', // make sure it is writable by webserver
    //                     )
    //                 ),
    //             ]);
    //             MailWizzBase::setConfig($config);

    //             $endpoint = new MailWizzListSubscribers();

    //             $response = $endpoint->deleteByEmail($marketing_list_uid, $email);

    //             return true;
    //         } 
    //         catch (Exception $e) {
    //             error_log('Caught general exception in private function deleteIdentityToMailinglist(): ' . $e->getMessage());
    //             return false;
    //         }
    //     }
    //     else if ($mail_type == 'mailchimp') {
    //         try {
    //             $MailChimp = new MailChimp($venue->venue_wifi['marketing_public_key']);

    //             $subscriber_hash = $MailChimp->subscriberHash($email);
    //             $update_result = $MailChimp->patch("lists/$marketing_list_uid/members/$subscriber_hash", [
    //                 "status" => "unsubscribed"
    //             ]);

    //             return true;
    //         }
    //         catch (Exception $e) {
    //             error_log('Caught general exception in private function deleteIdentityFromMailinglist(): ' . $e->getMessage());
    //             return false;
    //         }
    //     }
    //     else {
    //         error_log('This marketing list: ' . $marketing_list_uid . ' doesn\'t have a mail type');
    //         return false;
    //     }
    // }
}