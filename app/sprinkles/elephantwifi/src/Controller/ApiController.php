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

use UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\Event;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\Zone;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\SiteConfiguration;

use UserFrosting\Sprinkle\ElephantWifi\Database\Models\VenueWifi;
use UserFrosting\Sprinkle\ElephantWifi\Database\Models\WifiDailyStatsVenueVisitors;
use UserFrosting\Sprinkle\ElephantWifi\Database\Models\WifiDailyStatsVenueAuthorisedDwelltime;
use UserFrosting\Sprinkle\ElephantWifi\Database\Models\DailyStatsZoneDwelltime;
use UserFrosting\Sprinkle\ElephantWifi\Database\Models\Identity;
use UserFrosting\Sprinkle\ElephantWifi\Database\Models\WifiDailyStatsVenueUniqueDeviceUuids;
use UserFrosting\Sprinkle\ElephantWifi\Database\Models\WifiClientConnection;
use UserFrosting\Sprinkle\ElephantWifi\Database\Models\WifiDailyStatsZoneUniqueDeviceUuids;
use UserFrosting\Sprinkle\ElephantWifi\Database\Models\WifiDailyStatsVenueWeather;
use UserFrosting\Sprinkle\ElephantWifi\Database\Models\OldDailyVenueStats;
use UserFrosting\Sprinkle\ElephantWifi\Database\Models\WifiDailyStatsVenueWebTitanPerDay;
use UserFrosting\Sprinkle\ElephantWifi\Database\Models\WifiDailyStatsVenueEmailStatuses;
use UserFrosting\Sprinkle\ElephantWifi\Database\Models\MarketingList;
use UserFrosting\Sprinkle\ElephantWifi\Database\Models\AccessPoint;

use Jabranr\PostcodesIO\PostcodesIO;

/**
 * ApiController Class
 *
 * @package ElephantWiFi
 * @author Luke Rayner/ElephantWiFi
 * @link http://www.elephantwifi.co.uk
 */
