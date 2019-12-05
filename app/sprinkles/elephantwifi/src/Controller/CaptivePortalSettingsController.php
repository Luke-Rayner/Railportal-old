<?php

namespace UserFrosting\Sprinkle\ElephantWifi\Controller;

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
use UserFrosting\Support\Exception\ForbiddenExcefption;
use UserFrosting\Support\Exception\NotFoundException;

use UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue;

use UserFrosting\Sprinkle\ElephantWifi\Database\Models\VenueWifi;
use UserFrosting\Sprinkle\ElephantWifi\Database\Models\VenueFreeAccessSettings;
use UserFrosting\Sprinkle\ElephantWifi\Database\Models\VenueTextLabels;
use UserFrosting\Sprinkle\ElephantWifi\Database\Models\VenueCustomCSS;

require_once('/var/www/intelli_sense/app/local-packages/fineuploader/php-traditional-server/handler.php');

/**
 * CaptivePortalSettingsController Class
 *
 * @package IntelliSense
 * @author Luke Rayner/ElephantWiFi
 * @link http://www.elephantwifi.co.uk
 */
class CaptivePortalSettingsController extends SimpleController 
{
    private $logo_upload_size_limit  = 30000; // in bytes
    private $logo_upload_directory = '/var/www/intelli_sense/app/sprinkles/intelli-sense/assets/images/captive_portal/custom/logo/';

    private $background_upload_size_limit  = 4000000; // in bytes
    private $background_upload_directory = '/var/www/intelli_sense/app/sprinkles/intelli-sense/assets/images/captive_portal/custom/background/'; // with trailing slash!!!

    private $pdf_upload_size_limit  = 6000000; // in bytes
    private $license_agreement_upload_directory = '/var/www/intelli_sense/app/sprinkles/intelli-sense/assets/images/captive_portal/custom/pdf/license_agreement/'; // with trailing slash!!!
    private $privacy_policy_upload_directory = '/var/www/intelli_sense/app/sprinkles/intelli-sense/assets/images/captive_portal/custom/pdf/privacy_policy/'; // with trailing slash!!!

    private $chunks_directory = '/var/www/intelli_sense/app/sprinkles/intelli-sense/assets/images/upload_chunks';
    private $allowed_extensions = array('jpeg', 'jpg', 'png', 'pdf');
    private $uploader_inputname = 'qqfile';

    public function pageCaptivePortalSettings(Request $request, Response $response, $args)
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
        if (!$authorizer->checkAccess($currentUser, 'uri_captive_portal')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * find the primary venue for the current user, get it's details and it's locale etc..
         */
        $venue = Venue::where('id', $currentUser->primary_venue_id)->with('venue_wifi.controller', 'venue_wifi.text_labels', 'venue_wifi.free_access_settings', 'venue_wifi.custom_css')->first();

        /**
         * if the venue has the captive portal enabled and mailing_list == mailchimp, we fetch the lists for the API key in the settings
         */
        $lists = $this->fetchMailchimpMailinglists($venue);

        /**
         * we need to fetch the information for any existing logo/background files
         */
        $existing_logo_files = array();
        $existing_background_files = array();

        if (is_writable($this->license_agreement_upload_directory) && is_writable($this->privacy_policy_upload_directory)) {
            /**
             * first we check for license_agreement_pdf and privacy_policy_pdf files for the selected venue_id
             */
            $existing_license_agreement_files = array();
            $this->license_agreement_upload_directory = $this->license_agreement_upload_directory . $venue->id;

            $existing_privacy_policy_files = array();
            $this->privacy_policy_upload_directory = $this->privacy_policy_upload_directory . $venue->id;

            /**
             * if the directory for this venue does not exist, we create it
             */
            if (!file_exists($this->license_agreement_upload_directory)) {
                mkdir($this->license_agreement_upload_directory, 0744, true);
            }

            if (!file_exists($this->privacy_policy_upload_directory)) {
                mkdir($this->privacy_policy_upload_directory, 0744, true);
            }

            $found_directories = scandir($this->license_agreement_upload_directory);

            foreach ($found_directories as $directory) {
                if (!in_array($directory, array(".","..")) && is_dir($this->license_agreement_upload_directory . DIRECTORY_SEPARATOR . $directory)) {
                    $found_files = scandir($this->license_agreement_upload_directory . DIRECTORY_SEPARATOR . $directory);

                    foreach ($found_files as $file) {
                        if (!in_array($file, array(".","..")) && is_file($this->license_agreement_upload_directory . DIRECTORY_SEPARATOR . $directory . DIRECTORY_SEPARATOR . $file)) {
                            $found_file_name = $file;
                            $found_file_size = filesize($this->license_agreement_upload_directory . DIRECTORY_SEPARATOR . $directory . DIRECTORY_SEPARATOR . $file);
                        }
                    }

                    $existing_license_agreement_files[] = array(
                        'uuid' => $directory,
                        'name' => $found_file_name,
                        'size' => $found_file_size,
                        'thumbnailUrl' => $config['site.uri.public'] . '/assets-raw/images/captive_portal/custom/pdf/license_agreement/' . $venue->id . DIRECTORY_SEPARATOR . $directory . DIRECTORY_SEPARATOR . $found_file_name);
                }
            }

            $found_directories = scandir($this->privacy_policy_upload_directory);

            foreach ($found_directories as $directory) {
                if (!in_array($directory, array(".","..")) && is_dir($this->privacy_policy_upload_directory . DIRECTORY_SEPARATOR . $directory)) {
                    $found_files = scandir($this->privacy_policy_upload_directory . DIRECTORY_SEPARATOR . $directory);

                    foreach ($found_files as $file) {
                        if (!in_array($file, array(".","..")) && is_file($this->privacy_policy_upload_directory . DIRECTORY_SEPARATOR . $directory . DIRECTORY_SEPARATOR . $file)) {
                            $found_file_name = $file;
                            $found_file_size = filesize($this->privacy_policy_upload_directory . DIRECTORY_SEPARATOR . $directory . DIRECTORY_SEPARATOR . $file);
                        }
                    }

                    $existing_privacy_policy_files[] = array(
                        'uuid' => $directory,
                        'name' => $found_file_name,
                        'size' => $found_file_size,
                        'thumbnailUrl' => $config['site.uri.public'] . '/assets-raw/images/captive_portal/custom/pdf/privacy_policy/' . $venue->id . DIRECTORY_SEPARATOR . $directory . DIRECTORY_SEPARATOR . $found_file_name);
                }
            }

            // Load validation rules
            $schema = new RequestSchema('schema://requests/captive-portal-settings-update.yaml');
            $validator = new JqueryValidationAdapter($schema, $this->ci->translator);

            return $this->ci->view->render($response, 'pages/elephantwifi/admin/captive_portal_settings.html.twig', [
                'validators' => $validator->rules('json', true),
                'lists' => $lists,
                'existing_license_agreement_files_json' => json_encode($existing_license_agreement_files),
                'existing_privacy_policy_files_json' => json_encode($existing_privacy_policy_files),
                'unifi_venue' => $venue,
                'pdf_upload_size_limit' => $this->pdf_upload_size_limit
            ]);
        } else {
            /**
             * directories are not writable so we throw an error and render the blank page
             */
            $ms->addMessageTranslated('danger', 'Captive portal image directory is not writable.');

            return $this->ci->view->render($response, 'pages/blank.html.twig');
        }
    }

