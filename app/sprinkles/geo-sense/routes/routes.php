<?php
/**
 * @package Intelli-Sense
 * @author Luke Rayner/ElephantWiFi
 */
use UserFrosting\Sprinkle\Core\Util\NoCache;

// All the geo-sense routes
$app->group('/geo-sense', function () {
	// GET - Dashboard Page page
	$this->get('/dashboard', 'UserFrosting\Sprinkle\GeoSense\Controller\DashboardController:pageDashboard')
		->setName('geo-sense-dashboard');

	$this->get('/visitor_report', 'UserFrosting\Sprinkle\GeoSense\Controller\VisitorReportController:pageVisitorReport');

	$this->get('/visitor_report_old', 'UserFrosting\Sprinkle\GeoSense\Controller\VisitorReportOldController:pageVisitorReportOld');

	$this->get('/zone_report', 'UserFrosting\Sprinkle\GeoSense\Controller\ZoneReportController:pageZoneReport');

	$this->get('/day_night_report', 'UserFrosting\Sprinkle\GeoSense\Controller\DayNightReportController:pageDayNightReport');

	$this->get('/visit_report', 'UserFrosting\Sprinkle\GeoSense\Controller\VisitReportController:pageVisitReport');

	$this->get('/repeat_visitor_report', 'UserFrosting\Sprinkle\GeoSense\Controller\RepeatVisitorReportController:pageRepeatVisitorReport');

	$this->get('/multi_venue_report', 'UserFrosting\Sprinkle\GeoSense\Controller\MultiVenueReportController:pageMultiVenueReport');

	$this->get('/device_vendor_report/venue', 'UserFrosting\Sprinkle\GeoSense\Controller\DeviceVendorReportController:pageDeviceVendorReport');

	$this->get('/event_info', 'UserFrosting\Sprinkle\GeoSense\Controller\EventInfoReportController:pageEventInfoReport');

	$this->get('/national_stats_report', 'UserFrosting\Sprinkle\GeoSense\Controller\NationalStatsReportController:pageNationalStatsReport');

	$this->get('/comparison_charts/fixed', 'UserFrosting\Sprinkle\GeoSense\Controller\ComparisonChartsController:pageFixedDatesComparisonReport');

	$this->get('/comparison_charts/custom', 'UserFrosting\Sprinkle\GeoSense\Controller\ComparisonChartsController:pageCustomDatesComparisonReport');

	$this->get('/comparison_charts/customv2', 'UserFrosting\Sprinkle\GeoSense\Controller\ComparisonChartsController:pageCustomDatesV2ComparisonReport');

	$this->get('/heat_map', 'UserFrosting\Sprinkle\GeoSense\Controller\HeatmapReportController:pageHeatmapReport');

	$this->get('/journeys_diagram', 'UserFrosting\Sprinkle\GeoSense\Controller\JourneyReportController:pageJourneyReport');
});

// All the geo-sense admin routes
$app->group('/admin/geo-sense', function () {
	$this->get('/drones/summary', 'UserFrosting\Sprinkle\GeoSense\Controller\DroneController:pageDroneSummary');
	$this->get('/drones/all', 'UserFrosting\Sprinkle\GeoSense\Controller\DroneController:pageDronesAll');

	$this->get('/drones', 'UserFrosting\Sprinkle\GeoSense\Controller\DroneController:pageDrones');
	$this->get('/forms/drones/create', 'UserFrosting\Sprinkle\GeoSense\Controller\DroneController:formDroneCreate');
	$this->get('/forms/drones/update/{drone_id}', 'UserFrosting\Sprinkle\GeoSense\Controller\DroneController:formDroneUpdate');
	$this->get('/forms/drones/details/{drone_id}', 'UserFrosting\Sprinkle\GeoSense\Controller\DroneController:formDroneDetails');
	$this->post('/drones', 'UserFrosting\Sprinkle\GeoSense\Controller\DroneController:addDrone');
	$this->post('/drones/update/{drone_id}', 'UserFrosting\Sprinkle\GeoSense\Controller\DroneController:updateDrone');

	$this->get('/drones/activity', 'UserFrosting\Sprinkle\GeoSense\Controller\DroneController:pageDronesActivity');

	$this->get('/whitelist', 'UserFrosting\Sprinkle\GeoSense\Controller\WhitelistController:pageWhitelist');
	$this->post('/whitelist', 'UserFrosting\Sprinkle\GeoSense\Controller\WhitelistController:addWhitelistEntry');
	$this->post('/whitelist/u', 'UserFrosting\Sprinkle\GeoSense\Controller\WhitelistController:updateWhitelistEntry');
	$this->post('/whitelist/add_by_device_uuid', 'UserFrosting\Sprinkle\GeoSense\Controller\WhitelistController:addWhitelistEntryByDeviceUUID');
});

