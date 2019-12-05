<?php

namespace UserFrosting\Sprinkle\IntelliSense\Controller;

use Carbon\Carbon;
use DateTimeZone;
use WtcApiClient\WtcApiClient;
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

use UserFrosting\Sprinkle\IntelliSense\Database\Models\Category;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\Country;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\Region;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\Area;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\SubCategory;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\Tag;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\ExtendedUser;

use UserFrosting\Sprinkle\ElephantWifi\Database\Models\VenueWifi;
use UserFrosting\Sprinkle\ElephantWifi\Database\Models\Controller;

use UserFrosting\Sprinkle\GeoSense\Database\Models\VenueTracking;

require_once('/var/www/intelli_sense/app/local-packages/fineuploader/php-traditional-server/handler.php');

/**
 * VenueController Class
 *
 * @package IntelliSense
 * @author Luke Rayner/ElephantWiFi
 * @link http://www.elephantwifi.co.uk
 */
class VenueController extends SimpleController 
{
    /**
     * Specify the list of valid extensions, ex. array("jpeg", "xml", "bmp")
     * together with some other variables as required.
     * Should be fine to keep these private...
     *
     * NOTES:
     * $uploader_inputname matches Fine Uploader's default inputName value by default
     */
    private $geo_sense_map_upload_size_limit = 150000; // in bytes
    private $geo_sense_map_upload_directory = '/var/www/intelli_sense/app/sprinkles/intelli-sense/assets/images/venue/geo-sense/maps/';

    private $chunks_directory = '/var/www/intelli_sense/app/sprinkles/intelli-sense/assets/images/upload_chunks';
    private $allowed_extensions = array('jpeg', 'jpg', 'png');
    private $uploader_inputname = 'qqfile';

    public function pageVenues(Request $request, Response $response, $args)
    {
        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_site_admin')) {
            throw new NotFoundException($request, $response);
        }

