<?php
/**
 * @package ElephantWiFi
 * @author Luke Rayner/ElephantWiFi
 */
use UserFrosting\Sprinkle\Core\Util\NoCache;

// All the elephantwifi routes
$app->group('/elephantwifi', function () {
	// GET - Dashboard Page page
	$this->get('/dashboard', 'UserFrosting\Sprinkle\ElephantWifi\Controller\DashboardController:pageDashboard')
		->setName('elephantwifi-dashboard');

	$this->get('/user-reports/wifi-connection-report', 'UserFrosting\Sprinkle\ElephantWifi\Controller\WifiConnectionReportController:pageWifiConnectionReport');

	$this->get('/user-reports/internet-user-report', 'UserFrosting\Sprinkle\ElephantWifi\Controller\InternetUserReportController:pageInternetUserReport');

	$this->get('/user-reports/location-report', 'UserFrosting\Sprinkle\ElephantWifi\Controller\LocationReportController:pageLocationReport');

	$this->get('/user-reports/comparison-report', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ComparisonReportController:pageComparisonReport');

	$this->get('/user-reports/network-report', 'UserFrosting\Sprinkle\ElephantWifi\Controller\NetworkReportController:pageNetworkReport');

	$this->get('/user-reports/content-filter-report', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ContentFilterReportController:pageContentFilterReport');

	$this->get('/user-reports/list-of-visitors-report', 'UserFrosting\Sprinkle\ElephantWifi\Controller\VisitorInformationReportController:pageVisitorInformationReport');

	$this->get('/user-reports/users', 'UserFrosting\Sprinkle\ElephantWifi\Controller\WifiUsersReportController:pageWifiUsersReport');

	$this->get('/wlan-reports/heatmap', 'UserFrosting\Sprinkle\ElephantWifi\Controller\HeatmapReportController:pageHeatmapReport');

	$this->post('/ips', 'UserFrosting\Sprinkle\ElephantWifi\Controller\IpController:pageIps');
});

// All the elephantwifi admin routes
$app->group('/admin/elephantwifi', function () {
	$this->get('/ips', 'UserFrosting\Sprinkle\ElephantWifi\Controller\IpController:pageIps');
	$this->post('/ips', 'UserFrosting\Sprinkle\ElephantWifi\Controller\IpController:addIp');
	$this->get('/ips/delete/{ip_id}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\IpController:deleteIp');

	$this->get('/access_points', 'UserFrosting\Sprinkle\ElephantWifi\Controller\AccessPointController:pageAccessPoints');
	$this->post('/access_points/u', 'UserFrosting\Sprinkle\ElephantWifi\Controller\AccessPointController:updateAccessPoint');

	$this->get('/captive_portal/settings', 'UserFrosting\Sprinkle\ElephantWifi\Controller\CaptivePortalSettingsController:pageCaptivePortalSettings');
	$this->post('/captive_portal/settings/u', 'UserFrosting\Sprinkle\ElephantWifi\Controller\CaptivePortalSettingsController:updateCaptivePortalSettings');
	$this->post('/captive_portal/file_upload/post/{type}/{venue_id}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\CaptivePortalSettingsController:fineUploaderEndpointPost');
	$this->post('/captive_portal/file_upload/delete/{type}/{venue_id}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\CaptivePortalSettingsController:fineUploaderEndpointDelete');
	$this->get('/captive_portal/text_labels', 'UserFrosting\Sprinkle\ElephantWifi\Controller\CaptivePortalSettingsController:pageCaptivePortalTextLabelConfig');
	$this->post('/captive_portal/text_labels/u', 'UserFrosting\Sprinkle\ElephantWifi\Controller\CaptivePortalSettingsController:updateCaptivePortalTextLabelConfig');
	$this->get('/captive_portal/custom_css', 'UserFrosting\Sprinkle\ElephantWifi\Controller\CaptivePortalSettingsController:pageCaptivePortalCSSConfig');
	$this->post('/captive_portal/custom_css/u', 'UserFrosting\Sprinkle\ElephantWifi\Controller\CaptivePortalSettingsController:updateCaptivePortalCSSConfig');
	$this->get('/captive_portal/controller_integration', 'UserFrosting\Sprinkle\ElephantWifi\Controller\CaptivePortalSettingsController:pageCaptivePortalControllerIntegration');

	$this->get('/captive_portal/preview/splash', 'UserFrosting\Sprinkle\ElephantWifi\Controller\CaptivePortalController:previewSplash');

	$this->get('/marketing/list', 'UserFrosting\Sprinkle\ElephantWifi\Controller\MarketingController:pageList');
	$this->get('/marketing/list_type', 'UserFrosting\Sprinkle\ElephantWifi\Controller\MarketingController:pageListType');

	// SendinBlue routes
	$this->get('/forms/sendinblue/marketing_list/create', 'UserFrosting\Sprinkle\ElephantWifi\Controller\MarketingController:formSendinBlueListCreate');
	$this->get('/forms/sendinblue/marketing_list/u/{marketing_list_id}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\MarketingController:formSendinBlueListUpdate');
	$this->post('/sendinblue/marketing/list', 'UserFrosting\Sprinkle\ElephantWifi\Controller\MarketingController:createSendinBlueList');
	$this->post('/sendinblue/marketing/list/u/{marketing_list_id}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\MarketingController:updateSendinBlueList');

	// Mailchimp routes
	$this->get('/forms/mailchimp/marketing_list/create', 'UserFrosting\Sprinkle\ElephantWifi\Controller\MarketingController:formMailchimpListCreate');
	$this->get('/forms/mailchimp/marketing_list/u/{marketing_list_id}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\MarketingController:formMailchimpListUpdate');
	$this->post('/mailchimp/marketing/list', 'UserFrosting\Sprinkle\ElephantWifi\Controller\MarketingController:createMailchimpList');

	$this->post('/marketing/list_type', 'UserFrosting\Sprinkle\ElephantWifi\Controller\MarketingController:createListType');
	$this->post('/marketing/list_type/u', 'UserFrosting\Sprinkle\ElephantWifi\Controller\MarketingController:updateListType');
	$this->get('/marketing/list_type/delete/{list_type_id}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\MarketingController:deleteListType');

	$this->get('/marketing/list/delete/{marketing_list_id}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\MarketingController:deleteList');

	$this->get('/marketing/reshow_marketing/{time}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\MarketingController:reshowMarketing');

	$this->post('/venue-marketing-details/update', 'UserFrosting\Sprinkle\ElephantWifi\Controller\MarketingController:updateVenueMarketingDetails');
});

$app->group('/captive_portal', function () {
	$this->get('/preview/splash', 'UserFrosting\Sprinkle\ElephantWifi\Controller\CaptivePortalController:previewSplash');

	$this->get('/init/{local_venue_id}/{mac_address}/{ap_mac_address}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\CaptivePortalController:initSession');

	$this->get('/intro/{session_id}/{limited_browser}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\CaptivePortalController:displayIntro');

	$this->post('/email_checker/{session_id}/{marketing_types}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\CaptivePortalController:checkEmail');

	$this->get('/password/{session_id}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\CaptivePortalController:passwordPage');
	$this->post('/password/{session_id}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\CaptivePortalController:passwordCheck');

	$this->get('/splash_page/{session_id}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\CaptivePortalController:displaySplash');

	$this->get('/social_auth/{session_id}/{provider}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\CaptivePortalController:displaySocialAuthInit');

	$this->get('/social_auth/responder', 'UserFrosting\Sprinkle\ElephantWifi\Controller\CaptivePortalController:socialAuthResponder');
	
	$this->post('/social_auth/extra_info', 'UserFrosting\Sprinkle\ElephantWifi\Controller\CaptivePortalController:socialAuthExtraInfo');

	$this->get('/authorise_page/{session_id}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\CaptivePortalController:authorisePage');

	$this->get('/redirecting/{session_id}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\CaptivePortalController:redirectUponSuccess');

	$this->get('/register/{session_id}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\CaptivePortalController:displayRegistrationForm');
	$this->post('/register/{session_id}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\CaptivePortalController:registerIdentity');

	$this->post('/authorise_registered_device/{session_id}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\CaptivePortalController:authRegisteredDevice');

	$this->get('/css/{venue_id}/venue-custom.css', 'UserFrosting\Sprinkle\ElephantWifi\Controller\CaptivePortalController:customCSS');
});

// All the elephantwifi api routes
$app->group('/elephantwifi/api', function () {
	$this->get('/dwelltime/average/{start}/{end}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:listAverageDwelltime');

	$this->get('/list/daily_visitor_counts/{start}/{end}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:listVenueDailyVisitorCounts');

	$this->get('/stats/venue/authorised_visitors_durations/{start}/{end}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:listVenueStatsAuthorisedVisitorDurations');

	$this->get('/stats/venue/provider_identity_count/{start}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:listIdentityProviders');

	$this->get('/stats/venue/males_vs_females_count/{start}/{end}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:listGenderCount');

	$this->get('/stats/venue/males_vs_females_compare_age_count/{start}/{end}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:listGenderCompareAgeCount');

	$this->get('/authorised_visitors_per_hourofday/{start}/{end}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:listAuthorisedVisitorsTimeOfDay');

	$this->get('/list/unique_visitor_connections/{start}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:listUniqueVisitorConnections');

	$this->get('/controller_users/online', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:listUnifiOnlineUsers');

	$this->get('/visitor_report/new_vs_repeat/{start}/{end}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:listVisitorReportNewVsRepeat');

	$this->get('/visitor_report/visitors_per_hourofday/{start}/{end}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:listVisitorReportVisitorsTimeOfDay');

	$this->get('/visitor_report/visitors_durations/{start}/{end}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:listVisitorReportVisitorDurations');

	$this->get('/visitor_report/busiest_zones/{start}/{end}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:listVisitorReportZoneVisitorsComparison');

	$this->get('/stats/venue/connect_vs_internet_count/{start}/{end}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:connectVsInterntVisitorCount');

	$this->get('/visitor_report/alltime_averages', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:listVisitorAveragesAlltime');

	$this->get('/internet_user_report/new_vs_repeat/{start}/{end}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:listInternetUserReportNewVsRepeat');

	$this->get('/internet_user_report/visitors_per_hourofday/{start}/{end}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:listInternetUserReportVisitorsTimeOfDay');

	$this->get('/internet_user_report/visitors_durations/{start}/{end}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:listInternetUserReportVisitorDurations');

	$this->get('/weather_daily/{start}/{end}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:listWeatherDaily');

	$this->get('/internet_user_report/busiest_zones/{start}/{end}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:listInternetUserReportZoneVisitorsComparison');

	$this->get('/internet_user_report/alltime_averages', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:listBrowserAveragesAlltime');

	$this->get('/list_visitors_with_postcodes/{start}/{end}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:listVisitorsWithPostcodes');

	$this->get('/location_visitor_breakdown/{start}/{end}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:listLocationVisitorBreakdown');

	$this->get('/old_data_comparison_report/new_vs_repeat/{start}/{end}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:oldDataComparisonNewVsRepeat');

	$this->get('/old_data_comparison_report/age_breakdown/{start}/{end}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:oldDataComparisonAgeBreakdown');

	$this->get('/stats/venue/males_vs_females_count/{start}/{end}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:listGenderCount');

	$this->get('/old_data_report/new_vs_repeat/{start}/{end}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:oldDataNewVsRepeat');

	$this->get('/old_data_report/gender_count/{start}/{end}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:oldDataGenderCount');

	$this->get('/old_data_report/age_breakdown/{start}/{end}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:oldDataAgeBreakdown');

	$this->get('/old_data_report/start_end_dates', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:oldDataStartEndDates');

	$this->get('/stats/allusers/{historyhours}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:listAllUserStats');

	$this->get('/stats/sessions/{start}/{end}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:listAllUserSessions');

	$this->get('/network_report/device_info/{start}/{end}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:listNetworkReportDeviceInfo');

	$this->get('/stats/ap/hourly/{start}/{end}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:listHourlyApStats');

	$this->get('/stats/venue/hourly/{start}/{end}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:listHourlyVenueStats');

	$this->get('/stats/user_logins/hourly/{start}/{end}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:listUserLoginsStatsHourly');

	$this->get('/filtering_report/registered_email_stats/{start}/{end}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:listRegisteredEmailStats');

	$this->get('/filtering_report/emails_sent_count/{start}/{end}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:listEmailsSentCount');

	$this->get('/filtering_report/web_titan_stats/{start}/{end}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:webTitanFilteringData');

	$this->get('/stats/total_unique_venue_visitors/{start}/{end}/{type}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:totalUniqueVenueVisitors');

	$this->get('/list/visitor_details/{user_id}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:visitorDetails');

	$this->get('/list/visitors_basic_details/{start}/{end}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:listVisitorBasicDetails');

	$this->get('/stats/venue/daily/{start}/{end}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:listDailyVenueStats');

	$this->get('/connected_users/average/timeofday/{start}/{end}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:listAverageVisitorsTimeOfDay');

	$this->get('/connected_users/average/weekday/{start}/{end}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:listAverageVisitorsWeekDay');

	$this->get('/controller_users/all', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:listAllUnifiUsers');

	$this->get('/stats/venue/heat_map/visitors/{start}/{end}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:listVisitorHeatmap');

	$this->get('/total_connected_this_week', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:listTotalConnectedThisWeek');

	$this->get('/landing_page_map_metrics', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:listLandingPageMapMetrics');

	$this->get('/stats/users_vs_browsers/{start}/{end}', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:totalUsersVsBrowsers');

	// System API routes
	$this->get('/list/access_points', 'UserFrosting\Sprinkle\ElephantWifi\Controller\ApiController:listAccessPoints');
});



$app->group('/elephantwifi', function () {
	$this->get('/log/download_personal_visitor_information', 'UserFrosting\Sprinkle\ElephantWifi\Controller\LoggingController:logDownloadPersonalVisitorInformation');

	$this->get('/wifi_user/dashboard', 'UserFrosting\Sprinkle\ElephantWifi\Controller\WifiUserController:showWifiUserDashboard')
		->setName('wifi-user-dashboard');

	$this->post('/wifi_user/u/personal_info', 'UserFrosting\Sprinkle\ElephantWifi\Controller\WifiUserController:updateWifiUser');

	$this->get('/wifi_user/delete', 'UserFrosting\Sprinkle\ElephantWifi\Controller\WifiUserController:deleteWifiUser');
	
	$this->get('/wifi_user/splash', 'UserFrosting\Sprinkle\ElephantWifi\Controller\WifiUserController:showWifiUserSplash');
});