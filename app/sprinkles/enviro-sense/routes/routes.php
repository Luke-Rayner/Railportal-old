<?php
/**
 * @package EnviroSense
 * @author Luke Rayner/ElephantWiFi
 */
use UserFrosting\Sprinkle\Core\Util\NoCache;

// All the account routes
$app->group('/enviro-sense', function () {
	$this->get('/venue-overview', 'UserFrosting\Sprinkle\EnviroSense\Controller\VenueOverviewController:pageVenueOverview');

	$this->get('/zone-overview', 'UserFrosting\Sprinkle\EnviroSense\Controller\ZoneOverviewController:pageZoneOverview');

	$this->get('/detailed-report', 'UserFrosting\Sprinkle\EnviroSense\Controller\DetailedReportController:pageDetailedReport');

	$this->get('/aqi-report', 'UserFrosting\Sprinkle\EnviroSense\Controller\AqiReportController:pageAqiReport');
});

// All the geo-sense api routes
$app->group('/enviro-sense/api', function () {
	$this->get('/overview/stats', 'UserFrosting\Sprinkle\EnviroSense\Controller\ApiController:listEnviromentalOverviewStats');

	$this->get('/overview/donut_stats', 'UserFrosting\Sprinkle\EnviroSense\Controller\ApiController:listEnviromentalOverviewDonutStats');

	$this->get('/overview/zone/daqi/{enviro_sensor_id}', 'UserFrosting\Sprinkle\EnviroSense\Controller\ApiController:listEnviromentalZoneOverviewDAQI');

	$this->get('/overview/zone/stats/{enviro_sensor_id}', 'UserFrosting\Sprinkle\EnviroSense\Controller\ApiController:listEnviromentalZoneOverviewStats');

	$this->get('/overview/zone/donut_stats/{enviro_sensor_id}', 'UserFrosting\Sprinkle\EnviroSense\Controller\ApiController:listEnviromentalZoneOverviewDonutStats');

	$this->get('/stats/{start}/{end}', 'UserFrosting\Sprinkle\EnviroSense\Controller\ApiController:listEnviromentalStats');

	$this->get('/list/enviro_sensors', 'UserFrosting\Sprinkle\EnviroSense\Controller\ApiController:listEnviroSensors');

	$this->get('/daqi/stats/{start}/{end}/{enviro_sensor_ids}', 'UserFrosting\Sprinkle\EnviroSense\Controller\ApiController:listEnviromentalDAQIStats');

	$this->get('/aqi_stats/{start}/{end}/{enviro_sensor_ids}', 'UserFrosting\Sprinkle\EnviroSense\Controller\ApiController:listEnviromentalAqiStats');
});

// All the system admin routes
$app->group('/admin/enviro-sense', function () {
	$this->get('/enviro_sensors', 'UserFrosting\Sprinkle\EnviroSense\Controller\EnviroSensorController:pageEnviroSensors');
	$this->post('/enviro_sensor', 'UserFrosting\Sprinkle\EnviroSense\Controller\EnviroSensorController:addEnviroSensors');
	$this->post('/enviro_sensor/u', 'UserFrosting\Sprinkle\EnviroSense\Controller\EnviroSensorController:updateEnviroSensor');
});