    public function updateCaptivePortalSettings(Request $request, Response $response, $args)
    {
        // Get POST parameters
        $params = $request->getParsedBody();

        // Get the alert message stream
        $ms = $this->ci->alerts;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_captive_portal')) {
            throw new NotFoundException($request, $response);
        }

        // Load validation rules
        $schema = new RequestSchema('schema://requests/captive-portal-settings-update.yaml');

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
        $venue = Venue::where('id', $currentUser->primary_venue_id)->with('venue_wifi.controller', 'venue_wifi.free_access_settings')->first();

        /**
         * check whether the submitted venue id is the same as the user's primary_venue_id
         * else throw an error
         */
        if($currentUser->primary_venue_id != $data['venue_id']) {
            $ms->addMessageTranslated('danger', 'ACCESS_DENIED');
            throw new ForbiddenException();
        }

        /**
         * Update the model with the following captive portal data
         * =======================================================
         *
         * venue:
         * =====
         * venue_id (validate is primary venue for user)
         * captive_portal X
         *
         * venue_free_access_settings:
         * ==========================
         * 'free_access_settings_id' => $data['free_access_settings_id'],
         * 'redirect_url' => $data['redirect_url'],
         * 'auth_duration' => $data['auth_duration'],
         * 'registration_duration' => $data['registration_duration'],
         * 'redirect_timeout' => $data['redirect_timeout'],
         * 'speed_limit_down' => $data['speed_limit_down'],
         * 'speed_limit_up' => $data['speed_limit_up'],
         * 'data_transfer_limit' => $data['data_transfer_limit'],
         * 'form_firstname' => $data['form_firstname'],
         * 'form_lastname' => $data['form_lastname'],
         * 'form_email' => $data['form_email'],
         * 'social_auth_enable_facebook' => $data['social_auth_enable_facebook'],
         * 'social_auth_enable_twitter' => $data['social_auth_enable_twitter'],
         * 'social_auth_enable_linkedin' => $data['social_auth_enable_linkedin'],
         * 'social_auth_enable_googleplus' => $data['social_auth_enable_googleplus'],
         * 'social_auth_enable_registration_fallback' => $data['social_auth_enable_registration_fallback'],
         * 'social_auth_temp_auth_duration' => $data['social_auth_temp_auth_duration'],
         * 'primary_method' => $data['primary_method']
         */

        if ($venue->id == $data['venue_id']) {
            VenueWifi::where('venue_id', $currentUser->primary_venue_id)
                    ->update(array('captive_portal' => $data['captive_portal']));
        } else {
            $ms->addMessageTranslated('danger', 'ACCESS_DENIED');
            throw new ForbiddenException();
        }

        if($venue->tracking_venue != 1) {
            $data['location_consent_text'] = null;
            $data['required_location_consent'] = 0;
        }