// All the geo-sense api routes
$app->group('/geo-sense/api', function () {
	$this->get('/footfall_count/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listFootfallCount');

	$this->get('/stats/venue/footfall_count/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listVenueStatsFootfallCount');

	$this->get('/new_vs_repeat/today', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listNewVsRepeatToday');

	$this->get('/stats/daily_average_dwelltime/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listStatsDailyAverageDwelltime');

	$this->get('/stats/venue/unique_visitors/daily/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listVenueStatsUniqueVisitorsDaily');

	$this->get('/stats/venue/unique_visitors/weekdays_compared/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listVenueStatsUniqueVisitorsWeekdaysCompared');

	$this->get('/stats/venue/visitors_durations/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listVenueStatsVisitorDurations');
	$this->get('/stats/venue/visitors_durations/{start}/{end}/{zone_ids}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listVenueStatsVisitorDurations');

	$this->get('/stats/venue/unique_visitors/hours/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listVenueStatsUniqueVisitorsTimeOfDay');

	$this->get('/visitor_report/new_vs_repeat/old/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listVisitorReportNewVsRepeatOld');

	$this->get('/visitor_report/visitors_per_hourofday/old/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listVisitorReportVisitorsTimeOfDayOld');

	$this->get('/visitor_report/busiest_zones/old/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listVisitorReportZoneVisitorsComparisonOld');

	$this->get('/visitor_report/weather_daily/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listVisitorReportWeatherDaily');

	$this->get('/visitor_report/alltime_averages', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listVisitorAveragesAlltime');

	$this->get('/visitor_report/visitors_per_hourofday/{start}/{end}/{zone_ids}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listVisitorReportVisitorsTimeOfDay');

	$this->get('/stats/venue/visitors_durations/{start}/{end}/{zone_ids}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listVenueStatsVisitorDurations');

	$this->get('/visitor_report/new_vs_repeat/{start}/{end}/{zone_ids}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listVisitorReportNewVsRepeat');

	$this->get('/visitor_report/busiest_zones/{start}/{end}/{zone_ids}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listVisitorReportZoneVisitorsComparison');

	$this->get('/visitor_report/visitors_per_hourofday/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listVisitorReportVisitorsTimeOfDay');

	$this->get('/stats/venue/visitors_durations/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listVenueStatsVisitorDurations');

	$this->get('/visitor_report/new_vs_repeat/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listVisitorReportNewVsRepeat');

	$this->get('/visitor_report/busiest_zones/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listVisitorReportZoneVisitorsComparison');

	$this->get('/stats/average_dwelltime/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listStatsAverageDwelltime');

	$this->get('/stats/average_dwelltime/{start}/{end}/{zone_ids}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listStatsAverageDwelltime');

	$this->get('/stats/venue/visitors_durations/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listVenueStatsVisitorDurations');

	$this->get('/visitor_report/alltime_averages/zone/{zone_id}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listZoneVisitorAveragesAlltime');

	$this->get('/stats/average_dwelltime/zone/{zone_id}/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listZoneStatsAverageDwelltimeV2');

	$this->get('/visitor_report/unique_daily/zone/{zone_id}/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listZoneVisitorReportUniqueDaily');

	$this->get('/zone_report/visitors_per_hourofday/{zone_id}/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listZoneReportVisitorsTimeOfDay');

	$this->get('/zone_report/new_vs_repeat/{zone_id}/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listZoneReportNewVsRepeat');

	$this->get('/stats/zone/visitors_durations/{zone_id}/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listZoneStatsVisitorDurations');

	$this->get('/day_night_report/new_vs_repeat/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listDayNightReportNewVsRepeat');

	$this->get('/day_night_report/visitors_per_hourofday/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listDayNightReportVisitorsTimeOfDay');

	$this->get('/day_night_report/busiest_zones/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listDayNightReportZoneVisitorsComparison');

	$this->get('/day_night_report/visitors_durations/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listDayNightReportVisitorDurations');

	$this->get('/stats/venue/visits_comparison/{start}/{end}/{bucket}/{switch}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listVisitComparison');

	$this->get('/repeat_visitor_report/repeat_visitors/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listRepeatVisitorReportRepeatVisitors');

	$this->get('/venue_report/alltime_averages', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listVisitorAveragesAlltimeVenueReport');

	$this->get('/venue_report/average_dwelltime/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listVenueReportAverageDwelltime');

	$this->get('/venue_report/visitors_durations/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listVenueReportVisitorDurations');

	$this->get('/venue_report/new_vs_repeat/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listVenueReportNewVsRepeat');

	$this->get('/venue_report/visitors_per_hourofday/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listVenueReportVisitorsTimeOfDay');

	$this->get('/venue_report/busiest_venues/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listVisitorReportVenueVisitorsComparison');

	$this->get('/stats/venue/device_vendors/daily/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listVenueStatsDeviceVendorDaily');

	$this->get('/stats/venue/device_vendors/totals/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listVenueStatsDeviceVendorTotals');

	$this->get('/event/footfall_count', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listEventFootfallCount');

	$this->get('/event/footfall_series', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listEventFootfallSeries');

	$this->post('/national_stats_report/average_dwelltime/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listNationalStatsAverageDwelltime');

	$this->post('/national_stats_report/visitors_per_hourofday/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listNationalStatsVisitorsTimeOfDay');

	$this->post('/national_stats_report/visitors_durations/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listNationalStatsVisitorDurations');

	$this->post('/national_stats_report/new_vs_repeat/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listNationalStatsNewVsRepeat');

	$this->get('/stats/venue/unique_visitors/current_week_compared/incl_prev_year/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listVenueStatsUniqueVisitorsCurrentWeekComparedInclPrevYear');

	$this->get('/stats/venue/unique_visitors/current_month_compared/incl_prev_year/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listVenueStatsUniqueVisitorsCurrentMonthComparedInclPrevYear');

	$this->get('/stats/venue/unique_visitors/current_year_compared/incl_prev_year/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listVenueStatsUniqueVisitorsCurrentYearComparedToPrevYear');

	$this->get('/stats/venue/unique_visitors/weekdays_compared/incl_prev_year/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listVenueStatsUniqueVisitorsWeekdaysComparedInclPrevYear');

	$this->get('/stats/venue/unique_visitors/comparison_charts_custom_old/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listVenueStatsUniqueVisitorsComparisonChartsCustomOld');

	$this->get('/stats/venue/heat_map/visitors/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listVisitorHeatmap');

	$this->get('/stats/venue/unique_visitors/comparison_charts_custom/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listVenueStatsUniqueVisitorsComparisonChartsCustomV2');

	$this->get('/stats/venue/sankey_chart/visitors/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listVisitorJourneyReport');
	$this->get('/stats/venue/sankey_chart/visitors/{start}/{end}/{max_route_length}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listVisitorJourneyReport');

	$this->get('/drone_summary/all_drones', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:summaryAllDrones');

	$this->get('/drones', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listDrones');

	$this->get('/stats/drone/health/{drone_id}/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listDronesHealth');

	$this->get('/stats/venue/drones_activity/{bucketsize}/{start}/{end}', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listVenueStatsDronesActivity');

	$this->get('/whitelist', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listWhitelistEntries');

	$this->get('/whitelist/candidates', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listWhitelistCandidates');
	
	$this->get('/drones/all', 'UserFrosting\Sprinkle\GeoSense\Controller\ApiController:listDronesAll');
});