        return $this->ci->view->render($response, 'pages/admin/venues.html.twig');
    }

    public function formVenueInfo(Request $request, Response $response, $args)
    {
        // Get the alert message stream
        $ms = $this->ci->alerts;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_site_admin')) {
            throw new NotFoundException($request, $response);
        }

        $venueQuery = new Venue;
        $target_venue = $venueQuery->with('category', 'country', 'region', 'area', 'tags')->where('id', $args['venue_id'])->first();

        if ($target_venue->wifi_venue == 1) {
            $wifi_venue = VenueWifi::with('controller')->where('venue_id', $args['venue_id'])->first();

            /**
             * render the modal
             */
            return $this->ci->view->render($response, 'pages/admin/forms/venue-info-modal.html.twig', [
                'target_venue' => $target_venue,
                'wifi_venue' => $wifi_venue
            ]);
        }
        else {
            /**
             * render the modal
             */
            return $this->ci->view->render($response, 'pages/admin/forms/venue-info-modal.html.twig', [
                'target_venue' => $target_venue
            ]);
        }
    }

    public function formVenueCreate(Request $request, Response $response, $args)
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
        if (!$authorizer->checkAccess($currentUser, 'uri_site_admin')) {
            throw new NotFoundException($request, $response);
        }

        // Get HTTP GET parameters
        $params = $request->getQueryParams();

        /**
         * create an empty dummy venue with some default values
         */
        $target_venue = (object) [
            'time_zone' => 'Europe/London',
            'locale' => 'en_US',
            'capture_start' => Carbon::now('Europe/London')->timestamp
        ];

        /**
         * get the time zones for the form
         */
        $timezone_identifiers = DateTimeZone::listIdentifiers();

        /**
         * get a list of the system locales
         */
        $locale_list = $config['site.locales.available'];

        /**
         * get all categories for use in the forms
         */
        $categoryQuery = new Category;
        $category_collection = $categoryQuery->get();

        /**
         * get all countries for use in the forms
         */
        $countryQuery = new Country;
        $country_collection = $countryQuery->get();

        /**
         * get all regions for use in the forms
         */
        $regionQuery = new Region;
        $region_collection = $regionQuery->get();

        /**
         * get all areas for use in the forms
         */
        $areaQuery = new Area;
        $area_collection = $areaQuery->with('region')->get();

        /**
         * get all sub_categories
         */
        $sub_categoryQuery = new SubCategory;
        $sub_category_collection = $sub_categoryQuery->get();

        /**
         * get all tags
         */
        $tagQuery = new Tag;
        $tag_collection = $tagQuery->get();

        // Load validation rules
        $schema = new RequestSchema('schema://requests/venue-shared-create.yaml');
        $validator = new JqueryValidationAdapter($schema, $this->ci->translator);

        return $this->ci->view->render($response, 'pages/admin/forms/venue-shared-info-form.html.twig', [
            'box_id' => $params['box_id'],
            'box_title' => 'Create New Venue',
            'modal_mode' => 'new',
            'submit_button' => 'Create venue',
            'form_action' => $config['site.uri.public'] . '/admin/venue/create',
            'target_venue' => $target_venue,
            "categories" => $category_collection->values()->toArray(),
            'validators' => $validator->rules('json', true),
            'locales' => $locale_list,
            'time_zones' => $timezone_identifiers,
            "sub_categories" => $sub_category_collection->values()->toArray(),
            "countries" => $country_collection->values()->toArray(),
            "regions" => $region_collection->values()->toArray(),
            "areas" => $area_collection->values()->toArray(),
            'tags' => $tag_collection->values()->toArray()
        ]);
    }

    public function formVenueEdit(Request $request, Response $response, $args)
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
        if (!$authorizer->checkAccess($currentUser, 'uri_site_admin')) {
            throw new NotFoundException($request, $response);
        }

        $venueQuery    = new Venue;
        $current_venue = $venueQuery->where('id', $currentUser->primary_venue_id)->first();

        /**
         * get the time zones for the form
         */
        $timezone_identifiers = DateTimeZone::listIdentifiers();

        /**
         * Get the HTTP GET parameters
         */
        $params = $request->getQueryParams();

        if ($args['venue_type'] == 'wifi') {
            /**
             * Get the venue to edit
             */
            $target_venue  = $venueQuery->where('id', $args['venue_id'])->with('venue_wifi.controller')->first();

            /**
             * Get a list of shared controllers
             */
            $controllerQuery = new Controller;
            $shared_controllers = $controllerQuery->where('shared', 1)->get();

            /**
             * get a list of the system locales
             */
            $locale_list = $config['site.locales.available'];

            /**
             * get a list of all the webTitan Users
             */
            $wtc_url = "https://35.176.129.240:8443/";
            $consumer_key = "e22ed431cd60dda4359793dd2ed679f4";     //Enter your consumer_key here
            $consumer_secret = "64d1718bdb5d14ce5380b7c6d7f8947c";      //Enter your consumer_secret here
            $oauth_token = "73f1219beb6529ec3d4b6e2311db66c8";      //Enter your oauth_token here
            $oauth_token_secret = "4331810bb0f32bc787ef2d55e110065f";   //Enter your oauth_token_secret here

            $WtcApiClient = new WtcApiClient();
            $WtcApiClient->useOAuthCredentials();

            $webTitan_ids = json_decode($WtcApiClient->listCustomerAccounts());

            // Load validation rules
            $schema = new RequestSchema('schema://requests/venue-wifi-create.yaml');
            $validator = new JqueryValidationAdapter($schema, $this->ci->translator);

            /**
             * render the modal
             */
            return $this->ci->view->render($response, 'pages/admin/forms/venue-wifi-info-modal.html.twig', [
                'box_id' => $params['box_id'],
                'box_title' => 'Edit Venue',
                'modal_mode' => 'edit',
                'submit_button' => 'Update venue',
                'form_action' => $config['site.uri.public'] . '/admin/venues/update/' . $target_venue->id . '/wifi',
                'target_venue' => $target_venue,
                'validators' => $validator->rules('json', true),
                'shared_controllers'  => $shared_controllers,
                'time_zones' => $timezone_identifiers,
                'webTitan_ids' => $webTitan_ids->data
            ]);
        }

        else if ($args['venue_type'] == 'tracking') {
            $existing_geo_sense_map_files = array();

            // Get the venue to edit
            $target_venue = Venue::with('venue_tracking')->find($args['venue_id']);

            var_dump($this->geo_sense_map_upload_directory);

            var_dump(is_writable($this->geo_sense_map_upload_directory));

            /**
             * Here we collect details of already uploaded file(s) and details by scanning directories in the upload location
             * and then scanning each directory found, for image files inside them.
             *
             * Before we do anything, we check whether the root upload directories are writable, else we throw an error and render an empty page
             * - we also check in the first loop whether our found item is a directory or not
             * - we also check in the second loop whether our found item is a file or not
             *
             * private $geo_sense_map_upload_size_limit  = 150000; // in bytes
             * private $geo_sense_map_upload_directory = 'images/venue/geo-sense/maps/'; *

             * private $chunks_directory = 'images/upload_chunks';
             * private $allowed_extensions = array('jpeg', 'jpg', 'png');
             * private $uploader_inputname = 'qqfile';
             *
             */
            if (is_writable($this->geo_sense_map_upload_directory)) {
                /**
                 * first we check for map files for the selected venue_id
                 */
                $existing_geo_sense_map_files = array();
                $this->geo_sense_map_upload_directory = $this->geo_sense_map_upload_directory . $target_venue->id;

                /**
                 * if the directory for this venue does not exist, we create it
                 */
                if (!file_exists($this->geo_sense_map_upload_directory)) {
                    mkdir($this->geo_sense_map_upload_directory, 0775, true);
                }

                $found_directories = scandir($this->geo_sense_map_upload_directory);

                foreach ($found_directories as $directory) {
                    if (!in_array($directory, array(".","..")) && is_dir($this->geo_sense_map_upload_directory . DIRECTORY_SEPARATOR . $directory)) {
                        $found_files = scandir($this->geo_sense_map_upload_directory . DIRECTORY_SEPARATOR . $directory);

                        foreach ($found_files as $file) {
                            if (!in_array($file, array(".","..")) && is_file($this->geo_sense_map_upload_directory . DIRECTORY_SEPARATOR . $directory . DIRECTORY_SEPARATOR . $file)) {
                                $found_file_name = $file;
                                $found_file_size = filesize($this->geo_sense_map_upload_directory . DIRECTORY_SEPARATOR . $directory . DIRECTORY_SEPARATOR . $file);
                            }
                        }

                        $existing_geo_sense_map_files[] = array(
                            'uuid' => $directory,
                            'name' => $found_file_name,
                            'size' => $found_file_size,
                            'thumbnailUrl' => $config['site.uri.public'] . DIRECTORY_SEPARATOR
                                . $this->geo_sense_map_upload_directory . DIRECTORY_SEPARATOR
                                . $directory . DIRECTORY_SEPARATOR . $found_file_name);
                    }
                }

                // Load validation rules
                $schema = new RequestSchema('schema://requests/venue-tracking-create.yaml');
                $validator = new JqueryValidationAdapter($schema, $this->ci->translator);

                return $this->ci->view->render($response, 'pages/admin/forms/venue-geo_sense-info-modal.html.twig', [
                    "box_id" => $params['box_id'],
                    "box_title" => "Edit Venue",
                    "submit_button" => "Update venue",
                    "form_action" => $config['site.uri.public'] . '/admin/venues/update/' . $target_venue->id . '/tracking',
                    "target_venue" => $target_venue,
                    "time_zones" => $timezone_identifiers,
                    "validators" => $validator->rules('json', true),
                    'existing_geo_sense_map_files_json' => json_encode($existing_geo_sense_map_files),
                    'geo_sense_map_upload_size_limit' => $this->geo_sense_map_upload_size_limit
                ]);
            }
        }

        else if ($args['venue_type'] == 'shared') {
            // Get the venue to edit
            $target_venue = Venue::with('tags', 'category')->find($args['venue_id']);

            /**
             * get the time zones for the form
             */
            $timezone_identifiers = DateTimeZone::listIdentifiers();

            /**
             * get a list of the system locales
             */
            $locale_list = $config['site.locales.available'];

            /**
             * get all categories for use in the forms
             */
            $categoryQuery = new Category;
            $category_collection = $categoryQuery->get();

            /**
             * get all countries for use in the forms
             */
            $countryQuery = new Country;
            $country_collection = $countryQuery->get();

            /**
             * get all regions for use in the forms
             */
            $regionQuery = new Region;
            $region_collection = $regionQuery->get();

            /**
             * get all areas for use in the forms
             */
            $areaQuery = new Area;
            $area_collection = $areaQuery->with('region')->get();

            /**
             * get all sub_categories
             */
            $sub_categoryQuery = new SubCategory;
            $sub_category_collection = $sub_categoryQuery->get();

            /**
             * get all tags
             */
            $tagQuery = new Tag;
            $tag_collection = $tagQuery->get();

            // Load validation rules
            $schema = new RequestSchema('schema://requests/venue-shared-create.yaml');
            $validator = new JqueryValidationAdapter($schema, $this->ci->translator);

            return $this->ci->view->render($response, 'pages/admin/forms/venue-shared-info-form.html.twig', [
                "box_id" => $params['box_id'],
                "box_title" => "Edit Venue",
                "submit_button" => "Update venue",
                "form_action" => $config['site.uri.public'] . '/admin/venues/update/' . $target_venue->id . '/shared',
                "target_venue" => $target_venue,
                "validators" => $validator->rules('json', true),
                "categories" => $category_collection->values()->toArray(),
                'locales' => $locale_list,
                'time_zones' => $timezone_identifiers,
                "sub_categories" => $sub_category_collection->values()->toArray(),
                "countries" => $country_collection->values()->toArray(),
                "regions" => $region_collection->values()->toArray(),
                "areas" => $area_collection->values()->toArray(),
                'tags' => $tag_collection->values()->toArray()
            ]);
        }
    }

    public function addVenue(Request $request, Response $response, $args)
    {
        // Get POST parameters
        $params = $request->getParsedBody();

        // Get the alert message stream
        $ms = $this->ci->alerts;

        /** @var \UserFrosting\Support\Repository\Repository $config */
        $config = $this->ci->config;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        /**
         * here we extract and process any tags if submitted
         */
        if (isset($params['associated_tags'])) {
            $associated_tags = explode(',', $params['associated_tags']);
            unset($params['associated_tags']);
        }

        if (isset($params['sub_categories'])) {
            $sub_categories = explode(',', $params['sub_categories']);
            unset($params['sub_categories']);
        }

        // Load validation rules
        $schema = new RequestSchema('schema://requests/venue-shared-create.yaml');

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_site_admin')) {
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

        /**
         * Create the venue
         */
        $venue = new Venue();
        $venue->name = $data['name'];
        $venue->category_id = $data['category_id'];
        $venue->lat = $data['lat'];
        $venue->lon = $data['lon'];
        $venue->country_id = $data['country_id'];
        $venue->region_id = $data['region_id'];
        $venue->area_id = $data['area_id'];
        $venue->population = $data['population'];
        $venue->time_zone = $data['time_zone'];
        $venue->locale = $data['locale'];
        $venue->footfall_bucket = $data['footfall_bucket'];
        $venue->current_visitors_bucket = $data['current_visitors_bucket'];

        $venue->heatmap_min_zoom = $data['heatmap_min_zoom'];
        $venue->heatmap_max_zoom = $data['heatmap_max_zoom'];
        $venue->heatmap_init_zoom = $data['heatmap_init_zoom'];
        $venue->heatmap_radius = $data['heatmap_radius'];
        // $venue->show_stats_on_login     = $data['show_stats_on_login'];
        $venue->dt_threshold_1 = $data['dt_threshold_1'];
        $venue->dt_threshold_2 = $data['dt_threshold_2'];
        $venue->dt_threshold_3 = $data['dt_threshold_3'];
        $venue->dt_threshold_4 = $data['dt_threshold_4'];
        $venue->dt_threshold_5 = $data['dt_threshold_5'];
        $venue->dt_level_1_label = $data['dt_level_1_label'];
        $venue->dt_level_2_label = $data['dt_level_2_label'];
        $venue->dt_level_3_label = $data['dt_level_3_label'];
        $venue->dt_level_4_label = $data['dt_level_4_label'];
        $venue->dt_level_5_label = $data['dt_level_5_label'];
        $venue->dt_skipped_label = $data['dt_skipped_label'];
        $venue->sankey_max_route_length = $data['sankey_max_route_length'];
        $venue->save();

        $users = ExtendedUser::where('company_id', 21)->get();
        foreach($users as $user) {
            $venues = $user->getVenueIds();
            array_push($venues, $venue->id);
            $user->venues()->sync($venues);
        }

        /**
         * if we have received associated tags, we attach them to the new venue,
         * **after** the new venue has been created (otherwise we will get an error)
         */
        if (isset($associated_tags)) {
            $venue->tags()->sync($associated_tags);
        }

        /**
         * if we have received sub categories, we attach them to the new venue,
         * **after** the new venue has been created (otherwise we will get an error)
         */
        if (isset($sub_categories)) {
            $venue->sub_categories()->sync($sub_categories);
        }

        /**
         * Pass the venue_id back to the ajax success callback
         */
        echo json_encode($venue->id);

        /**
         * send message back to the user
         */
        $ms->addMessageTranslated('success', 'Venue was successfully created');

        return $response->withJson([], 200);
    }

    public function updateVenue(Request $request, Response $response, $args)
    {
        // Get POST parameters
        $params = $request->getParsedBody();

        // Get the alert message stream
        $ms = $this->ci->alerts;

        /** @var \UserFrosting\Support\Repository\Repository $config */
        $config = $this->ci->config;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        /**
         * here we extract and process any tags if submitted
         */
        if (isset($params['associated_tags'])) {
            $associated_tags = explode(',', $params['associated_tags']);
            unset($params['associated_tags']);
        }

        if (isset($params['sub_categories'])) {
            $sub_categories = explode(',', $params['sub_categories']);
            unset($params['sub_categories']);
        }        

        /**
         * Load the request schema
         */
        if ($args['venue_type'] == 'wifi') {
            $schema = new RequestSchema('schema://requests/venue-wifi-create.yaml');
        }
        else if ($args['venue_type'] == 'tracking') {
            $schema = new RequestSchema('schema://requests/venue-tracking-create.yaml');
        }
        else if ($args['venue_type'] == 'shared') {
            $schema = new RequestSchema('schema://requests/venue-shared-create.yaml');
        }

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_site_admin')) {
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

        /**
         * find the primary venue for the current user, get it's details and it's locale etc
         */
        $venueQuery = new Venue;
        $current_venue = $venueQuery->where('id', $args['venue_id'])->first();

        if ($args['venue_type'] == 'wifi') {
            $formatted_date = new Carbon($data['capture_start'], $current_venue['time_zone']);

            $current_wifi_venue = VenueWifi::firstOrNew([ 'venue_id' => $args['venue_id'] ]);

            /**
             * checks to perform:
             * - if controller_venue_id == default we need to create a new local_venue_id
             * - check if $venue_id and $data['id'] are equal, if not log an error
             * - also check if local_venue_id is empty or not, below is the PHP shorthand to achieve this
             */
            $data['local_venue_id'] = $data['local_venue_id'] ?: $data['controller_venue_id'];

            /**
             * if controller_venue_id has changed
             * - then we check whether it has the value of 'default'
             * - if not we assign 'local_venue_id' the same value
             * - if yes we generate a random 10 char string of letters and numbers
             */
            if ($data['controller_venue_id'] != $current_wifi_venue->controller_venue_id && $data['controller_venue_id'] == 'default') {
                /**
                 * the value of controller_venue_id has changed and controller_venue_id == default
                 * - generate a random string value for $local_venue_id
                 */
                $rnd = uniqid(rand(),true);
                $data['local_venue_id'] = substr(strtoupper(md5($rnd)),0,8);
            }

            $current_wifi_venue->capture_start = $formatted_date->timestamp;
            $current_wifi_venue->controller_id = $data['controller_id'];
            $current_wifi_venue->controller_venue_id = $data['controller_venue_id'];
            $current_wifi_venue->local_venue_id = $data['local_venue_id'];
            $current_wifi_venue->old_venue = $data['old_venue'];
            $current_wifi_venue->web_titan_id = $data['web_titan_id'];
            $current_wifi_venue->captive_portal = $data['captive_portal'];
            $current_wifi_venue->is_sponsored = $data['is_sponsored'];
            $current_wifi_venue->marketing_public_key = $data['marketing_public_key'];
            $current_wifi_venue->marketing_private_key = $data['marketing_private_key'];

            $current_venue->wifi_venue = 1;
            $current_wifi_venue->save();
        }

        else if ($args['venue_type'] == 'tracking') {
            $formatted_date = new Carbon($data['capture_start'], $current_venue['time_zone']);

            $current_tracking_venue = VenueTracking::firstOrNew([ 'venue_id' => $args['venue_id'] ]);

            $current_tracking_venue->capture_start = $formatted_date->timestamp;
            $current_tracking_venue->event_info_bucket = $data['event_info_bucket'];
            $current_tracking_venue->event_info_refresh = $data['event_info_refresh'];

            $current_venue->tracking_venue = 1;
            $current_tracking_venue->save();
        }

        else if ($args['venue_type'] == 'shared') {

            /**
             * update the many-to-many relationships
             */
            if (isset($associated_tags)) {
                $current_venue->tags()->sync($associated_tags);
            }

            if (isset($sub_categories)) {
                $current_venue->sub_categories()->sync($sub_categories);
            }

            /**
             * since these three aren't required fields, we have to populate them with a 0 value if
             * they weren't submitted through the form
             */
            if (empty($data['country_id'])) {
                $data['country_id'] = 0;
            }

            if (empty($data['region_id'])) {
                $data['region_id'] = 0;
            }

            if (empty($data['area_id'])) {
                $data['area_id'] = 0;
            }

            error_log(print_r($data, true));

            $current_venue->name = $data['name'];
            $current_venue->category_id = $data['category_id'];
            $current_venue->lat = $data['lat'];
            $current_venue->lon = $data['lon'];
            $current_venue->country_id = $data['country_id'];
            $current_venue->region_id = $data['region_id'];
            $current_venue->area_id = $data['area_id'];
            $current_venue->population = $data['population'];
            $current_venue->time_zone = $data['time_zone'];
            $current_venue->enviro_venue = $data['enviro_venue'];
            $current_venue->locale = $data['locale'];
            $current_venue->footfall_bucket = $data['footfall_bucket'];
            $current_venue->current_visitors_bucket = $data['current_visitors_bucket'];

            $current_venue->heatmap_min_zoom = $data['heatmap_min_zoom'];
            $current_venue->heatmap_max_zoom = $data['heatmap_max_zoom'];
            $current_venue->heatmap_init_zoom = $data['heatmap_init_zoom'];
            $current_venue->heatmap_radius = $data['heatmap_radius'];
            // $current_venue->show_stats_on_login     = $data['show_stats_on_login'];
            $current_venue->dt_threshold_1 = $data['dt_threshold_1'];
            $current_venue->dt_threshold_2 = $data['dt_threshold_2'];
            $current_venue->dt_threshold_3 = $data['dt_threshold_3'];
            $current_venue->dt_threshold_4 = $data['dt_threshold_4'];
            $current_venue->dt_threshold_5 = $data['dt_threshold_5'];
            $current_venue->dt_level_1_label = $data['dt_level_1_label'];
            $current_venue->dt_level_2_label = $data['dt_level_2_label'];
            $current_venue->dt_level_3_label = $data['dt_level_3_label'];
            $current_venue->dt_level_4_label = $data['dt_level_4_label'];
            $current_venue->dt_level_5_label = $data['dt_level_5_label'];
            $current_venue->dt_skipped_label = $data['dt_skipped_label'];
            $current_venue->sankey_max_route_length = $data['sankey_max_route_length'];
        }

        $current_venue->save();

        echo json_encode($current_venue->id);

        /**
         * send message back to the user
         */
        $ms->addMessageTranslated('success', 'Venue was successfully updated');

        return $response->withJson([], 200);
    }

    public function deleteVenue(Request $request, Response $response, $args)
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
        if (!$authorizer->checkAccess($currentUser, 'uri_site_admin')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * get the venue to be deleted
         */
        $venue_to_delete = Venue::find($args['venue_id']);

        /**
         * delete the venue
         * NOTE: deletion of all associated child objects is handled within the Venue model (Venue.php) by the delete function
         */
        $venue_name = $venue_to_delete->name;

        if ($venue_to_delete->wifi_venue == 1) {
            $venue_wifi_to_delete = VenueWifi::where('venue_id', $venue_to_delete->id)->first();
            if (!empty($venue_wifi_to_delete))
                $venue_wifi_to_delete->delete();
        }
        else if ($venue_to_delete->tracking_venue == 1) {
            $venue_tracking_to_delete = VenueWifi::where('venue_id', $venue_to_delete->id)->first();
            if (!empty($venue_tracking_to_delete))
                $venue_tracking_to_delete->delete();
        }

        $venue_to_delete->delete();

        /**
         * send message back to the user
         */
        $ms->addMessageTranslated('success', 'Venue was successfully removed');

        return $response->withJson([], 200);
    }

    public function fineUploaderEndpointPost(Request $request, Response $response, $args)
    {
        $venue_id = $args['venue_id'];
        $venue_type = $args['venue_type'];

        // Get the alert message stream
        $ms = $this->ci->alerts;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_site_admin')) {
            throw new NotFoundException($request, $response);
        }

        $uploader = new \UploadHandler();

        /**
         * Specify the list of valid extensions, ex. array("jpeg", "xml", "bmp")
         */
        $uploader->allowedExtensions = $this->allowed_extensions;

        /**
         * Specify the input name set in the javascript.
         */
        $uploader->inputName = $this->uploader_inputname;

        /**
         * If you want to use the chunking/resume feature, specify the folder to temporarily save parts.
         */
        $uploader->chunksFolder = $this->chunks_directory;

        /**
         * Get the venue_tracking using the venue_id
         */
        $venue_tracking = VenueTracking::where('venue_id', $venue_id)->first();

        switch ($venue_type) {
            case 'geo-sense':
                /**
                 * Specify max file size in bytes.
                 */
                $uploader->sizeLimit = $this->geo_sense_map_upload_size_limit;

                /**
                 * finish the upload:
                 * Call handleUpload() with the name of the folder (with $venue_id appended), relative to PHP's getcwd()
                 */
                $result = $uploader->handleUpload($this->geo_sense_map_upload_directory . $venue_id . DIRECTORY_SEPARATOR);

                /**
                 * To return a name used for uploaded file you can use the following line.
                 */
                $result['uploadName'] = $uploader->getUploadName();

                /**
                 * update the captive portal styling object
                 */
                if (isset($result['success']) && $result['success']) {
                    VenueTracking::where('id', $venue_tracking->id)
                        ->update(array(
                            'custom_map_file_uuid' => $result['uuid'],
                            'custom_map_file_name' => rawurlencode($result['uploadName'])
                    ));

                    //error_log('stored uuid: ' . $result['uuid'] . ' and stored name: ' . $result['uploadName']);
                } else {
                    error_log('we have a map image upload error: ' . $result['error']);
                }

                break;
            default:
                error_log('we received an upload POST without venue type');
                $ms->addMessageTranslated('danger', 'ACCESS_DENIED');
                throw new ForbiddenException();
                break;
        }


        /**
         * output the results in correct json formatting
         */
        return $response->withJson($result, 200);
    }

    /**
     * DELETE Endpoint for Fine Uploader (file upload library),
     * used for file delete requests.
     *
     * mimics this:
     * https://github.com/FineUploader/php-traditional-server/blob/master/endpoint.php
     */
    public function fineUploaderEndpointDelete(Request $request, Response $response, $args)
    {
        $venue_id = $args['venue_id'];
        $venue_type = $args['venue_type'];

        // Get the alert message stream
        $ms = $this->ci->alerts;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_site_admin')) {
            throw new NotFoundException($request, $response);
        }

        $uploader = new \UploadHandler();

        /**
         * Specify the list of valid extensions
         */
        $uploader->allowedExtensions = $this->allowed_extensions;

        /**
         * Specify the input name set in the javascript.
         */
        $uploader->inputName = $this->uploader_inputname;

        /**
         * If you want to use the chunking/resume feature, specify the folder to temporarily save parts.
         */
        $uploader->chunksFolder = $this->chunks_directory;

        /**
         * Get the venue_tracking using the venue_id
         */
        $venue_tracking = VenueTracking::where('venue_id', $venue_id)->first();

        switch ($venue_type) {
            case 'geo-sense':
                /**
                 * handle the delete:
                 * Call handleDelete() with the name of the folder (with $venue_id appended), relative to PHP's getcwd()
                 */
                $result = $uploader->handleDelete($this->geo_sense_map_upload_directory . $venue_id . DIRECTORY_SEPARATOR);

                /**
                 * To return a name used for uploaded file you can use the following line.
                 */
                $result['uploadName'] = $uploader->getUploadName();

                /**
                 * update the captive portal styling object
                 */
                if (isset($result['success']) && $result['success']) {
                    VenueTracking::where('id', $venue_tracking->id)
                        ->update(array(
                            'custom_map_file_uuid' => '',
                            'custom_map_file_name' => ''
                    ));
                } else {
                    error_log('we have a map image delete error: ' . $result['error']);
                }

                break;
            default:
                error_log('we received a DELETE request without type');
                $ms->addMessageTranslated('danger', 'ACCESS_DENIED');
                throw new ForbiddenException();
                break;
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($result, 200);
    }
}