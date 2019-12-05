<?php
/**
 * @package Intelli-Sense
 * @author Luke Rayner/ElephantWiFi
 */
use UserFrosting\Sprinkle\Core\Util\NoCache;

// GET - Front page
$app->get('/', 'UserFrosting\Sprinkle\IntelliSense\Controller\AccountController:pageHome')
	->setName('uri_home');

// All the account routes
$app->group('/account', function () {
	// GET - Login page
	$this->get('/login', 'UserFrosting\Sprinkle\IntelliSense\Controller\AccountController:pageLogin')
		->setName('sign-in');
	// POST - Handle login request
	$this->post('/login', 'UserFrosting\Sprinkle\IntelliSense\Controller\AccountController:login');

	// GET - Forgot password page
	$this->get('/forgot-password', 'UserFrosting\Sprinkle\IntelliSense\Controller\AccountController:pageForgotPassword')
		->setName('forgot-password');
	// POST - Handle forgot password request
	$this->post('/forgot-password', 'UserFrosting\Sprinkle\IntelliSense\Controller\AccountController:forgotPassword');

	// GET - Resend activation email page
	$this->get('/resend-activation', 'UserFrosting\Sprinkle\IntelliSense\Controller\AccountController:pageResendActivation');
	// POST - Handle activation email request
	$this->post('/resend-activation', 'UserFrosting\Sprinkle\IntelliSense\Controller\AccountController:resendActivation');

	// GET - Handle the account verification request
	$this->get('/verify', 'UserFrosting\Sprinkle\IntelliSense\Controller\AccountController:verify');

	// GET - Set password page
	$this->get('/set-password/confirm', 'UserFrosting\Sprinkle\IntelliSense\Controller\AccountController:pageResetPassword');
	// POST - Handle set password request
	$this->post('/set-password', 'UserFrosting\Sprinkle\IntelliSense\Controller\AccountController:setPassword');

	// GET - Account settings page
	$this->get('/settings', 'UserFrosting\Sprinkle\IntelliSense\Controller\AccountController:pageAccountSettings');
	$this->post('/settings', 'UserFrosting\Sprinkle\IntelliSense\Controller\AccountController:accountSettings');

	// GET - Account manage API key page
	$this->get('/manage_api_key', 'UserFrosting\Sprinkle\IntelliSense\Controller\PublicApiController:showAPIKeyMaintenancePage');
	$this->get('/manage_api_key/{refresh}', 'UserFrosting\Sprinkle\IntelliSense\Controller\PublicApiController:showAPIKeyMaintenancePage');
});

$app->group('/modals/users', function () {
    $this->get('/create', 'UserFrosting\Sprinkle\IntelliSense\Controller\UserController:formUserCreate');
    $this->get('/edit', 'UserFrosting\Sprinkle\IntelliSense\Controller\UserController:formUserEdit');
    // $this->get('/password', 'UserFrosting\Sprinkle\IntelliSense\Controller\UserController:formUserEditPassword');
})->add('authGuard')->add(new NoCache());

// All the account routes
$app->group('/api', function () {
	$this->get('/list/allowed_zones', 'UserFrosting\Sprinkle\IntelliSense\Controller\ApiController:listAllowedZones');

	$this->get('/list/zones', 'UserFrosting\Sprinkle\IntelliSense\Controller\ApiController:listZones');

	$this->get('/events/all', 'UserFrosting\Sprinkle\IntelliSense\Controller\ApiController:listAllEvents');

	$this->get('/list/venues', 'UserFrosting\Sprinkle\IntelliSense\Controller\ApiController:listVenues');

	$this->get('/list/controllers', 'UserFrosting\Sprinkle\IntelliSense\Controller\ApiController:listControllers');

	$this->get('/list/companies', 'UserFrosting\Sprinkle\IntelliSense\Controller\ApiController:listCompanies');

	$this->get('/tags/all', 'UserFrosting\Sprinkle\IntelliSense\Controller\ApiController:listAllTags');

	$this->get('/countries/all', 'UserFrosting\Sprinkle\IntelliSense\Controller\ApiController:listCountries');
	$this->get('/regions/all', 'UserFrosting\Sprinkle\IntelliSense\Controller\ApiController:listRegions');
	$this->get('/areas/all', 'UserFrosting\Sprinkle\IntelliSense\Controller\ApiController:listAreas');

	$this->get('/categories/all', 'UserFrosting\Sprinkle\IntelliSense\Controller\ApiController:listAllCategories');
	$this->get('/sub_categories/all', 'UserFrosting\Sprinkle\IntelliSense\Controller\ApiController:listAllSubCategories');
	$this->get('/event_categories/all', 'UserFrosting\Sprinkle\IntelliSense\Controller\ApiController:listAllEventCategories');

	$this->get('/zones/all', 'UserFrosting\Sprinkle\IntelliSense\Controller\ApiController:listAllZones');
	
	$this->get('/calendar-events/venue', 'UserFrosting\Sprinkle\IntelliSense\Controller\ApiController:listVenueCalendarEvents');
});

