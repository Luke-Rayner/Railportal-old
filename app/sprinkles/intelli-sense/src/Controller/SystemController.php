<?php

namespace UserFrosting\Sprinkle\IntelliSense\Controller;

use Carbon\Carbon;
use DateTimeZone;
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

use UserFrosting\Sprinkle\ElephantWifi\Controller\UnifiController;

use UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\SiteConfiguration;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\Event;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\EventCategory;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\ExtendedUser;

use UserFrosting\Sprinkle\GeoSense\Database\Models\ProbeRequest;
use UserFrosting\Sprinkle\GeoSense\Database\Models\Whitelist;
use UserFrosting\Sprinkle\GeoSense\Database\Models\WhitelistDeviceVendor;
use UserFrosting\Sprinkle\GeoSense\Database\Models\Drone;

use UserFrosting\Sprinkle\ElephantWifi\Database\Models\Device;
use UserFrosting\Sprinkle\ElephantWifi\Database\Models\Session;
use UserFrosting\Sprinkle\ElephantWifi\Database\Models\Identity;
use UserFrosting\Sprinkle\ElephantWifi\Database\Models\VenueWifi;
use UserFrosting\Sprinkle\ElephantWifi\Database\Models\AccessPoint;
use UserFrosting\Sprinkle\ElephantWifi\Database\Models\ApConfig;
use UserFrosting\Sprinkle\ElephantWifi\Database\Models\ApRadio;
use UserFrosting\Sprinkle\ElephantWifi\Database\Models\WifiClientConnection;

use UserFrosting\Sprinkle\EnviroSense\Database\Models\EnviroSensor;

/**
 * SystemController Class
 *
 * @package IntelliSense
 * @author Luke Rayner/ElephantWiFi
 * @link http://www.elephantwifi.co.uk
 */
class SystemController extends SimpleController 
{
    public function processCronRequest(Request $request, Response $response, $args)
    {
        $cron_id = $args['cron_id'];
        $secret_key = $args['secret_key'];

        /**
         * first thing to do is check the key
         * if valid continue, if not stop the process
         */
        $fetch_settings = new SiteConfiguration;
        $secret_key_setting = $fetch_settings->where('plugin', 'cron')
            ->where('name', 'cron_public_key')
            ->first()->value;

        if ($secret_key !== $secret_key_setting){
            throw new ForbiddenException();
        }

        /**
         * log some relevant information
         */
        error_log('===========================================================================');
        error_log('EXECUTING CRON JOBS');
        error_log('Secret key: ' . $secret_key);
        error_log('Cron_id: ' . $cron_id);
        error_log('Address local: ' . $_SERVER['SERVER_ADDR']);
        error_log('Address remote: ' . $_SERVER['REMOTE_ADDR']);
        error_log('===========================================================================');

        /**
         * then switch on the cron_id/type and execute the respective private function
         */
        switch ($cron_id) {
            case 'daily':
                /**
                 * do something daily
                 */
                echo 'run our daily job(s)' . PHP_EOL;
                //
                $this->deleteExpiredWifiUser();
                $this->whitelistDevices();
                break;
            case '12hours':
                /**
                 * do something every 12 hours
                 */
                echo 'run our job(s) every 12 hours' . PHP_EOL;
                //
                // $this->updateBankHolidays();
                break;
            case '60mins':
                /**
                 * do something every 60 minutes
                 */
                echo 'run our hourly job(s)' . PHP_EOL;
                //
                $this->accessPointCheckCronJob();
                break;
            case '30mins':
                /**
                 * do something every 30 minutes
                 */
                echo 'run our job(s) every 30 minutes' . PHP_EOL;
                //
                break;
            case '5mins':
                /**
                 * do something every 5 minutes
                 */
                echo 'run our job(s) every 5 minutes' . PHP_EOL;
                //
                $this->wifiClientConnectionCronJob();
                $this->droneCheckCronJob();
                $this->updateEnviroSensorStatus();
                break;
            default:
                /**
                 * you have no business here since the cron type supplied is not a valid one; we abort the session
                 */
                error_log('We received an incorrect $cron_id value! Session for cron job request was aborted.');
                $this->_app->halt(403);
        }
    }

