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

use UserFrosting\Sprinkle\IntelliSense\Database\Models\Category;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\Tag;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\Zone;

/**
 * ZoneController Class
 *
 * @package IntelliSense
 * @author Luke Rayner/ElephantWiFi
 * @link http://www.elephantwifi.co.uk
 */
class ZoneController extends SimpleController 
{
    public function pageZones(Request $request, Response $response, $args)
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

        // show the "add new" drone button when user has sufficient permissions
        if ($authorizer->checkAccess($currentUser, 'uri_zone_add')){
            $shown_buttons = ['new'];
        } else {
            $shown_buttons = [];
        }

        /**
         * get the details of the primary venue for this user so we can access it's properties in the twig template
         */
        $primary_venue_details = $currentUser->primaryVenue->first();

        /**
         * get the category details for the current venue and add it to the primary_venue_details
         */
        $categoryQuery = new Category;
        $venue_category = $categoryQuery->where('id', $currentUser->primaryVenue->category_id)->first();
        $primary_venue_details['category'] = $venue_category;

        /**
         * get all categories for use in the forms
         */
        $category_collection = $categoryQuery->get();

        // get the time zones for the forms
        $timezone_identifiers = DateTimeZone::listIdentifiers();

        return $this->ci->view->render($response, 'pages/admin/zones.html.twig', [
            'time_zones' => $timezone_identifiers,
            'primary_venue' => $primary_venue_details,
            'categories' => $category_collection->values()->toArray(),
            'buttons' => [
                'shown' => $shown_buttons
            ]
        ]);
    }

    public function pageZonesAll(Request $request, Response $response, $args)
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

        return $this->ci->view->render($response, 'pages/admin/zones_all.html.twig');
    }

    public function formZoneCreate(Request $request, Response $response, $args)
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

        // show the "add new" drone button when user has sufficient permissions
        if ($authorizer->checkAccess($currentUser, 'uri_zone_add')){
            $shown_buttons = ['new'];
        } else {
            $shown_buttons = [];
        }

        // Get HTPP GET parameters
        $params = $request->getQueryParams();

        var_dump($params);

        $include_venue;
        if(!empty($get['include_venue'])) 
            $include_venue = $params['include_venue'];
        else 
            $include_venue = 0;

        /**
         *get all categories for use in the forms
         */
        $categoryQuery = new Category;
        $category_collection = $categoryQuery->get();

        // get the time zones for the forms
        $timezone_identifiers = DateTimeZone::listIdentifiers();

        // Load validation rules
        $schema = new RequestSchema('schema://requests/zone-create.yaml');
        $validator = new JqueryValidationAdapter($schema, $this->ci->translator);

        /**
         * get all tags
         */
        $tagQuery = new Tag;
        $tag_collection = $tagQuery->get();

        /**
         * get all venues
         */
        $venueQuery = new Venue;
        $venues = $venueQuery->get();

        return $this->ci->view->render($response, 'pages/admin/forms/zone-create-modal.html.twig', [
            'include_venue' => $include_venue,
            'venues' => $venues,
            'box_id' => $params['box_id'],
            'box_title' => 'Create Zone',
            'form_action' => $config['site.uri.public'] . '/admin/zones',
            'validators' => $validator->rules('json', true),
            'tags' => $tag_collection->values()->toArray(),
            'mode' => 'create',
            'categories' => $category_collection->values()->toArray()
        ]);
    }

    public function formZoneUpdate(Request $request, Response $response, $args)
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

        // show the "add new" drone button when user has sufficient permissions
        if ($authorizer->checkAccess($currentUser, 'uri_zone_add')){
            $shown_buttons = ['new'];
        } else {
            $shown_buttons = [];
        }

        $zoneQuery = new Zone;
        $target_zone = $zoneQuery->where('id', $args['zone_id'])->with('tags')->first();

        /**
         * Get the HTTP GET parameters
         */
        $params = $request->getQueryParams();

        $include_venue;
        if(!empty($params['include_venue'])) 
            $include_venue = $params['include_venue'];
        else 
            $include_venue = 0;

        /**
         *get all categories for use in the forms
         */
        $categoryQuery = new Category;
        $category_collection = $categoryQuery->get();

        // get the time zones for the forms
        $timezone_identifiers = DateTimeZone::listIdentifiers();

        /**
         * get all venues
         */
        $venueQuery = new Venue;
        $venues = $venueQuery->get();

        // Load validation rules
        $schema = new RequestSchema('schema://requests/zone-create.yaml');
        $validator = new JqueryValidationAdapter($schema, $this->ci->translator);

        /**
         * get all tags
         */
        $tagQuery = new Tag;
        $tag_collection = $tagQuery->get();

        return $this->ci->view->render($response, 'pages/admin/forms/zone-create-modal.html.twig', [
            'include_venue' => $include_venue,
            'venues' => $venues,
            'box_id' => $params['box_id'],
            'box_title' => 'Edit Zone',
            'form_action' => $config['site.uri.public'] . '/admin/zones/u/' . $target_zone->id,
            'target_zone' => $target_zone,
            'validators' => $validator->rules('json', true),
            'tags' => $tag_collection->values()->toArray(),
            'mode' => 'edit',
            'categories' => $category_collection->values()->toArray()
        ]);
    }

    public function addZone(Request $request, Response $response, $args)
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

        if (isset($params['venue_id']))
            $venue_id = $params['venue_id'];
        else 
            $venue_id = $currentUser->primary_venue_id;

        // Get the venue
        $venue = Venue::where('id', $venue_id)->first();

        /**
         * here we extract and process any tags if submitted
         */
        if (isset($params['associated_tags'])) {
            $associated_tags = explode(',', $params['associated_tags']);
            unset($params['associated_tags']);
        }

        // Load validation rules
        $schema = new RequestSchema('schema://requests/zone-create.yaml');

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_zone_add')) {
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

        $data['venue_id'] = $venue_id;

        // Format capture start date
        $formatted_date = new Carbon($data['capture_start'], $venue['time_zone']);

        $data['capture_start'] = $formatted_date->timestamp;

        var_dump($data);

        // Create the zone entry
        $zone = new Zone($data);
        $zone->save();

        /**
         * if we have received associated tags, we attach them to the new zone,
         * **after** the new zone has been created (otherwise we will get an error)
         */
        if (isset($associated_tags)) {
            $zone->tags()->sync($associated_tags);
        }

        $ms->addMessageTranslated('success', 'Zone was successfully created');

        return $response->withJson([], 200);
    }

    public function updateZone(Request $request, Response $response, $args)
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

        if (isset($params['venue_id']))
            $venue_id = $params['venue_id'];
        else 
            $venue_id = $currentUser->primary_venue_id;

        // Get the venue
        $venue = Venue::where('id', $venue_id)->first();

        /**
         * here we extract and process any tags if submitted
         */
        if (isset($params['associated_tags'])) {
            $associated_tags = explode(',', $params['associated_tags']);
            unset($params['associated_tags']);
        }

        // Load validation rules
        $schema = new RequestSchema('schema://requests/zone-create.yaml');

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_zone_update')) {
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

        // Get the target zone
        $zone = Zone::where('id', $args['zone_id'])->first();

        // Format capture start date
        $formatted_date = new Carbon($data['capture_start'], $venue['time_zone']);

        // Update the zone data
        $zone->update(array('name' => $data['name'], 'venue_id' => $venue_id, 'capture_start' => $formatted_date->timestamp, 'wifi_zone' => $data['wifi_zone'], 'tracking_zone' => $data['tracking_zone'], 'category_id' => $data['category_id'], 'lat' => $data['lat'], 'lon' => $data['lon']));

        /**
         * if we have received associated tags, we attach them to the new zone,
         * **after** the new zone has been created (otherwise we will get an error)
         */
        if (isset($associated_tags)) {
            $zone->tags()->sync($associated_tags);
        }

        $ms->addMessageTranslated('success', 'Zone was successfully updated');

        return $response->withJson([], 200);
    }

    public function deleteZone(Request $request, Response $response, $args)
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
         * get the venue to be deleted
         */
        $zone_to_delete = Zone::find($args['zone_id']);

        /**
         * delete the zone
         */
        $zone_name = $zone_to_delete->name;

        $zone_to_delete->delete();

        $ms->addMessageTranslated('success', 'Zone was successfully deleted');

        return $response->withJson([], 200);
    }
}