<?php

namespace UserFrosting\Sprinkle\EnviroSense\Controller;

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

use UserFrosting\Sprinkle\EnviroSense\Database\Models\EnviroSensor;
use UserFrosting\Sprinkle\EnviroSense\Database\Models\EnviroSensorHourlyAqiData;
use UserFrosting\Sprinkle\EnviroSense\Database\Models\EnviroSensorData;
use UserFrosting\Sprinkle\EnviroSense\Database\Models\EnviroSensorDailyDaqiData;

/**
 * ApiController Class
 *
 * @package EnviroSense
 * @author Luke Rayner/ElephantWiFi
 * @link http://www.elephantwifi.co.uk
 */
class ApiController extends SimpleController 
{
    public function listEnviromentalOverviewStats(Request $request, Response $response, $args) 
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_enviro_user')) {
            throw new NotFoundException($request, $response);
        }

        $results = [];

        /**
         * filter on the user's primary_venue_id
         */
        $venue_filter = $currentUser->primary_venue_id;

        // Get the enviro_sensors for this venue
        $enviro_sensors = EnviroSensor::where('venue_id', $venue_filter)->get();

        $enviro_sensor_ids = [];
        foreach($enviro_sensors as $enviro_sensor) {
            array_push($enviro_sensor_ids, $enviro_sensor->id);
        }

        /**
         * create a string containing enviro_sensor ids, comma seperated, to insert into the PDO statement
         */
        $ids_string = implode(',', $enviro_sensor_ids);

        // Get the timestamp
        $now = Carbon::now()->format('U');
        $ts = floor($now / 3600) * 3600;

        /**
         * prepare the query using a "random" PDO connection
         */
        $query = new EnviroSensorHourlyAqiData;
        $db    = $query->getConnection()->getPdo();

        $fetch_enviro_sensor_hourly_aqi_data = $db->prepare("
            SELECT avg(particle_matter_2_5_aqi) as particle_matter_2_5_aqi,
                avg(particle_matter_2_5_value) as particle_matter_2_5_value,
                avg(particle_matter_10_aqi) as particle_matter_10_aqi,
                avg(particle_matter_10_value) as particle_matter_10_value,
                avg(ozone_aqi) as ozone_aqi,
                avg(ozone_value) as ozone_value,
                avg(nitrogen_dioxide_aqi) as nitrogen_dioxide_aqi,
                avg(nitrogen_dioxide_value) as nitrogen_dioxide_value,
                avg(sulfur_dioxide_aqi) as sulfur_dioxide_aqi,
                avg(sulfur_dioxide_value) as sulfur_dioxide_value
            FROM enviro_sensor_hourly_aqi_data t
            JOIN ( 
                SELECT MAX(mx.ts) AS max_ts
                FROM enviro_sensor_hourly_aqi_data mx
            ) m
            ON m.max_ts = t.ts
            AND  enviro_sensor_id IN (" . $ids_string . ")
        ");
        $fetch_enviro_sensor_hourly_aqi_data->execute();
        $fetch_enviro_sensor_hourly_aqi_data_array = $fetch_enviro_sensor_hourly_aqi_data->fetchAll();

        foreach ($fetch_enviro_sensor_hourly_aqi_data_array as $data) {
            $particle_matter_2_5_aqi = round($data['particle_matter_2_5_aqi']);
            $results['particle_matter_2_5']['aqi_value']   = $particle_matter_2_5_aqi;
            $results['particle_matter_2_5']['value']       = (float)$data['particle_matter_2_5_value'];
            $results['particle_matter_2_5']['rating_text'] = $this->colorPicker($particle_matter_2_5_aqi)['rating_text'];
            $results['particle_matter_2_5']['color']       = $this->colorPicker($particle_matter_2_5_aqi)['color'];

            $particle_matter_10_aqi = round($data['particle_matter_10_aqi']);
            $results['particle_matter_10']['aqi_value']    = $particle_matter_10_aqi;
            $results['particle_matter_10']['value']        = (float)$data['particle_matter_10_value'];
            $results['particle_matter_10']['rating_text']  = $this->colorPicker($particle_matter_10_aqi)['rating_text'];
            $results['particle_matter_10']['color']        = $this->colorPicker($particle_matter_10_aqi)['color'];

            $ozone_aqi = round($data['ozone_aqi']);
            $results['ozone']['aqi_value']                 = $ozone_aqi;
            $results['ozone']['value']                     = (float)$data['ozone_value'];
            $results['ozone']['rating_text']               = $this->colorPicker($ozone_aqi)['rating_text'];
            $results['ozone']['color']                     = $this->colorPicker($ozone_aqi)['color'];

            $nitrogen_dioxide_aqi = round($data['nitrogen_dioxide_aqi']);
            $results['nitrogen_dioxide']['aqi_value']      = $nitrogen_dioxide_aqi;
            $results['nitrogen_dioxide']['value']          = (float)$data['nitrogen_dioxide_value'];
            $results['nitrogen_dioxide']['rating_text']    = $this->colorPicker($nitrogen_dioxide_aqi)['rating_text'];
            $results['nitrogen_dioxide']['color']          = $this->colorPicker($nitrogen_dioxide_aqi)['color'];

            $sulfur_dioxide_aqi = round($data['sulfur_dioxide_aqi']);
            $results['sulfur_dioxide']['aqi_value']        = $sulfur_dioxide_aqi;
            $results['sulfur_dioxide']['value']            = (float)$data['sulfur_dioxide_value'];
            $results['sulfur_dioxide']['rating_text']      = $this->colorPicker($sulfur_dioxide_aqi)['rating_text'];
            $results['sulfur_dioxide']['color']            = $this->colorPicker($sulfur_dioxide_aqi)['color'];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listEnviromentalOverviewDonutStats(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_enviro_user')) {
            throw new NotFoundException($request, $response);
        }

        $results = [];

        /**
         * filter on the user's primary_venue_id
         */
        $venue_filter = $currentUser->primary_venue_id;

        // Get the enviro_sensors for this venue
        $enviro_sensors = EnviroSensor::where('venue_id', $venue_filter)->get();

        $enviro_sensor_ids = [];
        foreach($enviro_sensors as $enviro_sensor) {
            array_push($enviro_sensor_ids, $enviro_sensor->id);
        }

        /**
         * create a string containing enviro_sensor ids, comma seperated, to insert into the PDO statement
         */
        $ids_string = implode(',', $enviro_sensor_ids);

        // Get the timestamp
        $now = Carbon::now()->format('U');
        $ts = floor($now / 3600) * 3600;

        /**
         * prepare the query using a "random" PDO connection
         */
        $query = new EnviroSensorData;
        $db    = $query->getConnection()->getPdo();

        $fetch_enviro_sensor_latest_data = $db->prepare("
            SELECT avg(temperature) as temperature,
                avg(pressure) as pressure,
                avg(humidity) as humidity
            FROM enviro_sensor_data t
            JOIN ( 
                SELECT MAX(mx.ts) AS max_ts
                FROM enviro_sensor_data mx
            ) m
            ON m.max_ts = t.ts
            WHERE enviro_sensor_id IN (" . $ids_string . ")
        ");
        $fetch_enviro_sensor_latest_data->execute();
        $fetch_enviro_sensor_latest_data_array = $fetch_enviro_sensor_latest_data->fetchAll();

        foreach ($fetch_enviro_sensor_latest_data_array as $latest_data) {
            $results['temperature'] = (float)$latest_data['temperature'];
            $results['pressure'] = (float)$latest_data['pressure'];
            $results['humidity'] = (float)$latest_data['humidity'];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listEnviromentalZoneOverviewDAQI(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_enviro_user')) {
            throw new NotFoundException($request, $response);
        }

        $results = [];

        /**
         * Get the timestamps needed in the below query
         */
        $now = Carbon::now()->format('U');
        $startToday = Carbon::now()->startOfDay()->format('U');

        /**
         * prepare the query using a "random" PDO connection
         */
        $query = new EnviroSensorHourlyAqiData;
        $db = $query->getConnection()->getPdo();

        $fetch_enviro_sensor_hourly_aqi_data = $db->prepare("
            SELECT avg(particle_matter_2_5_aqi) as particle_matter_2_5_aqi,
                avg(particle_matter_2_5_value) as particle_matter_2_5_value,
                avg(particle_matter_10_aqi) as particle_matter_10_aqi,
                avg(particle_matter_10_value) as particle_matter_10_value,
                avg(ozone_aqi) as ozone_aqi,
                avg(ozone_value) as ozone_value,
                avg(nitrogen_dioxide_aqi) as nitrogen_dioxide_aqi,
                avg(nitrogen_dioxide_value) as nitrogen_dioxide_value,
                avg(sulfur_dioxide_aqi) as sulfur_dioxide_aqi,
                avg(sulfur_dioxide_value) as sulfur_dioxide_value
            FROM enviro_sensor_hourly_aqi_data
            WHERE ts >= :start
            AND ts < :end
            AND enviro_sensor_id = :enviro_sensor_id
        ");
        $fetch_enviro_sensor_hourly_aqi_data->bindParam(':start', $startToday);
        $fetch_enviro_sensor_hourly_aqi_data->bindParam(':end', $now);
        $fetch_enviro_sensor_hourly_aqi_data->bindParam(':enviro_sensor_id', $args['enviro_sensor_id']);
        $fetch_enviro_sensor_hourly_aqi_data->execute();
        $fetch_enviro_sensor_hourly_aqi_data_array = $fetch_enviro_sensor_hourly_aqi_data->fetchAll();

        $max_daqi_array = [
            'value' => 0
        ];
        $max_daqi = 0;
        foreach ($fetch_enviro_sensor_hourly_aqi_data_array as $enviro_sensor_data) {
            // Check if max_daqi should be increased
            $max_daqi_array['value'] = ($enviro_sensor_data['particle_matter_2_5_aqi'] > $max_daqi_array['value']) ? $enviro_sensor_data['particle_matter_2_5_aqi'] : $max_daqi_array['value'];

            $max_daqi_array['value'] = ($enviro_sensor_data['particle_matter_10_aqi'] > $max_daqi_array['value']) ? $enviro_sensor_data['particle_matter_10_aqi'] : $max_daqi_array['value'];

            $max_daqi_array['value'] = ($enviro_sensor_data['ozone_aqi'] > $max_daqi_array['value']) ? $enviro_sensor_data['ozone_aqi'] : $max_daqi_array['value'];

            $max_daqi_array['value'] = ($enviro_sensor_data['nitrogen_dioxide_aqi'] > $max_daqi_array['value']) ? $enviro_sensor_data['nitrogen_dioxide_aqi'] : $max_daqi_array['value'];

            $max_daqi_array['value'] = ($enviro_sensor_data['sulfur_dioxide_aqi'] > $max_daqi_array['value']) ? $enviro_sensor_data['sulfur_dioxide_aqi'] : $max_daqi_array['value'];

            if ($max_daqi_array['value'] > $max_daqi) {
                $max_daqi = $max_daqi_array['value'];
            }
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson(round($max_daqi_array['value']), 200, JSON_PRETTY_PRINT);
    }

    public function listEnviromentalZoneOverviewStats(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_enviro_user')) {
            throw new NotFoundException($request, $response);
        }

        $results = [];

        // Get the timestamp
        $now = Carbon::now()->format('U');
        $ts = floor($now / 3600) * 3600;

        /**
         * prepare the query using a "random" PDO connection
         */
        $query = new EnviroSensorHourlyAqiData;
        $db = $query->getConnection()->getPdo();

        $fetch_enviro_sensor_hourly_aqi_data = $db->prepare("
            SELECT particle_matter_2_5_aqi,
                particle_matter_2_5_value,
                particle_matter_10_aqi,
                particle_matter_10_value,
                ozone_aqi,
                ozone_value,
                nitrogen_dioxide_aqi,
                nitrogen_dioxide_value,
                sulfur_dioxide_aqi,
                sulfur_dioxide_value
            FROM enviro_sensor_hourly_aqi_data t
            JOIN ( 
                SELECT MAX(mx.ts) AS max_ts
                FROM enviro_sensor_hourly_aqi_data mx
            ) m
            ON m.max_ts = t.ts
            AND enviro_sensor_id = :enviro_sensor_id
        ");

        $fetch_enviro_sensor_hourly_aqi_data->bindParam(':enviro_sensor_id', $args['enviro_sensor_id']);
        $fetch_enviro_sensor_hourly_aqi_data->execute();
        $fetch_enviro_sensor_hourly_aqi_data_array = $fetch_enviro_sensor_hourly_aqi_data->fetchAll();

        foreach ($fetch_enviro_sensor_hourly_aqi_data_array as $data) {
            $particle_matter_2_5_aqi = round($data['particle_matter_2_5_aqi']);
            $results['particle_matter_2_5']['aqi_value'] = $particle_matter_2_5_aqi;
            $results['particle_matter_2_5']['value'] = (float)$data['particle_matter_2_5_value'];
            $results['particle_matter_2_5']['rating_text'] = $this->colorPicker($particle_matter_2_5_aqi)['rating_text'];
            $results['particle_matter_2_5']['color'] = $this->colorPicker($particle_matter_2_5_aqi)['color'];

            $particle_matter_10_aqi = round($data['particle_matter_10_aqi']);
            $results['particle_matter_10']['aqi_value'] = $particle_matter_10_aqi;
            $results['particle_matter_10']['value'] = (float)$data['particle_matter_10_value'];
            $results['particle_matter_10']['rating_text']  = $this->colorPicker($particle_matter_10_aqi)['rating_text'];
            $results['particle_matter_10']['color'] = $this->colorPicker($particle_matter_10_aqi)['color'];

            $ozone_aqi = round($data['ozone_aqi']);
            $results['ozone']['aqi_value'] = $ozone_aqi;
            $results['ozone']['value'] = (float)$data['ozone_value'];
            $results['ozone']['rating_text'] = $this->colorPicker($ozone_aqi)['rating_text'];
            $results['ozone']['color'] = $this->colorPicker($ozone_aqi)['color'];

            $nitrogen_dioxide_aqi = round($data['nitrogen_dioxide_aqi']);
            $results['nitrogen_dioxide']['aqi_value'] = $nitrogen_dioxide_aqi;
            $results['nitrogen_dioxide']['value'] = (float)$data['nitrogen_dioxide_value'];
            $results['nitrogen_dioxide']['rating_text'] = $this->colorPicker($nitrogen_dioxide_aqi)['rating_text'];
            $results['nitrogen_dioxide']['color'] = $this->colorPicker($nitrogen_dioxide_aqi)['color'];

            $sulfur_dioxide_aqi = round($data['sulfur_dioxide_aqi']);
            $results['sulfur_dioxide']['aqi_value'] = $sulfur_dioxide_aqi;
            $results['sulfur_dioxide']['value'] = (float)$data['sulfur_dioxide_value'];
            $results['sulfur_dioxide']['rating_text'] = $this->colorPicker($sulfur_dioxide_aqi)['rating_text'];
            $results['sulfur_dioxide']['color'] = $this->colorPicker($sulfur_dioxide_aqi)['color'];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listEnviromentalZoneOverviewDonutStats(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_enviro_user')) {
            throw new NotFoundException($request, $response);
        }

        $results = [];

        /**
         * prepare the query using a "random" PDO connection
         */
        $query = new EnviroSensorData;
        $db = $query->getConnection()->getPdo();

        $fetch_enviro_sensor_latest_data = $db->prepare("
            SELECT temperature,
                pressure,
                humidity
            FROM enviro_sensor_data
            WHERE enviro_sensor_id = :enviro_sensor_id
            ORDER BY ts DESC
            LIMIT 1
        ");
        $fetch_enviro_sensor_latest_data->bindParam(':enviro_sensor_id', $args['enviro_sensor_id']);
        $fetch_enviro_sensor_latest_data->execute();
        $fetch_enviro_sensor_latest_data_array = $fetch_enviro_sensor_latest_data->fetchAll();

        foreach ($fetch_enviro_sensor_latest_data_array as $latest_data) {
            $results['temperature'] = (float)$latest_data['temperature'];
            $results['pressure'] = (float)$latest_data['pressure'];
            $results['humidity'] = (float)$latest_data['humidity'];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listEnviromentalStats(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_enviro_user')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * filter on the user's primary_venue_id
         */
        $venue_filter = $currentUser->primary_venue_id;

        //get the enviro_sensors for this venue
        $enviro_sensors = EnviroSensor::where('venue_id', $venue_filter)->get();

        $enviro_sensor_ids = [];
        foreach($enviro_sensors as $enviro_sensor) {
            array_push($enviro_sensor_ids, $enviro_sensor->id);
        }

        /**
         * create a string containing enviro_sensor ids, comma seperated, to insert into the PDO statement
         */
        $ids_string = implode(',', $enviro_sensor_ids);

        $results = [];

        /**
         * Get the timestamps needed in the below query
         */
        $now = Carbon::now()->format('U');
        $start = floor($args['start']/1000);
        $end = floor($args['end']/1000);

        /**
         * prepare the query using a "random" PDO connection
         */
        $sensorDataQuery = new EnviroSensorData;
        $db = $sensorDataQuery->getConnection()->getPdo();

        $initialresults = $db->prepare("
            SELECT ts,
                temperature,
                pressure,
                humidity,
                voc_raw,
                noise,
                particle_matter_2_5,
                particle_matter_10,
                ozone,
                nitrogen_dioxide,
                sulfur_dioxide,
                carbon_monoxide,
                enviro_sensor.name AS enviro_sensor_name
            FROM enviro_sensor_data
            LEFT JOIN enviro_sensor ON enviro_sensor_data.enviro_sensor_id = enviro_sensor.id
            WHERE enviro_sensor_data.enviro_sensor_id IN (" . $ids_string . ")
            AND ts BETWEEN :start_1 AND :end_1
        ");

        $initialresults->bindParam(':start_1', $start);
        $initialresults->bindParam(':end_1', $end);
        $initialresults->execute();

        /**
         * process the results
         */
        foreach ($initialresults as $data) {
            $results[$data['enviro_sensor_name']]['temperature'][] = ['ts' => round($data['ts']), 'name' => 'temperature', 'value' => (float)$data['temperature']];

            $results[$data['enviro_sensor_name']]['pressure'][] = ['ts' => round($data['ts']), 'name' => 'pressure', 'value' => (float)$data['pressure']];

            $results[$data['enviro_sensor_name']]['humidity'][] = ['ts' => round($data['ts']), 'name' => 'humidity', 'value' => (float)$data['humidity']];

            $results[$data['enviro_sensor_name']]['voc_raw'][] = ['ts' => round($data['ts']), 'name' => 'voc_raw', 'value' => (float)$data['voc_raw']];

            $results[$data['enviro_sensor_name']]['noise'][] = ['ts' => round($data['ts']), 'name' => 'noise', 'value' => (float)$data['noise']];

            $results[$data['enviro_sensor_name']]['particle_matter_2_5'][] = ['ts' => round($data['ts']), 'name' => 'particle_matter_2_5', 'value' => (float)$data['particle_matter_2_5']];

            $results[$data['enviro_sensor_name']]['particle_matter_10'][] = ['ts' => round($data['ts']), 'name' => 'particle_matter_10', 'value' => (float)$data['particle_matter_10']];

            $results[$data['enviro_sensor_name']]['ozone'][] = ['ts' => round($data['ts']), 'name' => 'ozone', 'value' => (float)$data['ozone']];

            $results[$data['enviro_sensor_name']]['nitrogen_dioxide'][] = ['ts' => round($data['ts']), 'name' => 'nitrogen_dioxide', 'value' => (float)$data['nitrogen_dioxide']];

            $results[$data['enviro_sensor_name']]['sulfur_dioxide'][] = ['ts' => round($data['ts']), 'name' => 'sulfur_dioxide', 'value' => (float)$data['sulfur_dioxide']];

            $results[$data['enviro_sensor_name']]['carbon_monoxide'][] = ['ts' => round($data['ts']), 'name' => 'carbon_monoxide', 'value' => (float)$data['carbon_monoxide']];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listEnviroSensors(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_enviro_user')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * filter on the user's primary_venue_id
         */
        $venue_filter = $currentUser->primary_venue_id;

        $enviroSensorQuery = new EnviroSensor;
        $total = $enviroSensorQuery->count();

        /**
         * Get sessions filtered by the primary_venue_id of logged in user
         */
        $enviro_sensor_collection = $enviroSensorQuery->with('venue', 'last_activity', 'enviro_sensor_modules.enviro_sensor_module_type')
            ->where('venue_id', $venue_filter)->orWhere('venue_id', 0)->get();

        $total_filtered = count($enviro_sensor_collection);

        $results = [
            "count" => $total,
            "rows" => $enviro_sensor_collection->values()->toArray(),
            "count_filtered" => $total_filtered
        ];

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listEnviromentalDAQIStats(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_enviro_user')) {
            throw new NotFoundException($request, $response);
        }

        $enviro_sensor_ids = array_map('intval', explode(',', $args['enviro_sensor_ids']));
        
        $results = [];

        /**
         * Get the timestamps needed in the below query
         */
        $now = Carbon::now()->format('U');
        $start = floor($args['start']/1000);
        $end = floor($args['end']/1000);

        $sensorDailyDaqiDataQuery = new EnviroSensorDailyDaqiData;
        $initialresults = $sensorDailyDaqiDataQuery->selectRaw('day_epoch, max(daqi_value) as daqi_value')
            ->whereIn('enviro_sensor_id', $enviro_sensor_ids)
            ->whereBetween('day_epoch', [$start, $end])
            ->groupBy('day_epoch')
            ->get();

        foreach ($initialresults as $initialresult) {
            $results[] = ['day_epoch' => $initialresult['day_epoch'], 'daqi_value' => $initialresult['daqi_value']];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listEnviromentalAqiStats(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_enviro_user')) {
            throw new NotFoundException($request, $response);
        }

        $results = [];

        /**
         * filter on the user's primary_venue_id
         */
        $venue_filter = $currentUser->primary_venue_id;

        /**
         * Get the timestamps needed in the below query
         */
        $now = Carbon::now()->format('U');
        $start = floor($args['start']/1000);
        $end = floor($args['end']/1000);

        /**
         * prepare the query using a "random" PDO connection
         */
        $sensorDataQuery = new EnviroSensorHourlyAqiData;
        $db = $sensorDataQuery->getConnection()->getPdo();

        $initialresults = $db->prepare("
            SELECT ts,
                avg(particle_matter_2_5_value) as particle_matter_2_5_value,
                avg(particle_matter_2_5_aqi) as particle_matter_2_5_aqi,
                avg(particle_matter_10_value) as particle_matter_10_value,
                avg(particle_matter_10_aqi) as particle_matter_10_aqi,
                avg(ozone_value) as ozone_value,
                avg(ozone_aqi) as ozone_aqi,
                avg(nitrogen_dioxide_value) as nitrogen_dioxide_value,
                avg(nitrogen_dioxide_aqi) as nitrogen_dioxide_aqi,
                avg(sulfur_dioxide_value) as sulfur_dioxide_value,
                avg(sulfur_dioxide_aqi) as sulfur_dioxide_aqi
            FROM enviro_sensor_hourly_aqi_data
            WHERE enviro_sensor_id IN (" . $args['enviro_sensor_ids'] . ")
            AND ts BETWEEN :start_1 AND :end_1
            GROUP BY ts
        ");

        $initialresults->bindParam(':start_1', $start);
        $initialresults->bindParam(':end_1', $end);
        $initialresults->execute();

        /**
         * process the results
         */
        foreach ($initialresults as $data) {
            $results['particle_matter_2_5'][] = [
                'ts' => $data['ts'], 
                'name' => 'particle_matter_2_5', 
                'value' => (float)$data['particle_matter_2_5_value'], 
                'rating' => (int)$data['particle_matter_2_5_aqi']
            ];

            $results['particle_matter_10'][] = [
                'ts' => $data['ts'], 
                'name' => 'particle_matter_10', 
                'value' => (float)$data['particle_matter_10_value'], 
                'rating' => (int)$data['particle_matter_10_aqi']
            ];

            $results['ozone'][] = [
                'ts' => $data['ts'], 
                'name' => 'ozone', 
                'value' => (float)$data['ozone_value'], 
                'rating' => (int)$data['ozone_aqi']
            ];

            $results['nitrogen_dioxide'][] = [
                'ts' => $data['ts'], 
                'name' => 'nitrogen_dioxide', 
                'value' => (float)$data['nitrogen_dioxide_value'], 
                'rating' => (int)$data['nitrogen_dioxide_aqi']
            ];

            $results['sulfur_dioxide'][] = [
                'ts' => $data['ts'], 
                'name' => 'sulfur_dioxide', 
                'value' => (float)$data['sulfur_dioxide_value'], 
                'rating' => (int)$data['sulfur_dioxide_aqi']
            ];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }



    private function colorPicker($value) {
        $color = '';
        $rating_text = '';

        switch ($value) {
            case 1:
                $color = '#9CFF9C';
                $rating_text = 'Low';
                break;
            case 2:
                $color = '#31FF00';
                $rating_text = 'Low';
                break;
            case 3:
                $color = '#31CF00';
                $rating_text = 'Low';
                break;
            case 4:
                $color = '#FFFF00';
                $rating_text = 'Moderate';
                break;
            case 5:
                $color = '#FFCF00';
                $rating_text = 'Moderate';
                break;
            case 6:
                $color = '#FF9A00';
                $rating_text = 'Moderate';
                break;
            case 7:
                $color = '#FF6464';
                $rating_text = 'High';
                break;
            case 8:
                $color = '#FF0000';
                $rating_text = 'High';
                break;
            case 9:
                $color = '#990000';
                $rating_text = 'High';
                break;
            case 10:
                $color = '#CE30FF';
                $rating_text = 'Very High';
                break;
            default:
                $color = '';
                $rating_text = '';
        }

        return ['color' => $color, 'rating_text' => $rating_text];
    } 
}