class ApiController extends SimpleController 
{
    public function listAverageDwelltime(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $start = round((int)$args['start']/1000);
        $end = round((int)$args['end']/1000);

        /**
         * connect to the UniFi controller
         */
        $unifidata = $this->connectController();
        if (!$unifidata) {
            return $response->withStatus(400);
        }
        $results = $unifidata->stat_sessions($start, $end);

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listVenueDailyVisitorCounts(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $start = $args['start'] / 1000;
        $end = $args['end'] / 1000;

        $visitorCountQuery = new WifiDailyStatsVenueVisitors;
        $total = $visitorCountQuery->count();

        /**
         * Get visitor_count_collection filtered by the primary_venue_id of logged in user and start timestamp
         */
        $visitor_count_collection = $visitorCountQuery
            ->where('venue_id', $currentUser->primary_venue_id)
            ->where('day_epoch', '>=', $start)
            ->where('day_epoch', '<', $end)
            ->get();
        $total_filtered = count($visitor_count_collection);

        $results = [
            "count" => $total,
            "rows" => $visitor_count_collection->values()->toArray(),
            "count_filtered" => $total_filtered
        ];

        // Get all the events which were ran on between these dates
        $event = new Event;
        if ($authorizer->checkAccess($currentUser, 'uri_site_admin')) {
            $dailyEvents = $event
                ->with('event_category')
                ->where('start_date', '>=', $start)
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

    public function listVenueStatsAuthorisedVisitorDurations(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $results = ['dt_skipped' => [], 'dt_level_1' => [], 'dt_level_2' => [], 'dt_level_3' => [], 'dt_level_4' => [], 'dt_level_5' => [], 'dt_average' => []];

        $start = floor($args['start']/1000);
        $end   = floor($args['end']/1000);

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
            $thisWeekDwelltimes = new WifiDailyStatsVenueAuthorisedDwelltime;
            $initialresultsDurations = $thisWeekDwelltimes->selectRaw('day_epoch, dt_skipped, dt_level_1, dt_level_2, dt_level_3, dt_level_4, dt_level_5, dt_average')
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
            $thisWeekDwelltimes = new DailyStatsZoneDwelltime;
            $initialresultsDurations = $thisWeekDwelltimes->selectRaw('day_epoch, dt_skipped, dt_level_1, dt_level_2, dt_level_3, dt_level_4, dt_level_5, dt_average')
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
            $results['dt_skipped'][] = [$initialresultsDuration['day_epoch']*1000, (int)$initialresultsDuration['dt_skipped']];
            $results['dt_level_1'][] = [$initialresultsDuration['day_epoch']*1000, (int)$initialresultsDuration['dt_level_1']];
            $results['dt_level_2'][] = [$initialresultsDuration['day_epoch']*1000, (int)$initialresultsDuration['dt_level_2']];
            $results['dt_level_3'][] = [$initialresultsDuration['day_epoch']*1000, (int)$initialresultsDuration['dt_level_3']];
            $results['dt_level_4'][] = [$initialresultsDuration['day_epoch']*1000, (int)$initialresultsDuration['dt_level_4']];
            $results['dt_level_5'][] = [$initialresultsDuration['day_epoch']*1000, (int)$initialresultsDuration['dt_level_5']];
            $results['dt_average'][] = [$initialresultsDuration['day_epoch']*1000, round((int)$initialresultsDuration['dt_average']/60)];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listIdentityProviders(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $start = $args['start'] / 1000;

        $IdentityQuery = new Identity;
        $total = $IdentityQuery->count();

        $identity_provider_collection = $IdentityQuery
            ->selectRaw('COUNT(DISTINCT email_address) AS `count`,
                provider')
            ->whereRaw('UNIX_TIMESTAMP(identity.created_at) >= ' . $start)
            ->join('venue_wifi_user', 'venue_wifi_user.user_id', '=', 'identity.user_id')
            ->where('venue_wifi_user.venue_id', $currentUser->primary_venue_id)
            ->groupBy('provider')
            ->get();
        $total_filtered = count($identity_provider_collection);

        $results = [
            "count" => $total,
            "rows" => $identity_provider_collection->values()->toArray(),
            "count_filtered" => $total_filtered
        ];

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listGenderCompareAgeCount(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * filter on the user's primary_venue_id and get the user's timezone
         */
        $venue_filter = $currentUser->primary_venue_id;
        $timezone = $currentUser->primaryVenue->time_zone;

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
        $identityQuery = new WifiDailyStatsVenueUniqueDeviceUuids;
        $db = $identityQuery->getConnection()->getPdo();

        if ($start > 0 && $end > 0) {
            /**
             * check whether the user is allowed to view full venue stats or not
             * and act accordingly
             */

            if ($currentUser->full_venue_view_allowed == 1) {
                /**
                 * user is allowed to view full venue stats
                 * prepare and execute the query
                 */
                $identity_gender_vs_age_collection = $db->prepare("
                    SELECT IF((age IS NULL), 'Undisclosed', concat(10*floor(age/10), '-', 10*floor(age/10) + 10)) as `range`,
                           SUM(IF(gender = 0, 1, 0)) AS Male,
                           SUM(IF(gender = 1, 1, 0)) AS Female,
                           SUM(IF(gender IS NULL, 1, 0)) AS `Undisclosed`
                    FROM
                    (
                        SELECT day_epoch,
                               hour,
                               gender,
                               age
                        FROM wifi_daily_stats_venue_unique_device_uuids_per_hour
                        WHERE (day_epoch + (3600*hour)) >= :start
                         AND (day_epoch + (3600*hour)) < :end_1
                         AND venue_id = :venue_id
                         AND age >= 13
                         AND age < 100
                         AND (has_authorised = 1 OR is_authorised = 1)
                         AND provider IS NOT NULL
                        GROUP BY device_uuid, day_epoch
                    ) AS temp
                    GROUP BY `range`
                ");
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
                $identity_gender_vs_age_collection = $db->prepare("
                    SELECT IF((age IS NULL), 'Undisclosed', concat(10*floor(age/10), '-', 10*floor(age/10) + 10)) as `range`,
                           SUM(IF(gender = 0, 1, 0)) AS Male,
                           SUM(IF(gender = 1, 1, 0)) AS Female,
                           SUM(IF(gender IS NULL, 1, 0)) AS `Undisclosed`
                    FROM
                    (
                        SELECT day_epoch,
                               hour,
                               gender,
                               age
                        FROM wifi_daily_stats_zone_unique_device_uuids_per_hour
                        WHERE (day_epoch + (3600*hour)) >= :start
                         AND (day_epoch + (3600*hour)) < :end_1
                         AND age >= 13
                         AND age < 100
                         AND venue_id = :venue_id
                         AND zone_id IN (' . $ids_string . ')
                         AND (has_authorised = 1 OR is_authorised = 1)
                         AND provider IS NOT NULL
                        GROUP BY device_uuid, day_epoch
                    ) AS temp
                    GROUP BY `range`
                ");
            }
        }

        $identity_gender_vs_age_collection->bindParam(':start', $start);
        $identity_gender_vs_age_collection->bindParam(':end_1', $end);
        $identity_gender_vs_age_collection->bindParam(':venue_id', $venue_filter);

        /**
         * execute the query
         */
        $identity_gender_vs_age_collection->execute();

        $results = [];
        foreach ($identity_gender_vs_age_collection as $item) {
            $results[] = [$item[0], (int)$item[1], (int)$item[2], (int)$item[3]];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listAuthorisedVisitorsTimeOfDay(Request $request, Response $response, $args) 
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
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
        $start_hour = Carbon::createFromTimestamp($start, $timezone)->hour;
        $end_hour = Carbon::createFromTimestamp($end, $timezone)->hour;
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
        $query = new WifiDailyStatsVenueUniqueDeviceUuids;
        $db = $query->getConnection()->getPdo();

        if ($start > 0 && $end > 0) {
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
                    "SELECT COUNT(DISTINCT(device_uuid)) AS visitors,
                            hour_epoch,
                            hour
                    FROM
                    (
                        SELECT device_uuid,
                             day_epoch,
                             (day_epoch + (3600*hour)) AS hour_epoch,
                             hour
                        FROM wifi_daily_stats_venue_unique_device_uuids_per_hour
                        WHERE (day_epoch + (3600*hour)) >= :start
                        AND (day_epoch + (3600*hour)) < :end_1
                        AND venue_id = :venue_id
                        AND has_authorised = 1
                    ) AS temp1
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
                    'SELECT COUNT(DISTINCT(device_uuid)) AS visitors,
                            hour_epoch,
                            hour
                    FROM
                    (
                        SELECT device_uuid,
                             day_epoch,
                             (day_epoch + (3600*hour)) AS hour_epoch,
                             hour
                        FROM wifi_daily_stats_zone_unique_device_uuids_per_hour
                        WHERE (day_epoch + (3600*hour)) >= :start
                        AND (day_epoch + (3600*hour)) < :end_1
                        AND venue_id = :venue_id
                        AND zone_id IN (' . $ids_string . ')
                        AND has_authorised = 1
                    ) AS temp1
                   GROUP BY hour'
                );
            }
        }

        /**
         * bind the parameters to the selected query
         */
        $initialresultsVisitors->bindParam(':start', $start);
        $initialresultsVisitors->bindParam(':end_1', $end);
        $initialresultsVisitors->bindParam(':venue_id', $venue_filter);

        /**
         * execute the query for total visitors
         */
        $initialresultsVisitors->execute();

        $tempresults = [];
        /**
         * loop through the array and add to tempresults
         */
        foreach ($initialresultsVisitors as $item) {
            if(isset($tempresults[$item['hour']])) {
                $tempresults[$item['hour']][0] += (int)$item['visitors'];
                $tempresults[$item['hour']][1] += 1;
            }
            else {
                $tempresults[$item['hour']][0] = (int)$item['visitors'];
                $tempresults[$item['hour']][1] = 1;
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

    public function listUniqueVisitorConnections(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $venue_filter = $currentUser->primary_venue_id;

        $visitorConnectionQuery = new WifiClientConnection;
        $total = $visitorConnectionQuery->count();

        /**
         * Get visitor_connection filtered by the primary_venue_id of logged in user and start timestamp
         */
        $visitor_connection_collection = $visitorConnectionQuery
            ->selectRaw('
                wifi_client_connection.id as id,
                device.id as device_id, 
                wifi_client_connection.device_uuid as device_uuid, 
                wifi_client_connection.access_point_id as access_point_id,
                wifi_client_connection.venue_id as venue_id,
                device.first_seen as first_seen,
                device.last_seen as last_seen
            ')
            ->leftJoin('device', function($join) use ($venue_filter) {
                $join->on('wifi_client_connection.device_uuid', '=', "device.device_uuid");
            })
            ->where('wifi_client_connection.venue_id', $venue_filter)
            ->where('wifi_client_connection.ts', '>=', $args['start'])
            ->where('device.auth_expiry_date', '>', 0)
            ->groupBy('device_uuid')
            ->get();
        $total_filtered = count($visitor_connection_collection);

        foreach($visitor_connection_collection as $visitor_connection) {
            $visitor_connection->device_uuid = bin2hex($visitor_connection->device_uuid);
        }

        $results = [
            "count" => $total,
            "rows" => $visitor_connection_collection->values()->toArray(),
            "count_filtered" => $total_filtered
        ];

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listUnifiOnlineUsers(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * connect to the UniFi controller
         */
        $unifidata = $this->connectController();
        $results = $unifidata->list_clients();

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
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
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
        $results = ['new' => [], 'repeat' => []];
        $hours_array = [];

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
        $start_hour = Carbon::createFromTimestamp($args['start'], $timezone)->hour;
        $end_hour = Carbon::createFromTimestamp($args['end'], $timezone)->hour;
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
        $query = new WifiDailyStatsVenueUniqueDeviceUuids;
        $db = $query->getConnection()->getPdo();

        if ($start > 0 && $end > 0) {
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
                                            FROM wifi_daily_stats_venue_unique_device_uuids_per_hour
                                           WHERE (day_epoch + (3600*hour)) >= :start
                                             AND (day_epoch + (3600*hour)) < :end_1
                                             AND (has_authorised != 1 OR is_authorised != 1)
                                             AND venue_id = :venue_id
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
                $visitor_counts = $db->prepare(
                    'SELECT COUNT(CASE WHEN is_repeat = 0 THEN 1 END) AS new_visitors,
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
                                            FROM wifi_daily_stats_zone_unique_device_uuids_per_hour
                                           WHERE (day_epoch + (3600*hour)) >= :start
                                             AND (day_epoch + (3600*hour)) < :end_1
                                             AND (has_authorised != 1 OR is_authorised != 1)
                                             AND venue_id = :venue_id
                                             AND zone_id IN (' . $ids_string . ')
                                        ) AS temp1
                            ) AS temp2
                    GROUP BY day_epoch'
                );
            }

            /**
             * bind the parameters to the selected query
             */
            $visitor_counts->bindParam(':start', $start);
            $visitor_counts->bindParam(':end_1', $end);
            $visitor_counts->bindParam(':venue_id', $venue_filter);

            /**
             * execute the query for total visitors
             */
            $visitor_counts->execute();
        }

        foreach($visitor_counts as $day) {
            $results['new'][] = [$day['day_epoch']*1000, (int)$day['new_visitors']];
            $results['repeat'][] = [$day['day_epoch']*1000, (int)$day['repeat_visitors']];
        }

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
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
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
        $start_hour = Carbon::createFromTimestamp($args['start'], $timezone)->hour;
        $end_hour = Carbon::createFromTimestamp($args['end'], $timezone)->hour;
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
        $query = new WifiDailyStatsVenueUniqueDeviceUuids;
        $db = $query->getConnection()->getPdo();

        if ($start > 0 && $end > 0) {
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
                    "SELECT COUNT(DISTINCT(device_uuid)) AS visitors,
                            hour_epoch,
                            hour
                      FROM
                            (
                              SELECT device_uuid,
                                     day_epoch,
                                     (day_epoch + (3600*hour)) AS hour_epoch,
                                     hour
                                FROM wifi_daily_stats_venue_unique_device_uuids_per_hour
                                WHERE (day_epoch + (3600*hour)) >= :start
                                 AND (day_epoch + (3600*hour)) < :end_1
                                 AND (has_authorised != 1 OR is_authorised != 1)
                                 AND venue_id = :venue_id
                            ) AS temp1
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
                    'SELECT COUNT(DISTINCT(device_uuid)) AS visitors,
                            hour_epoch,
                            hour
                      FROM
                            (
                              SELECT device_uuid,
                                     day_epoch,
                                     (day_epoch + (3600*hour)) AS hour_epoch,
                                     hour
                                FROM wifi_daily_stats_zone_unique_device_uuids_per_hour
                               WHERE (day_epoch + (3600*hour)) >= :start
                                 AND (day_epoch + (3600*hour)) < :end_1
                                 AND (has_authorised != 1 OR is_authorised != 1)
                                 AND venue_id = :venue_id
                                 AND zone_id IN (' . $ids_string . ')
                            ) AS temp1
                       GROUP BY hour'
                );
            }
        }

        /**
         * bind the parameters to the selected query
         */
        $initialresultsVisitors->bindParam(':start', $start);
        $initialresultsVisitors->bindParam(':end_1', $end);
        $initialresultsVisitors->bindParam(':venue_id', $venue_filter);

        /**
         * execute the query for total visitors
         */
        $initialresultsVisitors->execute();

        $tempresults = [];
        /**
         * loop through the array and add to tempresults
         */
        foreach ($initialresultsVisitors as $item) {
            if(isset($tempresults[$item['hour']])) {
                $tempresults[$item['hour']][0] += (int)$item['visitors'];
                $tempresults[$item['hour']][1] += 1;
            }
            else {
                $tempresults[$item['hour']][0] = (int)$item['visitors'];
                $tempresults[$item['hour']][1] = 1;
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

    public function listVisitorReportVisitorDurations(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * filter on the user's primary_venue_id and get the venue's timezone
         */
        $venue_filter = $currentUser->primary_venue_id;
        $timezone = $currentUser->primaryVenue->time_zone;

        $results = ['dt_skipped' => [], 'dt_level_1' => [], 'dt_level_2' => [], 'dt_level_3' => [], 'dt_level_4' => [], 'dt_level_5' => [], 'dt_average' => []];

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
        $start_hour = Carbon::createFromTimestamp($args['start'], $timezone)->hour;
        $end_hour = Carbon::createFromTimestamp($args['end'], $timezone)->hour;
        $start_date = Carbon::createFromTimestamp($args['start']/1000, $timezone);
        $end_date = Carbon::createFromTimestamp($args['end']/1000, $timezone);

        /**
         * get the start date and end date (start of day)
         */
        $start = $start_date->format('U');
        $end   = $end_date->format('U');

        /**
         * prepare the query using a "random" PDO connection
         */
        $query = new WifiDailyStatsVenueUniqueDeviceUuids;
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

            $db->exec("SET sql_mode             = 'NO_UNSIGNED_SUBTRACTION';");
            $db->exec("SET @venue_filter        = '{$venue_filter}'");
            $db->exec("SET @range_start         = '{$start}'");
            $db->exec("SET @range_end           = '{$end}'");
            $db->exec("SET @dwell_bucket        = '{$dwell_bucket}'");
            $db->exec("SET @prev_ts             = 0");
            $db->exec("SET @dwell_start         = 0");
            $db->exec("SET @prev_dwelltime      = 0");
            $db->exec("SET @prev_device_uuid    = NULL");
            $db->exec("SET @device_uuid_changed = FALSE");
            $db->exec("SET @long_gap            = FALSE");
            $db->exec("SET @gap                 = 0");
            $db->exec("SET @dt_threshold_1      = '{$dt_threshold_1}'");
            $db->exec("SET @dt_threshold_2      = '{$dt_threshold_2}'");
            $db->exec("SET @dt_threshold_3      = '{$dt_threshold_3}'");
            $db->exec("SET @dt_threshold_4      = '{$dt_threshold_4}'");
            $db->exec("SET @dt_threshold_5      = '{$dt_threshold_5}'");

            if ($currentUser->full_venue_view_allowed == 1) {

                /**
                 * user is allowed to view full venue stats
                 * first we prepare and execute the query
                 */
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
                              FROM wifi_daily_stats_venue_unique_device_uuids_per_hour
                             WHERE venue_id = @venue_filter
                               AND (day_epoch + (3600*hour)) >= :start
                               AND (day_epoch + (3600*hour)) < :end_1
                               AND (has_authorised != 1 OR is_authorised != 1)
                             ORDER BY device_uuid ASC, hour ASC
                            ) temp_results
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
                $allowed_zones      = collect($currentUser->zones);
                $allowed_zone_ids   = [];

                foreach ($allowed_zones as $zone) {
                    $allowed_zone_ids[] = $zone->id;
                }

                /**
                 * create a string containing zone ids, comma seperated, to insert into the PDO statement
                 */
                $ids_string = implode(',', $allowed_zone_ids);

                error_log($ids_string);

                $db->exec("SET @zones = '{$ids_string}'");

                /**
                 * prepare and execute the query with venue AND zones filtering
                 */
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
                              FROM wifi_daily_stats_zone_unique_device_uuids_per_hour
                             WHERE venue_id = @venue_filter
                               AND FIND_IN_SET(zone_id, @zones)
                               AND (day_epoch + (3600*hour)) >= :start
                               AND (day_epoch + (3600*hour)) < :end_1
                               AND (has_authorised != 1 OR is_authorised != 1)
                             ORDER BY device_uuid ASC, hour ASC
                            ) temp_results
                        WHERE changed = TRUE
                        GROUP BY day_epoch
                    ) final_results'
                );
            }
        }

        /**
         * bind the parameters to the selected query
         */
        $initialresultsDurations->bindParam(':start', $start);
        $initialresultsDurations->bindParam(':end_1', $end);

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

    public function listVisitorReportZoneVisitorsComparison(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
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
        $start_hour = Carbon::createFromTimestamp($args['start'], $timezone)->hour;
        $end_hour = Carbon::createFromTimestamp($args['end'], $timezone)->hour;
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
        $query = new WifiDailyStatsZoneUniqueDeviceUuids;
        $db = $query->getConnection()->getPdo();

        if ($start > 0 && $end > 0) {
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
                    'SELECT zone_id,
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
                                       hour,
                                       zone_id,
                                       is_repeat
                                FROM wifi_daily_stats_zone_unique_device_uuids_per_hour
                                 WHERE (day_epoch + (3600*hour)) >= :start
                                 AND (day_epoch + (3600*hour)) < :end_1
                                 AND (has_authorised != 1 OR is_authorised != 1)
                                 AND venue_id = :venue_id
                            ) AS temp1
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
                $initialresultsVisitors = $db->prepare(
                    'SELECT zone_id,
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
                                       hour,
                                       zone_id,
                                       is_repeat
                                FROM wifi_daily_stats_zone_unique_device_uuids_per_hour
                                 WHERE (day_epoch + (3600*hour)) >= :start
                                 AND (day_epoch + (3600*hour)) < :end_1
                                 AND (has_authorised != 1 OR is_authorised != 1)
                                 AND venue_id = :venue_id
                                 AND zone_id IN (' . $ids_string . ')
                            ) AS temp1
                        ) AS temp2
                    INNER JOIN zone ON zone_id = zone.id 
                    GROUP BY zone_id'
                );
            }
        }

        /**
         * bind the parameters to the selected query
         */
        $initialresultsVisitors->bindParam(':start', $start);
        $initialresultsVisitors->bindParam(':end_1', $end);
        $initialresultsVisitors->bindParam(':venue_id', $venue_filter);

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

    public function connectVsInterntVisitorCount(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $start = $args['start'] / 1000;
        $end   = $args['end'] / 1000;

        /**
         * get user's primary venue id to use as filter
         */
        $venue_filter = $currentUser->primary_venue_id;

        $visitorQuery = new WifiDailyStatsVenueVisitors;
        $total = $visitorQuery->count();

        $cumulativeData = $visitorQuery->select('day_epoch', 'total_device_uuid', 'has_authorised_device_uuid')
           ->where('venue_id', $venue_filter)
           ->where('day_epoch', '>=', $start)
           ->where('day_epoch', '<', $end)
           ->orderBy('day_epoch')
           ->get();

        $total_filtered = count($cumulativeData);

        $results = [
            'count' => $total,
            'rows' => $cumulativeData->values()->toArray(),
            'count_filtered' => $total_filtered
        ];

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
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
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
        $current_venue = $venueQuery->with('venue_wifi')->where('id', $currentUser->primary_venue_id)->first();

        /**
         * check whether the user is allowed to view full venue stats or not
         * and act accordingly
         */
        if ($currentUser->full_venue_view_allowed == 1) {
            /**
             * user is allowed to view full venue stats
             * prepare and execute the query
             */
            $totalVisitors = new WifiDailyStatsVenueVisitors;
            $first_day_epoch = $current_venue->venue_wifi->capture_start;

            $initialresultsTotalVisitors = $totalVisitors->selectRaw('SUM(total_device_uuid) AS visitors')
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
            $allowed_zones = collect($currentUser->zones);
            $allowed_zones_ids = [];
            foreach ($allowed_zones as $allowed_zone) {
                $allowed_zones_ids[] = $allowed_zone->id;
            }

            /**
             * prepare and execute the query with venue AND zones filtering
             */
            $totalVisitors = new WifiDailyStatsZoneVisitors;
            $first_day_epoch = $current_venue->venue_wifi->capture_start;

            $initialresultsTotalVisitors = $totalVisitors->selectRaw('SUM(total_device_uuid) AS visitors')
                ->where('venue_id', $venue_filter)
                ->whereIn('zone_id', $allowed_zones_ids)
                ->where('day_epoch', '>=', $first_day_epoch)
                ->get();
        }

        /**
         * add the total visitors from the stats and the visitors for today
         */
        $totalVisitorsAlltime = $initialresultsTotalVisitors[0]['visitors'];

        /**
         * use Carbon to be able to determine durations
         */
        $timezone = $currentUser->primaryVenue->time_zone;
        $first_day = Carbon::createFromTimestamp($first_day_epoch, $timezone);
        $today = Carbon::now($timezone);

        /**
         * calculate the averages
         * TODO: see how the yearly averages turn out, maybe use the months to get non-rounded year count
         */
        if($first_day->diffInDays($today) === 0) {
            $average_daily = $totalVisitorsAlltime;
        } else {
            $average_daily = round($totalVisitorsAlltime/($first_day->diffInDays($today)));
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
            'days' => $first_day->diffInDays($today),
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

    public function listInternetUserReportNewVsRepeat(Request $request, Response $response, $args) 
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
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
        $results = ['new' => [], 'repeat' => []];
        $hours_array = [];

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
        $start_hour = Carbon::createFromTimestamp($args['start'], $timezone)->hour;
        $end_hour = Carbon::createFromTimestamp($args['end'], $timezone)->hour;
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
        $query = new WifiDailyStatsVenueUniqueDeviceUuids;
        $db = $query->getConnection()->getPdo();

        if ($start > 0 && $end > 0) {
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
                                            FROM wifi_daily_stats_venue_unique_device_uuids_per_hour
                                           WHERE (day_epoch + (3600*hour)) >= :start
                                             AND (day_epoch + (3600*hour)) < :end_1
                                             AND (has_authorised = 1 OR is_authorised = 1)
                                             AND venue_id = :venue_id
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
                $visitor_counts = $db->prepare(
                    'SELECT COUNT(CASE WHEN is_repeat = 0 THEN 1 END) AS new_visitors,
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
                                            FROM wifi_daily_stats_zone_unique_device_uuids_per_hour
                                           WHERE (day_epoch + (3600*hour)) >= :start
                                             AND (day_epoch + (3600*hour)) < :end_1
                                             AND (has_authorised = 1 OR is_authorised = 1)
                                             AND venue_id = :venue_id
                                             AND zone_id IN (' . $ids_string . ')
                                        ) AS temp1
                            ) AS temp2
                    GROUP BY day_epoch'
                );
            }

            /**
             * bind the parameters to the selected query
             */
            $visitor_counts->bindParam(':start', $start);
            $visitor_counts->bindParam(':end_1', $end);
            $visitor_counts->bindParam(':venue_id', $venue_filter);

            /**
             * execute the query for total visitors
             */
            $visitor_counts->execute();
        }

        foreach($visitor_counts as $day) {
            $results['new'][] = [$day['day_epoch']*1000, (int)$day['new_visitors']];
            $results['repeat'][] = [$day['day_epoch']*1000, (int)$day['repeat_visitors']];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listInternetUserReportVisitorsTimeOfDay(Request $request, Response $response, $args) 
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
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
        $start_hour = Carbon::createFromTimestamp($args['start'], $timezone)->hour;
        $end_hour = Carbon::createFromTimestamp($args['end'], $timezone)->hour;
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
        $query = new WifiDailyStatsVenueUniqueDeviceUuids;
        $db = $query->getConnection()->getPdo();

        if ($start > 0 && $end > 0) {
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
                    "SELECT COUNT(DISTINCT(device_uuid)) AS visitors,
                            hour_epoch,
                            hour
                      FROM
                            (
                              SELECT device_uuid,
                                     day_epoch,
                                     (day_epoch + (3600*hour)) AS hour_epoch,
                                     hour
                                FROM wifi_daily_stats_venue_unique_device_uuids_per_hour
                                WHERE (day_epoch + (3600*hour)) >= :start
                                 AND (day_epoch + (3600*hour)) < :end_1
                                 AND (has_authorised = 1 OR is_authorised = 1)
                                 AND venue_id = :venue_id
                            ) AS temp1
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
                    'SELECT COUNT(DISTINCT(device_uuid)) AS visitors,
                            hour_epoch,
                            hour
                      FROM
                            (
                              SELECT device_uuid,
                                     day_epoch,
                                     (day_epoch + (3600*hour)) AS hour_epoch,
                                     hour
                                FROM wifi_daily_stats_zone_unique_device_uuids_per_hour
                               WHERE (day_epoch + (3600*hour)) >= :start
                                 AND (day_epoch + (3600*hour)) < :end_1
                                 AND (has_authorised = 1 OR is_authorised = 1)
                                 AND venue_id = :venue_id
                                 AND zone_id IN (' . $ids_string . ')
                            ) AS temp1
                       GROUP BY hour'
                );
            }
        }

        /**
         * bind the parameters to the selected query
         */
        $initialresultsVisitors->bindParam(':start', $start);
        $initialresultsVisitors->bindParam(':end_1', $end);
        $initialresultsVisitors->bindParam(':venue_id', $venue_filter);

        /**
         * execute the query for total visitors
         */
        $initialresultsVisitors->execute();

        $tempresults = [];
        /**
         * loop through the array and add to tempresults
         */
        foreach ($initialresultsVisitors as $item) {
            if(isset($tempresults[$item['hour']])) {
                $tempresults[$item['hour']][0] += (int)$item['visitors'];
                $tempresults[$item['hour']][1] += 1;
            }
            else {
                $tempresults[$item['hour']][0] = (int)$item['visitors'];
                $tempresults[$item['hour']][1] = 1;
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

    public function listInternetUserReportVisitorDurations(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * filter on the user's primary_venue_id and get the venue's timezone
         */
        $venue_filter = $currentUser->primary_venue_id;
        $timezone = $currentUser->primaryVenue->time_zone;

        $results = ['dt_skipped' => [], 'dt_level_1' => [], 'dt_level_2' => [], 'dt_level_3' => [], 'dt_level_4' => [], 'dt_level_5' => [], 'dt_average' => []];

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
        $start_hour = Carbon::createFromTimestamp($args['start'], $timezone)->hour;
        $end_hour = Carbon::createFromTimestamp($args['end'], $timezone)->hour;
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
        $query = new WifiDailyStatsVenueUniqueDeviceUuids;
        $db = $query->getConnection()->getPdo();

        if ($start > 0 && $end > 0) {

            /**
             * check whether the user is allowed to view full venue stats or not
             * and act accordingly
             */
            $dt_threshold_1 = $venue_collection[0]['dt_threshold_1'];
            $dt_threshold_2 = $venue_collection[0]['dt_threshold_2'];
            $dt_threshold_3 = $venue_collection[0]['dt_threshold_3'];
            $dt_threshold_4 = $venue_collection[0]['dt_threshold_4'];
            $dt_threshold_5 = $venue_collection[0]['dt_threshold_5'];
            $dwell_bucket = $venue_collection[0]['footfall_bucket'];

            $db->exec("SET sql_mode             = 'NO_UNSIGNED_SUBTRACTION';");
            $db->exec("SET @venue_filter        = '{$venue_filter}'");
            $db->exec("SET @range_start         = '{$start}'");
            $db->exec("SET @range_end           = '{$end}'");
            $db->exec("SET @dwell_bucket        = '{$dwell_bucket}'");
            $db->exec("SET @prev_ts             = 0");
            $db->exec("SET @dwell_start         = 0");
            $db->exec("SET @prev_dwelltime      = 0");
            $db->exec("SET @prev_device_uuid    = NULL");
            $db->exec("SET @device_uuid_changed = FALSE");
            $db->exec("SET @long_gap            = FALSE");
            $db->exec("SET @gap                 = 0");
            $db->exec("SET @dt_threshold_1      = '{$dt_threshold_1}'");
            $db->exec("SET @dt_threshold_2      = '{$dt_threshold_2}'");
            $db->exec("SET @dt_threshold_3      = '{$dt_threshold_3}'");
            $db->exec("SET @dt_threshold_4      = '{$dt_threshold_4}'");
            $db->exec("SET @dt_threshold_5      = '{$dt_threshold_5}'");

            if ($currentUser->full_venue_view_allowed == 1) {

                /**
                 * user is allowed to view full venue stats
                 * first we prepare and execute the query
                 */
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
                              FROM wifi_daily_stats_venue_unique_device_uuids_per_hour
                             WHERE venue_id = @venue_filter
                               AND (day_epoch + (3600*hour)) >= :start
                               AND (day_epoch + (3600*hour)) < :end_1
                               AND (has_authorised = 1 OR is_authorised = 1)
                             ORDER BY device_uuid ASC, hour ASC
                            ) temp_results
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
                              FROM wifi_daily_stats_zone_unique_device_uuids_per_hour
                             WHERE venue_id = @venue_filter
                               AND FIND_IN_SET(zone_id, @zones)
                               AND (day_epoch + (3600*hour)) >= :start
                               AND (day_epoch + (3600*hour)) < :end_1
                               AND (has_authorised = 1 OR is_authorised = 1)
                             ORDER BY device_uuid ASC, hour ASC
                            ) temp_results
                        WHERE changed = TRUE
                        GROUP BY day_epoch
                    ) final_results'
                );
            }
        }