// All the system admin routes
$app->group('/admin', function () {
	$this->get('/events', 'UserFrosting\Sprinkle\IntelliSense\Controller\EventController:pageEvents');
	$this->get('/forms/event/create', 'UserFrosting\Sprinkle\IntelliSense\Controller\EventController:formEventcreate');
	$this->get('/forms/event/update/{event_id}', 'UserFrosting\Sprinkle\IntelliSense\Controller\EventController:formEventupdate');
	$this->post('/event', 'UserFrosting\Sprinkle\IntelliSense\Controller\EventController:addEvent');
	$this->post('/event/u/{event_id}', 'UserFrosting\Sprinkle\IntelliSense\Controller\EventController:updateEvent');
	$this->get('/event/delete/{event_id}', 'UserFrosting\Sprinkle\IntelliSense\Controller\EventController:deleteEvent');

	$this->get('/venues', 'UserFrosting\Sprinkle\IntelliSense\Controller\VenueController:pageVenues');
	$this->get('/forms/venues', 'UserFrosting\Sprinkle\IntelliSense\Controller\VenueController:formVenueCreate');
	$this->get('/forms/venues/info/{venue_id}', 'UserFrosting\Sprinkle\IntelliSense\Controller\VenueController:formVenueInfo');
	$this->get('/forms/venues/u/{venue_type}/{venue_id}', 'UserFrosting\Sprinkle\IntelliSense\Controller\VenueController:formVenueEdit');
	$this->post('/venue/create', 'UserFrosting\Sprinkle\IntelliSense\Controller\VenueController:addVenue');
	$this->post('/venues/update/{venue_id}/{venue_type}', 'UserFrosting\Sprinkle\IntelliSense\Controller\VenueController:updateVenue');
	$this->get('/venues/delete/{venue_id}', 'UserFrosting\Sprinkle\IntelliSense\Controller\VenueController:deleteVenue');

	$this->get('/controllers', 'UserFrosting\Sprinkle\IntelliSense\Controller\ControllerController:pageController');
	$this->get('/forms/controllers', 'UserFrosting\Sprinkle\IntelliSense\Controller\ControllerController:formControllerCreate');
	$this->get('/forms/controllers/u/{controller_id}', 'UserFrosting\Sprinkle\IntelliSense\Controller\ControllerController:formControllerEdit');

	$this->post('/controllers/create', 'UserFrosting\Sprinkle\IntelliSense\Controller\ControllerController:addController');
	$this->post('/controllers/update/{controller_id}', 'UserFrosting\Sprinkle\IntelliSense\Controller\ControllerController:updateController');
	$this->get('/controllers/delete/{controller_id}', 'UserFrosting\Sprinkle\IntelliSense\Controller\ControllerController:deleteController');

	$this->get('/companies', 'UserFrosting\Sprinkle\IntelliSense\Controller\CompanyController:pageCompanies');
	$this->post('/companies', 'UserFrosting\Sprinkle\IntelliSense\Controller\CompanyController:addCompany');
	$this->post('/companies/u', 'UserFrosting\Sprinkle\IntelliSense\Controller\CompanyController:updateCompany');

	$this->get('/tags', 'UserFrosting\Sprinkle\IntelliSense\Controller\TagController:pageTags');
	$this->post('/tags', 'UserFrosting\Sprinkle\IntelliSense\Controller\TagController:addTag');
	$this->post('/tag/u', 'UserFrosting\Sprinkle\IntelliSense\Controller\TagController:updateTag');

	$this->get('/nuts_classifications', 'UserFrosting\Sprinkle\IntelliSense\Controller\NutsClassificationController:pageNutsClassification');
	$this->post('/countries', 'UserFrosting\Sprinkle\IntelliSense\Controller\NutsClassificationController:addCountry');
	$this->post('/country/u', 'UserFrosting\Sprinkle\IntelliSense\Controller\NutsClassificationController:updateCountry');
	$this->post('/regions', 'UserFrosting\Sprinkle\IntelliSense\Controller\NutsClassificationController:addRegion');
	$this->post('/region/u', 'UserFrosting\Sprinkle\IntelliSense\Controller\NutsClassificationController:updateRegion');
	$this->post('/areas', 'UserFrosting\Sprinkle\IntelliSense\Controller\NutsClassificationController:addArea');
	$this->post('/area/u', 'UserFrosting\Sprinkle\IntelliSense\Controller\NutsClassificationController:updateArea');

	$this->get('/categories', 'UserFrosting\Sprinkle\IntelliSense\Controller\CategoryController:pageCategories');
	$this->post('/categories', 'UserFrosting\Sprinkle\IntelliSense\Controller\CategoryController:addCategory');
	$this->post('/category/u', 'UserFrosting\Sprinkle\IntelliSense\Controller\CategoryController:updateCategory');
	$this->post('/sub_categories', 'UserFrosting\Sprinkle\IntelliSense\Controller\CategoryController:addSubCategory');
	$this->post('/sub_category/u', 'UserFrosting\Sprinkle\IntelliSense\Controller\CategoryController:updateSubCategory');
	$this->post('/event_categories', 'UserFrosting\Sprinkle\IntelliSense\Controller\CategoryController:addEventCategory');
	$this->post('/event_category/u', 'UserFrosting\Sprinkle\IntelliSense\Controller\CategoryController:updateEventCategory');

	$this->get('/zones', 'UserFrosting\Sprinkle\IntelliSense\Controller\ZoneController:pageZones');
	$this->get('/forms/zones/create', 'UserFrosting\Sprinkle\IntelliSense\Controller\ZoneController:formZoneCreate');
	$this->get('/forms/zones/update/{zone_id}', 'UserFrosting\Sprinkle\IntelliSense\Controller\ZoneController:formZoneUpdate');
	$this->post('/zones', 'UserFrosting\Sprinkle\IntelliSense\Controller\ZoneController:addZone');
	$this->post('/zones/u/{zone_id}', 'UserFrosting\Sprinkle\IntelliSense\Controller\ZoneController:updateZone');
	$this->get('/zones/delete/{zone_id}', 'UserFrosting\Sprinkle\IntelliSense\Controller\ZoneController:deleteZone');
	$this->get('/zones_all', 'UserFrosting\Sprinkle\IntelliSense\Controller\ZoneController:pageZonesAll');

	$this->get('/site_settings', 'UserFrosting\Sprinkle\IntelliSense\Controller\SettingsController:pageShowSettings');
	$this->post('/site_settings/u', 'UserFrosting\Sprinkle\IntelliSense\Controller\SettingsController:updateSettings');
	$this->post('/whitelist/device_vendor/update', 'UserFrosting\Sprinkle\IntelliSense\Controller\SettingsController:updateWhitelistDeviceVendor');

	$this->get('/alert_notifications', 'UserFrosting\Sprinkle\IntelliSense\Controller\AlertNotificationController:pageAlertNotifications');
	$this->post('/alert_notifications/create', 'UserFrosting\Sprinkle\IntelliSense\Controller\AlertNotificationController:createAlertNotification');
	$this->post('/alert_notifications/u', 'UserFrosting\Sprinkle\IntelliSense\Controller\AlertNotificationController:updateAlertNotification');
	$this->get('/alert_notifications/delete/{alert_id}', 'UserFrosting\Sprinkle\IntelliSense\Controller\AlertNotificationController:deleteAlertNotification');

	$this->get('/event-calendar', 'UserFrosting\Sprinkle\IntelliSense\Controller\EventController:pageAdminEventCalendar');

	$this->post('/venue/file_upload/post/{venue_type}/map/{venue_id}', 'UserFrosting\Sprinkle\IntelliSense\Controller\VenueController:fineUploaderEndpointPost');
	$this->post('/venue/file_upload/delete/{venue_type}/map/{venue_id}', 'UserFrosting\Sprinkle\IntelliSense\Controller\VenueController:fineUploaderEndpointPost');
});