    private function whitelistDevices()
    {
        /**
         * Get all geo-sense venues
         */
        $venues = Venue::where('tracking_venue', 1)->get();

        /**
         * Get whitelist threshold
         */
        $whitelist_threshold = SiteConfiguration::where('name', 'whitelist_threshold')->first();

        foreach ($venues as $venue) {
            /**
             * get the user's timezone
             * - check across past 24 hours
             */
            $timezone = $venue->time_zone;
            $start = Carbon::now($timezone)->subDays(1)->format('U');
            $end = Carbon::now($timezone)->format('U');

            /**
             * Get unfiltered, unsorted, unpaginated collection
             */
            $whitelistCandidateQuery = new ProbeRequest;

            /**
             * Get whitelist candidates filtered by the primary_venue_id of logged in user
             * - observations over the past 24 hours
             */
            $whitelist_candidate_collection = $whitelistCandidateQuery->selectRaw('COUNT(id) AS probe_count, device_uuid, device_vendor_id')
                ->with('device_vendor')
                ->where('venue_id', $venue->id)
                ->whereBetween('ts', [$start, $end])
                ->groupBy('device_uuid')
                ->orderBy('probe_count', 'desc')
                ->take(20)
                ->get();

            $whitelist_candidate_collection_filtered = [];

            foreach ($whitelist_candidate_collection as $candidate) {
                /**
                 * here we check whether the candidate already exists in the whitelist table
                 */
                $device_whitelisted = Whitelist::whereRaw('device_uuid = UNHEX("' . $candidate->device_uuid . '")')->first();

                if (empty($device_whitelisted)) {
                    $whitelist_candidate_collection_filtered[] = $candidate;
                }
            }

            // Get all the whitelisted device vendors
            $whitelisted_device_vendors = WhitelistDeviceVendor::get();

            /**
             * create an array containing the id's of the whitelisted_device_vendors
            */
            $whitelisted_device_vendors_ids = array();
            foreach ($whitelisted_device_vendors as $whitelisted_device_vendor) {
                $whitelisted_device_vendors_ids[] = $whitelisted_device_vendor->device_vendor_id;
            }

            /**
             * If the device is over the count threshold whitelist it
             */
            foreach ($whitelist_candidate_collection_filtered as $device) {
                if ($device->probe_count > $whitelist_threshold->value || in_array($device->device_vendor_id, $whitelisted_device_vendors_ids)) {

                    // Create the mac whitelist object
                    $whitelist = new Whitelist();

                    $whitelist['venue_id'] = $venue->id;
                    $whitelist['label'] = $device->device_uuid;
                    $whitelist['mac'] = '';
                    $whitelist['device_vendor_id'] = $device->device_vendor_id;
                    $whitelist['device_uuid'] = $device->device_uuid;
                    $whitelist['whitelist'] = 1;

                    $whitelist->save();
                }
            } 
        }
    }

    private function updateEnviroSensorStatus()
    {
        $curl = curl_init();
        $headers = array(
            'X-User-id: 5244', 
            'X-User-hash:d4d75193d828decdbc532487322a58df',
        );
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_URL, "https://data.uradmonitor.com/api/v1/devices");
        $resp = curl_exec($curl);
        curl_close($curl);

        $api_data = json_decode($resp, true);

