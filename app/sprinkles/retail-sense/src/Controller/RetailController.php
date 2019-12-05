<?php

namespace UserFrosting\Sprinkle\RetailSense\Controller;

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

use UserFrosting\Sprinkle\RetailSense\Database\Models\RetailWeeklyAverageStats;
use UserFrosting\Sprinkle\RetailSense\Database\Models\RetailMonthlyStats;
use UserFrosting\Sprinkle\RetailSense\Database\Models\RetailOnlineWeeklyAverageStats;
use UserFrosting\Sprinkle\RetailSense\Database\Models\RetailOnlinePenetrationStats;

/**
 * RetailController Class
 *
 * @package IntelliSense
 * @author Luke Rayner/ElephantWiFi
 * @link http://www.elephantwifi.co.uk
 */
class RetailController extends SimpleController 
{
    public function pageUploadRetailStats(Request $request, Response $response, $args)
    {
        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_site_admin')) {
            throw new NotFoundException($request, $response);
        }

        $venue = Venue::where('id', $currentUser->primary_venue_id)->first();

        return $this->ci->view->render($response, 'pages/retail_sense/admin/retail_stats_upload.html.twig', [
            'venue' => $venue
        ]);
    }

    public function uploadRetailStats(Request $request, Response $response, $args)
    {
        // Get the alert message stream
        $ms = $this->ci->alerts;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_site_admin')) {
            throw new NotFoundException($request, $response);
        }

        /**
         * Check if the table is populated
         */
        $retailWeeklyAverageStatsData = RetailWeeklyAverageStats::orderBy('day_epoch', 'DESC')->get();
        $retailMonthlyStatsData = RetailMonthlyStats::orderBy('day_epoch', 'DESC')->get();
        $retailOnlineWeeklyAverageStatsData = RetailOnlineWeeklyAverageStats::orderBy('day_epoch', 'DESC')->get();
        $retailOnlinePenetrationStatsData = RetailOnlinePenetrationStats::orderBy('day_epoch', 'DESC')->get();

        $file = fopen($_FILES['qqfile']['tmp_name'], "r");

        $imported_data_array = [];
        $i = 0;
        while (($file_data = fgetcsv($file)) !== FALSE) {
            $num = count($file_data);

            for ($c=0; $c < $num; $c++) {
                $imported_data_array[$i][] = $file_data[$c];
            }
            $i++;
        }
        fclose($file);

        /**
         * Check while file has been uploaded
         */
        $retail_upload_file_type = $imported_data_array[0][0];

        var_dump($retail_upload_file_type);
        $retail_upload_file_type = str_replace("\xEF\xBB\xBF",'',$retail_upload_file_type); 
        var_dump($retail_upload_file_type);

        // Set the used date array to prevent duplicates
        $used_dates = [];

        foreach($imported_data_array as $data) {

            $valid_dates = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];
            
            foreach ($valid_dates as $valid_date) {
                // File is the weekly average data
                if ($retail_upload_file_type == 'VALNSAWD') {
                    // Check if this row contains valid data
                    if (strpos(strtolower($data[1]), $valid_date) !== FALSE) {

                        // Check if the table contains data
                        if (count($retailWeeklyAverageStatsData) <= 0) {

                            $day_epoch = new Carbon($data[1], 'Europe/London');
                            $day_epoch = $day_epoch->timestamp;

                            $retail_weekly_average_stats = new RetailWeeklyAverageStats();
                            $retail_weekly_average_stats->day_epoch = $day_epoch;

                            $retail_weekly_average_stats->date                                                     = $data[1];
                            $retail_weekly_average_stats->all_retailing_including_automotive_fuel_all_businesses   = intval(str_replace(",", "", str_replace(" ", "", $data[2]))) * 1000;
                            $retail_weekly_average_stats->all_retailing_including_automotive_fuel_large_businesses = intval(str_replace(",", "", str_replace(" ", "", $data[3]))) * 1000;
                            $retail_weekly_average_stats->all_retailing_including_automotive_fuel_small_businesses = intval(str_replace(",", "", str_replace(" ", "", $data[4]))) * 1000;

                            $retail_weekly_average_stats->all_retailing_excluding_automotive_fuel_all_businesses   = intval(str_replace(",", "", str_replace(" ", "", $data[6]))) * 1000;
                            $retail_weekly_average_stats->all_retailing_excluding_automotive_fuel_large_businesses = intval(str_replace(",", "", str_replace(" ", "", $data[7]))) * 1000;
                            $retail_weekly_average_stats->all_retailing_excluding_automotive_fuel_small_businesses = intval(str_replace(",", "", str_replace(" ", "", $data[8]))) * 1000;

                            $retail_weekly_average_stats->predominantly_food_stores_total_all_businesses           = intval(str_replace(",", "", str_replace(" ", "", $data[10]))) * 1000;
                            $retail_weekly_average_stats->predominantly_food_stores_total_large_businesses         = intval(str_replace(",", "", str_replace(" ", "", $data[11]))) * 1000;
                            $retail_weekly_average_stats->predominantly_food_stores_total_small_businesses         = intval(str_replace(",", "", str_replace(" ", "", $data[12]))) * 1000;

                            $retail_weekly_average_stats->non_specialised_food_stores_all_businesses               = intval(str_replace(",", "", str_replace(" ", "", $data[13]))) * 1000;
                            $retail_weekly_average_stats->non_specialised_food_stores_large_businesses             = intval(str_replace(",", "", str_replace(" ", "", $data[14]))) * 1000;
                            $retail_weekly_average_stats->non_specialised_food_stores_small_businesses             = intval(str_replace(",", "", str_replace(" ", "", $data[15]))) * 1000;

                            $retail_weekly_average_stats->specialist_food_stores                                   = intval(str_replace(",", "", str_replace(" ", "", $data[16]))) * 1000;
                            $retail_weekly_average_stats->alcoholic_drinks_other_beverages_and_tobacco             = intval(str_replace(",", "", str_replace(" ", "", $data[17]))) * 1000;

                            $retail_weekly_average_stats->predominantly_non_food_stores_total_all_businesses       = intval(str_replace(",", "", str_replace(" ", "", $data[19]))) * 1000;
                            $retail_weekly_average_stats->predominantly_non_food_stores_total_large_businesses     = intval(str_replace(",", "", str_replace(" ", "", $data[20]))) * 1000;
                            $retail_weekly_average_stats->predominantly_non_food_stores_total_small_businesses     = intval(str_replace(",", "", str_replace(" ", "", $data[21]))) * 1000;

                            $retail_weekly_average_stats->non_specialised_non_food_stores_all_businesses           = intval(str_replace(",", "", str_replace(" ", "", $data[22]))) * 1000;
                            $retail_weekly_average_stats->non_specialised_non_food_stores_large_businesses         = intval(str_replace(",", "", str_replace(" ", "", $data[23]))) * 1000;
                            $retail_weekly_average_stats->non_specialised_non_food_stores_small_businesses         = intval(str_replace(",", "", str_replace(" ", "", $data[24]))) * 1000;

                            $retail_weekly_average_stats->textile_clothing_footwear_and_leather_all_businesses     = intval(str_replace(",", "", str_replace(" ", "", $data[25]))) * 1000;
                            $retail_weekly_average_stats->textile_clothing_footwear_and_leather_large_businesses   = intval(str_replace(",", "", str_replace(" ", "", $data[26]))) * 1000;
                            $retail_weekly_average_stats->textile_clothing_footwear_and_leather_small_businesses   = intval(str_replace(",", "", str_replace(" ", "", $data[27]))) * 1000;

                            $retail_weekly_average_stats->textiles                                                 = intval(str_replace(",", "", str_replace(" ", "", $data[28]))) * 1000;
                            $retail_weekly_average_stats->clothing_all_businesses                                  = intval(str_replace(",", "", str_replace(" ", "", $data[29]))) * 1000;
                            $retail_weekly_average_stats->clothing_large_businesses                                = intval(str_replace(",", "", str_replace(" ", "", $data[30]))) * 1000;
                            $retail_weekly_average_stats->clothing_small_businesses                                = intval(str_replace(",", "", str_replace(" ", "", $data[31]))) * 1000;

                            $retail_weekly_average_stats->footwear_and_leather_goods                               = intval(str_replace(",", "", str_replace(" ", "", $data[32]))) * 1000;
                            $retail_weekly_average_stats->household_goods_stores_all_businesses                    = intval(str_replace(",", "", str_replace(" ", "", $data[33]))) * 1000;
                            $retail_weekly_average_stats->household_goods_stores_large_businesses                  = intval(str_replace(",", "", str_replace(" ", "", $data[34]))) * 1000;
                            $retail_weekly_average_stats->household_goods_stores_small_businesses                  = intval(str_replace(",", "", str_replace(" ", "", $data[35]))) * 1000;

                            $retail_weekly_average_stats->furniture_lighting_etc                                   = intval(str_replace(",", "", str_replace(" ", "", $data[36]))) * 1000;
                            $retail_weekly_average_stats->electrical_household_appliances                          = intval(str_replace(",", "", str_replace(" ", "", $data[37]))) * 1000;
                            $retail_weekly_average_stats->hardware_paints_and_glass                                = intval(str_replace(",", "", str_replace(" ", "", $data[38]))) * 1000;
                            $retail_weekly_average_stats->audio_and_video_and_music                                = intval(str_replace(",", "", str_replace(" ", "", $data[39]))) * 1000;

                            $retail_weekly_average_stats->other_non_food_stores_all_businesses                     = intval(str_replace(",", "", str_replace(" ", "", $data[40]))) * 1000;
                            $retail_weekly_average_stats->other_non_food_stores_large_businesses                   = intval(str_replace(",", "", str_replace(" ", "", $data[41]))) * 1000;
                            $retail_weekly_average_stats->other_non_food_stores_small_businesses                   = intval(str_replace(",", "", str_replace(" ", "", $data[42]))) * 1000;

                            $retail_weekly_average_stats->pharmaceutical_medical_cosmetic_and_toilet_goods         = intval(str_replace(",", "", str_replace(" ", "", $data[43]))) * 1000;
                            $retail_weekly_average_stats->books_newspapers_and_periodicals                         = intval(str_replace(",", "", str_replace(" ", "", $data[44]))) * 1000;
                            $retail_weekly_average_stats->floor_coverings                                          = intval(str_replace(",", "", str_replace(" ", "", $data[45]))) * 1000;
                            $retail_weekly_average_stats->computers_and_telecomms_equipment                        = intval(str_replace(",", "", str_replace(" ", "", $data[46]))) * 1000;
                            $retail_weekly_average_stats->specialised_stores_nec                                   = intval(str_replace(",", "", str_replace(" ", "", $data[47]))) * 1000;

                            $retail_weekly_average_stats->non_store_retail_all_retailing                           = intval(str_replace(",", "", str_replace(" ", "", $data[49]))) * 1000;
                            $retail_weekly_average_stats->non_store_retail_large_businesses                        = intval(str_replace(",", "", str_replace(" ", "", $data[50]))) * 1000;
                            $retail_weekly_average_stats->non_store_retail_small_businesses                        = intval(str_replace(",", "", str_replace(" ", "", $data[51]))) * 1000;
                            $retail_weekly_average_stats->non_store_retail_mail_order_houses                       = intval(str_replace(",", "", str_replace(" ", "", $data[52]))) * 1000;
                            $retail_weekly_average_stats->non_store_retail_excluding_mail_order                    = intval(str_replace(",", "", str_replace(" ", "", $data[53]))) * 1000;

                            $retail_weekly_average_stats->automotive_fuel                                          = intval(str_replace(",", "", str_replace(" ", "", $data[55]))) * 1000;
                            $retail_weekly_average_stats->save();
                        }
                        else {
                            $lastestResult = $retailWeeklyAverageStatsData[0]['date'];

                            $latest_day_epoch = new Carbon($lastestResult, 'Europe/London');
                            $latest_day_epoch = $latest_day_epoch->timestamp;

                            $day_epoch = new Carbon($data[1], 'Europe/London');
                            $day_epoch = $day_epoch->timestamp;

                            if ($day_epoch > $latest_day_epoch) {

                                $retail_weekly_average_stats = new RetailWeeklyAverageStats();
                                $retail_weekly_average_stats->day_epoch = $day_epoch;

                                $retail_weekly_average_stats->date                                                     = $data[1];
                                $retail_weekly_average_stats->all_retailing_including_automotive_fuel_all_businesses   = intval(str_replace(",", "", str_replace(" ", "", $data[2]))) * 1000;
                                $retail_weekly_average_stats->all_retailing_including_automotive_fuel_large_businesses = intval(str_replace(",", "", str_replace(" ", "", $data[3]))) * 1000;
                                $retail_weekly_average_stats->all_retailing_including_automotive_fuel_small_businesses = intval(str_replace(",", "", str_replace(" ", "", $data[4]))) * 1000;

                                $retail_weekly_average_stats->all_retailing_excluding_automotive_fuel_all_businesses   = intval(str_replace(",", "", str_replace(" ", "", $data[6]))) * 1000;
                                $retail_weekly_average_stats->all_retailing_excluding_automotive_fuel_large_businesses = intval(str_replace(",", "", str_replace(" ", "", $data[7]))) * 1000;
                                $retail_weekly_average_stats->all_retailing_excluding_automotive_fuel_small_businesses = intval(str_replace(",", "", str_replace(" ", "", $data[8]))) * 1000;

                                $retail_weekly_average_stats->predominantly_food_stores_total_all_businesses           = intval(str_replace(",", "", str_replace(" ", "", $data[10]))) * 1000;
                                $retail_weekly_average_stats->predominantly_food_stores_total_large_businesses         = intval(str_replace(",", "", str_replace(" ", "", $data[11]))) * 1000;
                                $retail_weekly_average_stats->predominantly_food_stores_total_small_businesses         = intval(str_replace(",", "", str_replace(" ", "", $data[12]))) * 1000;

                                $retail_weekly_average_stats->non_specialised_food_stores_all_businesses               = intval(str_replace(",", "", str_replace(" ", "", $data[13]))) * 1000;
                                $retail_weekly_average_stats->non_specialised_food_stores_large_businesses             = intval(str_replace(",", "", str_replace(" ", "", $data[14]))) * 1000;
                                $retail_weekly_average_stats->non_specialised_food_stores_small_businesses             = intval(str_replace(",", "", str_replace(" ", "", $data[15]))) * 1000;

                                $retail_weekly_average_stats->specialist_food_stores                                   = intval(str_replace(",", "", str_replace(" ", "", $data[16]))) * 1000;
                                $retail_weekly_average_stats->alcoholic_drinks_other_beverages_and_tobacco             = intval(str_replace(",", "", str_replace(" ", "", $data[17]))) * 1000;

                                $retail_weekly_average_stats->predominantly_non_food_stores_total_all_businesses       = intval(str_replace(",", "", str_replace(" ", "", $data[19]))) * 1000;
                                $retail_weekly_average_stats->predominantly_non_food_stores_total_large_businesses     = intval(str_replace(",", "", str_replace(" ", "", $data[20]))) * 1000;
                                $retail_weekly_average_stats->predominantly_non_food_stores_total_small_businesses     = intval(str_replace(",", "", str_replace(" ", "", $data[21]))) * 1000;

                                $retail_weekly_average_stats->non_specialised_non_food_stores_all_businesses           = intval(str_replace(",", "", str_replace(" ", "", $data[22]))) * 1000;
                                $retail_weekly_average_stats->non_specialised_non_food_stores_large_businesses         = intval(str_replace(",", "", str_replace(" ", "", $data[23]))) * 1000;
                                $retail_weekly_average_stats->non_specialised_non_food_stores_small_businesses         = intval(str_replace(",", "", str_replace(" ", "", $data[24]))) * 1000;

                                $retail_weekly_average_stats->textile_clothing_footwear_and_leather_all_businesses     = intval(str_replace(",", "", str_replace(" ", "", $data[25]))) * 1000;
                                $retail_weekly_average_stats->textile_clothing_footwear_and_leather_large_businesses   = intval(str_replace(",", "", str_replace(" ", "", $data[26]))) * 1000;
                                $retail_weekly_average_stats->textile_clothing_footwear_and_leather_small_businesses   = intval(str_replace(",", "", str_replace(" ", "", $data[27]))) * 1000;

                                $retail_weekly_average_stats->textiles                                                 = intval(str_replace(",", "", str_replace(" ", "", $data[28]))) * 1000;
                                $retail_weekly_average_stats->clothing_all_businesses                                  = intval(str_replace(",", "", str_replace(" ", "", $data[29]))) * 1000;
                                $retail_weekly_average_stats->clothing_large_businesses                                = intval(str_replace(",", "", str_replace(" ", "", $data[30]))) * 1000;
                                $retail_weekly_average_stats->clothing_small_businesses                                = intval(str_replace(",", "", str_replace(" ", "", $data[31]))) * 1000;

                                $retail_weekly_average_stats->footwear_and_leather_goods                               = intval(str_replace(",", "", str_replace(" ", "", $data[32]))) * 1000;
                                $retail_weekly_average_stats->household_goods_stores_all_businesses                    = intval(str_replace(",", "", str_replace(" ", "", $data[33]))) * 1000;
                                $retail_weekly_average_stats->household_goods_stores_large_businesses                  = intval(str_replace(",", "", str_replace(" ", "", $data[34]))) * 1000;
                                $retail_weekly_average_stats->household_goods_stores_small_businesses                  = intval(str_replace(",", "", str_replace(" ", "", $data[35]))) * 1000;

                                $retail_weekly_average_stats->furniture_lighting_etc                                   = intval(str_replace(",", "", str_replace(" ", "", $data[36]))) * 1000;
                                $retail_weekly_average_stats->electrical_household_appliances                          = intval(str_replace(",", "", str_replace(" ", "", $data[37]))) * 1000;
                                $retail_weekly_average_stats->hardware_paints_and_glass                                = intval(str_replace(",", "", str_replace(" ", "", $data[38]))) * 1000;
                                $retail_weekly_average_stats->audio_and_video_and_music                                = intval(str_replace(",", "", str_replace(" ", "", $data[39]))) * 1000;

                                $retail_weekly_average_stats->other_non_food_stores_all_businesses                     = intval(str_replace(",", "", str_replace(" ", "", $data[40]))) * 1000;
                                $retail_weekly_average_stats->other_non_food_stores_large_businesses                   = intval(str_replace(",", "", str_replace(" ", "", $data[41]))) * 1000;
                                $retail_weekly_average_stats->other_non_food_stores_small_businesses                   = intval(str_replace(",", "", str_replace(" ", "", $data[42]))) * 1000;

                                $retail_weekly_average_stats->pharmaceutical_medical_cosmetic_and_toilet_goods         = intval(str_replace(",", "", str_replace(" ", "", $data[43]))) * 1000;
                                $retail_weekly_average_stats->books_newspapers_and_periodicals                         = intval(str_replace(",", "", str_replace(" ", "", $data[44]))) * 1000;
                                $retail_weekly_average_stats->floor_coverings                                          = intval(str_replace(",", "", str_replace(" ", "", $data[45]))) * 1000;
                                $retail_weekly_average_stats->computers_and_telecomms_equipment                        = intval(str_replace(",", "", str_replace(" ", "", $data[46]))) * 1000;
                                $retail_weekly_average_stats->specialised_stores_nec                                   = intval(str_replace(",", "", str_replace(" ", "", $data[47]))) * 1000;

                                $retail_weekly_average_stats->non_store_retail_all_retailing                           = intval(str_replace(",", "", str_replace(" ", "", $data[49]))) * 1000;
                                $retail_weekly_average_stats->non_store_retail_large_businesses                        = intval(str_replace(",", "", str_replace(" ", "", $data[50]))) * 1000;
                                $retail_weekly_average_stats->non_store_retail_small_businesses                        = intval(str_replace(",", "", str_replace(" ", "", $data[51]))) * 1000;
                                $retail_weekly_average_stats->non_store_retail_mail_order_houses                       = intval(str_replace(",", "", str_replace(" ", "", $data[52]))) * 1000;
                                $retail_weekly_average_stats->non_store_retail_excluding_mail_order                    = intval(str_replace(",", "", str_replace(" ", "", $data[53]))) * 1000;

                                $retail_weekly_average_stats->automotive_fuel                                          = intval(str_replace(",", "", str_replace(" ", "", $data[55]))) * 1000;
                                $retail_weekly_average_stats->save();
                            }
                        }
                    }
                }

                // File is the monthly data
                if ($retail_upload_file_type == 'ValNSATD') {
                    // Check if this row contains valid data
                    if (strpos(strtolower($data[0]), $valid_date) !== FALSE) {

                        // Check if the table contains data
                        if (count($retailMonthlyStatsData) <= 0) {

                            $day_epoch = new Carbon($data[0], 'Europe/London');
                            $day_epoch = $day_epoch->timestamp;

                            $retail_monthly_stats = new RetailMonthlyStats();
                            $retail_monthly_stats->day_epoch = $day_epoch;

                            $retail_monthly_stats->date                                                     = $data[0];
                            $retail_monthly_stats->all_retailing_including_automotive_fuel_all_businesses   = intval(str_replace(",", "", str_replace(" ", "", $data[1]))) * 1000;
                            $retail_monthly_stats->all_retailing_including_automotive_fuel_large_businesses = intval(str_replace(",", "", str_replace(" ", "", $data[2]))) * 1000;
                            $retail_monthly_stats->all_retailing_including_automotive_fuel_small_businesses = intval(str_replace(",", "", str_replace(" ", "", $data[3]))) * 1000;

                            $retail_monthly_stats->all_retailing_excluding_automotive_fuel_all_businesses   = intval(str_replace(",", "", str_replace(" ", "", $data[5]))) * 1000;
                            $retail_monthly_stats->all_retailing_excluding_automotive_fuel_large_businesses = intval(str_replace(",", "", str_replace(" ", "", $data[6]))) * 1000;
                            $retail_monthly_stats->all_retailing_excluding_automotive_fuel_small_businesses = intval(str_replace(",", "", str_replace(" ", "", $data[7]))) * 1000;

                            $retail_monthly_stats->predominantly_food_stores_total_all_businesses           = intval(str_replace(",", "", str_replace(" ", "", $data[9]))) * 1000;
                            $retail_monthly_stats->predominantly_food_stores_total_large_businesses         = intval(str_replace(",", "", str_replace(" ", "", $data[10]))) * 1000;
                            $retail_monthly_stats->predominantly_food_stores_total_small_businesses         = intval(str_replace(",", "", str_replace(" ", "", $data[11]))) * 1000;

                            $retail_monthly_stats->non_specialised_food_stores_all_businesses               = intval(str_replace(",", "", str_replace(" ", "", $data[12]))) * 1000;
                            $retail_monthly_stats->non_specialised_food_stores_large_businesses             = intval(str_replace(",", "", str_replace(" ", "", $data[13]))) * 1000;
                            $retail_monthly_stats->non_specialised_food_stores_small_businesses             = intval(str_replace(",", "", str_replace(" ", "", $data[14]))) * 1000;

                            $retail_monthly_stats->specialist_food_stores                                   = intval(str_replace(",", "", str_replace(" ", "", $data[15]))) * 1000;
                            $retail_monthly_stats->alcoholic_drinks_other_beverages_and_tobacco             = intval(str_replace(",", "", str_replace(" ", "", $data[16]))) * 1000;

                            $retail_monthly_stats->predominantly_non_food_stores_total_all_businesses       = intval(str_replace(",", "", str_replace(" ", "", $data[18]))) * 1000;
                            $retail_monthly_stats->predominantly_non_food_stores_total_large_businesses     = intval(str_replace(",", "", str_replace(" ", "", $data[19]))) * 1000;
                            $retail_monthly_stats->predominantly_non_food_stores_total_small_businesses     = intval(str_replace(",", "", str_replace(" ", "", $data[20]))) * 1000;

                            $retail_monthly_stats->non_specialised_non_food_stores_all_businesses           = intval(str_replace(",", "", str_replace(" ", "", $data[21]))) * 1000;
                            $retail_monthly_stats->non_specialised_non_food_stores_large_businesses         = intval(str_replace(",", "", str_replace(" ", "", $data[22]))) * 1000;
                            $retail_monthly_stats->non_specialised_non_food_stores_small_businesses         = intval(str_replace(",", "", str_replace(" ", "", $data[23]))) * 1000;

                            $retail_monthly_stats->textile_clothing_footwear_and_leather_all_businesses     = intval(str_replace(",", "", str_replace(" ", "", $data[24]))) * 1000;
                            $retail_monthly_stats->textile_clothing_footwear_and_leather_large_businesses   = intval(str_replace(",", "", str_replace(" ", "", $data[25]))) * 1000;
                            $retail_monthly_stats->textile_clothing_footwear_and_leather_small_businesses   = intval(str_replace(",", "", str_replace(" ", "", $data[26]))) * 1000;

                            $retail_monthly_stats->textiles                                                 = intval(str_replace(",", "", str_replace(" ", "", $data[27]))) * 1000;
                            $retail_monthly_stats->clothing_all_businesses                                  = intval(str_replace(",", "", str_replace(" ", "", $data[28]))) * 1000;
                            $retail_monthly_stats->clothing_large_businesses                                = intval(str_replace(",", "", str_replace(" ", "", $data[29]))) * 1000;
                            $retail_monthly_stats->clothing_small_businesses                                = intval(str_replace(",", "", str_replace(" ", "", $data[30]))) * 1000;

                            $retail_monthly_stats->footwear_and_leather_goods                               = intval(str_replace(",", "", str_replace(" ", "", $data[31]))) * 1000;
                            $retail_monthly_stats->household_goods_stores_all_businesses                    = intval(str_replace(",", "", str_replace(" ", "", $data[32]))) * 1000;
                            $retail_monthly_stats->household_goods_stores_large_businesses                  = intval(str_replace(",", "", str_replace(" ", "", $data[33]))) * 1000;
                            $retail_monthly_stats->household_goods_stores_small_businesses                  = intval(str_replace(",", "", str_replace(" ", "", $data[34]))) * 1000;

                            $retail_monthly_stats->furniture_lighting_etc                                   = intval(str_replace(",", "", str_replace(" ", "", $data[35]))) * 1000;
                            $retail_monthly_stats->electrical_household_appliances                          = intval(str_replace(",", "", str_replace(" ", "", $data[36]))) * 1000;
                            $retail_monthly_stats->hardware_paints_and_glass                                = intval(str_replace(",", "", str_replace(" ", "", $data[37]))) * 1000;
                            $retail_monthly_stats->audio_and_video_and_music                                = intval(str_replace(",", "", str_replace(" ", "", $data[38]))) * 1000;

                            $retail_monthly_stats->other_non_food_stores_all_businesses                     = intval(str_replace(",", "", str_replace(" ", "", $data[39]))) * 1000;
                            $retail_monthly_stats->other_non_food_stores_large_businesses                   = intval(str_replace(",", "", str_replace(" ", "", $data[40]))) * 1000;
                            $retail_monthly_stats->other_non_food_stores_small_businesses                   = intval(str_replace(",", "", str_replace(" ", "", $data[41]))) * 1000;

                            $retail_monthly_stats->pharmaceutical_medical_cosmetic_and_toilet_goods         = intval(str_replace(",", "", str_replace(" ", "", $data[42]))) * 1000;
                            $retail_monthly_stats->books_newspapers_and_periodicals                         = intval(str_replace(",", "", str_replace(" ", "", $data[43]))) * 1000;
                            $retail_monthly_stats->floor_coverings                                          = intval(str_replace(",", "", str_replace(" ", "", $data[44]))) * 1000;
                            $retail_monthly_stats->computers_and_telecomms_equipment                        = intval(str_replace(",", "", str_replace(" ", "", $data[45]))) * 1000;
                            $retail_monthly_stats->specialised_stores_nec                                   = intval(str_replace(",", "", str_replace(" ", "", $data[46]))) * 1000;

                            $retail_monthly_stats->non_store_retail_all_retailing                           = intval(str_replace(",", "", str_replace(" ", "", $data[48]))) * 1000;
                            $retail_monthly_stats->non_store_retail_large_businesses                        = intval(str_replace(",", "", str_replace(" ", "", $data[49]))) * 1000;
                            $retail_monthly_stats->non_store_retail_small_businesses                        = intval(str_replace(",", "", str_replace(" ", "", $data[50]))) * 1000;
                            $retail_monthly_stats->non_store_retail_mail_order_houses                       = intval(str_replace(",", "", str_replace(" ", "", $data[51]))) * 1000;
                            $retail_monthly_stats->non_store_retail_excluding_mail_order                    = intval(str_replace(",", "", str_replace(" ", "", $data[52]))) * 1000;

                            $retail_monthly_stats->automotive_fuel                                          = intval(str_replace(",", "", str_replace(" ", "", $data[54]))) * 1000;
                            $retail_monthly_stats->save();
                        }
                        else {
                            $lastestResult = $retailMonthlyStatsData[0]['date'];

                            $latest_day_epoch = new Carbon($lastestResult, 'Europe/London');
                            $latest_day_epoch = $latest_day_epoch->timestamp;

                            $day_epoch = new Carbon($data[0], 'Europe/London');
                            $day_epoch = $day_epoch->timestamp;

                            if ($day_epoch > $latest_day_epoch) {

                                $retail_monthly_stats = new RetailMonthlyStats();
                                $retail_monthly_stats->day_epoch = $day_epoch;

                                $retail_monthly_stats->date                                                     = $data[0];
                                $retail_monthly_stats->all_retailing_including_automotive_fuel_all_businesses   = intval(str_replace(",", "", str_replace(" ", "", $data[1]))) * 1000;
                                $retail_monthly_stats->all_retailing_including_automotive_fuel_large_businesses = intval(str_replace(",", "", str_replace(" ", "", $data[2]))) * 1000;
                                $retail_monthly_stats->all_retailing_including_automotive_fuel_small_businesses = intval(str_replace(",", "", str_replace(" ", "", $data[3]))) * 1000;

                                $retail_monthly_stats->all_retailing_excluding_automotive_fuel_all_businesses   = intval(str_replace(",", "", str_replace(" ", "", $data[5]))) * 1000;
                                $retail_monthly_stats->all_retailing_excluding_automotive_fuel_large_businesses = intval(str_replace(",", "", str_replace(" ", "", $data[6]))) * 1000;
                                $retail_monthly_stats->all_retailing_excluding_automotive_fuel_small_businesses = intval(str_replace(",", "", str_replace(" ", "", $data[7]))) * 1000;

                                $retail_monthly_stats->predominantly_food_stores_total_all_businesses           = intval(str_replace(",", "", str_replace(" ", "", $data[9]))) * 1000;
                                $retail_monthly_stats->predominantly_food_stores_total_large_businesses         = intval(str_replace(",", "", str_replace(" ", "", $data[10]))) * 1000;
                                $retail_monthly_stats->predominantly_food_stores_total_small_businesses         = intval(str_replace(",", "", str_replace(" ", "", $data[11]))) * 1000;

                                $retail_monthly_stats->non_specialised_food_stores_all_businesses               = intval(str_replace(",", "", str_replace(" ", "", $data[12]))) * 1000;
                                $retail_monthly_stats->non_specialised_food_stores_large_businesses             = intval(str_replace(",", "", str_replace(" ", "", $data[13]))) * 1000;
                                $retail_monthly_stats->non_specialised_food_stores_small_businesses             = intval(str_replace(",", "", str_replace(" ", "", $data[14]))) * 1000;

                                $retail_monthly_stats->specialist_food_stores                                   = intval(str_replace(",", "", str_replace(" ", "", $data[15]))) * 1000;
                                $retail_monthly_stats->alcoholic_drinks_other_beverages_and_tobacco             = intval(str_replace(",", "", str_replace(" ", "", $data[16]))) * 1000;

                                $retail_monthly_stats->predominantly_non_food_stores_total_all_businesses       = intval(str_replace(",", "", str_replace(" ", "", $data[18]))) * 1000;
                                $retail_monthly_stats->predominantly_non_food_stores_total_large_businesses     = intval(str_replace(",", "", str_replace(" ", "", $data[19]))) * 1000;
                                $retail_monthly_stats->predominantly_non_food_stores_total_small_businesses     = intval(str_replace(",", "", str_replace(" ", "", $data[20]))) * 1000;

                                $retail_monthly_stats->non_specialised_non_food_stores_all_businesses           = intval(str_replace(",", "", str_replace(" ", "", $data[21]))) * 1000;
                                $retail_monthly_stats->non_specialised_non_food_stores_large_businesses         = intval(str_replace(",", "", str_replace(" ", "", $data[22]))) * 1000;
                                $retail_monthly_stats->non_specialised_non_food_stores_small_businesses         = intval(str_replace(",", "", str_replace(" ", "", $data[23]))) * 1000;

                                $retail_monthly_stats->textile_clothing_footwear_and_leather_all_businesses     = intval(str_replace(",", "", str_replace(" ", "", $data[24]))) * 1000;
                                $retail_monthly_stats->textile_clothing_footwear_and_leather_large_businesses   = intval(str_replace(",", "", str_replace(" ", "", $data[25]))) * 1000;
                                $retail_monthly_stats->textile_clothing_footwear_and_leather_small_businesses   = intval(str_replace(",", "", str_replace(" ", "", $data[26]))) * 1000;

                                $retail_monthly_stats->textiles                                                 = intval(str_replace(",", "", str_replace(" ", "", $data[27]))) * 1000;
                                $retail_monthly_stats->clothing_all_businesses                                  = intval(str_replace(",", "", str_replace(" ", "", $data[28]))) * 1000;
                                $retail_monthly_stats->clothing_large_businesses                                = intval(str_replace(",", "", str_replace(" ", "", $data[29]))) * 1000;
                                $retail_monthly_stats->clothing_small_businesses                                = intval(str_replace(",", "", str_replace(" ", "", $data[30]))) * 1000;

                                $retail_monthly_stats->footwear_and_leather_goods                               = intval(str_replace(",", "", str_replace(" ", "", $data[31]))) * 1000;
                                $retail_monthly_stats->household_goods_stores_all_businesses                    = intval(str_replace(",", "", str_replace(" ", "", $data[32]))) * 1000;
                                $retail_monthly_stats->household_goods_stores_large_businesses                  = intval(str_replace(",", "", str_replace(" ", "", $data[33]))) * 1000;
                                $retail_monthly_stats->household_goods_stores_small_businesses                  = intval(str_replace(",", "", str_replace(" ", "", $data[34]))) * 1000;

                                $retail_monthly_stats->furniture_lighting_etc                                   = intval(str_replace(",", "", str_replace(" ", "", $data[35]))) * 1000;
                                $retail_monthly_stats->electrical_household_appliances                          = intval(str_replace(",", "", str_replace(" ", "", $data[36]))) * 1000;
                                $retail_monthly_stats->hardware_paints_and_glass                                = intval(str_replace(",", "", str_replace(" ", "", $data[37]))) * 1000;
                                $retail_monthly_stats->audio_and_video_and_music                                = intval(str_replace(",", "", str_replace(" ", "", $data[38]))) * 1000;

                                $retail_monthly_stats->other_non_food_stores_all_businesses                     = intval(str_replace(",", "", str_replace(" ", "", $data[39]))) * 1000;
                                $retail_monthly_stats->other_non_food_stores_large_businesses                   = intval(str_replace(",", "", str_replace(" ", "", $data[40]))) * 1000;
                                $retail_monthly_stats->other_non_food_stores_small_businesses                   = intval(str_replace(",", "", str_replace(" ", "", $data[41]))) * 1000;

                                $retail_monthly_stats->pharmaceutical_medical_cosmetic_and_toilet_goods         = intval(str_replace(",", "", str_replace(" ", "", $data[42]))) * 1000;
                                $retail_monthly_stats->books_newspapers_and_periodicals                         = intval(str_replace(",", "", str_replace(" ", "", $data[43]))) * 1000;
                                $retail_monthly_stats->floor_coverings                                          = intval(str_replace(",", "", str_replace(" ", "", $data[44]))) * 1000;
                                $retail_monthly_stats->computers_and_telecomms_equipment                        = intval(str_replace(",", "", str_replace(" ", "", $data[45]))) * 1000;
                                $retail_monthly_stats->specialised_stores_nec                                   = intval(str_replace(",", "", str_replace(" ", "", $data[46]))) * 1000;

                                $retail_monthly_stats->non_store_retail_all_retailing                           = intval(str_replace(",", "", str_replace(" ", "", $data[48]))) * 1000;
                                $retail_monthly_stats->non_store_retail_large_businesses                        = intval(str_replace(",", "", str_replace(" ", "", $data[49]))) * 1000;
                                $retail_monthly_stats->non_store_retail_small_businesses                        = intval(str_replace(",", "", str_replace(" ", "", $data[50]))) * 1000;
                                $retail_monthly_stats->non_store_retail_mail_order_houses                       = intval(str_replace(",", "", str_replace(" ", "", $data[51]))) * 1000;
                                $retail_monthly_stats->non_store_retail_excluding_mail_order                    = intval(str_replace(",", "", str_replace(" ", "", $data[52]))) * 1000;

                                $retail_monthly_stats->automotive_fuel                                          = intval(str_replace(",", "", str_replace(" ", "", $data[54]))) * 1000;
                                $retail_monthly_stats->save();
                            }
                        }
                    }
                }

                // File is the weekly average data
                if ($retail_upload_file_type == 'ISCPNSA2') {
                    // Check if this row contains valid data
                    if (strpos(strtolower($data[1]), $valid_date) !== FALSE) {
                        // Check if the table contains data
                        if (count($retailOnlineWeeklyAverageStatsData) <= 0) {

                            // Set the date variable
                            if ($data[0] != '' && $data[0] != null) {
                                $year = $data[0];
                            }
                            $date = (string)$year . ' ' . $data[1];
                            
                            if (!in_array($date, $used_dates)) {
                                // Add date to $used_date array
                                array_push($used_dates, $date);

                                // Get the day_epoch using the date
                                $day_epoch = new Carbon($date, 'Europe/London');
                                $day_epoch = $day_epoch->timestamp;

                                // From here start setting the values and then save them
                                $retail_online_weekly_average_stats = new RetailOnlineWeeklyAverageStats();

                                $retail_online_weekly_average_stats->day_epoch                               = $day_epoch;
                                $retail_online_weekly_average_stats->date                                    = $date;
                                $retail_online_weekly_average_stats->all_retailing_excluding_automotive_fuel = $data[2] * 1000000;
                                $retail_online_weekly_average_stats->predominantly_food_stores_total         = $data[3] * 1000000;
                                $retail_online_weekly_average_stats->predominantly_non_food_stores_total     = $data[4] * 1000000;
                                $retail_online_weekly_average_stats->non_specialised_stores                  = $data[5] * 1000000;
                                $retail_online_weekly_average_stats->textile_clothing_footwear_stores        = $data[6] * 1000000;
                                $retail_online_weekly_average_stats->household_goods_stores                  = $data[7] * 1000000;
                                $retail_online_weekly_average_stats->other_stores                            = $data[8] * 1000000;
                                $retail_online_weekly_average_stats->non_store_retailing                     = $data[9] * 1000000;
                                
                                $retail_online_weekly_average_stats->save();
                            }                            
                        }
                        else {
                            $lastestResult = $retailOnlineWeeklyAverageStatsData[0]['date'];

                            $latest_day_epoch = new Carbon($lastestResult, 'Europe/London');
                            $latest_day_epoch = $latest_day_epoch->timestamp;

                            // Set the date variable
                            if ($data[0] != '' && $data[0] != null) {
                                $year = $data[0];
                            }
                            $date = (string)$year . ' ' . $data[1];

                            $day_epoch = new Carbon($date, 'Europe/London');
                            $day_epoch = $day_epoch->timestamp;

                            if (!in_array($date, $used_dates)) {
                                // Add date to $used_date array
                                array_push($used_dates, $date);

                                if ($day_epoch > $latest_day_epoch) {
                                    // From here start setting the values and then save them
                                    $retail_online_weekly_average_stats = new RetailOnlineWeeklyAverageStats();

                                    $retail_online_weekly_average_stats->day_epoch                               = $day_epoch;
                                    $retail_online_weekly_average_stats->date                                    = $date;
                                    $retail_online_weekly_average_stats->all_retailing_excluding_automotive_fuel = $data[2] * 1000000;
                                    $retail_online_weekly_average_stats->predominantly_food_stores_total         = $data[3] * 1000000;
                                    $retail_online_weekly_average_stats->predominantly_non_food_stores_total     = $data[4] * 1000000;
                                    $retail_online_weekly_average_stats->non_specialised_stores                  = $data[5] * 1000000;
                                    $retail_online_weekly_average_stats->textile_clothing_footwear_stores        = $data[6] * 1000000;
                                    $retail_online_weekly_average_stats->household_goods_stores                  = $data[7] * 1000000;
                                    $retail_online_weekly_average_stats->other_stores                            = $data[8] * 1000000;
                                    $retail_online_weekly_average_stats->non_store_retailing                     = $data[9] * 1000000;
                                    
                                    $retail_online_weekly_average_stats->save();
                                }
                            }
                        }
                    }
                }

                // File is the online penetration data
                if ($retail_upload_file_type == 'ISCPNSA3') {
                    // Check if this row contains valid data
                    if (strpos(strtolower($data[1]), $valid_date) !== FALSE) {

                        // Check if the table contains data
                        if (count($retailOnlinePenetrationStatsData) <= 0) {

                            // Set the date variable
                            if ($data[0] != '' && $data[0] != null) {
                                $year = $data[0];
                            }
                            $date = (string)$year . ' ' . $data[1];
                            
                            if (!in_array($date, $used_dates)) {
                                // Add date to $used_date array
                                array_push($used_dates, $date);

                                // Get the day_epoch using the date
                                $day_epoch = new Carbon($date, 'Europe/London');
                                $day_epoch = $day_epoch->timestamp;

                                // From here start setting the values and then save them
                                $retail_online_penetration_stats = new RetailOnlinePenetrationStats();

                                $retail_online_penetration_stats->day_epoch                               = $day_epoch;
                                $retail_online_penetration_stats->date                                    = $date;
                                $retail_online_penetration_stats->all_retailing_excluding_automotive_fuel = $data[2];
                                $retail_online_penetration_stats->predominantly_food_stores_total         = $data[3];
                                $retail_online_penetration_stats->predominantly_non_food_stores_total     = $data[4];
                                $retail_online_penetration_stats->non_specialised_stores                  = $data[5];
                                $retail_online_penetration_stats->textile_clothing_footwear_stores        = $data[6];
                                $retail_online_penetration_stats->household_goods_stores                  = $data[7];
                                $retail_online_penetration_stats->other_stores                            = $data[8];
                                $retail_online_penetration_stats->non_store_retailing                     = $data[9];
                                
                                $retail_online_penetration_stats->save();
                            }                            
                        }
                        else {
                            $lastestResult = $retailOnlineWeeklyAverageStatsData[0]['date'];

                            $latest_day_epoch = new Carbon($lastestResult, 'Europe/London');
                            $latest_day_epoch = $latest_day_epoch->timestamp;

                            // Set the date variable
                            if ($data[0] != '' && $data[0] != null) {
                                $year = $data[0];
                            }
                            $date = (string)$year . ' ' . $data[1];

                            $day_epoch = new Carbon($date, 'Europe/London');
                            $day_epoch = $day_epoch->timestamp;

                            if (!in_array($date, $used_dates)) {
                                // Add date to $used_date array
                                array_push($used_dates, $date);

                                if ($day_epoch > $latest_day_epoch) {
                                    // From here start setting the values and then save them
                                    $retail_online_penetration_stats = new RetailOnlinePenetrationStats();

                                    $retail_online_penetration_stats->day_epoch                               = $day_epoch;
                                    $retail_online_penetration_stats->date                                    = $date;
                                    $retail_online_penetration_stats->all_retailing_excluding_automotive_fuel = $data[2];
                                    $retail_online_penetration_stats->predominantly_food_stores_total         = $data[3];
                                    $retail_online_penetration_stats->predominantly_non_food_stores_total     = $data[4];
                                    $retail_online_penetration_stats->non_specialised_stores                  = $data[5];
                                    $retail_online_penetration_stats->textile_clothing_footwear_stores        = $data[6];
                                    $retail_online_penetration_stats->household_goods_stores                  = $data[7];
                                    $retail_online_penetration_stats->other_stores                            = $data[8];
                                    $retail_online_penetration_stats->non_store_retailing                     = $data[9];
                                    
                                    $retail_online_penetration_stats->save();
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}