        /**
         * Update the venue_free_access_settings data
         */
        if ($venue->venue_wifi->free_access_settings->id == $data['free_access_settings_id']) {
            VenueFreeAccessSettings::where('id', $data['free_access_settings_id'])
                ->update(array(
                    'id' => $data['free_access_settings_id'],
                    'redirect_url' => $data['redirect_url'],
                    'auth_duration' => $data['auth_duration'],
                    'registration_duration' => $data['registration_duration'],
                    'redirect_timeout' => $data['redirect_timeout'],
                    'speed_limit_down' => $data['speed_limit_down'],
                    'speed_limit_up' => $data['speed_limit_up'],
                    'data_transfer_limit' => $data['data_transfer_limit'],
                    'data_consent_text' => $data['data_consent_text'],
                    'marketing_consent_text' => $data['marketing_consent_text'],
                    'location_consent_text' => $data['location_consent_text'],
                    'required_location_consent' => $data['required_location_consent'],
                    'form_firstname' => $data['form_firstname'],
                    'form_lastname' => $data['form_lastname'],
                    'form_email' => $data['form_email'],
                    'form_gender' => $data['form_gender'],
                    'form_birth_date' => $data['form_birth_date'],
                    'form_postcode' => $data['form_postcode'],
                    'required_firstname' => $data['required_firstname'],
                    'required_lastname' => $data['required_lastname'],
                    'required_email' => $data['required_email'],
                    'required_gender' => $data['required_gender'],
                    'required_birth_date' => $data['required_birth_date'],
                    'required_postcode' => $data['required_postcode'],
                    'social_auth_enable_facebook' => $data['social_auth_enable_facebook'],
                    'social_auth_enable_twitter' => $data['social_auth_enable_twitter'],
                    'social_auth_enable_registration_fallback' => $data['social_auth_enable_registration_fallback'],
                    'social_auth_temp_auth_duration' => $data['social_auth_temp_auth_duration'],
                    'primary_method' => $data['primary_method'],
                ));
        } else {
            $ms->addMessageTranslated('danger', 'ACCESS_DENIED');
            throw new ForbiddenException();
        }

        // send message back to the user
        $ms->addMessageTranslated('success', 'Captive portal settings have been updated');

