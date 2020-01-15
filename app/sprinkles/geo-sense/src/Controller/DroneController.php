<?php

namespace UserFrosting\Sprinkle\GeoSense\Controller;

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

use UserFrosting\Sprinkle\IntelliSense\Database\Models\Zone;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue;

use UserFrosting\Sprinkle\GeoSense\Database\Models\Drone;

/**
 * DroneController Class
 *
 * @package IntelliSense
 * @author Luke Rayner/ElephantWiFi
 * @link http://www.elephantwifi.co.uk
 */
class DroneController extends SimpleController 
{
    /**
     * Render the GeoSense drone summary admin page
     * No AUTH required
     * Request type: GET
     */
    public function pageDroneSummary(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;
        
        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_site_admin')) {
            throw new NotFoundException($request, $response);
        }

        return $this->ci->view->render($response, 'pages/geo-sense/admin/drones_summary.html.twig');
    }

    public function pageDronesAll(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;
        
        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_site_admin')) {
            throw new NotFoundException($request, $response);
        }

        // we do not show the "add new" drone button on this page
        $shown_buttons = [];

        // Load validation rules
        $schema = new RequestSchema('schema://requests/drone-create.yaml');
        $validator = new JqueryValidationAdapter($schema, $this->ci->translator);

        return $this->ci->view->render($response, 'pages/geo-sense/admin/drones_all.html.twig', [
            'validators' => $validator->rules('json', true),
            'show_venue' => true,
            'buttons' => [
                'shown' => $shown_buttons
            ]
        ]);
    }

    public function pageDronesActivity(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;
        
        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_site_admin')) {
            throw new NotFoundException($request, $response);
        }

        return $this->ci->view->render($response, 'pages/geo-sense/admin/drones_activity.html.twig');
    }

    public function pageDrones(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;
        
        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_site_admin')) {
            throw new NotFoundException($request, $response);
        }

        // show the "add new" drone button when user has sufficient permissions
        if ($authorizer->checkAccess($currentUser, 'uri_drone_add')){
            $shown_buttons = ['new'];
        } else {
            $shown_buttons = [];
        }

        return $this->ci->view->render($response, 'pages/geo-sense/admin/drones.html.twig', [
            'show_venue' => false,
            'buttons' => [
                'shown' => $shown_buttons
            ]
        ]);
    }

    public function formDroneCreate(Request $request, Response $response, $args)
    {
        /** @var \UserFrosting\Support\Repository\Repository $config */
        $config = $this->ci->config;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;
        
        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_site_admin')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * Get the HTTP GET parameters
         */
        $params = $request->getQueryParams();

        $zoneQuery = new Zone;
        $zones = $zoneQuery->where('venue_id', $currentUser->primary_venue_id)->where('tracking_zone', 1)->get();

        // Load validation rules
        $schema = new RequestSchema('schema://requests/drone-create.yaml');
        $validator = new JqueryValidationAdapter($schema, $this->ci->translator);

        return $this->ci->view->render($response, 'pages/geo-sense/admin/forms/drone-create-modal.html.twig', [
            'box_id' => $params['box_id'],
            'box_title' => 'Create Drone',
            'form_action' => $config['site.uri.public'] . '/admin/geo-sense/drones',
            'validators' => $validator->rules('json', true),
            'zones' => $zones->values()->toArray()
        ]);
    }

    public function formDroneUpdate(Request $request, Response $response, $args)
    {
        /** @var \UserFrosting\Support\Repository\Repository $config */
        $config = $this->ci->config;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;
        
        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_site_admin')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * Get the HTTP GET parameters
         */
        $params = $request->getQueryParams();

        $zoneQuery = new Zone;
        $zones = $zoneQuery->where('venue_id', $currentUser->primary_venue_id)->where('tracking_zone', 1)->get();

        /**
         * Get the target drone
         */
        $droneQuery = new Drone;
        $target_drone = $droneQuery->find($args['drone_id']);

        // Load validation rules
        $schema = new RequestSchema('schema://requests/drone-create.yaml');
        $validator = new JqueryValidationAdapter($schema, $this->ci->translator);

        return $this->ci->view->render($response, 'pages/geo-sense/admin/forms/drone-create-modal.html.twig', [
            'box_id' => $params['box_id'],
            'box_title' => 'Update Drone',
            'form_action' => $config['site.uri.public'] . '/admin/geo-sense/drones/update/' . $args['drone_id'],
            'validators' => $validator->rules('json', true),
            'zones' => $zones->values()->toArray(),
            'target_drone' => $target_drone
        ]);
    }

    public function formDroneDetails(Request $request, Response $response, $args)
    {
        /** @var \UserFrosting\Support\Repository\Repository $config */
        $config = $this->ci->config;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;
        
        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_site_admin')) {
            throw new NotFoundException($request, $response);
        }

        $drone_id = $args['drone_id'];

        /**
         * Get the target drone
         */
        $droneQuery = new Drone;
        $target_drone = $droneQuery->with('drone_revision_code', 'last_activity')->find($drone_id);

        /**
         * Get the venue linked to this drone
         */
        $venueQuery = new Venue;
        $venue = $venueQuery->with('venue_tracking')->whereHas('zones.drones', function ($query) use ($drone_id) {
            $query->where('id', $drone_id);
        })->first();

        return $this->ci->view->render($response, 'pages/geo-sense/admin/forms/drone-details-modal.html.twig', [
            'box_id' => 'dialog-drone-details',
            'box_title' => 'Drone Details',
            'target_drone' => $target_drone,
            'venue' => $venue
        ]);
    }

    public function addDrone(Request $request, Response $response, $args)
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
        if (!$authorizer->checkAccess($currentUser, 'uri_drone_add')) {
            throw new NotFoundException($request, $response);
        }

        // Load validation rules
        $schema = new RequestSchema('schema://requests/drone-create.yaml');

        // Whitelist and set parameter defaults
        $transformer = new RequestDataTransformer($schema);
        $data = $transformer->transform($params);

        // Validate, and halt on validation errors.  Failed validation attempts do not count towards throttling limit.
        $validator = new ServerSideValidator($schema, $this->ci->translator);
        if (!$validator->validate($data)) {
            $ms->addValidationErrors($validator);

            return $response->withJson([], 400);
        }

        // check whether the submitted RSSI is negative, else stop
        if ($data['rssi_threshold'] > 0){
            $ms->addMessageTranslated("danger", "Drone RSSI cannot be positive");
            $error = true;
        }

        /**
         * Check if the given drone name already exists within the current (user's primary) venue
         */
        $zones = Zone::where('venue_id', $currentUser->primary_venue_id)->where('tracking_zone', 1)->get();
        foreach ($zones as $zone) {
            foreach ($zone->drones as $drone) {
                if ($drone->name == $data['name']) {
                    $ms->addMessageTranslated('danger', 'Drone name has already been used');
                    $error = true;
                }
            }
        }

        // Halt on any validation errors
        if ($error) {
            return $response->withJson([], 400);
        }

        // If a value hasn't been set for the delay period default it to 1
        if(empty($data['delay_period'])) {
            $data['delay_period'] = 1;
        }

        // Create the drone
        $drone = new Drone($data);
        $drone->save();

        $ms->addMessageTranslated('success', 'Drone was successfully stored');

        return $response->withJson([], 200);
    }

    public function updateDrone(Request $request, Response $response, $args)
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
        if (!$authorizer->checkAccess($currentUser, 'uri_drone_update')) {
            throw new NotFoundException($request, $response);
        }

        // Load validation rules
        $schema = new RequestSchema('schema://requests/drone-create.yaml');

        // Whitelist and set parameter defaults
        $transformer = new RequestDataTransformer($schema);
        $data = $transformer->transform($params);

        // Validate, and halt on validation errors.  Failed validation attempts do not count towards throttling limit.
        $validator = new ServerSideValidator($schema, $this->ci->translator);
        if (!$validator->validate($data)) {
            $ms->addValidationErrors($validator);

            return $response->withJson([], 400);
        }

        // check whether the submitted RSSI is negative, else stop
        if ($data['rssi_threshold'] > 0){
            $ms->addMessageTranslated("danger", "Drone RSSI cannot be positive");
            $error = true;
        }

        /**
         * Check if the given (new) drone name already exists within the current (user's primary) venue
         * since we are now updating a drone, we need to exclude itself from the check ;-)
         */
        $zones = Zone::where('venue_id', $currentUser->primary_venue_id)->where('tracking_zone', 1)->get();
        foreach ($zones as $zone) {
            foreach ($zone->drones as $drone) {
                if ($drone->name == $data['name'] && $drone->id != $args['drone_id']) {
                    $ms->addMessageTranslated('danger', 'Drone name has already been used');
                    $error = true;
                }
            }
        }

        // Halt on any validation errors
        if ($error) {
            return $response->withJson([], 400);
        }

        // Update the drone data
        Drone::where('id', $args['drone_id'])
                    ->update(array('name' => $data['name'], 'state' => $data['state'], 'zone_id' => $data['zone_id'], 'lat' => $data['lat'], 'lon' => $data['lon'], 'rssi_threshold' => $data['rssi_threshold'], 'delay_period' => $data['delay_period'], 'execute_command' => $data['execute_command'], 'drone_summary' => $data['drone_summary']));

        $ms->addMessageTranslated('success', 'Drone was successfully updated');

        return $response->withJson([], 200);
    }
}