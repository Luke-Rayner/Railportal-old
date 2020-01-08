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

use UserFrosting\Sprinkle\IntelliSense\Database\Models\SiteConfiguration;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\Zone;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\Category;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\Country;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\Region;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\Area;
use UserFrosting\Sprinkle\IntelliSense\Database\Models\SubCategory;

/**
 * NationalStatsReportController Class
 *
 * @package IntelliSense
 * @author Luke Rayner/ElephantWiFi
 * @link http://www.elephantwifi.co.uk
 */
class NationalStatsReportController extends SimpleController 
{
    /**
     * Render the GeoSense event info report page
     * No AUTH required
     * Request type: GET
     */
    public function pageNationalStatsReport(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;
        
        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_comparison_report')) {
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

        /**
         * we check whether the current venue has a zone with the correct ag attached to it
         */
        $zone_query = new Zone;
        $current_zone = $zone_query->where('venue_id', $currentUser->primary_venue_id)
            ->whereHas('tags', function($q) use ($tag_filter) {
                $q->where('tag.id', $tag_filter);
            })->first();

        if (empty($current_zone)) {
            /**
             * Venue does not have a zone with the correct tag attached
             */
            return $this->ci->view->render($response, 'pages/error_page.html.twig', [
                'main_error' => 'We have encountered an error',
                'error_explanation' => 'This venue does not have a zone with the National Stats tag assigned to it'
            ]);
        } else {
            $selected_zone_id = $current_zone->id;

            /**
             * get all used categories for use in the forms
             */
            $categoryQueryTest = new Category;
            $category_test_collection = $categoryQueryTest->whereHas('venues', function($q) use ($tag_filter) {
                $q->whereHas('zones.tags', function($q) use ($tag_filter) {
                    $q->where('tag.id', $tag_filter);
                });
            })->get();

            /**
             * get all used categories for use in the forms
             */
            $categoryQuery = new Category;
            $category_collection = $categoryQuery->whereHas('venues', function($q) use ($tag_filter) {
                $q->whereHas('zones.tags', function($q) use ($tag_filter) {
                    $q->where('tag.id', $tag_filter);
                });
            })->get();

            /**
             * get all used countries for use in the forms
             */
            $countryQuery = new Country;
            $country_collection = $countryQuery->whereHas('venue', function($q) use ($tag_filter) {
                $q->whereHas('zones.tags', function($q) use ($tag_filter) {
                    $q->where('tag.id', $tag_filter);
                });
            })->get();

            /**
             * get all used regions for use in the forms
             */
            $regionQuery = new Region;
            $region_collection = $regionQuery->whereHas('venue', function($q) use ($tag_filter) {
                $q->whereHas('zones.tags', function($q) use ($tag_filter) {
                    $q->where('tag.id', $tag_filter);
                });
            })->get();

            /**
             * get all used areas for use in the forms
             */
            $areaQuery = new Area;
            $area_collection = $areaQuery->whereHas('venue', function($q) use ($tag_filter) {
                $q->whereHas('zones.tags', function($q) use ($tag_filter) {
                    $q->where('tag.id', $tag_filter);
                });
            })->with('region')->get();

            /**
             * get all used sub_categories
             */
            $sub_categoryQuery = new SubCategory;
            $sub_category_collection = $sub_categoryQuery->whereHas('venues', function($q) use ($tag_filter) {
                $q->whereHas('zones.tags', function($q) use ($tag_filter) {
                    $q->where('tag.id', $tag_filter);
                });
            })->get();

            return $this->ci->view->render($response, 'pages/geo-sense/national_stats_report.html.twig', [
                "categories"     => $category_collection->values()->toArray(),
                "sub_categories" => $sub_category_collection->values()->toArray(),
                "countries"      => $country_collection->values()->toArray(),
                "regions"        => $region_collection->values()->toArray(),
                "areas"          => $area_collection->values()->toArray(),
                "selected_zone"  => $selected_zone_id,
            ]);
        }
    }
}