        return $response->withJson([], 200);
    }

    public function pageCaptivePortalTextLabelConfig(Request $request, Response $response, $args)
    {
        // Get the alert message stream
        $ms = $this->ci->alerts;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_captive_portal')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * find the primary venue for the current user, get it's details and it's locale etc
         */
        $venue = Venue::where('id', $currentUser->primary_venue_id)->with('venue_wifi.controller', 'venue_wifi.text_labels', 'venue_wifi.free_access_settings', 'venue_wifi.custom_css')->first();

        // Load validation rules
        $schema = new RequestSchema('schema://requests/captive-portal-labels-update.yaml');
        $validator = new JqueryValidationAdapter($schema, $this->ci->translator);

        return $this->ci->view->render($response, 'pages/elephantwifi/admin/captive_portal_text_labels.html.twig', [
            'validators' => $validator->rules('json', true),
            'unifi_venue' => $venue
        ]);
    }

    public function updateCaptivePortalTextLabelConfig(Request $request, Response $response, $args)
    {
        // Get the alert message stream
        $ms = $this->ci->alerts;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_captive_portal')) {
            throw new NotFoundException($request, $response);
        }

        // Get POST parameters
        $params = $request->getParsedBody();

        // Load validation rules
        $schema = new RequestSchema('schema://requests/captive-portal-labels-update.yaml');

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
        $venue = Venue::where('id', $currentUser->primary_venue_id)->with('venue_wifi.controller', 'venue_wifi.text_labels')->first();

        /**
         * check whether the submitted venue id is the same as the user's primary_venue_id
         * else throw an error
         */
        if($currentUser->primary_venue_id != $data['venue_id']) {
            $ms->addMessageTranslated('danger', 'ACCESS_DENIED');
            throw new ForbiddenException();
        }

        /**
         * Update the model with the following captive portal text labels
         * ==============================================================
         * fields for venue_captive_portal_text_labels:
         * venue_captive_portal_text_labels_id
         * venue_id
         * page_title
         * heading
         * sub_heading
         * social_auth_sub_heading
         * motd_sub_heading
         * tos_title
         * tos_pre_link_label
         * tos_post_link_label
         * tos_modal_content
         * tos_modal_dismiss_button_label
         * basic_connect_button_label
         * basic_connect_button_class
         * first_name_label
         * last_name_label
         * email_label
         * first_name_placeholder
         * last_name_placeholder
         * email_placeholder
         * registration_form_button_label
         * registration_form_button_class
         * motd_form_button_label
         * motd_form_button_class
         * redirecting
         * social_auth_button_label_pre_provider
         * social_auth_button_label_registration
         * social_auth_button_size
         * social_auth_connecting_pre_provider
         * social_auth_redirecting_pre_provider
         * social_auth_redirecting_post_provider
         *
         * TODO:
         * - add error handling using try/catch block
         */

        /**
         * Update the venue_free_access_settings data
         */
        if ($venue->venue_wifi->text_labels->id == $data['venue_captive_portal_text_labels_id']) {
            VenueTextLabels::where('id', $data['venue_captive_portal_text_labels_id'])
                ->update(array(
                    'page_title' => $data['page_title'],
                    'heading' => $data['heading'],
                    'basic_sub_heading' => '',
                    'sub_heading' => $data['sub_heading'],
                    'registration_sub_heading' => $data['registration_sub_heading'],
                    'social_auth_sub_heading' => $data['social_auth_sub_heading'],
                    'motd_sub_heading' => $data['motd_sub_heading'],
                    'basic_connect_button_label' => $data['basic_connect_button_label'],
                    'basic_connect_button_class' => $data['basic_connect_button_class'],
                    'first_name_label' => $data['first_name_label'],
                    'last_name_label' => $data['last_name_label'],
                    'email_label' => $data['email_label'],
                    'first_name_placeholder' => $data['first_name_placeholder'],
                    'last_name_placeholder' => $data['last_name_placeholder'],
                    'email_placeholder' => $data['email_placeholder'],
                    'registration_form_button_label' => $data['registration_form_button_label'],
                    'registration_form_button_class' => $data['registration_form_button_class'],
                    'motd_form_button_label' => $data['motd_form_button_label'],
                    'motd_form_button_class' => $data['motd_form_button_class'],
                    'redirecting' => $data['redirecting'],
                    'social_auth_button_label_pre_provider' => $data['social_auth_button_label_pre_provider'],
                    'social_auth_button_label_registration' => $data['social_auth_button_label_registration'],
                    'social_auth_button_size' => $data['social_auth_button_size'],
                    'social_auth_connecting_pre_provider' => $data['social_auth_connecting_pre_provider'],
                    'social_auth_redirecting_pre_provider' => $data['social_auth_redirecting_pre_provider'],
                    'social_auth_redirecting_post_provider' => $data['social_auth_redirecting_post_provider']
                ));
        } else {
            $ms->addMessageTranslated('danger', 'ACCESS_DENIED');
            throw new ForbiddenException();
        }

        /**
         * send message back to the user
         */
        $ms->addMessageTranslated("success", "CAPTIVE_PORTAL_TEXT_LABELS_UPDATE_STORED");
    }

    public function pageCaptivePortalCSSConfig(Request $request, Response $response, $args)
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
        if (!$authorizer->checkAccess($currentUser, 'uri_captive_portal')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * find the primary venue for the current user, get it's details and it's locale etc
         */
        $venue = Venue::where('id', $currentUser->primary_venue_id)->with('venue_wifi.controller', 'venue_wifi.text_labels', 'venue_wifi.free_access_settings', 'venue_wifi.custom_css')->first();

        /**
         * we need to fetch the information for any existing logo/background files
         */
        $existing_logo_files = array();
        $existing_background_files = array();

        /**
         * Here we collect details of already uploaded file(s) and details by scanning directories in the upload location
         * and then scanning each directory found, for image files inside them.
         *
         * Before we do anything, we check whether the root upload directories are writable, else we throw an error and render an empty page
         * - we also check in the first loop whether our found item is a directory or not
         * - we also check in the second loop whether our found item is a file or not
         *
         * private $logo_upload_size_limit  = 20000; // in bytes
         * private $logo_upload_directory = 'images/captive_portal/custom/logo/'; *

         * private $background_upload_size_limit  = 4000000; // in bytes
         * private $background_upload_directory = 'images/captive_portal/custom/background/'; *

         * private $chunks_directory = 'images/upload_chunks';
         * private $allowed_extensions = array('jpeg', 'jpg', 'png');
         * private $uploader_inputname = 'qqfile';
         *
         */
        if (is_writable($this->logo_upload_directory) && is_writable($this->background_upload_directory)) {
            /**
             * first we check for logo files for the selected venue_id
             */
            $existing_logo_files = array();
            $this->logo_upload_directory = $this->logo_upload_directory . $venue->id;

            /**
             * if the directory for this venue does not exist, we create it
             */
            if (!file_exists($this->logo_upload_directory)) {
                mkdir($this->logo_upload_directory, 0744, true);
            }

            $found_directories = scandir($this->logo_upload_directory);

            foreach ($found_directories as $directory) {
                if (!in_array($directory, array(".","..")) && is_dir($this->logo_upload_directory . DIRECTORY_SEPARATOR . $directory)) {
                    $found_files = scandir($this->logo_upload_directory . DIRECTORY_SEPARATOR . $directory);

                    foreach ($found_files as $file) {
                        if (!in_array($file, array(".","..")) && is_file($this->logo_upload_directory . DIRECTORY_SEPARATOR . $directory . DIRECTORY_SEPARATOR . $file)) {
                            $found_file_name = $file;
                            $found_file_size = filesize($this->logo_upload_directory . DIRECTORY_SEPARATOR . $directory . DIRECTORY_SEPARATOR . $file);
                        }
                    }

                    $existing_logo_files[] = array(
                        'uuid' => $directory,
                        'name' => $found_file_name,
                        'size' => $found_file_size,
                        'thumbnailUrl' => $config['site.uri.public'] . '/assets-raw/images/captive_portal/custom/logo/' . $venue->id . DIRECTORY_SEPARATOR . $directory . DIRECTORY_SEPARATOR . $found_file_name);
                }
            }

            /**
             * then we check for background files
             */
            $existing_background_files = array();
            $this->background_upload_directory = $this->background_upload_directory . $venue->id;

            /**
             * if the directory for this venue does not exist, we create it
             */
            if (!file_exists($this->background_upload_directory)) {
                mkdir($this->background_upload_directory, 0744, true);
            }

            $found_directories = scandir($this->background_upload_directory);

            foreach ($found_directories as $directory) {
                if (!in_array($directory, array(".","..")) && is_dir($this->background_upload_directory . DIRECTORY_SEPARATOR . $directory)) {
                    $found_files = scandir($this->background_upload_directory . DIRECTORY_SEPARATOR . $directory);

                    foreach ($found_files as $file) {
                        if (!in_array($file, array(".","..")) && is_file($this->background_upload_directory . DIRECTORY_SEPARATOR . $directory . DIRECTORY_SEPARATOR . $file)) {
                            $found_file_name = $file;
                            $found_file_size = filesize($this->background_upload_directory . DIRECTORY_SEPARATOR . $directory . DIRECTORY_SEPARATOR . $file);
                        }
                    }

                    $existing_background_files[] = array(
                        'uuid' => $directory,
                        'name' => $found_file_name,
                        'size' => $found_file_size,
                        'thumbnailUrl' => $config['site.uri.public'] . '/assets-raw/images/captive_portal/custom/background/' . $venue->id . DIRECTORY_SEPARATOR . $directory . DIRECTORY_SEPARATOR . $found_file_name);

                }
            }

            // Load validation rules
            $schema = new RequestSchema('schema://requests/captive-portal-css-update.yaml');
            $validator = new JqueryValidationAdapter($schema, $this->ci->translator);

            return $this->ci->view->render($response, 'pages/elephantwifi/admin/captive_portal_custom_css.html.twig', [
                'validators' => $validator->rules('json', true),
                'unifi_venue' => $venue,
                'existing_logo_files_json' => json_encode($existing_logo_files),
                'existing_background_files_json' => json_encode($existing_background_files),
                'background_upload_size_limit' => $this->background_upload_size_limit,
                'logo_upload_size_limit' => $this->logo_upload_size_limit
            ]);
        } else {
            /**
             * directories are not writable so we throw an error and render the blank page
             */
            $ms->addMessageTranslated('danger', 'CAPTIVE_PORTAL_UPLOAD_DIRECTORY_NOT_WRITABLE');

            $this->_app->render('pages/blank.twig');
        }
    }

    public function updateCaptivePortalCSSConfig(Request $request, Response $response, $args)
    {
        // Get the alert message stream
        $ms = $this->ci->alerts;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_captive_portal')) {
            throw new NotFoundException($request, $response);
        }

        // Get POST parameters
        $params = $request->getParsedBody();

        // Load validation rules
        $schema = new RequestSchema('schema://requests/captive-portal-css-update.yaml');

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
        $venue = Venue::where('id', $currentUser->primary_venue_id)->with('venue_wifi.controller', 'venue_wifi.custom_css')->first();

        /**
         * check whether the submitted venue id is the same as the user's primary_venue_id
         * else throw an error
         */
        if($currentUser->primary_venue_id != $data['venue_id']) {
            $ms->addMessageTranslated('danger', 'ACCESS_DENIED');
            throw new ForbiddenException();
        }

        /**
         * Update the model with the following captive portal css data
         * ===========================================================
         * fields for venue_captive_portal_custom_css:
         * panel_border_color
         * panel_bg_color
         * panel_header_bg_color
         * text_color
         * hyperlink_color
         * border_radius
         * button_radius
         * css
         *
         * NOTE:
         * we skip the active field for now in form handling
         * the file uploads are handled elsewhere
         */

        /**
         * Update the venue_captive_portal_custom_css object with the submitted data
         */
        if ($venue->venue_wifi->custom_css->id == $data['venue_captive_portal_css_id']) {
            VenueCustomCSS::where('id', $data['venue_captive_portal_css_id'])
                ->update(array(
                    'panel_border_color' => $data['panel_border_color'],
                    'panel_bg_color' => $data['panel_bg_color'],
                    'panel_header_bg_color' => $data['panel_header_bg_color'],
                    'text_color' => $data['text_color'],
                    'hyperlink_color' => $data['hyperlink_color'],
                    'border_radius' => $data['border_radius'],
                    'button_radius' => $data['button_radius'],
                    'css' => $data['css']
                ));
        } else {
            $ms->addMessageTranslated('danger', 'ACCESS_DENIED');
            throw new ForbiddenException();
        }

        // send message back to the user
        $ms->addMessageTranslated('success', 'Captive portal css settings have been updated');

        return $response->withJson([], 200);
    }

    public function pageCaptivePortalControllerIntegration(Request $request, Response $response, $args)
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

        /**
         * find the primary venue for the current user, get it's details, etc..
         */
        $venue = Venue::where('id', $currentUser->primary_venue_id)->with('venue_wifi.controller', 'venue_wifi.text_labels', 'venue_wifi.free_access_settings', 'venue_wifi.custom_css')->first();

        /**
         * connect to the UniFi controller
         */
        $unifidata = $this->connectController($venue);
        $site_settings = $unifidata->list_settings();
        $site_wlanconf = $unifidata->list_wlanconf();
        $self = $unifidata->list_self();

        /**
         * create Laravel collections
         */
        $site_settings_collection = collect($site_settings);
        $site_wlanconf_collection = collect($site_wlanconf);

        /**
         * get the guest control settings on the UniFi controller for this venue
         */
        $guest_control_index = $site_settings_collection->search(function ($item) {
            return $item->key == 'guest_access';
        });

        /**
         * get wlans with guest control enabled for this venue
         */
        $guest_wlans = $site_wlanconf_collection->filter(function ($item) {
            return (isset($item->is_guest) && $item->is_guest === true);
        });

        /**
         * construct elements for the redirect URL for the sample HTML code
         */
        $server_url = $_SERVER['SERVER_NAME'];

        /**
         * determine our IP address(es)
         */
        $public_ip_address = $this->get_ip();

        if ($this->get_floating_ip()) {
            $public_ip_address = $this->get_floating_ip();
        }

        /**
         * check whether our IP address (or host name for versions >= 5.4.14) has been entered in the allowed subnets
         * TODO: make the IP address of the captive portal server configurable
         */
        $guest_control_object = $site_settings_collection[$guest_control_index];
        $allowed_subnet_ok = array('key' => 'found', 'value' => 'false' );

        foreach($guest_control_object as $key => $value) {
            //error_log('guest control data value: ' . print_r($value, true));
            if (substr($key, 0, 15) === 'allowed_subnet_') {
                if ($value === $public_ip_address . '/32' || $value === $server_url) {
                    $allowed_subnet_ok = array('key' => $key, 'value' => $value );
                }
            }
        }

        return $this->ci->view->render($response, 'pages/elephantwifi/admin/captive_portal_controller_integration.html.twig', [
            'guest_wlans' => $guest_wlans,
            'server_url' => $server_url,
            'allowed_subnet_ok' => $allowed_subnet_ok,
            'guest_control_settings' => $site_settings_collection[$guest_control_index],
            'unifi_venue' => $venue,
            'public_ip_address' => $public_ip_address,
            'self' => $self
        ]);
    }

    public function fineUploaderEndpointPost(Request $request, Response $response, $args)
    {
        $venue_id = $args['venue_id'];
        $type = $args['type'];

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

        /**
         * check whether venue_id is correct or exists using:
         * $this->_app->user->venue->id
         */
        if ($venue_id != $currentUser->venue->id) {
            $ms->addMessageTranslated('danger', 'ACCESS_DENIED');
            throw new ForbiddenException();
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
         * Get the venue_wifi using the venue_id
         */
        $venue_wifi = VenueWifi::where('venue_id', $venue_id)->first();

        switch ($type) {
            case 'logo':
                /**
                 * Specify max file size in bytes.
                 */
                $uploader->sizeLimit = $this->logo_upload_size_limit;

                /**
                 * finish the upload:
                 * Call handleUpload() with the name of the folder (with $venue_id appended), relative to PHP's getcwd()
                 */
                $result = $uploader->handleUpload($this->logo_upload_directory . $venue_id . DIRECTORY_SEPARATOR);

                /**
                 * To return a name used for uploaded file you can use the following line.
                 */
                $result['uploadName'] = $uploader->getUploadName();

                /**
                 * update the captive portal styling object
                 */
                if (isset($result['success']) && $result['success']) {
                    VenueCustomCSS::where('venue_wifi_id', $venue_wifi->id)
                        ->update(array(
                            'custom_logo_file_uuid' => $result['uuid'],
                            'custom_logo_file_name' => rawurlencode($result['uploadName'])
                    ));

                    //error_log('stored uuid: ' . $result['uuid'] . ' and stored name: ' . $result['uploadName']);
                } else {
                    error_log('we have a logo image upload error: ' . $result['error']);
                }

                break;
            case 'background':
                /**
                 * Specify max file size in bytes.
                 */
                $uploader->sizeLimit = $this->background_upload_size_limit;

                /**
                 * finish the upload:
                 * Call handleUpload() with the name of the folder (with $venue_id appended), relative to PHP's getcwd()
                 */
                $result = $uploader->handleUpload($this->background_upload_directory . $venue_id . DIRECTORY_SEPARATOR);

                /**
                 * To return a name used for uploaded file you can use the following line.
                 */
                $result['uploadName'] = $uploader->getUploadName();

                /**
                 * update the captive portal styling object
                 */
                if (isset($result['success']) && $result['success']) {
                    VenueCustomCSS::where('venue_wifi_id', $venue_wifi->id)
                        ->update(array(
                            'custom_background_file_uuid' => $result['uuid'],
                            'custom_background_file_name' => rawurlencode($result['uploadName'])
                    ));

                    //error_log('stored uuid: ' . $result['uuid'] . ' and stored name: ' . $result['uploadName']);
                } else {
                    error_log('we have a background image upload error: ' . $result['error']);
                }

                break;
            case 'license_agreement':
                /**
                 * Specify max file size in bytes.
                 */
                $uploader->sizeLimit = $this->pdf_upload_size_limit;

                /**
                 * finish the upload:
                 * Call handleUpload() with the name of the folder (with $venue_id appended), relative to PHP's getcwd()
                 */
                $result = $uploader->handleUpload($this->license_agreement_upload_directory . $venue_id . DIRECTORY_SEPARATOR);

                /**
                 * To return a name used for uploaded file you can use the following line.
                 */
                $result['uploadName'] = $uploader->getUploadName();

                /**
                 * update the captive portal styling object
                 */
                if (isset($result['success']) && $result['success']) {
                    VenueFreeAccessSettings::where('venue_wifi_id', $venue_wifi->id)
                        ->update(array(
                            'license_agreement_file_uuid' => $result['uuid'],
                            'license_agreement_file_name' => rawurlencode($result['uploadName'])
                    ));
                } else {
                    error_log('we have a license agreement pdf upload error: ' . $result['error']);
                }

                break;
            case 'privacy_policy':
                /**
                 * Specify max file size in bytes.
                 */
                $uploader->sizeLimit = $this->pdf_upload_size_limit;

                /**
                 * finish the upload:
                 * Call handleUpload() with the name of the folder (with $venue_id appended), relative to PHP's getcwd()
                 */
                $result = $uploader->handleUpload($this->privacy_policy_upload_directory . $venue_id . DIRECTORY_SEPARATOR);

                /**
                 * To return a name used for uploaded file you can use the following line.
                 */
                $result['uploadName'] = $uploader->getUploadName();

                error_log(print_r($result, true));

                /**
                 * update the captive portal styling object
                 */
                if (isset($result['success']) && $result['success']) {
                    VenueFreeAccessSettings::where('venue_wifi_id', $venue_wifi->id)
                        ->update(array(
                            'privacy_policy_file_uuid' => $result['uuid'],
                            'privacy_policy_file_name' => rawurlencode($result['uploadName'])
                    ));
                } else {
                    error_log('we have a privacy policy pdf upload error: ' . $result['error']);
                }

                break;

            default:
                error_log('we received an upload POST without type');
                $ms->addMessageTranslated('danger', 'ACCESS_DENIED');
                throw new ForbiddenException();
                break;
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($result, 200);
    }

    public function fineUploaderEndpointDelete(Request $request, Response $response, $args)
    {
        $venue_id = $args['venue_id'];
        $type = $args['type'];

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
         * Get the venue_wifi using the venue_id
         */
        $venue_wifi = VenueWifi::where('venue_id', $venue_id)->first();

        switch ($type) {
            case 'logo':
                /**
                 * handle the delete:
                 * Call handleDelete() with the name of the folder (with $venue_id appended), relative to PHP's getcwd()
                 */
                $result = $uploader->handleDelete($this->logo_upload_directory . $venue_id . DIRECTORY_SEPARATOR);

                /**
                 * To return a name used for uploaded file you can use the following line.
                 */
                $result['uploadName'] = $uploader->getUploadName();

                /**
                 * update the captive portal styling object
                 */
                if (isset($result['success']) && $result['success']) {
                    VenueCustomCSS::where('venue_wifi_id', $venue_wifi->id)
                        ->update(array(
                            'custom_logo_file_uuid' => '',
                            'custom_logo_file_name' => ''
                    ));
                } else {
                    error_log('we have a logo image delete error: ' . $result['error']);
                }

                break;
            case 'background':
                /**
                 * finish the upload:
                 * Call handleDelete() with the name of the folder (with $venue_id appended), relative to PHP's getcwd()
                 */
                $result = $uploader->handleDelete($this->background_upload_directory . $venue_id . DIRECTORY_SEPARATOR);

                /**
                 * To return a name used for uploaded file you can use the following line.
                 */
                $result['uploadName'] = $uploader->getUploadName();

                /**
                 * update the captive portal styling object
                 */
                if (isset($result['success']) && $result['success']) {
                    VenueCustomCSS::where('venue_wifi_id', $venue_wifi->id)
                        ->update(array(
                            'custom_background_file_uuid' => '',
                            'custom_background_file_name' => ''
                    ));
                } else {
                    error_log('we have a background image delete error: ' . $result['error']);
                }

                break;
            case 'license_agreement':
                /**
                 * finish the upload:
                 * Call handleDelete() with the name of the folder (with $venue_id appended), relative to PHP's getcwd()
                 */
                $result = $uploader->handleDelete($this->license_agreement_upload_directory . $venue_id . DIRECTORY_SEPARATOR);

                /**
                 * To return a name used for uploaded file you can use the following line.
                 */
                $result['uploadName'] = $uploader->getUploadName();

                /**
                 * update the captive portal styling object
                 */
                if (isset($result['success']) && $result['success']) {
                    VenueFreeAccessSettings::where('venue_wifi_id', $venue_wifi->id)
                        ->update(array(
                            'license_agreement_file_uuid' => '',
                            'license_agreement_file_name' => ''
                    ));
                } else {
                    error_log('we have a license_agreement pdf file delete error: ' . $result['error']);
                }

                break;
            case 'privacy_policy':
                /**
                 * finish the upload:
                 * Call handleDelete() with the name of the folder (with $venue_id appended), relative to PHP's getcwd()
                 */
                $result = $uploader->handleDelete($this->privacy_policy_upload_directory . $venue_id . DIRECTORY_SEPARATOR);

                /**
                 * To return a name used for uploaded file you can use the following line.
                 */
                $result['uploadName'] = $uploader->getUploadName();

                /**
                 * update the captive portal styling object
                 */
                if (isset($result['success']) && $result['success']) {
                    VenueFreeAccessSettings::where('venue_wifi_id', $venue_wifi->id)
                        ->update(array(
                            'privacy_policy_file_uuid' => '',
                            'privacy_policy_file_name' => ''
                    ));
                } else {
                    error_log('we have a privacy_policy pdf file delete error: ' . $result['error']);
                }

                break;
            default:
                error_log('we received a DELETE request without type');
                $ms->addMessageTranslated('danger', 'ACCESS_DENIED');
                $this->_app->halt(403);
                break;
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson([], 200);
    }

    private function fetchMailchimpMailinglists($venue){
        try {
            /**
             * add conditions when to submit the address and if so, how
             */
            if ($venue->free_access_settings['mailchimp_api_key'] != '') {
                /**
                 * create a new instance of the Mailchimp API wrapper
                 */
                $MailChimp = new MailChimp($venue->free_access_settings['mailchimp_api_key']);

                /**
                 * submit our request together with the appropriate payload
                 */
                $results = $MailChimp->get("lists");

                if ($MailChimp->success()) {
                    //error_log('talking to Mailchimp went fine!');
                    return $results['lists'];
                } else {
                    /**
                     * here we log an error and continue
                     */
                    error_log('we encountered an error talking to Mailchimp: ' . $MailChimp->getLastError());
                    return array(['id' => '', 'name' => 'unable to find lists']);
                }
            } else {
                return array();
            }
        } catch (Exception $e) {
            error_log('Caught general exception in private function submitIdentityToMailinglist(): ' . $e->getMessage());
            return array(['id' => '', 'name' => 'unable to find lists']);
        }

        return array();
    }

    private function get_ip() {
        /**
         * Just get the headers if we can or else use the SERVER global
         */
        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
        } else {
            $headers = $_SERVER;
        }

        /**
         * Get the forwarded IP if it exists
         */
        if (array_key_exists( 'X-Forwarded-For', $headers) && filter_var($headers['X-Forwarded-For'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $the_ip = $headers['X-Forwarded-For'];
        } elseif (array_key_exists('HTTP_X_FORWARDED_FOR', $headers) && filter_var($headers['HTTP_X_FORWARDED_FOR'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $the_ip = $headers['HTTP_X_FORWARDED_FOR'];
        } else {
            $the_ip = filter_var(gethostbyname($_SERVER['SERVER_NAME']), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
        }

        return $the_ip;
    }
    
    private function get_floating_ip() {
        // create curl resource
        $ch = curl_init();

        // set url
        curl_setopt($ch, CURLOPT_URL, 'http://169.254.169.254/metadata/v1/floating_ip/ipv4/ip_address');
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 1);

        //return the transfer as a string
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        // $output contains the output string
        $output = curl_exec($ch);

        // close curl resource to free up system resources
        curl_close($ch);
        return $output;
    }

    /**
     * private function to create a connection with the UniFi controller for the current user
     */
    private function connectController($venue_details){
        /**
         * Get the vnue_wifi using the venue_id
         */
        $venue_wifi = VenueWifi::where('venue_id', $venue_details->id)->first();

        /**
         * Get the login credentialsdetails for primary_venue_id of logged in user
         */
        $controlleruser = $venue_wifi->controller->user_name;
        $controllerpassword = $venue_wifi->controller->password;
        $controllerurl = $venue_wifi->controller->url;
        $controllerversion = $venue_wifi->controller->version;
        $venueid = $venue_wifi->controller_venue_id;
        $unifidata = new UnifiController($controlleruser, $controllerpassword, $controllerurl, $venueid, $controllerversion, $this->ci);

        if (!isset($_SESSION['controller_cookies'])) {
            error_log('we need to log in');
            $loginresults = $unifidata->login();

            /**
             * if we have an error we need to stop
             */
            if ($loginresults !== true) {
                $unifidata->is_loggedin = false;
                $this->_app->halt(400);
            } else {
                $unifidata->is_loggedin = true;
            }

        } else {
            error_log('we were already logged in');
            $unifidata->is_loggedin = true;
        }

        return $unifidata;
    }
}