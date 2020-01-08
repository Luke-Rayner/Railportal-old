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

use UserFrosting\Sprinkle\IntelliSense\Database\Models\SiteConfiguration;

use UserFrosting\Sprinkle\GeoSense\Database\Models\DeviceVendor;
use UserFrosting\Sprinkle\GeoSense\Database\Models\WhitelistDeviceVendor;

/**
 * SettingsController Class
 *
 * @package IntelliSense
 * @author Luke Rayner/ElephantWiFi
 * @link http://www.elephantwifi.co.uk
 */
class SettingsController extends SimpleController 
{
    public function pageShowSettings(Request $request, Response $response, $args)
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

        $settingsQuery = new SiteConfiguration;
        $settings_collection = $settingsQuery->get();

        $device_vendors = DeviceVendor::get();

        $whitelisted_device_vendors = WhitelistDeviceVendor::get();
        
        /**
         * create an array containing the id's of the whitelisted_device_vendors
         */
        $whitelisted_device_vendors_ids = array();
        foreach ($whitelisted_device_vendors as $whitelisted_device_vendor) {
            $whitelisted_device_vendors_ids[] = $whitelisted_device_vendor->device_vendor_id;
        }

        return $this->ci->view->render($response, 'pages/admin/site_configuration.html.twig', [
            'settings' => $settings_collection,
            'device_vendors' => $device_vendors,
            'whitelisted_device_vendors' => $whitelisted_device_vendors_ids
        ]);
    }

    public function updateSettings(Request $request, Response $response, $args)
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

        // Get POST parameters
        $params = $request->getParsedBody();

        // Remove CSRF token
        if (isset($params['csrf_name'])) {
            unset($params['csrf_name']);
        }

        if (isset($params['csrf_value'])) {
            unset($params['csrf_value']);
        }

        /**
         * updates the settings in the SiteConfiguration model
         */
        foreach ($params as $name => $value) {
            SiteConfiguration::where('name', $name)
                ->update(array('value' => $value));
        }
    }

    public function updateWhitelistDeviceVendor(Request $request, Response $response, $args) 
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

        // Get POST parameters
        $params = $request->getParsedBody();

        // Remove CSRF token
        if (isset($params['csrf_name'])) {
            unset($params['csrf_name']);
        }

        if (isset($params['csrf_value'])) {
            unset($params['csrf_value']);
        }

        // Empty the WhitelistDeviceVendor table before inserting data
        WhitelistDeviceVendor::truncate();

        /**
         * updates the settings in the SiteConfiguration model
         */
        foreach ($params as $name => $value){
            // Convert string to array
            $whitelist_device_vendor_array = explode(",", $value);

            foreach($whitelist_device_vendor_array as $device_vendor_id) {
                $whitelist_device_vendor = new WhitelistDeviceVendor();
                $whitelist_device_vendor['device_vendor_id'] = $device_vendor_id;
                $whitelist_device_vendor->save();
            }
        }
    }
}