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

use UserFrosting\Sprinkle\IntelliSense\Database\Models\Venue;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\PdfModule;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\PdfPreset;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\SiteConfiguration;

/**
 * PDFController Class
 *
 * @package IntelliSense
 * @author Luke Rayner/ElephantWiFi
 * @link http://www.elephantwifi.co.uk
 */
class PDFController extends SimpleController 
{
    public function pagePdfReportGeneration(Request $request, Response $response, $args)
    {
        // Get the alert message stream
        $ms = $this->ci->alerts;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $venue = Venue::where('id', $currentUser->primary_venue_id)->first();

        // Get all the pdf modules available
        $pdf_modules = PdfModule::get();

        // Get the pdf presets for this user
        $pdf_presets = PdfPreset::with('pdf_modules', 'zones')->where('user_id', $currentUser->id)->get();

        return $this->ci->view->render($response, 'pages/pdf_report_generation.html.twig', [
            'venue' => $venue,
            'pdf_modules' => $pdf_modules,
            'pdf_presets' => $pdf_presets
        ]);
    }

    public function savePdfPreset(Request $request, Response $response, $args)
    {
        // Get POST parameters
        $params = $request->getParsedBody();

        /** @var \UserFrosting\Support\Repository\Repository $config */
        $config = $this->ci->config;

        // Get the alert message stream
        $ms = $this->ci->alerts;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        if (isset($params['pdf_modules'])) {
            $pdf_modules = $params['pdf_modules'];
        }
        else {
            $ms->addMessageTranslated("danger", "Please select at least one PDF Module");
            throw new ForbiddenException();
        }

        if (isset($params['zones'])) {
            $zones = $params['zones'];
        }

        // Load validation rules
        $schema = new RequestSchema('schema://requests/pdf-preset-create.yaml');

        // Whitelist and set parameter defaults
        $transformer = new RequestDataTransformer($schema);
        $data = $transformer->transform($params);

        // Validate, and halt on validation errors.  Failed validation attempts do not count towards throttling limit.
        $validator = new ServerSideValidator($schema, $this->ci->translator);
        if (!$validator->validate($data)) {
            $ms->addValidationErrors($validator);

            return $response->withJson([], 400);
        }

        $data['user_id'] = $currentUser->id;

        // Create the zone entry
        $pdf_preset = new PdfPreset($data);
        $pdf_preset->save();

        if (isset($pdf_modules)) {
            $pdf_preset->pdf_modules()->sync($pdf_modules);
        }

        if (isset($zones)) {
            $pdf_preset->zones()->sync($zones);
        }

        // send message back to the user
        $ms->addMessageTranslated('success', 'Captive portal settings have been updated');

        return $response->withJson([], 200);
    }

    /*********************************************
     * Start PDF Template Functions              * 
     ********************************************/

    public function getPdfHeader(Request $request, Response $response, $args)
    {
        // Get the alert message stream
        $ms = $this->ci->alerts;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $venue = Venue::with('venue_tracking')->where('id', $currentUser->primary_venue_id)->first();

        $start_date = Carbon::createFromTimestamp($args['start']/1000)->format('d/m/Y');   
        $end_date = Carbon::createFromTimestamp($args['end']/1000)->format('d/m/Y');

        return $this->ci->view->render($response, 'pages/pdf_templates/header.html.twig', [
            'start_date' => $start_date,
            'end_date' => $end_date,
            'venue' => $venue
        ]);
    }

    public function getPdfSummary(Request $request, Response $response, $args)
    {
        // Get the alert message stream
        $ms = $this->ci->alerts;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $month = Carbon::createFromTimestampMs($args['start'])->format('F');;

        $venue = Venue::where('id', $currentUser->primary_venue_id)->first();

        return $this->ci->view->render($response, 'pages/pdf_templates/summary.html.twig', [
            'start_date' => $args['start'],
            'end_date' => $args['end'],
            'month' => $month,
            'venue' => $venue
        ]);
    }

    public function getPdfVisitorInformation(Request $request, Response $response, $args)
    {
        // Get the alert message stream
        $ms = $this->ci->alerts;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $venue = Venue::where('id', $currentUser->primary_venue_id)->first();

        return $this->ci->view->render($response, 'pages/pdf_templates/visitor_information.html.twig', [
            'start_date' => $args['start'],
            'end_date' => $args['end'],
            'venue' => $venue
        ]);
    }

    public function getPdfDwellSummary(Request $request, Response $response, $args)
    {
        // Get the alert message stream
        $ms = $this->ci->alerts;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $venue = Venue::where('id', $currentUser->primary_venue_id)->first();

        return $this->ci->view->render($response, 'pages/pdf_templates/dwell_summary.html.twig', [
            'start_date' => $args['start'],
            'end_date' => $args['end'],
            'venue' => $venue
        ]);
    }

