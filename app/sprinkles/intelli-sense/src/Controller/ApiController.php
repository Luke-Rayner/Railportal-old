<?php

namespace UserFrosting\Sprinkle\IntelliSense\Controller;

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
use UserFrosting\Sprinkle\IntelliSense\Database\Models\Event;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\Company;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\Tag;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\Country;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\Region;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\Area;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\Category;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\SubCategory;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\EventCategory;

use UserFrosting\Sprinkle\ElephantWifi\Database\Models\Controller;

/**
 * ApiController Class
 *
 * @package IntelliSense
 * @author Luke Rayner/ElephantWiFi
 * @link http://www.elephantwifi.co.uk
 */
class ApiController extends SimpleController 
{
    public function listAllowedZones(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $zones = $currentUser->getZones();
        $zones_array = [];
        foreach($zones as $zone) {
            if ($zone->venue_id == $currentUser->primary_venue_id && $zone->tracking_zone == 1) {
                array_push($zones_array, $zone);
            }
        }

        sort($zones_array);

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($zones_array, 200, JSON_PRETTY_PRINT);
    }

    public function listZones(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $zoneQuery = new Zone;
        $total = $zoneQuery->count();

        /*
        Get zones filtered by the primary_venue_id of logged in user
        */
        $zone_collection = $zoneQuery->with('category', 'tags')->where('venue_id', $currentUser->primary_venue_id)->get();
        $total_filtered = count($zone_collection);

        $results = [
            'count' => $total,
            'rows' => $zone_collection->values()->toArray(),
            'count_filtered' => $total_filtered
        ];

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listAllEvents(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_site_admin')) {
            throw new NotFoundException($request, $response);
        }

        $eventQuery = new Event;
        $total = $eventQuery->count();

        /**
         * Get all tags
         */
        if ($authorizer->checkAccess($currentUser, 'uri_site_admin')) {
            $event_collection = $eventQuery->with('event_category')->where('venue_id', $currentUser->primary_venue_id)->get();
        }
        else {
            $event_collection = $eventQuery->with('event_category')->whereHas('event_category', function($query) {
                $query->where('admin_category', 0);
            })->where('venue_id', $currentUser->primary_venue_id)->get();
        }
        
        $total_filtered = count($event_collection);

        $results = [
            'count' => $total,
            'rows' => $event_collection->values()->toArray(),
            'count_filtered' => $total_filtered
        ];

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listVenues(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_site_admin')) {
            throw new NotFoundException($request, $response);
        }

        $venuesQuery = new Venue;
        $total = $venuesQuery->count();

        /**
         * Get sessions filtered by the primary_venue_id of logged in user
         */
        $venue_collection = $venuesQuery->with('category', 'venue_tracking', 'venue_wifi.controller', 'tags')->get();
        $total_filtered  = count($venue_collection);

        $results = [
            "count" => $total,
            "rows" => $venue_collection->values()->toArray(),
            "count_filtered" => $total_filtered
        ];

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listControllers(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_site_admin')) {
            throw new NotFoundException($request, $response);
        }

        $controllersQuery = new Controller;
        $total = $controllersQuery->count();

        /**
         * Get sessions filtered by the primary_venue_id of logged in user
         */
        $controller_collection = $controllersQuery->get();
        $total_filtered = count($controller_collection);

        $results = [
            "count" => $total,
            "rows" => $controller_collection->values()->toArray(),
            "count_filtered" => $total_filtered
        ];

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listCompanies(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_company_add')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * get the user's timezone
         */
        $timezone = $currentUser->primaryVenue->time_zone;

        $companyQuery = new Company;
        $total = $companyQuery->count();

        /**
         * Get unfiltered, unsorted, unpaginated collection
         * ->with('zone') for eager loading of the zone data
         */
        $company_collection = $companyQuery->get();

        /**
         * get the latest probe requests per active drone for today
         */
        $start_of_today = Carbon::now($timezone)->startOfDay()->format('U');

        $company_collection_filtered = [];

        foreach ($company_collection as $company) {
            $companies['id'] = $company->id;
            $companies['name'] = $company->name;

            $companies_collection_filtered[] = $companies;
        }

        $total_filtered = count($company_collection_filtered);

        $results = [
            'count' => $total,
            'rows' => $companies_collection_filtered,
            'count_filtered' => $total_filtered
        ];

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listAllTags(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tag_add')) {
            throw new NotFoundException($request, $response);
        }

        $tagQuery = new Tag;
        $total = $tagQuery->count();

        /**
         * Get all tags
         */
        $tag_collection = $tagQuery->get();
        $total_filtered = count($tag_collection);

        $results = [
            'count' => $total,
            'rows' => $tag_collection->values()->toArray(),
            'count_filtered' => $total_filtered
        ];

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listCountries(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_site_admin')) {
            throw new NotFoundException($request, $response);
        }

        $countryQuery = new Country;
        $total = $countryQuery->count();

        /**
         * Get unfiltered, unsorted, unpaginated company collection
         */
        $country_collection = $countryQuery->get();

        $total_filtered = count($country_collection);

        $results = [
            'count' => $total,
            'rows' => $country_collection,
            'count_filtered' => $total_filtered
        ];

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listRegions(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_site_admin')) {
            throw new NotFoundException($request, $response);
        }

        $regionQuery = new Region;
        $total = $regionQuery->count();

        /**
         * Get unfiltered, unsorted, unpaginated company collection
         */
        $region_collection = $regionQuery->with('country')->get();

        $total_filtered = count($region_collection);

        $results = [
            'count' => $total,
            'rows' => $region_collection,
            'count_filtered' => $total_filtered
        ];

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listAreas(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_site_admin')) {
            throw new NotFoundException($request, $response);
        }

        $areaQuery = new Area;
        $total = $areaQuery->count();

        /**
         * Get unfiltered, unsorted, unpaginated company collection
         */
        $area_collection = $areaQuery->with('region')->get();

        $total_filtered = count($area_collection);

        $results = [
            'count' => $total,
            'rows' => $area_collection,
            'count_filtered' => $total_filtered
        ];

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listAllCategories(Request $request, Response $response, $args)
    {
        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_category_add')) {
            throw new NotFoundException($request, $response);
        }

        $categoryQuery = new Category;
        $total = $categoryQuery->count();

        /**
         * Get all categories
         */
        $category_collection = $categoryQuery->get();
        $total_filtered = count($category_collection);

        $results = [
            'count' => $total,
            'rows' => $category_collection->values()->toArray(),
            'count_filtered' => $total_filtered
        ];

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listAllSubCategories(Request $request, Response $response, $args)
    {
        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_category_add')) {
            throw new NotFoundException($request, $response);
        }

        $subCategoryQuery = new SubCategory;
        $total = $subCategoryQuery->count();

        /**
         * Get all sub_categories
         */
        $sub_category_collection = $subCategoryQuery->get();
        $total_filtered = count($sub_category_collection);

        $results = [
            'count' => $total,
            'rows' => $sub_category_collection->values()->toArray(),
            'count_filtered' => $total_filtered
        ];

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listAllEventCategories(Request $request, Response $response, $args)
    {
        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_category_add')) {
            throw new NotFoundException($request, $response);
        }

        $eventCategoryQuery = new EventCategory;
        $total = $eventCategoryQuery->count();

        /**
         * Get all event_categories
         */
        $event_category_collection = $eventCategoryQuery->get();
        $total_filtered = count($event_category_collection);

        $results = [
            'count' => $total,
            'rows' => $event_category_collection->values()->toArray(),
            'count_filtered' => $total_filtered
        ];

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listAllZones(Request $request, Response $response, $args)
    {
        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_zone_add')) {
            throw new NotFoundException($request, $response);
        }

        $zoneQuery = new Zone;
        $total = $zoneQuery->count();

        /**
         * Get all zones
         */
        $zone_collection = $zoneQuery->with('category', 'venue', 'tags')->get();
        $total_filtered = count($zone_collection);

        $results = [
            'count' => $total,
            'rows' => $zone_collection->values()->toArray(),
            'count_filtered' => $total_filtered
        ];

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listVenueCalendarEvents(Request $request, Response $response, $args)
    {
        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $eventQuery = new Event;

        if ($authorizer->checkAccess($currentUser, 'uri_site_admin')){
            $event_collection = $eventQuery->with('event_category')
                ->where('venue_id', $currentUser->primary_venue_id)
                ->get();
        }
        else {
            $event_collection = $eventQuery->with('event_category')
                ->where('venue_id', $currentUser->primary_venue_id)
                ->where('admin_event', 0)
                ->get();
        }

        $results = [];

        foreach ($event_collection as $event) {
            /**
             * Add prefix to event title if it is an admin event
             */
            $event_title = $event->name;
            if ($event->admin_event == 1) {
                $event_title = 'SUPPORT - ' . $event_title;
            }

            $results[] = ['id' => $event->id, 'title' => $event_title, 'start' => Carbon::createFromTimestamp($event->start_date)->toDateTimeString(), 'end' => Carbon::createFromTimestamp($event->end_date)->addDays(1)->toDateTimeString(), 'color' => $event->event_category->category_color];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }
}