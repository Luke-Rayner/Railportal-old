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

use UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\Event;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\Zone;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\SiteConfiguration;

use UserFrosting\Sprinkle\GeoSense\Database\Models\ProbeRequest;
use UserFrosting\Sprinkle\GeoSense\Database\Models\TrackingDailyStatsVenueDwelltime;
use UserFrosting\Sprinkle\GeoSense\Database\Models\TrackingDailyStatsVenueVisitors;
use UserFrosting\Sprinkle\GeoSense\Database\Models\TrackingDailyStatsVenueHourlyVisitors;
use UserFrosting\Sprinkle\GeoSense\Database\Models\TrackingDailyStatsVenueUniqueDeviceUuids;
use UserFrosting\Sprinkle\GeoSense\Database\Models\TrackingDailyStatsVenueWeather;
use UserFrosting\Sprinkle\GeoSense\Database\Models\TrackingDailyStatsZoneUniqueDeviceUuids;
use UserFrosting\Sprinkle\GeoSense\Database\Models\TrackingDailyStatsZoneVisitors;
use UserFrosting\Sprinkle\GeoSense\Database\Models\TrackingDailyStatsZoneHourlyVisitors;
use UserFrosting\Sprinkle\GeoSense\Database\Models\TrackingDailyStatsZoneDwelltime;
use UserFrosting\Sprinkle\GeoSense\Database\Models\Drone;
use UserFrosting\Sprinkle\GeoSense\Database\Models\DroneHealth;
use UserFrosting\Sprinkle\GeoSense\Database\Models\Whitelist;

/**
 * ApiController Class
 *
 * @package GeoSense
 * @author Luke Rayner/ElephantWiFi
 * @link http://www.elephantwifi.co.uk
 */
class ApiController extends SimpleController 
{
    public function listFootfallCount(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $end   = floor($args['end']/1000);
        $start = floor($args['start']/1000);

        $results = [];

        /**
         * filter on the user's primary_venue_id
         */
        $venue_filter = $currentUser->primary_venue_id;

        /**
         * check whether the user is allowed to view full venue stats or not
         * and act accordingly
         */
        if ($currentUser->full_venue_view_allowed == 1) {
            /**
             * user is allowed to view full venue stats
             * prepare and execute the query
             */
            $footfallQuery = new ProbeRequest;
            $results = $footfallQuery->select('device_uuid')
                ->orderBy('ts', 'desc')
                ->distinct('device_uuid')
                ->whereBetween('ts', [$start, $end])
                ->where('venue_id', $venue_filter)
                ->get()
                ->count();

        } else {
            /**
             * user is NOT allowed to view full venue stats
             * filter on the user's allowed zones AND on the user's primary_venue_id
             *
             * get the allowed zones, then create an array which only contains the ids
             */
            $allowed_zones      = collect($currentUser->zones);
            $allowed_drones_ids = [];
            foreach ($allowed_zones as $allowed_zone) {
                foreach ($allowed_zone->drones as $drone) {
                    $allowed_drones_ids[] = $drone->id;
                }
            }

            /**
             * prepare and execute the query with venue AND drones filtering (since were working on the probe requests)
             */
            $footfallQuery = new ProbeRequest;
            $results = $footfallQuery->select('device_uuid')
                ->orderBy('ts', 'desc')
                ->distinct('device_uuid')
                ->whereBetween('ts', [$start, $end])
                ->where('venue_id', $venue_filter)
                ->whereIn('drone_id', $allowed_drones_ids)
                ->get()
                ->count();
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listVenueStatsFootfallCount(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $results = [];

        /**
         * filter on the user's primary_venue_id
         */
        $venue_filter = $currentUser->primary_venue_id;

        /**
         * check whether the user is allowed to view full venue stats or not
         * and act accordingly
         */
        if ($currentUser->full_venue_view_allowed == 1) {
            /**
             * user is allowed to view full venue stats
             * prepare and execute the query
             */
            $footfallQuery = new TrackingDailyStatsVenueVisitors;
            $results = $footfallQuery
                ->where('day_epoch', '>=', floor($args['start']/1000))
                ->where('day_epoch', '<', floor($args['end']/1000))
                ->where('venue_id', $venue_filter)
                ->sum('visitors_total');

        } else {
            /**
             * user is NOT allowed to view full venue stats
             * filter on the user's allowed zones AND on the user's primary_venue_id
             *
             * get the allowed zones, then create an array which only contains the ids
             */
            $allowed_zones     = collect($currentUser->zones);
            $allowed_zones_ids = [];
            foreach ($allowed_zones as $allowed_zone) {
                $allowed_zones_ids[] = $allowed_zone->id;
            }

            /**
             * prepare and execute the query with venue AND zones filtering
             */
            $footfallQuery = new TrackingDailyStatsZoneVisitors;
            $results = $footfallQuery
                ->where('day_epoch', '>=', floor($args['start']/1000))
                ->where('day_epoch', '<', floor($args['end']/1000))
                ->where('venue_id', $venue_filter)
                ->whereIn('zone_id', $allowed_zones_ids)
                ->sum('visitors_total');
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listNewVsRepeatToday(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $results = [];

        /**
         * filter on the user's primary_venue_id
         */
        $venue_filter = $currentUser->primary_venue_id;

        /**
         * define the starting point of today
         */
        $timezone   = $currentUser->primaryVenue->time_zone;
        $starttoday = Carbon::now($timezone)->startOfDay()->format('U');

        /**
         * prepare and execute the query using a PDO connection provided
         */
        $repeatVisitorsQuery = new ProbeRequest;
        $db = $repeatVisitorsQuery->getConnection()->getPdo();

        /**
         * check whether the user is allowed to view full venue stats or not
         * and act accordingly
         */
        if ($currentUser->full_venue_view_allowed == 1) {
            /**
             * user is allowed to view full venue stats
             * execute the prepared statement with the vars available for repeat/returning visitors today
             * NOTE: repeat is an SQL keyword, don't use it in the query!
             */
            $repeatVisitors = $db->prepare('
                SELECT COUNT(DISTINCT probes.device_uuid) AS `returning`
                FROM probe_request AS probes
                INNER JOIN tracking_daily_stats_venue_device_uuid_cache AS repeat_visitors
                ON probes.device_uuid = repeat_visitors.device_uuid
                WHERE repeat_visitors.first_seen < :start_1
                AND probes.ts > :start_2
                AND probes.venue_id = :venue_id'
            );
        } else {
            /**
             * user is NOT allowed to view full venue stats
             * filter on the user's allowed drones AND on the user's primary_venue_id
             *
             * get the allowed zones, then create an array which only contains the ids
             */
            $allowed_zones = collect($currentUser->zones);
            $allowed_drones_ids = [];
            foreach ($allowed_zones as $allowed_zone) {
                foreach ($allowed_zone->drones as $drone) {
                    $allowed_drones_ids[] = $drone->id;
                }
            }

            /**
             * create a string containing drone ids, comma seperated, to insert into the PDO statement
             */
            $ids_string = implode(',', $allowed_drones_ids);

            /**
             * prepare the query with venue AND drones filtering (since were working on the probe requests)
             * NOTE: repeat is an SQL keyword, don't use it in the query!
             */
            $repeatVisitors = $db->prepare('
                SELECT COUNT(DISTINCT probes.device_uuid) AS `returning`
                FROM probe_request AS probes
                INNER JOIN tracking_daily_stats_venue_device_uuid_cache AS repeat_visitors
                ON probes.device_uuid = repeat_visitors.device_uuid
                WHERE repeat_visitors.first_seen < :start_1
                AND probes.ts > :start_2
                AND probes.venue_id = :venue_id
                AND drone_id IN (' . $ids_string . ')'
            );
        }

        /**
         * bind the parameters for the prepared statement
         * in this case: with PDO prepared statements we can't use the same parameter twice...
         */
        $repeatVisitors->bindParam(':start_1', $starttoday);
        $repeatVisitors->bindParam(':start_2', $starttoday);
        $repeatVisitors->bindParam(':venue_id', $venue_filter);

        /**
         * execute the query
         */
        $repeatVisitors->execute();
        $initialresultsRepeatVisitors = $repeatVisitors->fetch();

        /**
         * check whether the user is allowed to view full venue stats or not
         * and act accordingly
         */
        if ($currentUser->full_venue_view_allowed == 1) {
            /**
             * user is allowed to view full venue stats
             * execute the prepared statement with the vars available for repeat/returning visitors today
             * NOTE: repeat is an SQL keyword, don't use it in the query!
             */
            $totalVisitors = $db->prepare('
                SELECT COUNT(DISTINCT device_uuid) AS total
                FROM probe_request
                WHERE ts > :start
                AND venue_id = :venue_id' // TODO: Partition needs looking into
            );

        } else {
            /**
             * user is NOT allowed to view full venue stats
             * filter on the user's allowed drones AND on the user's primary_venue_id
             *
             * get the allowed zones, then create an array which only contains the ids
             */
            $allowed_zones      = collect($currentUser->zones);
            $allowed_drones_ids = [];
            foreach ($allowed_zones as $allowed_zone) {
                foreach ($allowed_zone->drones as $drone) {
                    $allowed_drones_ids[] = $drone->id;
                }
            }

            /**
             * create a string containing drone ids, comma seperated, to insert into the PDO statement
             */
            $ids_string = implode(',', $allowed_drones_ids);

            /**
             * prepare the query with venue AND drones filtering (since were working on the probe requests)
             * NOTE: repeat is an SQL keyword, don't use it in the query!
             */
            $totalVisitors = $db->prepare('
                SELECT COUNT(DISTINCT device_uuid) AS total
                FROM probe_request
                WHERE ts > :start
                AND venue_id = :venue_id
                AND drone_id IN (' . $ids_string . ')'
            );
        }

        /**
         * bind the parameters for the prepared statement
         */
        $totalVisitors->bindParam(':venue_id', $venue_filter);
        $totalVisitors->bindParam(':start', $starttoday);

        /**
         * execute the query
         */
        $totalVisitors->execute();
        $initialresultsTotalVisitors = $totalVisitors->fetch();
        $results['repeat'] = $initialresultsRepeatVisitors['returning'];
        $results['new'] = $initialresultsTotalVisitors['total'] - $initialresultsRepeatVisitors['returning'];

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listStatsDailyAverageDwelltime(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * initialize some variables and the initial results array
         */
        $results = [
            'dwell_time_averages' => []
        ];

        $end = floor($args['end']/1000);
        $start = floor($args['start']/1000);

        /**
         * filter on the user's primary_venue_id and get the user's timezone
         */
        $venue_filter = $currentUser->primary_venue_id;
        $timezone = $currentUser->primaryVenue->time_zone;

        /**
         * check whether the user is allowed to view full venue stats or not
         * and act accordingly
         */
        if ($currentUser->full_venue_view_allowed == 1) {
            /**
             * user is allowed to view full venue stats
             * query the 'daily_stats_venue_visitor_dwelltime' table using DailyStatsVenueDwelltime class
             */
            $dwelltimeQuery = new TrackingDailyStatsVenueDwelltime;
            $initialresultsDwelltime = $dwelltimeQuery
                ->select('day_epoch', 'dt_average')
                ->where('day_epoch', '>=', $start)
                ->where('day_epoch', '<', $end)
                ->where('venue_id', $venue_filter)
                ->orderBy('day_epoch', 'asc')
                ->get();

            /**
             * here we populate the results array
             */
            foreach ($initialresultsDwelltime as $result) {
                $results['dwell_time_averages'][] = ['x' => $result->day_epoch * 1000, 'y' => round($result->dt_average/60)];
            }

        } else {
            /**
             * user is NOT allowed to view full venue stats
             * filter on the user's allowed zones AND on the user's primary_venue_id
             *
             * get the allowed zones, then create an array which only contains the ids
             */
            $allowed_zones     = collect($currentUser->zones);
            $allowed_zones_ids = [];
            foreach ($allowed_zones as $allowed_zone) {
                $allowed_zones_ids[] = $allowed_zone->id;
            }

            /**
             * query the 'daily_stats_zone_visitor_dwelltime' table using DailyStatsZoneDwelltime class with venue AND zones filtering
             *
             * TODO:
             * calculate the averages across the zones collected (selectRaw or something more sophisticated?) <== validate!
             */
            $dwelltimeQuery = new TrackingDailyStatsZoneDwelltime;
            $initialresultsDwelltime = $dwelltimeQuery
                ->selectRaw('day_epoch, avg(dt_average) AS dt_average')
                ->where('day_epoch', '>=', $start)
                ->where('day_epoch', '<', $end)
                ->where('venue_id', $venue_filter)
                ->whereIn('zone_id', $allowed_zones_ids)
                ->groupBy('day_epoch')
                ->orderBy('day_epoch', 'asc')
                ->get();
            /**
             * here we populate the results array
             */
            foreach ($initialresultsDwelltime as $result) {
                $results['dwell_time_averages'][] = ['x' => $result->day_epoch * 1000, 'y' => round($result->dt_average/60)];
            }
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listVenueStatsUniqueVisitorsDaily(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $results = [];

        /**
         * filter on the user's primary_venue_id
         */
        $venue_filter = $currentUser->primary_venue_id;

        /**
         * check whether the user is allowed to view full venue stats or not
         * and act accordingly
         */
        if ($currentUser->full_venue_view_allowed == 1) {
            /**
             * user is allowed to view full venue stats
             * prepare and execute the query
             */
            $visitorsQuery = new TrackingDailyStatsVenueVisitors;
            $initialresults = $visitorsQuery
                ->selectRaw('day_epoch, SUM(visitors_total) AS visitors_total')
                ->where('venue_id', $venue_filter)
                ->where('day_epoch', '>=', floor($args['start']/1000))
                ->where('day_epoch', '<', floor($args['end']/1000))
                ->groupBy('day_epoch')
                ->get();
        } else {
            /**
             * user is NOT allowed to view full venue stats
             * filter on the user's allowed zones AND on the user's primary_venue_id
             *
             * get the allowed zones, then create an array which only contains the ids
             */
            $allowed_zones     = collect($currentUser->zones);
            $allowed_zones_ids = [];
            foreach ($allowed_zones as $allowed_zone) {
                $allowed_zones_ids[] = $allowed_zone->id;
            }

            /**
             * prepare and execute the query with venue AND zones filtering
             */
            $visitorsQuery  = new TrackingDailyStatsZoneVisitors;
            $initialresults = $visitorsQuery
                ->selectRaw('day_epoch, SUM(visitors_total) AS visitors_total')
                ->where('venue_id', $venue_filter)
                ->where('day_epoch', '>=', floor($args['start']/1000))
                ->where('day_epoch', '<', floor($args['end']/1000))
                ->whereIn('zone_id', $allowed_zones_ids)
                ->groupBy('day_epoch')
                ->get();
        }

        /**
         * process the results
         */
        foreach ($initialresults as $initialresult) {
            $results[] = [(int)$initialresult['day_epoch']*1000, (int)$initialresult['visitors_total']];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listVenueStatsUniqueVisitorsWeekdaysCompared(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $results = [
            'prev_week' => [],
            'this_week' => [],
            'event'     => []
        ];

        $start = floor($args['start']/1000);
        $end   = floor($args['end']/1000);

        $timezone = $currentUser->primaryVenue->time_zone;
        $event_start = Carbon::createFromTimestamp($start, $timezone)->subWeek()->startOfDay()->format('U');

        /**
         * filter on the user's primary_venue_id
         */
        $venue_filter = $currentUser->primary_venue_id;

        // Get all the events which were ran on between these dates
        $event = new Event;
        if ($authorizer->checkAccess($currentUser, 'uri_site_admin')) {
            $dailyEvents = $event
                ->with('event_category')
                ->where('start_date', '>=', $event_start)
                ->where('end_date', '<=', $end)
                ->where('venue_id', $currentUser->primary_venue_id)
                ->get();
        }
        else {
            $dailyEvents = $event
                ->with('event_category')
                ->where('start_date', '>=', $start)
                ->where('end_date', '<=', $end)
                ->where('admin_event', 0)
                ->where('venue_id', $currentUser->primary_venue_id)
                ->get();
        }

        foreach($dailyEvents as $event) {
            $results['event'][] = [
                'start_date'  => $event->start_date * 1000, 
                'end_date'    => $event->end_date * 1000, 
                'name'        => $event->name,
                'color'       => $event->event_category->category_color,
                'category_id' => $event->event_category_id
            ];
        }

        /**
         * check whether the user is allowed to view full venue stats or not
         * and act accordingly
         */
        if ($currentUser->full_venue_view_allowed == 1) {
            /**
             * prepare and execute the query
             */
            $thisWeekVisitors = new TrackingDailyStatsVenueVisitors;
            $initialresultsThisWeekVisitors = $thisWeekVisitors
                ->selectRaw('day_of_week, SUM(visitors_total) AS visitors_total, day_epoch')
                ->where('day_epoch', '>=', $start)
                ->where('day_epoch', '<', $end)
                ->where('venue_id', $venue_filter)
                ->orderBy('day_epoch', 'asc')
                ->groupBy('day_of_week')
                ->get();

            /**
             * previous start is now our end, new end is one week later
             */
            $end = $start;
            $timezone = $currentUser->primaryVenue->time_zone;
            $start = Carbon::createFromTimestamp($end, $timezone)->subWeek()->startOfDay()->format('U');

            /**
             * prepare and execute the query
             */
            $prevWeekVisitors = new TrackingDailyStatsVenueVisitors;
            $initialresultsPrevWeekVisitors = $prevWeekVisitors
                ->selectRaw('day_of_week, SUM(visitors_total) AS visitors_total, day_epoch')
                ->where('day_epoch', '>=', $start)
                ->where('day_epoch', '<', $end)
                ->where('venue_id', $venue_filter)
                ->orderBy('day_epoch', 'asc')
                ->groupBy('day_of_week')
                ->get();
        } else {
            /**
             * user is NOT allowed to view full venue stats
             * filter on the user's allowed zones AND on the user's primary_venue_id
             *
             * get the allowed zones, then create an array which only contains the ids
             */
            $allowed_zones     = collect($currentUser->zones);
            $allowed_zones_ids = [];
            foreach ($allowed_zones as $allowed_zone) {
                $allowed_zones_ids[] = $allowed_zone->id;
            }

            /**
             * prepare and execute the query
             */
            $thisWeekVisitors = new TrackingDailyStatsZoneVisitors;
            $initialresultsThisWeekVisitors = $thisWeekVisitors
                ->selectRaw('day_of_week, SUM(visitors_total) AS visitors_total, day_epoch')
                ->where('day_epoch', '>=', $start)
                ->where('day_epoch', '<', $end)
                ->where('venue_id', $venue_filter)
                ->whereIn('zone_id', $allowed_zones_ids)
                ->orderBy('day_epoch', 'asc')
                ->groupBy('day_of_week')
                ->get();

            /**
             * previous start is now our end, new end is one week later
             */
            $end = $start;
            $timezone = $currentUser->primaryVenue->time_zone;
            $start = Carbon::createFromTimestamp($end, $timezone)->subWeek()->startOfDay()->format('U');

            /**
             * prepare and execute the query
             */
            $prevWeekVisitors = new TrackingDailyStatsZoneVisitors;
            $initialresultsPrevWeekVisitors = $prevWeekVisitors
                ->selectRaw('day_of_week, SUM(visitors_total) AS visitors_total, day_epoch')
                ->where('day_epoch', '>=', $start)
                ->where('day_epoch', '<', $end)
                ->where('venue_id', $venue_filter)
                ->whereIn('zone_id', $allowed_zones_ids)
                ->orderBy('day_epoch', 'asc')
                ->groupBy('day_of_week')
                ->get();
        }

        /**
         * process the results from both periods and merge into a single array
         */
        foreach ($initialresultsPrevWeekVisitors as $initialresultsPrevWeekVisitor) {
            $results['prev_week'][] = [
                'name' => $initialresultsPrevWeekVisitor['day_of_week'],
                'y' => (int)$initialresultsPrevWeekVisitor['visitors_total'],
                'day_epoch' => (int)$initialresultsPrevWeekVisitor['day_epoch'] * 1000
            ];
        }

        foreach ($initialresultsThisWeekVisitors as $initialresultsThisWeekVisitor) {
            $results['this_week'][] = [
                'name' => $initialresultsThisWeekVisitor['day_of_week'],
                'y' => (int)$initialresultsThisWeekVisitor['visitors_total'],
                'day_epoch' => (int)$initialresultsThisWeekVisitor['day_epoch'] * 1000
            ];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listVenueStatsVisitorDurations(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $results = [
            'dt_skipped' => [],
            'dt_level_1' => [],
            'dt_level_2' => [],
            'dt_level_3' => [],
            'dt_level_4' => [],
            'dt_level_5' => [],
            'dt_average' => []
        ];

        /**
         * filter on the user's primary_venue_id
         */
        $venue_filter = $currentUser->primary_venue_id;
        $timezone     = $currentUser->primaryVenue->time_zone;

        $venueQuery = new Venue;
        $venue_collection = $venueQuery->where('id', $venue_filter)->get();

        /**
         * WORK ON SELECTED RANGE FROM HERE
         * - determine start and end
         */
        $start       = floor($args['start']/1000);
        $end         = floor($args['end']/1000);
        $start_hour  = Carbon::createFromTimestamp($start, $timezone)->hour;
        $end_hour    = Carbon::createFromTimestamp($end, $timezone)->hour;
        $start_date  = Carbon::createFromTimestamp($start, $timezone);

        /**
         * get the start of day for head and set correct timezone for head_start and head_end
         */
        $initial_start = $start_date->startOfDay()->format('U');

        /**
         * get the user's timezone
         */
        $timezone = $currentUser->primaryVenue->time_zone;

        /**
         * prepare the query using a "random" PDO connection
         */
        $query = new TrackingDailyStatsVenueUniqueDeviceUuids;
        $db    = $query->getConnection()->getPdo();

        if ($start > 0 && $end > 0) {

            /**
             * check whether the user is allowed to view full venue stats or not
             * and act accordingly
             */
            $dt_threshold_1  = $venue_collection[0]['dt_threshold_1'];
            $dt_threshold_2  = $venue_collection[0]['dt_threshold_2'];
            $dt_threshold_3  = $venue_collection[0]['dt_threshold_3'];
            $dt_threshold_4  = $venue_collection[0]['dt_threshold_4'];
            $dt_threshold_5  = $venue_collection[0]['dt_threshold_5'];
            $dwell_bucket    = $venue_collection[0]['footfall_bucket'];

            $init_query = "SET sql_mode = 'NO_UNSIGNED_SUBTRACTION',
                @venue_filter        = '{$venue_filter}',
                @range_start         = '{$initial_start}',
                @range_end           = '{$end}',
                @start               = '{$start}',
                @end                 = '{$end}',
                @dwell_bucket        = '{$dwell_bucket}',
                @prev_ts             = 0,
                @dwell_start         = 0,
                @prev_dwelltime      = 0,
                @prev_device_uuid    = NULL,
                @device_uuid_changed = FALSE,
                @long_gap            = FALSE,
                @gap                 = 0,
                @dt_threshold_1      = '{$dt_threshold_1}',
                @dt_threshold_2      = '{$dt_threshold_2}',
                @dt_threshold_3      = '{$dt_threshold_3}',
                @dt_threshold_4      = '{$dt_threshold_4}',
                @dt_threshold_5      = '{$dt_threshold_5}'";

            $db->exec($init_query);

            if (isset($args['zone_ids'])) {
                $zone_ids = $args['zone_ids'];
                $db->exec("SET @zones = '{$zone_ids}'");

                $initialresultsDurations = $db->prepare(
                    'SELECT day_epoch,
                            dt_skipped,
                            dt_level_1,
                            dt_level_2,
                            dt_level_3,
                            dt_level_4,
                            dt_level_5,
                            dt_average
                    FROM (
                        SELECT venue_id,
                               day_epoch,
                               day_of_week,
                               SUM(IF(prev_dwelltime < @dt_threshold_1, 1, 0))                             AS dt_skipped,
                               SUM(IF((prev_dwelltime BETWEEN @dt_threshold_1 AND @dt_threshold_2), 1, 0)) AS dt_level_1,
                               SUM(IF((prev_dwelltime BETWEEN @dt_threshold_2 AND @dt_threshold_3), 1, 0)) AS dt_level_2,
                               SUM(IF((prev_dwelltime BETWEEN @dt_threshold_3 AND @dt_threshold_4), 1, 0)) AS dt_level_3,
                               SUM(IF((prev_dwelltime BETWEEN @dt_threshold_4 AND @dt_threshold_5), 1, 0)) AS dt_level_4,
                               SUM(IF(prev_dwelltime > @dt_threshold_5, 1, 0))                             AS dt_level_5,
                               ROUND(AVG(IF(prev_dwelltime > @dt_threshold_1, prev_dwelltime, NULL))/60)   AS dt_average
                        FROM (
                            SELECT venue_id,
                                   @prev_device_uuid     AS prev_device_uuid,
                                   @prev_ts              AS prev_ts,
                                   @prev_dwelltime       AS prev_dwelltime,
                                   @dwell_start          := IF(@dwell_start = 0, first_seen, @dwell_start),
                                   device_uuid,
                                   (@device_uuid_changed := IF(@prev_device_uuid <> device_uuid, TRUE, FALSE)) AS changed,
                                   (@long_gap            := IF(@device_uuid_changed = FALSE AND first_seen - @prev_ts > @dwell_bucket, TRUE, FALSE)) AS long_gap,
                                   (@dwell_start         := IF(@device_uuid_changed = TRUE OR @long_gap = TRUE, first_seen, @dwell_start)) as dwell_start,
                                   (@dwelltime           := IF(@long_gap = TRUE, 0, last_seen - @dwell_start)) AS dwelltime,
                                   @prev_device_uuid     := device_uuid,
                                   @prev_ts              := last_seen,
                                   @prev_dwelltime       := @dwelltime,
                                   day_epoch,
                                   day_of_week
                            FROM (

                                SELECT venue_id,
                                       device_uuid,
                                       first_seen,
                                       last_seen,
                                       day_epoch,
                                       (day_epoch + (3600*hour)) AS hour_epoch,
                                       day_of_week,
                                       hour
                                  FROM tracking_daily_stats_zone_unique_device_uuids_per_hour
                                  PARTITION (p' . $venue_filter . ')
                                  WHERE day_epoch BETWEEN @range_start AND @range_end
                                    AND FIND_IN_SET(zone_id, @zones)
                                ) temp_results

                           WHERE hour_epoch >= @start
                             AND hour_epoch < @end
                             ORDER BY device_uuid ASC, hour ASC
                            ) temp_results_1
                        WHERE changed = TRUE
                        GROUP BY day_epoch
                    ) final_results'
                );

                /**
                 * execute the query for total visitors
                 */
                $initialresultsDurations->execute();

                /**
                 * process the results from both periods and merge into a single array
                 * 5 buckets/series to fill
                 */
                foreach ($initialresultsDurations as $initialresultsDuration) {
                    $results['dt_skipped'][] = [$initialresultsDuration['day_epoch']*1000, (int)$initialresultsDuration['dt_skipped']];
                    $results['dt_level_1'][] = [$initialresultsDuration['day_epoch']*1000, (int)$initialresultsDuration['dt_level_1']];
                    $results['dt_level_2'][] = [$initialresultsDuration['day_epoch']*1000, (int)$initialresultsDuration['dt_level_2']];
                    $results['dt_level_3'][] = [$initialresultsDuration['day_epoch']*1000, (int)$initialresultsDuration['dt_level_3']];
                    $results['dt_level_4'][] = [$initialresultsDuration['day_epoch']*1000, (int)$initialresultsDuration['dt_level_4']];
                    $results['dt_level_5'][] = [$initialresultsDuration['day_epoch']*1000, (int)$initialresultsDuration['dt_level_5']];
                    $results['dt_average'][] = [$initialresultsDuration['day_epoch']*1000, (int)$initialresultsDuration['dt_average']];
                }
            }
            else {
                /**
                 * check whether the user is allowed to view full venue stats or not
                 * and act accordingly
                 */
                if ($currentUser->full_venue_view_allowed == 1) {
                    /**
                     * user is allowed to view full venue stats
                     * prepare and execute the query
                     */
                    $thisWeekDwelltimes = new TrackingDailyStatsVenueDwelltime;
                    $initialresultsDurations = $thisWeekDwelltimes
                        ->selectRaw('day_epoch, dt_skipped, dt_level_1, dt_level_2, dt_level_3, dt_level_4, dt_level_5, dt_average')
                        ->where('day_epoch', '>=', $start)
                        ->where('day_epoch', '<', $end)
                        ->where('venue_id', $venue_filter)
                        ->orderBy('day_epoch', 'asc')
                        ->get();

                } else {
                    /**
                     * user is NOT allowed to view full venue stats
                     * filter on the user's allowed zones AND on the user's primary_venue_id
                     *
                     * get the allowed zones, then create an array which only contains the ids
                     */
                    $allowed_zones = collect($currentUser->zones);
                    $allowed_zones_ids = [];
                    foreach ($allowed_zones as $allowed_zone) {
                        $allowed_zones_ids[] = $allowed_zone->id;
                    }

                    /**
                     * prepare and execute the query with venue AND zones filtering
                     */
                    $thisWeekDwelltimes = new TrackingDailyStatsZoneDwelltime;
                    $initialresultsDurations = $thisWeekDwelltimes
                        ->selectRaw('day_epoch, dt_skipped, dt_level_1, dt_level_2, dt_level_3, dt_level_4, dt_level_5, dt_average')
                        ->where('day_epoch', '>=', $start)
                        ->where('day_epoch', '<', $end)
                        ->where('venue_id', $venue_filter)
                        ->whereIn('zone_id', $allowed_zones_ids)
                        ->orderBy('day_epoch', 'asc')
                        ->get();
                }

                /**
                 * process the results from both periods and merge into a single array
                 * 5 buckets/series to fill
                 */
                foreach ($initialresultsDurations as $initialresultsDuration) {
                    $results['dt_skipped'][] = [
                        $initialresultsDuration['day_epoch']*1000,
                        (int)$initialresultsDuration['dt_skipped']
                    ];
                    $results['dt_level_1'][] = [
                        $initialresultsDuration['day_epoch']*1000,
                        (int)$initialresultsDuration['dt_level_1']
                    ];
                    $results['dt_level_2'][] = [
                        $initialresultsDuration['day_epoch']*1000,
                        (int)$initialresultsDuration['dt_level_2']
                    ];
                    $results['dt_level_3'][] = [
                        $initialresultsDuration['day_epoch']*1000,
                        (int)$initialresultsDuration['dt_level_3']
                    ];
                    $results['dt_level_4'][] = [
                        $initialresultsDuration['day_epoch']*1000,
                        (int)$initialresultsDuration['dt_level_4']
                    ];
                    $results['dt_level_5'][] = [
                        $initialresultsDuration['day_epoch']*1000,
                        (int)$initialresultsDuration['dt_level_5']
                    ];
                    $results['dt_average'][] = [
                        $initialresultsDuration['day_epoch']*1000,
                        round((int)$initialresultsDuration['dt_average']/60)
                    ];
                }
            }
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listVenueStatsUniqueVisitorsTimeOfDay(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $results = [];
        $start   = floor($args['start']/1000);
        $end     = floor($args['end']/1000);

        /**
         * filter on the user's primary_venue_id
         */
        $venue_filter = $currentUser->primary_venue_id;

        /**
         * check whether the user is allowed to view full venue stats or not
         * and act accordingly
         */
        if ($currentUser->full_venue_view_allowed == 1) {
            /**
             * user is allowed to view full venue stats
             * prepare and execute the query
             */
            $hourlyVisitors = new TrackingDailyStatsVenueHourlyVisitors;
            $initialresultsHourlyVisitors = $hourlyVisitors
                ->selectRaw('hour, SUM(visitors_total) AS visitors_total')
                ->where('day_epoch', '>=', $start)
                ->where('day_epoch', '<', $end)
                ->where('venue_id', $venue_filter)
                ->orderBy('hour', 'asc')
                ->groupBy('hour')
                ->get();
        } else {
            /**
             * user is NOT allowed to view full venue stats
             * filter on the user's allowed zones AND on the user's primary_venue_id
             *
             * get the allowed zones, then create an array which only contains the ids
             */
            $allowed_zones = collect($currentUser->zones);
            $allowed_zones_ids = [];
            foreach ($allowed_zones as $allowed_zone) {
                $allowed_zones_ids[] = $allowed_zone->id;
            }

            /**
             * prepare and execute the query with venue AND zones filtering
             */
            $hourlyVisitors = new TrackingDailyStatsZoneHourlyVisitors;
            $initialresultsHourlyVisitors = $hourlyVisitors
                ->selectRaw('hour, SUM(visitors_total) AS visitors_total')
                ->where('day_epoch', '>=', $start)
                ->where('day_epoch', '<', $end)
                ->where('venue_id', $venue_filter)
                ->whereIn('zone_id', $allowed_zones_ids)
                ->orderBy('hour', 'asc')
                ->groupBy('hour')
                ->get();
        }

        /**
         * define the averaging factor (=number of days the stats were collected for)
         */
        $averagingFactor = ceil(($end - $start)/(3600*24));

        /**
         * process the results
         */
        foreach ($initialresultsHourlyVisitors as $initialresultsHourlyVisitor) {
            $results[] = [$initialresultsHourlyVisitor['hour'], round($initialresultsHourlyVisitor['visitors_total']/$averagingFactor)];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listVisitorReportWeatherDaily(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * filter on the user's primary_venue_id and get the user's timezone
         */
        $venue_filter = $currentUser->primary_venue_id;
        $timezone     = $currentUser->primaryVenue->time_zone;

        /**
         * user is allowed to view full venue stats
         * prepare and execute the query for the daily stats for BODY
         */
        $start_date = Carbon::createFromTimestamp($args['start']/1000, $timezone);
        $end_date   = Carbon::createFromTimestamp($args['end']/1000, $timezone);

        $startOfDay = $start_date->startOfDay()->format('U');
        $endOfDay   = $end_date->endOfDay()->format('U');

        $weatherResults = new TrackingDailyStatsVenueWeather;
        $initialResults = $weatherResults
            ->select('day_epoch', 'temperature_max', 'temperature_min', 'wind_bearing', 'wind_speed', 'pressure', 'precip_total', 'icon', 'summary')
            ->where('day_epoch', '>=', $startOfDay)
            ->where('day_epoch', '<', $endOfDay)
            ->where('venue_id', $venue_filter)
            ->orderBy('day_epoch', 'asc')
            ->get();

        /**
         * here we create the data for the series as required by the weather chart:
         * - max_temp
         * - min_temp
         * - pressure
         * - precipitation
         */
        $results = [
            'temp_max'      => [],
            'temp_min'      => [],
            'pressure'      => [],
            'precipitation' => []
        ];

        foreach ($initialResults as $result) {
            $results['temp_max'][]      = [
                'x'       => $result->day_epoch * 1000,
                'y'       => (float)$result->temperature_max,
                'icon'    => $result->icon,
                'summary' => $result->summary
            ];
            $results['temp_min'][]      = [
                'x' => $result->day_epoch * 1000,
                'y' => (float)$result->temperature_min
            ];
            $results['pressure'][]      = [
                'x' => $result->day_epoch * 1000,
                'y' => (float)$result->pressure
            ];
            $results['precipitation'][] = [
                'x' => $result->day_epoch * 1000,
                'y' => (float)$result->precip_total
            ];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listVisitorAveragesAlltime(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * filter on the user's primary_venue_id
         */
        $venue_filter = $currentUser->primary_venue_id;

        /**
         * prepare and execute the queries
         * - get the first day
         * - get total number of visitors
         * - get today's visitors
         *
         * TODO:
         * get the first date from the venue object
         * use first date as filter for getting stats
         */
        $venueQuery = new Venue;

        /**
         * Get venue filtered by the primary_venue_id of logged in user
         */
        $current_venue = $venueQuery->with('venue_tracking')->where('id', $currentUser->primary_venue_id)->first();

        /**
         * check whether the user is allowed to view full venue stats or not
         * and act accordingly
         */
        if ($currentUser->full_venue_view_allowed == 1) {
            /**
             * user is allowed to view full venue stats
             * prepare and execute the query
             */
            $totalVisitors   = new TrackingDailyStatsVenueVisitors;
            $first_day_epoch = $current_venue->venue_tracking->capture_start;

            $initialresultsTotalVisitors = $totalVisitors
                ->selectRaw('SUM(visitors_total) AS visitors')
                ->where('venue_id', $venue_filter)
                ->where('day_epoch', '>=', $first_day_epoch)
                ->get();
        } else {
            /**
             * user is NOT allowed to view full venue stats
             * filter on the user's allowed zones AND on the user's primary_venue_id
             *
             * get the allowed zones, then create an array which only contains the ids
             */
            $allowed_zones     = collect($currentUser->zones);
            $allowed_zones_ids = [];
            foreach ($allowed_zones as $allowed_zone) {
                $allowed_zones_ids[] = $allowed_zone->id;
            }

            /**
             * prepare and execute the query with venue AND zones filtering
             */
            $totalVisitors   = new TrackingDailyStatsZoneVisitors;
            $first_day_epoch = $current_venue->venue_tracking->capture_start;

            $initialresultsTotalVisitors = $totalVisitors
                ->selectRaw('SUM(visitors_total) AS visitors')
                ->where('venue_id', $venue_filter)
                ->whereIn('zone_id', $allowed_zones_ids)
                ->where('day_epoch', '>=', $first_day_epoch)
                ->get();
        }

        /**
         * define the starting point of today
         */
        $timezone   = $currentUser->primaryVenue->time_zone;
        $starttoday = Carbon::now($timezone)->startOfDay()->format('U');

        /**
         * prepare and execute the query using a PDO connection provided
         */
        $visitorsQuery = new ProbeRequest;
        $db            = $visitorsQuery->getConnection()->getPdo();

        /**
         * check whether the user is allowed to view full venue stats or not
         * and act accordingly
         */
        if ($currentUser->full_venue_view_allowed == 1) {
            /**
             * user is allowed to view full venue stats
             * create the prepared statement with the vars available for total visitors today
             */
            $totalVisitorsToday = $db->prepare('
                SELECT COUNT(DISTINCT device_uuid) AS total
                FROM probe_request
                WHERE ts > :start
                AND venue_id = :venue_id'
            );
        } else {
            /**
             * user is NOT allowed to view full venue stats
             * filter on the user's allowed zones AND on the user's primary_venue_id
             *
             * get the allowed zones, then create an array which only contains the ids
             */
            $allowed_zones = collect($currentUser->zones);
            $allowed_drones_ids = [];
            foreach ($allowed_zones as $allowed_zone) {
                foreach ($allowed_zone->drones as $drone) {
                    $allowed_drones_ids[] = $drone->id;
                }
            }

            /**
             * create a string containing drone ids, comma seperated, to insert into the PDO statement
             */
            $ids_string = implode(',', $allowed_drones_ids);

            /**
             * execute the prepared statement with the vars available for total visitors today
             */
            $totalVisitorsToday = $db->prepare('
                SELECT COUNT(DISTINCT device_uuid) AS total
                FROM probe_request
                WHERE ts > :start
                AND venue_id = :venue_id
                AND drone_id IN (' . $ids_string . ')'
            );
        }

        /**
         * bind the parameters for the prepared statement
         */
        $totalVisitorsToday->bindParam(':start', $starttoday);
        $totalVisitorsToday->bindParam(':venue_id', $venue_filter);

        /**
         * execute the query for today's visitor count
         */
        $totalVisitorsToday->execute();
        $totalVisitorsToday = $totalVisitorsToday->fetch();

        /**
         * add the total visitors from the stats and the visitors for today
         */
        $totalVisitorsAlltime = $totalVisitorsToday['total'] + $initialresultsTotalVisitors[0]['visitors'];

        /**
         * use Carbon to be able to determine durations
         */
        $timezone  = $currentUser->primaryVenue->time_zone;
        $first_day = Carbon::createFromTimestamp($first_day_epoch, $timezone);
        $today     = Carbon::now($timezone);

        /**
         * calculate the averages
         * TODO: see how the yearly averages turn out, maybe use the months to get non-rounded year count
         */
        if($first_day->diffInDays($today) === 0) {
            $average_daily  = $totalVisitorsAlltime;
        } else {
            $average_daily   = round($totalVisitorsAlltime/($first_day->diffInDays($today)));
        }

        if($first_day->diffInWeeks($today) === 0) {
            $average_weekly  = $totalVisitorsAlltime;
        } else {
            $average_weekly  = round($totalVisitorsAlltime/$first_day->diffInWeeks($today));
        }

        if($first_day->diffInMonths($today) === 0) {
            $average_monthly  = $totalVisitorsAlltime;
        } else {
            $average_monthly = round($totalVisitorsAlltime/$first_day->diffInMonths($today));
        }

        /**
         * if we have a period shorter than a year we divide by 1, else we divide by the returned numeric value
         */
        $yearly_divider = $first_day->diffInDays($today)/365 > 1 ? $first_day->diffInDays($today)/365 : 1;
        $average_yearly = round($totalVisitorsAlltime/$yearly_divider);

        /**
         * assemble the results into a single object
         */
        $results = [
            'total'            => $totalVisitorsAlltime,
            'days'             => $first_day->diffInDays($today),
            'average_daily'    => $average_daily,
            'average_weekly'   => $average_weekly,
            'average_monthly'  => $average_monthly,
            'average_yearly'   => $average_yearly
        ];

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listVisitorReportVisitorsTimeOfDay(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * filter on the user's primary_venue_id and get the venue's timezone
         */
        $venue_filter = $currentUser->primary_venue_id;
        $timezone = $currentUser->primaryVenue->time_zone;

        /**
         * initialise several variables
         */
        $results = [];
        $hours_array = [];

        /**
         * WORK ON SELECTED RANGE FROM HERE
         * - determine start and end
         */
        $start_date = Carbon::createFromTimestamp($args['start']/1000, $timezone);
        $end_date = Carbon::createFromTimestamp($args['end']/1000, $timezone);

        /**
         * get the start date and end date (start of day)
         */
        $start = $start_date->format('U');
        $end = $end_date->format('U');

        /**
         * prepare the query using a "random" PDO connection
         */
        $query = new TrackingDailyStatsVenueUniqueDeviceUuids;
        $db = $query->getConnection()->getPdo();

        if ($start > 0 && $end > 0) {
            if (isset($args['zone_ids'])) {
                /**
                 * prepare and execute the query with venue AND zones filtering
                 */
                $initialresultsVisitors = $db->prepare("
                    SELECT FLOOR(AVG(visitors)) AS visitors,
                           hour_epoch,
                           hour
                    FROM
                    (
                        SELECT COUNT(DISTINCT(device_uuid)) AS visitors,
                            hour_epoch,
                            hour
                        FROM
                        (
                            SELECT device_uuid,
                                   day_epoch,
                                   (day_epoch + (3600*hour)) AS hour_epoch,
                                   hour
                            FROM tracking_daily_stats_zone_unique_device_uuids_per_hour
                            PARTITION (p" . $venue_filter . ")
                            WHERE (day_epoch + (3600*hour)) >= :start_1
                            AND (day_epoch + (3600*hour)) < :end_1
                            AND zone_id IN (" . $args['zone_ids'] . ")
                        ) AS temp1
                        GROUP BY day_epoch, hour
                    ) AS temp2
                    GROUP BY hour"
                );
            }
            else {
                /**
                 * check whether the user is allowed to view full venue stats or not
                 * and act accordingly
                 */
                if ($currentUser->full_venue_view_allowed == 1) {
                    /**
                     * user is allowed to view full venue stats
                     * prepare and execute the query
                     */
                    $initialresultsVisitors = $db->prepare(
                        "SELECT FLOOR(AVG(visitors)) AS visitors,
                               hour_epoch,
                               hour
                        FROM
                        (
                            SELECT COUNT(DISTINCT(device_uuid)) AS visitors,
                                hour_epoch,
                                hour
                            FROM
                            (
                                SELECT device_uuid,
                                       day_epoch,
                                       (day_epoch + (3600*hour)) AS hour_epoch,
                                       hour
                                FROM tracking_daily_stats_venue_unique_device_uuids_per_hour
                            PARTITION (p" . $venue_filter . ")
                                WHERE (day_epoch + (3600*hour)) >= :start_1
                                AND (day_epoch + (3600*hour)) < :end_1
                            ) AS temp1
                            GROUP BY day_epoch, hour
                        ) AS temp2
                        GROUP BY hour"
                    );
                } else {
                    /**
                     * user is NOT allowed to view full venue stats
                     * - filter on the user's allowed zones AND on the user's primary_venue_id
                     * - get the allowed zones, then create an array which only contains their ids
                     */
                    $allowed_zones = collect($currentUser->zones);
                    $allowed_zone_ids = [];

                    foreach ($allowed_zones as $zone) {
                        $allowed_zone_ids[] = $zone->id;
                    }

                    /**
                     * create a string containing zone ids, comma seperated, to insert into the PDO statement
                     */
                    $ids_string = implode(',', $allowed_zone_ids);

                    /**
                     * prepare and execute the query with venue AND zones filtering
                     */
                    $initialresultsVisitors = $db->prepare(
                        "SELECT FLOOR(AVG(visitors)) AS visitors,
                               hour_epoch,
                               hour
                        FROM
                        (
                            SELECT COUNT(DISTINCT(device_uuid)) AS visitors,
                                hour_epoch,
                                hour
                            FROM
                            (
                                SELECT device_uuid,
                                       day_epoch,
                                       (day_epoch + (3600*hour)) AS hour_epoch,
                                       hour
                                FROM tracking_daily_stats_zone_unique_device_uuids_per_hour
                                PARTITION (p" . $venue_filter . ")
                                WHERE (day_epoch + (3600*hour)) >= :start_1
                                AND (day_epoch + (3600*hour)) < :end_1
                                AND zone_id IN (" . $ids_string . ")
                            ) AS temp1
                            GROUP BY day_epoch, hour
                        ) AS temp2
                        GROUP BY hour"
                    );
                }
            }
        }

        /**
         * bind the parameters to the selected query
         */
        $initialresultsVisitors->bindParam(':start_1', $start);
        $initialresultsVisitors->bindParam(':end_1', $end);

        /**
         * execute the query for total visitors
         */
        $initialresultsVisitors->execute();

        $tempresults = [];
        /**
         * loop through the array and add to tempresults
         */
        foreach ($initialresultsVisitors as $item) {
            $results[] = [$item['hour'], (int)$item['visitors']];
        }

        $results = array_values(array_sort($results, function ($value) {
            return $value[0];
        }));

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listVisitorReportNewVsRepeat(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * filter on the user's primary_venue_id and get the user's timezone
         */
        $venue_filter = $currentUser->primary_venue_id;
        $timezone = $currentUser->primaryVenue->time_zone;

        /**
         * initialise several variables
         */
        $results     = ['new' => [], 'repeat' => [], 'event' => []];
        $hours_array = [];

        /*************************************************************************************
         * WORK ON SELECTED RANGE FROM HERE
         * - get the desired time zone
         * - set $start, $end
         *************************************************************************************/

        $start_date = Carbon::createFromTimestamp($args['start']/1000, $timezone);
        $end_date = Carbon::createFromTimestamp($args['end']/1000, $timezone);

        /**
         * get the start date and end date (start of day)
         */
        $start = $start_date->format('U');
        $end = $end_date->format('U');

        /**
         * prepare the query using a "random" PDO connection
         */
        $query = new TrackingDailyStatsVenueUniqueDeviceUuids;
        $db = $query->getConnection()->getPdo();

        if ($start > 0 && $end > 0) {
            if (isset($args['zone_ids'])) {

                /**
                 * create the prepared statement
                 */
                $visitor_counts = $db->prepare("
                    SELECT COUNT(CASE WHEN is_repeat = 0 THEN 1 END) AS new_visitors,
                        COUNT(CASE WHEN is_repeat = 1 THEN 1 END) AS repeat_visitors,
                        day_epoch,
                        hour_epoch
                    FROM
                    (
                        SELECT device_uuid,
                            day_epoch,
                            hour,
                            is_repeat,
                            (day_epoch + (3600*hour)) AS hour_epoch
                        FROM tracking_daily_stats_zone_unique_device_uuids_per_hour
                        PARTITION (p" . $venue_filter . ")
                        WHERE (day_epoch + (3600*hour)) >= :start_1
                        AND (day_epoch + (3600*hour)) < :end_1
                        AND zone_id IN (" . $args['zone_ids'] . ")
                        GROUP BY day_epoch, device_uuid
                    ) AS temp1
                    GROUP BY day_epoch"
                );
            }
            else {
                /**
                 * check whether the user is allowed to view full venue stats or not
                 * and act accordingly
                 */
                if ($currentUser->full_venue_view_allowed == 1) {
                    /**
                     * user is allowed to view full venue stats
                     * create the prepared statement
                     */
                    $visitor_counts = $db->prepare('
                        SELECT COUNT(CASE WHEN is_repeat = 0 THEN 1 END) AS new_visitors,
                            COUNT(CASE WHEN is_repeat = 1 THEN 1 END) AS repeat_visitors,
                            day_epoch,
                            hour_epoch
                        FROM
                        (
                            SELECT device_uuid,
                                day_epoch,
                                hour,
                                is_repeat,
                                (day_epoch + (3600*hour)) AS hour_epoch
                            FROM tracking_daily_stats_venue_unique_device_uuids_per_hour
                            PARTITION (p' . $venue_filter . ')
                            WHERE (day_epoch + (3600*hour)) >= :start_1
                            AND (day_epoch + (3600*hour)) < :end_1
                            GROUP BY day_epoch, device_uuid
                        ) AS temp1
                        GROUP BY day_epoch'
                    );
                } else {
                    /**
                     * user is NOT allowed to view full venue stats
                     * - filter on the user's allowed zones AND on the user's primary_venue_id
                     * - get the allowed zones, then create an array which only contains their ids
                     */
                    $allowed_zones      = collect($currentUser->zones);
                    $allowed_zone_ids   = [];

                    foreach ($allowed_zones as $zone) {
                        $allowed_zone_ids[] = $zone->id;
                    }

                    /**
                     * create a string containing zone ids, comma seperated, to insert into the PDO statement
                     */
                    $ids_string = implode(',', $allowed_zone_ids);

                    /**
                     * create the prepared statement
                     */
                    $visitor_counts = $db->prepare("
                        SELECT COUNT(CASE WHEN is_repeat = 0 THEN 1 END) AS new_visitors,
                            COUNT(CASE WHEN is_repeat = 1 THEN 1 END) AS repeat_visitors,
                            day_epoch,
                            hour_epoch
                        FROM
                        (
                            SELECT device_uuid,
                                day_epoch,
                                hour,
                                is_repeat,
                                (day_epoch + (3600*hour)) AS hour_epoch
                            FROM tracking_daily_stats_zone_unique_device_uuids_per_hour
                        PARTITION (p" . $venue_filter . ")
                            WHERE (day_epoch + (3600*hour)) >= :start_1
                            AND (day_epoch + (3600*hour)) < :end_1
                            AND zone_id IN (" . $ids_string . ")
                            GROUP BY day_epoch, device_uuid
                        ) AS temp1
                        GROUP BY day_epoch"
                    );
                }
            }

            /**
             * bind the parameters to the selected query
             */
            $visitor_counts->bindParam(':start_1', $start);
            $visitor_counts->bindParam(':end_1', $end);

            /**
             * execute the query for total visitors
             */
            $visitor_counts->execute();
        }

        // Get all the events which were ran on between these dates
        $event = new Event;
        if ($authorizer->checkAccess($currentUser, 'uri_site_admin')) {
            $dailyEvents = $event
                ->with('event_category')
                ->where('start_date', '>=', $start)
                ->where('end_date', '<=', $end)
                ->where('venue_id', $venue_filter)
                ->get();
        }
        else {
            $dailyEvents = $event
                ->with('event_category')
                ->where('start_date', '>=', $start)
                ->where('end_date', '<=', $end)
                ->where('admin_event', 0)
                ->where('venue_id', $venue_filter)
                ->get();
        }

        foreach($dailyEvents as $event) {
            $results['event'][] = [
                'start_date' => $event->start_date * 1000, 
                'end_date' => $event->end_date * 1000, 
                'name' => $event->name,
                'color' => $event->event_category->category_color,
                'category_id' => $event->event_category_id
            ];
        }

        foreach($visitor_counts as $visitor_count) {
            $results['new'][] = [$visitor_count['day_epoch'] * 1000, $visitor_count['new_visitors']];
            $results['repeat'][] = [$visitor_count['day_epoch'] * 1000, $visitor_count['repeat_visitors']];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listVisitorReportZoneVisitorsComparison(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * filter on the user's primary_venue_id and get the user's timezone
         */
        $venue_filter = $currentUser->primary_venue_id;
        $timezone = $currentUser->primaryVenue->time_zone;

        /**
         * initialise several variables
         */
        $results = [];
        $hours_array = [];

        /*************************************************************************************
         * WORK ON SELECTED RANGE FROM HERE
         * - get the desired time zone
         * - set $start, $end
         *************************************************************************************/
        $start_date = Carbon::createFromTimestamp($args['start']/1000, $timezone);
        $end_date = Carbon::createFromTimestamp($args['end']/1000, $timezone);

        /**
         * get the start date and end date (start of day)
         */
        $start = $start_date->format('U');
        $end = $end_date->format('U');

        /**
         * prepare the query using a "random" PDO connection
         */
        $query = new TrackingDailyStatsZoneUniqueDeviceUuids;
        $db = $query->getConnection()->getPdo();

        if ($start > 0 && $end > 0) {

            if (isset($args['zone_ids'])) {
                /**
                 * prepare and execute the query with venue AND zones filtering
                 */
                $initialresultsVisitors = $db->prepare('
                    SELECT zone_id,
                        zone.name AS zone_name,
                        COUNT(CASE WHEN is_repeat = 0 THEN 1 END) AS new_visitors,
                        COUNT(CASE WHEN is_repeat = 1 THEN 1 END) AS repeat_visitors,
                        hour_epoch
                    FROM
                    (
                        SELECT device_uuid,
                               day_epoch,
                               hour,
                               zone_id,
                               is_repeat,
                               (day_epoch + (3600*hour)) AS hour_epoch
                        FROM tracking_daily_stats_zone_unique_device_uuids_per_hour
                        PARTITION (p' . $venue_filter . ')
                        WHERE (day_epoch + (3600*hour)) >= :start_1
                        AND (day_epoch + (3600*hour)) < :end_1
                        AND zone_id IN (' . $args['zone_ids'] . ')
                        GROUP BY day_epoch, zone_id, device_uuid
                    ) AS temp1
                    INNER JOIN zone ON zone_id = zone.id 
                    GROUP BY zone_id'
                );
            }
            else {
                /**
                 * check whether the user is allowed to view full venue stats or not
                 * and act accordingly
                 */
                if ($currentUser->full_venue_view_allowed == 1) {
                    /**
                     * user is allowed to view full venue stats
                     * prepare and execute the query
                     */
                    $initialresultsVisitors = $db->prepare('
                        SELECT zone_id,
                            zone.name AS zone_name,
                            COUNT(CASE WHEN is_repeat = 0 THEN 1 END) AS new_visitors,
                            COUNT(CASE WHEN is_repeat = 1 THEN 1 END) AS repeat_visitors,
                            hour_epoch
                        FROM
                        (
                            SELECT device_uuid,
                                   day_epoch,
                                   hour,
                                   zone_id,
                                   is_repeat,
                                   (day_epoch + (3600*hour)) AS hour_epoch
                            FROM tracking_daily_stats_zone_unique_device_uuids_per_hour
                            WHERE (day_epoch + (3600*hour)) >= :start_1
                            AND (day_epoch + (3600*hour)) < :end_1
                            AND venue_id = :venue_id
                            GROUP BY day_epoch, zone_id, device_uuid
                        ) AS temp1
                        INNER JOIN zone ON zone_id = zone.id 
                        GROUP BY zone_id'
                    );

                    $initialresultsVisitors->bindParam(':venue_id', $venue_filter);
                } else {
                    /**
                     * user is NOT allowed to view full venue stats
                     * - filter on the user's allowed zones AND on the user's primary_venue_id
                     * - get the allowed zones, then create an array which only contains their ids
                     */
                    $allowed_zones = collect($currentUser->zones);
                    $allowed_zone_ids = [];

                    foreach ($allowed_zones as $zone) {
                        $allowed_zone_ids[] = $zone->id;
                    }

                    /**
                     * create a string containing zone ids, comma seperated, to insert into the PDO statement
                     */
                    $ids_string = implode(',', $allowed_zone_ids);

                    /**
                     * prepare and execute the query with venue AND zones filtering
                     */
                    $initialresultsVisitors = $db->prepare('
                        SELECT zone_id,
                            zone.name AS zone_name,
                            COUNT(CASE WHEN is_repeat = 0 THEN 1 END) AS new_visitors,
                            COUNT(CASE WHEN is_repeat = 1 THEN 1 END) AS repeat_visitors,
                            hour_epoch
                        FROM
                        (
                            SELECT device_uuid,
                                   day_epoch,
                                   hour,
                                   zone_id,
                                   is_repeat,
                                   (day_epoch + (3600*hour)) AS hour_epoch
                            FROM tracking_daily_stats_zone_unique_device_uuids_per_hour
                            WHERE (day_epoch + (3600*hour)) >= :start_1
                            AND (day_epoch + (3600*hour)) < :end_1
                            AND venue_id = :venue_id
                            AND zone_id IN (' . $ids_string . ')
                            GROUP BY day_epoch, zone_id, device_uuid
                        ) AS temp1
                        INNER JOIN zone ON zone_id = zone.id 
                        GROUP BY zone_id'
                    );


                    $initialresultsVisitors->bindParam(':venue_id', $venue_filter);
                }
            }
        }

        /**
         * bind the parameters to the selected query
         */
        $initialresultsVisitors->bindParam(':start_1', $start);
        $initialresultsVisitors->bindParam(':end_1', $end);

        /**
         * execute the query for total visitors
         */
        $initialresultsVisitors->execute();

        foreach ($initialresultsVisitors as $item) {
            $results[] = [$item['zone_name'], $item['repeat_visitors'], $item['new_visitors']];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listVisitorReportNewVsRepeatOld(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * filter on the user's primary_venue_id and get the user's timezone
         */
        $venue_filter = $currentUser->primary_venue_id;
        $timezone = $currentUser->primaryVenue->time_zone;

        /**
         * initialise several variables
         */
        $results = [
            'new' => [],
            'repeat' => []
        ];

        /*************************************************************************************
         * WORK ON SELECTED RANGE FROM HERE
         * - get the desired time zone
         * - set $start, $end
         *
         * TODO:
         * - determine full days within the body
         * - get daily stats for it
         * - determine whether tail is today or not
         *  - yes: we get data from probe_requests
         *  - no: we get hourly stats for it
         * - determine head and get hourly stats for it
         * - using Carbon to copy timestamps you need to use the copy function!!!
         *************************************************************************************/
        $start = floor($args['start']/1000);
        $end = floor($args['end']/1000);
        $this->determineHeadBodyTail($start, $end, $timezone);

        /**
         * execute query for HEAD of selected range
         */
        if($this->head_length > 0) {
            /**
             * get the start of day for head and get the hour of head_start and head_end
             */
            $head_start_hour = $this->head_start->format('G');
            $head_end_hour = $this->head_end->subSecond()->format('G');

            /**
             * check whether the user is allowed to view full venue stats or not
             * and act accordingly
             */
            if ($currentUser->full_venue_view_allowed == 1) {
                /**
                 * user is allowed to view full venue stats
                 * prepare and execute the query
                 */
                $headVisitors = new TrackingDailyStatsVenueHourlyVisitors;
                $initialresultsHeadVisitors = $headVisitors
                    ->selectRaw('day_epoch, SUM(visitors_new) AS new, SUM(visitors_total) AS total')
                    ->where('day_epoch', '=', $this->head_day_epoch)
                    ->where('hour', '<=', $head_end_hour)
                    ->where('hour', '>=', $head_start_hour)
                    ->where('tracking_daily_stats_venue_visitors_per_hour.venue_id', $venue_filter)
                    ->groupBy('day_epoch')
                    ->get();
            } 
            else {
                /**
                 * user is NOT allowed to view full venue stats
                 * filter on the user's allowed zones AND on the user's primary_venue_id
                 *
                 * get the allowed zones, then create an array which only contains the ids
                 */
                $allowed_zones = collect($currentUser->zones);
                $allowed_zones_ids = [];
                foreach ($allowed_zones as $allowed_zone) {
                    $allowed_zones_ids[] = $allowed_zone->id;
                }

                /**
                 * prepare and execute the query with venue AND zones filtering
                 */
                $headVisitors = new TrackingDailyStatsZoneHourlyVisitors;
                $initialresultsHeadVisitors = $headVisitors
                    ->selectRaw('day_epoch, SUM(visitors_new) AS new, SUM(visitors_total) AS total')
                    ->where('day_epoch', '=', $this->head_day_epoch)
                    ->where('hour', '<=', $head_end_hour)
                    ->where('hour', '>=', $head_start_hour)
                    ->where('tracking_daily_stats_zone_visitors_per_hour.venue_id', $venue_filter)
                    ->whereIn('zone_id', $allowed_zones_ids)
                    ->groupBy('day_epoch')
                    ->get();
            }

            foreach($initialresultsHeadVisitors as $day) {
                $results['new'][] = [$day['day_epoch']*1000, (int)$day['new']];
                $results['repeat'][] = [$day['day_epoch']*1000, (int)$day['total'] - (int)$day['new']];
                $results['event'][] = [$day['day_epoch']*1000, $day['event_name']];
            }
        }

        /**
         * execute query for BODY of selected range
         */
        if($this->body_length > 0) {
            /**
             * get body_start and body_end formatted as epoch
             */
            $body_start = $this->body_start->format('U');
            $body_end = $this->body_end->format('U');

            /**
             * check whether the user is allowed to view full venue stats or not
             * and act accordingly
             */
            if ($currentUser->full_venue_view_allowed == 1) {
                /**
                 * user is allowed to view full venue stats
                 * prepare and execute the query for the daily stats for BODY
                 */
                $bodyVisitors = new TrackingDailyStatsVenueVisitors;
                $initialresultsbodyVisitors = $bodyVisitors
                    ->selectRaw('day_epoch, visitors_new, visitors_total')
                    ->where('day_epoch', '>=', $body_start)
                    ->where('day_epoch', '<', $body_end)
                    ->where('tracking_daily_stats_venue_visitors.venue_id', $venue_filter)
                    ->orderBy('day_epoch', 'asc')
                    ->get();
            } 
            else {
                /**
                 * user is NOT allowed to view full venue stats
                 * filter on the user's allowed zones AND on the user's primary_venue_id
                 *
                 * get the allowed zones, then create an array which only contains the ids
                 */
                $allowed_zones = collect($currentUser->zones);
                $allowed_zones_ids = [];
                foreach ($allowed_zones as $allowed_zone) {
                    $allowed_zones_ids[] = $allowed_zone->id;
                }

                /**
                 * prepare and execute the query for the daily stats for BODY with venue AND zones filtering
                 */
                $bodyVisitors = new TrackingDailyStatsZoneVisitors;
                $initialresultsbodyVisitors = $bodyVisitors
                    ->selectRaw('day_epoch, SUM(visitors_new) AS visitors_new, SUM(visitors_total) AS visitors_total')
                    ->where('day_epoch', '>=', $body_start)
                    ->where('day_epoch', '<', $body_end)
                    ->where('tracking_daily_stats_zone_visitors.venue_id', $venue_filter)
                    ->whereIn('zone_id', $allowed_zones_ids)
                    ->groupBy('day_epoch')
                    ->orderBy('day_epoch', 'asc')
                    ->get();
            }

            foreach($initialresultsbodyVisitors as $day) {
                $results['new'][] = [$day['day_epoch']*1000, $day['visitors_new']];
                $results['repeat'][] = [$day['day_epoch']*1000, $day['visitors_total'] - $day['visitors_new']];
            }
        }

        /**
         * execute query for TAIL of selected range
         */
        if($this->tail_length > 0) {
            /**
             * we first determine whether TAIL is in today or not
             */
            if($this->tail_in_today) {
                /**
                 * get body_start and body_end formatted as epoch
                 */
                $tail_start = $this->tail_start->format('U');
                $tail_end = $this->tail_end->format('U');

                /**
                 * prepare and execute the query using a PDO connection provided
                 */
                $repeatVisitorsQuery = new ProbeRequest;
                $db = $repeatVisitorsQuery->getConnection()->getPdo();

                /**
                 * check whether the user is allowed to view full venue stats or not
                 * and act accordingly
                 */
                if ($currentUser->full_venue_view_allowed == 1) {
                    /**
                     * user is allowed to view full venue stats
                     * create the prepared statement with the vars available for repeat visitors today
                     * NOTE: repeat is an SQL keyword, don't use it in the query
                     */
                    $repeatVisitors = $db->prepare('
                        SELECT COUNT(DISTINCT probes.device_uuid) AS `returning`
                        FROM probe_request AS probes
                        INNER JOIN tracking_daily_stats_venue_device_uuid_cache AS repeat_visitors
                        ON probes.device_uuid = repeat_visitors.device_uuid
                        WHERE repeat_visitors.first_seen < :start_1
                        AND probes.ts > :start_2
                        AND ts < :end
                        AND probes.venue_id = :venue_id'
                    );
                } else {
                    /**
                     * user is NOT allowed to view full venue stats
                     * filter on the user's allowed zones AND on the user's primary_venue_id
                     *
                     * get the allowed zones, then create an array which only contains the ids
                     */
                    $allowed_zones = collect($currentUser->zones);
                    $allowed_drones_ids = [];
                    foreach ($allowed_zones as $allowed_zone) {
                        foreach ($allowed_zone->drones as $drone) {
                            $allowed_drones_ids[] = $drone->id;
                        }
                    }

                    /**
                     * create a string containing drone ids, comma seperated, to insert into the PDO statement
                     */
                    $ids_string = implode(',', $allowed_drones_ids);

                    /**
                     * execute the prepared statement with the vars available for repeat visitors today
                     * NOTE: repeat is an SQL keyword, don't use it in the query
                     */
                    $repeatVisitors = $db->prepare('
                        SELECT COUNT(DISTINCT probes.device_uuid) AS `returning`
                        FROM probe_request AS probes
                        INNER JOIN tracking_daily_stats_venue_device_uuid_cache AS repeat_visitors
                        ON probes.device_uuid = repeat_visitors.device_uuid
                        WHERE repeat_visitors.first_seen < :start_1
                        AND probes.ts > :start_2
                        AND ts < :end
                        AND probes.venue_id = :venue_id
                        AND drone_id IN (' . $ids_string . ')'
                    );
                }

                /**
                 * bind the parameters for the prepared statement
                 * in this case: with PDO prepared statements we couldn't use the same parameter twice...
                 */
                $repeatVisitors->bindParam(':start_1', $tail_start);
                $repeatVisitors->bindParam(':start_2', $tail_start);
                $repeatVisitors->bindParam(':end', $tail_end);
                $repeatVisitors->bindParam(':venue_id', $venue_filter);

                /**
                 * execute the query
                 */
                $repeatVisitors->execute();
                $initialresultsRepeatVisitors = $repeatVisitors->fetch();

                /**
                 * check whether the user is allowed to view full venue stats or not
                 * and act accordingly
                 */
                if ($currentUser->full_venue_view_allowed == 1) {
                    /**
                     * user is allowed to view full venue stats
                     * create the prepared statement with the vars available for total visitors today
                     */
                    $totalVisitors = $db->prepare('
                        SELECT COUNT(DISTINCT device_uuid) AS total
                        FROM probe_request
                        WHERE ts > :start
                        AND ts < :end
                        AND venue_id = :venue_id'
                    );

                } else {
                    /**
                     * user is NOT allowed to view full venue stats
                     * filter on the user's allowed zones AND on the user's primary_venue_id
                     *
                     * get the allowed zones, then create an array which only contains the ids
                     */
                    $allowed_zones = collect($currentUser->zones);
                    $allowed_drones_ids = [];
                    foreach ($allowed_zones as $allowed_zone) {
                        foreach ($allowed_zone->drones as $drone) {
                            $allowed_drones_ids[] = $drone->id;
                        }
                    }

                    /**
                     * create a string containing drone ids, comma seperated, to insert into the PDO statement
                     */
                    $ids_string = implode(',', $allowed_drones_ids);

                    /**
                     * execute the prepared statement with the vars available for total visitors today
                     */
                    $totalVisitors = $db->prepare('
                        SELECT COUNT(DISTINCT device_uuid) AS total
                        FROM probe_request
                        WHERE ts > :start
                        AND ts < :end
                        AND venue_id = :venue_id
                        AND drone_id IN (' . $ids_string . ')'
                    );
                }

                /**
                 * bind the parameters for the prepared statement
                 */
                $totalVisitors->bindParam(':start', $tail_start);
                $totalVisitors->bindParam(':end', $tail_end);
                $totalVisitors->bindParam(':venue_id', $venue_filter);

                /**
                 * execute the query
                 */
                $totalVisitors->execute();
                $initialresultsTotalVisitors = $totalVisitors->fetch();

                $startoftoday        = Carbon::now($timezone)->startOfDay()->format('U') * 1000;
                $results['repeat'][] = [$startoftoday, $initialresultsRepeatVisitors['returning']];
                $results['new'][]    = [$startoftoday, $initialresultsTotalVisitors['total'] - $initialresultsRepeatVisitors['returning']];
            } else {
                /**
                 * TAIL is not in today so we get hourly stats for TAIL
                 * - get the start of day for tail and get the hour of tail_start and tail_end
                 */
                $tail_start_hour = $this->tail_start->format('G');
                $tail_end_hour = $this->tail_end->format('G');

                /**
                 * check whether the user is allowed to view full venue stats or not
                 * and act accordingly
                 */
                if ($currentUser->full_venue_view_allowed == 1) {
                    /**
                     * user is allowed to view full venue stats
                     * prepare and execute the query
                     */
                    $tailVisitors = new TrackingDailyStatsVenueHourlyVisitors;
                    $initialresultsTailVisitors = $tailVisitors
                        ->selectRaw('day_epoch, SUM(visitors_new) AS new, SUM(visitors_total) AS total')
                        ->where('day_epoch', '=', $this->tail_day_epoch)
                        ->where('hour', '<', $tail_end_hour)
                        ->where('hour', '>=', $tail_start_hour)
                        ->where('venue_id', $venue_filter)
                        ->groupBy('day_epoch')
                        ->get();

                } else {
                    /**
                     * user is NOT allowed to view full venue stats
                     * filter on the user's allowed zones AND on the user's primary_venue_id
                     *
                     * get the allowed zones, then create an array which only contains the ids
                     */
                    $allowed_zones = collect($currentUser->zones);
                    $allowed_zones_ids = [];
                    foreach ($allowed_zones as $allowed_zone) {
                        $allowed_zones_ids[] = $allowed_zone->id;
                    }

                    /**
                     * prepare and execute the query with venue AND zones filtering
                     */
                    $tailVisitors = new TrackingDailyStatsZoneHourlyVisitors;
                    $initialresultsTailVisitors = $tailVisitors
                        ->selectRaw('day_epoch, SUM(visitors_new) AS new, SUM(visitors_total) AS total')
                        ->where('day_epoch', '=', $this->tail_day_epoch)
                        ->where('hour', '<', $tail_end_hour)
                        ->where('hour', '>=', $tail_start_hour)
                        ->where('venue_id', $venue_filter)
                        ->whereIn('zone_id', $allowed_zones_ids)
                        ->groupBy('day_epoch')
                        ->get();
                }

                foreach($initialresultsTailVisitors as $day) {
                    $results['new'][] = [$day['day_epoch']*1000, (int)$day['new']];
                    $results['repeat'][] = [$day['day_epoch']*1000, (int)$day['total'] - (int)$day['new']];
                }
            }
        }

        // Get all the events which were ran on between these dates
        $event = new Event;
        if ($authorizer->checkAccess($currentUser, 'uri_site_admin')) {
            $dailyEvents = $event
                ->with('event_category')
                ->where('start_date', '>=', $start)
                ->where('end_date', '<=', $end)
                ->where('venue_id', $venue_filter)
                ->get();
        }
        else {
            $dailyEvents = $event
                ->with('event_category')
                ->where('start_date', '>=', $start)
                ->where('end_date', '<=', $end)
                ->where('admin_event', 0)
                ->where('venue_id', $venue_filter)
                ->get();
        }

        foreach($dailyEvents as $event) {
            $results['event'][] = [
                'start_date'  => $event->start_date * 1000, 
                'end_date'    => $event->end_date * 1000, 
                'name'        => $event->name,
                'color'       => $event->event_category->category_color,
                'category_id' => $event->event_category_id
            ];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listVisitorReportVisitorsTimeOfDayOld(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * filter on the user's primary_venue_id and get the venue's timezone
         */
        $venue_filter = $currentUser->primary_venue_id;
        $timezone = $currentUser->primaryVenue->time_zone;

        /**
         * initialise several variables
         */
        $results = [];

        /**
         * WORK ON SELECTED RANGE FROM HERE
         * - determine start and end
         * - determine head/body/tail
         */
        $start = floor($args['start']/1000);
        $end = floor($args['end']/1000);
        $this->determineHeadBodyTail($start, $end, $timezone);

        /**
         * execute query for HEAD of selected range
         */
        if($this->head_length > 0) {
            /**
             * get the start of day for head and get the hour of head_start and head_end
             */
            $head_start_hour = $this->head_start->format('G');
            $head_end_hour = $this->head_end->subSecond()->format('G');

            /**
             * check whether the user is allowed to view full venue stats or not
             * and act accordingly
             */
            if ($currentUser->full_venue_view_allowed == 1) {
                /**
                 * user is allowed to view full venue stats
                 * prepare and execute the query
                 */
                $headVisitors = new TrackingDailyStatsVenueHourlyVisitors;
                $initialresultsHeadVisitors = $headVisitors
                    ->selectRaw('hour, SUM(visitors_total) AS visitors')
                    ->where('day_epoch', '=', $this->head_day_epoch)
                    ->where('hour', '<=', $head_end_hour)
                    ->where('hour', '>=', $head_start_hour)
                    ->where('venue_id', $venue_filter)
                    ->groupBy('hour')
                    ->get();
            } else {
                /**
                 * user is NOT allowed to view full venue stats
                 * filter on the user's allowed zones AND on the user's primary_venue_id
                 *
                 * get the allowed zones, then create an array which only contains the ids
                 */
                $allowed_zones = collect($currentUser->zones);
                $allowed_zones_ids = [];
                foreach ($allowed_zones as $allowed_zone) {
                    $allowed_zones_ids[] = $allowed_zone->id;
                }

                /**
                 * prepare and execute the query with venue AND zones filtering
                 */
                $headVisitors = new TrackingDailyStatsZoneHourlyVisitors;
                $initialresultsHeadVisitors = $headVisitors
                    ->selectRaw('hour, SUM(visitors_total) AS visitors')
                    ->where('day_epoch', '=', $this->head_day_epoch)
                    ->where('hour', '<=', $head_end_hour)
                    ->where('hour', '>=', $head_start_hour)
                    ->where('venue_id', $venue_filter)
                    ->whereIn('zone_id', $allowed_zones_ids)
                    ->groupBy('hour')
                    ->get();
            }
        } 
        else {
            /**
             * we do nothing and return a zero value
             */
            $initialresultsHeadVisitors = 0;
        }

        /**
         * execute query for BODY of selected range
         */
        if($this->body_length > 0) {
            /**
             * get body_start and body_end formatted as epoch
             */
            $body_start = $this->body_start->format('U');
            $body_end = $this->body_end->format('U');

            /**
             * check whether the user is allowed to view full venue stats or not
             * and act accordingly
             */
            if ($currentUser->full_venue_view_allowed == 1) {
                /**
                 * user is allowed to view full venue stats
                 * prepare and execute the query for BODY
                 */
                $bodyVisitors = new TrackingDailyStatsVenueHourlyVisitors;
                $initialresultsBodyVisitors = $bodyVisitors
                    ->selectRaw('hour, SUM(visitors_total) AS visitors')
                    ->where('day_epoch', '>=', $body_start)
                    ->where('day_epoch', '<', $body_end)
                    ->where('venue_id', $venue_filter)
                    ->orderBy('hour', 'asc')
                    ->groupBy('hour')
                    ->get();

            } else {
                /**
                 * user is NOT allowed to view full venue stats
                 * filter on the user's allowed zones AND on the user's primary_venue_id
                 *
                 * get the allowed zones, then create an array which only contains the ids
                 */
                $allowed_zones = collect($currentUser->zones);
                $allowed_zones_ids = [];
                foreach ($allowed_zones as $allowed_zone) {
                    $allowed_zones_ids[] = $allowed_zone->id;
                }

                /**
                 * prepare and execute the query for BODY with venue AND zones filtering
                 */
                $bodyVisitors = new TrackingDailyStatsZoneHourlyVisitors;
                $initialresultsBodyVisitors = $bodyVisitors
                    ->selectRaw('hour, SUM(visitors_total) AS visitors')
                    ->where('day_epoch', '>=', $body_start)
                    ->where('day_epoch', '<', $body_end)
                    ->where('venue_id', $venue_filter)
                    ->whereIn('zone_id', $allowed_zones_ids)
                    ->orderBy('hour', 'asc')
                    ->groupBy('hour')
                    ->get();
            }
        } else {
            /**
             * we do nothing and return zero values for new and repeat
             */
            $initialresultsBodyVisitors = 0;
        }

        /**
         * execute query for TAIL of selected range
         */
        if($this->tail_length > 0) {
            /**
             * we first determine whether TAIL is in today or not
             */
            if($this->tail_in_today) {
                /**
                 * get body_start and body_end formatted as epoch
                 */
                $tail_start = $this->tail_start->format('U');
                $tail_end = $this->tail_end->format('U');

                /**
                 * prepare and execute the query using a PDO connection provided
                 */
                $newVisitorsQuery = new ProbeRequest;
                $db = $newVisitorsQuery->getConnection()->getPdo();

                /**
                 * check whether the user is allowed to view full venue stats or not
                 * and act accordingly
                 */
                if ($currentUser->full_venue_view_allowed == 1) {
                    /**
                     * user is allowed to view full venue stats
                     * create the prepared statement with the vars available for total visitors in TAIL today
                     */
                    $totalVisitors = $db->prepare("
                        SELECT FROM_UNIXTIME(ts,'%k') AS hour,
                        COUNT(DISTINCT device_uuid) AS visitors
                        FROM probe_request
                        WHERE ts > :start
                        AND ts < :end
                        AND venue_id = :venue_id
                        GROUP BY hour"
                    );
                } else {
                    /**
                     * user is NOT allowed to view full venue stats
                     * filter on the user's allowed zones AND on the user's primary_venue_id
                     *
                     * get the allowed zones, then create an array which only contains the ids
                     */
                    $allowed_zones = collect($currentUser->zones);
                    $allowed_drones_ids = [];
                    foreach ($allowed_zones as $allowed_zone) {
                        foreach ($allowed_zone->drones as $drone) {
                            $allowed_drones_ids[] = $drone->id;
                        }
                    }

                    /**
                     * create a string containing drone ids, comma seperated, to insert into the PDO statement
                     */
                    $ids_string = implode(',', $allowed_drones_ids);

                    /**
                     * execute the prepared statement with the vars available for total visitors in TAIL today
                     */
                    $totalVisitors = $db->prepare("
                        SELECT FROM_UNIXTIME(ts,'%k') AS hour,
                        COUNT(DISTINCT device_uuid) AS visitors
                        FROM probe_request
                        WHERE ts > :start
                        AND ts < :end
                        AND venue_id = :venue_id
                        AND drone_id IN (" . $ids_string . ")
                        GROUP BY hour"
                    );
                }

                /**
                 * bind the parameters for the prepared statement
                 */
                $totalVisitors->bindParam(':start', $tail_start);
                $totalVisitors->bindParam(':end', $tail_end);
                $totalVisitors->bindParam(':venue_id', $venue_filter);

                /**
                 * execute the query for total visitors in TAIL
                 */
                $totalVisitors->execute();
                $initialresultsTailVisitors = $totalVisitors->fetchAll();

            } else {
                /**
                 * TAIL is not in today so we get hourly stats for TAIL
                 * - get the start of day for tail and get the hour of tail_start and tail_end
                 */
                $tail_start_hour = $this->tail_start->format('G');
                $tail_end_hour = $this->tail_end->format('G');

                /**
                 * check whether the user is allowed to view full venue stats or not
                 * and act accordingly
                 */
                if ($currentUser->full_venue_view_allowed == 1) {
                    /**
                     * user is allowed to view full venue stats
                     * prepare and execute the query
                     */
                    $tailVisitors = new TrackingDailyStatsVenueHourlyVisitors;
                    $initialresultsTailVisitors = $tailVisitors
                        ->selectRaw('hour, SUM(visitors_total) AS visitors')
                        ->where('day_epoch', '=', $this->tail_day_epoch)
                        ->where('hour', '<', $tail_end_hour)
                        ->where('hour', '>=', $tail_start_hour)
                        ->where('venue_id', $venue_filter)
                        ->groupBy('hour')
                        ->get();
                } else {
                    /**
                     * user is NOT allowed to view full venue stats
                     * filter on the user's allowed zones AND on the user's primary_venue_id
                     *
                     * get the allowed zones, then create an array which only contains the ids
                     */
                    $allowed_zones = collect($currentUser->zones);
                    $allowed_zones_ids = [];
                    foreach ($allowed_zones as $allowed_zone) {
                        $allowed_zones_ids[] = $allowed_zone->id;
                    }

                    /**
                     * prepare and execute the query with venue AND zones filtering
                     */
                    $tailVisitors = new TrackingDailyStatsVenueHourlyVisitors;
                    $initialresultsTailVisitors = $tailVisitors
                        ->selectRaw('hour, SUM(visitors_total) AS visitors')
                        ->where('day_epoch', '=', $this->tail_day_epoch)
                        ->where('hour', '<', $tail_end_hour)
                        ->where('hour', '>=', $tail_start_hour)
                        ->whereIn('zone_id', $allowed_zones_ids)
                        ->where('venue_id', $venue_filter)
                        ->groupBy('hour')
                        ->get();
                }
            }

        } else {
            /**
             * we do nothing and return zero value
             */
            $initialresultsTailVisitors = 0;
        }

        /**
         * merge the head/body/tail arrays, all containing hour/visitors data
         */
        $tempresults = [];

        /**
         * while merging the arrays we also keep count of the number of data points we have collected for each hour so that
         * we're able to calculate an accurate average visitor count per hour
         */
        if($this->head_length > 0) {
            /**
             * loop through the array and add to tempresults
             */
            foreach ($initialresultsHeadVisitors as $item) {
                if(isset($tempresults[$item['hour']])) {
                    $tempresults[$item['hour']][0] += (int)$item['visitors'];
                    $tempresults[$item['hour']][1] += 1;
                }else {
                    $tempresults[$item['hour']][0] = (int)$item['visitors'];
                    $tempresults[$item['hour']][1] = 1;
                }
            }
        }

        if($this->body_length > 0) {
            /**
             * loop through the array and add to tempresults
             * (in the body, the number of data points is the same as $body_length)
             */
            foreach ($initialresultsBodyVisitors as $item) {
                if(isset($tempresults[$item['hour']])) {
                    $tempresults[$item['hour']][0] += (int)$item['visitors'];
                    $tempresults[$item['hour']][1] += (int)$this->body_length;
                }else {
                    $tempresults[$item['hour']][0] = (int)$item['visitors'];
                    $tempresults[$item['hour']][1] = (int)$this->body_length;
                }
            }
        }

        if($this->tail_length > 0) {
            /**
             * loop through the array and add to tempresults
             */
            foreach ($initialresultsTailVisitors as $item) {
                if(isset($tempresults[$item['hour']])) {
                    $tempresults[$item['hour']][0] += (int)$item['visitors'];
                    $tempresults[$item['hour']][1] += 1;
                }else {
                    $tempresults[$item['hour']][0] = (int)$item['visitors'];
                    $tempresults[$item['hour']][1] = 1;
                }
            }
        }

        /**
         * process the results then sort the results by hour
         */
        foreach($tempresults as $key => $value) {
            $results[] = [$key, round($value[0]/$value[1])];
        }

        $results = array_values(array_sort($results, function ($value) {
            return $value[0];
        }));

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listVisitorReportZoneVisitorsComparisonOld(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * filter on the user's primary_venue_id and get the user's timezone
         */
        $venue_filter = $currentUser->primary_venue_id;
        $timezone = $currentUser->primaryVenue->time_zone;

        /**
         * initialise several variables
         */
        $results = [];

        /*************************************************************************************
         * WORK ON SELECTED RANGE FROM HERE
         * - get the desired time zone
         * - set $start, $end
         *
         * TODO:
         * - get daily stats for body
         * - determine whether tail is today or not
         *  - yes: we get data from probe_requests
         *  - no: we get hourly stats for it
         * - get hourly stats for head
         *************************************************************************************/
        $start = floor($args['start']/1000);
        $end = floor($args['end']/1000);
        $this->determineHeadBodyTail($start, $end, $timezone);

        /**
         * execute query for HEAD of selected range
         */
        if($this->head_length > 0) {
            /**
             * get the start of day for head and get the hour of head_start and head_end
             */
            $head_start_hour = $this->head_start->format('G');
            $head_end_hour = $this->head_end->format('G');

            /**
             * check whether the user is allowed to view full venue stats or not
             * and act accordingly
             */
            if ($currentUser->full_venue_view_allowed == 1) {
                /**
                 * user is allowed to view full venue stats
                 * prepare and execute the query
                 */
                $headVisitors = new TrackingDailyStatsZoneHourlyVisitors;
                $initialresultsHeadVisitors = $headVisitors
                    ->with('zone')
                    ->selectRaw('zone_id, SUM(visitors_total) AS total, SUM(visitors_new) AS new')
                    ->where('day_epoch', $this->head_day_epoch)
                    ->where('hour', '<', $head_end_hour)
                    ->where('hour', '>=', $head_start_hour)
                    ->where('venue_id', $venue_filter)
                    ->groupBy('zone_id')
                    ->get();
            } else {
                /**
                 * user is NOT allowed to view full venue stats
                 * filter on the user's allowed zones AND on the user's primary_venue_id
                 *
                 * get the allowed zones, then create an array which only contains the ids
                 */
                $allowed_zones = collect($currentUser->zones);
                $allowed_zones_ids = [];
                foreach ($allowed_zones as $allowed_zone) {
                    $allowed_zones_ids[] = $allowed_zone->id;
                }

                /**
                 * prepare and execute the query with venue AND zones filtering
                 */
                $headVisitors = new TrackingDailyStatsZoneHourlyVisitors;
                $initialresultsHeadVisitors = $headVisitors
                    ->with('zone')
                    ->selectRaw('zone_id, SUM(visitors_total) AS total, SUM(visitors_new) AS new')
                    ->where('day_epoch', $this->head_day_epoch)
                    ->where('hour', '<', $head_end_hour)
                    ->where('hour', '>=', $head_start_hour)
                    ->where('venue_id', $venue_filter)
                    ->whereIn('zone_id', $allowed_zones_ids)
                    ->groupBy('zone_id')
                    ->get();
            }
        }

        /**
         * execute query for BODY of selected range
         */
        if($this->body_length > 0) {
            /**
             * get body_start and body_end formatted as epoch
             */
            $body_start = $this->body_start->format('U');
            $body_end = $this->body_end->format('U');

            /**
             * check whether the user is allowed to view full venue stats or not
             * and act accordingly
             */
            if ($currentUser->full_venue_view_allowed == 1) {
                /**
                 * user is allowed to view full venue stats
                 * prepare and execute the query for BODY
                 */
                $visitorsQuery = new TrackingDailyStatsZoneVisitors;
                $initialresultsBodyVisitors = $visitorsQuery
                    ->with('zone')
                    ->selectRaw('zone_id, SUM(visitors_total) AS total, SUM(visitors_new) AS new')
                    ->where('day_epoch', '>=', $body_start)
                    ->where('day_epoch', '<', $body_end)
                    ->where('venue_id', $venue_filter)
                    ->groupBy('zone_id')
                    ->get();

            } else {
                /**
                 * user is NOT allowed to view full venue stats
                 * filter on the user's allowed zones AND on the user's primary_venue_id
                 *
                 * get the allowed zones, then create an array which only contains the ids
                 */
                $allowed_zones = collect($currentUser->zones);
                $allowed_zones_ids = [];
                foreach ($allowed_zones as $allowed_zone) {
                    $allowed_zones_ids[] = $allowed_zone->id;
                }

                /**
                 * prepare and execute the query with venue AND zones filtering
                 */
                $visitorsQuery = new TrackingDailyStatsZoneVisitors;
                $initialresultsBodyVisitors = $visitorsQuery
                    ->with('zone')
                    ->selectRaw('zone_id, SUM(visitors_total) AS total, SUM(visitors_new) AS new')
                    ->where('day_epoch', '>=', $body_start)
                    ->where('day_epoch', '<', $body_end)
                    ->where('venue_id', $venue_filter)
                    ->whereIn('zone_id', $allowed_zones_ids)
                    ->groupBy('zone_id')
                    ->get();
            }
        }

        /**
         * execute query for TAIL of selected range
         */
        if($this->tail_length > 0) {
            /**
             * we first determine whether TAIL is in today or not
             */
            if($this->tail_in_today) {
                /**
                 * get body_start and body_end formatted as epoch
                 */
                $tail_start = $this->tail_start->format('U');
                $tail_end = $this->tail_end->format('U');

                /**
                 * prepare and execute the query
                 */
                $tailVisitors = new ProbeRequest;
                $db = $tailVisitors->getConnection()->getPdo();

                /**
                 * check whether the user is allowed to view full venue stats or not
                 * and act accordingly
                 */
                if ($currentUser->full_venue_view_allowed == 1) {
                    /**
                     * user is allowed to view full venue stats
                     * create the prepared statement with the vars available for new visitors past 7 days
                    */

                    $tail_visitors = $db->prepare('
                        SELECT temp2.zone_id AS zone_id,
                               temp2.zone_name AS zone_name,
                               SUM(repeat_visit) AS returning,
                               SUM(new_visit) AS new
                        FROM
                        (
                            SELECT zone.id AS zone_id,
                                   zone.name AS zone_name,
                                   temp1.device_uuid AS left_mac,
                                   mac_cache.device_uuid AS right_mac,
                                   mac_cache.first_seen AS first_seen,
                                   IF(mac_cache.first_seen < :start1, 1, 0) AS repeat_visit,
                                   IF(mac_cache.first_seen >= :start2 OR mac_cache.first_seen IS NULL, 1, 0) AS new_visit
                            FROM
                            (
                                SELECT device_uuid,
                                       drone_id
                                FROM probe_request
                                WHERE venue_id = :venue_id1
                                AND ts BETWEEN :start3 AND :end
                                GROUP BY device_uuid
                            ) AS temp1
                            LEFT JOIN tracking_daily_stats_venue_device_uuid_cache AS mac_cache
                            ON temp1.device_uuid = mac_cache.device_uuid
                            AND mac_cache.venue_id = :venue_id2
                            INNER JOIN drone ON temp1.drone_id = drone.id
                            INNER JOIN zone ON drone.zone_id = zone.id
                            GROUP BY left_mac
                        ) AS temp2
                        GROUP BY zone_id'
                    );
                } else {
                    /**
                     * user is NOT allowed to view full venue stats
                     * filter on the user's allowed zones AND on the user's primary_venue_id
                     *
                     * get the allowed zones, then create an array which only contains the ids
                     */
                    $allowed_zones = collect($currentUser->zones);
                    $allowed_drones_ids = [];
                    foreach ($allowed_zones as $allowed_zone) {
                        foreach ($allowed_zone->drones as $drone) {
                            $allowed_drones_ids[] = $drone->id;
                        }
                    }

                    /**
                     * create a string containing drone ids, comma seperated, to insert into the PDO statement
                     */
                    $ids_string = implode(',', $allowed_drones_ids);

                    /**
                     * execute the prepared statement with the vars available for new visitors past 7 days
                    */

                    $tail_visitors = $db->prepare('
                        SELECT temp2.zone_id AS zone_id,
                               temp2.zone_name AS zone_name,
                               SUM(repeat_visit) AS `returning`,
                               SUM(new_visit) AS new
                        FROM
                        (
                            SELECT zone.id AS zone_id,
                                   zone.name AS zone_name,
                                   temp1.device_uuid AS left_mac,
                                   mac_cache.device_uuid AS right_mac,
                                   mac_cache.first_seen AS first_seen,
                                   IF(mac_cache.first_seen < :start1, 1, 0) AS repeat_visit,
                                   IF(mac_cache.first_seen >= :start2 OR mac_cache.first_seen IS NULL, 1, 0) AS new_visit
                            FROM
                            (
                                SELECT device_uuid,
                                       drone_id
                                FROM probe_request
                                WHERE venue_id = :venue_id1
                                AND ts BETWEEN :start3 AND :end
                                AND probe_request.drone_id IN (' . $ids_string . ')
                                GROUP BY device_uuid
                            ) AS temp1
                            LEFT JOIN tracking_daily_stats_venue_device_uuid_cache AS mac_cache ON temp1.device_uuid = mac_cache.device_uuid
                            AND mac_cache.venue_id = :venue_id2
                            INNER JOIN drone ON temp1.drone_id = drone.id
                            INNER JOIN zone ON drone.zone_id = zone.id
                            GROUP BY left_mac
                        ) AS temp2
                        GROUP BY zone_id'
                    );
                }

                /**
                 * bind the parameters for the prepared statement
                 * in this case: with PDO prepared statements we couldn't use the same parameter twice...
                 */
                $tail_visitors->bindParam(':start1', $tail_start);
                $tail_visitors->bindParam(':start2', $tail_start);
                $tail_visitors->bindParam(':start3', $tail_start);
                $tail_visitors->bindParam(':end', $tail_end);
                $tail_visitors->bindParam(':venue_id1', $venue_filter);
                $tail_visitors->bindParam(':venue_id2', $venue_filter);

                $tail_visitors->execute();
                $initialresultsTailVisitors = $tail_visitors->fetchAll();
            } else {
                /**
                 * TAIL is not in today so we get hourly stats for TAIL
                 * - get the start of day for tail and get the hour of tail_start and tail_end
                 */
                $tail_start_hour = $this->tail_start->format('G');
                $tail_end_hour = $this->tail_end->format('G');

                /**
                 * check whether the user is allowed to view full venue stats or not
                 * and act accordingly
                 */
                if ($currentUser->full_venue_view_allowed == 1) {
                    /**
                     * user is allowed to view full venue stats
                     * prepare and execute the query
                     */
                    $tailVisitors = new TrackingDailyStatsZoneHourlyVisitors;
                    $initialresultsTailVisitors = $tailVisitors
                        ->with('zone')
                        ->selectRaw('zone_id, SUM(visitors_total) AS total, SUM(visitors_new) AS new')
                        ->where('day_epoch', $this->tail_day_epoch)
                        ->where('hour', '<', $tail_end_hour)
                        ->where('hour', '>=', $tail_start_hour)
                        ->where('venue_id', $venue_filter)
                        ->groupBy('zone_id')
                        ->get();
                } else {
                    /**
                     * user is NOT allowed to view full venue stats
                     * filter on the user's allowed zones AND on the user's primary_venue_id
                     *
                     * get the allowed zones, then create an array which only contains the ids
                     */
                    $allowed_zones = collect($currentUser->zones);
                    $allowed_zones_ids = [];
                    foreach ($allowed_zones as $allowed_zone) {
                        $allowed_zones_ids[] = $allowed_zone->id;
                    }

                    /**
                     * prepare and execute the query with venue AND zones filtering
                     */
                    $tailVisitors = new TrackingDailyStatsZoneHourlyVisitors;
                    $initialresultsTailVisitors = $tailVisitors
                        ->with('zone')
                        ->selectRaw('zone_id, SUM(visitors_total) AS total, SUM(visitors_new) AS new')
                        ->where('day_epoch', $this->tail_day_epoch)
                        ->where('hour', '<', $tail_end_hour)
                        ->where('hour', '>=', $tail_start_hour)
                        ->where('venue_id', $venue_filter)
                        ->whereIn('zone_id', $allowed_zones_ids)
                        ->groupBy('zone_id')
                        ->get();
                }
            }
        }

        /**
         * merge the arrays form head/body/tail results into a single array ($tempresults)
         * indexed by the site_id
         */
        $tempresults = [];
        if($this->head_length > 0 && count($initialresultsHeadVisitors) > 0) {
            foreach ($initialresultsHeadVisitors as $item) {
                if(isset($tempresults[$item['zone_id']])) {
                    $tempresults[$item['zone_id']][0] += (int)$item['new'];
                    $tempresults[$item['zone_id']][1] += ((int)$item['total'] - (int)$item['new']);
                } else {
                    $tempresults[$item['zone_id']][0] = (int)$item['new'];
                    $tempresults[$item['zone_id']][1] = ((int)$item['total'] - (int)$item['new']);
                }
                $tempresults[$item['zone_id']][2] = $item['zone']->name;
            }
        }

        if($this->body_length > 0 && count($initialresultsBodyVisitors) > 0) {
            foreach ($initialresultsBodyVisitors as $item) {
                if(isset($tempresults[$item['zone_id']])) {
                    $tempresults[$item['zone_id']][0] += (int)$item['new'];
                    $tempresults[$item['zone_id']][1] += ((int)$item['total'] - (int)$item['new']);
                } else {
                    $tempresults[$item['zone_id']][0] = (int)$item['new'];
                    $tempresults[$item['zone_id']][1] = ((int)$item['total'] - (int)$item['new']);
                }
                $tempresults[$item['zone_id']][2] = $item['zone']->name;
            }
        }

        if($this->tail_length > 0 && count($initialresultsTailVisitors) > 0) {
            foreach ($initialresultsTailVisitors as $item) {
                if($this->tail_in_today) {
                    if(isset($tempresults[$item['zone_id']])) {
                        $tempresults[$item['zone_id']][0] += (int)$item['new'];
                        $tempresults[$item['zone_id']][1] += (int)$item['returning'];
                    } else {
                        $tempresults[$item['zone_id']][0] = (int)$item['new'];
                        $tempresults[$item['zone_id']][1] = (int)$item['returning'];
                    }
                    $tempresults[$item['zone_id']][2] = $item['zone_name'];
                } else {
                    if(isset($tempresults[$item['zone_id']])) {
                        $tempresults[$item['zone_id']][0] += (int)$item['new'];
                        $tempresults[$item['zone_id']][1] += ((int)$item['total'] - (int)$item['new']);
                    } else {
                        $tempresults[$item['zone_id']][0] = (int)$item['new'];
                        $tempresults[$item['zone_id']][1] = ((int)$item['total'] - (int)$item['new']);
                    }
                    $tempresults[$item['zone_id']][2] = $item['zone']->name;
                }
            }
        }

        /**
         * transform the temporary results to the format required
         */
        foreach ($tempresults as $tempresult) {
            $results[] = [$tempresult[2], $tempresult[1], $tempresult[0]];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listStatsAverageDwelltime(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $results = 0;

        /**
         * filter on the user's primary_venue_id
         */
        $venue_filter = $currentUser->primary_venue_id;
        $timezone = $currentUser->primaryVenue->time_zone;

        $venueQuery = new Venue;
        $venue_collection = $venueQuery->where('id', $venue_filter)->get();

        /**
         * WORK ON SELECTED RANGE FROM HERE
         * - determine start and end
         */
        $start = floor($args['start']/1000);
        $end = floor($args['end']/1000);
        $start_hour = Carbon::createFromTimestamp($start, $timezone)->hour;
        $end_hour = Carbon::createFromTimestamp($end, $timezone)->hour;
        $start_date = Carbon::createFromTimestamp($start, $timezone);

        /**
         * get the start of day for head and set correct timezone for head_start and head_end
         */
        $initial_start = $start_date->startOfDay()->format('U');

        /**
         * get the user's timezone
         */
        $timezone = $currentUser->primaryVenue->time_zone;

        /**
         * Convert zone_ids into an array
         */
        if ($zone_ids != '') {
            $zone_ids = explode(',', $zone_ids);
        }

        /**
         * prepare the query using a "random" PDO connection
         */
        $query = new TrackingDailyStatsVenueUniqueDeviceUuids;
        $db = $query->getConnection()->getPdo();

        if ($start > 0 && $end > 0) {

            /**
             * check whether the user is allowed to view full venue stats or not
             * and act accordingly
             */
            $dt_threshold_1  = $venue_collection[0]['dt_threshold_1'];
            $dt_threshold_2  = $venue_collection[0]['dt_threshold_2'];
            $dt_threshold_3  = $venue_collection[0]['dt_threshold_3'];
            $dt_threshold_4  = $venue_collection[0]['dt_threshold_4'];
            $dt_threshold_5  = $venue_collection[0]['dt_threshold_5'];
            $dwell_bucket    = $venue_collection[0]['footfall_bucket'];

            $init_query = "SET sql_mode             = 'NO_UNSIGNED_SUBTRACTION',
                               @venue_filter        = '{$venue_filter}',
                               @range_start         = '{$initial_start}',
                               @range_end           = '{$end}',
                               @start               = '{$start}',
                               @end                 = '{$end}',
                               @dwell_bucket        = '{$dwell_bucket}',
                               @prev_ts             = 0,
                               @dwell_start         = 0,
                               @prev_dwelltime      = 0,
                               @prev_device_uuid    = NULL,
                               @device_uuid_changed = FALSE,
                               @long_gap            = FALSE,
                               @gap                 = 0,
                               @dt_threshold_1      = '{$dt_threshold_1}',
                               @dt_threshold_2      = '{$dt_threshold_2}',
                               @dt_threshold_3      = '{$dt_threshold_3}',
                               @dt_threshold_4      = '{$dt_threshold_4}',
                               @dt_threshold_5      = '{$dt_threshold_5}'";

            $db->exec($init_query);

            if (isset($args['zone_ids'])) {
                /**
                 * create a string containing zone ids, comma seperated, to insert into the PDO statement
                 */
                $ids_string = implode(',', $args['zone_ids']);

                $db->exec("SET @zones = '{$ids_string}'");

                $initialresultsDurations = $db->prepare(
                    'SELECT day_epoch,
                            dt_skipped,
                            dt_level_1,
                            dt_level_2,
                            dt_level_3,
                            dt_level_4,
                            dt_level_5,
                            dt_average
                    FROM (
                        SELECT venue_id,
                               day_epoch,
                               day_of_week,
                               SUM(IF(prev_dwelltime < @dt_threshold_1, 1, 0))                             AS dt_skipped,
                               SUM(IF((prev_dwelltime BETWEEN @dt_threshold_1 AND @dt_threshold_2), 1, 0)) AS dt_level_1,
                               SUM(IF((prev_dwelltime BETWEEN @dt_threshold_2 AND @dt_threshold_3), 1, 0)) AS dt_level_2,
                               SUM(IF((prev_dwelltime BETWEEN @dt_threshold_3 AND @dt_threshold_4), 1, 0)) AS dt_level_3,
                               SUM(IF((prev_dwelltime BETWEEN @dt_threshold_4 AND @dt_threshold_5), 1, 0)) AS dt_level_4,
                               SUM(IF(prev_dwelltime > @dt_threshold_5, 1, 0))                             AS dt_level_5,
                               ROUND(AVG(IF(prev_dwelltime > @dt_threshold_1, prev_dwelltime, NULL))/60)   AS dt_average
                        FROM (
                            SELECT venue_id,
                                   @prev_device_uuid     AS prev_device_uuid,
                                   @prev_ts              AS prev_ts,
                                   @prev_dwelltime       AS prev_dwelltime,
                                   @dwell_start          := IF(@dwell_start = 0, first_seen, @dwell_start),
                                   device_uuid,
                                   (@device_uuid_changed := IF(@prev_device_uuid <> device_uuid, TRUE, FALSE)) AS changed,
                                   (@long_gap            := IF(@device_uuid_changed = FALSE AND first_seen - @prev_ts > @dwell_bucket, TRUE, FALSE)) AS long_gap,
                                   (@dwell_start         := IF(@device_uuid_changed = TRUE OR @long_gap = TRUE, first_seen, @dwell_start)) as dwell_start,
                                   (@dwelltime           := IF(@long_gap = TRUE, 0, last_seen - @dwell_start)) AS dwelltime,
                                   @prev_device_uuid     := device_uuid,
                                   @prev_ts              := last_seen,
                                   @prev_dwelltime       := @dwelltime,
                                   day_epoch,
                                   day_of_week
                            FROM (

                                SELECT venue_id,
                                       device_uuid,
                                       first_seen,
                                       last_seen,
                                       day_epoch,
                                       (day_epoch + (3600*hour)) AS hour_epoch,
                                       day_of_week,
                                       hour
                                  FROM tracking_daily_stats_zone_unique_device_uuids_per_hour
                              PARTITION (p' . $venue_filter . ')
                                  WHERE day_epoch BETWEEN @range_start AND @range_end
                                    AND FIND_IN_SET(zone_id, @zones)
                                ) temp_results

                           WHERE hour_epoch >= @start
                             AND hour_epoch < @end
                             ORDER BY device_uuid ASC, hour ASC
                            ) temp_results_1
                        WHERE changed = TRUE
                        GROUP BY day_epoch
                    ) final_results'
                );

                /**
                 * execute the duration query
                 */
                $initialresultsDurations->execute();

                /**
                 * process the results from both periods and merge into a single array
                 * 5 buckets/series to fill
                 */
                $temp_results = [];
                foreach ($initialresultsDurations as $initialresultsDuration) {
                    array_push($temp_results, (int)$initialresultsDuration['dt_average']);
                }

                $temp_results = array_filter($temp_results);
                if (count($temp_results) != 0) {
                    $results = round(array_sum($temp_results) / count($temp_results));
                }
            }
            else {
                /**
                 * check whether the user is allowed to view full venue stats or not
                 * and act accordingly
                 */
                if ($currentUser->full_venue_view_allowed == 1) {
                    /**
                     * user is allowed to view full venue stats
                     * query the 'daily_stats_venue_visitor_dwelltime' table using DailyStatsVenueDwelltime class
                     */
                    $dwelltimeQuery = new TrackingDailyStatsVenueDwelltime;
                    $averageDwelltime = $dwelltimeQuery
                        ->where('day_epoch', '>=', $start)
                        ->where('day_epoch', '<', $end)
                        ->where('venue_id', $venue_filter)
                        ->avg('dt_average');
                } else {
                    /**
                     * user is NOT allowed to view full venue stats
                     * filter on the user's allowed zones AND on the user's primary_venue_id
                     *
                     * get the allowed zones, then create an array which only contains the ids
                     */
                    $allowed_zones = collect($currentUser->zones);
                    $allowed_zones_ids = [];
                    foreach ($allowed_zones as $allowed_zone) {
                        $allowed_zones_ids[] = $allowed_zone->id;
                    }

                    /**
                     * query the 'daily_stats_zone_visitor_dwelltime' table using DailyStatsVenueDwelltime class with venue AND zones filtering
                     */
                    $dwelltimeQuery   = new TrackingDailyStatsZoneDwelltime;
                    $averageDwelltime = $dwelltimeQuery
                        ->where('day_epoch', '>=', $start)
                        ->where('day_epoch', '<', $end)
                        ->where('venue_id', $venue_filter)
                        ->whereIn('zone_id', $allowed_zones_ids)
                        ->avg('dt_average');
                }

                $results = round($averageDwelltime/60);
            }
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listZoneVisitorAveragesAlltime(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * filter on the user's primary_venue_id
         */
        $venue_filter = $currentUser->primary_venue_id;

        /**
         * Get the primary venue of logged in user
         */
        $venueQuery = new Venue;
        $current_venue = $venueQuery->with('venue_tracking')->where('id', $currentUser->primary_venue_id)->first();

        /**
         * get the object of the selected zone
         */
        $selected_zone = Zone::find($args['zone_id']);

        /**
         * prepare and execute the queries
         * - get the first day
         * - get total number of visitors
         * - get today's visitors
         */
        $totalVisitors = new TrackingDailyStatsZoneVisitors;
        $first_day_epoch = $selected_zone->capture_start;

        $initialresultsTotalVisitors = $totalVisitors
            ->selectRaw('SUM(visitors_total) AS visitors')
            ->where('venue_id', $venue_filter)
            ->where('zone_id', $args['zone_id'])
            ->where('day_epoch', '>=', $first_day_epoch)
            ->get();
        /**
         * determine start of today etc.
         */
        $timezone = $currentUser->primaryVenue->venue_tracking->time_zone;
        $start_of_today = Carbon::now($timezone)->startOfDay()->format('U');
        $totalVisitorsAlltime = $initialresultsTotalVisitors[0]['visitors'];

        /**
         * use Carbon to be able to determine durations
         */
        $first_day = Carbon::createFromTimestamp($first_day_epoch, $timezone);
        $today = Carbon::now($timezone);
        $numberOfDays = $first_day->diffInDays($today);

        /**
         * calculate the averages
         * - to get more accurate daily average we only use number of days the drones in the zone have been capturing
         * TODO: see how the yearly averages turn out, maybe use the months to get non-rounded year count
         */
        if($numberOfDays === 1 || $numberOfDays === 0) {
            $average_daily = $totalVisitorsAlltime;
        } else {
            $average_daily = round($totalVisitorsAlltime/($numberOfDays));
        }

        if($first_day->diffInWeeks($today) === 0) {
            $average_weekly = $totalVisitorsAlltime;
        } else {
            $average_weekly = round($totalVisitorsAlltime/$first_day->diffInWeeks($today));
        }

        if($first_day->diffInMonths($today) === 0) {
            $average_monthly = $totalVisitorsAlltime;
        } else {
            $average_monthly = round($totalVisitorsAlltime/$first_day->diffInMonths($today));
        }

        /**
         * if we have a period shorter than a year we divide by 1, else we divide by the returned numeric value
         */
        $yearly_divider = $first_day->diffInDays($today)/365 > 1 ? $first_day->diffInDays($today)/365 : 1;
        $average_yearly = round($totalVisitorsAlltime/$yearly_divider);

        /**
         * assemble the results into a single object
         */
        $results = [
            'total' => $totalVisitorsAlltime,
            'days' => $numberOfDays,
            'average_daily' => $average_daily,
            'average_weekly' => $average_weekly,
            'average_monthly' => $average_monthly,
            'average_yearly' => $average_yearly
        ];

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listZoneStatsAverageDwelltimeV2(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $results = 0;
        $end = floor($args['end']/1000);
        $start = floor($args['start']/1000);

        /**
         * filter on the user's primary_venue_id
         */
        $venue_filter = $currentUser->primary_venue_id;

        /**
         * query the 'daily_stats_venue_visitor_dwelltime' table using DailyStatsVenueDwelltime class
         */
        $dwelltimeQuery   = new TrackingDailyStatsZoneDwelltime;
        $averageDwelltime = $dwelltimeQuery
            ->raw('PARTITION (p' . $venue_filter . ')')
            ->where('day_epoch', '>=', $start)
            ->where('day_epoch', '<', $end)
            ->where('zone_id', $args['zone_id'])
            ->avg('dt_average');

        $results = round($averageDwelltime/60);

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listZoneVisitorReportUniqueDaily(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * filter on the user's primary_venue_id and get the user's timezone
         */
        $venue_filter = $currentUser->primary_venue_id;
        $timezone = $currentUser->primaryVenue->venue_tracking->time_zone;

        /**
         * get the object of the selected zone
         */
        $selected_zone = Zone::find($args['zone_id']);

        /**
         * initialise several variables
         */
        $results = [];

        /*************************************************************************************
        WORK ON SELECTED RANGE FROM HERE
        - get the desired time zone
        - set $start, $end

        TODO:
        - determine full days within the body
            - get daily stats for it
        - determine whether tail is today or not
            - yes: we get data from probe_requests
            - no: we get hourly stats for it
        - determine head and get hourly stats for it
        - using Carbon to copy timestamps you need to use the copy function!!!
        **************************************************************************************/
        $start = floor($args['start']/1000);
        $end   = floor($args['end']/1000);
        $this->determineHeadBodyTail($start, $end, $timezone);

        /**
         * execute query for HEAD of selected range
         */
        if($this->head_length > 0) {
            /**
             * get the start of day for head and get the hour of head_start and head_end
             */
            $head_start_hour = $this->head_start->format('G');
            $head_end_hour = $this->head_end->subSecond()->format('G');

            $headVisitors = new TrackingDailyStatsZoneHourlyVisitors;
            $initialresultsHeadVisitors = $headVisitors
                ->raw('PARTITION (p' . $venue_filter . ')')
                ->where('day_epoch', '=', $this->head_day_epoch)
                ->where('hour', '<=', $head_end_hour)
                ->where('hour', '>=', $head_start_hour)
                ->where('zone_id', $args['zone_id'])
                ->sum('visitors_total');

            $results[] = [$this->head_day_epoch*1000, (int)$initialresultsHeadVisitors];
        }

        /**
         * execute query for BODY of selected range
         */
        if($this->body_length > 0) {
            /**
             * get body_start and body_end formatted as epoch
             */
            $body_start = $this->body_start->format('U');
            $body_end = $this->body_end->format('U');

            /*
            prepare and execute the query for the daily stats for BODY
            */
            $bodyVisitors = new TrackingDailyStatsZoneVisitors;
            $initialresultsbodyVisitors = $bodyVisitors
                ->select('day_epoch', 'visitors_total')
                ->raw('PARTITION (p' . $venue_filter . ')')
                ->where('day_epoch', '>=', $body_start)
                ->where('day_epoch', '<', $body_end)
                ->where('zone_id', $args['zone_id'])
                ->orderBy('day_epoch', 'asc')
                ->get();

            foreach($initialresultsbodyVisitors as $day) {
                $results[] = [
                    $day['day_epoch']*1000,
                    $day['visitors_total']
                ];
            }
        }

        /**
         * execute query for TAIL of selected range
         */
        if($this->tail_length > 0) {
            /**
             * we first determine whether TAIL is in today or not
             */
            if($this->tail_in_today) {
                /**
                 * get body_start and body_end formatted as epoch
                 */
                $tail_start = $this->tail_start->format('U');
                $tail_end = $this->tail_end->format('U');

                /**
                 * prepare and execute the query using a PDO connection provided
                 */
                $newVisitorsQuery = new ProbeRequest;
                $db = $newVisitorsQuery->getConnection()->getPdo();

                /**
                 * execute the prepared statement with the vars available for total visitors today
                 * TODO: implement lookup with array of drone_id's
                  */
                $totalVisitors = $db->prepare('
                    SELECT COUNT(DISTINCT device_uuid) AS total
                    FROM probe_request
                    INNER JOIN drone ON probe_request.drone_id = drone.id
                    PARTITION (p' . $venue_filter . ')
                    WHERE ts BETWEEN :start AND :end
                    AND drone.zone_id = :zone_id'
                );

                /**
                 * bind the parameters for the prepared statement
                 */
                $totalVisitors->bindParam(':start', $tail_start);
                $totalVisitors->bindParam(':end', $tail_end);
                $totalVisitors->bindParam(':zone_id', $args['zone_id']);

                /**
                 * execute the query
                 */
                $totalVisitors->execute();
                $initialresultsTotalVisitors = $totalVisitors->fetch();

                $startoftoday = Carbon::now($timezone)->startOfDay()->format('U') * 1000;

                if ($initialresultsTotalVisitors['total'] > 0) {
                    $results[] = [
                        $startoftoday,
                        $initialresultsTotalVisitors['total']
                    ];
                }
            } else {
                /**
                 * TAIL is not in today so we get hourly stats for TAIL
                 * - get the start of day for tail and get the hour of tail_start and tail_end
                 */
                $tail_start_hour = $this->tail_start->format('G');
                $tail_end_hour = $this->tail_end->format('G');

                $tailVisitors = new TrackingDailyStatsZoneHourlyVisitors;
                $initialresultsTailVisitors = $tailVisitors
                    ->raw('PARTITION (p' . $venue_filter . ')')
                    ->where('day_epoch', '=', $this->tail_day_epoch)
                    ->where('hour', '<', $tail_end_hour)
                    ->where('hour', '>=', $tail_start_hour)
                    ->where('zone_id', $args['zone_id'])
                    ->sum('visitors_total');

                $results[] = [
                    $this->tail_day_epoch*1000,
                    (int)$initialresultsTailVisitors
                ];
            }
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listZoneReportVisitorsTimeOfDay(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * filter on the user's primary_venue_id and get the venue's timezone
         */
        $venue_filter = $currentUser->primary_venue_id;
        $timezone = $currentUser->primaryVenue->time_zone;

        /*
        get the object of the selected zone
        */
        $selected_zone = Zone::find($args['zone_id']);

        /**
         * initialise several variables
         */
        $results     = [
            [0,0],
            [1,0],
            [2,0],
            [3,0],
            [4,0],
            [5,0],
            [6,0],
            [7,0],
            [8,0],
            [9,0],
            [10,0],
            [11,0],
            [12,0],
            [13,0],
            [14,0],
            [15,0],
            [16,0],
            [17,0],
            [18,0],
            [19,0],
            [20,0],
            [21,0],
            [22,0],
            [23,0]
        ];

        /*************************************************************************************
         * WORK ON SELECTED RANGE FROM HERE
         * - get the desired time zone
         * - set $start, $end
         *
         * TODO:
         * determine full days within the body
         *   - get daily stats for it
         * - determine whether tail is today or not
         *   - yes: we get data from probe_requests
         *   - no: we get hourly stats for it
         * - get hourly stats for head
         **************************************************************************************/
        $start = floor($args['start']/1000);
        $end = floor($args['end']/1000);
        $this->determineHeadBodyTail($start, $end, $timezone);

        /**
         * execute query for HEAD of selected range
         */
        if($this->head_length > 0) {
            /**
             * get the start of day for head and get the hour of head_start and head_end
             */
            $head_start_hour = $this->head_start->format('G');
            $head_end_hour = $this->head_end->subSecond()->format('G');

            $headVisitors = new TrackingDailyStatsZoneHourlyVisitors;
            $initialresultsHeadVisitors = $headVisitors
                ->selectRaw('hour, SUM(visitors_total) AS visitors')
                ->raw('PARTITION (p' . $venue_filter . ')')
                ->where('day_epoch', '=', $this->head_day_epoch)
                ->where('hour', '<=', $head_end_hour)
                ->where('hour', '>=', $head_start_hour)
                ->where('zone_id', $args['zone_id'])
                ->groupBy('hour')
                ->get();
        } else {
            /**
             * we do nothing and return a zero value
             */
            $initialresultsHeadVisitors = 0;
        }

        /**
         * execute query for BODY of selected range
         */
        if($this->body_length > 0) {
            /**
             * get body_start and body_end formatted as epoch
             */
            $body_start = $this->body_start->format('U');
            $body_end = $this->body_end->format('U');

            /**
             * prepare and execute the query for BODY
             */
            $bodyVisitors = new TrackingDailyStatsZoneHourlyVisitors;
            $initialresultsBodyVisitors = $bodyVisitors
                ->selectRaw('hour, SUM(visitors_total) AS visitors')
                ->raw('PARTITION (p' . $venue_filter . ')')
                ->where('day_epoch', '>=', $body_start)
                ->where('day_epoch', '<', $body_end)
                ->where('zone_id', $args['zone_id'])
                ->orderBy('hour', 'asc')
                ->groupBy('hour')
                ->get();
        } else {
            /**
             * we do nothing and return zero values for new and repeat
             */
            $initialresultsBodyVisitors = 0;
        }

        /**
         * execute query for TAIL of selected range
         * TODO: implement an array of drone_id's for a WHERE IN clause in the first query?
         */
        if($this->tail_length > 0) {
            /**
             * we first determine whether TAIL is in today or not
             */
            if($this->tail_in_today) {
                /**
                 * get body_start and body_end formatted as epoch
                 */
                $tail_start = $this->tail_start->format('U');
                $tail_end = $this->tail_end->format('U');

                /**
                 * prepare and execute the query using a PDO connection provided
                 */
                $newVisitorsQuery = new ProbeRequest;
                $db = $newVisitorsQuery->getConnection()->getPdo();

                /**
                 * execute the prepared statement with the vars available for total visitors in TAIL today
                 */
                $totalVisitors = $db->prepare("
                    SELECT FROM_UNIXTIME(ts,'%k') AS hour,
                    COUNT(DISTINCT device_uuid) AS visitors
                    FROM probe_request
                    INNER JOIN drone
                    ON probe_request.drone_id = drone.id
                    PARTITION (p" . $venue_filter . ")
                    WHERE ts BETWEEN :start AND :end
                    AND drone.zone_id = :zone_id
                    GROUP BY hour"
                );

                /**
                 * bind the parameters for the prepared statement
                 */
                $totalVisitors->bindParam(':start', $tail_start);
                $totalVisitors->bindParam(':end', $tail_end);
                $totalVisitors->bindParam(':zone_id', $args['zone_id']);

                /**
                 * execute the query for total visitors in TAIL
                 */
                $totalVisitors->execute();
                $initialresultsTailVisitors = $totalVisitors->fetchAll();
            } else {
                /**
                 * TAIL is not in today so we get hourly stats for TAIL
                 * - get the start of day for tail and get the hour of tail_start and tail_end
                 */
                $tail_start_hour = $this->tail_start->format('G');
                $tail_end_hour = $this->tail_end->format('G');

                $tailVisitors = new TrackingDailyStatsZoneHourlyVisitors;
                $initialresultsTailVisitors = $tailVisitors
                    ->selectRaw('hour, SUM(visitors_total) AS visitors')
                    ->raw('PARTITION (p' . $venue_filter . ')')
                    ->where('day_epoch', '=', $this->tail_day_epoch)
                    ->where('hour', '<', $tail_end_hour)
                    ->where('hour', '>=', $tail_start_hour)
                    ->where('zone_id', $args['zone_id'])
                    ->groupBy('hour')
                    ->get();
            }
        } else {
            /**
             * we do nothing and return zero value
             */
            $initialresultsTailVisitors = 0;
        }

        /**
         * merge the head/body/tail arrays, all containing hour/visitors data
         */
        $tempresults = [];

        /**
         * while merging the arrays we also keep count of the number of data points we have collected for each hour so that
         *we're able to calculate an accurate average visitor count per hour
         */
        if($this->head_length > 0) {
            /*
            loop through the array and add to tempresults
            */
            foreach ($initialresultsHeadVisitors as $item) {
                if(isset($tempresults[$item['hour']])) {
                    $tempresults[$item['hour']][0] += (int)$item['visitors'];
                    $tempresults[$item['hour']][1] += 1;
                }else {
                    $tempresults[$item['hour']][0] = (int)$item['visitors'];
                    $tempresults[$item['hour']][1] = 1;
                }
            }
        }

        if($this->body_length > 0) {
            /**
             * loop through the array and add to tempresults
             * (in the body, the number of data points is the same as $body_length)
             */
            foreach ($initialresultsBodyVisitors as $item) {
                if(isset($tempresults[$item['hour']])) {
                    $tempresults[$item['hour']][0] += (int)$item['visitors'];
                    $tempresults[$item['hour']][1] += (int)$this->body_length;
                }else {
                    $tempresults[$item['hour']][0] = (int)$item['visitors'];
                    $tempresults[$item['hour']][1] = (int)$this->body_length;
                }
            }
        }

        if($this->tail_length > 0) {
            /**
             * loop through the array and add to tempresults
             */
            foreach ($initialresultsTailVisitors as $item) {
                if(isset($tempresults[$item['hour']])) {
                    $tempresults[$item['hour']][0] += (int)$item['visitors'];
                    $tempresults[$item['hour']][1] += 1;
                }else {
                    $tempresults[$item['hour']][0] = (int)$item['visitors'];
                    $tempresults[$item['hour']][1] = 1;
                }
            }
        }

        /**
         * process the results then sort the results by hour
         *
         * NOTE:
         * now changed to a pre-populated array for $results
         */
        foreach($tempresults as $key => $value) {
            $results[$key] = [
                $key,
                round($value[0]/$value[1])
            ];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listZoneReportNewVsRepeat(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * filter on the user's primary_venue_id and get the user's timezone
         */
        $venue_filter = $currentUser->primary_venue_id;
        $timezone = $currentUser->primaryVenue->time_zone;

        /*
        get the object for the selected zone
        */
        $selected_zone = Zone::find($args['zone_id']);

        /**
         * initialise several variables
         */
        $results = [
            'new' => [],
            'repeat' => [],
            'event' => []
        ];

        /*************************************************************************************
        WORK ON SELECTED RANGE FROM HERE
        - get the desired time zone
        - set $start, $end

        TODO:
        - determine full days within the body
            - get daily stats for it
        - determine whether tail is today or not
            - yes: we get data from probe_requests
            - no: we get hourly stats for it
        - determine head and get hourly stats for it
        **************************************************************************************/
        $start = floor($args['start']/1000);
        $end = floor($args['end']/1000);
        $this->determineHeadBodyTail($start, $end, $timezone);

        /**
         * execute query for HEAD of selected range
         */
        if($this->head_length > 0) {
            /**
             * get the start of day for head and get the hour of head_start and head_end
             */
            $head_start_hour = $this->head_start->format('G');
            $head_end_hour = $this->head_end->subSecond()->format('G');

            $headVisitors = new TrackingDailyStatsZoneHourlyVisitors;
            $initialresultsHeadVisitors = $headVisitors
                ->selectRaw('day_epoch, SUM(visitors_new) AS new, SUM(visitors_total) AS total')
                ->raw('PARTITION (p' . $venue_filter . ')')
                ->where('day_epoch', '=', $this->head_day_epoch)
                ->where('hour', '<=', $head_end_hour)
                ->where('hour', '>=', $head_start_hour)
                ->where('zone_id', $args['zone_id'])
                ->groupBy('day_epoch')
                ->get();

            foreach($initialresultsHeadVisitors as $day) {
                $results['new'][]    = [$day['day_epoch']*1000, (int)$day['new']];
                $results['repeat'][] = [$day['day_epoch']*1000, (int)$day['total'] - (int)$day['new']];
            }
        }

        /**
         * execute query for BODY of selected range
         */
        if($this->body_length > 0) {
            /**
             * get body_start and body_end formatted as epoch
             */
            $body_start = $this->body_start->format('U');
            $body_end = $this->body_end->format('U');

            /*
            prepare and execute the query for the daily stats for BODY
            */
            $bodyVisitors = new TrackingDailyStatsZoneVisitors;
            $initialresultsbodyVisitors = $bodyVisitors
                ->select('day_epoch', 'visitors_new', 'visitors_total')
                ->raw('PARTITION (p' . $venue_filter . ')')
                ->where('day_epoch', '>=', $body_start)
                ->where('day_epoch', '<', $body_end)
                ->where('zone_id', $args['zone_id'])
                ->orderBy('day_epoch', 'asc')
                ->get();

            foreach($initialresultsbodyVisitors as $day) {
                $results['new'][] = [$day['day_epoch']*1000, $day['visitors_new']];
                $results['repeat'][] = [$day['day_epoch']*1000, $day['visitors_total'] - $day['visitors_new']];
            }

        }

        /**
         * execute query for TAIL of selected range
         */
        if($this->tail_length > 0) {
            /**
             * we first determine whether TAIL is in today or not
             */
            if($this->tail_in_today) {
                /**
                 * get body_start and body_end formatted as epoch
                 */
                $tail_start = $this->tail_start->format('U');
                $tail_end = $this->tail_end->format('U');

                /**
                 * prepare and execute the query using a PDO connection provided
                 */
                $repeatVisitorsQuery = new ProbeRequest;
                $db = $repeatVisitorsQuery->getConnection()->getPdo();

                /**
                 * execute the prepared statement with the vars available for repeat visitors today
                 * NOTE: repeat is an SQL keyword, don't use it in the query
                 * TODO:
                 */
                $repeatVisitors = $db->prepare('
                    SELECT returning
                    FROM
                    (
                        SELECT COUNT(DISTINCT probes.device_uuid) AS `returning`,
                               drone_id
                        FROM probe_request AS probes
                        INNER JOIN tracking_daily_stats_venue_device_uuid_cache AS repeat_visitors
                        ON probes.device_uuid = repeat_visitors.device_uuid
                        PARTITION (p' . $venue_filter . ')
                        WHERE repeat_visitors.first_seen < :start_1
                        AND probes.ts > :start_2
                        AND ts < :end
                    ) AS tempresult
                    INNER JOIN drone ON drone_id = drone.id
                    WHERE drone.zone_id = :zone_id'
                );

                /**
                 * bind the parameters for the prepared statement
                 */
                $repeatVisitors->bindParam(':start_1', $tail_start);
                $repeatVisitors->bindParam(':start_2', $tail_start);
                $repeatVisitors->bindParam(':end', $tail_end);
                $repeatVisitors->bindParam(':zone_id', $args['zone_id']);

                /**
                 * execute the query
                 */
                $repeatVisitors->execute();
                $initialresultsRepeatVisitors = $repeatVisitors->fetch();

                /**
                 * execute the prepared statement with the vars available for total visitors today
                 * TODO:
                  */
                $totalVisitors = $db->prepare('
                    SELECT COUNT(DISTINCT device_uuid) AS total
                    FROM probe_request
                    INNER JOIN drone
                    ON probe_request.drone_id = drone.id
                    PARTITION (p' . $venue_filter . ')
                    WHERE ts > :start
                    AND ts < :end
                    AND drone.zone_id = :zone_id'
                );

                /**
                 * bind the parameters for the prepared statement
                 */
                $totalVisitors->bindParam(':start', $tail_start);
                $totalVisitors->bindParam(':end', $tail_end);
                $totalVisitors->bindParam(':zone_id', $args['zone_id']);

                /**
                 * execute the query
                 */
                $totalVisitors->execute();
                $initialresultsTotalVisitors = $totalVisitors->fetch();

                $startoftoday = Carbon::now($timezone)->startOfDay()->format('U') * 1000;

                if ($initialresultsTotalVisitors['total'] > 0) {
                    $results['repeat'][] = [$startoftoday, $initialresultsRepeatVisitors['returning']];
                    $results['new'][] = [$startoftoday, $initialresultsTotalVisitors['total'] - $initialresultsRepeatVisitors['returning']];
                }
            } else {
                /**
                 * TAIL is not in today so we get hourly stats for TAIL
                 * - get the start of day for tail and get the hour of tail_start and tail_end
                  */
                $tail_start_hour = $this->tail_start->format('G');
                $tail_end_hour = $this->tail_end->format('G');

                $tailVisitors = new TrackingDailyStatsZoneHourlyVisitors;
                $initialresultsTailVisitors = $tailVisitors
                    ->selectRaw('day_epoch, SUM(visitors_new) AS new, SUM(visitors_total) AS total')
                    ->raw('PARTITION (p' . $venue_filter . ')')
                    ->where('day_epoch', '=', $this->tail_day_epoch)
                    ->where('hour', '<', $tail_end_hour)
                    ->where('hour', '>=', $tail_start_hour)
                    ->where('zone_id', $args['zone_id'])
                    ->groupBy('day_epoch')
                    ->get();

                foreach($initialresultsTailVisitors as $day) {
                    $results['new'][]    = [$day['day_epoch']*1000, (int)$day['new']];
                    $results['repeat'][] = [$day['day_epoch']*1000, (int)$day['total'] - (int)$day['new']];
                }
            }
        }

        // Get all the events which were ran on between these dates
        $event = new Event;
        if ($authorizer->checkAccess($currentUser, 'uri_site_admin')) {
            $dailyEvents = $event
                ->with('event_category')
                ->where('start_date', '>=', $start)
                ->where('end_date', '<=', $end)
                ->where('venue_id', $venue_filter)
                ->get();
        }
        else {
            $dailyEvents = $event
                ->with('event_category')
                ->where('start_date', '>=', $start)
                ->where('end_date', '<=', $end)
                ->where('admin_event', 0)
                ->where('venue_id', $venue_filter)
                ->get();
        }

        foreach($dailyEvents as $event) {
            $results['event'][] = [
                'start_date' => $event->start_date * 1000, 
                'end_date' => $event->end_date * 1000, 
                'name' => $event->name,
                'color' => $event->event_category->category_color,
                'category_id' => $event->event_category_id
            ];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listZoneStatsVisitorDurations(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $results = [
            'dt_skipped' => [],
            'dt_level_1' => [],
            'dt_level_2' => [],
            'dt_level_3' => [],
            'dt_level_4' => [],
            'dt_level_5' => [],
            'dt_average' => []
        ];

        $start = floor($args['start']/1000);
        $end = floor($args['end']/1000);

        /**
         * filter on the user's primary_venue_id AND on the supplied zone id
         *
         * TODO:
         * also check if zone access is allowed unless the user is a venue admin
         */
        $venue_filter = $currentUser->primary_venue_id;

        $allowed_zones = collect($currentUser->zones);
        $allowed_zones_ids = [];
        foreach ($allowed_zones as $allowed_zone) {
            $allowed_zones_ids[] = $allowed_zone->id;
        }

        /**
         * throw an error if requested zone_id is not in allowed zones for this user
         * but only when current user is not a site admin
         */
        if (!in_array($args['zone_id'], $allowed_zones_ids) && !$authorizer->checkAccess($currentUser, 'uri_site_admin')){
            $this->_app->notFound();
        }

        /**
         * prepare and execute the query with venue AND zone filters
         */
        $thisWeekDwelltimes = new TrackingDailyStatsZoneDwelltime;
        $initialresultsDurations = $thisWeekDwelltimes
            ->selectRaw('day_epoch, dt_skipped, dt_level_1, dt_level_2, dt_level_3, dt_level_4, dt_level_5, dt_average')
            ->raw('PARTITION (p' . $venue_filter . ')')
            ->where('day_epoch', '>=', $start)
            ->where('day_epoch', '<', $end)
            ->where('zone_id', $args['zone_id'])
            ->orderBy('day_epoch', 'asc')
            ->get();

        /**
         * process the results from both periods and merge into a single array
         * 5 buckets/series to fill with the dwelltime averages
         */
        foreach ($initialresultsDurations as $initialresultsDuration) {
            $results['dt_skipped'][] = [
                $initialresultsDuration['day_epoch']*1000,
                (int)$initialresultsDuration['dt_skipped']
            ];
            $results['dt_level_1'][] = [
                $initialresultsDuration['day_epoch']*1000,
                (int)$initialresultsDuration['dt_level_1']
            ];
            $results['dt_level_2'][] = [
                $initialresultsDuration['day_epoch']*1000,
                (int)$initialresultsDuration['dt_level_2']
            ];
            $results['dt_level_3'][] = [
                $initialresultsDuration['day_epoch']*1000,
                (int)$initialresultsDuration['dt_level_3']
            ];
            $results['dt_level_4'][] = [
                $initialresultsDuration['day_epoch']*1000,
                (int)$initialresultsDuration['dt_level_4']
            ];
            $results['dt_level_5'][] = [
                $initialresultsDuration['day_epoch']*1000,
                (int)$initialresultsDuration['dt_level_5']
            ];
            $results['dt_average'][] = [
                $initialresultsDuration['day_epoch']*1000,
                round((int)$initialresultsDuration['dt_average']/60)
            ];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listDayNightReportNewVsRepeat(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * filter on the user's primary_venue_id and get the user's timezone
         */
        $venue_filter = $currentUser->primary_venue_id;
        $timezone = $currentUser->primaryVenue->time_zone;

        /**
         * initialise several variables
         */
        $results = ['new' => [], 'repeat' => [], 'event' => []];
        $hours_array = [];
        $start_hours_array = [];
        $end_hours_array = [];

        /*************************************************************************************
         * WORK ON SELECTED RANGE FROM HERE
         * - get the desired time zone
         * - set $start, $end
         *
         * TODO:
         * - determine full days within the body
         * - get daily stats for it
         * - determine whether tail is today or not
         *  - yes: we get data from probe_requests
         *  - no: we get hourly stats for it
         * - determine head and get hourly stats for it
         * - using Carbon to copy timestamps you need to use the copy function!!!
         *************************************************************************************/
        $start = floor($args['start']/1000);
        $end = floor($args['end']/1000);
        $start_hour = Carbon::createFromTimestamp($start, $timezone)->hour;
        $end_hour = Carbon::createFromTimestamp($end, $timezone)->hour;
        $start_date = Carbon::createFromTimestamp($start, $timezone);

        if ($start_hour <= $end_hour) {
            foreach (range($start_hour, $end_hour) as $number) {
                array_push($hours_array, $number);
            }
        } else {
            foreach (range($start_hour, 24) as $number) {
                array_push($end_hours_array, $number);
            }

            foreach (range(0, $end_hour) as $number) {
                array_push($start_hours_array, $number);
            }
        }

        $hours = implode(",", $hours_array);

        $start_hours = implode(",", $start_hours_array);
        $end_hours = implode(",", $end_hours_array);
        $all_hours = implode(",", array_merge($start_hours_array, $end_hours_array));

        /**
         * get the start of day for head and set correct timezone for head_start and head_end
         */
        $initial_start = $start_date->startOfDay()->format('U');
        $end_date = Carbon::createFromTimestamp($end, $timezone)->startOfDay()->format('U');

        /**
         * prepare the query using a "random" PDO connection
         */
        $query = new TrackingDailyStatsVenueUniqueDeviceUuids;
        $db = $query->getConnection()->getPdo();

        if ($start > 0 && $end > 0) {
           
            /**
             * Check if the range goes over midnight
             */
            if ($start_hour <= $end_hour) {
                $db->exec("SET @hours = '{$hours}'");

                 /**
                 * check whether the user is allowed to view full venue stats or not
                 * and act accordingly
                 */
                if ($currentUser->full_venue_view_allowed == 1) {
                    /**
                     * user is allowed to view full venue stats
                     * create the prepared statement
                     */
                    $visitor_counts = $db->prepare('
                        SELECT COUNT(CASE WHEN is_repeat = 0 THEN 1 END) AS new_visitors,
                               COUNT(CASE WHEN is_repeat = 1 THEN 1 END) AS repeat_visitors,
                               day_epoch
                        FROM
                        (
                            SELECT DISTINCT(device_uuid),
                                   day_epoch,
                                   is_repeat
                            FROM
                            (
                                SELECT device_uuid,
                                       day_epoch,
                                       hour,
                                       is_repeat
                                FROM tracking_daily_stats_venue_unique_device_uuids_per_hour
                                PARTITION (p' . $venue_filter . ')
                                WHERE day_epoch >= :initial_start
                                AND day_epoch < :end_1
                                AND FIND_IN_SET(hour, @hours)
                            ) AS temp1
                        ) AS temp2
                        GROUP BY day_epoch'
                    );
                } else {
                    /**
                     * user is NOT allowed to view full venue stats
                     * - filter on the user's allowed zones AND on the user's primary_venue_id
                     * - get the allowed zones, then create an array which only contains their ids
                     */
                    $allowed_zones = collect($currentUser->zones);
                    $allowed_zone_ids = [];

                    foreach ($allowed_zones as $zone) {
                        $allowed_zone_ids[] = $zone->id;
                    }

                    /**
                     * create a string containing zone ids, comma seperated, to insert into the PDO statement
                     */
                    $ids_string = implode(',', $allowed_zone_ids);

                    /**
                     * create the prepared statement
                     */
                    $visitor_counts = $db->prepare('
                        SELECT COUNT(CASE WHEN is_repeat = 0 THEN 1 END) AS new_visitors,
                               COUNT(CASE WHEN is_repeat = 1 THEN 1 END) AS repeat_visitors,
                               day_epoch
                        FROM
                        (
                            SELECT DISTINCT(device_uuid),
                                   day_epoch,
                                   is_repeat
                            FROM
                            (
                                SELECT device_uuid,
                                       day_epoch,
                                       hour,
                                       is_repeat
                                FROM tracking_daily_stats_zone_unique_device_uuids_per_hour
                                WHERE day_epoch >= :initial_start
                                PARTITION (p' . $venue_filter . ')
                                AND day_epoch < :end_1
                                AND FIND_IN_SET(hour, @hours)
                                AND zone_id IN (' . $ids_string . ')
                            ) AS temp1
                        ) AS temp2
                        GROUP BY day_epoch'
                    );
                }

                /**
                 * bind the parameters to the selected query
                 */
                $visitor_counts->bindParam(':initial_start', $initial_start);
                $visitor_counts->bindParam(':end_1', $end);

                /**
                 * execute the query for total visitors
                 */
                $visitor_counts->execute();

                foreach($visitor_counts as $day) {
                    $results['new'][] = [$day['day_epoch']*1000, (int)$day['new_visitors']];
                    $results['repeat'][] = [$day['day_epoch']*1000, (int)$day['repeat_visitors']];
                }

            }
            else {
                $db->exec("SET @hours = '{$all_hours}'");

                /**
                 * check whether the user is allowed to view full venue stats or not
                 * and act accordingly
                 */
                if ($currentUser->full_venue_view_allowed == 1) {
                    /**
                     * user is allowed to view full venue stats
                     * create the prepared statement
                     */
                    $visitor_counts = $db->prepare('
                        SELECT COUNT(CASE WHEN is_repeat = 0 THEN 1 END) AS new_visitors,
                               COUNT(CASE WHEN is_repeat = 1 THEN 1 END) AS repeat_visitors,
                               day_epoch,
                               hour
                        FROM
                        (
                            SELECT device_uuid,
                                   day_epoch,
                                   is_repeat,
                                   hour
                            FROM
                            (
                                SELECT device_uuid,
                                       day_epoch,
                                       hour,
                                       is_repeat
                                FROM tracking_daily_stats_venue_unique_device_uuids_per_hour
                                PARTITION (p' . $venue_filter . ')
                                WHERE day_epoch >= :initial_start
                                AND day_epoch <= :end_1
                                AND FIND_IN_SET(hour, @hours)
                            ) AS temp1
                            GROUP BY device_uuid
                        ) AS temp2
                        GROUP BY day_epoch, hour'
                    );
                }
                else {
                    /**
                     * user is NOT allowed to view full venue stats
                     * - filter on the user's allowed zones AND on the user's primary_venue_id
                     * - get the allowed zones, then create an array which only contains their ids
                     */
                    $allowed_zones = collect($currentUser->zones);
                    $allowed_zone_ids = [];

                    foreach ($allowed_zones as $zone) {
                        $allowed_zone_ids[] = $zone->id;
                    }

                    /**
                     * create a string containing zone ids, comma seperated, to insert into the PDO statement
                     */
                    $ids_string = implode(',', $allowed_zone_ids);

                    /**
                     * create the prepared statement
                     */
                    $visitor_counts = $db->prepare('
                        SELECT COUNT(CASE WHEN is_repeat = 0 THEN 1 END) AS new_visitors,
                               COUNT(CASE WHEN is_repeat = 1 THEN 1 END) AS repeat_visitors,
                               day_epoch,
                               hour
                        FROM
                        (
                            SELECT device_uuid,
                                   day_epoch,
                                   is_repeat,
                                   hour
                            FROM
                            (
                                SELECT device_uuid,
                                       day_epoch,
                                       hour,
                                       is_repeat
                                FROM tracking_daily_stats_venue_unique_device_uuids_per_hour
                                PARTITION (p' . $venue_filter . ')
                                WHERE day_epoch >= :initial_start
                                AND day_epoch <= :end_1
                                AND FIND_IN_SET(hour, @hours)
                                AND zone_id IN (' . $ids_string . ')
                            ) AS temp1
                            GROUP BY device_uuid
                        ) AS temp2
                        GROUP BY day_epoch, hour'
                    );
                }

                /**
                 * bind the parameters to the selected query
                 */
                $visitor_counts->bindParam(':initial_start', $initial_start);
                $visitor_counts->bindParam(':end_1', $end);

                $visitor_counts->execute();

                /**
                 * Manipulate the day_epoch making the visual report clearer to the user
                 *
                 * FOR EXAMPLE
                 * Friday - Sunday | 22:00 - 03:00
                 * only show values for Friday and Saturday night
                 * don't show values for Sunday
                 */
                $temp_results = [];
                foreach($visitor_counts as $day) {

                    if ($day['day_epoch'] == $initial_start) {
                        if (in_array((int)$day['hour'], $end_hours_array)) {
                            $temp_results['new'][] = [$day['day_epoch'] * 1000, $day['hour'], (int)$day['new_visitors']];
                            $temp_results['repeat'][] = [$day['day_epoch'] * 1000, $day['hour'], (int)$day['repeat_visitors']];
                        }
                    }

                    if ($day['day_epoch'] == $end_date) {
                        if ( in_array( (int)$day['hour'], $start_hours_array ) ) {
                            $temp_results['new'][] = [ ($day['day_epoch'] - 86400) * 1000, $day['hour'], (int)$day['new_visitors']];
                            $temp_results['repeat'][] = [ ($day['day_epoch'] - 86400) * 1000, $day['hour'], (int)$day['repeat_visitors']];
                        }
                    }

                    else if ($day['day_epoch'] != $end && $day['day_epoch'] != $initial_start) {
                        if ( in_array((int)$day['hour'], $end_hours_array) ) {
                            $temp_results['new'][] = [$day['day_epoch'] * 1000, $day['hour'], (int)$day['new_visitors']];
                            $temp_results['repeat'][] = [$day['day_epoch'] * 1000, $day['hour'], (int)$day['repeat_visitors']];
                        }
                        else {
                            $temp_results['new'][] = [ ($day['day_epoch'] - 86400) * 1000, $day['hour'], (int)$day['new_visitors']];
                            $temp_results['repeat'][] = [ ($day['day_epoch'] - 86400) * 1000, $day['hour'], (int)$day['repeat_visitors']];
                        }
                    }
                }

                /**
                 * Each day SUM(values) for new visitors
                 */
                $day_epoch = $initial_start;
                foreach($temp_results['new'] as $row) {
                    if ($day_epoch == $row[0]) {
                        $results['new'][$row[0]] += $row[2];
                    }
                    else {
                        $day_epoch = $row[0];
                        $results['new'][$row[0]] = $row[2];
                    }
                }

                /**
                 * Each day SUM(values) for repeat visitors
                 */
                $day_epoch = $initial_start;
                foreach($temp_results['repeat'] as $row) {
                    if ($day_epoch == $row[0]) {
                        $results['repeat'][$row[0]] += $row[2];
                    }
                    else {
                        $day_epoch = $row[0];
                        $results['repeat'][$row[0]] = $row[2];
                    }
                }

                /**
                 * Convert the day_epoch key into a array value
                 * The day_epoch had to be used as a key for the above code
                 */
                $count = 0;
                foreach($results['new'] as $key => $value) {
                    $results['new'][$count] = [$key, $value];
                    unset($results['new'][$key]);
                    $count += 1;
                }

                $count = 0;
                foreach($results['repeat'] as $key => $value) {
                    $results['repeat'][$count] = [$key, $value];
                    unset($results['repeat'][$key]);
                    $count += 1;
                }
            }
        }

        // Get all the events which were ran on between these dates
        $event = new Event;
        if ($authorizer->checkAccess($currentUser, 'uri_site_admin')) {
            $dailyEvents = $event
                ->with('event_category')
                ->where('start_date', '>=', $start)
                ->where('end_date', '<=', $end)
                ->where('venue_id', $venue_filter)
                ->get();
        }
        else {
            $dailyEvents = $event
                ->with('event_category')
                ->where('start_date', '>=', $start)
                ->where('end_date', '<=', $end)
                ->where('admin_event', 0)
                ->where('venue_id', $venue_filter)
                ->get();
        }

        foreach($dailyEvents as $event) {
            $results['event'][] = [
                'start_date' => $event->start_date * 1000, 
                'end_date' => $event->end_date * 1000, 
                'name' => $event->name,
                'color' => $event->event_category->category_color,
                'category_id' => $event->event_category_id
            ];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listDayNightReportVisitorsTimeOfDay(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * filter on the user's primary_venue_id and get the venue's timezone
         */
        $venue_filter = $currentUser->primary_venue_id;
        $timezone = $currentUser->primaryVenue->time_zone;

        /**
         * initialise several variables
         */
        $results = [];
        $hours_array = [];

        /**
         * WORK ON SELECTED RANGE FROM HERE
         * - determine start and end
         */
        $start = floor($args['start']/1000);
        $end = floor($args['end']/1000);
        $start_hour = Carbon::createFromTimestamp($start, $timezone)->hour;
        $end_hour = Carbon::createFromTimestamp($end, $timezone)->hour;
        $start_date = Carbon::createFromTimestamp($start, $timezone);

        $range_length = $start_date->startOfDay($timezone)->diffInDays(
            Carbon::createFromTimestamp($end, $timezone)->addDay()->startOfDay()
        );

        if ($start_hour <= $end_hour) {
            foreach (range($start_hour, $end_hour) as $number) {
                array_push($hours_array, $number);
            }
        } else {
            foreach (range($start_hour, 24) as $number) {
                array_push($hours_array, $number);
            }

            foreach (range(0, $end_hour) as $number) {
                array_push($hours_array, $number);
            }
        }

        $hours = implode(",", $hours_array);

        /**
         * get the start of day for head and set correct timezone for head_start and head_end
         */
        $initial_start = $start_date->startOfDay()->format('U');

        /**
         * prepare the query using a "random" PDO connection
         */
        $query = new TrackingDailyStatsVenueUniqueDeviceUuids;
        $db = $query->getConnection()->getPdo();

        if ($start > 0 && $end > 0) {
            $db->exec("SET @hours = '{$hours}'");

            /**
             * check whether the user is allowed to view full venue stats or not
             * and act accordingly
             */
            if ($currentUser->full_venue_view_allowed == 1) {
                /**
                 * user is allowed to view full venue stats
                 * prepare and execute the query
                 */
                $initialresultsVisitors = $db->prepare('
                    SELECT COUNT(DISTINCT(device_uuid)) AS visitors,
                           hour_epoch,
                           hour,
                           day_epoch
                    FROM
                    (
                        SELECT device_uuid,
                               day_epoch,
                               (day_epoch + (3600*hour)) AS hour_epoch,
                               hour
                        FROM tracking_daily_stats_venue_unique_device_uuids_per_hour
                        PARTITION (p' . $venue_filter . ')
                        WHERE day_epoch >= :initial_start
                        AND day_epoch < :end_1
                        AND FIND_IN_SET(hour, @hours)
                    ) AS temp1
                    WHERE hour_epoch >= :start
                    AND hour_epoch <= :end_2
                    GROUP BY hour'
                );
            } else {
                /**
                 * user is NOT allowed to view full venue stats
                 * - filter on the user's allowed zones AND on the user's primary_venue_id
                 * - get the allowed zones, then create an array which only contains their ids
                 */
                $allowed_zones = collect($currentUser->zones);
                $allowed_zone_ids = [];

                foreach ($allowed_zones as $zone) {
                    $allowed_zone_ids[] = $zone->id;
                }

                /**
                 * create a string containing zone ids, comma seperated, to insert into the PDO statement
                 */
                $ids_string = implode(',', $allowed_zone_ids);

                /**
                 * prepare and execute the query with venue AND zones filtering
                 */
                $initialresultsVisitors = $db->prepare('
                    SELECT COUNT(DISTINCT(device_uuid)) AS visitors,
                           hour_epoch,
                           hour,
                           day_epoch
                    FROM
                    (
                        SELECT device_uuid,
                               day_epoch,
                               (day_epoch + (3600*hour)) AS hour_epoch,
                               hour
                        FROM tracking_daily_stats_zone_unique_device_uuids_per_hour
                        PARTITION (p' . $venue_filter . ')
                        WHERE day_epoch >= :initial_start
                        AND day_epoch < :end_1
                        AND FIND_IN_SET(hour, @hours)
                        AND zone_id IN (' . $ids_string . ')
                    ) AS temp1
                    WHERE hour_epoch >= :start
                    AND hour_epoch <= :end_2
                    GROUP BY hour'
                );
            }
        }

        /**
         * bind the parameters to the selected query
         */
        $initialresultsVisitors->bindParam(':initial_start', $initial_start);
        $initialresultsVisitors->bindParam(':start', $start);
        $initialresultsVisitors->bindParam(':end_1', $end);
        $initialresultsVisitors->bindParam(':end_2', $end);

        /**
         * execute the query for total visitors
         */
        $initialresultsVisitors->execute();

        /**
         * loop through the array and add to tempresults
         */
        $tempresults = [];
        foreach ($initialresultsVisitors as $item) {
            if(isset($tempresults[$item['hour']])) {
                $tempresults[$item['hour']][0] += (int)$item['visitors'];
            } else {
                $tempresults[$item['hour']][0] = (int)$item['visitors'];
            }
        }

        /**
         * process the results then sort the results by hour
         */
        foreach($tempresults as $key => $value) {
            $results[] = [$key, round($value[0]/$range_length)];
        }

        $results = array_values(array_sort($results, function ($value) {
            return $value[0];
        }));

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listDayNightReportZoneVisitorsComparison(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * filter on the user's primary_venue_id and get the user's timezone
         */
        $venue_filter = $currentUser->primary_venue_id;
        $timezone = $currentUser->primaryVenue->time_zone;

        /**
         * initialise several variables
         */
        $results = [];
        $hours_array = [];

        /*************************************************************************************
         * WORK ON SELECTED RANGE FROM HERE
         * - get the desired time zone
         * - set $start, $end
         *************************************************************************************/
        $start = floor($args['start']/1000);
        $end = floor($args['end']/1000);
        $start_hour = Carbon::createFromTimestamp($start, $timezone)->hour;
        $end_hour = Carbon::createFromTimestamp($end, $timezone)->hour;
        $start_date = Carbon::createFromTimestamp($start, $timezone);

        if ($start_hour <= $end_hour) {
            foreach (range($start_hour, $end_hour) as $number) {
                array_push($hours_array, $number);
            }
        } else {
            foreach (range($start_hour, 24) as $number) {
                array_push($hours_array, $number);
            }

            foreach (range(0, $end_hour) as $number) {
                array_push($hours_array, $number);
            }
        }

        $hours = implode(",", $hours_array);

        /**
         * get the start of day for head and set correct timezone for head_start and head_end
         */
        $initial_start = $start_date->startOfDay()->format('U');

        /**
         * prepare the query using a "random" PDO connection
         */
        $query = new TrackingDailyStatsVenueUniqueDeviceUuids;
        $db = $query->getConnection()->getPdo();

        if ($start > 0 && $end > 0) {
            /**
             * check whether the user is allowed to view full venue stats or not
             * and act accordingly
             */
            $db->exec("SET @hours = '{$hours}'");

            if ($currentUser->full_venue_view_allowed == 1) {
                /**
                 * user is allowed to view full venue stats
                 * prepare and execute the query
                 */
                $initialresultsVisitors = $db->prepare('
                    SELECT zone_id,
                           zone.name AS zone_name,
                           COUNT(CASE WHEN is_repeat = 0 THEN 1 END) AS new_visitors,
                           COUNT(CASE WHEN is_repeat = 1 THEN 1 END) AS repeat_visitors
                    FROM
                    (
                        SELECT DISTINCT(device_uuid),
                               zone_id,
                               day_epoch,
                               is_repeat
                        FROM
                        (
                            SELECT device_uuid,
                                   day_epoch,
                                   (day_epoch + (3600*hour)) AS hour_epoch,
                                   hour,
                                   zone_id,
                                   is_repeat
                            FROM tracking_daily_stats_zone_unique_device_uuids_per_hour
                            PARTITION (p' . $venue_filter . ')
                            WHERE day_epoch >= :initial_start
                            AND day_epoch < :end_1
                            AND FIND_IN_SET(hour, @hours)
                        ) AS temp1
                        WHERE hour_epoch >= :start
                        AND hour_epoch <= :end_2
                    ) AS temp2
                    INNER JOIN zone ON zone_id = zone.id
                    GROUP BY zone_id'
                );
            } else {
                /**
                 * user is NOT allowed to view full venue stats
                 * - filter on the user's allowed zones AND on the user's primary_venue_id
                 * - get the allowed zones, then create an array which only contains their ids
                 */
                $allowed_zones = collect($currentUser->zones);
                $allowed_zone_ids = [];

                foreach ($allowed_zones as $zone) {
                    $allowed_zone_ids[] = $zone->id;
                }

                /**
                 * create a string containing zone ids, comma seperated, to insert into the PDO statement
                 */
                $ids_string = implode(',', $allowed_zone_ids);

                /**
                 * prepare and execute the query with venue AND zones filtering
                 */
                $initialresultsVisitors = $db->prepare('
                    SELECT zone_id,
                           zone.name AS zone_name,
                           COUNT(CASE WHEN is_repeat = 0 THEN 1 END) AS new_visitors,
                           COUNT(CASE WHEN is_repeat = 1 THEN 1 END) AS repeat_visitors
                    FROM
                    (
                        SELECT DISTINCT(device_uuid),
                               zone_id,
                               day_epoch,
                               is_repeat
                        FROM
                        (
                            SELECT device_uuid,
                                   day_epoch,
                                   (day_epoch + (3600*hour)) AS hour_epoch,
                                   hour,
                                   zone_id,
                                   is_repeat
                            FROM tracking_daily_stats_zone_unique_device_uuids_per_hour
                            PARTITION (p' . $venue_filter . ')
                            WHERE day_epoch >= :initial_start
                            AND day_epoch < :end_1
                            AND FIND_IN_SET(hour, @hours)
                            AND zone_id IN (' . $ids_string . ')
                        ) AS temp1
                        WHERE hour_epoch >= :start
                        AND hour_epoch <= :end_2
                    ) AS temp2
                    INNER JOIN zone ON zone_id = zone.id
                    GROUP BY zone_id'
                );
            }
        }

        /**
         * bind the parameters to the selected query
         */
        $initialresultsVisitors->bindParam(':initial_start', $initial_start);
        $initialresultsVisitors->bindParam(':start', $start);
        $initialresultsVisitors->bindParam(':end_1', $end);
        $initialresultsVisitors->bindParam(':end_2', $end);

        /**
         * execute the query for total visitors
         */
        $initialresultsVisitors->execute();

        foreach ($initialresultsVisitors as $item) {
            $results[] = [$item['zone_name'], $item['repeat_visitors'], $item['new_visitors']];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listDayNightReportVisitorDurations(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * filter on the user's primary_venue_id and get the venue's timezone
         */
        $venue_filter = $currentUser->primary_venue_id;
        $timezone = $currentUser->primaryVenue->time_zone;

        $results = [
            'dt_skipped' => [],
            'dt_level_1' => [],
            'dt_level_2' => [],
            'dt_level_3' => [],
            'dt_level_4' => [],
            'dt_level_5' => [],
            'dt_average' => []
        ];

        $venueQuery = new Venue;
        $venue_collection = $venueQuery->where('id', $venue_filter)->get();

        /**
         * initialise several variables
         */
        $hours_array = [];

        /**
         * WORK ON SELECTED RANGE FROM HERE
         * - determine start and end
         */
        $start = floor($args['start']/1000);
        $end = floor($args['end']/1000);
        $start_hour = Carbon::createFromTimestamp($start, $timezone)->hour;
        $end_hour = Carbon::createFromTimestamp($end, $timezone)->hour;
        $start_date = Carbon::createFromTimestamp($start, $timezone);

        if ($start_hour <= $end_hour) {
            foreach (range($start_hour, $end_hour) as $number) {
                array_push($hours_array, $number);
            }
        } else {
            foreach (range($start_hour, 24) as $number) {
                array_push($hours_array, $number);
            }

            foreach (range(0, $end_hour) as $number) {
                array_push($hours_array, $number);
            }
        }

        $hours = implode(',', $hours_array);

        /**
         * get the start of day for head and set correct timezone for head_start and head_end
         */
        $initial_start = $start_date->startOfDay()->format('U');

        /**
         * prepare the query using a "random" PDO connection
         */
        $query = new TrackingDailyStatsVenueUniqueDeviceUuids;
        $db = $query->getConnection()->getPdo();

        if ($start > 0 && $end > 0) {
            /**
             * check whether the user is allowed to view full venue stats or not
             * and act accordingly
             */
            $dt_threshold_1  = $venue_collection[0]['dt_threshold_1'];
            $dt_threshold_2  = $venue_collection[0]['dt_threshold_2'];
            $dt_threshold_3  = $venue_collection[0]['dt_threshold_3'];
            $dt_threshold_4  = $venue_collection[0]['dt_threshold_4'];
            $dt_threshold_5  = $venue_collection[0]['dt_threshold_5'];
            $dwell_bucket    = $venue_collection[0]['footfall_bucket'];

            $init_query = "SET sql_mode             = 'NO_UNSIGNED_SUBTRACTION',
                               @venue_filter        = '{$venue_filter}',
                               @range_start         = '{$initial_start}',
                               @range_end           = '{$end}',
                               @start               = '{$start}',
                               @end                 = '{$end}',
                               @dwell_bucket        = '{$dwell_bucket}',
                               @hours               = '{$hours}',
                               @prev_ts             = 0,
                               @dwell_start         = 0,
                               @prev_dwelltime      = 0,
                               @prev_device_uuid    = NULL,
                               @device_uuid_changed = FALSE,
                               @long_gap            = FALSE,
                               @gap                 = 0,
                               @dt_threshold_1      = '{$dt_threshold_1}',
                               @dt_threshold_2      = '{$dt_threshold_2}',
                               @dt_threshold_3      = '{$dt_threshold_3}',
                               @dt_threshold_4      = '{$dt_threshold_4}',
                               @dt_threshold_5      = '{$dt_threshold_5}'";

            $db->exec($init_query);

            if ($currentUser->full_venue_view_allowed == 1) {
                /**
                 * user is allowed to view full venue stats
                 * first we prepare and execute the query
                 */
                $initialresultsDurations = $db->prepare('
                    SELECT day_epoch,
                           dt_skipped,
                           dt_level_1,
                           dt_level_2,
                           dt_level_3,
                           dt_level_4,
                           dt_level_5,
                           dt_average
                    FROM 
                    (
                        SELECT venue_id,
                               day_epoch,
                               day_of_week,
                               SUM(IF(prev_dwelltime < @dt_threshold_1, 1, 0))                             AS dt_skipped,
                               SUM(IF((prev_dwelltime BETWEEN @dt_threshold_1 AND @dt_threshold_2), 1, 0)) AS dt_level_1,
                               SUM(IF((prev_dwelltime BETWEEN @dt_threshold_2 AND @dt_threshold_3), 1, 0)) AS dt_level_2,
                               SUM(IF((prev_dwelltime BETWEEN @dt_threshold_3 AND @dt_threshold_4), 1, 0)) AS dt_level_3,
                               SUM(IF((prev_dwelltime BETWEEN @dt_threshold_4 AND @dt_threshold_5), 1, 0)) AS dt_level_4,
                               SUM(IF(prev_dwelltime > @dt_threshold_5, 1, 0))                             AS dt_level_5,
                               ROUND(AVG(IF(prev_dwelltime > @dt_threshold_1, prev_dwelltime, NULL))/60)   AS dt_average
                        FROM 
                        (
                            SELECT venue_id,
                                   @prev_device_uuid     AS prev_device_uuid,
                                   @prev_ts              AS prev_ts,
                                   @prev_dwelltime       AS prev_dwelltime,
                                   @dwell_start          := IF(@dwell_start = 0, first_seen, @dwell_start),
                                   device_uuid,
                                   (@device_uuid_changed := IF(@prev_device_uuid <> device_uuid, TRUE, FALSE)) AS changed,
                                   (@long_gap            := IF(@device_uuid_changed = FALSE AND first_seen - @prev_ts > @dwell_bucket, TRUE, FALSE)) AS long_gap,
                                   (@dwell_start         := IF(@device_uuid_changed = TRUE OR @long_gap = TRUE, first_seen, @dwell_start)) as dwell_start,
                                   (@dwelltime           := IF(@long_gap = TRUE, 0, last_seen - @dwell_start)) AS dwelltime,
                                   @prev_device_uuid     := device_uuid,
                                   @prev_ts              := last_seen,
                                   @prev_dwelltime       := @dwelltime,
                                   day_epoch,
                                   day_of_week
                            FROM 
                            (
                                SELECT venue_id,
                                       device_uuid,
                                       first_seen,
                                       last_seen,
                                       day_epoch,
                                       (day_epoch + (3600*hour)) AS hour_epoch,
                                       day_of_week,
                                       hour
                                FROM tracking_daily_stats_venue_unique_device_uuids_per_hour
                                PARTITION (p' . $venue_filter . ')
                                WHERE day_epoch BETWEEN @range_start AND @range_end
                                AND FIND_IN_SET(hour, @hours)
                            ) temp_results
                            WHERE hour_epoch >= @start
                            AND hour_epoch <= @end
                            ORDER BY device_uuid ASC, hour ASC
                        ) temp_results_1
                        WHERE changed = TRUE
                        GROUP BY day_epoch
                    ) final_results'
                );

            } else {
                /**
                 * user is NOT allowed to view full venue stats
                 * - filter on the user's allowed zones AND on the user's primary_venue_id
                 * - get the allowed zones, then create an array which only contains their ids
                 */
                $allowed_zones = collect($currentUser->zones);
                $allowed_zone_ids = [];

                foreach ($allowed_zones as $zone) {
                    $allowed_zone_ids[] = $zone->id;
                }

                /**
                 * create a string containing zone ids, comma seperated, to insert into the PDO statement
                 */
                $ids_string = implode(',', $allowed_zone_ids);

                $db->exec("SET @zones = '{$ids_string}'");

                /**
                 * prepare and execute the query with venue AND zones filtering
                 */
                $initialresultsDurations = $db->prepare('
                    SELECT day_epoch,
                           dt_skipped,
                           dt_level_1,
                           dt_level_2,
                           dt_level_3,
                           dt_level_4,
                           dt_level_5,
                           dt_average
                    FROM 
                    (
                        SELECT venue_id,
                               day_epoch,
                               day_of_week,
                               SUM(IF(prev_dwelltime < @dt_threshold_1, 1, 0))                             AS dt_skipped,
                               SUM(IF((prev_dwelltime BETWEEN @dt_threshold_1 AND @dt_threshold_2), 1, 0)) AS dt_level_1,
                               SUM(IF((prev_dwelltime BETWEEN @dt_threshold_2 AND @dt_threshold_3), 1, 0)) AS dt_level_2,
                               SUM(IF((prev_dwelltime BETWEEN @dt_threshold_3 AND @dt_threshold_4), 1, 0)) AS dt_level_3,
                               SUM(IF((prev_dwelltime BETWEEN @dt_threshold_4 AND @dt_threshold_5), 1, 0)) AS dt_level_4,
                               SUM(IF(prev_dwelltime > @dt_threshold_5, 1, 0))                             AS dt_level_5,
                               ROUND(AVG(IF(prev_dwelltime > @dt_threshold_1, prev_dwelltime, NULL))/60)   AS dt_average
                        FROM 
                        (
                            SELECT venue_id,
                                   @prev_device_uuid     AS prev_device_uuid,
                                   @prev_ts              AS prev_ts,
                                   @prev_dwelltime       AS prev_dwelltime,
                                   @dwell_start          := IF(@dwell_start = 0, first_seen, @dwell_start),
                                   device_uuid,
                                   (@device_uuid_changed := IF(@prev_device_uuid <> device_uuid, TRUE, FALSE)) AS changed,
                                   (@long_gap            := IF(@device_uuid_changed = FALSE AND first_seen - @prev_ts > @dwell_bucket, TRUE, FALSE)) AS long_gap,
                                   (@dwell_start         := IF(@device_uuid_changed = TRUE OR @long_gap = TRUE, first_seen, @dwell_start)) as dwell_start,
                                   (@dwelltime           := IF(@long_gap = TRUE, 0, last_seen - @dwell_start)) AS dwelltime,
                                   @prev_device_uuid     := device_uuid,
                                   @prev_ts              := last_seen,
                                   @prev_dwelltime       := @dwelltime,
                                   day_epoch,
                                   day_of_week
                            FROM tracking_daily_stats_zone_unique_device_uuids_per_hour
                            PARTITION (p' . $venue_filter . ')
                            WHERE FIND_IN_SET(zone_id, @zones)
                            AND day_epoch BETWEEN @range_start AND @range_end
                            AND FIND_IN_SET(hour, @hours)
                            ORDER BY device_uuid ASC, hour ASC
                        ) temp_results
                        WHERE changed = TRUE
                        GROUP BY day_epoch
                    ) final_results'
                );
            }
        }

        /**
         * execute the query for total visitors
         */
        $initialresultsDurations->execute();

        /**
         * process the results from both periods and merge into a single array
         * 5 buckets/series to fill
         */
        foreach ($initialresultsDurations as $initialresultsDuration) {
            $results['dt_skipped'][] = [$initialresultsDuration['day_epoch']*1000, (int)$initialresultsDuration['dt_skipped']];
            $results['dt_level_1'][] = [$initialresultsDuration['day_epoch']*1000, (int)$initialresultsDuration['dt_level_1']];
            $results['dt_level_2'][] = [$initialresultsDuration['day_epoch']*1000, (int)$initialresultsDuration['dt_level_2']];
            $results['dt_level_3'][] = [$initialresultsDuration['day_epoch']*1000, (int)$initialresultsDuration['dt_level_3']];
            $results['dt_level_4'][] = [$initialresultsDuration['day_epoch']*1000, (int)$initialresultsDuration['dt_level_4']];
            $results['dt_level_5'][] = [$initialresultsDuration['day_epoch']*1000, (int)$initialresultsDuration['dt_level_5']];
            $results['dt_average'][] = [$initialresultsDuration['day_epoch']*1000, (int)$initialresultsDuration['dt_average']];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listVisitComparison(Request $request, Response $response, $args) 
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * filter on the user's primary_venue_id
         */
        $venue_filter = $currentUser->primary_venue_id;

        /**
         * initialise several variables
         */
        $results = [];

        /**
         * Get the start and end time
         */
        $start = floor($args['start']/1000);
        $end = floor($args['end']/1000);

        /**
         * prepare and execute the query using a PDO connection provided
         */
        $newVisitQuery = new TrackingDailyStatsVenueUniqueDeviceUuids;
        $db = $newVisitQuery->getConnection()->getPdo();

        /**
         * check whether the user is allowed to view full venue stats or not
         * and act accordingly
         */
        if ($currentUser->full_venue_view_allowed == 1) {

            $visitComparison = $db->prepare('
                SELECT SUM( IF(visits = 0, 1, 0) ) AS one_visit,
                       SUM( IF(visits = 1, 1, 0) ) AS two_visits,
                       SUM( IF(visits = 2, 1, 0) ) AS three_visits,
                       SUM( IF(visits = 3, 1, 0) ) AS four_visits,
                       SUM( IF(visits = 4, 1, 0) ) AS five_visits,
                       SUM( IF(visits = 5, 1, 0) ) AS six_visits,
                       SUM( IF(visits = 6, 1, 0) ) AS seven_visits,
                       SUM( IF(visits = 7, 1, 0) ) AS eight_visits,
                       SUM( IF(visits = 8, 1, 0) ) AS nine_visits,
                       SUM( IF(visits = 9, 1, 0) ) AS ten_visits,
                       SUM( IF(visits = 10, 1, 0) ) AS eleven_visits,
                       SUM( IF(visits = 11, 1, 0) ) AS twelve_visits,
                       day_epoch
                FROM
                (
                    SELECT SUM(visit) AS visits,
                           device_uuid,
                           day_epoch
                    FROM
                    (
                        SELECT device_uuid,
                               IF(device_uuid = prev_device_uuid AND hour - prev_hour >= :bucket, 1, 0) AS visit,
                               day_epoch
                        FROM
                        (
                            SELECT day_epoch,
                                   hour,
                                   device_uuid,
                                   @prev_hour AS prev_hour,
                                   @prev_device_uuid AS prev_device_uuid,
                                   @prev_hour := hour,
                                   @prev_device_uuid := device_uuid
                            FROM tracking_daily_stats_venue_unique_device_uuids_per_hour
                            PARTITION (p' . $venue_filter . ')
                            WHERE day_epoch >= :start
                            AND day_epoch < :end_1
                            ORDER BY day_epoch, device_uuid, hour
                        ) AS temp_1
                        ORDER BY visit DESC, device_uuid
                    ) AS temp_2
                    GROUP BY device_uuid
                    ORDER BY visits DESC
                ) AS temp_3
                GROUP BY day_epoch
            ');
        }

        /**
         * bind the parameters for the prepared statement
         */
        $visitComparison->bindParam(':bucket', $args['bucket']);
        $visitComparison->bindParam(':start', $start);
        $visitComparison->bindParam(':end_1', $end);

        /**
         * execute the query
         */
        $visitComparison->execute();
        $visitComparisonResults = $visitComparison->fetchAll();

        /**
         * prepare the results for use in Highcharts
         * the switch is used to see if we want the visit comparison results or total visits
         */
        if ($args['switch'] == '0') {
            foreach ($visitComparisonResults as $value) {
                $results['one_visit'][] = [$value['day_epoch'] * 1000, (float)$value['one_visit']];
                $results['two_visits'][] = [$value['day_epoch'] * 1000, (float)$value['two_visits']];
                $results['three_visits'][] = [$value['day_epoch'] * 1000, (float)$value['three_visits']];
                $results['four_visits'][] = [$value['day_epoch'] * 1000, (float)$value['four_visits']];
                $results['five_visits'][] = [$value['day_epoch'] * 1000, (float)$value['five_visits']];
                $results['six_visits'][] = [$value['day_epoch'] * 1000, (float)$value['six_visits']];
                $results['seven_visits'][] = [$value['day_epoch'] * 1000, (float)$value['seven_visits']];
                $results['eight_visits'][] = [$value['day_epoch'] * 1000, (float)$value['eight_visits']];
                $results['nine_visits'][] = [$value['day_epoch'] * 1000, (float)$value['nine_visits']];
                $results['ten_visits'][] = [$value['day_epoch'] * 1000, (float)$value['ten_visits']];
                $results['eleven_visits'][] = [$value['day_epoch'] * 1000, (float)$value['eleven_visits']];
                $results['twelve_visits'][] = [$value['day_epoch'] * 1000, (float)$value['twelve_visits']];
            }
        }
        else {
            foreach ($visitComparisonResults as $value) {
                $results['visits'][] = [
                    $value['day_epoch'] * 1000, 
                    ($value['one_visit'] * 1) + 
                    ($value['two_visits'] * 2) + 
                    ($value['three_visits'] * 3) + 
                    ($value['four_visits'] * 4) + 
                    ($value['five_visits'] * 5) +
                    ($value['six_visits'] * 6) +
                    ($value['seven_visits'] * 7) +
                    ($value['eight_visits'] * 8) +
                    ($value['nine_visits'] * 9) +
                    ($value['ten_visits'] * 10) +
                    ($value['eleven_visits'] * 11) +
                    ($value['twelve_visits'] * 12)
                ];

                $results['visitors'][] = [
                    $value['day_epoch'] * 1000, 
                    $value['one_visit'] + 
                    $value['two_visits'] + 
                    $value['three_visits'] + 
                    $value['four_visits'] + 
                    $value['five_visits'] +
                    $value['six_visits'] +
                    $value['seven_visits'] +
                    $value['eight_visits'] +
                    $value['nine_visits'] +
                    $value['ten_visits'] +
                    $value['eleven_visits'] +
                    $value['twelve_visits']
                ]; 
            }
        }

        // Get all the events which were ran on between these dates
        $event = new Event;
        if ($authorizer->checkAccess($currentUser, 'uri_site_admin')) {
            $dailyEvents = $event
                ->with('event_category')
                ->where('start_date', '>=', $start)
                ->where('end_date', '<=', $end)
                ->where('venue_id', $venue_filter)
                ->get();
        }
        else {
            $dailyEvents = $event
                ->with('event_category')
                ->where('start_date', '>=', $start)
                ->where('end_date', '<=', $end)
                ->where('admin_event', 0)
                ->where('venue_id', $venue_filter)
                ->get();
        }

        foreach($dailyEvents as $event) {
            $results['event'][] = [
                'start_date' => $event->start_date * 1000, 
                'end_date' => $event->end_date * 1000, 
                'name' => $event->name,
                'color' => $event->event_category->category_color,
                'category_id' => $event->event_category_id
            ];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listRepeatVisitorReportRepeatVisitors(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * filter on the user's primary_venue_id and get the user's timezone
         */
        $venue_filter = $currentUser->primary_venue_id;
        $timezone = $currentUser->primaryVenue->time_zone;

        /**
         * initialise several variables
         */
        $results = [
            'new'        => [],
            'repeat'     => [],
            'rv_level_1' => [],
            'rv_level_2' => [],
            'rv_level_3' => [],
            'rv_level_4' => [],
            'rv_level_5' => [],
            'rv_level_6' => [],
            'rv_level_7' => [],
            'event'      => []
        ];

        /*************************************************************************************
         * WORK ON SELECTED RANGE FROM HERE
         * - get the desired time zone
         * - set $start, $end
         *
         * TODO:
         * - determine full days within the body
         * - get daily stats for it
         * - determine whether tail is today or not
         *  - yes: we get data from probe_requests
         *  - no: we get hourly stats for it
         * - determine head and get hourly stats for it
         * - using Carbon to copy timestamps you need to use the copy function!!!
         *************************************************************************************/
        $start = floor($args['start']/1000);
        $end = floor($args['end']/1000);
        $this->determineHeadBodyTail($start, $end, $timezone);

        /**
         * execute query for HEAD of selected range
         */
        if($this->head_length > 0) {
            /**
             * get the start of day for head and get the hour of head_start and head_end
             */
            $head_start_hour = $this->head_start->format('G');
            $head_end_hour = $this->head_end->subSecond()->format('G');
            $select_raw_statement = 'day_epoch, SUM(visitors_new) AS visitors_new, SUM(visitors_total) AS visitors_total,' .
                                    'SUM(rv_level_1) AS rv_level_1,' .
                                    'SUM(rv_level_2) AS rv_level_2,' .
                                    'SUM(rv_level_3) AS rv_level_3,' .
                                    'SUM(rv_level_4) AS rv_level_4,' .
                                    'SUM(rv_level_5) AS rv_level_5,' .
                                    'SUM(rv_level_6) AS rv_level_6,' .
                                    'SUM(rv_level_7) AS rv_level_7';

            /**
             * check whether the user is allowed to view full venue stats or not
             * and act accordingly
             */
            if ($currentUser->full_venue_view_allowed == 1) {
                /**
                 * user is allowed to view full venue stats
                 * prepare and execute the query
                 */
                $headVisitors = new TrackingDailyStatsVenueHourlyVisitors;
                $initialresultsHeadVisitors = $headVisitors
                    ->selectRaw($select_raw_statement)
                    ->where('day_epoch', '=', $this->head_day_epoch)
                    ->where('hour', '<=', $head_end_hour)
                    ->where('hour', '>=', $head_start_hour)
                    ->where('venue_id', $venue_filter)
                    ->groupBy('day_epoch')
                    ->get();
            } else {
                /**
                 * user is NOT allowed to view full venue stats
                 * filter on the user's allowed zones AND on the user's primary_venue_id
                 *
                 * get the allowed zones, then create an array which only contains the ids
                 */
                $allowed_zones     = collect($currentUser->zones);
                $allowed_zones_ids = [];
                foreach ($allowed_zones as $allowed_zone) {
                    $allowed_zones_ids[] = $allowed_zone->id;
                }

                /**
                 * prepare and execute the query with venue AND zones filtering
                 */
                $headVisitors = new TrackingDailyStatsZoneHourlyVisitors;
                $initialresultsHeadVisitors = $headVisitors
                    ->selectRaw($select_raw_statement)
                    ->where('day_epoch', '=', $this->head_day_epoch)
                    ->where('hour', '<=', $head_end_hour)
                    ->where('hour', '>=', $head_start_hour)
                    ->where('venue_id', $venue_filter)
                    ->whereIn('zone_id', $allowed_zones_ids)
                    ->groupBy('day_epoch')
                    ->get();
            }

            foreach($initialresultsHeadVisitors as $day) {
                $results['new'][]        = [$day['day_epoch'] * 1000, (int)$day['visitors_new']];
                $results['repeat'][]     = [$day['day_epoch'] * 1000, (int)$day['visitors_total'] - (int)$day['visitors_new']];
                $results['rv_level_1'][] = [$day['day_epoch'] * 1000, (int)$day['rv_level_1']];
                $results['rv_level_2'][] = [$day['day_epoch'] * 1000, (int)$day['rv_level_2']];
                $results['rv_level_3'][] = [$day['day_epoch'] * 1000, (int)$day['rv_level_3']];
                $results['rv_level_4'][] = [$day['day_epoch'] * 1000, (int)$day['rv_level_4']];
                $results['rv_level_5'][] = [$day['day_epoch'] * 1000, (int)$day['rv_level_5']];
                $results['rv_level_6'][] = [$day['day_epoch'] * 1000, (int)$day['rv_level_6']];
                $results['rv_level_7'][] = [$day['day_epoch'] * 1000, (int)$day['rv_level_7']];
            }
        }

        /**
         * execute query for BODY of selected range
         */
        if($this->body_length > 0) {
            /**
             * get body_start and body_end formatted as epoch
             */
            $body_start = $this->body_start->format('U');
            $body_end = $this->body_end->format('U');
            $select_raw_statement = 'day_epoch, SUM(visitors_new) AS visitors_new, SUM(visitors_total) AS visitors_total,' .
                                    'SUM(rv_level_1) AS rv_level_1,' .
                                    'SUM(rv_level_2) AS rv_level_2,' .
                                    'SUM(rv_level_3) AS rv_level_3,' .
                                    'SUM(rv_level_4) AS rv_level_4,' .
                                    'SUM(rv_level_5) AS rv_level_5,' .
                                    'SUM(rv_level_6) AS rv_level_6,' .
                                    'SUM(rv_level_7) AS rv_level_7';

            /**
             * check whether the user is allowed to view full venue stats or not
             * and act accordingly
             */
            if ($currentUser->full_venue_view_allowed == 1) {
                /**
                 * user is allowed to view full venue stats
                 * prepare and execute the query for the daily stats for BODY
                 */
                $bodyVisitors = new TrackingDailyStatsVenueVisitors;
                $initialresultsbodyVisitors = $bodyVisitors
                    ->select('day_epoch', 'visitors_new', 'visitors_total', 'rv_level_1', 'rv_level_2', 'rv_level_3', 'rv_level_4', 'rv_level_5', 'rv_level_6', 'rv_level_7')
                    ->where('day_epoch', '>=', $body_start)
                    ->where('day_epoch', '<', $body_end)
                    ->where('venue_id', $venue_filter)
                    ->orderBy('day_epoch', 'asc')
                    ->get();
            } else {
                /**
                 * user is NOT allowed to view full venue stats
                 * filter on the user's allowed zones AND on the user's primary_venue_id
                 *
                 * get the allowed zones, then create an array which only contains the ids
                 */
                $allowed_zones     = collect($currentUser->zones);
                $allowed_zones_ids = [];
                foreach ($allowed_zones as $allowed_zone) {
                    $allowed_zones_ids[] = $allowed_zone->id;
                }

                /**
                 * prepare and execute the query for the daily stats for BODY with venue AND zones filtering
                 */
                $bodyVisitors = new TrackingDailyStatsZoneVisitors;
                $initialresultsbodyVisitors = $bodyVisitors
                    ->selectRaw($select_raw_statement)
                    ->where('day_epoch', '>=', $body_start)
                    ->where('day_epoch', '<', $body_end)
                    ->where('venue_id', $venue_filter)
                    ->whereIn('zone_id', $allowed_zones_ids)
                    ->groupBy('day_epoch')
                    ->orderBy('day_epoch', 'asc')
                    ->get();
            }

            foreach($initialresultsbodyVisitors as $day) {
                $results['new'][]        = [$day['day_epoch'] * 1000, $day['visitors_new']];
                $results['repeat'][]     = [$day['day_epoch'] * 1000, $day['visitors_total'] - $day['visitors_new']];
                $results['rv_level_1'][] = [$day['day_epoch'] * 1000, (int)$day['rv_level_1']];
                $results['rv_level_2'][] = [$day['day_epoch'] * 1000, (int)$day['rv_level_2']];
                $results['rv_level_3'][] = [$day['day_epoch'] * 1000, (int)$day['rv_level_3']];
                $results['rv_level_4'][] = [$day['day_epoch'] * 1000, (int)$day['rv_level_4']];
                $results['rv_level_5'][] = [$day['day_epoch'] * 1000, (int)$day['rv_level_5']];
                $results['rv_level_6'][] = [$day['day_epoch'] * 1000, (int)$day['rv_level_6']];
                $results['rv_level_7'][] = [$day['day_epoch'] * 1000, (int)$day['rv_level_7']];
            }
        }

        /**
         * execute query for TAIL of selected range
         */
        if($this->tail_length > 0) {
            /**
             * we first determine whether TAIL is in today or not
             */
            if($this->tail_in_today) {
                /**
                 * get body_start and body_end formatted as epoch
                 */
                $tail_start = $this->tail_start->format('U');
                $tail_end = $this->tail_end->format('U');

                /**
                 * prepare and execute the query using a PDO connection provided
                 */
                $repeatVisitorsQuery = new ProbeRequest;
                $db = $repeatVisitorsQuery->getConnection()->getPdo();

                /**
                 * check whether the user is allowed to view full venue stats or not
                 * and act accordingly
                 */
                if ($currentUser->full_venue_view_allowed == 1) {
                    /**
                     * user is allowed to view full venue stats
                     * create the prepared statement with the vars available for repeat visitors today
                     * NOTE: repeat is an SQL keyword, don't use it in the query
                     */
                    $repeatVisitors = $db->prepare('
                        SELECT COUNT(DISTINCT probes.device_uuid) AS `returning`
                        FROM probe_request AS probes
                        INNER JOIN tracking_daily_stats_venue_device_uuid_cache AS repeat_visitors
                        ON probes.device_uuid = repeat_visitors.device_uuid
                        WHERE repeat_visitors.first_seen < :start_1
                        AND probes.ts > :start_2
                        AND ts < :end
                        AND probes.venue_id = :venue_id'
                    );
                } else {
                    /**
                     * user is NOT allowed to view full venue stats
                     * filter on the user's allowed zones AND on the user's primary_venue_id
                     *
                     * get the allowed zones, then create an array which only contains the ids
                     */
                    $allowed_zones = collect($currentUser->zones);
                    $allowed_drones_ids = [];
                    foreach ($allowed_zones as $allowed_zone) {
                        foreach ($allowed_zone->drones as $drone) {
                            $allowed_drones_ids[] = $drone->id;
                        }
                    }

                    /**
                     * create a string containing drone ids, comma seperated, to insert into the PDO statement
                     */
                    $ids_string = implode(',', $allowed_drones_ids);

                    /**
                     * execute the prepared statement with the vars available for repeat visitors today
                     * NOTE: repeat is an SQL keyword, don't use it in the query
                     */
                    $repeatVisitors = $db->prepare('
                        SELECT COUNT(DISTINCT probes.device_uuid) AS `returning`
                        FROM probe_request AS probes
                        INNER JOIN tracking_daily_stats_venue_device_uuid_cache AS repeat_visitors
                        ON probes.device_uuid = repeat_visitors.device_uuid
                        WHERE repeat_visitors.first_seen < :start_1
                        AND probes.ts > :start_2
                        AND ts < :end
                        AND probes.venue_id = :venue_id
                        AND drone_id IN (' . $ids_string . ')'
                    );
                }

                /**
                 * bind the parameters for the prepared statement
                 * in this case: with PDO prepared statements we couldn't use the same parameter twice...
                 */
                $repeatVisitors->bindParam(':start_1', $tail_start);
                $repeatVisitors->bindParam(':start_2', $tail_start);
                $repeatVisitors->bindParam(':end', $tail_end);
                $repeatVisitors->bindParam(':venue_id', $venue_filter);

                /**
                 * execute the query
                 */
                $repeatVisitors->execute();
                $initialresultsRepeatVisitors = $repeatVisitors->fetch();

                /**
                 * check whether the user is allowed to view full venue stats or not
                 * and act accordingly
                 */
                if ($currentUser->full_venue_view_allowed == 1) {
                    /**
                     * user is allowed to view full venue stats
                     * create the prepared statement with the vars available for total visitors today
                     */
                    $totalVisitors = $db->prepare('
                        SELECT COUNT(DISTINCT device_uuid) AS total
                        FROM probe_request
                        WHERE ts > :start
                        AND ts < :end
                        AND venue_id = :venue_id'
                    );

                } else {
                    /**
                     * user is NOT allowed to view full venue stats
                     * filter on the user's allowed zones AND on the user's primary_venue_id
                     *
                     * get the allowed zones, then create an array which only contains the ids
                     */
                    $allowed_zones = collect($currentUser->zones);
                    $allowed_drones_ids = [];
                    foreach ($allowed_zones as $allowed_zone) {
                        foreach ($allowed_zone->drones as $drone) {
                            $allowed_drones_ids[] = $drone->id;
                        }
                    }

                    /**
                     * create a string containing drone ids, comma seperated, to insert into the PDO statement
                     */
                    $ids_string = implode(',', $allowed_drones_ids);

                    /**
                     * execute the prepared statement with the vars available for total visitors today
                     */
                    $totalVisitors = $db->prepare('
                        SELECT COUNT(DISTINCT device_uuid) AS total
                        FROM probe_request
                        WHERE ts > :start
                        AND ts < :end
                        AND venue_id = :venue_id
                        AND drone_id IN (' . $ids_string . ')'
                    );
                }

                /**
                 * bind the parameters for the prepared statement
                 */
                $totalVisitors->bindParam(':start', $tail_start);
                $totalVisitors->bindParam(':end', $tail_end);
                $totalVisitors->bindParam(':venue_id', $venue_filter);

                /**
                 * execute the query
                 */
                $totalVisitors->execute();
                $initialresultsTotalVisitors = $totalVisitors->fetch();

                $startoftoday        = Carbon::now($timezone)->startOfDay()->format('U') * 1000;
                $results['repeat'][] = [$startoftoday, $initialresultsRepeatVisitors['returning']];
                $results['new'][]    = [$startoftoday, $initialresultsTotalVisitors['total'] - $initialresultsRepeatVisitors['returning']];
            } else {
                /**
                 * TAIL is not in today so we get hourly stats for TAIL
                 * - get the start of day for tail and get the hour of tail_start and tail_end
                 */
                $tail_start_hour = $this->tail_start->format('G');
                $tail_end_hour = $this->tail_end->format('G');
                $select_raw_statement = 'day_epoch, SUM(visitors_new) AS visitors_new, SUM(visitors_total) AS visitors_total,' .
                                        'SUM(rv_level_1) AS rv_level_1,' .
                                        'SUM(rv_level_2) AS rv_level_2,' .
                                        'SUM(rv_level_3) AS rv_level_3,' .
                                        'SUM(rv_level_4) AS rv_level_4,' .
                                        'SUM(rv_level_5) AS rv_level_5,' .
                                        'SUM(rv_level_6) AS rv_level_6,' .
                                        'SUM(rv_level_7) AS rv_level_7';

                /**
                 * check whether the user is allowed to view full venue stats or not
                 * and act accordingly
                 */
                if ($currentUser->full_venue_view_allowed == 1) {
                    /**
                     * user is allowed to view full venue stats
                     * prepare and execute the query
                     */
                    $tailVisitors = new TrackingDailyStatsVenueHourlyVisitors;
                    $initialresultsTailVisitors = $tailVisitors
                        ->selectRaw($select_raw_statement)
                        ->where('day_epoch', '=', $this->tail_day_epoch)
                        ->where('hour', '<', $tail_end_hour)
                        ->where('hour', '>=', $tail_start_hour)
                        ->where('venue_id', $venue_filter)
                        ->groupBy('day_epoch')
                        ->get();

                } else {
                    /**
                     * user is NOT allowed to view full venue stats
                     * filter on the user's allowed zones AND on the user's primary_venue_id
                     *
                     * get the allowed zones, then create an array which only contains the ids
                     */
                    $allowed_zones = collect($currentUser->zones);
                    $allowed_zones_ids = [];
                    foreach ($allowed_zones as $allowed_zone) {
                        $allowed_zones_ids[] = $allowed_zone->id;
                    }

                    /**
                     * prepare and execute the query with venue AND zones filtering
                     */
                    $tailVisitors = new TrackingDailyStatsZoneHourlyVisitors;
                    $initialresultsTailVisitors = $tailVisitors
                        ->selectRaw($select_raw_statement)
                        ->where('day_epoch', '=', $this->tail_day_epoch)
                        ->where('hour', '<', $tail_end_hour)
                        ->where('hour', '>=', $tail_start_hour)
                        ->where('venue_id', $venue_filter)
                        ->whereIn('zone_id', $allowed_zones_ids)
                        ->groupBy('day_epoch')
                        ->get();
                }

                foreach($initialresultsTailVisitors as $day) {
                    $results['new'][]        = [$day['day_epoch'] * 1000, (int)$day['visitors_new']];
                    $results['repeat'][]     = [$day['day_epoch'] * 1000, (int)$day['visitors_total'] - (int)$day['visitors_new']];
                    $results['rv_level_1'][] = [$day['day_epoch'] * 1000, (int)$day['rv_level_1']];
                    $results['rv_level_2'][] = [$day['day_epoch'] * 1000, (int)$day['rv_level_2']];
                    $results['rv_level_3'][] = [$day['day_epoch'] * 1000, (int)$day['rv_level_3']];
                    $results['rv_level_4'][] = [$day['day_epoch'] * 1000, (int)$day['rv_level_4']];
                    $results['rv_level_5'][] = [$day['day_epoch'] * 1000, (int)$day['rv_level_5']];
                    $results['rv_level_6'][] = [$day['day_epoch'] * 1000, (int)$day['rv_level_6']];
                    $results['rv_level_7'][] = [$day['day_epoch'] * 1000, (int)$day['rv_level_7']];
                }
            }
        }

        // Get all the events which were ran on between these dates
        $event = new Event;
        if ($authorizer->checkAccess($currentUser, 'uri_site_admin')) {
            $dailyEvents = $event
                ->with('event_category')
                ->where('start_date', '>=', $start)
                ->where('end_date', '<=', $end)
                ->where('venue_id', $venue_filter)
                ->get();
        }
        else {
            $dailyEvents = $event
                ->with('event_category')
                ->where('start_date', '>=', $start)
                ->where('end_date', '<=', $end)
                ->where('admin_event', 0)
                ->where('venue_id', $venue_filter)
                ->get();
        }

        foreach($dailyEvents as $event) {
            $results['event'][] = [
                'start_date'  => $event->start_date * 1000, 
                'end_date' => $event->end_date * 1000, 
                'name' => $event->name,
                'color' => $event->event_category->category_color,
                'category_id' => $event->event_category_id
            ];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listVisitorAveragesAlltimeVenueReport(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_venue_report')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * get the venues the currect user has access to and user's timezone
         */
        $venues_allowed = $currentUser->venues;
        $timezone = $currentUser->primaryVenue->time_zone;

        /**
         * start off with an empty array
         */
        $totalVisitorsAllVenues = [];

        /**
         * define the starting point of today and now using Carbon and timezone
         */
        $starttoday = Carbon::now($timezone)->startOfDay()->format('U');
        $today = Carbon::now($timezone);

        foreach ($venues_allowed->unique() as $venue) {
            /**
             * cycle through the venues array and deal with each venue
             *
             * TODO:
             * consider skipping the probe_request query
             */
            if (isset($venue->venue_tracking->capture_start) && $venue->venue_tracking->capture_start < time()) {
                /**
                 * only deal with venues that have a capture start date and one that lies in the past
                 */
                $totalVisitorsAlltime = $venue->tracking_daily_stats_visitors()->sum('visitors_total');

                /**
                 * use Carbon to be able to determine durations
                 */
                $first_day = Carbon::createFromTimestamp($venue->venue_tracking->capture_start, $timezone);

                /**
                 * calculate the averages
                 */
                if($first_day->diffInDays($today) === 0) {
                    $average_daily = $totalVisitorsAlltime;
                } else {
                    $average_daily = round($totalVisitorsAlltime/$first_day->diffInDays($today));
                }

                if($first_day->diffInWeeks($today) === 0) {
                    $average_weekly = $totalVisitorsAlltime;
                } else {
                    $average_weekly = round($totalVisitorsAlltime/$first_day->diffInWeeks($today));
                }

                if($first_day->diffInMonths($today) === 0) {
                    $average_monthly = $totalVisitorsAlltime;
                } else {
                    $average_monthly = round($totalVisitorsAlltime/$first_day->diffInMonths($today));
                }

                if($first_day->diffInYears($today) === 0) {
                    $average_yearly  = $totalVisitorsAlltime;
                } else {
                    /**
                     * if we have a period shorter than a year we divide by 1, else we divide by the returned numeric value
                     */
                    $yearly_divider = $first_day->diffInDays($today)/365 > 1 ? $first_day->diffInDays($today)/365 : 1;
                    $average_yearly = round($totalVisitorsAlltime/$yearly_divider);
                }

                $totalVisitorsAllVenues[] = [
                    'total' => $totalVisitorsAlltime,
                    'days' => $first_day->diffInDays($today),
                    'average_daily' => $average_daily,
                    'average_weekly' => $average_weekly,
                    'average_monthly' => $average_monthly,
                    'average_yearly' => $average_yearly,
                    'venue_id' => $venue->id
                ];
            }
        }

        /**
         * assemble the results into a single object containing averages:
         * - start off with an empty array with keys defined
         */
        $results = [
            'total' => 0,
            'days' => 0,
            'average_daily' => 0,
            'average_weekly' => 0,
            'average_monthly' => 0,
            'average_yearly' => 0
        ];

        $counter = 0;

        foreach($totalVisitorsAllVenues as $value) {
            /**
             * only add the results for a venue when not empty
             */
            if ($value['average_daily'] > 0 && $value['average_weekly'] > 0 && $value['average_monthly'] > 0 && $value['average_yearly'] > 0) {
                $results['total']           += $value['total'];
                $results['days']             = $results['days'] < $value['days'] ? $value['days'] : $results['days']; // determine max
                $results['average_daily']   += $value['average_daily'];
                $results['average_weekly']  += $value['average_weekly'];
                $results['average_monthly'] += $value['average_monthly'];
                $results['average_yearly']  += $value['average_yearly']; //check if this is in order
                $counter++;
            }
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listVenueReportAverageDwelltime(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_venue_report')) {
            throw new NotFoundException($request, $response);
        }

        $results = 0;
        $end = floor($args['end']/1000);
        $start = floor($args['start']/1000);

        /**
         * filter on the venue_id's this user has access to
         */
        $allowed_venues_ids = $currentUser->getVenueIds();

        /**
         * get the user's timezone
         */
        $timezone = $currentUser->primaryVenue->time_zone;

        /**
         * query the 'daily_stats_venue_visitor_dwelltime' table using DailyStatsVenueDwelltime class
         */
        $dwelltimeQuery = new TrackingDailyStatsVenueDwelltime;
        $averageDwelltime = $dwelltimeQuery
            ->where('day_epoch', '>=', $start)
            ->where('day_epoch', '<', $end)
            ->whereIn('venue_id', $allowed_venues_ids)
            ->avg('dt_average');

        $results = round($averageDwelltime/60);

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listVenueReportVisitorDurations(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_venue_report')) {
            throw new NotFoundException($request, $response);
        }

        $results = [
            'dt_skipped' => [],
            'dt_level_1' => [],
            'dt_level_2' => [],
            'dt_level_3' => [],
            'dt_level_4' => [],
            'dt_level_5' => [],
            'dt_average' => []
        ];

        $start = floor($args['start']/1000);
        $end   = floor($args['end']/1000);

        /**
         * filter on the venue_id's this user has access to
         */
        $allowed_venues_ids = $currentUser->getVenueIds();

        /**
         * prepare and execute the query
         */
        $thisWeekDwelltimes = new TrackingDailyStatsVenueDwelltime;
        $initialresultsDurations = $thisWeekDwelltimes
            ->selectRaw('day_epoch, SUM(dt_skipped) AS dt_skipped, SUM(dt_level_1) AS dt_level_1, SUM(dt_level_2) AS dt_level_2, SUM(dt_level_3) AS dt_level_3, SUM(dt_level_4) AS dt_level_4, SUM(dt_level_5) AS dt_level_5, AVG(dt_average) AS dt_average')
            ->where('day_epoch', '>=', $start)
            ->where('day_epoch', '<', $end)
            ->whereIn('venue_id', $allowed_venues_ids)
            ->groupBy('day_epoch')
            ->orderBy('day_epoch', 'asc')
            ->get();

        /**
         * process the results from both periods and merge into a single array
         * 5 buckets/series to fill
         */
        foreach ($initialresultsDurations as $initialresultsDuration) {
            $results['dt_skipped'][] = [
                $initialresultsDuration['day_epoch']*1000,
                (int)$initialresultsDuration['dt_skipped']
            ];
            $results['dt_level_1'][] = [
                $initialresultsDuration['day_epoch']*1000,
                (int)$initialresultsDuration['dt_level_1']
            ];
            $results['dt_level_2'][] = [
                $initialresultsDuration['day_epoch']*1000,
                (int)$initialresultsDuration['dt_level_2']
            ];
            $results['dt_level_3'][] = [
                $initialresultsDuration['day_epoch']*1000,
                (int)$initialresultsDuration['dt_level_3']
            ];
            $results['dt_level_4'][] = [
                $initialresultsDuration['day_epoch']*1000,
                (int)$initialresultsDuration['dt_level_4']
            ];
            $results['dt_level_5'][] = [
                $initialresultsDuration['day_epoch']*1000,
                (int)$initialresultsDuration['dt_level_5']
            ];
            $results['dt_average'][] = [
                $initialresultsDuration['day_epoch']*1000,
                round((int)$initialresultsDuration['dt_average']/60)
            ];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listVenueReportNewVsRepeat(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_venue_report')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * filter on the venue id's the user is allowed to access and get the user's timezone
         */
        $venue_filter = $currentUser->getVenueIds();
        $timezone = $currentUser->primaryVenue->time_zone;

        /**
         * initialise several variables
         */
        $results = [
            'new' => [],
            'repeat' => []
        ];

        /*************************************************************************************
         * WORK ON SELECTED RANGE FROM HERE
         * - get the desired time zone
         * - set $start, $end
         *
         * TODO:
         * - determine full days within the body
         * - get daily stats for it
         * - determine whether tail is today or not
         *  - yes: we get data from probe_requests
         *  - no: we get hourly stats for it
         * - determine head and get hourly stats for it
         *************************************************************************************/
        $start = floor($args['start']/1000);
        $end = floor($args['end']/1000);
        $this->determineHeadBodyTail($start, $end, $timezone);

        /**
         * execute query for HEAD of selected range
         */
        if($this->head_length > 0) {
            /**
             * get the start of day for head and get the hour of head_start and head_end
             */
            $head_start_hour = $this->head_start->format('G');
            $head_end_hour   = $this->head_end->subSecond()->format('G');

            /**
             * prepare and execute the query
             */
            $headVisitors = new TrackingDailyStatsVenueHourlyVisitors;
            $initialresultsHeadVisitors = $headVisitors
                ->selectRaw('day_epoch, SUM(visitors_new) AS new, SUM(visitors_total) AS total')
                ->where('day_epoch', '=', $this->head_day_epoch)
                ->where('hour', '<=', $head_end_hour)
                ->where('hour', '>=', $head_start_hour)
                ->whereIn('venue_id', $venue_filter)
                ->groupBy('day_epoch')
                ->get();

            foreach($initialresultsHeadVisitors as $day) {
                $results['new'][] = [$day['day_epoch']*1000, (int)$day['new']];
                $results['repeat'][] = [$day['day_epoch']*1000, (int)$day['total'] - (int)$day['new']];
            }
        }

        /**
         * execute query for BODY of selected range
         */
        if($this->body_length > 0) {
            /**
             * get body_start and body_end formatted as epoch
             */
            $body_start = $this->body_start->format('U');
            $body_end = $this->body_end->format('U');

            /**
             * prepare and execute the query for the daily stats for BODY
             */
            $bodyVisitors = new TrackingDailyStatsVenueVisitors;
            //$initialresultsbodyVisitors = $bodyVisitors->select('day_epoch', 'visitors_new', 'visitors_total')
            $initialresultsbodyVisitors = $bodyVisitors
                ->selectRaw('day_epoch, SUM(visitors_new) AS visitors_new, SUM(visitors_total) AS visitors_total')
                ->where('day_epoch', '>=', $body_start)
                ->where('day_epoch', '<', $body_end)
                ->whereIn('venue_id', $venue_filter)
                ->orderBy('day_epoch', 'asc')
                ->groupBy('day_epoch')
                ->get();

            foreach($initialresultsbodyVisitors as $day) {
                $results['new'][]    = [$day['day_epoch']*1000, (int)$day['visitors_new']];
                $results['repeat'][] = [$day['day_epoch']*1000, (int)$day['visitors_total'] - (int)$day['visitors_new']];
            }

        }

        /**
         * execute query for TAIL of selected range
         */
        if($this->tail_length > 0) {
            /**
             * we first determine whether TAIL is in today or not
             */
            if($this->tail_in_today) {
                /**
                 * get body_start and body_end formatted as epoch
                 */
                $tail_start = $this->tail_start->format('U');
                $tail_end = $this->tail_end->format('U');

                /**
                 * prepare and execute the query using a PDO connection provided
                 */
                $repeatVisitorsQuery = new ProbeRequest;
                $db = $repeatVisitorsQuery->getConnection()->getPdo();

                /**
                 * create a string containing venue ids, comma seperated, to insert into the PDO statement
                 */
                $ids_string = implode(',', $venue_filter);

                /**
                 * user is allowed to view full venue stats
                 * create the prepared statement with the vars available for repeat visitors today
                 * NOTE: repeat is an SQL keyword, don't use it in the query
                 */
                $repeatVisitors = $db->prepare('
                    SELECT COUNT(DISTINCT probes.device_uuid) AS `returning`
                    FROM probe_request AS probes
                    INNER JOIN daily_stats_venue_device_uuid_cache AS repeat_visitors
                    ON probes.device_uuid = repeat_visitors.device_uuid
                    WHERE repeat_visitors.first_seen < :start_1
                    AND probes.ts > :start_2
                    AND ts < :end
                    AND probes.venue_id IN (' . $ids_string . ')'
                );

                /**
                 * bind the parameters for the prepared statement
                 * in this case: with PDO prepared statements we couldn't use the same parameter twice...
                 */
                $repeatVisitors->bindParam(':start_1', $tail_start);
                $repeatVisitors->bindParam(':start_2', $tail_start);
                $repeatVisitors->bindParam(':end', $tail_end);

                /**
                 * execute the query
                 */
                $repeatVisitors->execute();
                $initialresultsRepeatVisitors = $repeatVisitors->fetch();

                /**
                 * fetch today's total number of unique visitors (not visits!)
                 */
                $initialresultsTotalVisitors = $repeatVisitorsQuery
                    ->selectRaw('COUNT(DISTINCT mac) AS total')
                    ->whereBetween('ts', [$tail_start, $tail_end])
                    ->whereIn('venue_id', $venue_filter)
                    ->get();

                $startoftoday = Carbon::now($timezone)->startOfDay()->format('U') * 1000;
                $results['repeat'][] = [$startoftoday, $initialresultsRepeatVisitors['returning']];
                $results['new'][] = [$startoftoday, $initialresultsTotalVisitors[0]['total'] - $initialresultsRepeatVisitors['returning']];
            } else {
                /**
                 * TAIL is not in today so we get hourly stats for TAIL
                 * - get the start of day for tail and get the hour of tail_start and tail_end
                 */
                $tail_start_hour = $this->tail_start->format('G');
                $tail_end_hour = $this->tail_end->format('G');

                /**
                 * user is allowed to view full venue stats
                 * prepare and execute the query
                 */
                $tailVisitors = new TrackingDailyStatsVenueHourlyVisitors;
                $initialresultsTailVisitors = $tailVisitors
                    ->selectRaw('day_epoch, SUM(visitors_new) AS new, SUM(visitors_total) AS total')
                    ->where('day_epoch', '=', $this->tail_day_epoch)
                    ->where('hour', '<', $tail_end_hour)
                    ->where('hour', '>=', $tail_start_hour)
                    ->whereIn('venue_id', $venue_filter)
                    ->groupBy('day_epoch')
                    ->get();

                foreach($initialresultsTailVisitors as $day) {
                    $results['new'][] = [$day['day_epoch']*1000, (int)$day['new']];
                    $results['repeat'][] = [$day['day_epoch']*1000, (int)$day['total'] - (int)$day['new']];
                }
            }
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listVenueReportVisitorsTimeOfDay(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_venue_report')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * filter on the ids of venues the current user has access to and get the timezone of user's primary venue
         */
        $allowed_venues_ids = $currentUser->getVenueIds();
        $timezone = $currentUser->primaryVenue->time_zone;

        /**
         * initialise several variables
         */
        $results = [];

        /**
         * WORK ON SELECTED RANGE FROM HERE
         * - determine start and end
         * - determine head/body/tail
         */
        $start = floor($args['start']/1000);
        $end = floor($args['end']/1000);
        $this->determineHeadBodyTail($start, $end, $timezone);

        /**
         * execute query for HEAD of selected range
         */
        if($this->head_length > 0) {
            /**
             * get correct formatting for the query
             */
            $head_start_hour = $this->head_start->format('G');
            $head_end_hour = $this->head_end->subSecond()->format('G');

            /**
             * prepare and execute the query
             */
            $headVisitors = new TrackingDailyStatsVenueHourlyVisitors;
            $initialresultsHeadVisitors = $headVisitors
                ->selectRaw('hour, SUM(visitors_total) AS visitors_total')
                ->where('day_epoch', '=', $this->head_day_epoch)
                ->where('hour', '<=', $head_end_hour)
                ->where('hour', '>=', $head_start_hour)
                ->whereIn('venue_id', $allowed_venues_ids)
                ->groupBy('hour')
                ->get();
        } else {
            /**
             * we do nothing and return a zero value
             */
            $initialresultsHeadVisitors = 0;
        }

        /**
         * execute query for BODY of selected range
         */
        if($this->body_length > 0) {
            /**
             * get correct formatting for the query
             */
            $body_start = $this->body_start->format('U');
            $body_end = $this->body_end->format('U');

            /**
             * prepare and execute the query for BODY
             */
            $bodyVisitors = new TrackingDailyStatsVenueHourlyVisitors;
            $initialresultsBodyVisitors = $bodyVisitors
                ->selectRaw('hour, SUM(visitors_total) AS visitors_total')
                ->where('day_epoch', '>=', $body_start)
                ->where('day_epoch', '<', $body_end)
                ->whereIn('venue_id', $allowed_venues_ids)
                ->orderBy('hour', 'asc')
                ->groupBy('hour')
                ->get();
        } else {
            /**
             * we do nothing and return a zero value
             */
            $initialresultsBodyVisitors = 0;
        }

        /**
         * execute query for TAIL of selected range
         */
        if($this->tail_length > 0) {
            /**
             * we first determine whether TAIL is in today or not
             */
            if($this->tail_in_today) {
                /**
                 * get correct formatting for the query
                 */
                $tail_start = $this->tail_start->format('U');
                $tail_end = $this->tail_end->format('U');

                /**
                 * prepare and execute the query using a PDO connection provided
                 */
                $newVisitorsQuery = new ProbeRequest;
                $db = $newVisitorsQuery->getConnection()->getPdo();

                /**
                 * create a string containing venue ids, comma seperated, to insert into the PDO statement
                 */
                $ids_string = implode(',', $allowed_venues_ids);

                /**
                 * create the prepared statement with the vars available for total visitors in TAIL today
                 */
                $totalVisitors = $db->prepare("
                    SELECT FROM_UNIXTIME(ts,'%k') AS hour,
                    COUNT(DISTINCT device_uuid) AS visitors_total
                    FROM probe_request
                    WHERE ts > :start
                    AND ts < :end
                    AND venue_id IN (" . $ids_string . ")
                    GROUP BY hour"
                );

                /**
                 * bind the parameters for the prepared statement
                 */
                $totalVisitors->bindParam(':start', $tail_start);
                $totalVisitors->bindParam(':end', $tail_end);

                /**
                 * execute the query for total visitors in TAIL
                 */
                $totalVisitors->execute();
                $initialresultsTailVisitors = $totalVisitors->fetchAll();
            } else {
                /**
                 * format correctly for the query
                 */
                $tail_start_hour = $this->tail_start->format('G');
                $tail_end_hour = $this->tail_end->format('G');

                /**
                 * prepare and execute the query
                 */
                $tailVisitors = new TrackingDailyStatsVenueHourlyVisitors;
                $initialresultsTailVisitors = $tailVisitors
                    ->selectRaw('hour, SUM(visitors_total) AS visitors_total')
                    ->where('day_epoch', '=', $this->tail_day_epoch)
                    ->where('hour', '<', $tail_end_hour)
                    ->where('hour', '>=', $tail_start_hour)
                    ->whereIn('venue_id', $allowed_venues_ids)
                    ->groupBy('hour')
                    ->get();
            }
        } else {
            /**
             * we do nothing and return zero value
             */
            $initialresultsTailVisitors = 0;
        }

        /**
         * merge the head/body/tail arrays, all containing hour/visitors data
         */
        $tempresults = [];

        /**
         * while merging the arrays we also keep count of the number of data points we have collected for each hour so that
         * we're able to calculate an accurate average visitor count per hour
         */
        if($this->head_length > 0) {
            /**
             * loop through the array and append to tempresults
             */
            foreach ($initialresultsHeadVisitors as $item) {
                if(isset($tempresults[$item['hour']])) {
                    $tempresults[$item['hour']][0] += (int)$item['visitors_total'];
                    $tempresults[$item['hour']][1] += 1;
                }else {
                    $tempresults[$item['hour']][0] = (int)$item['visitors_total'];
                    $tempresults[$item['hour']][1] = 1;
                }
            }
        }

        if($this->body_length > 0) {
            /**
             * loop through the array and append to tempresults
             * (in the body, the number of data points is the same as $body_length)
             */
            foreach ($initialresultsBodyVisitors as $item) {
                if(isset($tempresults[$item['hour']])) {
                    $tempresults[$item['hour']][0] += (int)$item['visitors_total'];
                    $tempresults[$item['hour']][1] += (int)$this->body_length;
                }else {
                    $tempresults[$item['hour']][0] = (int)$item['visitors_total'];
                    $tempresults[$item['hour']][1] = (int)$this->body_length;
                }
            }
        }

        if($this->tail_length > 0) {
            /**
             * loop through the array and append to tempresults
             */
            foreach ($initialresultsTailVisitors as $item) {
                if(isset($tempresults[$item['hour']])) {
                    $tempresults[$item['hour']][0] += (int)$item['visitors_total'];
                    $tempresults[$item['hour']][1] += 1;
                }else {
                    $tempresults[$item['hour']][0] = (int)$item['visitors_total'];
                    $tempresults[$item['hour']][1] = 1;
                }
            }
        }

        /**
         * process the results then sort the results by hour
         */
        foreach($tempresults as $key => $value) {
            $results[] = [$key, round($value[0]/$value[1])];
        }

        $results = array_values(array_sort($results, function ($value) {
            return $value[0];
        }));

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listVisitorReportVenueVisitorsComparison(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_venue_report')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * filter on the venue_id's this user has access to and get the user's timezone
         */
        $allowed_venues_ids = $currentUser->getVenueIds();
        $timezone = $currentUser->primaryVenue->time_zone;

        /**
         * initialise several variables
         */
        $results = [];

        /*************************************************************************************
         * WORK ON SELECTED RANGE FROM HERE
         * - get the desired time zone
         * - set $start, $end
         *
         * TODO:
         * - determine full days within the body
         * - get daily stats for it
         * - determine whether tail is today or not
         *  - yes: we get data from probe_requests
         *   - no: we get hourly stats for it
         *   - determine head and get hourly stats for it
         *   - using Carbon to copy timestamps you need to use the copy function!!!
         *************************************************************************************/
        $start = floor($args['start']/1000);
        $end = floor($args['end']/1000);
        $this->determineHeadBodyTail($start, $end, $timezone);

        /**
         * execute query for HEAD of selected range
         */
        if($this->head_length > 0) {
            /**
             * get the start of day for head and get the hour of head_start and head_end
             */
            $head_start_hour = $this->head_start->format('G');
            $head_end_hour = $this->head_end->subSecond()->format('G');

            /**
             * prepare and execute the query
             */
            $headVisitors = new TrackingDailyStatsVenueHourlyVisitors;
            $initialresultsHeadVisitors = $headVisitors
                ->with('venue')
                ->selectRaw('venue_id, SUM(visitors_total) AS total, SUM(visitors_new) AS new')
                ->where('day_epoch', $this->head_day_epoch)
                ->where('hour', '<=', $head_end_hour)
                ->where('hour', '>=', $head_start_hour)
                ->whereIn('venue_id', $allowed_venues_ids)
                ->groupBy('venue_id')
                ->get();
        }

        /**
         * execute query for BODY of selected range
         */
        if($this->body_length > 0) {
            /**
             * get body_start and body_end formatted as epoch
             */
            $body_start = $this->body_start->format('U');
            $body_end   = $this->body_end->format('U');

            /**
             * prepare and execute the query for BODY
             */
            $visitorsQuery = new TrackingDailyStatsVenueVisitors;
            $initialresultsBodyVisitors = $visitorsQuery
                ->with('venue')
                ->selectRaw('venue_id, SUM(visitors_total) AS total, SUM(visitors_new) AS new')
                ->where('day_epoch', '>=', $body_start)
                ->where('day_epoch', '<', $body_end)
                ->whereIn('venue_id', $allowed_venues_ids)
                ->groupBy('venue_id')
                ->get();

        }

        /**
         * execute query for TAIL of selected range
         */
        if($this->tail_length > 0) {
            /**
             * we first determine whether TAIL is in today or not
             */
            if($this->tail_in_today) {
                /**
                 * get body_start and body_end formatted as epoch
                 */
                $tail_start = $this->tail_start->format('U');
                $tail_end   = $this->tail_end->format('U');

                /**
                 * prepare and execute the query
                 */
                $tailVisitors = new ProbeRequest;
                $initialresultsTailVisitors = $tailVisitors
                    ->with('venue')
                    ->selectRaw('venue_id, COUNT(DISTINCT mac) AS total, 0 as new')
                    ->whereBetween('ts', [$tail_start, $tail_end])
                    ->whereIn('venue_id', $allowed_venues_ids)
                    ->groupBy('venue_id')
                    ->get();
            } else {
                /**
                 * TAIL is not in today so we get hourly stats for TAIL
                 * - get the start of day for tail and get the hour of tail_start and tail_end
                 */
                $tail_start_hour = $this->tail_start->format('G');
                $tail_end_hour = $this->tail_end->format('G');

                /**
                 * prepare and execute the query
                 */
                $tailVisitors = new TrackingDailyStatsVenueHourlyVisitors;
                $initialresultsTailVisitors = $tailVisitors
                    ->with('venue')
                    ->selectRaw('venue_id, SUM(visitors_total) AS total, SUM(visitors_new) AS new')
                    ->where('day_epoch', $this->tail_day_epoch)
                    ->where('hour', '<', $tail_end_hour)
                    ->where('hour', '>=', $tail_start_hour)
                    ->whereIn('venue_id', $allowed_venues_ids)
                    ->groupBy('venue_id')
                    ->get();
            }
        }

        /**
         * merge the arrays form head/body/tail results into a single array ($tempresults)
         * indexed by the site_id
         */
        $tempresults = [];

        if($this->head_length > 0 && count($initialresultsHeadVisitors) > 0) {
            foreach ($initialresultsHeadVisitors as $item) {
                if(isset($tempresults[$item['venue_id']])) {
                    $tempresults[$item['venue_id']][0] += (int)$item['new'];
                    $tempresults[$item['venue_id']][1] += ((int)$item['total'] - (int)$item['new']);
                } else {
                    $tempresults[$item['venue_id']][0] = (int)$item['new'];
                    $tempresults[$item['venue_id']][1] = ((int)$item['total'] - (int)$item['new']);
                }

                $tempresults[$item['venue_id']][2] = $item['venue']->name;
            }
        }

        if($this->body_length > 0 && count($initialresultsBodyVisitors) > 0) {
            foreach ($initialresultsBodyVisitors as $item) {
                if(isset($tempresults[$item['venue_id']])) {
                    $tempresults[$item['venue_id']][0] += (int)$item['new`'];
                    $tempresults[$item['venue_id']][1] += ((int)$item['total'] - (int)$item['new']);
                } else {
                    $tempresults[$item['venue_id']][0] = (int)$item['new'];
                    $tempresults[$item['venue_id']][1] = ((int)$item['total'] - (int)$item['new']);
                }

                $tempresults[$item['venue_id']][2] = $item['venue']->name;
            }
        }

        if($this->tail_length > 0 && count($initialresultsTailVisitors) > 0) {
            foreach ($initialresultsTailVisitors as $item) {
                if(isset($item['name'])) {
                    if(isset($tempresults[$item['venue_id']])) {
                        $tempresults[$item['venue_id']][0] += (int)$item['new'];
                        $tempresults[$item['venue_id']][1] += ((int)$item['total'] - (int)$item['new']);
                    } else {
                        $tempresults[$item['venue_id']][0] = (int)$item['total'];
                        $tempresults[$item['venue_id']][1] = ((int)$item['total'] - (int)$item['new']);
                    }

                    $tempresults[$item['venue_id']][1] = $item['name'];
                } else {
                    if(isset($tempresults[$item['venue_id']])) {
                        $tempresults[$item['venue_id']][0] += (int)$item['new'];
                        $tempresults[$item['venue_id']][1] += ((int)$item['total'] - (int)$item['new']);
                    } else {
                        $tempresults[$item['venue_id']][0] = (int)$item['new'];
                        $tempresults[$item['venue_id']][1] = ((int)$item['total'] - (int)$item['new']);
                    }

                    $tempresults[$item['venue_id']][2] = $item['venue']->name;
                }
            }
        }

        /**
         * transform the temporary results to the format required
         */
        foreach ($tempresults as $tempresult) {
            $results[] = [$tempresult[2], $tempresult[1], $tempresult[0]];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listVenueStatsDeviceVendorDaily(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_device_vendor_report')) {
            throw new NotFoundException($request, $response);
        }

        $results = [];
        $end = floor($args['end']/1000);
        $start = floor($args['start']/1000);

        /**
         * filter on the user's primary_venue_id
         */
        $venue_filter = $currentUser->primary_venue_id;

        /**
         * user is allowed to view full venue stats
         * prepare and execute the query using a PDO connection provided
         */
        $deviceVendorQuery = new TrackingDailyStatsVenueUniqueDeviceUuids;
        $db = $deviceVendorQuery->getConnection()->getPdo();

        /**
         * execute the prepared statement with the vars available
         */
        $device_vendor_stats = $db->prepare('
            SELECT IF(LENGTH(device_vendor.description) > 0, device_vendor.description, device_vendor.name) AS vendor,
                   day_epoch,
                   temp.count AS count
            FROM
            (
                SELECT device_vendor_id,
                       day_epoch,
                       COUNT(DISTINCT(device_uuid)) AS count
                FROM tracking_daily_stats_venue_unique_device_uuids_per_hour
                PARTITION (p' . $venue_filter . ')
                WHERE device_vendor_id <> 0
                AND day_epoch + (hour * 3600) >= :start
                AND day_epoch + (hour * 3600) < :end
                GROUP BY CONCAT(device_vendor_id, "-", day_epoch)
                ORDER BY device_vendor_id DESC,
                day_epoch ASC
            ) AS temp
            LEFT JOIN device_vendor
            ON device_vendor.id = device_vendor_id'
        );

        /**
         * bind the parameters for the prepared statement
         */
        $device_vendor_stats->bindParam(':start', $start);
        $device_vendor_stats->bindParam(':end', $end);

        /**
         * execute the query
         */
        $device_vendor_stats->execute();
        $initialresults = $device_vendor_stats->fetchAll();

        foreach ($initialresults as $item) {
            $new_array = [$item['day_epoch'] * 1000, $item['count']];
            $results[$item['vendor']][] = $new_array;
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listVenueStatsDeviceVendorTotals(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_device_vendor_report')) {
            throw new NotFoundException($request, $response);
        }

        $results = [];
        $end = floor($args['end']/1000);
        $start = floor($args['start']/1000);
        $limit = 10;

        /**
         * filter on the user's primary_venue_id
         */
        $venue_filter = $currentUser->primary_venue_id;

        /**
         * user is allowed to view full venue stats
         * prepare and execute the query using a PDO connection provided
         */
        $deviceVendorQuery = new TrackingDailyStatsVenueUniqueDeviceUuids;
        $db = $deviceVendorQuery->getConnection()->getPdo();

        /**
         * execute the prepared statement with the vars available
         */
        $device_vendor_stats = $db->prepare('
            SELECT IF(LENGTH(device_vendor.description) > 0, device_vendor.description, device_vendor.name) AS vendor,
                   temp.count
            FROM
            (
                SELECT device_vendor_id,
                       COUNT(DISTINCT(device_uuid)) AS count
                FROM tracking_daily_stats_venue_unique_device_uuids_per_hour
                PARTITION (p' . $venue_filter . ')
                WHERE device_vendor_id <> 0
                AND day_epoch + (hour * 3600) >= :start
                AND day_epoch + (hour * 3600) < :end
                GROUP BY device_vendor_id
                ORDER BY count DESC
                LIMIT :limit
            ) AS temp
            LEFT JOIN device_vendor
            ON device_vendor.id = device_vendor_id'
        );

        /**
         * bind the parameters for the prepared statement
         */
        $device_vendor_stats->bindParam(':start', $start);
        $device_vendor_stats->bindParam(':end', $end);
        $device_vendor_stats->bindParam(':limit', $limit);

        /**
         * execute the query
         */
        $device_vendor_stats->execute();
        $initialresults = $device_vendor_stats->fetchAll();

        foreach ($initialresults as $item) {
            $results[] = [
                'name' => $item['vendor'],
                'y' => $item['count']
            ];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listEventFootfallCount(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_event_info')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * fetch the current venue and assign values to several variables for later use
         */
        $venueQuery = new Venue;
        $current_venue = $venueQuery->with('venue_tracking')->where('id', $currentUser->primary_venue_id)->first();
        $tag_filter = $current_venue->venue_tracking->event_info_zone_tag;
        $venue_filter = $currentUser->primary_venue_id;
        $event_info_bucket = $current_venue->venue_tracking->event_info_bucket;
        $end = Carbon::now()->timestamp;
        $start = $end - $event_info_bucket;
        $results = [];

        /**
         * prepare and execute the query
         */
        $footfallQuery = new ProbeRequest;
        $finalQuery = $footfallQuery->selectRaw('DISTINCT(device_uuid)')
            ->where('venue_id', 4)
            ->whereBetween('ts', [(int)$start, ((int)$end + 1)]);

        if (!empty($tag_filter)) {
            /**
             * then we select only drones which belong to zones with the selected tag,
             * if no tag is provided we select all drones
             */
            $selected_tag = Tag::where('id', $tag_filter)
                ->with('zones', 'zones.drones')
                ->first();

            $drone_filter = [];

            foreach ($selected_tag->zones as $zone) {
                if ($zone->venue_id == $currentUser->primary_venue_id) {
                    foreach ($zone->drones as $drone) {
                        if ($drone->state == 1) {
                            $drone_filter[] = $drone->id;
                        }
                    }
                }
            }

            /**
             * apply the drone filter to the initial query
             */
            $finalQuery->whereIn('drone_id', $drone_filter);
        }

        /**
         * prepare and execute the query
         */
        $results = $finalQuery->get()->count();

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listEventFootfallSeries(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_event_info')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * define the starting point of today
         */
        $timezone = $currentUser->primaryVenue->time_zone;
        $starttoday = Carbon::now($timezone)->startOfDay()->format('U');

        /**
         * filter on the user's primary_venue_id
         */
        $venue_filter = $currentUser->primary_venue_id;

        /**
         * fetch the current venue and initialise several other variables
         */
        $venueQuery = new Venue;
        $current_venue = $venueQuery->with('venue_tracking')->where('id', $currentUser->primary_venue_id)->first();
        $event_info_bucket = $current_venue->venue_tracking->event_info_bucket;
        $tag_filter = $current_venue->venue_tracking->event_info_zone_tag;
        $drone_filter = [];
        $results = [];

        if ($tag_filter != 0) {
            /**
             * then we select only drones which belong to zones with the selected tag,
             * if no tag is provided we select all drones
             */
            $selected_tag = Tag::where('id', $tag_filter)
                ->with('zones', 'zones.drones')
                ->first();

            foreach ($selected_tag->zones as $zone) {
                foreach ($zone->drones as $drone) {
                    if ($drone->state == 1) {
                        $drone_filter[] = $drone->id;
                    }
                }
            }
        }

        /**
         * initialise the query and get the PDO connection
         */
        $classInstance = new ProbeRequest;
        $db = $classInstance->getConnection()->getPdo();

        if (empty($drone_filter)) {
            /**
             * execute the prepared statement with the vars available for total visitors today
             */
            $footfallQuery = $db->prepare(
                'SELECT temp.unix_ts * 1000 AS unix_ts,
                        COUNT(DISTINCT(probe_request.device_uuid)) AS unique_users
                   FROM (
                         SELECT (probe_request.ts - MOD(probe_request.ts,900)) AS unix_ts
                           FROM probe_request
                          WHERE probe_request.venue_id = :venue_id_1
                            AND probe_request.ts > :start_1
                       GROUP BY unix_ts
                        ) AS temp
                   JOIN probe_request AS probe_request
                     ON probe_request.ts BETWEEN temp.unix_ts - :event_info_bucket and (temp.unix_ts + 1)
                    AND probe_request.venue_id = :venue_id_2
                  WHERE unix_ts > :start_2
               GROUP BY unix_ts'
            );

            /**
             * bind the parameters for the prepared statement
             */
            $footfallQuery->bindParam(':start_1', $starttoday);
            $footfallQuery->bindParam(':start_2', $starttoday);
            $footfallQuery->bindParam(':venue_id_1', $venue_filter);
            $footfallQuery->bindParam(':venue_id_2', $venue_filter);
            $footfallQuery->bindParam(':event_info_bucket', $event_info_bucket);
        } else {
            /**
             * create a string containing drone ids, comma seperated, to insert into the PDO statement
             */
            $ids_string = implode(',', $drone_filter);

            /**
             * execute the prepared statement with the vars available for total visitors today
             */
            $footfallQuery = $db->prepare(
                'SELECT temp.unix_ts * 1000 AS unix_ts,
                        COUNT(DISTINCT(probe_request.device_uuid)) AS unique_users
                   FROM (
                         SELECT (probe_request.ts - MOD(probe_request.ts,900)) AS unix_ts
                           FROM probe_request
                          WHERE probe_request.venue_id = :venue_id_1
                            AND probe_request.ts >  :start_1
                       GROUP BY unix_ts
                        ) AS temp
                   JOIN probe_request AS probe_request
                     ON probe_request.ts BETWEEN temp.unix_ts - :event_info_bucket and temp.unix_ts
                    AND probe_request.venue_id = :venue_id_2
                    AND drone_id IN (' . $ids_string . ')
                  WHERE unix_ts > :start_2
               GROUP BY unix_ts'
            );

            /**
             * bind the parameters for the prepared statement
             */
            $footfallQuery->bindParam(':start_1', $starttoday);
            $footfallQuery->bindParam(':start_2', $starttoday);
            $footfallQuery->bindParam(':venue_id_1', $venue_filter);
            $footfallQuery->bindParam(':venue_id_2', $venue_filter);
            $footfallQuery->bindParam(':event_info_bucket', $event_info_bucket);
        }

        /**
         * execute the query
         */
        $footfallQuery->execute();
        $footfallResults = $footfallQuery->fetchAll();

        /**
         * prepare the results for use in Highcharts
         */
        foreach ($footfallResults as $object) {
            $results[] = ['x' => $object['unix_ts'], 'y' => $object['unique_users']];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listNationalStatsAverageDwelltime(Request $request, Response $response, $args)
    {
        $post = $request->getParsedBody();

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_comparison_report')) {
            throw new NotFoundException($request, $response);
        }

        // Get the alert message stream
        $ms = $this->ci->alerts;

        /**
         * then get an array containing venue ids that passed the filters
         */
        $allowed_zone_ids = $this->getFilteredZoneIds($post);

        $results = 0;
        $end = floor($args['end']/1000);
        $start = floor($args['start']/1000);

        error_log('start: ' . $start . ' end: ' . $end);

        /**
         * query the 'daily_stats_zone_visitor_dwelltime' table using DailyStatsZoneDwelltime class
         */
        $dwelltimeQuery = new TrackingDailyStatsZoneDwelltime;
        $averageDwelltime = $dwelltimeQuery->where('day_epoch', '>=', $start)
            ->where('day_epoch', '<', $end)
            ->whereIn('zone_id', $allowed_zone_ids)
            ->avg('dt_average');

        $results = round($averageDwelltime/60);

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listNationalStatsVisitorsTimeOfDay(Request $request, Response $response, $args)
    {
        $post = $request->getParsedBody();

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_comparison_report')) {
            throw new NotFoundException($request, $response);
        }

        // Get the alert message stream
        $ms = $this->ci->alerts;

        /**
         * then get an array containing venue ids that passed the filters
         */
        $allowed_zone_ids = $this->getFilteredZoneIds($post);

        /**
         * number of venues we're looking at
         */
        $zone_count = count($allowed_zone_ids);

        /**
         * get the current user venue's timezone
         */
        $timezone = $currentUser->primaryVenue->time_zone;

        /**
         * initialise several variables
         */
        $results = [];

        /**
         * WORK ON SELECTED RANGE FROM HERE
         * - determine start and end
         * - determine head/body/tail
         */
        $start = floor($args['start']/1000);
        $end   = floor($args['end']/1000);
        $this->determineHeadBodyTail($start, $end, $timezone);

        /**
         * execute query for HEAD of selected range
         */
        if($this->head_length > 0) {
            /**
             * get the start of day for head and get the hour of head_start and head_end
             */
            $head_start_hour = $this->head_start->format('G');
            $head_end_hour   = $this->head_end->subSecond()->format('G');

            /**
             * prepare and execute the query
             */
            $headVisitors = new TrackingDailyStatsZoneHourlyVisitors;
            $initialresultsHeadVisitors = $headVisitors->selectRaw('hour, SUM(visitors_total) AS visitors')
                ->where('day_epoch', '=', $this->head_day_epoch)
                ->where('hour', '<=', $head_end_hour)
                ->where('hour', '>=', $head_start_hour)
                ->whereIn('zone_id', $allowed_zone_ids)
                ->groupBy('hour')
                ->get();
        } else {
            /**
             * we do nothing and return a zero value
             */
            $initialresultsHeadVisitors = 0;
        }

        /**
         * execute query for BODY of selected range
         */
        if($this->body_length > 0) {
            /**
             * get body_start and body_end formatted as epoch
             */
            $body_start = $this->body_start->format('U');
            $body_end   = $this->body_end->format('U');

            /**
             * user is allowed to view full venue stats
             * prepare and execute the query for BODY
             */
            $bodyVisitors = new TrackingDailyStatsZoneHourlyVisitors;
            $initialresultsBodyVisitors = $bodyVisitors->selectRaw('hour, SUM(visitors_total) AS visitors')
                ->where('day_epoch', '>=', $body_start)
                ->where('day_epoch', '<', $body_end)
                ->whereIn('zone_id', $allowed_zone_ids)
                ->orderBy('hour', 'asc')
                ->groupBy('hour')
                ->get();
        } else {
            /**
             * we do nothing and return zero values for new and repeat
             */
            $initialresultsBodyVisitors = 0;
        }

        /**
         * execute query for TAIL of selected range
         */
        if($this->tail_length > 0) {
            /**
             * we first determine whether TAIL is in today or not
             */
            if($this->tail_in_today) {
                /**
                 * NOTE:
                 * tail should never be in today
                 */
                error_log('tail was in today which should NOT happen!');
            } else {
                /**
                 * TAIL is not in today so we get hourly stats for TAIL
                 * - get the start of day for tail and get the hour of tail_start and tail_end
                 */
                $tail_start_hour = $this->tail_start->format('G');
                $tail_end_hour = $this->tail_end->format('G');

                /**
                 * prepare and execute the query
                 */
                $tailVisitors = new TrackingDailyStatsZoneHourlyVisitors;
                $initialresultsTailVisitors = $tailVisitors->selectRaw('hour, SUM(visitors_total) AS visitors')
                    ->where('day_epoch', '=', $this->tail_day_epoch)
                    ->where('hour', '<', $tail_end_hour)
                    ->where('hour', '>=', $tail_start_hour)
                    ->whereIn('zone_id', $allowed_zone_ids)
                    ->groupBy('hour')
                    ->get();
            }
        } else {
            /**
             * we do nothing and return zero value
             */
            $initialresultsTailVisitors = 0;
        }

        /**
         * merge the head/body/tail arrays, all containing hour/visitors data
         */
        $tempresults = [];

        /**
         * while merging the arrays we also keep count of the number of data points we have collected for each hour so that
         * we're able to calculate an accurate average visitor count per hour
         *
         * NOTE:
         * we might determine that this way of calculating averages is too rough (works better with higher venue counts)
         * so we may need to revisit this later depending on the results from the live server
         */
        if($this->head_length > 0) {
            /**
             * loop through the array and add to tempresults
             */
            foreach ($initialresultsHeadVisitors as $item) {
                if(isset($tempresults[$item['hour']])) {
                    $tempresults[$item['hour']][0] += (int)$item['visitors']/$zone_count;
                    $tempresults[$item['hour']][1] += 1;
                }else {
                    $tempresults[$item['hour']][0] = (int)$item['visitors']/$zone_count;
                    $tempresults[$item['hour']][1] = 1;
                }
            }
        }

        if($this->body_length > 0) {
            /**
             * loop through the array and add to tempresults
             * (in the body, the number of data points is the same as $body_length)
             */
            foreach ($initialresultsBodyVisitors as $item) {
                if(isset($tempresults[$item['hour']])) {
                    $tempresults[$item['hour']][0] += (int)$item['visitors']/$zone_count;
                    $tempresults[$item['hour']][1] += (int)$this->body_length;
                }else {
                    $tempresults[$item['hour']][0] = (int)$item['visitors']/$zone_count;
                    $tempresults[$item['hour']][1] = (int)$this->body_length;
                }
            }
        }

        if($this->tail_length > 0) {
            /**
             * loop through the array and add to tempresults
             */
            foreach ($initialresultsTailVisitors as $item) {
                if(isset($tempresults[$item['hour']])) {
                    $tempresults[$item['hour']][0] += (int)$item['visitors']/$zone_count;
                    $tempresults[$item['hour']][1] += 1;
                }else {
                    $tempresults[$item['hour']][0] = (int)$item['visitors']/$zone_count;
                    $tempresults[$item['hour']][1] = 1;
                }
            }
        }

        /**
         * process the results then sort the results by hour
         */
        foreach($tempresults as $key => $value) {
            $results[] = [$key, round($value[0]/$value[1])];
        }

        $results = array_values(array_sort($results, function ($value) {
            return $value[0];
        }));

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listNationalStatsVisitorDurations(Request $request, Response $response, $args)
    {
        $post = $request->getParsedBody();

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_comparison_report')) {
            throw new NotFoundException($request, $response);
        }

        // Get the alert message stream
        $ms = $this->ci->alerts;

        /**
         * then get an array containing venue ids that passed the filters
         */
        $allowed_zone_ids = $this->getFilteredZoneIds($post);

        /**
         * number of venues we're looking at
         */
        $zone_count = count($allowed_zone_ids);

        $results = [
            'dt_average' => []
        ];

        $start = floor($args['start']/1000);
        $end = floor($args['end']/1000);

        /**
         * prepare and execute the query
         *
         * TODO:
         * determine how to calculate the correct average value here
         */
        $thisWeekDwelltimes = new TrackingDailyStatsZoneDwelltime;
        $initialresultsDurations = $thisWeekDwelltimes->selectRaw('day_epoch, dt_average')
            ->where('day_epoch', '>=', $start)
            ->where('day_epoch', '<', $end)
            ->whereIn('zone_id', $allowed_zone_ids)
            ->orderBy('day_epoch', 'asc')
            ->get();

        /**
         * process the initial results
         * - group by timestamp
         * - then cycle through the values for each venue
         * - calculate the overall average dwelltime for that timestamp
         * - push the final object to $results
         */
        $collection = collect($initialresultsDurations);
        $unique_values = $collection->groupBy('day_epoch');
        foreach ($unique_values as $key => $value) {
            if (count($value) > 0) {
                $row_count    = 0;
                $sum_averages = 0;

                foreach ($value as $row) {
                    $sum_averages += $row->dt_average;
                    $row_count++;
                }

                $results['dt_average'][] = [
                    $key*1000,
                    round(($sum_averages/$row_count)/60)
                ];
            }
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listNationalStatsNewVsRepeat(Request $request, Response $response, $args)
    {
        $post = $request->getParsedBody();

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_comparison_report')) {
            throw new NotFoundException($request, $response);
        }

        // Get the alert message stream
        $ms = $this->ci->alerts;

        /**
         * then get an array containing venue ids that passed the filters
         */
        $allowed_zone_ids = $this->getFilteredZoneIds($post);

        /**
         * create a string containing venue ids, comma seperated, to insert into the PDO statement
         */
        $ids_string = implode(',', $allowed_zone_ids);

        /**
         * number of venues we're looking at
         */
        $zone_count = count($allowed_zone_ids);

        /**
         * filter on the user's primary_venue_id and get the user's timezone
         */
        $timezone = $currentUser->primaryVenue->time_zone;

        /**
         * initialise several variables
         */
        $results = [
            'new' => [],
            'repeat' => []
        ];

        /*************************************************************************************
         * WORK ON SELECTED RANGE FROM HERE
         * - get the desired time zone
         * - set $start, $end
         *
         * TODO:
         * - determine full days within the body
         * - get daily stats for it
         * - determine whether tail is today or not
         *  - yes: we get data from probe_requests
         *  - no: we get hourly stats for it
         * - determine head and get hourly stats for it
         * - using Carbon to copy timestamps you need to use the copy function!!!
         *************************************************************************************/
        $start = floor($args['start']/1000);
        $end = floor($args['end']/1000);
        $this->determineHeadBodyTail($start, $end, $timezone);

        /**
         * execute query for HEAD of selected range
         */
        if($this->head_length > 0) {
            /**
             * get the start of day for head and get the hour of head_start and head_end
             */
            $head_start_hour = $this->head_start->format('G');
            $head_end_hour = $this->head_end->subSecond()->format('G');

            /**
             * prepare and execute the query
             */
            $headVisitors = new TrackingDailyStatsZoneHourlyVisitors;
            $initialresultsHeadVisitors = $headVisitors->selectRaw('day_epoch, SUM(visitors_new) AS new, SUM(visitors) AS total')
                ->where('day_epoch', '=', $this->head_day_epoch)
                ->where('hour', '<=', $head_end_hour)
                ->where('hour', '>=', $head_start_hour)
                ->whereIn('zone_id', $allowed_zone_ids)
                ->groupBy('day_epoch')
                ->get();

            foreach($initialresultsHeadVisitors as $day) {
                $results['new'][] = ['timestamp' => $day['day_epoch']*1000, 'data' => round((int)$day['new'])];
                $results['repeat'][] = ['timestamp' => $day['day_epoch']*1000, 'data' => round((int)$day['total'] - (int)$day['new'])];
            }
        }

        /**
         * execute query for BODY of selected range
         */
        if($this->body_length > 0) {
            /**
             * get body_start and body_end formatted as epoch
             */
            $body_start = $this->body_start->format('U');
            $body_end = $this->body_end->format('U');

            /**
             * uprepare and execute the query for the daily stats for BODY
             */
            $bodyVisitors = new TrackingDailyStatsZoneVisitors;
            $initialresultsbodyVisitors = $bodyVisitors->select('day_epoch', 'visitors_new', 'visitors_total')
                ->where('day_epoch', '>=', $body_start)
                ->where('day_epoch', '<', $body_end)
                ->whereIn('zone_id', $allowed_zone_ids)
                ->orderBy('day_epoch', 'asc')
                ->get();

            foreach($initialresultsbodyVisitors as $day) {
                $results['new'][] = ['timestamp' => $day['day_epoch']*1000, 'data' => round($day['visitors_new'])];
                $results['repeat'][] = ['timestamp' => $day['day_epoch']*1000, 'data' => round($day['visitors_total'] - $day['visitors_new'])];
            }
        }

        /**
         * execute query for TAIL of selected range
         */
        if($this->tail_length > 0) {
            /**
             * we first determine whether TAIL is in today or not
             */
            if($this->tail_in_today) {
                /**
                 * tail should never be in today
                 */
                error_log('tail was in today which should NOT happen!');
            } else {
                /**
                 * TAIL is not in today so we get hourly stats for TAIL
                 * - get the start of day for tail and get the hour of tail_start and tail_end
                 */
                $tail_start_hour = $this->tail_start->format('G');
                $tail_end_hour = $this->tail_end->format('G');

                /**
                 * prepare and execute the query
                 */
                $tailVisitors = new TrackingDailyStatsZoneHourlyVisitors;
                $initialresultsTailVisitors = $tailVisitors->selectRaw('day_epoch, SUM(visitors_new) AS new, SUM(visitors_total) AS total')
                    ->where('day_epoch', '=', $this->tail_day_epoch)
                    ->where('hour', '<', $tail_end_hour)
                    ->where('hour', '>=', $tail_start_hour)
                    ->whereIn('zone_id', $allowed_zone_ids)
                    ->groupBy('day_epoch')
                    ->get();

                foreach($initialresultsTailVisitors as $day) {
                    $results['new'][] = ['timestamp' => $day['day_epoch']*1000, 'data' => round((int)$day['new']/$zone_count)];
                    $results['repeat'][] = ['timestamp' => $day['day_epoch']*1000, 'data' => round(((int)$day['total'] - (int)$day['new'])/$zone_count)];
                }
            }
        }

        /**
         * fix overall average calculation and remove duplicate objects for the same timestamp from final results
         */
        $raw_results_new_collection = collect($results['new']);
        $unique_new_values = $raw_results_new_collection->groupBy('timestamp');

        $raw_results_repeat_collection = collect($results['repeat']);
        $unique_repeat_values = $raw_results_repeat_collection->groupBy('timestamp');

        /**
         * reset $results array to free up memory
         */
        $results = [
            'new' => [],
            'repeat' => []
        ];

        /**
         * process our intermediate results for new and repeat visitors to determine the overall averages
         * and push them to the final $results array
         */
        foreach ($unique_new_values as $key => $value) {
            if (count($value) > 0) {
                $row_count    = 0;
                $sum_averages = 0;

                foreach ($value as $row) {
                    $sum_averages += $row['data'];
                    $row_count++;
                }

                $results['new'][] = [
                    $key,
                    round($sum_averages/$row_count)
                ];
            }
        }

        foreach ($unique_repeat_values as $key => $value) {
            if (count($value) > 0) {
                $row_count    = 0;
                $sum_averages = 0;

                foreach ($value as $row) {
                    $sum_averages += $row['data'];
                    $row_count++;
                }

                $results['repeat'][] = [
                    $key,
                    round($sum_averages/$row_count)
                ];
            }
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listVenueStatsUniqueVisitorsCurrentWeekComparedInclPrevYear(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $results = [];

        /*************************************************************************************
         * CURRENT WEEK FROM HERE
         * get the desired time zone
         * set $start, $end_temp (end of the last full day) and $end
         *************************************************************************************/
        $start = floor($args['start']/1000);
        $end = floor($args['end']/1000);
        $timezone = $currentUser->primaryVenue->time_zone;
        $now = Carbon::now($timezone)->format('U');
        $starttoday = Carbon::now($timezone)->startOfDay()->format('U');
        $end_temp = ($starttoday - 1);

        /**
         * filter on the user's primary_venue_id
         */
        $venue_filter = $currentUser->primary_venue_id;

        /**
         * check whether the user is allowed to view full venue stats or not
         * and act accordingly
         */
        if ($currentUser->full_venue_view_allowed == 1) {
            /**
             * user is allowed to view full venue stats
             * prepare and execute the query for the daily stats
             */
            $thisWeekVisitors = new TrackingDailyStatsVenueVisitors;
            $initialresultsThisWeekVisitors = $thisWeekVisitors->selectRaw('SUM(visitors_new) AS visitors_new, SUM(visitors_total) AS visitors_total')
                ->where('day_epoch', '>=', $start)
                ->where('day_epoch', '<', $end_temp)
                ->where('venue_id', $venue_filter)
                ->get();
        } else {
            /**
             * user is NOT allowed to view full venue stats
             * filter on the user's allowed zones AND on the user's primary_venue_id
             *
             * get the allowed zones, then create an array which only contains the ids
             */
            $allowed_zones = collect($currentUser->zones);
            $allowed_zones_ids = [];
            foreach ($allowed_zones as $allowed_zone) {
                $allowed_zones_ids[] = $allowed_zone->id;
            }

            /**
             * prepare and execute the query for the daily stats
             */
            $thisWeekVisitors = new TrackingDailyStatsZoneVisitors;
            $initialresultsThisWeekVisitors = $thisWeekVisitors->selectRaw('SUM(visitors_new) AS visitors_new, SUM(visitors_total) AS visitors_total')
                ->where('day_epoch', '>=', $start)
                ->where('day_epoch', '<', $end_temp)
                ->where('venue_id', $venue_filter)
                ->whereIn('zone_id', $allowed_zones_ids)
                ->get();
        }

        /**
         * get the new/repeat visitors for today to add to the previous results
         * prepare and execute the query using a PDO connection provided
         */
        $returningVisitorsQuery = new ProbeRequest;
        $db = $returningVisitorsQuery->getConnection()->getPdo();

        /**
         * check whether the user is allowed to view full venue stats or not
         * and act accordingly
         */
        if ($currentUser->full_venue_view_allowed == 1) {
            /**
             * user is allowed to view full venue stats
             * execute the prepared statement with the vars available for returning visitors today
             */
            $returningVisitors = $db->prepare('
                SELECT COUNT(DISTINCT probe_request.device_uuid) AS `returning`
                FROM probe_request
                PARTITION (p' . $venue_filter . ')
                INNER JOIN tracking_daily_stats_venue_device_uuid_cache AS repeat_visitors
                    ON probe_request.device_uuid = repeat_visitors.device_uuid
                WHERE repeat_visitors.first_seen < :start_1
                AND ts > :start_2'
            );

        } else {
            /**
             * user is NOT allowed to view full venue stats
             * filter on the user's allowed zones AND on the user's primary_venue_id
             *
             * get the allowed zones, then create an array which only contains the ids
             */
            $allowed_zones = collect($currentUser->zones);
            $allowed_drones_ids = [];
            foreach ($allowed_zones as $allowed_zone) {
                foreach ($allowed_zone->drones as $drone) {
                    $allowed_drones_ids[] = $drone->id;
                }
            }

            /**
             * create a string containing drone ids, comma seperated, to insert into the PDO statement
             */
            $ids_string = implode(',', $allowed_drones_ids);

            /**
             * execute the prepared statement with the vars available for returning visitors today
             */
            $returningVisitors = $db->prepare('
                SELECT COUNT(DISTINCT probe_request.device_uuid) AS `returning`
                FROM probe_request
                INNER JOIN daily_stats_venue_mac_cache AS repeat_visitors
                    ON probe_request.device_uuid = repeat_visitors.device_uuid
                WHERE tracking_repeat_visitors.first_seen < :start_1
                AND ts > :start_2
                AND venue_id = :venue_id
                AND drone_id IN (' . $ids_string . ')'
            );

            $returningVisitors->bindParam(':venue_id', $venue_filter);
        }

        /**
         * bind the parameters for the prepared statement
         * in this case: with PDO prepared statements we couldn't use the same parameter twice...
         */
        $returningVisitors->bindParam(':start_1', $starttoday);
        $returningVisitors->bindParam(':start_2', $starttoday);

        /**
         * execute the query
         */
        $returningVisitors->execute();
        $returningVisitorsToday = $returningVisitors->fetch();

        /**
         * check whether the user is allowed to view full venue stats or not
         * and act accordingly
         */
        if ($currentUser->full_venue_view_allowed == 1) {
            /**
             * user is allowed to view full venue stats
             * execute the prepared statement with the vars available for total visitors today
             */
            $totalVisitors = $db->prepare('
                SELECT COUNT(DISTINCT device_uuid) AS total
                FROM probe_request
                PARTITION (p' . $venue_filter . ')
                WHERE ts > :start'
            );
        } else {
            /**
             * user is NOT allowed to view full venue stats
             * filter on the user's allowed zones AND on the user's primary_venue_id
             *
             * get the allowed zones, then create an array which only contains the ids
             */
            $allowed_zones = collect($currentUser->zones);
            $allowed_drones_ids = [];
            foreach ($allowed_zones as $allowed_zone) {
                foreach ($allowed_zone->drones as $drone) {
                    $allowed_drones_ids[] = $drone->id;
                }
            }

            /**
             * create a string containing drone ids, comma seperated, to insert into the PDO statement
             */
            $ids_string = implode(',', $allowed_drones_ids);

            /**
             * execute the prepared statement with the vars available for total visitors today
             */
            $totalVisitors = $db->prepare('
                SELECT COUNT(DISTINCT device_uuid) AS total
                FROM probe_request
                WHERE ts > :start
                AND venue_id = :venue_id
                AND drone_id IN (' . $ids_string . ')'
            );

            $totalVisitors->bindParam(':venue_id', $venue_filter);
        }

        /**
         * bind the parameters for the prepared statement
         */
        $totalVisitors->bindParam(':start', $starttoday);

        /**
         * execute the query
         */
        $totalVisitors->execute();
        $totalVisitorsToday = $totalVisitors->fetch();
        $repeatVisitorsToday = $returningVisitorsToday['returning'];
        $newVisitorsToday = $totalVisitorsToday['total'] - $returningVisitorsToday['returning'];

        /*************************************************************************************
         * PREVIOUS WEEK FROM HERE
         * set $start, $end_temp (end of the last full day) and $end
         * TODO: add the remaining hourly stats
         * get the unix datetime for the partial day (today - 7 days)
         * get the current hour
         * query on day_epoch and hour <= current hour
         * filter on venue_filter
         *************************************************************************************/
        $prev_week_start = $start - (7*24*60*60);
        $prev_week_end_temp = $end_temp - (7*24*60*60);
        $prev_week_end = $end - (7*24*60*60);
        $current_hour = Carbon::now($timezone)->hour;
        $today_1_week_ago = Carbon::now($timezone)->subWeek()->startOfDay()->format('U');

        /**
         * check whether the user is allowed to view full venue stats or not
         * and act accordingly
         */
        if ($currentUser->full_venue_view_allowed == 1) {
            /**
             * user is allowed to view full venue stats
             * prepare and execute the query for the daily stats
             */
            $prevWeekVisitors = new TrackingDailyStatsVenueVisitors;
            $initialresultsPrevWeekVisitors = $prevWeekVisitors->selectRaw('SUM(visitors_new) AS visitors_new, SUM(visitors_total) AS visitors_total')
                ->where('day_epoch', '>=', $prev_week_start)
                ->where('day_epoch', '<', $prev_week_end_temp)
                ->where('venue_id', $venue_filter)
                ->get();

            /**
             * prepare and execute the query for the remaining hourly stats
             */
            $prevWeekVisitorsHourly = new TrackingDailyStatsVenueHourlyVisitors;
            $initialresultsPrevWeekVisitorsHourly = $prevWeekVisitorsHourly->selectRaw('SUM(visitors_new) AS visitors_new, SUM(visitors_total) AS visitors_total')
                ->where('day_epoch', '=', $today_1_week_ago)
                ->where('hour', '<=', $current_hour)
                ->where('venue_id', $venue_filter)
                ->get();
        } else {
            /**
             * user is NOT allowed to view full venue stats
             * filter on the user's allowed zones AND on the user's primary_venue_id
             *
             * get the allowed zones, then create an array which only contains the ids
             */
            $allowed_zones = collect($currentUser->zones);
            $allowed_zones_ids = [];
            foreach ($allowed_zones as $allowed_zone) {
                $allowed_zones_ids[] = $allowed_zone->id;
            }

            /**
             * prepare and execute the query for the daily stats
             */
            $prevWeekVisitors = new TrackingDailyStatsZoneVisitors;
            $initialresultsPrevWeekVisitors = $prevWeekVisitors->selectRaw('SUM(visitors_new) AS visitors_new, SUM(visitors_total) AS visitors_total')
                ->where('day_epoch', '>=', $prev_week_start)
                ->where('day_epoch', '<', $prev_week_end_temp)
                ->where('venue_id', $venue_filter)
                ->whereIn('zone_id', $allowed_zones_ids)
                ->get();

            /**
             * prepare and execute the query for the remaining hourly stats
             */
            $prevWeekVisitorsHourly = new TrackingDailyStatsZoneHourlyVisitors;
            $initialresultsPrevWeekVisitorsHourly = $prevWeekVisitorsHourly->selectRaw('SUM(visitors_new) AS visitors_new, SUM(visitors_total) AS visitors_total')
                ->where('day_epoch', '=', $today_1_week_ago)
                ->where('hour', '<=', $current_hour)
                ->where('venue_id', $venue_filter)
                ->whereIn('zone_id', $allowed_zones_ids)
                ->get();
        }

        /*************************************************************************************
         * PREVIOUS YEAR, SAME WEEK, FROM HERE
         * set $start, $end_temp (end of the last full day) and $end
         * TODO: add the remaining hourly stats
         *************************************************************************************/
        $prev_year_start = $start - (52*7*24*60*60);
        $prev_year_end_temp = $end_temp - (52*7*24*60*60);
        $prev_year_end = $end - (52*7*24*60*60);
        $current_hour = Carbon::now($timezone)->hour;
        $today_1_year_ago = Carbon::now($timezone)->subYear()->startOfDay()->format('U');

        /**
         * check whether the user is allowed to view full venue stats or not
         * and act accordingly
         */
        if ($currentUser->full_venue_view_allowed == 1) {
            /**
             * user is allowed to view full venue stats
             * prepare and execute the query for the daily stats
             */
            $prevYearVisitors = new TrackingDailyStatsVenueVisitors;
            $initialresultsPrevYearVisitors = $prevYearVisitors->selectRaw('SUM(visitors_new) AS visitors_new, SUM(visitors_total) AS visitors_total')
                ->where('day_epoch', '>=', $prev_year_start)
                ->where('day_epoch', '<', $prev_year_end_temp)
                ->where('venue_id', $venue_filter)
                ->get();

            /**
             * prepare and execute the query for the remaining hourly stats
             */
            $prevYearVisitorsHourly = new TrackingDailyStatsVenueHourlyVisitors;
            $initialresultsPrevYearVisitorsHourly = $prevYearVisitorsHourly->selectRaw('SUM(visitors_new) AS visitors_new, SUM(visitors_total) AS visitors_total')
                ->where('day_epoch', '=', $today_1_year_ago)
                ->where('hour', '<=', $current_hour)
                ->where('venue_id', $venue_filter)
                ->get();
        } else {
            /**
             * user is NOT allowed to view full venue stats
             * filter on the user's allowed zones AND on the user's primary_venue_id
             *
             * get the allowed zones, then create an array which only contains the ids
             */
            $allowed_zones = collect($currentUser->zones);
            $allowed_zones_ids = [];
            foreach ($allowed_zones as $allowed_zone) {
                $allowed_zones_ids[] = $allowed_zone->id;
            }

            /**
             * prepare and execute the query for the daily stats
             */
            $prevYearVisitors = new TrackingDailyStatsZoneVisitors;
            $initialresultsPrevYearVisitors = $prevYearVisitors->selectRaw('SUM(visitors_new) AS visitors_new, SUM(visitors_total) AS visitors_total')
                ->where('day_epoch', '>=', $prev_year_start)
                ->where('day_epoch', '<', $prev_year_end_temp)
                ->where('venue_id', $venue_filter)
                ->whereIn('zone_id', $allowed_zones_ids)
                ->get();

            /**
             * prepare and execute the query for the remaining hourly stats
             */
            $prevYearVisitorsHourly = new TrackingDailyStatsZoneHourlyVisitors;
            $initialresultsPrevYearVisitorsHourly = $prevYearVisitorsHourly->selectRaw('SUM(visitors_new) AS visitors_new, SUM(visitors_total) AS visitors_total')
                ->where('day_epoch', '=', $today_1_year_ago)
                ->where('hour', '<=', $current_hour)
                ->where('venue_id', $venue_filter)
                ->whereIn('zone_id', $allowed_zones_ids)
                ->get();
        }

        /*************************************************************************************
         * process the results from all three periods and merge into a single array
         * TODO:
         * error_log('visitors new today: ' . $newVisitorsToday . ' repeat today: ' . $repeatVisitorsToday);
         *************************************************************************************/
        $results['new'] = [
            (int)$initialresultsPrevYearVisitors[0]['visitors_new'] + $initialresultsPrevYearVisitorsHourly[0]['visitors_new'],
            (int)$initialresultsPrevWeekVisitors[0]['visitors_new'] + $initialresultsPrevWeekVisitorsHourly[0]['visitors_new'],
            (int)$initialresultsThisWeekVisitors[0]['visitors_new'] + $newVisitorsToday
        ];

        $results['repeat'] = [
            (int)$initialresultsPrevYearVisitors[0]['visitors_total'] - (int)$initialresultsPrevYearVisitors[0]['visitors_new'] + $initialresultsPrevYearVisitorsHourly[0]['visitors_total'] - $initialresultsPrevYearVisitorsHourly[0]['visitors_new'],
            (int)$initialresultsPrevWeekVisitors[0]['visitors_total'] - (int)$initialresultsPrevWeekVisitors[0]['visitors_new'] + $initialresultsPrevWeekVisitorsHourly[0]['visitors_total'] -  $initialresultsPrevWeekVisitorsHourly[0]['visitors_new'],
            (int)$initialresultsThisWeekVisitors[0]['visitors_total'] - (int)$initialresultsThisWeekVisitors[0]['visitors_new'] + $repeatVisitorsToday
        ];

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listVenueStatsUniqueVisitorsCurrentMonthComparedInclPrevYear(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $results = [];

        /*************************************************************************************
         * CURRENT MONTH FROM HERE
         * get the desired time zone
         * set $start, $end_temp (end of the last full day) and $end
         *************************************************************************************/
        $start = floor($args['start']/1000);
        $end = floor($args['end']/1000);
        $timezone = $currentUser->primaryVenue->time_zone;
        $now = Carbon::now($timezone)->format('U');
        $starttoday = Carbon::now($timezone)->startOfDay()->format('U');
        $end_temp = ($starttoday - 1);

        /**
         * filter on the user's primary_venue_id
         */
        $venue_filter = $currentUser->primary_venue_id;

        /**
         * check whether the user is allowed to view full venue stats or not
         * and act accordingly
         */
        if ($currentUser->full_venue_view_allowed == 1) {
            /**
             * user is allowed to view full venue stats
             * prepare and execute the query for the daily stats
             */
            $thisMonthVisitors = new TrackingDailyStatsVenueVisitors;
            $initialresultsThisMonthVisitors = $thisMonthVisitors->selectRaw('SUM(visitors_new) AS visitors_new, SUM(visitors_total) AS visitors_total')
                ->where('day_epoch', '>=', $start)
                ->where('day_epoch', '<', $end_temp)
                ->where('venue_id', $venue_filter)
                ->get();
        } else {
            /**
             * user is NOT allowed to view full venue stats
             * filter on the user's allowed zones AND on the user's primary_venue_id
             *
             * get the allowed zones, then create an array which only contains the ids
             */
            $allowed_zones = collect($currentUser->zones);
            $allowed_zones_ids = [];
            foreach ($allowed_zones as $allowed_zone) {
                $allowed_zones_ids[] = $allowed_zone->id;
            }

            /**
             * prepare and execute the query for the daily stats
             */
            $thisMonthVisitors = new TrackingDailyStatsZoneVisitors;
            $initialresultsThisMonthVisitors = $thisMonthVisitors->selectRaw('SUM(visitors_new) AS visitors_new, SUM(visitors_total) AS visitors_total')
                ->where('day_epoch', '>=', $start)
                ->where('day_epoch', '<', $end_temp)
                ->where('venue_id', $venue_filter)
                ->whereIn('zone_id', $allowed_zones_ids)
                ->get();
        }

        /**
         * get the new/repeat visitors for today to add to the previous results
         * prepare and execute the query using a PDO connection provided
         */
        $returningVisitorsQuery = new ProbeRequest;
        $db = $returningVisitorsQuery->getConnection()->getPdo();

        /**
         * check whether the user is allowed to view full venue stats or not
         * and act accordingly
         */
        if ($currentUser->full_venue_view_allowed == 1) {
            /**
             * user is allowed to view full venue stats
             * execute the prepared statement with the vars available for returning visitors today
             */
            $returningVisitors = $db->prepare('
                SELECT COUNT(DISTINCT probe_request.device_uuid) AS `returning`
                FROM probe_request
                PARTITION (p' . $venue_filter . ')
                INNER JOIN tracking_daily_stats_venue_device_uuid_cache AS repeat_visitors
                    ON probe_request.device_uuid = repeat_visitors.device_uuid
                WHERE repeat_visitors.first_seen < :start_1
                AND ts > :start_2'
            );
        } else {
            /**
             * user is NOT allowed to view full venue stats
             * filter on the user's allowed zones AND on the user's primary_venue_id
             *
             * get the allowed zones, then create an array which only contains the ids
             */
            $allowed_zones = collect($currentUser->zones);
            $allowed_drones_ids = [];
            foreach ($allowed_zones as $allowed_zone) {
                foreach ($allowed_zone->drones as $drone) {
                    $allowed_drones_ids[] = $drone->id;
                }
            }

            /**
             * create a string containing drone ids, comma seperated, to insert into the PDO statement
             */
            $ids_string = implode(',', $allowed_drones_ids);

            /**
             * execute the prepared statement with the vars available for returning visitors today
             */
            $returningVisitors = $db->prepare('
                SELECT COUNT(DISTINCT probe_request.device_uuid) AS `returning`
                FROM probe_request
                INNER JOIN tracking_daily_stats_venue_device_uuid_cache AS repeat_visitors
                    ON probe_request.device_uuid = repeat_visitors.device_uuid
                WHERE repeat_visitors.first_seen < :start_1
                AND ts > :start_2
                AND venue_id = :venue_id
                AND drone_id IN (' . $ids_string . ')'
            );

            $returningVisitors->bindParam(':venue_id', $venue_filter);
        }

        /**
         * bind the parameters for the prepared statement
         * in this case: with PDO prepared statements we couldn't use the same parameter twice...
         */
        $returningVisitors->bindParam(':start_1', $starttoday);
        $returningVisitors->bindParam(':start_2', $starttoday);

        /**
         * execute the query
         */
        $returningVisitors->execute();
        $returningVisitorsToday = $returningVisitors->fetch();

        /**
         * check whether the user is allowed to view full venue stats or not
         * and act accordingly
         */
        if ($currentUser->full_venue_view_allowed == 1) {
            /**
             * user is allowed to view full venue stats
             * execute the prepared statement with the vars available for total visitors today
             */
            $totalVisitors = $db->prepare('
                SELECT COUNT(DISTINCT device_uuid) AS total
                FROM probe_request
                PARTITION (p' . $venue_filter . ')
                WHERE ts > :start'
            );
        } else {
            /**
             * user is NOT allowed to view full venue stats
             * filter on the user's allowed zones AND on the user's primary_venue_id
             *
             * get the allowed zones, then create an array which only contains the ids
             */
            $allowed_zones = collect($currentUser->zones);
            $allowed_drones_ids = [];
            foreach ($allowed_zones as $allowed_zone) {
                foreach ($allowed_zone->drones as $drone) {
                    $allowed_drones_ids[] = $drone->id;
                }
            }

            /**
             * create a string containing drone ids, comma seperated, to insert into the PDO statement
             */
            $ids_string = implode(',', $allowed_drones_ids);

            /**
             * execute the prepared statement with the vars available for total visitors today
             */
            $totalVisitors = $db->prepare('
                SELECT COUNT(DISTINCT device_uuid) AS total
                FROM probe_request
                WHERE ts > :start
                AND venue_id = :venue_id
                AND drone_id IN (' . $ids_string . ')'
            );

            $totalVisitors->bindParam(':venue_id', $venue_filter);
        }

        /**
         * bind the parameters for the prepared statement
         */
        $totalVisitors->bindParam(':start', $starttoday);

        /**
         * execute the query
         */
        $totalVisitors->execute();
        $totalVisitorsToday = $totalVisitors->fetch();
        $repeatVisitorsToday = $returningVisitorsToday['returning'];
        $newVisitorsToday = $totalVisitorsToday['total'] - $repeatVisitorsToday;

        /*************************************************************************************
         * PREVIOUS MONTH FROM HERE
         * set $start, $end_temp (end of the last full day) and $end
         * TODO: add the remaining hourly stats
         * get the unix datetime for the partial day (today - 7 days)
         * get the current hour
         * query on day_epoch and hour <= current hour
         * filter on venue_filter
         *************************************************************************************/
        $prev_month_start = Carbon::createFromTimestamp($start, $timezone)->subMonth()->format('U');
        $prev_month_end_temp = Carbon::createFromTimestamp($end_temp, $timezone)->subMonth()->format('U');
        $prev_month_end = Carbon::createFromTimestamp($end, $timezone)->subMonth()->format('U');
        $current_hour = Carbon::now($timezone)->hour;
        $today_1_month_ago = Carbon::now($timezone)->subMonth()->startOfDay()->format('U');

        /**
         * check whether the user is allowed to view full venue stats or not
         * and act accordingly
         */
        if ($currentUser->full_venue_view_allowed == 1) {
            /**
             * user is allowed to view full venue stats
             * prepare and execute the query for the daily stats
             */
            $prevMonthVisitors = new TrackingDailyStatsVenueVisitors;
            $initialresultsPrevMonthVisitors = $prevMonthVisitors->selectRaw('SUM(visitors_new) AS visitors_new, SUM(visitors_total) AS visitors_total')
                ->where('day_epoch', '>=', $prev_month_start)
                ->where('day_epoch', '<', $prev_month_end_temp)
                ->where('venue_id', $venue_filter)
                ->get();

            /**
             * prepare and execute the query for the remaining hourly stats
             */
            $prevMonthVisitorsHourly = new TrackingDailyStatsVenueHourlyVisitors;
            $initialresultsPrevMonthVisitorsHourly = $prevMonthVisitorsHourly->selectRaw('SUM(visitors_new) AS visitors_new, SUM(visitors_total) AS visitors_total')
                ->where('day_epoch', '=', $today_1_month_ago)
                ->where('hour', '<=', $current_hour)
                ->where('venue_id', $venue_filter)
                ->get();
        } else {
            /**
             * user is NOT allowed to view full venue stats
             * filter on the user's allowed zones AND on the user's primary_venue_id
             *
             * get the allowed zones, then create an array which only contains the ids
             */
            $allowed_zones = collect($currentUser->zones);
            $allowed_zones_ids = [];
            foreach ($allowed_zones as $allowed_zone) {
                $allowed_zones_ids[] = $allowed_zone->id;
            }

            /**
             * prepare and execute the query for the daily stats
             */
            $prevMonthVisitors = new TrackingDailyStatsZoneVisitors;
            $initialresultsPrevMonthVisitors = $prevMonthVisitors->selectRaw('SUM(visitors_new) AS visitors_new, SUM(visitors_total) AS visitors_total')
                ->where('day_epoch', '>=', $prev_month_start)
                ->where('day_epoch', '<', $prev_month_end_temp)
                ->where('venue_id', $venue_filter)
                ->whereIn('zone_id', $allowed_zones_ids)
                ->get();

            /**
             * prepare and execute the query for the remaining hourly stats
             */
            $prevMonthVisitorsHourly = new TrackingDailyStatsZoneHourlyVisitors;
            $initialresultsPrevMonthVisitorsHourly = $prevMonthVisitorsHourly->selectRaw('SUM(visitors_new) AS visitors_new, SUM(visitors_total) AS visitors_total')
                ->where('day_epoch', '=', $today_1_month_ago)
                ->where('hour', '<=', $current_hour)
                ->where('venue_id', $venue_filter)
                ->whereIn('zone_id', $allowed_zones_ids)
                ->get();
        }

        /*************************************************************************************
         * PREVIOUS YEAR, SAME MONTH, FROM HERE
         * set $start, $end_temp (end of the last full day) and $end
         * TODO: add the remaining hourly stats
         *************************************************************************************/
        $prev_year_start = Carbon::createFromTimestamp($start, $timezone)->subYear()->format('U');
        $prev_year_end_temp = Carbon::createFromTimestamp($end_temp, $timezone)->subYear()->format('U');
        $prev_year_end = Carbon::createFromTimestamp($end, $timezone)->subYear()->format('U');
        $current_hour = Carbon::now($timezone)->hour;
        $today_1_year_ago = Carbon::now($timezone)->subYear()->startOfDay()->format('U');

        /**
         * check whether the user is allowed to view full venue stats or not
         * and act accordingly
         */
        if ($currentUser->full_venue_view_allowed == 1) {
            /**
             * user is allowed to view full venue stats
             * prepare and execute the query for the daily stats
             */
            $prevYearVisitors = new TrackingDailyStatsVenueVisitors;
            $initialresultsPrevYearVisitors = $prevYearVisitors->selectRaw('SUM(visitors_new) AS visitors_new, SUM(visitors_total) AS visitors_total')
                ->where('day_epoch', '>=', $prev_year_start)
                ->where('day_epoch', '<', $prev_year_end_temp)
                ->where('venue_id', $venue_filter)
                ->get();

            /**
             * prepare and execute the query for the remaining hourly stats
             */
            $prevYearVisitorsHourly = new TrackingDailyStatsVenueHourlyVisitors;
            $initialresultsPrevYearVisitorsHourly = $prevYearVisitorsHourly->selectRaw('SUM(visitors_new) AS visitors_new, SUM(visitors_total) AS visitors_total')
                ->where('day_epoch', '=', $today_1_year_ago)
                ->where('hour', '<=', $current_hour)
                ->where('venue_id', $venue_filter)
                ->get();
        } else {
            /**
             * user is NOT allowed to view full venue stats
             * filter on the user's allowed zones AND on the user's primary_venue_id
             *
             * get the allowed zones, then create an array which only contains the ids
             */
            $allowed_zones = collect($currentUser->zones);
            $allowed_zones_ids = [];
            foreach ($allowed_zones as $allowed_zone) {
                $allowed_zones_ids[] = $allowed_zone->id;
            }

            /**
             * prepare and execute the query for the daily stats
             */
            $prevYearVisitors = new TrackingDailyStatsZoneVisitors;
            $initialresultsPrevYearVisitors = $prevYearVisitors->selectRaw('SUM(visitors_new) AS visitors_new, SUM(visitors_total) AS visitors_total')
                ->where('day_epoch', '>=', $prev_year_start)
                ->where('day_epoch', '<', $prev_year_end_temp)
                ->where('venue_id', $venue_filter)
                ->whereIn('zone_id', $allowed_zones_ids)
                ->get();

            /**
             * prepare and execute the query for the remaining hourly stats
             */
            $prevYearVisitorsHourly = new TrackingDailyStatsZoneHourlyVisitors;
            $initialresultsPrevYearVisitorsHourly = $prevYearVisitorsHourly->selectRaw('SUM(visitors_new) AS visitors_new, SUM(visitors_total) AS visitors_total')
                ->where('day_epoch', '=', $today_1_year_ago)
                ->where('hour', '<=', $current_hour)
                ->where('venue_id', $venue_filter)
                ->whereIn('zone_id', $allowed_zones_ids)
                ->get();
        }

        /*************************************************************************************
         * process the results from all three periods and merge into a single array
         * TODO:
         *************************************************************************************/
        $results['new'] = [
            (int)$initialresultsPrevYearVisitors[0]['visitors_new']  + $initialresultsPrevYearVisitorsHourly[0]['visitors_new'],
            (int)$initialresultsPrevMonthVisitors[0]['visitors_new'] + $initialresultsPrevMonthVisitorsHourly[0]['visitors_new'],
            (int)$initialresultsThisMonthVisitors[0]['visitors_new'] + $newVisitorsToday
        ];

        $results['repeat'] = [
            (int)$initialresultsPrevYearVisitors[0]['visitors_total'] - (int)$initialresultsPrevYearVisitors[0]['visitors_new']
                + $initialresultsPrevYearVisitorsHourly[0]['visitors_total'] - $initialresultsPrevYearVisitorsHourly[0]['visitors_new'],
            (int)$initialresultsPrevMonthVisitors[0]['visitors_total'] - (int)$initialresultsPrevMonthVisitors[0]['visitors_new']
                + $initialresultsPrevMonthVisitorsHourly[0]['visitors_total'] - $initialresultsPrevMonthVisitorsHourly[0]['visitors_new'],
            (int)$initialresultsThisMonthVisitors[0]['visitors_total'] - (int)$initialresultsThisMonthVisitors[0]['visitors_new']
                + $repeatVisitorsToday
        ];

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listVenueStatsUniqueVisitorsCurrentYearComparedToPrevYear(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $results = [];

        /*************************************************************************************
         * CURRENT YEAR FROM HERE
         * get the desired time zone
         * set $start, $end_temp (end of the last full day) and $end
         *************************************************************************************/
        $start = floor($args['start']/1000);
        $end = floor($args['end']/1000);
        $timezone = $currentUser->primaryVenue->time_zone;
        $now = Carbon::now($timezone)->format('U');
        $starttoday = Carbon::now($timezone)->startOfDay()->format('U');
        $end_temp = ($starttoday - 1);

        /**
         * filter on the user's primary_venue_id
         */
        $venue_filter = $currentUser->primary_venue_id;

        /**
         * check whether the user is allowed to view full venue stats or not
         * and act accordingly
         */
        if ($currentUser->full_venue_view_allowed == 1) {
            /**
             * user is allowed to view full venue stats
             * prepare and execute the query for the daily stats
             */
            $thisYearVisitors = new TrackingDailyStatsVenueVisitors;
            $initialresultsThisYearVisitors = $thisYearVisitors->selectRaw('SUM(visitors_new) AS visitors_new, SUM(visitors_total) AS visitors_total')
                ->where('day_epoch', '>=', $start)
                ->where('day_epoch', '<', $end_temp)
                ->where('venue_id', $venue_filter)
                ->get();
        } else {
            /**
             * user is NOT allowed to view full venue stats
             * filter on the user's allowed zones AND on the user's primary_venue_id
             *
             * get the allowed zones, then create an array which only contains the ids
             */
            $allowed_zones = collect($currentUser->zones);
            $allowed_zones_ids = [];
            foreach ($allowed_zones as $allowed_zone) {
                $allowed_zones_ids[] = $allowed_zone->id;
            }

            /**
             * prepare and execute the query for the daily stats
             */
            $thisYearVisitors = new TrackingDailyStatsZoneVisitors;
            $initialresultsThisYearVisitors = $thisYearVisitors->selectRaw('SUM(visitors_new) AS visitors_new, SUM(visitors_total) AS visitors_total')
                ->where('day_epoch', '>=', $start)
                ->where('day_epoch', '<', $end_temp)
                ->where('venue_id', $venue_filter)
                ->whereIn('zone_id', $allowed_zones_ids)
                ->get();
        }

        /**
         * get the new/repeat visitors for today to add to the previous results
         * prepare and execute the query using a PDO connection provided
         */
        $returningVisitorsQuery = new ProbeRequest;
        $db = $returningVisitorsQuery->getConnection()->getPdo();

        /**
         * check whether the user is allowed to view full venue stats or not
         * and act accordingly
         */
        if ($currentUser->full_venue_view_allowed == 1) {
            /**
             * user is allowed to view full venue stats
             * create the prepared statement with the vars available for returning visitors today
             */
            $returningVisitors = $db->prepare('
                SELECT COUNT(DISTINCT probe_request.device_uuid) AS `returning`
                FROM probe_request
                PARTITION (p' . $venue_filter . ')
                INNER JOIN tracking_daily_stats_venue_device_uuid_cache AS repeat_visitors
                    ON probe_request.device_uuid = repeat_visitors.device_uuid
                WHERE repeat_visitors.first_seen < :start_1
                AND ts > :start_2'
            );

        } else {
            /**
             * user is NOT allowed to view full venue stats
             * filter on the user's allowed zones AND on the user's primary_venue_id
             *
             * get the allowed zones, then create an array which only contains the ids
             */
            $allowed_zones = collect($currentUser->zones);
            $allowed_drones_ids = [];
            foreach ($allowed_zones as $allowed_zone) {
                foreach ($allowed_zone->drones as $drone) {
                    $allowed_drones_ids[] = $drone->id;
                }
            }

            /**
             * create a string containing drone ids, comma seperated, to insert into the PDO statement
             */
            $ids_string = implode(',', $allowed_drones_ids);

            /**
             * execute the prepared statement with the vars available for returning visitors today
             */
            $returningVisitors = $db->prepare('
                SELECT COUNT(DISTINCT probe_request.device_uuid) AS `returning`
                FROM probe_request
                INNER JOIN tracking_daily_stats_venue_device_uuid_cache AS repeat_visitors
                    ON probe_request.device_uuid = repeat_visitors.device_uuid
                WHERE repeat_visitors.first_seen < :start_1
                AND ts > :start_2
                AND venue_id = :venue_id
                AND drone_id IN (' . $ids_string . ')'
            );

            $returningVisitors->bindParam(':venue_id', $venue_filter);
        }

        /**
         * bind the parameters for the prepared statement
         * in this case: with PDO prepared statements we couldn't use the same parameter twice...
         */
        $returningVisitors->bindParam(':start_1', $starttoday);
        $returningVisitors->bindParam(':start_2', $starttoday);

        /**
         * execute the query
         */
        $returningVisitors->execute();
        $returningVisitorsToday = $returningVisitors->fetch();

        /**
         * check whether the user is allowed to view full venue stats or not
         * and act accordingly
         */
        if ($currentUser->full_venue_view_allowed == 1) {
            /**
             * user is allowed to view full venue stats
             * create the prepared statement with the vars available for total visitors today
             */
            $totalVisitors = $db->prepare('
                SELECT COUNT(DISTINCT device_uuid) AS total
                FROM probe_request
                PARTITION (p' . $venue_filter . ')
                WHERE ts > :start'
            );

        } else {
            /**
             * user is NOT allowed to view full venue stats
             * filter on the user's allowed zones AND on the user's primary_venue_id
             *
             * get the allowed zones, then create an array which only contains the ids
             */
            $allowed_zones = collect($currentUser->zones);
            $allowed_drones_ids = [];
            foreach ($allowed_zones as $allowed_zone) {
                foreach ($allowed_zone->drones as $drone) {
                    $allowed_drones_ids[] = $drone->id;
                }
            }

            /**
             * create a string containing drone ids, comma seperated, to insert into the PDO statement
             */
            $ids_string = implode(',', $allowed_drones_ids);

            /**
             * execute the prepared statement with the vars available for total visitors today
             */
            $totalVisitors = $db->prepare('
                SELECT COUNT(DISTINCT device_uuid) AS total
                FROM probe_request
                WHERE ts > :start
                AND venue_id = :venue_id
                AND drone_id IN (' . $ids_string . ')'
            );

            $totalVisitors->bindParam(':venue_id', $venue_filter);
        }

        /**
         * bind the parameters for the prepared statement
         */
        $totalVisitors->bindParam(':start', $starttoday);

        /**
         * execute the query
         */
        $totalVisitors->execute();
        $totalVisitorsToday = $totalVisitors->fetch();
        $repeatVisitorsToday = $returningVisitorsToday['returning'];
        $newVisitorsToday = $totalVisitorsToday['total'] - $repeatVisitorsToday;

        /*************************************************************************************
         * PREVIOUS YEAR FROM HERE
         * set $start, $end_temp (end of the last full day) and $end
         * TODO: add the remaining hourly stats
         * get the unix datetime for the partial day (today - 7 days)
         * get the current hour
         * query on day_epoch and hour <= current hour
         * filter on venue_filter                                  Year
         *************************************************************************************/
        $prev_year_start = Carbon::createFromTimestamp($start, $timezone)->subYear()->format('U');
        $prev_year_end_temp = Carbon::createFromTimestamp($end_temp, $timezone)->subYear()->format('U');
        $prev_year_end = Carbon::createFromTimestamp($end, $timezone)->subYear()->format('U');
        $current_hour = Carbon::now($timezone)->hour;
        $today_1_year_ago = Carbon::now($timezone)->subYear()->startOfDay()->format('U');

        /**
         * check whether the user is allowed to view full venue stats or not
         * and act accordingly
         */
        if ($currentUser->full_venue_view_allowed == 1) {
            /**
             * user is allowed to view full venue stats
             * prepare and execute the query for the daily stats
             */
            $prevYearVisitors = new TrackingDailyStatsVenueVisitors;
            $initialresultsPrevYearVisitors = $prevYearVisitors->selectRaw('SUM(visitors_new) AS visitors_new, SUM(visitors_total) AS visitors_total')
                ->where('day_epoch', '>=', $prev_year_start)
                ->where('day_epoch', '<', $prev_year_end_temp)
                ->where('venue_id', $venue_filter)
                ->get();

            /**
             * prepare and execute the query for the remaining hourly stats
             */
            $prevYearVisitorsHourly = new TrackingDailyStatsVenueHourlyVisitors;
            $initialresultsPrevYearVisitorsHourly = $prevYearVisitorsHourly->selectRaw('SUM(visitors_new) AS visitors_new, SUM(visitors_total) AS visitors_total')
                ->where('day_epoch', '=', $today_1_year_ago)
                ->where('hour', '<=', $current_hour)
                ->where('venue_id', $venue_filter)
                ->get();
        } else {
            /**
             * user is NOT allowed to view full venue stats
             * filter on the user's allowed zones AND on the user's primary_venue_id
             *
             * get the allowed zones, then create an array which only contains the ids
             */
            $allowed_zones = collect($currentUser->zones);
            $allowed_zones_ids = [];
            foreach ($allowed_zones as $allowed_zone) {
                $allowed_zones_ids[] = $allowed_zone->id;
            }

            /**
             * prepare and execute the query for the daily stats
             */
            $prevYearVisitors = new TrackingDailyStatsZoneVisitors;
            $initialresultsPrevYearVisitors = $prevYearVisitors->selectRaw('SUM(visitors_new) AS visitors_new, SUM(visitors_total) AS visitors_total')
                ->where('day_epoch', '>=', $prev_year_start)
                ->where('day_epoch', '<', $prev_year_end_temp)
                ->where('venue_id', $venue_filter)
                ->whereIn('zone_id', $allowed_zones_ids)
                ->get();

            /**
             * prepare and execute the query for the remaining hourly stats
             */
            $prevYearVisitorsHourly = new TrackingDailyStatsZoneHourlyVisitors;
            $initialresultsPrevYearVisitorsHourly = $prevYearVisitorsHourly->selectRaw('SUM(visitors_new) AS visitors_new, SUM(visitors_total) AS visitors_total')
                ->where('day_epoch', '=', $today_1_year_ago)
                ->where('hour', '<=', $current_hour)
                ->where('venue_id', $venue_filter)
                ->whereIn('zone_id', $allowed_zones_ids)
                ->get();
        }

        /*************************************************************************************
         * process the results from both periods and merge into a single array
         * TODO:
         *************************************************************************************/
        $results['new'] = [
            (int)$initialresultsPrevYearVisitors[0]['visitors_new'] + (int)$initialresultsPrevYearVisitorsHourly[0]['visitors_new'],
            (int)$initialresultsThisYearVisitors[0]['visitors_new'] + $newVisitorsToday
        ];

        $results['repeat'] = [
            (int)$initialresultsPrevYearVisitors[0]['visitors_total'] - (int)$initialresultsPrevYearVisitors[0]['visitors_new']
                + (int)$initialresultsPrevYearVisitorsHourly[0]['visitors_total'] - (int)$initialresultsPrevYearVisitorsHourly[0]['visitors_new'],
            (int)$initialresultsThisYearVisitors[0]['visitors_total'] - (int)$initialresultsThisYearVisitors[0]['visitors_new']
                + $repeatVisitorsToday
        ];

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listVenueStatsUniqueVisitorsWeekdaysComparedInclPrevYear(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $results = [
            'prev_week' => [],
            'prev_week' => [],
            'this_week' => []
        ];

        $start = floor($args['start']/1000);
        $end = floor($args['end']/1000);

        /**
         * filter on the user's primary_venue_id
         */
        $venue_filter = $currentUser->primary_venue_id;

        /**
         * check whether the user is allowed to view full venue stats or not
         * and act accordingly
         */
        if ($currentUser->full_venue_view_allowed == 1) {
            /**
             * user is allowed to view full venue stats
             * prepare and execute the query
             */
            $thisWeekVisitors = new TrackingDailyStatsVenueVisitors;
            $initialresultsThisWeekVisitors = $thisWeekVisitors->selectRaw('day_of_week, SUM(visitors_total) AS visitors_total')
                ->where('day_epoch', '>=', $start)
                ->where('day_epoch', '<', $end)
                ->where('venue_id', $venue_filter)
                ->orderBy('day_epoch', 'asc')
                ->groupBy('day_of_week')
                ->get();
        } else {
            /**
             * user is NOT allowed to view full venue stats
             * filter on the user's allowed zones AND on the user's primary_venue_id
             *
             * get the allowed zones, then create an array which only contains the ids
             */
            $allowed_zones = collect($currentUser->zones);
            $allowed_zones_ids = [];
            foreach ($allowed_zones as $allowed_zone) {
                $allowed_zones_ids[] = $allowed_zone->id;
            }

            /**
             * prepare and execute the query with venue AND zones filtering
             */
            $thisWeekVisitors = new TrackingDailyStatsZoneVisitors;
            $initialresultsThisWeekVisitors = $thisWeekVisitors->selectRaw('day_of_week, SUM(visitors_total) AS visitors_total')
                ->where('day_epoch', '>=', $start)
                ->where('day_epoch', '<', $end)
                ->where('venue_id', $venue_filter)
                ->whereIn('zone_id', $allowed_zones_ids)
                ->orderBy('day_epoch', 'asc')
                ->groupBy('day_of_week')
                ->get();
        }

        /**
         * previous start is now our end, new end is one week later
         */
        $end = $start;
        $start = $end - (3600*24*7);

        /**
         * check whether the user is allowed to view full venue stats or not
         * and act accordingly
         */
        if ($currentUser->full_venue_view_allowed == 1) {
            /**
             * user is allowed to view full venue stats
             * prepare and execute the query
             */
            $prevWeekVisitors = new TrackingDailyStatsVenueVisitors;
            $initialresultsPrevWeekVisitors = $prevWeekVisitors->selectRaw('day_of_week, SUM(visitors_total) AS visitors_total')
                ->where('day_epoch', '>=', $start)
                ->where('day_epoch', '<', $end)
                ->where('venue_id', $venue_filter)
                ->orderBy('day_epoch', 'asc')
                ->groupBy('day_of_week')
                ->get();
        } else {
            /**
             * user is NOT allowed to view full venue stats
             * filter on the user's allowed zones AND on the user's primary_venue_id
             *
             * get the allowed zones, then create an array which only contains the ids
             */
            $allowed_zones = collect($currentUser->zones);
            $allowed_zones_ids = [];
            foreach ($allowed_zones as $allowed_zone) {
                $allowed_zones_ids[] = $allowed_zone->id;
            }

            /**
             * prepare and execute the query with venue AND zones filtering
             */
            $prevWeekVisitors = new TrackingDailyStatsZoneVisitors;
            $initialresultsPrevWeekVisitors = $prevWeekVisitors->selectRaw('day_of_week, SUM(visitors_total) AS visitors_total')
                ->where('day_epoch', '>=', $start)
                ->where('day_epoch', '<', $end)
                ->where('venue_id', $venue_filter)
                ->whereIn('zone_id', $allowed_zones_ids)
                ->orderBy('day_epoch', 'asc')
                ->groupBy('day_of_week')
                ->get();
        }

        /**
         * in case we don't have enough data yet, we return 0 visitors
         * NOTE: not sure we need this
         * if($initialresultsPrevWeekVisitors->count() < $initialresultsThisWeekVisitors->count()) {
         */
        if($initialresultsPrevWeekVisitors->count() === 0) {
            $initialresultsPrevWeekVisitors = [];
            $initialresultsPrevWeekVisitors[] = [
                'day_of_week' => 'Sunday',
                'visitors_total' => 0
            ];
            $initialresultsPrevWeekVisitors[] = [
                'day_of_week' => 'Monday',
                'visitors_total' => 0
            ];
            $initialresultsPrevWeekVisitors[] = [
                'day_of_week' => 'Tuesday',
                'visitors_total' => 0
            ];
            $initialresultsPrevWeekVisitors[] = [
                'day_of_week' => 'Wednesday',
                'visitors_total' => 0
            ];
            $initialresultsPrevWeekVisitors[] = [
                'day_of_week' => 'Thursday',
                'visitors_total' => 0
            ];
            $initialresultsPrevWeekVisitors[] = [
                'day_of_week' => 'Friday',
                'visitors_total' => 0
            ];
            $initialresultsPrevWeekVisitors[] = [
                'day_of_week' => 'Saturday',
                'visitors_total' => 0
            ];
        }

        /**
         * this is the same range of days, previous year (52 weeks ago)
         */
        $start = floor($initial_end/1000) - (3600*24*7*52);
        $end = $start + (3600*24*7);

        /**
         * check whether the user is allowed to view full venue stats or not
         * and act accordingly
         */
        if ($currentUser->full_venue_view_allowed == 1) {
            /**
             * user is allowed to view full venue stats
             * prepare and execute the query
             */
            $prevYearVisitors = new TrackingDailyStatsVenueVisitors;
            $initialresultsPrevYearVisitors = $prevYearVisitors->selectRaw('day_of_week, SUM(visitors_total) AS visitors_total')
                ->where('day_epoch', '>=', $start)
                ->where('day_epoch', '<', $end)
                ->where('venue_id', $venue_filter)
                ->orderBy('day_epoch', 'asc')
                ->groupBy('day_of_week')
                ->get();
        } else {
            /**
             * user is NOT allowed to view full venue stats
             * filter on the user's allowed zones AND on the user's primary_venue_id
             *
             * get the allowed zones, then create an array which only contains the ids
             */
            $allowed_zones = collect($currentUser->zones);
            $allowed_zones_ids = [];
            foreach ($allowed_zones as $allowed_zone) {
                $allowed_zones_ids[] = $allowed_zone->id;
            }

            /**
             * prepare and execute the query with venue AND zones filtering
             */
            $prevYearVisitors = new TrackingDailyStatsZoneVisitors;
            $initialresultsPrevYearVisitors = $prevYearVisitors->selectRaw('day_of_week, SUM(visitors_total) AS visitors_total')
                ->where('day_epoch', '>=', $start)
                ->where('day_epoch', '<', $end)
                ->where('venue_id', $venue_filter)
                ->whereIn('zone_id', $allowed_zones_ids)
                ->orderBy('day_epoch', 'asc')
                ->groupBy('day_of_week')
                ->get();
        }

        /**
         * in case we don't have enough data yet, we return 0 visitors
         */
        if($initialresultsPrevYearVisitors->count() === 0) {
            $initialresultsPrevYearVisitors = [];
            $initialresultsPrevYearVisitors[] = [
                'day_of_week' => 'Sunday',
                'visitors_total' => 0
            ];
            $initialresultsPrevYearVisitors[] = [
                'day_of_week' => 'Monday',
                'visitors_total' => 0
            ];
            $initialresultsPrevYearVisitors[] = [
                'day_of_week' => 'Tuesday',
                'visitors_total' => 0
            ];
            $initialresultsPrevYearVisitors[] = [
                'day_of_week' => 'Wednesday',
                'visitors_total' => 0
            ];
            $initialresultsPrevYearVisitors[] = [
                'day_of_week' => 'Thursday',
                'visitors_total' => 0
            ];
            $initialresultsPrevYearVisitors[] = [
                'day_of_week' => 'Friday',
                'visitors_total' => 0
            ];
            $initialresultsPrevYearVisitors[] = [
                'day_of_week' => 'Saturday',
                'visitors_total' => 0
            ];
        }

        /**
         * process the results from both periods and merge into a single array
         */
        foreach ($initialresultsPrevYearVisitors as $initialresultsPrevYearVisitor) {
            $results['prev_year'][] = [
                $initialresultsPrevYearVisitor['day_of_week'],
                (int)$initialresultsPrevYearVisitor['visitors_total']
            ];
        }

        foreach ($initialresultsPrevWeekVisitors as $initialresultsPrevWeekVisitor) {
            $results['prev_week'][] = [
                $initialresultsPrevWeekVisitor['day_of_week'],
                (int)$initialresultsPrevWeekVisitor['visitors_total']
            ];
        }

        foreach ($initialresultsThisWeekVisitors as $initialresultsThisWeekVisitor) {
            $results['this_week'][] = [
                $initialresultsThisWeekVisitor['day_of_week'],
                (int)$initialresultsThisWeekVisitor['visitors_total']
            ];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listVenueStatsUniqueVisitorsComparisonChartsCustomOld(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $results = [];

        /*************************************************************************************
         * SELECTED RANGE FROM HERE
         * get the desired time zone
         * set $start, $end
         * TODO:
         * - determine full days within the body
         * - get daily stats for it
         * - determine whether tail is today or not
         * - yes: we get data from probe_requests
         * - no: we get hourly stats for it
         * - determine head and get hourly stats for it
         * - using Carbon to copy timestamps you need to use the copy function!!!
         *************************************************************************************/
        $start = floor($args['start']/1000);
        $end = floor($args['end']/1000);
        $timezone = $currentUser->primaryVenue->time_zone;
        $this->determineHeadBodyTail($start, $end, $timezone);

        /**
         * filter on the user's primary_venue_id
         */
        $venue_filter = $currentUser->primary_venue_id;

        /**
         * execute query for HEAD of selected range
         */
        if($this->head_length > 0) {
            /**
             * get the start of day for head and get the hour of head_start and head_end
             */
            $head_start_hour = $this->head_start->format('G');
            $head_end_hour = $this->head_end->format('G');

            /**
             * prepare and execute the query using a PDO connection provided
             */
            $headVisitorsQuery = new TrackingDailyStatsVenueHourlyVisitors;
            $db = $headVisitorsQuery->getConnection()->getPdo();

            /**
             * check whether the user is allowed to view full venue stats or not
             * and act accordingly
             */
            if ($currentUser->full_venue_view_allowed == 1) {
                /**
                 * user is allowed to view full venue stats
                 * prepare and execute the query
                 */
                $initialresultsHeadVisitors = $db->prepare('
                    SELECT SUM(visitors_new) AS new, SUM(visitors_total) AS total
                    FROM tracking_daily_stats_venue_visitors_per_hour
                    PARTITION (p' . $venue_filter . ')
                    WHERE day_epoch = :head_day_epoch
                    AND hour <= :head_end_hour
                    AND hour >= :head_start_hour'
                );
            } else {
                /**
                 * user is NOT allowed to view full venue stats
                 * filter on the user's allowed zones AND on the user's primary_venue_id
                 *
                 * get the allowed zones, then create an array which only contains the ids
                 */
                $allowed_zones = collect($currentUser->zones);
                $allowed_zones_ids = [];
                foreach ($allowed_zones as $allowed_zone) {
                    $allowed_zones_ids[] = $allowed_zone->id;
                }

                $initialresultsHeadVisitors = $db->prepare('
                    SELECT SUM(visitors_new) AS new, SUM(visitors_total) AS total
                    FROM tracking_daily_stats_zone_visitors_per_hour
                    WHERE venue_id = :venue_id
                    AND day_epoch = :head_day_epoch
                    AND hour <= :head_end_hour
                    AND hour >= :head_start_hour
                    WHERE zone_id IN (' . $allowed_zones_ids . ')'
                );

                $initialresultsHeadVisitors->bindParam(':venue_id', $venue_id);
            }

            /**
             * bind the parameters for the prepared statement
             */
            $initialresultsHeadVisitors->bindParam(':head_day_epoch', $this->head_day_epoch);
            $initialresultsHeadVisitors->bindParam(':head_end_hour', $head_end_hour);
            $initialresultsHeadVisitors->bindParam(':head_start_hour', $head_start_hour);

            /**
             * execute the query
             */
            $initialresultsHeadVisitors->execute();
            $initialresultsHeadVisitors = $initialresultsHeadVisitors->fetchAll();

            $head_results['new']    = $initialresultsHeadVisitors[0]['new'];
            $head_results['repeat'] = $initialresultsHeadVisitors[0]['total'] - $initialresultsHeadVisitors[0]['new'];
        } else {
            /**
             * we do nothing and return zero values for new and repeat
             */
            $head_results['new']    = 0;
            $head_results['repeat'] = 0;
        }

        /**
         * execute query for BODY of selected range
         */
        if($this->body_length > 0) {
            /**
             * get body_start and body_end formatted as epoch
             */
            $body_start = $this->body_start->format('U');
            $body_end = $this->body_end->format('U');

            /**
             * prepare and execute the query using a PDO connection provided
             */
            $bodyVisitorsQuery = new TrackingDailyStatsVenueVisitors;
            $db = $bodyVisitorsQuery->getConnection()->getPdo();

            /**
             * check whether the user is allowed to view full venue stats or not
             * and act accordingly
             */
            if ($currentUser->full_venue_view_allowed == 1) {
                /**
                 * user is allowed to view full venue stats
                 * prepare and execute the query for the daily stats for BODY
                 */

                $initialresultsbodyVisitors = $db->prepare('
                    SELECT SUM(visitors_new) AS new, SUM(visitors_total) AS total
                    FROM tracking_daily_stats_venue_visitors
                    PARTITION (p' . $venue_filter . ')
                    WHERE day_epoch >= :body_start
                    AND day_epoch < :body_end'
                );
            } else {
                /**
                 * user is NOT allowed to view full venue stats
                 * filter on the user's allowed zones AND on the user's primary_venue_id
                 *
                 * get the allowed zones, then create an array which only contains the ids
                 */
                $allowed_zones = collect($currentUser->zones);
                $allowed_zones_ids = [];
                foreach ($allowed_zones as $allowed_zone) {
                    $allowed_zones_ids[] = $allowed_zone->id;
                }

                $initialresultsbodyVisitors = $db->prepare('
                    SELECT SUM(visitors_new) AS new, SUM(visitors_total) AS total
                    FROM tracking_daily_stats_zone_visitors
                    WHERE venue_id = :venue_id
                    AND day_epoch >= :body_start
                    AND day_epoch < :body_end
                    WHERE zone_id IN (' . $allowed_zones_ids . ')'
                );

                $initialresultsbodyVisitors->bindParam(':venue_id', $venue_filter);
            }

            /**
             * bind the parameters for the prepared statement
             */
            $initialresultsbodyVisitors->bindParam(':body_start', $body_start);
            $initialresultsbodyVisitors->bindParam(':body_end', $body_end);

            /**
             * execute the query
             */
            $initialresultsbodyVisitors->execute();
            $initialresultsbodyVisitors = $initialresultsbodyVisitors->fetchAll();

            $body_results['new'] = $initialresultsbodyVisitors[0]['new'];
            $body_results['repeat'] = $initialresultsbodyVisitors[0]['total'] - $initialresultsbodyVisitors[0]['new'];

        } else {
            /**
             * we do nothing and return zero values for new and repeat
             */
            $body_results['new'] = 0;
            $body_results['repeat'] = 0;
        }

        /**
         * execute query for TAIL of selected range
         */
        if($this->tail_length > 0) {
            /**
             * we first determine whether TAIL is in today or not
             */
            if($this->tail_in_today) {
                /**
                 * get body_start and body_end formatted as epoch
                 */
                $tail_start = $this->tail_start->format('U');
                $tail_end = $this->tail_end->format('U');

                /**
                 * prepare and execute the query using a PDO connection provided
                 */
                $returningVisitorsQuery = new ProbeRequest;
                $db = $returningVisitorsQuery->getConnection()->getPdo();

                /**
                 * check whether the user is allowed to view full venue stats or not
                 * and act accordingly
                 */
                if ($currentUser->full_venue_view_allowed == 1) {
                    /**
                     * user is allowed to view full venue stats
                     * create the prepared statement with the vars available for returning visitors in TAIL today
                     */
                    $returningVisitors = $db->prepare('
                        SELECT COUNT(DISTINCT probe_request.device_uuid) AS `returning`
                        FROM probe_request
                        PARTITION (p' . $venue_filter . ')
                        INNER JOIN tracking_daily_stats_venue_device_uuid_cache AS repeat_visitors
                            ON probe_request.device_uuid = repeat_visitors.device_uuid
                        WHERE repeat_visitors.first_seen < :start_1
                        AND ts > :start_2
                        AND ts < :end'
                    );
                } else {
                    /**
                     * user is NOT allowed to view full venue stats
                     * filter on the user's allowed zones AND on the user's primary_venue_id
                     *
                     * get the allowed zones, then create an array which only contains the ids
                     */
                    $allowed_zones = collect($currentUser->zones);
                    $allowed_drones_ids = [];
                    foreach ($allowed_zones as $allowed_zone) {
                        foreach ($allowed_zone->drones as $drone) {
                            $allowed_drones_ids[] = $drone->id;
                        }
                    }

                    /**
                     * create a string containing drone ids, comma seperated, to insert into the PDO statement
                     */
                    $ids_string = implode(',', $allowed_drones_ids);

                    /**
                     * execute the prepared statement with the vars available for returning visitors in TAIL today
                     */
                    $returningVisitors = $db->prepare('
                        SELECT COUNT(DISTINCT probe_request.device_uuid) AS `returning`
                        FROM probe_request
                        INNER JOIN tracking_daily_stats_venue_device_uuid_cache AS repeat_visitors
                            ON probe_request.device_uuid = repeat_visitors.device_uuid
                        WHERE repeat_visitors.first_seen < :start_1
                        AND ts > :start_2
                        AND ts < :end
                        AND venue_id = :venue_id
                        AND drone_id IN (' . $ids_string . ')'
                    );

                    $returningVisitors->bindParam(':venue_id', $venue_filter);
                }

                /**
                 * bind the parameters for the prepared statement
                 */
                $returningVisitors->bindParam(':start_1', $tail_start);
                $returningVisitors->bindParam(':start_2', $tail_start);
                $returningVisitors->bindParam(':end', $tail_end);

                /**
                 * execute the query
                 */
                $returningVisitors->execute();
                $returningVisitorsToday = $returningVisitors->fetch();

                /**
                 * check whether the user is allowed to view full venue stats or not
                 * and act accordingly
                 */
                if ($currentUser->full_venue_view_allowed == 1) {
                    /**
                     * user is allowed to view full venue stats
                     * create the prepared statement with the vars available for total visitors in TAIL today
                     */
                    $totalVisitors = $db->prepare('
                        SELECT COUNT(DISTINCT device_uuid) AS total
                        FROM probe_request
                        PARTITION (p' . $venue_filter . ')
                        WHERE ts > :start
                        AND ts < :end'
                    );
                } else {
                    /**
                     * user is NOT allowed to view full venue stats
                     * filter on the user's allowed zones AND on the user's primary_venue_id
                     *
                     * get the allowed zones, then create an array which only contains the ids
                     */
                    $allowed_zones = collect($currentUser->zones);
                    $allowed_drones_ids = [];
                    foreach ($allowed_zones as $allowed_zone) {
                        foreach ($allowed_zone->drones as $drone) {
                            $allowed_drones_ids[] = $drone->id;
                        }
                    }

                    /**
                     * create a string containing drone ids, comma seperated, to insert into the PDO statement
                     */
                    $ids_string = implode(',', $allowed_drones_ids);

                    /**
                     * execute the prepared statement with the vars available for total visitors in TAIL today
                     */
                    $totalVisitors = $db->prepare('
                        SELECT COUNT(DISTINCT device_uuid) AS total
                        FROM probe_request
                        WHERE ts > :start
                        AND ts < :end
                        AND venue_id = :venue_id
                        AND drone_id IN (' . $ids_string . ')'
                    );

                    $totalVisitors->bindParam(':venue_id', $venue_filter);
                }

                /**
                 * bind the parameters for the prepared statement
                 */
                $totalVisitors->bindParam(':start', $tail_start);
                $totalVisitors->bindParam(':end', $tail_end);

                /**
                 * execute the query for total visitors in TAIL
                 */
                $totalVisitors->execute();
                $totalVisitorsToday = $totalVisitors->fetch();

                $tail_results['new'] = $totalVisitorsToday['total'] - $returningVisitorsToday['returning'];
                $tail_results['repeat'] = $returningVisitorsToday['returning'];

            } else {
                /**
                 * TAIL is not in today so we get hourly stats for TAIL
                 * - get the start of day for tail and get the hour of tail_start and tail_end
                 */
                $tail_start_hour = $this->tail_start->format('G');
                $tail_end_hour = $this->tail_end->format('G');

                /**
                 * prepare and execute the query using a PDO connection provided
                 */
                $tailVisitorsQuery = new TrackingDailyStatsVenueHourlyVisitors;
                $db = $tailVisitorsQuery->getConnection()->getPdo();

                /**
                 * check whether the user is allowed to view full venue stats or not
                 * and act accordingly
                 */
                if ($currentUser->full_venue_view_allowed == 1) {
                    /**
                     * user is allowed to view full venue stats
                     * prepare and execute the query
                     */

                    $initialresultsTailVisitors = $db->prepare('
                        SELECT SUM(visitors_new) AS new, SUM(visitors_total) AS total
                        FROM tracking_daily_stats_venue_visitors_per_hour
                        PARTITION (p' . $venue_filter . ')
                        WHERE day_epoch = :tail_day_epoch
                        AND hour < :tail_end_hour
                        AND hour >= :tail_start_hour'
                    );
                } else {
                    /**
                     * user is NOT allowed to view full venue stats
                     * filter on the user's allowed zones AND on the user's primary_venue_id
                     *
                     * get the allowed zones, then create an array which only contains the ids
                     */
                    $allowed_zones = collect($currentUser->zones);
                    $allowed_zones_ids = [];
                    foreach ($allowed_zones as $allowed_zone) {
                        $allowed_zones_ids[] = $allowed_zone->id;
                    }

                    $initialresultsTailVisitors = $db->prepare('
                        SELECT SUM(visitors_new) AS new, SUM(visitors_total) AS total
                        FROM tracking_daily_stats_zone_visitors_per_hour
                        WHERE venue_id = :venue_id
                        AND day_epoch = :tail_day_epoch
                        AND hour < :tail_end_hour
                        AND hour >= :tail_start_hour
                        AND zone_id IN (' . $allowed_zones_ids . ')'
                    );

                    $initialresultsTailVisitors->bindParam(':venue_id', $venue_filter);
                }

                /**
                 * bind the parameters for the prepared statement
                 */
                $initialresultsTailVisitors->bindParam(':tail_day_epoch', $this->tail_day_epoch);
                $initialresultsTailVisitors->bindParam(':tail_end_hour', $tail_end_hour);
                $initialresultsTailVisitors->bindParam(':tail_start_hour', $tail_start_hour);

                /**
                 * execute the query for total visitors in TAIL
                 */
                $initialresultsTailVisitors->execute();
                $initialresultsTailVisitors = $initialresultsTailVisitors->fetchAll();

                $tail_results['new'] = $initialresultsTailVisitors[0]['new'];
                $tail_results['repeat'] = $initialresultsTailVisitors[0]['total'] - $initialresultsTailVisitors[0]['new'];
            }
        } else {
            /**
             * we do nothing and return zero values for new and repeat
             */
            $tail_results['new'] = 0;
            $tail_results['repeat'] = 0;
        }

        /*************************************************************************************
         * process the results into a single array
         * TODO: add the hourly stats at the beginning of the range when not a full day
         *************************************************************************************/
        $results['new'] = [
            $head_results['new'] + $body_results['new'] + $tail_results['new']
        ];
        $results['repeat'] = [
            $head_results['repeat'] + $body_results['repeat'] + $tail_results['repeat']
        ];

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listVenueStatsUniqueVisitorsComparisonChartsCustomV2(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        /*************************************************************************************
         * get the desired time zone
         * set $start, $end and some other vars which will used further on
         *************************************************************************************/
        $timezone = $currentUser->primaryVenue->time_zone;
        $start = floor($args['start']/1000);
        $end = floor($args['end']/1000);
        $start_date = Carbon::createFromTimestamp($start, $timezone);
        $end_date = Carbon::createFromTimestamp($end, $timezone);
        $today_start = 0;
        $today_end = 0;
        $summary_start = 0;
        $summary_end = 0;
        $results = [];
        $temp_new = 0;
        $temp_repeat = 0;

        /**
         * get the start of day for head and set correct timezone for head_start and head_end
         */
        $initial_start = $start_date->startOfDay()->format('U');

        /**
         * filter on the user's primary_venue_id
         */
        $venue_filter = $currentUser->primary_venue_id;

        /**
         * prepare the query using a "random" PDO connection
         */
        $query = new TrackingDailyStatsVenueUniqueDeviceUuids;
        $db = $query->getConnection()->getPdo();

        /**
         * analyse the range requested and whether a section of it is in today
         * - stats for today require a query against the live probe_request table
         * - remaining range queries utilise the daily_stats_venue_unique_device_uuids_per_hour or
         *   the daily_stats_zone_unique_device_uuids_per_hour tables (when zone filtering is required)
         */
        if ($end_date->isToday()) {
            /**
             * part of the range is within today
             */
            if ($start_date->gte(Carbon::now($timezone)->startOfDay())) {
                /**
                 * full range is within today so no summary table query required
                 */
                $today_start = $start;
                $today_end   = $end;
            } else {
                /**
                 * part of range (from start to start of today) requires a summary table query,
                 * start of today up to end requires query on probe request table
                 */
                $today_start   = Carbon::now($timezone)->startOfDay()->format('U');
                $today_end     = $end;
                $summary_start = $start;
                $summary_end   = $today_start;
            }
        } else {
            /**
             * no part of range in today so we only query summary table
             */
            $summary_start = $start;
            $summary_end   = $end;
        }

        if ($summary_start > 0 && $summary_end > 0) {
            /**
             * check whether the user is allowed to view full venue stats or not
             * and act accordingly
             */
            if ($currentUser->full_venue_view_allowed == 1) {
                /**
                 * user is allowed to view full venue stats
                 * create the prepared statement
                 */
                $visitor_counts = $db->prepare(
                    'SELECT COUNT(CASE WHEN is_repeat = 0 THEN 1 END) AS new_visitors,
                            COUNT(CASE WHEN is_repeat = 1 THEN 1 END) AS repeat_visitors
                       FROM
                            (
                                SELECT DISTINCT(device_uuid),
                                       is_repeat
                                  FROM
                                        (
                                          SELECT device_uuid,
                                                 (day_epoch + (3600*hour)) AS hour_epoch,
                                                 is_repeat
                                            FROM tracking_daily_stats_venue_unique_device_uuids_per_hour
                                       PARTITION (p' . $venue_filter . ')
                                           WHERE day_epoch >= :initial_start
                                             AND day_epoch < :end_1
                                        ) AS temp1
                                 WHERE hour_epoch >= :start
                                   AND hour_epoch < :end_2
                            ) AS temp2'
                );
            } else {
                /**
                 * user is NOT allowed to view full venue stats
                 * - filter on the user's allowed zones AND on the user's primary_venue_id
                 * - get the allowed zones, then create an array which only contains their ids
                 */
                $allowed_zones      = collect($currentUser->zones);
                $allowed_zone_ids   = [];

                foreach ($allowed_zones as $zone) {
                    $allowed_zone_ids[] = $zone->id;
                }

                /**
                 * create a string containing zone ids, comma seperated, to insert into the PDO statement
                 */
                $ids_string = implode(',', $allowed_zone_ids);

                /**
                 * create the prepared statement
                 */
                $visitor_counts = $db->prepare(
                    'SELECT COUNT(CASE WHEN is_repeat = 0 THEN 1 END) AS new_visitors,
                            COUNT(CASE WHEN is_repeat = 1 THEN 1 END) AS repeat_visitors
                       FROM
                            (
                                SELECT DISTINCT(device_uuid),
                                       is_repeat
                                  FROM
                                        (
                                          SELECT device_uuid,
                                                 (day_epoch + (3600*hour)) AS hour_epoch,
                                                 is_repeat
                                            FROM tracking_daily_stats_zone_unique_device_uuids_per_hour
                                       PARTITION (p' . $venue_filter . ')
                                           WHERE day_epoch >= :initial_start
                                             AND day_epoch < :end_1
                                             AND zone_id IN (' . $ids_string . ')
                                        ) AS temp1
                                 WHERE hour_epoch >= :start
                                   AND hour_epoch < :end_2
                            ) AS temp2'
                );
            }

            /**
             * bind the parameters to the selected query
             */
            $visitor_counts->bindParam(':initial_start', $initial_start);
            $visitor_counts->bindParam(':start', $summary_start);
            $visitor_counts->bindParam(':end_1', $summary_end);
            $visitor_counts->bindParam(':end_2', $summary_end);

            /**
             * execute the query for total visitors
             */
            $visitor_counts->execute();
            $visitor_count_results = $visitor_counts->fetch();

            $temp_new    += $visitor_count_results['new_visitors'];
            $temp_repeat += $visitor_count_results['repeat_visitors'];
        }

        /**
         * the queries for the section of the requested range which falls into today
         */
        if($today_start > 0 && $today_end > 0) {
            /**
             * check whether the user is allowed to view full venue stats or not
             * and act accordingly
             */
            if ($currentUser->full_venue_view_allowed == 1) {
                /**
                 * user is allowed to view full venue stats
                 * create the prepared statement with the vars available for today's returning visitors
                 */
                $returning_visitors_today = $db->prepare(
                    'SELECT COUNT(DISTINCT probe_request.device_uuid) AS `returning`
                       FROM probe_request
                  PARTITION (p' . $venue_filter . ')
                 INNER JOIN tracking_daily_stats_venue_device_uuid_cache AS repeat_visitors
                         ON probe_request.device_uuid = repeat_visitors.device_uuid
                      WHERE repeat_visitors.first_seen < :start_1
                        AND ts > :start_2
                        AND ts < :end'
                );
            } else {
                /**
                 * user is NOT allowed to view full venue stats
                 * - filter on the user's allowed zones AND on the user's primary_venue_id
                 * - get the allowed zones, then create an array which only contains the ids
                 */
                $allowed_zones = collect($currentUser->zones);
                $allowed_drones_ids = [];
                foreach ($allowed_zones as $allowed_zone) {
                    foreach ($allowed_zone->drones as $drone) {
                        $allowed_drones_ids[] = $drone->id;
                    }
                }

                /**
                 * create a string containing drone ids, comma seperated, to insert into the PDO statement
                 */
                $ids_string = implode(',', $allowed_drones_ids);

                /**
                 * execute the prepared statement with the vars available for today's returning visitors
                 */
                $returning_visitors_today = $db->prepare(
                    'SELECT COUNT(DISTINCT probe_request.device_uuid) AS `returning`
                       FROM probe_request
                  PARTITION (p' . $venue_filter . ')
                 INNER JOIN tracking_daily_stats_venue_device_uuid_cache AS repeat_visitors
                         ON probe_request.device_uuid = repeat_visitors.device_uuid
                      WHERE repeat_visitors.first_seen < :start_1
                        AND ts > :start_2
                        AND ts < :end
                        AND drone_id IN (' . $ids_string . ')'
                );
            }

            /**
             * bind the parameters for the prepared statement
             */
            $returning_visitors_today->bindParam(':start_1', $today_start);
            $returning_visitors_today->bindParam(':start_2', $today_start);
            $returning_visitors_today->bindParam(':end', $today_end);

            /**
             * execute the query
             */
            $returning_visitors_today->execute();
            $returning_visitors_today_results = $returning_visitors_today->fetch();

            /**
             * check whether the user is allowed to view full venue stats or not
             * and act accordingly
             */
            if ($currentUser->full_venue_view_allowed == 1) {
                /**
                 * user is allowed to view full venue stats
                 * create the prepared statement with the vars available for today's total visitors
                 */
                $total_visitors_today = $db->prepare(
                    'SELECT COUNT(DISTINCT device_uuid) AS total
                       FROM probe_request
                  PARTITION (p' . $venue_filter . ')
                      WHERE ts > :start
                        AND ts < :end'
                );
            } else {
                /**
                 * user is NOT allowed to view full venue stats
                 * - filter on the user's allowed zones AND on the user's primary_venue_id
                 * - get the allowed zones, then create an array which only contains the ids
                 */
                $allowed_zones      = collect($currentUser->zones);
                $allowed_drones_ids = [];
                foreach ($allowed_zones as $allowed_zone) {
                    foreach ($allowed_zone->drones as $drone) {
                        $allowed_drones_ids[] = $drone->id;
                    }
                }

                /**
                 * create a string containing drone ids, comma seperated, to insert into the PDO statement
                 */
                $ids_string = implode(',', $allowed_drones_ids);

                /**
                 * execute the prepared statement with the vars available for today's total visitors
                 */
                $total_visitors_today = $db->prepare(
                    'SELECT COUNT(DISTINCT device_uuid) AS total
                       FROM probe_request
                  PARTITION (p' . $venue_filter . ')
                      WHERE ts > :start
                        AND ts < :end
                        AND drone_id IN (' . $ids_string . ')'
                );
            }

            /**
             * bind the parameters for the prepared statement
             */
            $total_visitors_today->bindParam(':start', $today_start);
            $total_visitors_today->bindParam(':end', $today_end);

            /**
             * execute the query for today's total visitors
             */
            $total_visitors_today->execute();
            $total_visitors_today_results = $total_visitors_today->fetch();

            $temp_new    += $total_visitors_today_results['total'] - $returning_visitors_today_results['returning'];
            $temp_repeat += $returning_visitors_today_results['returning'];
        }

        $results['new']    = [$temp_new];
        $results['repeat'] = [$temp_repeat];

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listVisitorHeatmap(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * filter on the user's primary_venue_id and get the user's timezone
         */
        $venue_filter = $currentUser->primary_venue_id;
        $timezone = $currentUser->primaryVenue->time_zone;

        /**
         * GET THE HOURLY USERS PER ZONE FOR THE HEATMAP ITSELF ******************************
         */

        /**
         * initialise several variables
         */
        $results = [];

        /*************************************************************************************
         * WORK ON SELECTED RANGE FROM HERE
         * - get the desired time zone
         * - set $start, $end
         *
         * TODO:
         * - get daily stats for body
         * - determine whether tail is today or not
         *  - yes: we get data from probe_requests
         *  - no: we get hourly stats for it
         * - get hourly stats for head
         *************************************************************************************/
        $start = floor($args['start']/1000);
        $end = floor($args['end']/1000);
        $this->determineHeadBodyTail($start, $end, $timezone);

        /**
         * execute query for HEAD of selected range
         */
        if($this->head_length > 0) {
            /**
             * get the start of day for head and get the hour of head_start and head_end
             */
            $head_start_hour = $this->head_start->format('G');
            $head_end_hour = $this->head_end->subSecond()->format('G');

            /**
             * check whether the user is allowed to view full venue stats or not
             * and act accordingly
             */
            if ($currentUser->full_venue_view_allowed == 1) {
                /**
                 * user is allowed to view full venue stats
                 * prepare and execute the query for BODY
                 */
                $headVisitors = new TrackingDailyStatsZoneHourlyVisitors;
                $initialresultsHeadVisitors = $headVisitors->with('zone')
                    ->select('day_epoch', 'hour', 'zone_id', 'visitors_total')
                    ->where('day_epoch', $this->head_day_epoch)
                    ->where('hour', '<=', $head_end_hour)
                    ->where('hour', '>=', $head_start_hour)
                    ->where('venue_id', $venue_filter)
                    ->groupBy('day_epoch', 'hour', 'zone_id')
                    ->orderBy('day_epoch', 'ASC')
                    ->orderBy('hour', 'ASC')
                    ->get();
            } else {
                /**
                 * user is NOT allowed to view full venue stats
                 * filter on the user's allowed zones AND on the user's primary_venue_id
                 *
                 * get the allowed zones, then create an array which only contains the ids
                 */
                $allowed_zones = collect($currentUser->zones);
                $allowed_zones_ids = [];
                foreach ($allowed_zones as $allowed_zone) {
                    $allowed_zones_ids[] = $allowed_zone->id;
                }

                /**
                 * prepare and execute the query with venue AND zones filtering
                 */
                $headVisitors = new TrackingDailyStatsZoneHourlyVisitors;
                $initialresultsHeadVisitors = $headVisitors->with('zone')
                    ->select('day_epoch', 'hour', 'zone_id', 'visitors_total')
                    ->where('day_epoch', $this->head_day_epoch)
                    ->where('hour', '<=', $head_end_hour)
                    ->where('hour', '>=', $head_start_hour)
                    ->where('venue_id', $venue_filter)
                    ->whereIn('zone_id', $allowed_zones_ids)
                    ->groupBy('day_epoch', 'hour', 'zone_id')
                    ->orderBy('day_epoch', 'ASC')
                    ->orderBy('hour', 'ASC')
                    ->get();
            }
        }

        /**
         * execute query for BODY of selected range
         */
        if($this->body_length > 0) {
            /**
             * get body_start and body_end formatted as epoch
             */
            $body_start = $this->body_start->format('U');
            $body_end = $this->body_end->format('U');

            /**
             * check whether the user is allowed to view full venue stats or not
             * and act accordingly
             */
            if ($currentUser->full_venue_view_allowed == 1) {
                /**
                 * user is allowed to view full venue stats
                 * prepare and execute the query for BODY
                 */
                $visitorsQuery = new TrackingDailyStatsZoneHourlyVisitors;
                $initialresultsBodyVisitors = $visitorsQuery->with('zone')
                    ->select('day_epoch', 'hour', 'zone_id', 'visitors_total')
                    ->where('day_epoch', '>=', $body_start)
                    ->where('day_epoch', '<', $body_end)
                    ->where('venue_id', $venue_filter)
                    ->groupBy('day_epoch', 'hour', 'zone_id')
                    ->orderBy('day_epoch', 'ASC')
                    ->orderBy('hour', 'ASC')
                    ->get();
            } else {
                /**
                 * user is NOT allowed to view full venue stats
                 * filter on the user's allowed zones AND on the user's primary_venue_id
                 *
                 * get the allowed zones, then create an array which only contains the ids
                 */
                $allowed_zones = collect($currentUser->zones);
                $allowed_zones_ids = [];
                foreach ($allowed_zones as $allowed_zone) {
                    $allowed_zones_ids[] = $allowed_zone->id;
                }

                /**
                 * prepare and execute the query for BODY with venue AND zones filtering
                 */
                $visitorsQuery = new TrackingDailyStatsZoneHourlyVisitors;
                $initialresultsBodyVisitors = $visitorsQuery->with('zone')
                    ->select('day_epoch', 'hour', 'zone_id', 'visitors_total')
                    ->where('day_epoch', '>=', $body_start)
                    ->where('day_epoch', '<', $body_end)
                    ->where('venue_id', $venue_filter)
                    ->whereIn('zone_id', $allowed_zones_ids)
                    ->groupBy('day_epoch', 'hour', 'zone_id')
                    ->orderBy('day_epoch', 'ASC')
                    ->orderBy('hour', 'ASC')
                    ->get();
            }
        }

        /**
         * execute query for TAIL of selected range
         */
        if($this->tail_length > 0) {
            /**
             * we first determine whether TAIL is in today or not
             */
            if($this->tail_in_today) {
                /**
                 * get body_start and body_end formatted as epoch
                 */
                $tail_start = $this->tail_start->format('U');
                $tail_end = $this->tail_end->format('U');

                /**
                 * prepare and execute the query
                 */
                $tailVisitors = new ProbeRequest;
                $db = $tailVisitors->getConnection()->getPdo();

                /**
                 * check whether the user is allowed to view full venue stats or not
                 * and act accordingly
                 */
                if ($currentUser->full_venue_view_allowed == 1) {
                    /**
                     * user is allowed to view full venue stats
                     * create the prepared statement with the vars available for new visitors past 7 days
                     * to get visitor numbers per hour (bucket size of 3600)
                     */
                    $tail_visitors = $db->prepare(
                        'SELECT temp.bucket,
                                zone.id AS zone_id,
                                zone.name,
                                zone.lat,
                                zone.lon,
                                temp.total
                           FROM
                              (
                                  SELECT DISTINCT (ts-MOD(ts, 3600)) AS bucket,
                                         drone_id,
                                         COUNT(DISTINCT device_uuid) AS total
                                    FROM probe_request
                                   WHERE ts BETWEEN :start AND :end
                                     AND probe_request.venue_id = :venue_id
                                GROUP BY bucket, drone_id
                              ) AS temp
                     INNER JOIN drone ON temp.drone_id = drone.id
                     INNER JOIN zone ON drone.zone_id = zone.id
                       ORDER BY temp.bucket ASC'
                    );

                } else {
                    /**
                     * user is NOT allowed to view full venue stats
                     * filter on the user's allowed zones AND on the user's primary_venue_id
                     *
                     * get the allowed zones, then create an array which only contains the ids
                     */
                    $allowed_zones = collect($currentUser->zones);
                    $allowed_drones_ids = [];
                    foreach ($allowed_zones as $allowed_zone) {
                        foreach ($allowed_zone->drones as $drone) {
                            $allowed_drones_ids[] = $drone->id;
                        }
                    }

                    /**
                     * create a string containing drone ids, comma seperated, to insert into the PDO statement
                     */
                    $ids_string = implode(',', $allowed_drones_ids);

                    /**
                     * execute the prepared statement with the vars available for new visitors past 7 days
                     * to get visitor numbers per hour (bucket size of 3600)
                     */
                    $tail_visitors = $db->prepare(
                        'SELECT temp.bucket,
                                zone.id AS zone_id,
                                zone.name,
                                zone.lat,
                                zone.lon,
                                temp.total
                           FROM
                              (
                                  SELECT DISTINCT (ts-MOD(ts, 3600)) AS bucket,
                                         drone_id,
                                         COUNT(DISTINCT device_uuid) AS total
                                    FROM probe_request
                                   WHERE ts BETWEEN :start AND :end
                                     AND probe_request.venue_id = :venue_id
                                     AND probe_request.drone_id IN (' . $ids_string . ')
                                GROUP BY bucket, drone_id
                              ) AS temp
                     INNER JOIN drone ON temp.drone_id = drone.id
                     INNER JOIN zone ON drone.zone_id = zone.id
                       ORDER BY temp.bucket ASC'
                    );
                }

                /**
                 * bind the parameters for the prepared statement
                 * in this case: with PDO prepared statements we couldn't use the same parameter twice...
                 */
                $tail_visitors->bindParam(':start', $tail_start);
                $tail_visitors->bindParam(':end', $tail_end);
                $tail_visitors->bindParam(':venue_id', $venue_filter);

                $tail_visitors->execute();
                $initialresultsTailVisitors = $tail_visitors->fetchAll();
            } else {
                /**
                 * TAIL is not in today so we get hourly stats for TAIL
                 * - get the start of day for tail and get the hour of tail_start and tail_end
                 */
                $tail_start_hour = $this->tail_start->format('G');
                $tail_end_hour = $this->tail_end->format('G');

                /**
                 * check whether the user is allowed to view full venue stats or not
                 * and act accordingly
                 */
                if ($currentUser->full_venue_view_allowed == 1) {
                    /**
                     * user is allowed to view full venue stats
                     * prepare and execute the query
                     */
                    $tailVisitors = new TrackingDailyStatsZoneHourlyVisitors;
                    $initialresultsTailVisitors = $tailVisitors->with('zone')
                        ->select('day_epoch', 'hour', 'zone_id', 'visitors_total')
                        ->where('day_epoch', $this->tail_day_epoch)
                        ->where('hour', '<', $tail_end_hour)
                        ->where('hour', '>=', $tail_start_hour)
                        ->where('venue_id', $venue_filter)
                        ->groupBy('day_epoch', 'hour', 'zone_id')
                        ->orderBy('day_epoch', 'ASC')
                        ->orderBy('hour', 'ASC')
                        ->get();
                } else {
                    /**
                     * user is NOT allowed to view full venue stats
                     * filter on the user's allowed zones AND on the user's primary_venue_id
                     *
                     * get the allowed zones, then create an array which only contains the ids
                     */
                    $allowed_zones = collect($currentUser->zones);
                    $allowed_zones_ids = [];
                    foreach ($allowed_zones as $allowed_zone) {
                        $allowed_zones_ids[] = $allowed_zone->id;
                    }

                    /**
                     * prepare and execute the query with venue AND zones filtering
                     */
                    $tailVisitors = new TrackingDailyStatsZoneHourlyVisitors;
                    $initialresultsTailVisitors = $tailVisitors->with('zone')
                        ->select('day_epoch', 'hour', 'zone_id', 'visitors_total')
                        ->where('day_epoch', $this->tail_day_epoch)
                        ->where('hour', '<', $tail_end_hour)
                        ->where('hour', '>=', $tail_start_hour)
                        ->where('venue_id', $venue_filter)
                        ->whereIn('zone_id', $allowed_zones_ids)
                        ->groupBy('day_epoch', 'hour', 'zone_id')
                        ->orderBy('day_epoch', 'ASC')
                        ->orderBy('hour', 'ASC')
                        ->get();
                }
            }
        }

        /**
         * merge the arrays from head/body/tail results into a single array ($tempresults)
         *
         * TODO:
         * initially populate the $tempresults array with objects for each zone for each hour with a default count of 0
         */
        $zone_key = 0;
        $total_visitors = 0;
        $tempresults = [];

        if($this->head_length > 0) {
            foreach ($initialresultsHeadVisitors as $item) {
                $zone_key += 1;
                $total_visitors += $item['visitors_total'];

                $tempresults[]= [
                    'zone_key' => $zone_key,
                    'zone_id' => $item['zone']->id,
                    'zone_name' => $item['zone']->name,
                    'lat' => $item['zone']->lat,
                    'lon' => $item['zone']->lon,
                    'timestamp' => ($item['day_epoch'] + ($item['hour'])*3600)*1000,
                    'count' => $item['visitors_total']
                ];
            }
        }

        if($this->body_length > 0) {
            foreach ($initialresultsBodyVisitors as $item) {
                $zone_key += 1;
                $total_visitors += $item['visitors_total'];

                $tempresults[]= [
                    'zone_key' => $zone_key,
                    'zone_id' => $item['zone']->id,
                    'zone_name' => $item['zone']->name,
                    'lat' => $item['zone']->lat,
                    'lon' => $item['zone']->lon,
                    'timestamp' => ($item['day_epoch'] + ($item['hour'])*3600)*1000,
                    'count' => $item['visitors_total']
                ];
            }
        }

        if($this->tail_length > 0) {
            if($this->tail_in_today) {
                foreach ($initialresultsTailVisitors as $item) {
                    $zone_key += 1;
                    $total_visitors += $item['total'];

                    $tempresults[]= [
                        'zone_key' => $zone_key,
                        'zone_id' => $item['zone_id'],
                        'zone_name' => $item['name'],
                        'lat' => $item['lat'],
                        'lon' => $item['lon'],
                        'timestamp' => $item['bucket']*1000,
                        'count' => $item['total']
                    ];
                }
            } else {
                foreach ($initialresultsTailVisitors as $item) {
                    $zone_key += 1;
                    $total_visitors += $item['visitors_total'];

                    $tempresults[]= [
                        'zone_key' => $zone_key,
                        'zone_id' => $item['zone']->id,
                        'zone_name' => $item['zone']->name,
                        'lat' => $item['zone']->lat,
                        'lon' => $item['zone']->lon,
                        'timestamp' => ($item['day_epoch'] + ($item['hour'])*3600)*1000,
                        'count' => $item['visitors_total']
                    ];
                }
            }
        }

        /**
         * format the heatmap output in the correct required format with the average visitor count per data point
         * (average visitor count = total visitors divided by the number of data points)
         */
        $heatmap_results = [
            'min' => 0,
            'max' => round($total_visitors/($zone_key > 0 ? $zone_key : 1 )),
            'data' => $tempresults
        ];

        /**
         * GET THE HOURLY USERS FOR THIS VENUE FOR THE TOP CHART *****************************
         */

        /**
         * execute query for HEAD of selected range
         */
        if($this->head_length > 0) {
            /**
             * check whether the user is allowed to view full venue stats or not
             * and act accordingly
             */
            if ($currentUser->full_venue_view_allowed == 1) {
                /**
                 * user is allowed to view full venue stats
                 * prepare and execute the query for HEAD
                 */
                $headVisitors = new TrackingDailyStatsVenueHourlyVisitors;
                $initialresultsHeadVisitorsChart = $headVisitors->select('day_epoch', 'hour', 'visitors_total')
                    ->where('day_epoch', $this->head_day_epoch)
                    ->where('hour', '<=', $head_end_hour)
                    ->where('hour', '>=', $head_start_hour)
                    ->where('venue_id', $venue_filter)
                    ->groupBy('day_epoch', 'hour')
                    ->orderBy('day_epoch', 'ASC')
                    ->orderBy('hour', 'ASC')
                    ->get();
            } else {
                /**
                 * user is NOT allowed to view full venue stats
                 * filter on the user's allowed zones AND on the user's primary_venue_id
                 *
                 * get the allowed zones, then create an array which only contains the ids
                 */
                $allowed_zones = collect($currentUser->zones);
                $allowed_zones_ids = [];
                foreach ($allowed_zones as $allowed_zone) {
                    $allowed_zones_ids[] = $allowed_zone->id;
                }

                /**
                 * prepare and execute the query for HEAD with venue AND zones filtering
                 */
                $headVisitors = new TrackingDailyStatsZoneHourlyVisitors;
                $initialresultsHeadVisitorsChart = $headVisitors->select('day_epoch', 'hour', 'visitors_total')
                    ->where('day_epoch', $this->head_day_epoch)
                    ->where('hour', '<=', $head_end_hour)
                    ->where('hour', '>=', $head_start_hour)
                    ->where('venue_id', $venue_filter)
                    ->whereIn('zone_id', $allowed_zones_ids)
                    ->groupBy('day_epoch', 'hour')
                    ->orderBy('day_epoch', 'ASC')
                    ->orderBy('hour', 'ASC')
                    ->get();
            }
        }

        /**
         * execute query for BODY of selected range
         */
        if($this->body_length > 0) {
            /**
             * check whether the user is allowed to view full venue stats or not
             * and act accordingly
             */
            if ($currentUser->full_venue_view_allowed == 1) {
                /**
                 * user is allowed to view full venue stats
                 * prepare and execute the query for BODY
                 */
                $visitorsQuery = new TrackingDailyStatsVenueHourlyVisitors;
                $initialresultsBodyVisitorsChart = $visitorsQuery->select('day_epoch', 'hour', 'visitors_total')
                    ->where('day_epoch', '>=', $body_start)
                    ->where('day_epoch', '<', $body_end)
                    ->where('venue_id', $venue_filter)
                    ->groupBy('day_epoch', 'hour')
                    ->orderBy('day_epoch', 'ASC')
                    ->orderBy('hour', 'ASC')
                    ->get();
            } else {
                /**
                 * user is NOT allowed to view full venue stats
                 * filter on the user's allowed zones AND on the user's primary_venue_id
                 *
                 * get the allowed zones, then create an array which only contains the ids
                 */
                $allowed_zones = collect($currentUser->zones);
                $allowed_zones_ids = [];
                foreach ($allowed_zones as $allowed_zone) {
                    $allowed_zones_ids[] = $allowed_zone->id;
                }

                /**
                 * prepare and execute the query for BODY with venue AND zones filtering
                 */
                $visitorsQuery = new TrackingDailyStatsZoneHourlyVisitors;
                $initialresultsBodyVisitorsChart = $visitorsQuery->select('day_epoch', 'hour', 'visitors_total')
                    ->where('day_epoch', '>=', $body_start)
                    ->where('day_epoch', '<', $body_end)
                    ->where('venue_id', $venue_filter)
                    ->whereIn('zone_id', $allowed_zones_ids)
                    ->groupBy('day_epoch', 'hour')
                    ->orderBy('day_epoch', 'ASC')
                    ->orderBy('hour', 'ASC')
                    ->get();
            }
        }

        /**
         * execute query for TAIL of selected range
         */
        if($this->tail_length > 0) {
            /**
             * we first determine whether TAIL is in today or not
             */
            if($this->tail_in_today) {
                /**
                 * prepare and execute the query
                 */
                $tailVisitors = new ProbeRequest;
                $db = $tailVisitors->getConnection()->getPdo();

                /**
                 * check whether the user is allowed to view full venue stats or not
                 * and act accordingly
                 */
                if ($currentUser->full_venue_view_allowed == 1) {
                    /**
                     * user is allowed to view full venue stats
                     * create the prepared statement with the vars available for new visitors past 7 days
                     * to get visitor numbers per hour (bucket size of 3600)
                     */
                    $tail_visitors = $db->prepare(
                        'SELECT DISTINCT (ts-MOD(ts, 3600)) AS bucket,
                                COUNT(DISTINCT device_uuid) AS total
                           FROM probe_request
                          WHERE ts BETWEEN :start AND :end
                            AND probe_request.venue_id = :venue_id
                       GROUP BY bucket
                       ORDER BY bucket ASC'
                    );
                } else {
                    /**
                     * user is NOT allowed to view full venue stats
                     * filter on the user's allowed zones AND on the user's primary_venue_id
                     *
                     * get the allowed zones, then create an array which only contains the ids
                     */
                    $allowed_zones = collect($currentUser->zones);
                    $allowed_drones_ids = [];
                    foreach ($allowed_zones as $allowed_zone) {
                        foreach ($allowed_zone->drones as $drone) {
                            $allowed_drones_ids[] = $drone->id;
                        }
                    }

                    /**
                     * create a string containing drone ids, comma seperated, to insert into the PDO statement
                     */
                    $ids_string = implode(',', $allowed_drones_ids);

                    /**
                     * execute the prepared statement with the vars available for new visitors past 7 days
                     * to get visitor numbers per hour (bucket size of 3600)
                     */
                    $tail_visitors = $db->prepare(
                        'SELECT DISTINCT (ts-MOD(ts, 3600)) AS bucket,
                                COUNT(DISTINCT device_uuid) AS total
                           FROM probe_request
                          WHERE ts BETWEEN :start AND :end
                            AND probe_request.venue_id = :venue_id
                            AND drone_id IN (' . $ids_string . ')
                       GROUP BY bucket
                       ORDER BY bucket ASC'
                    );
                }

                /*
                bind the parameters for the prepared statement
                in this case: with PDO prepared statements we couldn't use the same parameter twice...
                 */
                $tail_visitors->bindParam(':start', $tail_start);
                $tail_visitors->bindParam(':end', $tail_end);
                $tail_visitors->bindParam(':venue_id', $venue_filter);

                $tail_visitors->execute();
                $initialresultsTailVisitorsChart = $tail_visitors->fetchAll();
            } else {
                /**
                 * check whether the user is allowed to view full venue stats or not
                 * and act accordingly
                 */
                if ($currentUser->full_venue_view_allowed == 1) {
                    /**
                     * user is allowed to view full venue stats
                     * prepare and execute the query
                     */
                    $tailVisitors = new TrackingDailyStatsVenueHourlyVisitors;
                    $initialresultsTailVisitorsChart = $tailVisitors->select('day_epoch', 'hour', 'visitors_total')
                        ->where('day_epoch', $this->tail_day_epoch)
                        ->where('hour', '<', $tail_end_hour)
                        ->where('hour', '>=', $tail_start_hour)
                        ->where('venue_id', $venue_filter)
                        ->groupBy('day_epoch', 'hour')
                        ->orderBy('day_epoch', 'ASC')
                        ->orderBy('hour', 'ASC')
                        ->get();
                } else {
                    /**
                     * user is NOT allowed to view full venue stats
                     * filter on the user's allowed zones AND on the user's primary_venue_id
                     *
                     * get the allowed zones, then create an array which only contains the ids
                     */
                    $allowed_zones = collect($currentUser->zones);
                    $allowed_zones_ids = [];
                    foreach ($allowed_zones as $allowed_zone) {
                        $allowed_zones_ids[] = $allowed_zone->id;
                    }

                    /**
                     * prepare and execute the query with venue AND zones filtering
                     */
                    $tailVisitors = new TrackingDailyStatsZoneHourlyVisitors;
                    $initialresultsTailVisitorsChart = $tailVisitors->select('day_epoch', 'hour', 'visitors_total')
                        ->where('day_epoch', $this->tail_day_epoch)
                        ->where('hour', '<', $tail_end_hour)
                        ->where('hour', '>=', $tail_start_hour)
                        ->where('venue_id', $venue_filter)
                        ->whereIn('zone_id', $allowed_zones_ids)
                        ->groupBy('day_epoch', 'hour')
                        ->orderBy('day_epoch', 'ASC')
                        ->orderBy('hour', 'ASC')
                        ->get();
                }
            }
        }

        /**
         * merge the arrays from head/body/tail results for the chart into a single array ($temp_chart_results)
         */
        $temp_chart_results = [];

        if($this->head_length > 0) {
            foreach ($initialresultsHeadVisitorsChart as $item) {
                $temp_chart_results[]= [($item['day_epoch'] + ($item['hour'])*3600)*1000, $item['visitors_total']];
            }
        }

        if($this->body_length > 0) {
            foreach ($initialresultsBodyVisitorsChart as $item) {
                $temp_chart_results[]= [($item['day_epoch'] + ($item['hour'])*3600)*1000, $item['visitors_total']];
            }
        }

        if($this->tail_length > 0) {
            if($this->tail_in_today) {
                foreach ($initialresultsTailVisitorsChart as $item) {
                    $temp_chart_results[]= [$item['bucket']*1000, $item['total']];
                }
            } else {
                foreach ($initialresultsTailVisitorsChart as $item) {
                    $temp_chart_results[]= [($item['day_epoch'] + ($item['hour'])*3600)*1000, $item['visitors_total']];
                }
            }
        }

        /**
         * check whether the user is allowed to view full venue stats or not
         * and act accordingly
         */
        if ($currentUser->full_venue_view_allowed == 1) {
            /**
             * user is allowed to view full venue stats
             * prepare and execute the query
             */
            $getZones = new Zone;
            $activeZones = $getZones->where('venue_id', $venue_filter)->where('tracking_zone', 1)
                ->orderBy('id')
                ->get();
        } else {
            /**
             * user is NOT allowed to view full venue stats
             * filter on the user's allowed zones AND on the user's primary_venue_id
             *
             * get the allowed zones, then create an array which only contains the ids
             */
            $allowed_zones = collect($currentUser->zones);
            $allowed_zones_ids = [];
            foreach ($allowed_zones as $allowed_zone) {
                $allowed_zones_ids[] = $allowed_zone->id;
            }

            /**
             * prepare and execute the query with venue AND zones filtering
             */
            $getZones = new Zone;
            $activeZones = $getZones->where('venue_id', $venue_filter)->where('tracking_zone', 1)
                ->whereIn('id', $allowed_zones_ids)
                ->orderBy('id')
                ->get();
        }

        $results[] = $temp_chart_results;
        $results[] = $heatmap_results;
        $results[] = $activeZones;

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listVisitorJourneyReport(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * filter on the user's primary_venue_id and get the user's timezone
         * and adapt start/end to required format/unit
         */
        $venue_filter = $currentUser->primary_venue_id;
        $timezone = $currentUser->primaryVenue->time_zone;
        $start = floor($args['start']/1000);
        $end = floor($args['end']/1000);

        /**
         * prepare and execute the query
         */
        $sankey_query_connect = new ProbeRequest;
        $db = $sankey_query_connect->getConnection()->getPdo();

        /**
         * prepare the connection and define vars
         * We have to execute the query this way to avoid mysql errors regarding different collations in comparisons
         * (we did not see these errors using the default command-line mysql client)
         */
        $init_query = "SET NAMES utf8,
           @row_number = 0,
           @prev_device_uuid = NULL,
           @curr_device_uuid = NULL";

        $db->query($init_query);

        /**
         * limit the length of routes to report on (max number of steps in a route)
         */
        if ($max_route_length == 0)
            $max_route_length = $currentUser->primaryVenue->sankey_max_route_length;
        else
            $max_route_length = 4;
        

        /**
         * check whether the user is allowed to view full venue stats or not
         * and act accordingly
         */
        if ($currentUser->full_venue_view_allowed == 1) {
            /**
             * user is allowed to view full venue stats
             * create the prepared statement
             */
            $query = $db->query(
                   "SELECT CONCAT(row_number, '__', drone2.name) AS source, CONCAT(row_number + 1, '__', drone1.name) AS  target, COUNT(DISTINCT(device_uuid)) as value
                      FROM
                           (
                              SELECT row_number,
                                     device_uuid,
                                     from_drone_id,
                                     to_drone_id
                                FROM
                                     (
                                     SELECT @curr_device_uuid := device_uuid AS device_uuid,
                                            (@row_number := CASE WHEN @prev_device_uuid = @curr_device_uuid THEN @row_number + 1 ELSE 1 END) AS row_number,
                                            arrival,
                                            venue_id,
                                            from_drone_id,
                                            to_drone_id,
                                            @prev_device_uuid := device_uuid
                                       FROM tracking_daily_stats_venue_visitor_moves_raw
                                      WHERE arrival >= $start
                                        AND arrival < $end
                                        AND venue_id = $venue_filter
                                   ORDER BY device_uuid,
                                            arrival
                                     ) AS temp
                           ) AS temp2
                INNER JOIN drone AS drone1
                        ON temp2.to_drone_id = drone1.id
                INNER JOIN drone AS drone2
                        ON temp2.from_drone_id = drone2.id
                     WHERE row_number <= $max_route_length
                  GROUP BY CONCAT(source, target)
                  ORDER BY row_number, source, value DESC"
            );
        } else {
            /**
             * user is NOT allowed to view full venue stats
             * filter on the user's allowed zones AND on the user's primary_venue_id
             *
             * get the allowed zones, then create an array which only contains the ids
             */
            $allowed_zones = collect($currentUser->zones);
            $allowed_drones_ids = [];
            foreach ($allowed_zones as $allowed_zone) {
                foreach ($allowed_zone->drones as $drone) {
                    $allowed_drones_ids[] = $drone->id;
                }
            }

            /**
             * create a string containing drone ids, comma seperated, to insert into the PDO statement
             */
            $ids_string = implode(',', $allowed_drones_ids);

            /**
             * execute the query
             */
            $query = $db->query(
                   "SELECT CONCAT(row_number, '__', drone2.name) AS source, CONCAT(row_number + 1, '__', drone1.name) AS  target, COUNT(DISTINCT(device_uuid)) as value
                      FROM
                           (
                                SELECT row_number,
                                       device_uuid,
                                       from_drone_id,
                                       to_drone_id,
                                       id
                                FROM
                                     (
                                     SELECT @curr_device_uuid := device_uuid AS device_uuid,
                                            @row_number := CASE WHEN @prev_device_uuid = @curr_device_uuid THEN @row_number+1 ELSE 1 END AS row_number,
                                            arrival,
                                            venue_id,
                                            from_drone_id,
                                            to_drone_id,
                                            @prev_device_uuid := device_uuid,
                                            id
                                       FROM tracking_daily_stats_venue_visitor_moves_raw
                                      WHERE (arrival - travel_time) >= $start
                                        AND arrival < $end
                                        AND venue_id = $venue_filter
                                        AND from_drone_id IN (" . $ids_string . ")
                                        AND to_drone_id IN (" . $ids_string . ")
                                   ORDER BY device_uuid,
                                            arrival
                                      ) AS temp
                           ) AS temp2
                INNER JOIN drone AS drone1 ON temp2.to_drone_id = drone1.id
                INNER JOIN drone AS drone2 ON temp2.from_drone_id = drone2.id
                     WHERE row_number <= $max_route_length
                  GROUP BY CONCAT(source, target)
                  ORDER BY row_number, source, value DESC"
            );
        }

        /**
         * fetch the results
         */
        $temp_results = $query->fetchAll();

        $nodes = [];

        foreach ($temp_results as $result) {
            if(!in_array($result['source'], $nodes)){
                $nodes[] = $result['source'];
            }

            if(!in_array($result['target'], $nodes)){
                $nodes[] = $result['target'];
            }
        }

        $full_nodes = [];

        foreach ($nodes as $key => $value) {
            $full_nodes[] = ['name' => $value, 'id' => $key];
        }

        /**
         * here we replace the node names with the indices from the $full_nodes array
         * NOTE:
         * - If we need to support routes longer than 10 steps, we will need modify the array search
         *   below. Now a search for 1_name probably also returns true for 11_name... Need to check!
         */
        foreach ($temp_results as &$result) {
            $source_key = array_search($result['source'], array_column($full_nodes, 'name'));
            $result['source'] = $source_key;
            $target_key = array_search($result['target'], array_column($full_nodes, 'name'));
            $result['target'] = $target_key;

            unset($result['0']);
            unset($result['1']);
            unset($result['2']);
        }

        $results['nodes'] = $full_nodes;
        $results['links'] = $temp_results;

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function summaryAllDrones(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_all_drones')) {
            throw new NotFoundException($request, $response);
        }

        $venueQuery = new Venue;
        $total = $venueQuery->count();

        /**
         * Get unfiltered, unsorted, unpaginated collection
         */
        $venue_collection = $venueQuery->with('zones.drones.last_activity')->get();
        $venue_collection_filtered = [];

        foreach ($venue_collection as $venue) {
            $last_activity = [];
            $active = 0;
            $inactive = 0;
            $calibrating = 0;
            $online = 0;
            $quiet = 0;
            $offline = 0;

            foreach ($venue->zones as $zone) {
                foreach ($zone->drones as $drone) {
                    $timestamp = $drone->last_activity['timestamp'];
                    $now = date(time());

                    if ($drone->drone_summary == 1) {
                        switch ($drone->state) {
                            case 0:
                                $inactive += 1;
                                break;
                            case 1:
                                $active += 1;
                                if ($timestamp > ($now - 300)) {
                                    $online += 1;
                                }
                                else if ($timestamp > ($now - 3600)) {
                                    $quiet += 1;
                                }
                                else if ($timestamp < ($now - 3600)) {
                                    $offline += 1;
                                }
                                break;
                            case 2:
                                $calibrating += 1;
                                break;
                        }
                    }
                }
            }

            $venue_attribs['id'] = $venue->id;
            $venue_attribs['name'] = $venue->name;
            $venue_attribs['active'] = $active;
            $venue_attribs['inactive'] = $inactive;
            $venue_attribs['calibrating'] = $calibrating;
            $venue_attribs['online'] = $online;
            $venue_attribs['quiet'] = $quiet;
            $venue_attribs['offline'] = $offline;

            $venue_collection_filtered[] = $venue_attribs;
        }

        $total_filtered = count($venue_collection_filtered);

        $results = [
            'count' => $total,
            'rows' => $venue_collection_filtered,
            'count_filtered' => $total_filtered
        ];

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listDrones(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_site_admin')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * filter on the user's primary_venue_id
         */
        $venue_filter = $currentUser->primary_venue_id;

        /**
         * get the user's timezone
         */
        $timezone = $currentUser->primaryVenue->time_zone;

        $droneQuery = new Drone;
        $total = $droneQuery->count();

        /**
         * Get unfiltered, unsorted, unpaginated collection
         * ->with('zone') for eager loading of the zone data
         */
        $drone_collection = $droneQuery->with('zone', 'zone.venue', 'last_activity', 'drone_revision_code')->get();

        /**
         * filter the drones array on the primary_venue_id of the current user
         */
        $drone_collection_filtered = [];
        foreach ($drone_collection as $drone){
            if ($drone->zone_id != 0 && isset($drone->zone)) {
                if($drone->zone->venue_id == $venue_filter) {
                    // get the last health message
                    $last_health_message = $drone->lastHealthMessage();

                    // if found, push the last health message details to the drone object
                    if($last_health_message != NULL) {
                        $drone['last_health_message'] = $last_health_message;
                    }

                    $drone_collection_filtered[] = $drone;
                }
            }
        }

        $total_filtered = count($drone_collection_filtered);

        $results = [
            'count' => $total,
            'rows' => $drone_collection_filtered,
            'count_filtered' => $total_filtered
        ];

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listDronesHealth(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_site_admin')) {
            throw new NotFoundException($request, $response);
        }

        $dronesHealthQuery = new DroneHealth;
        $total = $dronesHealthQuery->count();

        /**
         * Get unfiltered, unsorted, unpaginated collection
         */
        $drones_health_collection = $dronesHealthQuery->where('drone_id', '=', $args['drone_id'])
            ->where('timestamp', '>=', floor($args['start']/1000))
            ->where('timestamp', '<', floor($args['end']/1000))
            ->get();

        $temperatures = [];
        $load_averages_1 = [];
        $load_averages_5 = [];
        $load_averages_15 = [];

        /**
         * process the results to extract the data and push into the correct array format
         */
        foreach ($drones_health_collection as $drone_health) {
            $temperatures[] = [$drone_health['timestamp']*1000, floor($drone_health['temp']/100)/10];
            $averages = explode(':', $drone_health['load_average']);
            $load_averages_1[] = [$drone_health['timestamp']*1000, (float)$averages[0]];
            $load_averages_5[] = [$drone_health['timestamp']*1000, (float)$averages[1]];
            $load_averages_15[] = [$drone_health['timestamp']*1000, (float)$averages[2]];
        }

        $results = [
            'temperatures' => $temperatures,
            'load_averages_1' => $load_averages_1,
            'load_averages_5' => $load_averages_5,
            'load_averages_15' => $load_averages_15
        ];

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listVenueStatsDronesActivity(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_site_admin')) {
            throw new NotFoundException($request, $response);
        }

        $start = floor($args['start']/1000);
        $end = floor($args['end']/1000);
        $results = [];

        /**
         * initiate a connection to the ProbeRequest table
         */
        $dronesQueryConnection = new ProbeRequest;

        /**
         * filter on the user's primary_venue_id
         */
        $venue_filter = $currentUser->primary_venue_id;

        /*
        get the PDO connection directly
        */
        $db = $dronesQueryConnection->getConnection()->getPdo();

        /*
        prepare the statement with the vars available
        */
        $dronesQuery = $db->prepare(
                'SELECT timestamp,
                        visitors,
                        probes,
                        drone.name AS drone
                   FROM (
                         SELECT DISTINCT (ts-MOD(ts,:bucketsize)) AS timestamp,
                                drone_id,
                                COUNT(DISTINCT(device_uuid)) AS visitors,
                                COUNT(id) AS probes
                           FROM probe_request
                          WHERE (ts BETWEEN :start AND :end)
                            AND venue_id = :venue_id
                       GROUP BY timestamp, drone_id, venue_id
                        )
                     AS final
             INNER JOIN drone ON drone_id = drone.id'
        );

        /**
         * bind the parameters for the prepared statement
         */
        $dronesQuery->bindParam(':start', $start);
        $dronesQuery->bindParam(':end', $end);
        $dronesQuery->bindParam(':bucketsize', $args['bucketsize']);
        $dronesQuery->bindParam(':venue_id', $venue_filter);

        /*
        execute the query using PDO directly
        */
        $dronesQuery->execute();
        $initialresults = $dronesQuery->fetchAll();

        /**
         * process the results
         */
        foreach ($initialresults as $initialresult) {
            $results[0][$initialresult['drone']][] = [(int)$initialresult['timestamp']*1000, (int)$initialresult['probes']];
            $results[1][$initialresult['drone']][] = [(int)$initialresult['timestamp']*1000, (int)$initialresult['visitors']];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listWhitelistEntries(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_site_admin')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * Get unfiltered, unsorted, unpaginated collection
         */
        $whitelistQuery = new Whitelist;
        $total = $whitelistQuery->count();

        /**
         * Get whitelist entries filtered by the primary_venue_id of logged in user
         */
        $whitelist_collection = $whitelistQuery->with('device_vendor')
            ->where('venue_id', $currentUser->primary_venue_id)
            ->orderBy('updated_at', 'desc')
            ->get();

        $total_filtered = count($whitelist_collection);

        $results = [
            'count' => $total,
            'rows' => $whitelist_collection->values()->toArray(),
            'count_filtered' => $total_filtered
        ];

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listWhitelistCandidates(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_site_admin')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * get user's primary venue id to use as filter
         */
        $venue_filter = $currentUser->primary_venue_id;

        /**
         * get the user's timezone
         * - check across past 24 hours
         */
        $timezone = $currentUser->primaryVenue->time_zone;
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
            ->where('venue_id', $venue_filter)
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

        $total_filtered = count($whitelist_candidate_collection_filtered);

        $results = [
            'rows' => $whitelist_candidate_collection_filtered,
            'count_filtered' => $total_filtered
        ];

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listDronesAll(Request $request, Response $response, $args)
    {
        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_site_admin')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * get the user's timezone
         */
        $timezone = $currentUser->primaryVenue->time_zone;

        $droneQuery = new Drone;
        $total = $droneQuery->count();

        /**
         * Get unfiltered, unsorted, unpaginated collection
         * ->with('zone') for eager loading of the zone data
         */
        $drone_collection = $droneQuery->with('zone', 'zone.venue', 'last_activity', 'drone_revision_code')->get();

        /**
         * process the drones array
         */
        $drone_collection_filtered = [];
        foreach ($drone_collection as $drone){
            // get the last health message
            $last_health_message = $drone->lastHealthMessage();

            // if found, push the last health message details to the drone object
            if($last_health_message != NULL) {
                $drone['last_health_message'] = $last_health_message;
            }

            $drone_collection_filtered[] = $drone;
        }

        $total_filtered = count($drone_collection_filtered);

        $results = [
            'count' => $total,
            'rows' => $drone_collection,
            'count_filtered' => $total_filtered
        ];

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listTotalVisitorsThisWeek(Request $request, Response $response, $args)
    {
        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $start = Carbon::now()->subDays(7)->startOfDay()->timestamp;
        $end = Carbon::now()->startOfDay()->timestamp;

        $visitorQuery = new TrackingDailyStatsVenueVisitors;
        $total = $visitorQuery->count();

        $visitotData = $visitorQuery->selectRaw('SUM(visitors_total) AS total')
            ->where('day_epoch', '>=', $start)
            ->where('day_epoch', '<', $end)
            ->first();

        $total_filtered = count($visitotData);

        $results = [
            'count' => $total,
            'total' => (int)$visitotData['total'],
            'count_filtered' => $total_filtered
        ];

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listLandingPageMapMetrics(Request $request, Response $response, $args)
    {
        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $venueQuery = new Venue;

        /**
         * Get venues filtered by the show_stats_on_login flag
         */
        $venue_collection = $venueQuery->with('venue_tracking')
            ->where('show_stats_on_login', 1)
            ->where('tracking_venue', 1)
            ->get();

        /**
         * iterate through the venue collection to construct the output for each venue
         */
        foreach ($venue_collection as $venue) {
            $total_new_visitors = 0;
            $total_repeat_visitors = 0;
            $end_date = Carbon::now()->timestamp;
            $venue_id = $venue->id;

            /**
             * prepare the query using a "random" PDO connection
             */
            $query = new TrackingDailyStatsVenueVisitors;
            $db = $query->getConnection()->getPdo();

            $visitor_data = $db->prepare('
                SELECT SUM(visitors_new) AS visitors_new,
                       SUM(visitors_total) AS visitor_total
                FROM tracking_daily_stats_venue_visitors
                WHERE venue_id = :venue_id
            ');

            /**
             * bind the parameters to the selected query
             */
            $visitor_data->bindParam(':venue_id', $venue_id);
            $visitor_data->execute();

            foreach($visitor_data as $data) {
                $total_new_visitors = $data['visitors_new'];
                $total_repeat_visitors = $data['visitor_total'] - $data['visitors_new'];
            }

            /**
             * count days/weeks/months from the start date to now using Carbon
             */
            $dt1 = Carbon::createFromTimestamp($venue->venue_tracking->capture_start);
            $dt2 = Carbon::createFromTimestamp($end_date);

            $days = $dt1->diffInDays($dt2);
            $weeks = $dt1->diffInWeeks($dt2);
            $months = $dt1->diffInMonths($dt2);

            if ($days == 0) {
                $days = 1;
            }

            if ($weeks == 0) {
                $weeks = 1;
            }

            if ($months == 0) {
                $months = 1;
            }

            /**
             * determine the daily/weekly/monthly average visitor values
             */
            $average_visitors_per_day = round(($total_new_visitors + $total_repeat_visitors)/$days);
            $average_visitors_per_week = round(($total_new_visitors + $total_repeat_visitors)/$weeks);
            $average_visitors_per_month = round(($total_new_visitors + $total_repeat_visitors)/$months);

            $venue_metrics[] = [
                'id' => $venue->id,
                'venue_name' => $venue->name,
                'venue_lat' => $venue->lat,
                'venue_lon' => $venue->lon,
                'total_new_visitors' => $total_new_visitors,
                'total_repeat_visitors' => $total_repeat_visitors,
                'average_visitors_per_day' => $average_visitors_per_day,
                'average_visitors_per_week' => $average_visitors_per_week,
                'average_visitors_per_month' => $average_visitors_per_month
            ];
        }

        /**
         * assemble the results into a single object
         */
        $results = $venue_metrics;

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }




















    /**
     * Return an array of zone_ids which match the POSTed filter criteria
     */
    private function getFilteredZoneIds($post)
    {
        /**
         * some basic transformation of the POSTed contents
         */
        $category_id = intval($post['category']);
        $country_id = intval($post['country']);
        $region_id= intval($post['region']);
        $area_id = intval($post['area']);
        $population_filter = $post['population_filter'];
        if (!empty($post['sub_categories']) && is_array($post['sub_categories'])) {
            $venue_sub_categories = $post['sub_categories'];
        } else {
            $venue_sub_categories = [];
        }

        /**
         * search the venues which match the POSTed filter criteria
         */
        $venueQuery      = new Venue;
        // $filtered_venues = $venueQuery->where('capture_start', '<', time());

        $filtered_venues = $venueQuery->whereHas('venue_tracking', function ($query) {
            $query->where('capture_start', '<', time());
        });

        $userVenue = $this->ci->currentUser->primaryVenue;

        /**
         * then apply filters to the current query
         */
        if ($category_id != 0) {
            $filtered_venues->where('category_id', $category_id);
        };

        if (count($venue_sub_categories) > 0) {
            $filtered_venues->whereHas('sub_categories', function ($query) use($venue_sub_categories) {
                $query->whereIn('sub_category.id', $venue_sub_categories);
            });
        };

        if ($country_id != 0) {
            $filtered_venues->where('country_id', $country_id);
        };

        if ($region_id != 0) {
            $filtered_venues->where('region_id', $region_id);
        };

        if ($area_id != 0) {
            $filtered_venues->where('area_id', $area_id);
        };

        if ($population_filter != 'false') {
            $population_from = $userVenue->population * 0.85;
            $population_to   = $userVenue->population * 1.15;

            $filtered_venues->where('population', '>=', $population_from)->where('population', '<=', $population_to);
        };
        

        /**
         * - get the National Stats tag id from the settings table which
         *   we will use to filter on
         * - lastly we filter on the zone tag
         */
        $fetch_settings = new SiteConfiguration;
        $tag_filter = $fetch_settings->where('plugin', 'national_stats')
            ->where('name', 'national_stats_tag_id')
            ->first()->value;

        $filtered_venues->whereHas('zones.tags', function($q) use ($tag_filter) {
            $q->where('tag.id', $tag_filter);
        });

        /**
         * fetch the final results from the venue query
         */
        $filtered_venues = $filtered_venues->get();

        /**
         * then create an array containing venue ids that passed the filters,
         * filtering out the National Stats zone for the current venue
         */
        $allowed_zone_ids = [];
        $zone_query = new Zone;

        foreach ($filtered_venues as $venue) {
            if ($venue->id != $this->ci->currentUser->primary_venue_id) {
                $include_zones = $zone_query->where('venue_id', $venue->id)
                    ->whereHas('tags', function($q) use ($tag_filter) {
                        $q->where('tag.id', $tag_filter);
                    })->get();

                foreach ($include_zones as $zone) {
                    $allowed_zone_ids[] = $zone->id;
                }
            }
        }

        return $allowed_zone_ids;
    }

    /**
     * determine head/body/tail attributes for a given time period
     */
    private function determineHeadBodyTail($start, $end, $timezone)
    {
        /**
         * determine TAIL
         */
        $this->tail_end = Carbon::createFromTimestamp($end, $timezone);

        if($start < Carbon::createFromTimestamp($end, $timezone)->startOfDay()->format('U')) {
            $this->tail_start = Carbon::createFromTimestamp($end, $timezone)->startOfDay();
        } else {
            $this->tail_start = Carbon::createFromTimestamp($start, $timezone);
        }

        /**
         * determine whether the tail is in today
         */
        if($this->tail_end->isToday()) {
            $this->tail_in_today = true;
        } else {
            $this->tail_in_today = false;
        }

        $this->tail_length = $this->tail_start->diffInSeconds($this->tail_end);

        if($this->tail_end->format('U') === $this->tail_start->copy()->endofDay()->format('U')) {
            /**
             * this is a full day
             */
            $this->tail_length = 0;
            $this->tail_start  = $this->tail_end->copy();
        }

        /**
         * get tail_start and tail_end formatted as epoch
         * first set the correct timezone
         */
        $this->tail_start->timezone = $timezone;
        $this->tail_end->timezone   = $timezone;
        $this->tail_day_epoch       = $this->tail_start->copy()->startOfDay()->format('U');

        /**
         * determine BODY
         */
        $this->body_length = $this->tail_start->diffInDays(Carbon::createFromTimestamp($start, $timezone));
        $this->body_start  = $this->tail_start->copy()->subDays($this->body_length);

        if($this->body_length > 0) {
            $this->body_start  = $this->tail_start->copy()->subDays($this->body_length);
            $this->body_end    = $this->tail_start->copy();
            $this->head_length = $this->body_start->diffInHours(Carbon::createFromTimestamp($start, $timezone));
        } else {
            $this->body_start  = $this->tail_start->copy();
            $this->body_end    = $this->tail_start->copy();
            $this->head_length = $this->tail_start->diffInSeconds(Carbon::createFromTimestamp($start, $timezone));
        }

        /**
         * set the correct timezone for body_start and body_end
         */
        $this->body_start->timezone = $timezone;
        $this->body_end->timezone   = $timezone;

        /**
         * determine HEAD
         */
        if($this->head_length > 0) {
            $this->head_start = Carbon::createFromTimestamp($start, $timezone);
            $this->head_end   = $this->body_start->copy();
        } else {
            $this->head_start = $this->body_start->copy();
            $this->head_end   = $this->body_start->copy();
        }

        if($this->head_end->format('U') === $this->head_start->copy()->endofDay()->format('U')) {
            /**
             * this is a full day
             */
            $this->head_length = 0;
            ++$this->body_length;
            unset($this->body_start);
            $this->body_start = Carbon::createFromTimestamp($start, $timezone);
        }

        /**
         * get the start of day for head and set correct timezone for head_start and head_end
         */
        $this->head_day_epoch = $this->head_start->copy()->startOfDay()->format('U');
        $this->head_start->timezone = $timezone;
        $this->head_end->timezone   = $timezone;

        return;
    }
}