// All the system pdf routes
$app->group('/pdf', function () {
	$this->post('/pdf_preset/save', 'UserFrosting\Sprinkle\IntelliSense\Controller\PDFController:savePdfPreset');

	$this->get('/pdf_templates/header/{start}/{end}', 'UserFrosting\Sprinkle\IntelliSense\Controller\PDFController:getPdfHeader');
	$this->get('/pdf_templates/summary/{start}/{end}', 'UserFrosting\Sprinkle\IntelliSense\Controller\PDFController:getPdfSummary');
	$this->get('/pdf_templates/visitor_information/{start}/{end}', 'UserFrosting\Sprinkle\IntelliSense\Controller\PDFController:getPdfVisitorInformation');
	$this->get('/pdf_templates/dwell_summary/{start}/{end}', 'UserFrosting\Sprinkle\IntelliSense\Controller\PDFController:getPdfDwellSummary');
	$this->get('/pdf_templates/dwell_detailed_report/{start}/{end}', 'UserFrosting\Sprinkle\IntelliSense\Controller\PDFController:getPdfDwellDetailedReport');
	$this->get('/pdf_templates/average_timeOfDay/{start}/{end}', 'UserFrosting\Sprinkle\IntelliSense\Controller\PDFController:getPdfAverageTimeOfDay');
	$this->get('/pdf_templates/weather_overview/{start}/{end}', 'UserFrosting\Sprinkle\IntelliSense\Controller\PDFController:getPdfWeatherOverview');
	$this->get('/pdf_templates/zone_summary/{start}/{end}', 'UserFrosting\Sprinkle\IntelliSense\Controller\PDFController:getPdfZoneSummary');
	$this->get('/pdf_templates/single_zone_detailed_report/{start}/{end}/{zone_id}', 'UserFrosting\Sprinkle\IntelliSense\Controller\PDFController:getPdfSingleZoneDetailedReport');
	$this->get('/pdf_templates/comparison_information/{month}', 'UserFrosting\Sprinkle\IntelliSense\Controller\PDFController:getPdfComparisonInformation');
	$this->get('/pdf_templates/national_stats/{start}/{end}', 'UserFrosting\Sprinkle\IntelliSense\Controller\PDFController:getPdfNationalStats');
	$this->get('/pdf_templates/journey_report/{start}/{end}', 'UserFrosting\Sprinkle\IntelliSense\Controller\PDFController:getPdfJourneyReport');
	$this->get('/pdf_templates/visit_report/{start}/{end}', 'UserFrosting\Sprinkle\IntelliSense\Controller\PDFController:getPdfVisitReport');
	$this->get('/pdf_templates/repeat_visitors/{start}/{end}', 'UserFrosting\Sprinkle\IntelliSense\Controller\PDFController:getPdfRepeatVisitors');
});