        foreach($api_data as $data) {
            $enviro_sensor = EnviroSensor::where('serial_id', $data['id'])->first();

            if (!empty($enviro_sensor)) {
                $enviro_sensor->update(['status' => $data['status'], 'versionsw' => $data['versionsw'], 'versionhw' => $data['versionhw']]);
            }
        }
    }

    private function deleteExpiredWifiUser()
    {
        $now = Carbon::now();

        /**
         * Get all the devices that havent been used for 14 months
         */
        $devices_to_delete = Device::where('last_seen', '<=', $now->subMonths(14)->timestamp)->get();
        $device_ids = [];
        $mac_addresses = [];
        foreach ($devices_to_delete as $device_to_delete) {
            array_push($device_ids, $device_to_delete->id);
            array_push($mac_addresses, $device_to_delete['mac_address']);
            $device_to_delete->delete();
        }

        /**
         * Get all the sessions linked to the deleted devices
         */
        $sessions = Session::whereIn('device_id', $device_ids)->get();
        $identity_ids = [];
        foreach ($sessions as $session) {
            array_push($identity_ids, $session->identity_id);
            $session->delete();
        }

        /**
         * Get all users linked to the identities
         * Delete all the identities
         */
        $identities_to_delete = Identity::whereIn('id', $identity_ids)->get();
        $user_ids = [];
        foreach ($identities_to_delete as $identity_to_delete) {
            array_push($user_ids, $identity_to_delete->user_id);
            $identity_to_delete->delete();
        }

        $users = ExtendedUser::whereIn('users_id', $user_ids)->get();
        foreach ($users as $user) {
            $venues = $user->getWifiUserVenueIds();
            foreach ($venues as $venue) {
                $unifidata = $this->connectControllerSingleVenue($venue);
                foreach ($mac_addresses as $mac_address) {
                    $unauth_device = $unifidata->unauthorize_guest($mac_address);
                }
            }

            // Delete user if they are only in wifi_user group
            if( in_array(3, $user->getRoleIds()) && count($user->getRoleIds()) <= 1 ) {
                $user->delete();
            }

            // If the users primary venue is venue user group change it to portal user group
            // and remove wifi group and detach wifi user venues
            else if($user->primary_group_id == 3) {
                $user->removeRole(3);
                $user->wifiUserVenues()->detach();

                $user->primary_group_id = 1;
                $user->save();
            }
            // Delete wifi_user group from the user and detach wifi user venues
            else {
                $user->removeRole(3);
                $user->wifiUserVenues()->detach();
                $user->save();
            }
        }
    }

    private function accessPointCheckCronJob()
    {
        /**
         * Get a list of all the available controllers
         */
        $unifidataArray = $this->connectController();

        // Initialise empty array
        $list_aps = array();

        /**
         * Loop through each venue and get the APs that are linked to them
         */
        foreach($unifidataArray as $unifidata) {
            $access_points = $unifidata->list_aps();

            print_r($access_points);

            if (!empty($access_points)) {
                foreach($access_points as $access_point) {
                    array_push($list_aps, $access_point);
                }
            }
        }

        foreach ($list_aps as $access_point) {
            // find access_point in table
            if (!isset($access_point->_id) || !isset($access_point->serial)) {
                continue;
            }
            $ap = AccessPoint::where('ap_uuid', $access_point->_id)->first();

            // check if access_point exists
            if (empty($ap)) {

                if (empty($access_point->board_rev))
                    $access_point->board_rev = null;

                $ap = new AccessPoint([
                    'zone_id' => 0, 
                    'ap_uuid' => $access_point->_id, 
                    'mac' => $access_point->mac, 
                    'state' => $access_point->state, 
                    'model' => $access_point->model,
                    'serial' => $access_point->serial,
                    'board_rev' => $access_point->board_rev,
                ]);

                $ap->save();

                if (empty($access_point->_uptime))
                    $access_point->_uptime = null;

                $ap_config = new ApConfig([
                    'name'             => $access_point->name,
                    'firmware_version' => $access_point->version,
                    'ip_address'       => $access_point->ip,
                    'uptime'           => $access_point->_uptime
                ]);

                $ap->ap_configs()->save($ap_config);

                if (!empty($access_point->radio_table)) {
                    foreach($access_point->radio_table as $radio) {
                        $this->checkRadios($radio);

                        $ap_radio = new ApRadio([
                            'radio'          => $radio->radio,
                            'tx_power_mode'  => $radio->tx_power_mode,
                            'tx_power'       => $radio->tx_power,
                            'channel'        => $radio->channel
                        ]);

                        $ap_config->ap_radios()->save($ap_radio);
                    }
                }
            }
            else {
                // check if the state of the AP has changed
                if ($ap->state != $access_point->state) {
                    $ap->state = $access_point->state;
                    $ap->save();
                }

                // check if any of the values in the ap_config / ap_radio table have changed
                $ap_config_table = $ap->lastApConfig();

                if (empty($ap_config_table) || $ap_config_table->name != $access_point->name || $ap_config_table->firmware_version != $access_point->version || $ap_config_table->ip_address != $access_point->ip) {

                    if (empty($access_point->_uptime))
                        $access_point->_uptime = null;

                    if (!isset($access_point->name)) {
                        $access_point->name = 'NO NAME SET';
                    }

                    $ap_config = new ApConfig([
                        'name' => $access_point->name,
                        'firmware_version' => $access_point->version,
                        'ip_address' => $access_point->ip,
                        'uptime' => $access_point->_uptime
                    ]);

                    $ap->ap_configs()->save($ap_config);

                    if (!empty($access_point->radio_table)) {
                        foreach($access_point->radio_table as $radio) {
                            $this->checkRadios($radio);

                            $ap_radio = new ApRadio([
                                'radio' => $radio->radio,
                                'tx_power_mode' => $radio->tx_power_mode,
                                'tx_power' => $radio->tx_power,
                                'channel' => $radio->channel
                            ]);

                            $ap_config->ap_radios()->save($ap_radio);
                        }
                    }
                }

                if (!empty($ap_config_table)) {
                    /**
                     * count the number of radios for this config we have stored in our db
                     * - used in the if statement below
                     */
                    if (!empty($access_point->radio_table)) {
                        $ap_radio_count = count(ApRadio::where('ap_config_id', $ap_config_table->id)->get());

                        foreach($access_point->radio_table as $radio) {
                            $this->checkRadios($radio);

                            $ap_radio_table = ApRadio::where('ap_config_id', $ap_config_table->id)->where('radio', $radio->radio)->first();

                            if (!empty($ap_radio_table)) {
                                if ($ap_radio_table->radio != $radio->radio                 || 
                                    $ap_radio_table->tx_power_mode != $radio->tx_power_mode || 
                                    $ap_radio_table->channel != $radio->channel             || 
                                    $ap_radio_table->tx_power != $radio->tx_power           || 
                                    $ap_radio_count != count($access_point->radio_table)) 
                                {

                                    $ap_config = new ApConfig([
                                        'name' => $access_point->name,
                                        'firmware_version' => $access_point->version,
                                        'ip_address' => $access_point->ip,
                                        'uptime' => $access_point->_uptime
                                    ]);

                                    $ap->ap_configs()->save($ap_config);

                                    foreach($access_point->radio_table as $radio) {
                                        $this->checkRadios($radio);

                                        $ap_radio = new ApRadio([
                                            'radio' => $radio->radio,
                                            'tx_power_mode' => $radio->tx_power_mode,
                                            'tx_power' => $radio->tx_power,
                                            'channel' => $radio->channel
                                        ]);

                                        $ap_config->ap_radios()->save($ap_radio);
                                    }

                                    /**
                                     * If one of the radios is different it creates a new ap_config and 2 ap_radios 
                                     * If this happens on the first check we don't want to loop through again 
                                     * - If we looped through again we would create duplicate data
                                     */
                                    break;
                                }
                            }
                            else {
                                $ap_config = new ApConfig([
                                    'name' => $access_point->name,
                                    'firmware_version' => $access_point->version,
                                    'ip_address' => $access_point->ip,
                                    'uptime' => $access_point->_uptime
                                ]);

                                $ap->ap_configs()->save($ap_config);

                                foreach($access_point->radio_table as $radio) {
                                    $this->checkRadios($radio);

                                    $ap_radio = new ApRadio([
                                        'radio' => $radio->radio,
                                        'tx_power_mode' => $radio->tx_power_mode,
                                        'tx_power' => $radio->tx_power,
                                        'channel' => $radio->channel
                                    ]);

                                    $ap_config->ap_radios()->save($ap_radio);
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    private function wifiClientConnectionCronJob()
    {
        $now = Carbon::now('Europe/London')->second(0)->timestamp;
        /**
         * Get a list of all the available controllers
         */
        $unifidataArray = $this->connectController();

        // Initialise empty arrays
        $list_clients = array();

        /**
         * Loop through each venue and get the guests that belong to them
         */
        foreach($unifidataArray as $unifidata) {
            $clients = $unifidata->list_clients();

            /**
             * Check to see if any clients are currently online
             */
            if (!empty($clients)) {
                foreach($clients as $client) {
                    if (!empty($client->ap_mac))
                        $list_clients[] = $client;
                }
            }
        }

        /**
         * Check to see if any clients are in the $list_client array
         */
        if (!empty($list_clients)) {

            /**
             * Loop through each online client and insert them into wifi_client_connection
             */
            foreach($list_clients as $client) {

                if (!isset($client->authorized)) {
                    continue;
                }

                /**
                 * Get access_point / zone data in order to retrieve ids for wifi_client_connection table
                 */
                $access_point = AccessPoint::with('zone')->where('mac', $client->ap_mac)->first();

                if ($client->authorized == false) {
                    $client->authorized = 0;
                }
                else {
                    $client->authorized = 1;
                }

                if (isset($access_point) && isset($access_point->zone)) {
                    $venue_id = $access_point->zone->venue_id;
                    $access_point_id = $access_point->id;

                    // convert mac address into device_uuid
                    $device_uuid = hex2bin(md5($client->mac . $venue_id));

                    /**
                     * create new entry in database if access_point doesnt exist
                     */
                    WifiClientConnection::create(array(
                        'ts' => $now,
                        'device_uuid' => $device_uuid,
                        'access_point_id' => $access_point_id,
                        'venue_id' => $venue_id,
                        'authorised' => $client->authorized
                    ));
                }
            }
        }
    }

    private function droneCheckCronJob()
    {
    	/** @var \UserFrosting\Support\Repository\Repository $config */
        $config = $this->ci->config;
    	
        $droneQuery = new Drone;

        /**
         * Get unfiltered, unsorted, unpaginated collection
         * ->with('zone') for eager loading of the zone data
         */
        $drone_collection = $droneQuery->with('zone', 'zone.venue', 'last_activity')->get();

        /**
         * get the drones array
         */
        $drone_collection_processed = array();
        foreach ($drone_collection as $drone){
            if ($drone->state == 1) {
                /**
                 * get the last health message
                 */
                $last_health_message = $drone->lastHealthMessage();

                /**
                 * if found, push the last health message details to the drone object
                 */
                if($last_health_message != NULL) {
                    $drone['last_health_message'] = $last_health_message;
                }

                $drone_collection_processed[] = $drone;
            }
        }

        $total_processed = count($drone_collection_processed);

        /**
         * get the threshold values from the settings table
         */
        $fetch_settings = new SiteConfiguration;
        $drone_quiet_threshold = $fetch_settings->where('plugin', 'cron')
            ->where('name', 'drone_quiet_threshold')
            ->first()->value;

        $drone_offline_threshold = $fetch_settings->where('plugin', 'cron')
            ->where('name', 'drone_offline_threshold')
            ->first()->value;

        echo PHP_EOL;
        echo 'DRONES CHECKS' . PHP_EOL;
        echo 'Total number of active drones: ' . $total_processed . PHP_EOL;
        echo '--------------------------------------------------------------------' . PHP_EOL;
        echo 'state    ID name (last probe request)' . PHP_EOL;
        echo '--------------------------------------------------------------------' . PHP_EOL;

        /**
         * process each drone in the collection
         * TODO:
         * give drones with low uptime values (recently rebooted) time to get up to speed
         */
        foreach ($drone_collection_processed as $drone) {
            /**
             * filter on active drones only
             */
            if ($drone->state == 1) {
                if (isset($drone->last_activity->timestamp)){
                    $now = Carbon::now()->format('U');

                    if ($now - $drone->last_activity->timestamp < $drone_quiet_threshold) {
                        echo 'online:   ' . $drone->id . ' ' . $drone->name . ' (' . max(($now - $drone->last_activity->timestamp),0) . ' secs ago)' . PHP_EOL;
                    } elseif ($now - $drone->last_activity->timestamp < $drone_offline_threshold) {
                        echo 'quiet:    ' . $drone->id . ' ' . $drone->name . ' ('  . max(($now - $drone->last_activity->timestamp),0) . ' secs ago)' . PHP_EOL;
                    } else {
                        /**
                         * we have a drone that appears to be offline so we request a reboot
                         * we also need to unset the attributes we had attached earlier on for ease-of-use
                         *
                         * TODO:
                         * - add an option here to send an email with diagnostics data such as:
                         *     - latest temperature
                         *    - CPU load
                         *    - number of probe requests in last 15 minutes
                         *    - etc.
                         */
                        echo 'OFFLINE:  ' . $drone->id . ' ' . $drone->name . ', requesting a reboot!' . PHP_EOL;

                        $drone->execute_command = 'reboot';
                        unset($drone->last_activity);
                        unset($drone->last_health_message);
                        $drone->save();
                    }
                }
            }
        }

        echo PHP_EOL;
    }

    private function updateBankHolidays()
    {
        //  Initiate curl
        $ch = curl_init();
        // Will return the response, if false it print the response
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // Set the url
        curl_setopt($ch, CURLOPT_URL, 'https://www.gov.uk/bank-holidays.json');
        // Execute
        $result=curl_exec($ch);
        // Closing
        curl_close($ch);

        // Will dump a beauty json :3
        $bank_holidays = json_decode($result, true);

        $venues = Venue::get();

        $event_category = EventCategory::where('name', 'UK Holiday')->first();

        foreach($venues as $venue) {
            foreach($bank_holidays['england-and-wales']['events'] as $bank_holiday) {
                $event = new Event();
                $event->name = $bank_holiday['title'];
                $event->notes = $bank_holiday['notes'];
                $event->venue_id = $venue->id;
                $event->event_category_id = $event_category->id;
                $event->start_date = Carbon::parse($bank_holiday['date'])->timestamp;
                $event->end_date = Carbon::parse($bank_holiday['date'])->timestamp;
                $event->recurring = 0;
                $event->admin_event = 0;
                $event->can_delete = 0;
                $event->save();
            }
        }
    }

    private function connectControllerSingleVenue($venue)
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
        $controllerversion = $venue_details->controller->version;
        $venueid = $venue_details->controller_venue_id;
        $unifidata = new UnifiController($controlleruser, $controllerpassword, $controllerurl, $venueid, $controllerversion, $this->ci);

        $loginresults = $unifidata->login();

        /**
         * if we have an error we need to stop, else carry on
         */
        if ($loginresults !== true) {
            $unifidata->is_loggedin = false;
            throw new ForbiddenException();
        } else {
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

    private function connectController(){
        /**
         * Get a list of all the available venues
         */
        $venueQuery = new VenueWifi;
        $venue_details = $venueQuery->with('controller')->get();

        $unifidataArray = array();

        /**
         * Loop through each venue and get the APs that are linked to them
         */
        foreach($venue_details as $venue) {
            $controlleruser = $venue->controller->user_name;
            $controllerpassword = $venue->controller->password;
            $controllerurl = $venue->controller->url;
            $controllerversion = $venue->controller->version;
            $venueid = $venue->controller_venue_id;
            $unifidata = new UnifiController($controlleruser, $controllerpassword, $controllerurl, $venueid, $controllerversion, $this->ci);

            $loginresults = $unifidata->login();

            /**
             * if we have an error we need to stop, else carry on
             */
            if ($loginresults !== true) {
                $unifidata->is_loggedin = false;
                throw new ForbiddenException();
            } else {
                $unifidata->is_loggedin = true;
                /**
                 * check when controller version was last updated,
                 * if longer than 60*60*24*7 seconds (7 days) ago, we fetch version and update the controller object
                 */
                if ((time() - $venue->controller->version_last_check) > 60*60*24*7) {
                    /**
                     * we need to update the controller version
                     */
                    $sys_info = $unifidata->stat_sysinfo();
                    if (isset($sys_info[0]->version)) {
                        /**
                         * we received a controller version, now store it, also update the value of version_last_check
                         */
                        $venue->controller->update(['version' => $sys_info[0]->version, 'version_last_check' => time()]);
                    }
                }
            }

            $unifidataArray[] = $unifidata;
        }

        return $unifidataArray;
    }

    private function checkRadios($radio)
    {
        $radio->tx_power = !empty($radio->tx_power) ? $radio->tx_power : null;
        $radio->tx_power_mode = !empty($radio->tx_power_mode) ? $radio->tx_power_mode : null;
        $radio->channel = !empty($radio->channel) ? $radio->channel : null;
        $radio->radio = !empty($radio->radio) ? $radio->radio : null;

        return $radio;
    }
}