    public function getPdfDwellDetailedReport(Request $request, Response $response, $args)
    {
        // Get the alert message stream
        $ms = $this->ci->alerts;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $venue = Venue::where('id', $currentUser->primary_venue_id)->first();

        return $this->ci->view->render($response, 'pages/pdf_templates/dwell_detailed_report.html.twig', [
            'start_date' => $args['start'],
            'end_date' => $args['end'],
            'venue' => $venue
        ]);
    }

    public function getPdfAverageTimeOfDay(Request $request, Response $response, $args)
    {
        // Get the alert message stream
        $ms = $this->ci->alerts;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $venue = Venue::where('id', $currentUser->primary_venue_id)->first();

        return $this->ci->view->render($response, 'pages/pdf_templates/average_timeOfDay.html.twig', [
            'start_date' => $args['start'],
            'end_date' => $args['end'],
            'venue' => $venue
        ]);
    }

    public function getPdfWeatherOverview(Request $request, Response $response, $args)
    {
        // Get the alert message stream
        $ms = $this->ci->alerts;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $venue = Venue::where('id', $currentUser->primary_venue_id)->first();

        return $this->ci->view->render($response, 'pages/pdf_templates/weather_overview.html.twig', [
            'start_date' => $args['start'],
            'end_date' => $args['end'],
            'venue' => $venue
        ]);
    }

    public function getPdfZoneSummary(Request $request, Response $response, $args)
    {
        // Get the alert message stream
        $ms = $this->ci->alerts;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $venue = Venue::where('id', $currentUser->primary_venue_id)->first();

        return $this->ci->view->render($response, 'pages/pdf_templates/zone_summary.html.twig', [
            'start_date' => $args['start'],
            'end_date' => $args['end'],
            'venue' => $venue
        ]);
    }

    public function getPdfSingleZoneDetailedReport(Request $request, Response $response, $args)
    {
        // Get the alert message stream
        $ms = $this->ci->alerts;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $venue = Venue::where('id', $currentUser->primary_venue_id)->first();

        $zone = Zone::where('id', $args['zone_id'])->first();

        return $this->ci->view->render($response, 'pages/pdf_templates/single_zone_detailed_report.html.twig', [
            'start_date' => $args['start'],
            'end_date' => $args['end'],
            'venue' => $venue,
            'zone_id' => $args['zone_id'],
            'zone' => $zone
        ]);
    }

    public function getPdfComparisonInformation(Request $request, Response $response, $args)
    {
        // Get the alert message stream
        $ms = $this->ci->alerts;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $venue = Venue::where('id', $currentUser->primary_venue_id)->first();

        return $this->ci->view->render($response, 'pages/pdf_templates/comparison_information.html.twig', [
            'venue' => $venue,
            'month' => $args['month']
        ]);
    }

    public function getPdfNationalStats(Request $request, Response $response, $args)
    {
        // Get the alert message stream
        $ms = $this->ci->alerts;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * get the National Stats tag id from the settings table which
         * we will use to filter on
         */
        $fetch_settings = new SiteConfiguration;
        $tag_filter = $fetch_settings->where('plugin', 'national_stats')
            ->where('name', 'national_stats_tag_id')
            ->first()->value;

        $venue = Venue::where('id', $currentUser->primary_venue_id)->first();

        /**
         * we check whether the current venue has a zone with the correct ag attached to it
         */
        $zone_query = new Zone;
        $current_zone = $zone_query->where('venue_id', $currentUser->primary_venue_id)
            ->whereHas('tags', function($q) use ($tag_filter) {
                  $q->where('tag.id', $tag_filter);
              })
            ->first();

        $selected_zone_id = $current_zone->id;

        return $this->ci->view->render($response, 'pages/pdf_templates/national_stats.html.twig', [
            "selected_zone" => $selected_zone_id,
            'start_date' => $args['start'],
            'end_date' => $args['end'],
            'venue' => $venue
        ]);
    }

    public function getPdfJourneyReport(Request $request, Response $response, $args)
    {
        // Get the alert message stream
        $ms = $this->ci->alerts;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $venue = Venue::where('id', $currentUser->primary_venue_id)->first();

        return $this->ci->view->render($response, 'pages/pdf_templates/journey_report.html.twig', [
            'start_date' => $args['start'],
            'end_date' => $args['end'],
            'venue' => $venue
        ]);
    }

    public function getPdfVisitReport(Request $request, Response $response, $args)
    {
        // Get the alert message stream
        $ms = $this->ci->alerts;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $venue = Venue::where('id', $currentUser->primary_venue_id)->first();

        return $this->ci->view->render($response, 'pages/pdf_templates/visit_report.html.twig', [
            'start_date' => $args['start'],
            'end_date' => $args['end'],
            'venue' => $venue
        ]);
    }

    public function getPdfRepeatVisitors(Request $request, Response $response, $args)
    {
        // Get the alert message stream
        $ms = $this->ci->alerts;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_tracking_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $venue = Venue::where('id', $currentUser->primary_venue_id)->first();

        return $this->ci->view->render($response, 'pages/pdf_templates/repeat_visitor_report.html.twig', [
            'start_date' => $args['start'],
            'end_date' => $args['end'],
            'venue' => $venue
        ]);
    }
}