// System routes
$app->get('/system/cron/{cron_id}/{secret_key}', 'UserFrosting\Sprinkle\IntelliSense\Controller\SystemController:processCronRequest');

$app->get('/users/switch_venue/{requested_venue_id}/{current_venue_id}', 'UserFrosting\Sprinkle\IntelliSense\Controller\UserController:switchVenue');

$app->get('/forms/user/session_expiry_time/{box_id}', 'UserFrosting\Sprinkle\IntelliSense\Controller\UserController:formSessionExpiryTimeEdit');
$app->post('/user/session_expiry_time/u', 'UserFrosting\Sprinkle\IntelliSense\Controller\UserController:updateSessionExpiryTime');

// User routes
$app->get('/users', 'UserFrosting\Sprinkle\IntelliSense\Controller\UserController:pageUsers');
$app->post('/users', 'UserFrosting\Sprinkle\IntelliSense\Controller\UserController:createUser');
$app->post('/users/u/{user_id}', 'UserFrosting\Sprinkle\IntelliSense\Controller\UserController:updateUser');

$app->get('/event-calendar', 'UserFrosting\Sprinkle\IntelliSense\Controller\EventController:pageEventCalendar');

$app->get('/pdf-report-generation', 'UserFrosting\Sprinkle\IntelliSense\Controller\PDFController:pagePdfReportGeneration');

$app->get('/message-center', 'UserFrosting\Sprinkle\IntelliSense\Controller\AlertNotificationController:pageMessageCenter');
$app->post('/alert_seen/{alert_id}', 'UserFrosting\Sprinkle\IntelliSense\Controller\AlertNotificationController:acknowledgeAlert');

// Test routes
$app->get('/test/sendinblue', 'UserFrosting\Sprinkle\IntelliSense\Controller\TestController:sendinBlue');
$app->get('/test/sendinblue-lists', 'UserFrosting\Sprinkle\IntelliSense\Controller\TestController:sendinBlueLists');
$app->get('/test/sendinblue-list-contacts', 'UserFrosting\Sprinkle\IntelliSense\Controller\TestController:sendinBlueListContacts');
$app->get('/test/sendinblue-create-attribute', 'UserFrosting\Sprinkle\IntelliSense\Controller\TestController:sendinBlueCreateAttribite');