        /**
         * bind the parameters to the selected query
         */
        $initialresultsDurations->bindParam(':start', $start);
        $initialresultsDurations->bindParam(':end_1', $end);

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

    public function listWeatherDaily(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * filter on the user's primary_venue_id and get the user's timezone
         */
        $venue_filter = $currentUser->primary_venue_id;
        $timezone = $currentUser->primaryVenue->time_zone;

        /**
         * user is allowed to view full venue stats
         * prepare and execute the query for the daily stats for BODY
         */
        $start_hour = Carbon::createFromTimestamp($args['start'], $timezone)->hour;
        $end_hour = Carbon::createFromTimestamp($args['end'], $timezone)->hour;
        $start_date = Carbon::createFromTimestamp($args['start']/1000, $timezone);
        $end_date = Carbon::createFromTimestamp($args['end']/1000, $timezone);

        /**
         * get the start date and end date (start of day)
         */
        $start = $start_date->format('U');
        $end = $end_date->format('U');

        $weatherResults = new WifiDailyStatsVenueWeather;
        $initialResults = $weatherResults->select('day_epoch', 'temperature_max', 'temperature_min', 'wind_bearing', 'wind_speed', 'pressure', 'precip_total', 'icon', 'summary')
            ->where('day_epoch', '>=', $start)
            ->where('day_epoch', '<', $end)
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
            'temp_max' => [],
            'temp_min' => [],
            'pressure' => [],
            'precipitation' => []
        ];

        foreach ($initialResults as $result) {
            $results['temp_max'][] = ['x' => $result->day_epoch * 1000, 'y' => $result->temperature_max, 'icon' => $result->icon, 'summary' => $result->summary];
            $results['temp_min'][] = ['x' => $result->day_epoch * 1000, 'y' => $result->temperature_min];
            $results['pressure'][] = ['x' => $result->day_epoch * 1000, 'y' => $result->pressure];
            $results['precipitation'][] = ['x' => $result->day_epoch * 1000, 'y' => $result->precip_total];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listInternetUserReportZoneVisitorsComparison(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
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
        $start_hour = Carbon::createFromTimestamp($args['start'], $timezone)->hour;
        $end_hour = Carbon::createFromTimestamp($args['end'], $timezone)->hour;
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
        $query = new WifiDailyStatsZoneUniqueDeviceUuids;
        $db = $query->getConnection()->getPdo();

        if ($start > 0 && $end > 0) {
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
                    'SELECT zone_id,
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
                                       hour,
                                       zone_id,
                                       is_repeat
                                FROM wifi_daily_stats_zone_unique_device_uuids_per_hour
                                 WHERE (day_epoch + (3600*hour)) >= :start
                                 AND (day_epoch + (3600*hour)) < :end_1
                                 AND (has_authorised = 1 OR is_authorised = 1)
                                 AND venue_id = :venue_id
                            ) AS temp1
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
                $initialresultsVisitors = $db->prepare(
                    'SELECT zone_id,
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
                                       hour,
                                       zone_id,
                                       is_repeat
                                FROM wifi_daily_stats_zone_unique_device_uuids_per_hour
                                 WHERE (day_epoch + (3600*hour)) >= :start
                                 AND (day_epoch + (3600*hour)) < :end_1
                                 AND (has_authorised = 1 OR is_authorised = 1)
                                 AND venue_id = :venue_id
                                 AND zone_id IN (' . $ids_string . ')
                            ) AS temp1
                        ) AS temp2
                    INNER JOIN zone ON zone_id = zone.id 
                    GROUP BY zone_id'
                );
            }
        }

        /**
         * bind the parameters to the selected query
         */
        $initialresultsVisitors->bindParam(':start', $start);
        $initialresultsVisitors->bindParam(':end_1', $end);
        $initialresultsVisitors->bindParam(':venue_id', $venue_filter);

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

    public function listBrowserAveragesAlltime(Request $request, Response $response, $args) 
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
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
        $current_venue = $venueQuery->with('venue_wifi')->where('id', $currentUser->primary_venue_id)->first();

        /**
         * check whether the user is allowed to view full venue stats or not
         * and act accordingly
         */
        if ($currentUser->full_venue_view_allowed == 1) {
            /**
             * user is allowed to view full venue stats
             * prepare and execute the query
             */
            $totalVisitors = new WifiDailyStatsVenueVisitors;
            $first_day_epoch = $current_venue->venue_wifi->capture_start;

            $initialresultsTotalVisitors = $totalVisitors->selectRaw('SUM(has_authorised_device_uuid) AS visitors')
                ->where('venue_id', $venue_filter)
                ->where('day_epoch', '>=', $first_day_epoch)
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
            $totalVisitors = new WifiDailyStatsZoneVisitors;
            $first_day_epoch = $current_venue->venue_wifi->capture_start;

            $initialresultsTotalVisitors = $totalVisitors->selectRaw('SUM(has_authorised_device_uuid) AS visitors')
                ->where('venue_id', $venue_filter)
                ->whereIn('zone_id', $allowed_zones_ids)
                ->where('day_epoch', '>=', $first_day_epoch)
                ->get();
        }

        /**
         * add the total visitors from the stats and the visitors for today
         */
        $totalVisitorsAlltime = $initialresultsTotalVisitors[0]['visitors'];

        /**
         * use Carbon to be able to determine durations
         */
        $timezone = $currentUser->primaryVenue->time_zone;
        $first_day = Carbon::createFromTimestamp($first_day_epoch, $timezone);
        $today = Carbon::now($timezone);

        /**
         * calculate the averages
         * TODO: see how the yearly averages turn out, maybe use the months to get non-rounded year count
         */
        if($first_day->diffInDays($today) === 0) {
            $average_daily = $totalVisitorsAlltime;
        } else {
            $average_daily = round($totalVisitorsAlltime/($first_day->diffInDays($today)));
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
            'days' => $first_day->diffInDays($today),
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

    public function listVisitorsWithPostcodes(Request $request, Response $response, $args) 
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * filter on the user's primary_venue_id and get the user's timezone
         */
        $venue_filter = $currentUser->primary_venue_id;
        $timezone = $currentUser->primaryVenue->time_zone;
        $venue = Venue::find($venue_filter);

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
        $query = new WifiDailyStatsVenueUniqueDeviceUuids;
        $db = $query->getConnection()->getPdo();

        /**
         * create the prepared statement
         */
        $list_visitors_with_postcodes = $db->prepare('
            SELECT COUNT(CASE WHEN postcode IS NOT NULL THEN 1 END) AS postcode_count,
                COUNT(CASE WHEN postcode IS NULL THEN 1 END) AS non_postcode_count,
                day_epoch
            FROM
                (
                    SELECT DISTINCT(device_uuid),
                           day_epoch,
                           postcode
                      FROM
                            (
                              SELECT device_uuid,
                                     day_epoch,
                                     hour,
                                     postcode
                                FROM wifi_daily_stats_venue_unique_device_uuids_per_hour
                               WHERE (day_epoch + (3600*hour)) >= :start
                                 AND (day_epoch + (3600*hour)) < :end_1
                                 AND (has_authorised = 1 OR is_authorised = 1)
                                 AND venue_id = :venue_id
                            ) AS temp1
                ) AS temp2'
        );

        /**
         * bind the parameters to the selected query
         */
        $list_visitors_with_postcodes->bindParam(':start', $start);
        $list_visitors_with_postcodes->bindParam(':end_1', $end);
        $list_visitors_with_postcodes->bindParam(':venue_id', $venue_filter);

        /**
         * execute the query
         */
        $list_visitors_with_postcodes->execute();

        $results = [];
        foreach($list_visitors_with_postcodes as $list_visitors_with_postcode) {
            $results['postcode_count']     = (int)$list_visitors_with_postcode['postcode_count'];
            $results['non_postcode_count'] = (int)$list_visitors_with_postcode['non_postcode_count'];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listLocationVisitorBreakdown(Request $request, Response $response, $args) 
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * filter on the user's primary_venue_id and get the user's timezone
         */
        $venue_filter = $currentUser->primary_venue_id;
        $timezone = $currentUser->primaryVenue->time_zone;
        $venue = Venue::find($venue_filter);

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
        $query = new WifiDailyStatsVenueUniqueDeviceUuids;
        $db = $query->getConnection()->getPdo();

        /**
         * create the prepared statement
         */
        $list_visitor_postcodes = $db->prepare('
            SELECT DISTINCT(device_uuid),
                   postcode,
                   day_epoch
            FROM
            (
                SELECT device_uuid,
                     postcode,
                     day_epoch
                FROM wifi_daily_stats_venue_unique_device_uuids_per_hour
                WHERE day_epoch >= :start
                AND day_epoch < :end_1
                AND venue_id = :venue_id
                AND postcode IS NOT NULL
            ) AS temp1'
        );

        $list_postcodes = $db->prepare('
            SELECT postcode 
            FROM `wifi_daily_stats_venue_unique_device_uuids_per_hour` 
            WHERE day_epoch >= :start
            AND day_epoch < :end_1
            AND venue_id = :venue_id
            AND postcode IS NOT NULL
            GROUP BY postcode
        ');

        /**
         * bind the parameters to the selected query
         */
        $list_visitor_postcodes->bindParam(':start', $start);
        $list_visitor_postcodes->bindParam(':end_1', $end);
        $list_visitor_postcodes->bindParam(':venue_id', $venue_filter);
        $list_visitor_postcodes->execute();

        $list_postcodes->bindParam(':start', $start);
        $list_postcodes->bindParam(':end_1', $end);
        $list_postcodes->bindParam(':venue_id', $venue_filter);
        $list_postcodes->execute();

        /**
         * Get all the postcode details
         */
        $postcode_array = [];
        $index = 0;
        foreach($list_postcodes as $postcode) {
            if (count($postcode_array) == 0) {
                $postcode_array[$index][] = str_replace(' ', '', strtolower($postcode['postcode']));
            }
            else if (count($postcode_array[$index]) < 100) {
                $postcode_array[$index][] = str_replace(' ', '', strtolower($postcode['postcode']));
            }
            else {
                $index = $index + 1;
                $postcode_array[$index][] = str_replace(' ', '', strtolower($postcode['postcode']));
            }
        }

        // Initate the postcode API
        $postcodeFinder = new PostcodesIO();

        $temp_results = [];
        if (count($postcode_array) != 0) {
            $postcode_details = $postcodeFinder->bulkPostcodeSearch($postcode_array[0]);

            for ($i=1; $i < count($postcode_array); $i++) {
                $temp_postcode_details = $postcodeFinder->bulkPostcodeSearch($postcode_array[$i]);
                if ($temp_postcode_details->status != 404) {
                    foreach($temp_postcode_details->result as $result) {
                        $postcode_details->result[] = $result;
                    }
                }
            }

            // Get the venue address details
            $venueDetails = $postcodeFinder->findByLocation($venue->lat, $venue->lon);
            $venue_nuts = $venueDetails->result[0]->nuts;

            if ($postcode_details->status != 404) {
                foreach($list_visitor_postcodes as $visitor) {
                    $visitor_postcode = str_replace(' ', '', strtolower($visitor['postcode']));

                    $visitor_nuts   = '';
                    $visitor_parish = '';
                    foreach($postcode_details->result as $result) {
                        if ($result->query == $visitor_postcode && !empty($result->result)) {
                            $visitor_nuts   = $result->result->nuts;
                            $visitor_parish = $result->result->parish;
                        }
                    }

                    if ($visitor_nuts != '') {
                        if (!isset($temp_results['national'][$visitor_nuts][$visitor['day_epoch']])) {
                            $temp_results['national'][$visitor_nuts][$visitor['day_epoch']] = ['name' => $visitor_nuts, 'y' => 1, 'x' => $visitor['day_epoch']];
                        }
                        else {
                            $temp_results['national'][$visitor_nuts][$visitor['day_epoch']]['y']++;
                        }
                    }

                    if ($visitor_nuts != '' && $visitor_parish != '') {
                        if ($visitor_nuts == $venue_nuts) {
                            if (!isset($temp_results['local'][$visitor_parish][$visitor['day_epoch']])) {
                                $temp_results['local'][$visitor_parish][$visitor['day_epoch']] = ['name' => $visitor_parish, 'y' => 1, 'x' => $visitor['day_epoch']];
                            }
                            else {
                                $temp_results['local'][$visitor_parish][$visitor['day_epoch']]['y']++;
                            }
                        }
                    }
                }
            }
        }

        $results = [];
        // National results
        if (isset($temp_results['national'])) {
            foreach($temp_results['national'] as $temp_result) {
                foreach($temp_result as $data) {
                    $results['national'][$data['name']][] = [$data['x'] * 1000, $data['y']];
                }
            }
        }

        // Local results
        if (isset($temp_results['local'])) {
            foreach($temp_results['local'] as $temp_result) {
                foreach($temp_result as $data) {
                    $results['local'][$data['name']][] = [$data['x'] * 1000, $data['y']];
                }
            }
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function oldDataComparisonNewVsRepeat(Request $request, Response $response, $args) 
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
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
        $query = new WifiDailyStatsVenueUniqueDeviceUuids;
        $db = $query->getConnection()->getPdo();

        if ($start > 0 && $end > 0) {
            /**
             * user is allowed to view full venue stats
             * create the prepared statement
             */
            $visitor_counts = $db->prepare(
                'SELECT COUNT(CASE WHEN is_repeat = 0 THEN 1 END) AS new_visitors,
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
                                             is_repeat
                                        FROM wifi_daily_stats_venue_unique_device_uuids_per_hour
                                       WHERE day_epoch >= :start_time
                                         AND day_epoch < :end_time
                                         AND (has_authorised = 1 OR is_authorised = 1)
                                         AND venue_id = :venue_id
                                    ) AS temp1
                        ) AS temp2
                    GROUP BY day_epoch'
            );

            /**
             * bind the parameters to the selected query
             */
            $visitor_counts->bindParam(':start_time', $start);
            $visitor_counts->bindParam(':end_time', $end);
            $visitor_counts->bindParam(':venue_id', $venue_filter);

            /**
             * execute the query for total visitors
             */
            $visitor_counts->execute();
        }

        foreach($visitor_counts as $day) {
            $results['repeat'][] = [$day['day_epoch']*1000, (int)$day['repeat_visitors']];
            $results['new'][]    = [$day['day_epoch']*1000, (int)$day['new_visitors']];
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

    public function oldDataComparisonAgeBreakdown(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * filter on the user's primary_venue_id and get the user's timezone
         */
        $venue_filter = $currentUser->primary_venue_id;
        $timezone = $currentUser->primaryVenue->time_zone;

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
        $query = new WifiDailyStatsVenueUniqueDeviceUuids;
        $db = $query->getConnection()->getPdo();

        if ($start > 0 && $end > 0) {
            /**
             * user is allowed to view full venue stats
             * prepare and execute the query
             */
            $visitor_counts = $db->prepare("
                SELECT IF((age IS NULL), 'Undisclosed', concat(10*floor(age/10), '-', 10*floor(age/10) + 9)) as `range`,
                       COUNT(id) AS Total
                FROM
                (
                    SELECT day_epoch,
                           age,
                           id
                     FROM wifi_daily_stats_venue_unique_device_uuids_per_hour
                    WHERE day_epoch >= :start_time
                      AND day_epoch < :end_time
                      AND age >= 13
                      AND age < 100
                      AND venue_id = :venue_id
                      AND (has_authorised = 1 OR is_authorised = 1)
                      AND provider IS NOT NULL
                    GROUP BY device_uuid, day_epoch
                ) AS temp
                GROUP BY `range`
            ");
        }

        $visitor_counts->bindParam(':start_time', $start);
        $visitor_counts->bindParam(':end_time', $end);
        $visitor_counts->bindParam(':venue_id', $venue_filter);

        /**
         * execute the query
         */
        $visitor_counts->execute();

        $results = [];
        foreach ($visitor_counts as $item) {
            $results[] = [$item[0], (int)$item[1]];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listGenderCount(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * filter on the user's primary_venue_id and get the user's timezone
         */
        $venue_filter = $currentUser->primary_venue_id;
        $timezone = $currentUser->primaryVenue->time_zone;

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
        $identityQuery = new WifiDailyStatsVenueUniqueDeviceUuids;
        $db = $identityQuery->getConnection()->getPdo();

        if ($start > 0 && $end > 0) {
            /**
             * check whether the user is allowed to view full venue stats or not
             * and act accordingly
             */

            if ($currentUser->full_venue_view_allowed == 1) {
                /**
                 * user is allowed to view full venue stats
                 * prepare and execute the query
                 */
                $identity_gender_count_collection = $db->prepare("
                    SELECT SUM(IF(gender = 0, 1, 0)) AS Male,
                           SUM(IF(gender = 1, 1, 0)) AS Female,
                           SUM(IF(gender IS NULL, 1, 0)) AS `Undisclosed`
                    FROM
                    (
                        SELECT day_epoch,
                               hour,
                               gender
                        FROM wifi_daily_stats_venue_unique_device_uuids_per_hour
                        WHERE (day_epoch + (3600*hour)) >= :start
                         AND (day_epoch + (3600*hour)) < :end_1
                         AND venue_id = :venue_id
                         AND provider IS NOT NULL
                        GROUP BY device_uuid, day_epoch
                    ) AS temp
                ");
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
                $identity_gender_count_collection = $db->prepare("
                    SELECT SUM(IF(gender = 0, 1, 0)) AS Male,
                           SUM(IF(gender = 1, 1, 0)) AS Female,
                           SUM(IF(gender IS NULL, 1, 0)) AS `Undisclosed`
                    FROM
                    (
                        SELECT day_epoch,
                               hour,
                               gender
                        FROM wifi_daily_stats_venue_unique_device_uuids_per_hour
                        WHERE (day_epoch + (3600*hour)) >= :start
                         AND (day_epoch + (3600*hour)) < :end_1
                         AND venue_id = :venue_id
                         AND zone_id IN (' . $ids_string . ')
                         AND provider IS NOT NULL
                        GROUP BY device_uuid, day_epoch
                    ) AS temp
                ");
            }
        }

        $identity_gender_count_collection->bindParam(':start', $start);
        $identity_gender_count_collection->bindParam(':end_1', $end);
        $identity_gender_count_collection->bindParam(':venue_id', $venue_filter);

        /**
         * execute the query
         */
        $identity_gender_count_collection->execute();

        $results = [];
        foreach ($identity_gender_count_collection as $item) {
            $results = [['gender' => 'Male', 'count' => (int)$item[0]], ['gender' => 'Female', 'count' => (int)$item[1]], ['gender' => 'Undisclosed', 'count' => (int)$item[2]]];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function oldDataNewVsRepeat(Request $request, Response $response, $args) 
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
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
        $query = new OldDailyVenueStats;
        $db = $query->getConnection()->getPdo();

        if ($start > 0 && $end > 0) {
            /**
             * user is allowed to view full venue stats
             * create the prepared statement
             */
            $visitor_counts = $db->prepare(
                'SELECT total_count,
                        repeat_count,
                        day_epoch
                   FROM old_daily_venue_stats
                  WHERE day_epoch > :start_time
                    AND day_epoch <= :end_time
                    AND venue_id = :venue_id
                GROUP BY day_epoch'
            );

            /**
             * bind the parameters to the selected query
             */
            $visitor_counts->bindParam(':start_time', $start);
            $visitor_counts->bindParam(':end_time', $end);
            $visitor_counts->bindParam(':venue_id', $venue_filter);

            /**
             * execute the query for total visitors
             */
            $visitor_counts->execute();
        }

        foreach($visitor_counts as $day) {
            $results['new'][]    = [$day['day_epoch']*1000, (int)$day['total_count'] - (int)$day['repeat_count']];
            $results['repeat'][] = [$day['day_epoch']*1000, (int)$day['repeat_count']];
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

    public function oldDataGenderCount(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * filter on the user's primary_venue_id and get the user's timezone
         */
        $venue_filter = $currentUser->primary_venue_id;
        $timezone = $currentUser->primaryVenue->time_zone;

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
        $query = new OldDailyVenueStats;
        $db = $query->getConnection()->getPdo();

        if ($start > 0 && $end > 0) {
            /**
             * user is allowed to view full venue stats
             * create the prepared statement
             */
            $visitor_counts = $db->prepare(
                'SELECT SUM(male_count) AS male_count,
                        SUM(female_count) AS female_count,
                        SUM(unknown_gender_count) AS unknown_gender_count
                   FROM old_daily_venue_stats
                  WHERE day_epoch > :start_time
                    AND day_epoch <= :end_time
                    AND venue_id = :venue_id'
            );

            /**
             * bind the parameters to the selected query
             */
            $visitor_counts->bindParam(':start_time', $start);
            $visitor_counts->bindParam(':end_time', $end);
            $visitor_counts->bindParam(':venue_id', $venue_filter);

            /**
             * execute the query for total visitors
             */
            $visitor_counts->execute();
        }

        $results = [];
        foreach($visitor_counts as $visitor_count) {
            $results = [(int)$visitor_count['male_count'], (int)$visitor_count['female_count'], (int)$visitor_count['unknown_gender_count']];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function oldDataAgeBreakdown(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * filter on the user's primary_venue_id and get the user's timezone
         */
        $venue_filter = $currentUser->primary_venue_id;
        $timezone = $currentUser->primaryVenue->time_zone;

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
        $query = new OldDailyVenueStats;
        $db = $query->getConnection()->getPdo();

        if ($start > 0 && $end > 0) {
            /**
             * user is allowed to view full venue stats
             * create the prepared statement
             */
            $visitor_counts = $db->prepare(
                'SELECT SUM(range_10_19) AS range_13_19,
                        SUM(range_20_29) AS range_20_29,
                        SUM(range_30_39) AS range_30_39,
                        SUM(range_40_49) AS range_40_49,
                        SUM(range_50_59) AS range_50_59,
                        SUM(range_60_69) AS range_60_69,
                        SUM(range_70_79) AS range_70_79,
                        SUM(range_80_89) AS range_80_89,
                        SUM(range_90_99) AS range_90_99,
                        SUM(range_100_plus) AS range_100_plus
                   FROM old_daily_venue_stats
                  WHERE day_epoch > :start_time
                    AND day_epoch <= :end_time
                    AND venue_id = :venue_id'
            );

            /**
             * bind the parameters to the selected query
             */
            $visitor_counts->bindParam(':start_time', $start);
            $visitor_counts->bindParam(':end_time', $end);
            $visitor_counts->bindParam(':venue_id', $venue_filter);

            /**
             * execute the query for total visitors
             */
            $visitor_counts->execute();
        }

        $results = [];
        foreach($visitor_counts as $visitor_count) {
            $results = 
                    [
                        ['name' => '13 - 19', 'y' => (int)$visitor_count['range_13_19']],
                        ['name' => '20 - 29', 'y' => (int)$visitor_count['range_20_29']],
                        ['name' => '30 - 39', 'y' => (int)$visitor_count['range_30_39']],
                        ['name' => '40 - 49', 'y' => (int)$visitor_count['range_40_49']],
                        ['name' => '50 - 59', 'y' => (int)$visitor_count['range_50_59']],
                        ['name' => '60 - 69', 'y' => (int)$visitor_count['range_60_69']],
                        ['name' => '70 - 79', 'y' => (int)$visitor_count['range_70_79']],
                        ['name' => '80 - 89', 'y' => (int)$visitor_count['range_80_89']],
                        ['name' => '90 - 99', 'y' => (int)$visitor_count['range_90_99']],
                        ['name' => '100+', 'y' => (int)$visitor_count['range_100_plus']]
                    ];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function oldDataStartEndDates(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
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
         * prepare the query using a "random" PDO connection
         */
        $query = new OldDailyVenueStats;
        $db = $query->getConnection()->getPdo();

        /**
         * user is allowed to view full venue stats
         * create the prepared statement
         */
        $oldDataDates = $db->prepare(
            'SELECT MIN(day_epoch) AS start_date, MAX(day_epoch) AS end_date 
             FROM old_daily_venue_stats 
             WHERE venue_id = :venue_id'
        );

        /**
         * bind the parameters to the selected query
         */
        $oldDataDates->bindParam(':venue_id', $venue_filter);

        /**
         * execute the query for total visitors
         */
        $oldDataDates->execute();

        foreach($oldDataDates as $dates) {
            $results['start_date'] = $dates['start_date'];
            $results['end_date']   = $dates['end_date'];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listAllUserStats(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $historyhours = (int)$args['historyhours'];

        /**
         * validate whether the timestamp for $historyhours is an integer
         * else stop the process
         */
        if($historyhours) {
            if(is_numeric($historyhours) && (int)$historyhours == $historyhours) {
                /**
                 * all is well
                 */
            } else {
                /**
                 * we have an incorrect $start
                 */
                error_log('we have an incorrect $historyhours');
                return $response->withStatus(400);
            }
        }

        /**
         * connect to the UniFi controller
         */
        $unifidata = $this->connectController();
        $results = $unifidata->stat_allusers($historyhours);

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listAllUserSessions(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $start = (int)$args['start'];
        $end = (int)$args['end'];

        /**
         * validate whether timestamps for $start and $end are integers
         * else stop the process
         */
        if($start) {
            if(is_numeric($start) && (int)$start == $start) {
                /**
                 * all is well
                 */
            } else {
                /**
                 * we have an incorrect $start
                 */
                error_log('we have an incorrect $start');
                return $response->withStatus(400);
            }
        }

        if($end) {
            if(is_numeric($end) && (int)$end == $end) {
                /**
                 * all is well
                 */
            } else {
                /**
                 * we have an incorrect $end
                 */
                error_log('we have an incorrect $end');
                return $response->withStatus(400);
            }
        }

        $start = round($start/1000);
        $end = round($end/1000);

        /**
         * connect to the UniFi controller
         */
        $unifidata = $this->connectController();
        $results = $unifidata->stat_sessions($start, $end);

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listNetworkReportDeviceInfo(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
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
        $start_hour = Carbon::createFromTimestamp($args['start'], $timezone)->hour;
        $end_hour = Carbon::createFromTimestamp($args['end'], $timezone)->hour;
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
        $query = new WifiDailyStatsVenueUniqueDeviceUuids;
        $db = $query->getConnection()->getPdo();

        if ($start > 0 && $end > 0) {
            /**
             * check whether the user is allowed to view full venue stats or not
             * and act accordingly
             */

            if ($currentUser->full_venue_view_allowed == 1) {
                /**
                 * user is allowed to view full venue stats
                 * prepare and execute the query
                 */
                $initialresultsDevices = $db->prepare(
                    "SELECT device.os,
                            device.browser,
                            device.brand
                     FROM wifi_daily_stats_venue_unique_device_uuids_per_hour
                     LEFT OUTER JOIN device
                             ON wifi_daily_stats_venue_unique_device_uuids_per_hour.device_uuid = device.device_uuid
                     WHERE (day_epoch + (3600*hour)) >= :start 
                      AND (day_epoch + (3600*hour)) < :end_1 
                      AND wifi_daily_stats_venue_unique_device_uuids_per_hour.venue_id = :venue_id
                      AND device.os IS NOT NULL
                     GROUP BY wifi_daily_stats_venue_unique_device_uuids_per_hour.device_uuid, day_epoch"
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
                $initialresultsDevices = $db->prepare(
                    "SELECT device.os,
                            device.browser,
                            device.brand
                     FROM wifi_daily_stats_venue_unique_device_uuids_per_hour
                     LEFT OUTER JOIN device
                             ON wifi_daily_stats_venue_unique_device_uuids_per_hour.device_uuid = device.device_uuid
                     WHERE (day_epoch + (3600*hour)) >= :start 
                      AND (day_epoch + (3600*hour)) < :end_1 
                      AND wifi_daily_stats_venue_unique_device_uuids_per_hour.venue_id = :venue_id
                      AND device.os IS NOT NULL
                      AND zone_id IN (' . $ids_string . ')
                     GROUP BY wifi_daily_stats_venue_unique_device_uuids_per_hour.device_uuid, day_epoch"
                );
            }
        }

        /**
         * bind the parameters to the selected query
         */
        $initialresultsDevices->bindParam(':start', $start);
        $initialresultsDevices->bindParam(':end_1', $end);
        $initialresultsDevices->bindParam(':venue_id', $venue_filter);

        /**
         * execute the query for total visitors
         */
        $initialresultsDevices->execute();

        foreach ($initialresultsDevices as $item) {
            $results[] = [$item['os'], $item['browser'], $item['brand']];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listHourlyApStats(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $start = $args['start'];
        $end = $args['end'];

        /**
         * validate whether timestamps for $start and $end are integers
         * else stop the process
         */
        if($start) {
            if(is_numeric($start) && (int)$start == $start) {
                /**
                 * all is well
                 */
            } else {
                /**
                 * we have an incorrect $start
                 */
                error_log('we have an incorrect $start');
                return $response->withStatus(400);
            }
        }

        if($end) {
            if(is_numeric($end) && (int)$end == $end) {
                /**
                 * all is well
                 */
            } else {
                /**
                 * we have an incorrect $end
                 */
                error_log('we have an incorrect $end');
                return $response->withStatus(400);
            }
        }

        /**
         * connect to the UniFi controller
         */
        $unifidata = $this->connectController();
        $stats = $unifidata->stat_hourly_aps($start, $end);
        $aps = $unifidata->list_aps();
        $results[0] = $aps;
        $results[1] = $stats;

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listHourlyVenueStats(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $start = $args['start'];
        $end = $args['end'];

        /**
         * validate whether timestamps for $start and $end are integers
         * else stop the process
         */
        if($start) {
            if(is_numeric($start) && (int)$start == $start) {
                /**
                 * all is well
                 */
            } else {
                /**
                 * we have an incorrect $start
                 */
                error_log('we have an incorrect $start');
                return $response->withStatus(400);
            }
        }

        if($end) {
            if(is_numeric($end) && (int)$end == $end) {
                /**
                 * all is well
                 */
            } else {
                /**
                 * we have an incorrect $end
                 */
                error_log('we have an incorrect $end');
                return $response->withStatus(400);
            }
        }

        /**
         * connect to the UniFi controller
         */
        $unifidata = $this->connectController();
        $results   = $unifidata->stat_hourly_site($start, $end);

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listUserLoginsStatsHourly(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $identityQuery = new Identity;
        $total = $identityQuery->count();

        /**
         * convert $start and $end to MySQL dates
         */
        $start = floor($args['start']/1000);
        $end = floor($args['end']/1000);
        $timezone = $currentUser->primaryVenue->time_zone;
        $startDate = Carbon::createFromTimestamp($start, $timezone)->toIso8601String();
        $endDate = Carbon::createFromTimestamp($end, $timezone)->toIso8601String();

        /**
         * Get identities filtered by the primary_venue_id of logged in user
         */
        $identity_collection = $identityQuery
            ->selectRaw('DISTINCT (UNIX_TIMESTAMP(DATE_FORMAT(identity.updated_at,"%Y-%m-%d %H:00:00")) *1000) AS epoch_date, count(DISTINCT(identity.id)) AS registrations')
            ->join('venue_wifi_user', 'venue_wifi_user.user_id', '=', 'identity.user_id')
            ->where('venue_wifi_user.venue_id', $this->_app->user->primary_venue_id)
            ->whereBetween('identity.updated_at', [$startDate, $endDate])
            ->groupBy('epoch_date')
            ->get();

        $total_filtered = count($identity_collection);

        $result['registrations'] = [
            "count_filtered" => $total_filtered,
            "rows" => $identity_collection->values()->toArray()
        ];

        /**
         * Get logins filtered by the primary_venue_id of logged in user
         */
        $sessionQuery     = new Session;
        $login_collection = $sessionQuery
            ->selectRaw('DISTINCT (UNIX_TIMESTAMP(DATE_FORMAT(updated_at,"%Y-%m-%d %H:00:00")) *1000) AS epoch_date, count(DISTINCT(id)) AS returning_logins')
            ->where('venue_id', $this->_app->user->primary_venue_id)
            ->where('auth_status', 1)
            ->where('identity_id', 0)
            ->whereBetween('updated_at', [$startDate, $endDate])
            ->groupBy('epoch_date')
            ->get();

        $total_filtered = count($login_collection);

        $result['returning_logins'] = [
            "count_filtered" => $total_filtered,
            "rows"           => $login_collection->values()->toArray()
        ];

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listRegisteredEmailStats(Request $request, Response $response, $args) 
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
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
        $start_hour = Carbon::createFromTimestamp($args['start'], $timezone)->hour;
        $end_hour = Carbon::createFromTimestamp($args['end'], $timezone)->hour;
        $start_date = Carbon::createFromTimestamp($args['start']/1000, $timezone);
        $end_date = Carbon::createFromTimestamp($args['end']/1000, $timezone);

        /**
         * get the start date and end date (start of day)
         */
        $start = $start_date->format('U');
        $end = $end_date->format('U');

        if ($start > 0 && $end > 0) {
            /**
             * check whether the user is allowed to view full venue stats or not
             * and act accordingly
             */

            if ($currentUser->full_venue_view_allowed == 1) {
                /**
                 * user is allowed to view full venue stats
                 * create the prepared statement
                 */
                $email_stats = WifiDailyStatsVenueEmailStatuses::selectRaw('SUM(valid) AS valid, SUM(invalid) AS invalid, SUM(catch_all) AS catch_all, SUM(`unknown`) AS unknown')
                    ->where('venue_id', $venue_filter)
                    ->where('day_epoch', '>=', $start)
                    ->where('day_epoch', '<', $end)
                    ->first();
            }
        }

        $results[0] = ['name' => 'valid', 'y' => $email_stats['valid']];
        $results[1] = ['name' => 'invalid', 'y' => $email_stats['invalid']];
        $results[2] = ['name' => 'catch_all', 'y' => $email_stats['catch_all']];
        $results[3] = ['name' => 'unknown', 'y' => $email_stats['unknown']];

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listEmailsSentCount(Request $request, Response $response, $args) 
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
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
         * - determine full days within the body
         * - get daily stats for it
         * - determine whether tail is today or not
         *  - yes: we get data from probe_requests
         *  - no: we get hourly stats for it
         * - determine head and get hourly stats for it
         * - using Carbon to copy timestamps you need to use the copy function!!!
         *************************************************************************************/
        $start_date  = Carbon::createFromTimestamp($args['start']/1000, $timezone);
        $end_date = Carbon::createFromTimestamp($args['end']/1000, $timezone);

        /**
         * get the start date and end date (start of day)
         */
        $start = $start_date->format('U');
        $end = $end_date->format('U');

        if ($start > 0 && $end > 0) {
            /**
             * check whether the user is allowed to view full venue stats or not
             * and act accordingly
             */

            if ($currentUser->full_venue_view_allowed == 1) {
                /**
                 * user is allowed to view full venue stats
                 * create the prepared statement
                 */
                $email_stats = WifiDailyStatsVenueEmailStatuses::selectRaw('day_epoch, emails_sent')
                    ->where('venue_id', $venue_filter)
                    ->where('day_epoch', '>=', $start)
                    ->where('day_epoch', '<', $end)
                    ->groupBy('day_epoch')
                    ->get();
            }
        }

        foreach($email_stats as $email_stat) {
            array_push($results, [$email_stat['day_epoch'] * 1000, $email_stat['emails_sent']]);
        } 

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function webTitanFilteringData(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * filter on the user's primary_venue_id and get the user's timezone
         */
        $venue_filter = $currentUser->primary_venue_id;
        $timezone = $currentUser->primaryVenue->time_zone;

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
        $start_date  = Carbon::createFromTimestamp($args['start']/1000, $timezone);
        $end_date = Carbon::createFromTimestamp($args['end']/1000, $timezone);

        /**
         * get the start date and end date (start of day)
         */
        $start = $start_date->format('U');
        $end = $end_date->format('U');

        /**
         * create the prepared statement
         */
        $filtering_data = WifiDailyStatsVenueWebTitanPerDay::where('venue_id', $venue_filter)
            ->where('day_epoch', '>=', $start)
            ->where('day_epoch', '<', $end)
            ->get();

        /**
         * Initialise the arrays
         */
        $allowed_domains = [];
        $blocked_domains = [];
        $allowed_categories = [];
        $blocked_categories = [];

        foreach($filtering_data as $data) {
            /**
             * Decode the result into seperate arrays
             */
            $allowed_domains_array = json_decode($data['allowed_domains']);
            $blocked_domains_array = json_decode($data['blocked_domains']);
            $allowed_categories_array = json_decode($data['allowed_categories']);
            $blocked_categories_array = json_decode($data['blocked_categories']);

            /**
             * Create a allowed domains array
             */
            if (!empty($allowed_domains_array)) {
                foreach($allowed_domains_array as $allowed_domain) {
                    if(array_key_exists($allowed_domain->name, $allowed_domains)) {
                        $allowed_domains[$allowed_domain->name]->count += $allowed_domain->count;
                    } else {
                        $allowed_domains[$allowed_domain->name] = $allowed_domain;
                    }
                }
            }

            /**
             * Create a blocked domains array
             */
            if (!empty($blocked_domains_array)) {
                foreach($blocked_domains_array as $blocked_domain) {
                    if(array_key_exists($blocked_domain->name, $blocked_domains)) {
                        $blocked_domains[$blocked_domain->name]->count += $blocked_domain->count;
                    } else {
                        $blocked_domains[$blocked_domain->name] = $blocked_domain;
                    }
                }
            }

            /**
             * Create a allowed categories array
             */
            if (!empty($allowed_categories_array)) {
                foreach($allowed_categories_array as $allowed_category) {
                    // error_log($allowed_category->name . ' : ' . $allowed_category->count);
                    if(array_key_exists($allowed_category->name, $allowed_categories)) {
                        $allowed_categories[$allowed_category->name]->count += $allowed_category->count;
                    } else {
                        $allowed_categories[$allowed_category->name] = $allowed_category;
                    }
                }
            }

            /**
             * Create a blocked categories array
             */
            if (!empty($blocked_categories_array)) {
                foreach($blocked_categories_array as $blocked_category) {
                    if(array_key_exists($blocked_category->name, $blocked_categories)) {
                        $blocked_categories[$blocked_category->name]->count += $blocked_category->count;
                    } else {
                        $blocked_categories[$blocked_category->name] = $blocked_category;
                    }
                }
            }
        }

        /**
         * Merge all the arrays into a multidimensional array
         */
        $webTitanStats['allowed_domains'] = array_values(array_slice($allowed_domains, 0, 9));
        $webTitanStats['blocked_domains'] = array_values(array_slice($blocked_domains, 0, 9));
        $webTitanStats['allowed_categories'] = array_values(array_slice($allowed_categories, 0, 9));
        $webTitanStats['blocked_categories'] = array_values(array_slice($blocked_categories, 0, 9));

        /**
         * Sort all the arrays in ascending order
         */
        usort($webTitanStats['allowed_categories'], function ($a, $b) {
            // return $b->count <=> $a->count;
            if ($a->count == $b->count) {
                return 0;
            }
            return ($a->count < $b->count) ? -1 : 1;
        });
        usort($webTitanStats['blocked_domains'], function ($a, $b) {
            // return $b->count <=> $a->count;
            if ($a->count == $b->count) {
                return 0;
            }
            return ($a->count < $b->count) ? -1 : 1;
        });
        usort($webTitanStats['allowed_categories'], function ($a, $b) {
            // return $b->count <=> $a->count;
            if ($a->count == $b->count) {
                return 0;
            }
            return ($a->count < $b->count) ? -1 : 1;
        });
        usort($webTitanStats['blocked_categories'], function ($a, $b) {
            // return $b->count <=> $a->count;
            if ($a->count == $b->count) {
                return 0;
            }
            return ($a->count < $b->count) ? -1 : 1;
        });

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($webTitanStats, 200, JSON_PRETTY_PRINT);
    }

    public function totalUniqueVenueVisitors(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * filter on the user's primary_venue_id
         */
        $venue_filter = $currentUser->primary_venue_id;
        $timezone = $currentUser->primaryVenue->time_zone;

        /**
         * Get venue filtered by the primary_venue_id of logged in user
         */
        $venueQuery = new Venue;
        $current_venue = $venueQuery->where('id', $currentUser->primary_venue_id)->first();

        $start_time = Carbon::createFromTimestamp($args['start']/1000, $timezone)->format('U');
        $end_time = Carbon::createFromTimestamp($args['end']/1000, $timezone)->format('U');

        /**
         * initialise several variables
         */
        $results = [];

        /**
         * prepare the query using a "random" PDO connection
         */
        $query = new Identity;
        $db = $query->getConnection()->getPdo();

        /**
         * user is allowed to view full venue stats
         * create the prepared statement
         */
        $visitorCounts = $db->prepare('
            SELECT COUNT(*) AS visitors
            FROM identity
            WHERE venue_id = :venue_id
            AND created_at >= FROM_UNIXTIME(:start)
            AND created_at < FROM_UNIXTIME(:end_1)'
        );

        /**
         * bind the parameters to the selected query
         */
        $visitorCounts->bindParam(':start', $start_time);
        $visitorCounts->bindParam(':end_1', $end_time);
        $visitorCounts->bindParam(':venue_id', $venue_filter);

        /**
         * execute the query for total visitors
         */
        $visitorCounts->execute();

        // This is used to get the old wifi portal stats
        $venue_wifi = VenueWifi::where('venue_id', $venue_filter)->first();

        foreach($visitorCounts as $count) {
            /**
             * add the total visitors from the stats and the visitors for today
             */
            if ($args['type'] == 'all')
                $totalVisitorsAlltime = $count['visitors'] + $venue_wifi->old_unique_total;
            else
                $totalVisitorsAlltime = $count['visitors'];

            /**
             * use Carbon to be able to determine durations
             */
            $timezone  = $currentUser->primaryVenue->time_zone;
            $start_day = Carbon::createFromTimestamp($start_time, $timezone);
            $today     = Carbon::now($timezone);

            /**
             * calculate the averages
             * TODO: see how the yearly averages turn out, maybe use the months to get non-rounded year count
             */
            if($start_day->diffInDays($today) === 0) {
                $average_daily = $totalVisitorsAlltime;
            } else {
                $average_daily = round($totalVisitorsAlltime/($start_day->diffInDays($today)));
            }

            if($start_day->diffInWeeks($today) === 0) {
                $average_weekly = $totalVisitorsAlltime;
            } else {
                $average_weekly = round($totalVisitorsAlltime/$start_day->diffInWeeks($today));
            }

            if($start_day->diffInMonths($today) === 0) {
                $average_monthly = $totalVisitorsAlltime;
            } else {
                $average_monthly = round($totalVisitorsAlltime/$start_day->diffInMonths($today));
            }

            $results = [
                'total' => $totalVisitorsAlltime,
                'days' => $start_day->diffInDays($today),
                'average_daily' => $average_daily,
                'average_weekly' => $average_weekly,
                'average_monthly' => $average_monthly
            ];
        }

        /**
         * user is allowed to view full venue stats
         * create the prepared statement
         */
        $marketingCount = $db->prepare(
            'SELECT COUNT(DISTINCT(identity_list.identity_id)) AS marketing_total
             FROM wifi_daily_stats_venue_unique_device_uuids_per_hour AS `connection`
             LEFT JOIN device ON device.device_uuid = `connection`.device_uuid AND device.venue_id = :venue_id1
             LEFT JOIN `session` ON `session`.device_id = device.id AND `session`.venue_id = :venue_id2
             LEFT JOIN identity ON identity.id = `session`.identity_id
             LEFT JOIN identity_list ON identity_list.identity_id = identity.id
             WHERE `connection`.venue_id = :venue_id3
             AND `connection`.day_epoch >= :start_date
             AND `connection`.day_epoch < :end_date'
        );

        /**
         * bind the parameters to the selected query
         */
        $marketingCount->bindParam(':venue_id1', $venue_filter);
        $marketingCount->bindParam(':venue_id2', $venue_filter);
        $marketingCount->bindParam(':venue_id3', $venue_filter);
        $marketingCount->bindParam(':start_date', $start_time);
        $marketingCount->bindParam(':end_date', $end_time);

        /**
         * execute the query for total visitors
         */
        $marketingCount->execute();

        foreach($marketingCount as $count) {
            $results['marketing_total'] = ($args['type'] == 'all') ? $count['marketing_total'] + $venue_wifi->old_marketing_total : $count['marketing_total'];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function visitorDetails(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $venue_filter = $currentUser->primary_venue_id;

        /**
         * create the prepared statement
         */
        $email_stats = User::select()->with(['identities' => function($q) use ($venue_filter) {
            $q->with('sessions.device', 'marketing_lists')->where('venue_id', $venue_filter)->orderBy('provider', 'ASC');
        }])->where('id', $args['user_id'])->first();

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($email_stats, 200, JSON_PRETTY_PRINT);
    }

    public function listVisitorBasicDetails(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * filter on the user's primary_venue_id and get the user's timezone
         */
        $venue_filter = $currentUser->primary_venue_id;
        $timezone = $currentUser->primaryVenue->time_zone;

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
        $query = new WifiClientConnection;
        $db = $query->getConnection()->getPdo();

        /**
         * Get all the marketing_lists for this venue
         */
        $marketing_lists = MarketingList::where('venue_id', $venue_filter)->get();

        /**
         * prepare and execute the query
         */
        $initialresultsVisitors = $db->prepare(
            "SELECT *
            FROM
            (
            SELECT identity.id AS identity_id,
                identity.first_name AS first_name, 
                identity.last_name AS last_name,
                identity.gender AS gender,
                identity.birth_date AS birth_date,
                identity.email_address AS email_address,
                identity.avatar_url AS avatar_url, 
                identity.provider AS provider,
                identity.postcode AS postcode,
                identity.county AS county,
                identity.hometown AS hometown,
                MAX(day_epoch) AS last_seen,
                users.id AS user_id,
                identity.profile_id,
                identity.created_at
            FROM wifi_daily_stats_venue_unique_device_uuids_per_hour AS `connection`
            LEFT JOIN device ON connection.device_uuid = device.device_uuid AND device.venue_id = :venue_id1
            LEFT JOIN `session` ON device.id = `session`.device_id AND device.venue_id = :venue_id2
            LEFT JOIN `identity` ON `session`.identity_id = identity.id
            LEFT JOIN users ON `identity`.`user_id` = users.id
            LEFT JOIN venue_wifi_user ON `venue_wifi_user`.`user_id` = `identity`.`user_id`
            LEFT JOIN `identity_list` ON identity.id = identity_list.identity_id
            WHERE (day_epoch + (3600*hour)) >= :start
            AND (day_epoch + (3600*hour)) < :end_1
            AND identity.first_name != ''
            AND venue_wifi_user.venue_id = :venue_id3
            AND identity_list.id IS NOT NULL
            GROUP BY `connection`.device_uuid, identity.provider
            ORDER BY device.last_seen DESC, identity.provider DESC
            ) AS temp
            GROUP BY email_address"
        );

        /**
         * bind the parameters to the selected query
         */
        $initialresultsVisitors->bindParam(':start', $start);
        $initialresultsVisitors->bindParam(':end_1', $end);
        $initialresultsVisitors->bindParam(':venue_id1', $venue_filter);
        $initialresultsVisitors->bindParam(':venue_id2', $venue_filter);
        $initialresultsVisitors->bindParam(':venue_id3', $venue_filter);

        /**
         * execute the query for total visitors
         */
        $initialresultsVisitors->execute();

        $results = [];
        foreach($initialresultsVisitors as $visitors) {
            $identity_id = $visitors['identity_id'];

            $marketing_lists = MarketingList::whereHas('identities', function($q) use ($identity_id ) {
                $q->where('identity_id', $identity_id);
            })->get();

            array_push($results, array(
                'first_name' => $visitors['first_name'],
                'last_name' => $visitors['last_name'],
                'gender' => $visitors['gender'],
                'birth_date' => $visitors['birth_date'],
                'email_address' => $visitors['email_address'],
                'provider' => $visitors['provider'],
                'avatar_url' => $visitors['avatar_url'],
                'postcode' => $visitors['postcode'],
                'county' => $visitors['county'],
                'hometown' => $visitors['hometown'],
                'last_seen' => $visitors['last_seen'],
                'marketing_lists' => $marketing_lists,
                'user_id' => $visitors['user_id'],
                'profile_id' => $visitors['profile_id'],
                'created_at' => $visitors['created_at']
            ));
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listDailyVenueStats(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $start = (int)$args['start'];
        $end = (int)$args['end'];

        /**
         * validate whether timestamps for $start and $end are integers
         * else stop the process
         */
        if($start) {
            if(is_numeric($start) && (int)$start == $start) {
                /**
                 * all is well
                 */
            } else {
                /**
                 * we have an incorrect $start
                 */
                error_log('we have an incorrect $start');
                return $response->withStatus(400);
            }
        }

        if($end) {
            if(is_numeric($end) && (int)$end == $end) {
                /**
                 * all is well
                 */
            } else {
                /**
                 * we have an incorrect $end
                 */
                error_log('we have an incorrect $end');
                return $response->withStatus(400);
            }
        }

        /**
         * connect to the UniFi controller
         */
        $unifidata = $this->connectController();
        $results = $unifidata->stat_daily_site($start, $end);

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listAverageVisitorsTimeOfDay(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $start = (int)$args['start'];
        $end = (int)$args['end'];

        /**
         * validate whether timestamps for $start and $end are integers
         * else stop the process
         */
        if($start) {
            if(is_numeric($start) && (int)$start == $start) {
                /**
                 * all is well
                 */
            } else {
                /**
                 * we have an incorrect $start
                 */
                error_log('we have an incorrect $start');
                return $response->withStatus(400);
            }
        }

        if($end) {
            if(is_numeric($end) && (int)$end == $end) {
                /**
                 * all is well
                 */
            } else {
                /**
                 * we have an incorrect $end
                 */
                error_log('we have an incorrect $end');
                return $response->withStatus(400);
            }
        }

        /**
         * connect to the Unifi controller
         */
        $unifidata = $this->connectController();
        $hourly_stats = $unifidata->stat_hourly_site($start, $end);

        $temp_results = [[0,0], [1,0], [2,0], [3,0], [4,0], [5,0], [6,0], [7,0], [8,0], [9,0], [10,0], [11,0],
                         [12,0], [13,0], [14,0], [15,0], [16,0], [17,0], [18,0], [19,0], [20,0], [21,0], [22,0], [23,0]];

        $timezone = $currentUser->primaryVenue->time_zone;

        /**
         * summarize the WIRELESS user count for each hour of the day and push into temp_results array
         */
        foreach ($hourly_stats as $stat) {
            if (!empty($stat->num_sta) && !empty($stat->time)) {
                if (property_exists($stat, 'wlan-num_sta')) {
                    $wireless_users = $stat->{'wlan-num_sta'};
                } else {
                    $wireless_users = 0;
                }

                $temp_results[Carbon::createFromTimestamp($stat->time/1000, $timezone)->hour][1] += $wireless_users;
            }
        }

        /**
         * determine length of date range in days, if 0 (shorter than a day) then set to 1
         */
        $range_length = Carbon::createFromTimestamp($start/1000, $timezone)->diffInDays(Carbon::createFromTimestamp($end/1000, $timezone));

        if ($range_length == 0) {
            $range_length = 1;
        }

        /**
         * final results array and populate it with the user averages over the time frame in days
         */
        $results = [];

        foreach ($temp_results as $item) {
            if ($item[1] > 0) {
                $item[1] = ceil($item[1]/$range_length);
            }
            $results[] = $item;
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listAverageVisitorsWeekDay(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $start = (int)$args['start'];
        $end = (int)$args['end'];

        /**
         * validate whether timestamps for $start and $end are integers
         * else stop the process
         */
        if($start) {
            if(is_numeric($start) && (int)$start == $start) {
                /**
                 * all is well
                 */
            } else {
                /**
                 * we have an incorrect $start
                 */
                error_log('we have an incorrect $start');
                return $response->withStatus(400);
            }
        }

        if($end) {
            if(is_numeric($end) && (int)$end == $end) {
                /**
                 * all is well
                 */
            } else {
                /**
                 * we have an incorrect $end
                 */
                error_log('we have an incorrect $end');
                return $response->withStatus(400);
            }
        }

        /**
         * connect to the Unifi controller
         */
        $unifidata = $this->connectController();
        $daily_stats = $unifidata->stat_daily_site($start, $end);

        /**
         * get the current user's time zone and locale
         */
        $timezone = $currentUser->primaryVenue->time_zone;
        setlocale(LC_TIME, $currentUser->locale);

        /**
         * summarize the WIRELESS user count for each weekday and push into temp_results array
         * - pre-populate the array below with days based on the locale
         * - keep count of the number of data points per weekday in index 2 of each array
         * TODO:
         */
        $timestamp = strtotime('next Sunday');
        $weekdays  = [];
        for ($i = 0; $i < 7; $i++) {
            $weekdays[] = strftime('%A', $timestamp);
            $timestamp  = strtotime('+1 day', $timestamp);
        }

        $temp_results = [[$weekdays[1],0,0], [$weekdays[2],0,0], [$weekdays[3],0,0], [$weekdays[4],0,0], [$weekdays[5],0,0], [$weekdays[6],0,0], [$weekdays[0],0,0]];

        foreach ($daily_stats as $stat) {
            if (!empty($stat->num_sta) && !empty($stat->time)) {
                if (property_exists($stat, 'wlan-num_sta')) {
                    $wireless_users = $stat->{'wlan-num_sta'};
                } else {
                    $wireless_users = 0;
                }

                $temp_results[Carbon::createFromTimestamp($stat->time/1000, $timezone)->format('N') - 1][1] += $wireless_users;
                $temp_results[Carbon::createFromTimestamp($stat->time/1000, $timezone)->format('N') - 1][2] += 1;
            }
        }

        /**
         * final results array: populate it with the user averages using the data points count stored in $item[2]
         */
        $results = [];

        foreach ($temp_results as $item) {
            /**
             * calculate the average, then remove the data points count for $item
             */
            if ($item[1] > 0) {
                $item[1] = ceil($item[1]/$item[2]);
            }

            unset($item[2]);
            $results[] = $item;
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listAllUnifiUsers(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * connect to the UniFi controller
         */
        $unifidata = $this->connectController();
        $results['clients'] = $unifidata->stat_allusers();
        $results['guests']  = $unifidata->list_guests();

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
        if (!$authorizer->checkAccess($currentUser, 'uri_wifi_dashboard')) {
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

        $startToday = Carbon::today()->timestamp;

        /**
         * prepare the query using a "random" PDO connection
         */
        $query = new WifiDailyStatsZoneUniqueDeviceUuids;
        $db = $query->getConnection()->getPdo();

        if ($start > 0 && $end > 0) {
            /**
             * If they want to look at todays results only
             */
            if ($start >= Carbon::today()->timestamp) {
                $initialresultsVisitors = $db->prepare(
                    "SELECT temp1.day_epoch AS day_epoch,
                            temp1.hour AS hour,
                            COUNT(temp1.device_uuid) AS visitors,
                            zone.id AS zone_id,
                            zone.name AS zone_name,
                            zone.lat AS lat,
                            zone.lon AS lon
                     FROM
                     (
                        SELECT UNIX_TIMESTAMP(DATE_FORMAT(FROM_UNIXTIME(ts),'%Y-%m-%d 00:00:00')) AS day_epoch, 
                               HOUR(FROM_UNIXTIME(ts)) AS hour,
                               access_point_id, 
                               device_uuid
                        FROM wifi_client_connection
                        WHERE ts >= :start
                        AND ts < :end_1
                        AND venue_id = :venue_id
                        GROUP BY device_uuid, day_epoch
                        ORDER BY device_uuid
                     ) AS temp1

                     LEFT JOIN access_point ON temp1.access_point_id = access_point.id
                     LEFT JOIN zone ON access_point.zone_id = zone.id
                     GROUP BY day_epoch, hour, zone_id
                     ORDER BY day_epoch ASC, hour ASC"
                );
            } else {
                /**
                 * user is allowed to view full venue stats
                 * prepare and execute the query
                 */
                $initialresultsVisitors = $db->prepare(
                    "SELECT temp1.day_epoch AS day_epoch, 
                            temp1.hour AS hour, 
                            COUNT(temp1.device_uuid) AS visitors, 
                            temp1.zone_id AS zone_id, 
                            zone.name AS zone_name, 
                            zone.lat AS lat, 
                            zone.lon AS lon
                     FROM
                     (
                         SELECT day_epoch, hour, zone_id, device_uuid
                         FROM wifi_daily_stats_zone_unique_device_uuids_per_hour
                         WHERE (day_epoch + (3600*hour)) >= :start
                         AND (day_epoch + (3600*hour)) < :end_1
                         AND venue_id = :venue_id
                         GROUP BY device_uuid, day_epoch
                         ORDER BY device_uuid
                     ) AS temp1
                     LEFT OUTER JOIN zone ON zone_id = zone.id
                     GROUP BY day_epoch, hour, zone_id
                     ORDER BY day_epoch ASC, hour ASC"
                );

                /**
                 * If they want to look at todays results as well as previous results run this code
                 */
                if ($end > Carbon::today()->timestamp) {
                    $todaysresultsVisitors = $db->prepare(
                        "SELECT temp1.day_epoch AS day_epoch,
                                temp1.hour AS hour,
                                COUNT(temp1.device_uuid) AS visitors,
                                zone.id AS zone_id,
                                zone.name AS zone_name,
                                zone.lat AS lat,
                                zone.lon AS lon
                         FROM
                         (
                            SELECT UNIX_TIMESTAMP(DATE_FORMAT(FROM_UNIXTIME(ts),'%Y-%m-%d 00:00:00')) AS day_epoch, 
                                   HOUR(FROM_UNIXTIME(ts)) AS hour,
                                   access_point_id, 
                                   device_uuid
                            FROM wifi_client_connection
                            WHERE ts >= :start
                            AND ts < :end_1
                            AND venue_id = :venue_id
                            GROUP BY device_uuid, day_epoch
                            ORDER BY device_uuid
                         ) AS temp1

                         LEFT JOIN access_point ON temp1.access_point_id = access_point.id
                         LEFT JOIN zone ON access_point.zone_id = zone.id
                         GROUP BY day_epoch, hour, zone_id
                         ORDER BY day_epoch ASC, hour ASC"
                    );

                    $todaysresultsVisitors->bindParam(':start', $startToday);
                    $todaysresultsVisitors->bindParam(':end_1', $end);
                    $todaysresultsVisitors->bindParam(':venue_id', $venue_filter);

                    $todaysresultsVisitors->execute();
                }
            }
        }

        /**
         * bind the parameters to the selected query
         */
        $initialresultsVisitors->bindParam(':start', $start);
        $initialresultsVisitors->bindParam(':end_1', $end);
        $initialresultsVisitors->bindParam(':venue_id', $venue_filter);

        /**
         * execute the query for total visitors
         */
        $initialresultsVisitors->execute();

        /**
         * Create an array with the heatmap data in
         */
        $zone_key = 0;
        $total_visitors = 0;
        $tempresults = [];

        if (!empty($initialresultsVisitors) > 0) {
            foreach ($initialresultsVisitors as $item) {
                $zone_key       += 1;
                $total_visitors += $item['visitors'];

                $tempresults[]= [
                    'zone_key'  => $zone_key,
                    'zone_id'   => $item['zone_id'],
                    'zone_name' => $item['zone_name'],
                    'lat'       => $item['lat'],
                    'lon'       => $item['lon'],
                    'timestamp' => ($item['day_epoch'] + ($item['hour'])*3600)*1000,
                    'count'     => $item['visitors']
                ];
            }
        }
        if (!empty($todaysresultsVisitors) > 0) {
            foreach ($todaysresultsVisitors as $item) {
                $zone_key       += 1;
                $total_visitors += $item['visitors'];

                $tempresults[]= [
                    'zone_key'  => $zone_key,
                    'zone_id'   => $item['zone_id'],
                    'zone_name' => $item['zone_name'],
                    'lat'       => $item['lat'],
                    'lon'       => $item['lon'],
                    'timestamp' => ($item['day_epoch'] + ($item['hour'])*3600)*1000,
                    'count'     => $item['visitors']
                ];
            }
        }

        /**
         * format the heatmap output in the correct required format with the average visitor count per data point
         * (average visitor count = total visitors divided by the number of data points)
         */
        $heatmap_results = [
            'min'  => 0,
            'max'  => round($total_visitors/($zone_key > 0 ? $zone_key : 1 )),
            'data' => $tempresults
        ];

        /***************************************************************
         * GET THE HOURLY USERS FOR THIS VENUE FOR THE TOP CHART
         ***************************************************************/

        if ($start > 0 && $end > 0) {
            /**
             * If they want to look at todays results only
             */
            if ($start >= Carbon::today()->timestamp) {
                $initialresultsVisitorsChart = $db->prepare(
                    "SELECT temp1.day_epoch AS day_epoch,
                            temp1.hour AS hour,
                            COUNT(temp1.device_uuid) AS visitors
                     FROM
                     (
                        SELECT UNIX_TIMESTAMP(DATE_FORMAT(FROM_UNIXTIME(ts),'%Y-%m-%d 00:00:00')) AS day_epoch, 
                               HOUR(FROM_UNIXTIME(ts)) AS hour,
                               device_uuid
                        FROM wifi_client_connection
                        WHERE ts >= :start
                        AND ts < :end_1
                        AND venue_id = :venue_id
                        GROUP BY device_uuid, day_epoch
                        ORDER BY device_uuid
                     ) AS temp1
                     GROUP BY day_epoch, hour
                     ORDER BY day_epoch ASC, hour ASC"
                );
            } else {
                /**
                 * user is allowed to view full venue stats
                 * prepare and execute the query
                 */
                $initialresultsVisitorsChart = $db->prepare(
                    "SELECT temp1.day_epoch AS day_epoch, 
                            temp1.hour AS hour, 
                            COUNT(temp1.device_uuid) AS visitors
                     FROM
                     (
                         SELECT day_epoch, hour, zone_id, device_uuid
                         FROM wifi_daily_stats_zone_unique_device_uuids_per_hour
                         WHERE (day_epoch + (3600*hour)) >= :start
                         AND (day_epoch + (3600*hour)) < :end_1
                         AND venue_id = :venue_id
                         GROUP BY device_uuid, day_epoch
                         ORDER BY device_uuid
                     ) AS temp1
                     GROUP BY day_epoch, hour
                     ORDER BY day_epoch ASC, hour ASC"
                );

                /**
                 * If they want to look at todays results as well as previous results run this code
                 */
                if ($end > Carbon::today()->timestamp) {
                    $todaysresultsVisitorsChart = $db->prepare(
                        "SELECT temp1.day_epoch AS day_epoch,
                                temp1.hour AS hour,
                                COUNT(temp1.device_uuid) AS visitors
                         FROM
                         (
                            SELECT UNIX_TIMESTAMP(DATE_FORMAT(FROM_UNIXTIME(ts),'%Y-%m-%d 00:00:00')) AS day_epoch, 
                                   HOUR(FROM_UNIXTIME(ts)) AS hour,
                                   device_uuid
                            FROM wifi_client_connection
                            WHERE ts >= :start
                            AND ts < :end_1
                            AND venue_id = :venue_id
                            GROUP BY device_uuid, day_epoch
                            ORDER BY device_uuid
                         ) AS temp1
                         GROUP BY day_epoch, hour
                         ORDER BY day_epoch ASC, hour ASC"
                    );

                    $todaysresultsVisitorsChart->bindParam(':start', $startToday);
                    $todaysresultsVisitorsChart->bindParam(':end_1', $end);
                    $todaysresultsVisitorsChart->bindParam(':venue_id', $venue_filter);

                    $todaysresultsVisitorsChart->execute();
                }
            }
        }

        /**
         * bind the parameters to the selected query
         */
        $initialresultsVisitorsChart->bindParam(':start', $start);
        $initialresultsVisitorsChart->bindParam(':end_1', $end);
        $initialresultsVisitorsChart->bindParam(':venue_id', $venue_filter);

        /**
         * Create an array with the chart data in
         */
        $initialresultsVisitorsChart->execute();

        /**
         * merge the arrays from head/body/tail results for the chart into a single array ($temp_chart_results)
         */
        $temp_chart_results = [];

        if (!empty($initialresultsVisitors) > 0) {
            foreach ($initialresultsVisitorsChart as $item) {
                $temp_chart_results[]= [($item['day_epoch'] + ($item['hour'])*3600)*1000, $item['visitors']];
            }
        }

        if (!empty($todaysresultsVisitorsChart) > 0) {
            foreach ($todaysresultsVisitorsChart as $item) {
                $temp_chart_results[]= [($item['day_epoch'] + ($item['hour'])*3600)*1000, $item['visitors']];
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
            $activeZones = $getZones->where('venue_id', $venue_filter)->where('wifi_zone', 1)
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
            $activeZones = $getZones->where('venue_id', $venue_filter)->where('wifi_zone', 1)
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

    public function listAccessPoints(Request $request, Response $response, $args) 
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

        $apsQuery = new AccessPoint;
        $total = $apsQuery->count();

        /**
         * Get sessions filtered by the primary_venue_id of logged in user
         */
        $ap_collection = $apsQuery->with('ap_configs', 'zone.venue')->whereHas('zone.venue', function ($query) use($venue_filter) {
            $query->where('venue_id', $venue_filter);
        })->orWhere('zone_id', 0)->get();

        $total_filtered  = count($ap_collection);

        $results = [
            "count" => $total,
            "rows" => $ap_collection->values()->toArray(),
            "count_filtered" => $total_filtered
        ];

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    private function connectController()
    {
        /**
         * Get the login credentials for primary_venue_id of logged in user
         */
        $venueQuery = new VenueWifi;
        $venue_details = $venueQuery->with('controller')->where('venue_id', $this->ci->currentUser->primary_venue_id)->first();

        /**
         * we use credentials from the controller object
         */
        $controlleruser = $venue_details->controller->user_name;
        $controllerpassword = $venue_details->controller->password;
        $controllerurl = $venue_details->controller->url;
        $controllerversion  = $venue_details->controller->version;
        $venueid = $venue_details->controller_venue_id;
        $unifidata = new UnifiController($controlleruser, $controllerpassword, $controllerurl, $venueid, $controllerversion, $this->ci);

        if (
            !isset($_SESSION['controller_cookies']) ||
            !isset($_SESSION['controller_cookies_expires']) ||
            !isset($_SESSION['controller_cookies_url']) ||
            $_SESSION['controller_cookies_expires'] < time() ||
            $_SESSION['controller_cookies_url'] != $controllerurl ||
            $_SESSION['controller_cookies_username'] != $controlleruser
        ) {
            $loginresults = $unifidata->login();

            /**
             * cookies will have been set in the $_SESSION variable, now we need to add a timeout timestamp and the $controllerurl and $controlleruser
             * so that we can re-login after either:
             * - session times out
             * - $controllerurl or $controlleruser have been switched
             */
            $_SESSION['controller_cookies_expires'] = time() + 1800;
            $_SESSION['controller_cookies_url'] = $controllerurl;
            $_SESSION['controller_cookies_username'] = $controlleruser;

            /**
             * if we have an error we need to stop, else carry on
             */
            if ($loginresults !== true) {
                $unifidata->is_loggedin = false;
                return false;
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

        } 
        else {
            $unifidata->is_loggedin = true;
        }

        return $unifidata;
    }
}