<?php

namespace UserFrosting\Sprinkle\RetailSense\Controller;

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

use UserFrosting\Sprinkle\RetailSense\Database\Models\RetailWeeklyAverageStats;
use UserFrosting\Sprinkle\RetailSense\Database\Models\RetailOnlineWeeklyAverageStats;
use UserFrosting\Sprinkle\RetailSense\Database\Models\RetailOnlinePenetrationStats;

/**
 * ApiController Class
 *
 * @package RetailSense
 * @author Luke Rayner/ElephantWiFi
 * @link http://www.elephantwifi.co.uk
 */
class ApiController extends SimpleController 
{
    public function listMonthWeeklyAverageSalesExclFuel(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_retail_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $results = [];

        $retailWeeklyAverageStatsQuery = new RetailWeeklyAverageStats;
        $initialresults = $retailWeeklyAverageStatsQuery
            ->selectRaw('day_epoch, all_retailing_excluding_automotive_fuel_all_businesses AS total_weekly_average_sales_all_businesses, all_retailing_excluding_automotive_fuel_large_businesses AS total_weekly_average_sales_large_businesses, all_retailing_excluding_automotive_fuel_small_businesses AS total_weekly_average_sales_small_businesses')
            ->where('day_epoch', '>=', $args['start']/1000)
            ->where('day_epoch', '<', $args['end']/1000)
            ->groupBy('day_epoch')
            ->orderBy('day_epoch', 'ASC')
            ->get();

        /**
         * process the results
         */
        foreach ($initialresults as $initialresult) {
            $results[0][] = [(int)$initialresult['day_epoch']*1000, (int)$initialresult['total_weekly_average_sales_all_businesses']];
            $results[1][] = [(int)$initialresult['day_epoch']*1000, (int)$initialresult['total_weekly_average_sales_large_businesses']];
            $results[2][] = [(int)$initialresult['day_epoch']*1000, (int)$initialresult['total_weekly_average_sales_small_businesses']];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listMonthWeeklyAverageSalesPercentageChangeExclFuel(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_retail_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $results = [];

        $retailWeeklyAverageStatsQuery = new RetailWeeklyAverageStats;
        $initialresults = $retailWeeklyAverageStatsQuery
            ->selectRaw('day_epoch, all_retailing_excluding_automotive_fuel_all_businesses AS total_weekly_average_sales_all_businesses, all_retailing_excluding_automotive_fuel_large_businesses AS total_weekly_average_sales_large_businesses, all_retailing_excluding_automotive_fuel_small_businesses AS total_weekly_average_sales_small_businesses')
            ->where('day_epoch', '>=', $args['start']/1000)
            ->where('day_epoch', '<', $args['end']/1000)
            ->groupBy('day_epoch')
            ->orderBy('day_epoch', 'ASC')
            ->get();

        /**
         * process the results
         */
        $previousMonthSales = 0;
        foreach ($initialresults as $initialresult) {
            $total_weekly_average_sales_all_businesses = (int)$initialresult['total_weekly_average_sales_all_businesses'];
            $total_weekly_average_sales_large_businesses = (int)$initialresult['total_weekly_average_sales_large_businesses'];
            $total_weekly_average_sales_small_businesses = (int)$initialresult['total_weekly_average_sales_small_businesses'];

            $oneYearAgo = Carbon::createFromTimestamp((int)$initialresult['day_epoch'])->subYears(1)->timestamp; 

            $salesOneYearAgo = $retailWeeklyAverageStatsQuery
                ->selectRaw('day_epoch, all_retailing_excluding_automotive_fuel_all_businesses AS total_weekly_average_sales_all_businesses, all_retailing_excluding_automotive_fuel_large_businesses AS total_weekly_average_sales_large_businesses, all_retailing_excluding_automotive_fuel_small_businesses AS total_weekly_average_sales_small_businesses')
                ->where('day_epoch', $oneYearAgo)
                ->first();

            // All businesses
            if ($total_weekly_average_sales_all_businesses != 0 && $salesOneYearAgo['total_weekly_average_sales_all_businesses'] != 0 && $total_weekly_average_sales_all_businesses != $salesOneYearAgo['total_weekly_average_sales_all_businesses']) {
                $percentageChange = ((($salesOneYearAgo['total_weekly_average_sales_all_businesses'] - $total_weekly_average_sales_all_businesses) / $salesOneYearAgo['total_weekly_average_sales_all_businesses']) * 100) * -1;
                $results[0][] = [(int)$initialresult['day_epoch']*1000, $percentageChange];
            }
            else {
                $results[0][] = [(int)$initialresult['day_epoch']*1000, 0];
            }

            // Large Businesses
            if ($total_weekly_average_sales_large_businesses != 0 && $salesOneYearAgo['total_weekly_average_sales_large_businesses'] != 0 && $total_weekly_average_sales_large_businesses != $salesOneYearAgo['total_weekly_average_sales_large_businesses']) {
                $percentageChange = ((($salesOneYearAgo['total_weekly_average_sales_large_businesses'] - $total_weekly_average_sales_large_businesses) / $salesOneYearAgo['total_weekly_average_sales_large_businesses']) * 100) * -1;
                $results[1][] = [(int)$initialresult['day_epoch']*1000, $percentageChange];
            }
            else {
                $results[1][] = [(int)$initialresult['day_epoch']*1000, 0];
            }

            // Small Businesses
            if ($total_weekly_average_sales_small_businesses != 0 && $salesOneYearAgo['total_weekly_average_sales_small_businesses'] != 0 && $total_weekly_average_sales_small_businesses != $salesOneYearAgo['total_weekly_average_sales_small_businesses']) {
                $percentageChange = ((($salesOneYearAgo['total_weekly_average_sales_small_businesses'] - $total_weekly_average_sales_small_businesses) / $salesOneYearAgo['total_weekly_average_sales_small_businesses']) * 100) * -1;
                $results[2][] = [(int)$initialresult['day_epoch']*1000, $percentageChange];
            }
            else {
                $results[2][] = [(int)$initialresult['day_epoch']*1000, 0];
            }

            // Month on Month percentage difference all businesses
            if ($previousMonthSales == 0) {
                // Do nothing
            }
            else if ($total_weekly_average_sales_all_businesses > $previousMonthSales || $total_weekly_average_sales_all_businesses < $previousMonthSales) {
                $percentageChange = ((($previousMonthSales - $total_weekly_average_sales_all_businesses) / $previousMonthSales) * 100) * -1;
                $results[3][] = [(int)$initialresult['day_epoch']*1000, $percentageChange];
            }
            else {
                $results[3][] = [(int)$initialresult['day_epoch']*1000, 0];
            }

            $previousMonthSales = $total_weekly_average_sales_all_businesses;
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listMonthWeeklyAverageOnlineSalesExclFuel(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_retail_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $results = [];

        $retailOnlineWeeklyAverageStatsQuery = new RetailOnlineWeeklyAverageStats;
        $initialresults = $retailOnlineWeeklyAverageStatsQuery
            ->selectRaw('day_epoch, all_retailing_excluding_automotive_fuel AS total_weekly_average_online_sales')
            ->where('day_epoch', '>=', $args['start']/1000)
            ->where('day_epoch', '<', $args['end']/1000)
            ->groupBy('day_epoch')
            ->orderBy('day_epoch', 'ASC')
            ->get();

        /**
         * process the results
         */
        foreach ($initialresults as $initialresult) {
            $results[] = [(int)$initialresult['day_epoch']*1000, (int)$initialresult['total_weekly_average_online_sales']];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listMonthWeeklyAverageOnlineSalesPercentageChangeExclFuel(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_retail_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $results = [];

        $retailOnlineWeeklyAverageStatsQuery = new RetailOnlineWeeklyAverageStats;
        $initialresults = $retailOnlineWeeklyAverageStatsQuery
            ->selectRaw('day_epoch, all_retailing_excluding_automotive_fuel AS total_weekly_average_online_sales')
            ->where('day_epoch', '>=', $args['start']/1000)
            ->where('day_epoch', '<', $args['end']/1000)
            ->groupBy('day_epoch')
            ->orderBy('day_epoch', 'ASC')
            ->get();

        /**
         * process the results
         */
        foreach ($initialresults as $initialresult) {
            $total_weekly_average_online_sales = (int)$initialresult['total_weekly_average_online_sales'];

            $oneYearAgo = Carbon::createFromTimestamp((int)$initialresult['day_epoch'])->subYears(1)->timestamp; 

            $salesOneYearAgo = $retailOnlineWeeklyAverageStatsQuery
                ->selectRaw('day_epoch, date, all_retailing_excluding_automotive_fuel AS total_weekly_average_online_sales')
                ->where('day_epoch', $oneYearAgo)
                ->first();

            if ($total_weekly_average_online_sales != 0 && $salesOneYearAgo['total_weekly_average_online_sales'] != 0 && $total_weekly_average_online_sales != $salesOneYearAgo['total_weekly_average_online_sales']) {
                $percentageChange = ((($salesOneYearAgo['total_weekly_average_online_sales'] - $total_weekly_average_online_sales) / $salesOneYearAgo['total_weekly_average_online_sales']) * 100) * -1;
                $results[] = [(int)$initialresult['day_epoch']*1000, $percentageChange];
            }
            else {
                $results[] = [(int)$initialresult['day_epoch']*1000, 0];
            }
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listMonthWeeklyAverageOnlinePenetration(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_retail_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $results = [];

        $retailOnlinePenetrationStatsQuery = new RetailOnlinePenetrationStats;
        $initialresults = $retailOnlinePenetrationStatsQuery
            ->selectRaw('day_epoch, 
                all_retailing_excluding_automotive_fuel AS all_retail_online_penetration, 
                predominantly_food_stores_total AS food_store_online_penetration, 
                textile_clothing_footwear_stores AS textile_clothing_footwear_stores_online_penetration,
                predominantly_non_food_stores_total AS non_food_store_online_penetration,
                household_goods_stores AS household_goods_stores,
                non_store_retailing AS none_store_retailing
            ')
            ->where('day_epoch', '>=', $args['start']/1000)
            ->where('day_epoch', '<', $args['end']/1000)
            ->groupBy('day_epoch')
            ->orderBy('day_epoch', 'ASC')
            ->get();

        /**
         * process the results
         */
        foreach ($initialresults as $initialresult) {
            $results['all_retail_online_penetration'][] = [(int)$initialresult['day_epoch']*1000, (int)$initialresult['all_retail_online_penetration']];
            $results['food_store_online_penetration'][] = [(int)$initialresult['day_epoch']*1000, (int)$initialresult['food_store_online_penetration']];
            $results['non_food_store_online_penetration'][] = [(int)$initialresult['day_epoch']*1000, (int)$initialresult['non_food_store_online_penetration']];
            $results['textile_clothing_footwear_stores_online_penetration'][] = [(int)$initialresult['day_epoch']*1000, (int)$initialresult['textile_clothing_footwear_stores_online_penetration']];
            $results['household_goods_stores'][] = [(int)$initialresult['day_epoch']*1000, (int)$initialresult['household_goods_stores']];
            $results['none_store_retailing'][] = [(int)$initialresult['day_epoch']*1000, (int)$initialresult['none_store_retailing']];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listMonthWeeklyAverageFoodStoreSales(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_retail_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $results = [];

        $retailWeeklyAverageStatsQuery = new RetailWeeklyAverageStats;
        $initialresults = $retailWeeklyAverageStatsQuery
            ->selectRaw('day_epoch, predominantly_food_stores_total_all_businesses AS food_stores_weekly_average_sales_all_businesses, predominantly_food_stores_total_large_businesses AS food_stores_weekly_average_sales_large_businesses, predominantly_food_stores_total_small_businesses AS food_stores_weekly_average_sales_small_businesses')
            ->where('day_epoch', '>=', $args['start']/1000)
            ->where('day_epoch', '<', $args['end']/1000)
            ->groupBy('day_epoch')
            ->orderBy('day_epoch', 'ASC')
            ->get();

        /**
         * process the results
         */
        foreach ($initialresults as $initialresult) {
            $results[0][] = [(int)$initialresult['day_epoch']*1000, (int)$initialresult['food_stores_weekly_average_sales_all_businesses']];
            $results[1][] = [(int)$initialresult['day_epoch']*1000, (int)$initialresult['food_stores_weekly_average_sales_large_businesses']];
            $results[2][] = [(int)$initialresult['day_epoch']*1000, (int)$initialresult['food_stores_weekly_average_sales_small_businesses']];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listMonthWeeklyAverageFoodStoreSalesPercentageChange(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_retail_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $results = [];

        $retailWeeklyAverageStatsQuery = new RetailWeeklyAverageStats;
        $initialresults = $retailWeeklyAverageStatsQuery
            ->selectRaw('day_epoch, predominantly_food_stores_total_all_businesses AS food_stores_weekly_average_sales_all_businesses, predominantly_food_stores_total_large_businesses AS food_stores_weekly_average_sales_large_businesses, predominantly_food_stores_total_small_businesses AS food_stores_weekly_average_sales_small_businesses')
            ->where('day_epoch', '>=', $args['start']/1000)
            ->where('day_epoch', '<', $args['end']/1000)
            ->groupBy('day_epoch')
            ->orderBy('day_epoch', 'ASC')
            ->get();

        /**
         * process the results
         */
        $previousMonthSales = 0;
        foreach ($initialresults as $initialresult) {
            $food_stores_weekly_average_sales_all_businesses = (int)$initialresult['food_stores_weekly_average_sales_all_businesses'];
            $food_stores_weekly_average_sales_large_businesses = (int)$initialresult['food_stores_weekly_average_sales_large_businesses'];
            $food_stores_weekly_average_sales_small_businesses = (int)$initialresult['food_stores_weekly_average_sales_small_businesses'];

            $oneYearAgo = Carbon::createFromTimestamp((int)$initialresult['day_epoch'])->subYears(1)->timestamp; 

            $salesOneYearAgo = $retailWeeklyAverageStatsQuery
                ->selectRaw('day_epoch, predominantly_food_stores_total_all_businesses AS food_stores_weekly_average_sales_all_businesses, predominantly_food_stores_total_large_businesses AS food_stores_weekly_average_sales_large_businesses, predominantly_food_stores_total_small_businesses AS food_stores_weekly_average_sales_small_businesses')
                ->where('day_epoch', $oneYearAgo)
                ->first();

            // All businesses
            if ($food_stores_weekly_average_sales_all_businesses != 0 && $salesOneYearAgo['food_stores_weekly_average_sales_all_businesses'] != 0 && $food_stores_weekly_average_sales_all_businesses != $salesOneYearAgo['food_stores_weekly_average_sales_all_businesses']) {
                $percentageChange = ((($salesOneYearAgo['food_stores_weekly_average_sales_all_businesses'] - $food_stores_weekly_average_sales_all_businesses) / $salesOneYearAgo['food_stores_weekly_average_sales_all_businesses']) * 100) * -1;
                $results[0][] = [(int)$initialresult['day_epoch']*1000, $percentageChange];
            }
            else {
                $results[0][] = [(int)$initialresult['day_epoch']*1000, 0];
            }

            // Large Businesses
            if ($food_stores_weekly_average_sales_large_businesses != 0 && $salesOneYearAgo['food_stores_weekly_average_sales_large_businesses'] != 0 && $food_stores_weekly_average_sales_large_businesses != $salesOneYearAgo['food_stores_weekly_average_sales_large_businesses']) {
                $percentageChange = ((($salesOneYearAgo['food_stores_weekly_average_sales_large_businesses'] - $food_stores_weekly_average_sales_large_businesses) / $salesOneYearAgo['food_stores_weekly_average_sales_large_businesses']) * 100) * -1;
                $results[1][] = [(int)$initialresult['day_epoch']*1000, $percentageChange];
            }
            else {
                $results[1][] = [(int)$initialresult['day_epoch']*1000, 0];
            }

            // Small Businesses
            if ($food_stores_weekly_average_sales_small_businesses != 0 && $salesOneYearAgo['food_stores_weekly_average_sales_small_businesses'] != 0 && $food_stores_weekly_average_sales_small_businesses != $salesOneYearAgo['food_stores_weekly_average_sales_small_businesses']) {
                $percentageChange = ((($salesOneYearAgo['food_stores_weekly_average_sales_small_businesses'] - $food_stores_weekly_average_sales_small_businesses) / $salesOneYearAgo['food_stores_weekly_average_sales_small_businesses']) * 100) * -1;
                $results[2][] = [(int)$initialresult['day_epoch']*1000, $percentageChange];
            }
            else {
                $results[2][] = [(int)$initialresult['day_epoch']*1000, 0];
            }

            // Month on Month percentage difference all businesses
            if ($previousMonthSales == 0) {
                // Do nothing
            }
            else if ($food_stores_weekly_average_sales_all_businesses > $previousMonthSales || $food_stores_weekly_average_sales_all_businesses < $previousMonthSales) {
                $percentageChange = ((($previousMonthSales - $food_stores_weekly_average_sales_all_businesses) / $previousMonthSales) * 100) * -1;
                $results[3][] = [(int)$initialresult['day_epoch']*1000, $percentageChange];
            }
            else {
                $results[3][] = [(int)$initialresult['day_epoch']*1000, 0];
            }

            $previousMonthSales = $food_stores_weekly_average_sales_all_businesses;
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listMonthWeeklyAverageOnlineFoodStoreSales(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_retail_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $results = [];

        $retailOnlineWeeklyAverageStatsQuery = new RetailOnlineWeeklyAverageStats;
        $initialresults = $retailOnlineWeeklyAverageStatsQuery
            ->selectRaw('day_epoch, predominantly_food_stores_total AS food_stores_weekly_average_online_sales')
            ->where('day_epoch', '>=', $args['start']/1000)
            ->where('day_epoch', '<', $args['end']/1000)
            ->groupBy('day_epoch')
            ->orderBy('day_epoch', 'ASC')
            ->get();

        /**
         * process the results
         */
        foreach ($initialresults as $initialresult) {
            $results[] = [(int)$initialresult['day_epoch']*1000, (int)$initialresult['food_stores_weekly_average_online_sales']];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listMonthWeeklyAverageOnlineFoodStoreSalesPercentageChange(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_retail_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $results = [];

        $retailOnlineWeeklyAverageStatsQuery = new RetailOnlineWeeklyAverageStats;
        $initialresults = $retailOnlineWeeklyAverageStatsQuery
            ->selectRaw('day_epoch, predominantly_food_stores_total AS food_stores_weekly_average_online_sales')
            ->where('day_epoch', '>=', $args['start']/1000)
            ->where('day_epoch', '<', $args['end']/1000)
            ->groupBy('day_epoch')
            ->orderBy('day_epoch', 'ASC')
            ->get();

        /**
         * process the results
         */
        foreach ($initialresults as $initialresult) {
            $food_stores_weekly_average_online_sales = (int)$initialresult['food_stores_weekly_average_online_sales'];

            $oneYearAgo = Carbon::createFromTimestamp((int)$initialresult['day_epoch'])->subYears(1)->timestamp; 

            $salesOneYearAgo = $retailOnlineWeeklyAverageStatsQuery
                ->selectRaw('day_epoch, date, predominantly_food_stores_total AS food_stores_weekly_average_online_sales')
                ->where('day_epoch', $oneYearAgo)
                ->first();

            if ($food_stores_weekly_average_online_sales != 0 && $salesOneYearAgo['food_stores_weekly_average_online_sales'] != 0 && $food_stores_weekly_average_online_sales != $salesOneYearAgo['food_stores_weekly_average_online_sales']) {
                $percentageChange = ((($salesOneYearAgo['food_stores_weekly_average_online_sales'] - $food_stores_weekly_average_online_sales) / $salesOneYearAgo['food_stores_weekly_average_online_sales']) * 100) * -1;
                $results[] = [(int)$initialresult['day_epoch']*1000, $percentageChange];
            }
            else {
                $results[] = [(int)$initialresult['day_epoch']*1000, 0];
            }
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listMonthWeeklyAverageNoneFoodStoreSales(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_retail_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $results = [];

        $retailWeeklyAverageStatsQuery = new RetailWeeklyAverageStats;
        $initialresults = $retailWeeklyAverageStatsQuery
            ->selectRaw('day_epoch, predominantly_non_food_stores_total_all_businesses AS non_food_stores_weekly_average_sales_all_businesses, predominantly_non_food_stores_total_large_businesses AS non_food_stores_weekly_average_sales_large_businesses, predominantly_non_food_stores_total_small_businesses AS non_food_stores_weekly_average_sales_small_businesses')
            ->where('day_epoch', '>=', $args['start']/1000)
            ->where('day_epoch', '<', $args['end']/1000)
            ->groupBy('day_epoch')
            ->orderBy('day_epoch', 'ASC')
            ->get();

        /**
         * process the results
         */
        foreach ($initialresults as $initialresult) {
            $results[0][] = [(int)$initialresult['day_epoch']*1000, (int)$initialresult['non_food_stores_weekly_average_sales_all_businesses']];
            $results[1][] = [(int)$initialresult['day_epoch']*1000, (int)$initialresult['non_food_stores_weekly_average_sales_large_businesses']];
            $results[2][] = [(int)$initialresult['day_epoch']*1000, (int)$initialresult['non_food_stores_weekly_average_sales_small_businesses']];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listMonthWeeklyAverageNoneFoodStoreSalesPercentageChange(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_retail_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $results = [];

        $retailWeeklyAverageStatsQuery = new RetailWeeklyAverageStats;
        $initialresults = $retailWeeklyAverageStatsQuery
            ->selectRaw('day_epoch, predominantly_non_food_stores_total_all_businesses AS non_food_stores_weekly_average_sales_all_businesses, predominantly_non_food_stores_total_large_businesses AS non_food_stores_weekly_average_sales_large_businesses, predominantly_non_food_stores_total_small_businesses AS non_food_stores_weekly_average_sales_small_businesses')
            ->where('day_epoch', '>=', $args['start']/1000)
            ->where('day_epoch', '<', $args['end']/1000)
            ->groupBy('day_epoch')
            ->orderBy('day_epoch', 'ASC')
            ->get();

        /**
         * process the results
         */
        $previousMonthSales = 0;
        foreach ($initialresults as $initialresult) {
            $non_food_stores_weekly_average_sales_all_businesses = (int)$initialresult['non_food_stores_weekly_average_sales_all_businesses'];
            $non_food_stores_weekly_average_sales_large_businesses = (int)$initialresult['non_food_stores_weekly_average_sales_large_businesses'];
            $non_food_stores_weekly_average_sales_small_businesses = (int)$initialresult['non_food_stores_weekly_average_sales_small_businesses'];

            $oneYearAgo = Carbon::createFromTimestamp((int)$initialresult['day_epoch'])->subYears(1)->timestamp; 

            $salesOneYearAgo = $retailWeeklyAverageStatsQuery
                ->selectRaw('day_epoch, predominantly_non_food_stores_total_all_businesses AS non_food_stores_weekly_average_sales_all_businesses, predominantly_non_food_stores_total_large_businesses AS non_food_stores_weekly_average_sales_large_businesses, predominantly_non_food_stores_total_small_businesses AS non_food_stores_weekly_average_sales_small_businesses')
                ->where('day_epoch', $oneYearAgo)
                ->first();

            // All businesses
            if ($non_food_stores_weekly_average_sales_all_businesses != 0 && $salesOneYearAgo['non_food_stores_weekly_average_sales_all_businesses'] != 0 && $non_food_stores_weekly_average_sales_all_businesses != $salesOneYearAgo['non_food_stores_weekly_average_sales_all_businesses']) {
                $percentageChange = ((($salesOneYearAgo['non_food_stores_weekly_average_sales_all_businesses'] - $non_food_stores_weekly_average_sales_all_businesses) / $salesOneYearAgo['non_food_stores_weekly_average_sales_all_businesses']) * 100) * -1;
                $results[0][] = [(int)$initialresult['day_epoch']*1000, $percentageChange];
            }
            else {
                $results[0][] = [(int)$initialresult['day_epoch']*1000, 0];
            }

            // Large Businesses
            if ($non_food_stores_weekly_average_sales_large_businesses != 0 && $salesOneYearAgo['non_food_stores_weekly_average_sales_large_businesses'] != 0 && $non_food_stores_weekly_average_sales_large_businesses != $salesOneYearAgo['non_food_stores_weekly_average_sales_large_businesses']) {
                $percentageChange = ((($salesOneYearAgo['non_food_stores_weekly_average_sales_large_businesses'] - $non_food_stores_weekly_average_sales_large_businesses) / $salesOneYearAgo['non_food_stores_weekly_average_sales_large_businesses']) * 100) * -1;
                $results[1][] = [(int)$initialresult['day_epoch']*1000, $percentageChange];
            }
            else {
                $results[1][] = [(int)$initialresult['day_epoch']*1000, 0];
            }

            // Small Businesses
            if ($non_food_stores_weekly_average_sales_small_businesses != 0 && $salesOneYearAgo['non_food_stores_weekly_average_sales_small_businesses'] != 0 && $non_food_stores_weekly_average_sales_small_businesses != $salesOneYearAgo['non_food_stores_weekly_average_sales_small_businesses']) {
                $percentageChange = ((($salesOneYearAgo['non_food_stores_weekly_average_sales_small_businesses'] - $non_food_stores_weekly_average_sales_small_businesses) / $salesOneYearAgo['non_food_stores_weekly_average_sales_small_businesses']) * 100) * -1;
                $results[2][] = [(int)$initialresult['day_epoch']*1000, $percentageChange];
            }
            else {
                $results[2][] = [(int)$initialresult['day_epoch']*1000, 0];
            }

            // Month on Month percentage difference all businesses
            if ($previousMonthSales == 0) {
                // Do nothing
            }
            else if ($non_food_stores_weekly_average_sales_all_businesses > $previousMonthSales || $non_food_stores_weekly_average_sales_all_businesses < $previousMonthSales) {
                $percentageChange = ((($previousMonthSales - $non_food_stores_weekly_average_sales_all_businesses) / $previousMonthSales) * 100) * -1;
                $results[3][] = [(int)$initialresult['day_epoch']*1000, $percentageChange];
            }
            else {
                $results[3][] = [(int)$initialresult['day_epoch']*1000, 0];
            }

            $previousMonthSales = $non_food_stores_weekly_average_sales_all_businesses;
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listMonthWeeklyAverageOnlineNoneFoodStoreSales(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_retail_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $results = [];

        $retailOnlineWeeklyAverageStatsQuery = new RetailOnlineWeeklyAverageStats;
        $initialresults = $retailOnlineWeeklyAverageStatsQuery
            ->selectRaw('day_epoch, predominantly_non_food_stores_total AS non_food_stores_weekly_average_online_sales')
            ->where('day_epoch', '>=', $args['start']/1000)
            ->where('day_epoch', '<', $args['end']/1000)
            ->groupBy('day_epoch')
            ->orderBy('day_epoch', 'ASC')
            ->get();

        /**
         * process the results
         */
        foreach ($initialresults as $initialresult) {
            $results[] = [(int)$initialresult['day_epoch']*1000, (int)$initialresult['non_food_stores_weekly_average_online_sales']];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listMonthWeeklyAverageOnlineNoneFoodStoreSalesPercentageChange(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_retail_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $results = [];

        $retailOnlineWeeklyAverageStatsQuery = new RetailOnlineWeeklyAverageStats;
        $initialresults = $retailOnlineWeeklyAverageStatsQuery
            ->selectRaw('day_epoch, predominantly_non_food_stores_total AS non_food_stores_weekly_average_online_sales')
            ->where('day_epoch', '>=', $args['start']/1000)
            ->where('day_epoch', '<', $args['end']/1000)
            ->groupBy('day_epoch')
            ->orderBy('day_epoch', 'ASC')
            ->get();

        /**
         * process the results
         */
        foreach ($initialresults as $initialresult) {
            $non_food_stores_weekly_average_online_sales = (int)$initialresult['non_food_stores_weekly_average_online_sales'];

            $oneYearAgo = Carbon::createFromTimestamp((int)$initialresult['day_epoch'])->subYears(1)->timestamp; 

            $salesOneYearAgo = $retailOnlineWeeklyAverageStatsQuery
                ->selectRaw('day_epoch, date, predominantly_non_food_stores_total AS non_food_stores_weekly_average_online_sales')
                ->where('day_epoch', $oneYearAgo)
                ->first();

            if ($non_food_stores_weekly_average_online_sales != 0 && $salesOneYearAgo['non_food_stores_weekly_average_online_sales'] != 0 && $non_food_stores_weekly_average_online_sales != $salesOneYearAgo['non_food_stores_weekly_average_online_sales']) {
                $percentageChange = ((($salesOneYearAgo['non_food_stores_weekly_average_online_sales'] - $non_food_stores_weekly_average_online_sales) / $salesOneYearAgo['non_food_stores_weekly_average_online_sales']) * 100) * -1;
                $results[] = [(int)$initialresult['day_epoch']*1000, $percentageChange];
            }
            else {
                $results[] = [(int)$initialresult['day_epoch']*1000, 0];
            }
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listMonthWeeklyAverageTextileClothingFootwearSales(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_retail_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $results = [];

        $retailWeeklyAverageStatsQuery = new RetailWeeklyAverageStats;
        $initialresults = $retailWeeklyAverageStatsQuery
            ->selectRaw('day_epoch, 
                textile_clothing_footwear_and_leather_all_businesses AS all_businesses_sales, 
                textile_clothing_footwear_and_leather_large_businesses AS large_businesses_sales, 
                textile_clothing_footwear_and_leather_small_businesses AS small_businesses_sales
            ')
            ->where('day_epoch', '>=', $args['start']/1000)
            ->where('day_epoch', '<', $args['end']/1000)
            ->groupBy('day_epoch')
            ->orderBy('day_epoch', 'ASC')
            ->get();

        /**
         * process the results
         */
        foreach ($initialresults as $initialresult) {
            $results[0][] = [(int)$initialresult['day_epoch']*1000, (int)$initialresult['all_businesses_sales']];
            $results[1][] = [(int)$initialresult['day_epoch']*1000, (int)$initialresult['large_businesses_sales']];
            $results[2][] = [(int)$initialresult['day_epoch']*1000, (int)$initialresult['small_businesses_sales']];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listMonthWeeklyAverageTextileClothingFootwearSalesPercentageChange(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_retail_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $results = [];

        $retailWeeklyAverageStatsQuery = new RetailWeeklyAverageStats;
        $initialresults = $retailWeeklyAverageStatsQuery
            ->selectRaw('day_epoch, 
                textile_clothing_footwear_and_leather_all_businesses AS all_businesses_sales, 
                textile_clothing_footwear_and_leather_large_businesses AS large_businesses_sales, 
                textile_clothing_footwear_and_leather_small_businesses AS small_businesses_sales
            ')
            ->where('day_epoch', '>=', $args['start']/1000)
            ->where('day_epoch', '<', $args['end']/1000)
            ->groupBy('day_epoch')
            ->orderBy('day_epoch', 'ASC')
            ->get();

        /**
         * process the results
         */
        $previousMonthSales = 0;
        foreach ($initialresults as $initialresult) {
            $all_businesses_sales = (int)$initialresult['all_businesses_sales'];
            $large_businesses_sales = (int)$initialresult['large_businesses_sales'];
            $small_businesses_sales = (int)$initialresult['small_businesses_sales'];

            $oneYearAgo = Carbon::createFromTimestamp((int)$initialresult['day_epoch'])->subYears(1)->timestamp; 

            $salesOneYearAgo = $retailWeeklyAverageStatsQuery
                ->selectRaw('day_epoch, 
                    textile_clothing_footwear_and_leather_all_businesses AS all_businesses_sales, 
                    textile_clothing_footwear_and_leather_large_businesses AS large_businesses_sales, 
                    textile_clothing_footwear_and_leather_small_businesses AS small_businesses_sales
                ')
                ->where('day_epoch', $oneYearAgo)
                ->first();

            // All businesses
            if ($all_businesses_sales != 0 && $salesOneYearAgo['all_businesses_sales'] != 0 && $all_businesses_sales != $salesOneYearAgo['all_businesses_sales']) {
                $percentageChange = ((($salesOneYearAgo['all_businesses_sales'] - $all_businesses_sales) / $salesOneYearAgo['all_businesses_sales']) * 100) * -1;
                $results[0][] = [(int)$initialresult['day_epoch']*1000, $percentageChange];
            }
            else {
                $results[0][] = [(int)$initialresult['day_epoch']*1000, 0];
            }

            // Large Businesses
            if ($large_businesses_sales != 0 && $salesOneYearAgo['large_businesses_sales'] != 0 && $large_businesses_sales != $salesOneYearAgo['large_businesses_sales']) {
                $percentageChange = ((($salesOneYearAgo['large_businesses_sales'] - $large_businesses_sales) / $salesOneYearAgo['large_businesses_sales']) * 100) * -1;
                $results[1][] = [(int)$initialresult['day_epoch']*1000, $percentageChange];
            }
            else {
                $results[1][] = [(int)$initialresult['day_epoch']*1000, 0];
            }

            // Small Businesses
            if ($small_businesses_sales != 0 && $salesOneYearAgo['small_businesses_sales'] != 0 && $small_businesses_sales != $salesOneYearAgo['small_businesses_sales']) {
                $percentageChange = ((($salesOneYearAgo['small_businesses_sales'] - $small_businesses_sales) / $salesOneYearAgo['small_businesses_sales']) * 100) * -1;
                $results[2][] = [(int)$initialresult['day_epoch']*1000, $percentageChange];
            }
            else {
                $results[2][] = [(int)$initialresult['day_epoch']*1000, 0];
            }

            // Month on Month percentage difference all businesses
            if ($previousMonthSales == 0) {
                // Do nothing
            }
            else if ($all_businesses_sales > $previousMonthSales || $all_businesses_sales < $previousMonthSales) {
                $percentageChange = ((($previousMonthSales - $all_businesses_sales) / $previousMonthSales) * 100) * -1;
                $results[3][] = [(int)$initialresult['day_epoch']*1000, $percentageChange];
            }
            else {
                $results[3][] = [(int)$initialresult['day_epoch']*1000, 0];
            }

            $previousMonthSales = $all_businesses_sales;
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listMonthWeeklyAverageOnlineTextileClothingFootwearSales(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_retail_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $results = [];

        $retailOnlineWeeklyAverageStatsQuery = new RetailOnlineWeeklyAverageStats;
        $initialresults = $retailOnlineWeeklyAverageStatsQuery
            ->selectRaw('day_epoch, textile_clothing_footwear_stores AS online_sales')
            ->where('day_epoch', '>=', $args['start']/1000)
            ->where('day_epoch', '<', $args['end']/1000)
            ->groupBy('day_epoch')
            ->orderBy('day_epoch', 'ASC')
            ->get();

        /**
         * process the results
         */
        foreach ($initialresults as $initialresult) {
            $results[] = [(int)$initialresult['day_epoch']*1000, (int)$initialresult['online_sales']];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listMonthWeeklyAverageOnlineTextileClothingFootwearSalesPercentageChange(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_retail_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $results = [];

        $retailOnlineWeeklyAverageStatsQuery = new RetailOnlineWeeklyAverageStats;
        $initialresults = $retailOnlineWeeklyAverageStatsQuery
            ->selectRaw('day_epoch, textile_clothing_footwear_stores AS online_sales')
            ->where('day_epoch', '>=', $args['start']/1000)
            ->where('day_epoch', '<', $args['end']/1000)
            ->groupBy('day_epoch')
            ->orderBy('day_epoch', 'ASC')
            ->get();

        /**
         * process the results
         */
        foreach ($initialresults as $initialresult) {
            $online_sales = (int)$initialresult['online_sales'];

            $oneYearAgo = Carbon::createFromTimestamp((int)$initialresult['day_epoch'])->subYears(1)->timestamp; 

            $salesOneYearAgo = $retailOnlineWeeklyAverageStatsQuery
                ->selectRaw('day_epoch, date, textile_clothing_footwear_stores AS online_sales')
                ->where('day_epoch', $oneYearAgo)
                ->first();

            if ($online_sales != 0 && $salesOneYearAgo['online_sales'] != 0 && $online_sales != $salesOneYearAgo['online_sales']) {
                $percentageChange = ((($salesOneYearAgo['online_sales'] - $online_sales) / $salesOneYearAgo['online_sales']) * 100) * -1;
                $results[] = [(int)$initialresult['day_epoch']*1000, $percentageChange];
            }
            else {
                $results[] = [(int)$initialresult['day_epoch']*1000, 0];
            }
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listMonthWeeklyAverageHouseholdGoodsSales(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_retail_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $results = [];

        $retailWeeklyAverageStatsQuery = new RetailWeeklyAverageStats;
        $initialresults = $retailWeeklyAverageStatsQuery
            ->selectRaw('day_epoch, 
                household_goods_stores_all_businesses AS all_businesses_sales, 
                household_goods_stores_large_businesses AS large_businesses_sales, 
                household_goods_stores_small_businesses AS small_businesses_sales
            ')
            ->where('day_epoch', '>=', $args['start']/1000)
            ->where('day_epoch', '<', $args['end']/1000)
            ->groupBy('day_epoch')
            ->orderBy('day_epoch', 'ASC')
            ->get();

        /**
         * process the results
         */
        foreach ($initialresults as $initialresult) {
            $results[0][] = [(int)$initialresult['day_epoch']*1000, (int)$initialresult['all_businesses_sales']];
            $results[1][] = [(int)$initialresult['day_epoch']*1000, (int)$initialresult['large_businesses_sales']];
            $results[2][] = [(int)$initialresult['day_epoch']*1000, (int)$initialresult['small_businesses_sales']];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listMonthWeeklyAverageHouseholdGoodsSalesPercentageChange(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_retail_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $results = [];

        $retailWeeklyAverageStatsQuery = new RetailWeeklyAverageStats;
        $initialresults = $retailWeeklyAverageStatsQuery
            ->selectRaw('day_epoch, 
                household_goods_stores_all_businesses AS all_businesses_sales, 
                household_goods_stores_large_businesses AS large_businesses_sales, 
                household_goods_stores_small_businesses AS small_businesses_sales
            ')
            ->where('day_epoch', '>=', $args['start']/1000)
            ->where('day_epoch', '<', $args['end']/1000)
            ->groupBy('day_epoch')
            ->orderBy('day_epoch', 'ASC')
            ->get();

        /**
         * process the results
         */
        $previousMonthSales = 0;
        foreach ($initialresults as $initialresult) {
            $all_businesses_sales = (int)$initialresult['all_businesses_sales'];
            $large_businesses_sales = (int)$initialresult['large_businesses_sales'];
            $small_businesses_sales = (int)$initialresult['small_businesses_sales'];

            $oneYearAgo = Carbon::createFromTimestamp((int)$initialresult['day_epoch'])->subYears(1)->timestamp; 

            $salesOneYearAgo = $retailWeeklyAverageStatsQuery
                ->selectRaw('day_epoch, 
                    household_goods_stores_all_businesses AS all_businesses_sales, 
                    household_goods_stores_large_businesses AS large_businesses_sales, 
                    household_goods_stores_small_businesses AS small_businesses_sales
                ')
                ->where('day_epoch', $oneYearAgo)
                ->first();

            // All businesses
            if ($all_businesses_sales != 0 && $salesOneYearAgo['all_businesses_sales'] != 0 && $all_businesses_sales != $salesOneYearAgo['all_businesses_sales']) {
                $percentageChange = ((($salesOneYearAgo['all_businesses_sales'] - $all_businesses_sales) / $salesOneYearAgo['all_businesses_sales']) * 100) * -1;
                $results[0][] = [(int)$initialresult['day_epoch']*1000, $percentageChange];
            }
            else {
                $results[0][] = [(int)$initialresult['day_epoch']*1000, 0];
            }

            // Large Businesses
            if ($large_businesses_sales != 0 && $salesOneYearAgo['large_businesses_sales'] != 0 && $large_businesses_sales != $salesOneYearAgo['large_businesses_sales']) {
                $percentageChange = ((($salesOneYearAgo['large_businesses_sales'] - $large_businesses_sales) / $salesOneYearAgo['large_businesses_sales']) * 100) * -1;
                $results[1][] = [(int)$initialresult['day_epoch']*1000, $percentageChange];
            }
            else {
                $results[1][] = [(int)$initialresult['day_epoch']*1000, 0];
            }

            // Small Businesses
            if ($small_businesses_sales != 0 && $salesOneYearAgo['small_businesses_sales'] != 0 && $small_businesses_sales != $salesOneYearAgo['small_businesses_sales']) {
                $percentageChange = ((($salesOneYearAgo['small_businesses_sales'] - $small_businesses_sales) / $salesOneYearAgo['small_businesses_sales']) * 100) * -1;
                $results[2][] = [(int)$initialresult['day_epoch']*1000, $percentageChange];
            }
            else {
                $results[2][] = [(int)$initialresult['day_epoch']*1000, 0];
            }

            // Month on Month percentage difference all businesses
            if ($previousMonthSales == 0) {
                // Do nothing
            }
            else if ($all_businesses_sales > $previousMonthSales || $all_businesses_sales < $previousMonthSales) {
                $percentageChange = ((($previousMonthSales - $all_businesses_sales) / $previousMonthSales) * 100) * -1;
                $results[3][] = [(int)$initialresult['day_epoch']*1000, $percentageChange];
            }
            else {
                $results[3][] = [(int)$initialresult['day_epoch']*1000, 0];
            }

            $previousMonthSales = $all_businesses_sales;
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listMonthWeeklyAverageOnlineHouseholdGoodsSales(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_retail_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $results = [];

        $retailOnlineWeeklyAverageStatsQuery = new RetailOnlineWeeklyAverageStats;
        $initialresults = $retailOnlineWeeklyAverageStatsQuery
            ->selectRaw('day_epoch, household_goods_stores AS online_sales')
            ->where('day_epoch', '>=', $args['start']/1000)
            ->where('day_epoch', '<', $args['end']/1000)
            ->groupBy('day_epoch')
            ->orderBy('day_epoch', 'ASC')
            ->get();

        /**
         * process the results
         */
        foreach ($initialresults as $initialresult) {
            $results[] = [(int)$initialresult['day_epoch']*1000, (int)$initialresult['online_sales']];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listMonthWeeklyAverageOnlineHouseholdGoodsSalesPercentageChange(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_retail_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $results = [];

        $retailOnlineWeeklyAverageStatsQuery = new RetailOnlineWeeklyAverageStats;
        $initialresults = $retailOnlineWeeklyAverageStatsQuery
            ->selectRaw('day_epoch, household_goods_stores AS online_sales')
            ->where('day_epoch', '>=', $args['start']/1000)
            ->where('day_epoch', '<', $args['end']/1000)
            ->groupBy('day_epoch')
            ->orderBy('day_epoch', 'ASC')
            ->get();

        /**
         * process the results
         */
        foreach ($initialresults as $initialresult) {
            $online_sales = (int)$initialresult['online_sales'];

            $oneYearAgo = Carbon::createFromTimestamp((int)$initialresult['day_epoch'])->subYears(1)->timestamp; 

            $salesOneYearAgo = $retailOnlineWeeklyAverageStatsQuery
                ->selectRaw('day_epoch, date, household_goods_stores AS online_sales')
                ->where('day_epoch', $oneYearAgo)
                ->first();

            if ($online_sales != 0 && $salesOneYearAgo['online_sales'] != 0 && $online_sales != $salesOneYearAgo['online_sales']) {
                $percentageChange = ((($salesOneYearAgo['online_sales'] - $online_sales) / $salesOneYearAgo['online_sales']) * 100) * -1;
                $results[] = [(int)$initialresult['day_epoch']*1000, $percentageChange];
            }
            else {
                $results[] = [(int)$initialresult['day_epoch']*1000, 0];
            }
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listMonthWeeklyAverageNoneStoreRetailingSales(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_retail_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $results = [];

        $retailWeeklyAverageStatsQuery = new RetailWeeklyAverageStats;
        $initialresults = $retailWeeklyAverageStatsQuery
            ->selectRaw('day_epoch, 
                non_store_retail_all_retailing AS all_businesses_sales, 
                non_store_retail_large_businesses AS large_businesses_sales, 
                non_store_retail_small_businesses AS small_businesses_sales
            ')
            ->where('day_epoch', '>=', $args['start']/1000)
            ->where('day_epoch', '<', $args['end']/1000)
            ->groupBy('day_epoch')
            ->orderBy('day_epoch', 'ASC')
            ->get();

        /**
         * process the results
         */
        foreach ($initialresults as $initialresult) {
            $results[0][] = [(int)$initialresult['day_epoch']*1000, (int)$initialresult['all_businesses_sales']];
            $results[1][] = [(int)$initialresult['day_epoch']*1000, (int)$initialresult['large_businesses_sales']];
            $results[2][] = [(int)$initialresult['day_epoch']*1000, (int)$initialresult['small_businesses_sales']];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listMonthWeeklyAverageNoneStoreRetailingSalesPercentageChange(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_retail_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $results = [];

        $retailWeeklyAverageStatsQuery = new RetailWeeklyAverageStats;
        $initialresults = $retailWeeklyAverageStatsQuery
            ->selectRaw('day_epoch, 
                non_store_retail_all_retailing AS all_businesses_sales, 
                non_store_retail_large_businesses AS large_businesses_sales, 
                non_store_retail_small_businesses AS small_businesses_sales
            ')
            ->where('day_epoch', '>=', $args['start']/1000)
            ->where('day_epoch', '<', $args['end']/1000)
            ->groupBy('day_epoch')
            ->orderBy('day_epoch', 'ASC')
            ->get();

        /**
         * process the results
         */
        $previousMonthSales = 0;
        foreach ($initialresults as $initialresult) {
            $all_businesses_sales   = (int)$initialresult['all_businesses_sales'];
            $large_businesses_sales = (int)$initialresult['large_businesses_sales'];
            $small_businesses_sales = (int)$initialresult['small_businesses_sales'];

            $oneYearAgo = Carbon::createFromTimestamp((int)$initialresult['day_epoch'])->subYears(1)->timestamp; 

            $salesOneYearAgo = $retailWeeklyAverageStatsQuery
                ->selectRaw('day_epoch, 
                    non_store_retail_all_retailing AS all_businesses_sales, 
                    non_store_retail_large_businesses AS large_businesses_sales, 
                    non_store_retail_small_businesses AS small_businesses_sales
                ')
                ->where('day_epoch', $oneYearAgo)
                ->first();

            // All businesses
            if ($all_businesses_sales != 0 && $salesOneYearAgo['all_businesses_sales'] != 0 && $all_businesses_sales != $salesOneYearAgo['all_businesses_sales']) {
                $percentageChange = ((($salesOneYearAgo['all_businesses_sales'] - $all_businesses_sales) / $salesOneYearAgo['all_businesses_sales']) * 100) * -1;
                $results[0][] = [(int)$initialresult['day_epoch']*1000, $percentageChange];
            }
            else {
                $results[0][] = [(int)$initialresult['day_epoch']*1000, 0];
            }

            // Large Businesses
            if ($large_businesses_sales != 0 && $salesOneYearAgo['large_businesses_sales'] != 0 && $large_businesses_sales != $salesOneYearAgo['large_businesses_sales']) {
                $percentageChange = ((($salesOneYearAgo['large_businesses_sales'] - $large_businesses_sales) / $salesOneYearAgo['large_businesses_sales']) * 100) * -1;
                $results[1][] = [(int)$initialresult['day_epoch']*1000, $percentageChange];
            }
            else {
                $results[1][] = [(int)$initialresult['day_epoch']*1000, 0];
            }

            // Small Businesses
            if ($small_businesses_sales != 0 && $salesOneYearAgo['small_businesses_sales'] != 0 && $small_businesses_sales != $salesOneYearAgo['small_businesses_sales']) {
                $percentageChange = ((($salesOneYearAgo['small_businesses_sales'] - $small_businesses_sales) / $salesOneYearAgo['small_businesses_sales']) * 100) * -1;
                $results[2][] = [(int)$initialresult['day_epoch']*1000, $percentageChange];
            }
            else {
                $results[2][] = [(int)$initialresult['day_epoch']*1000, 0];
            }

            // Month on Month percentage difference all businesses
            if ($previousMonthSales == 0) {
                // Do nothing
            }
            else if ($all_businesses_sales > $previousMonthSales || $all_businesses_sales < $previousMonthSales) {
                $percentageChange = ((($previousMonthSales - $all_businesses_sales) / $previousMonthSales) * 100) * -1;
                $results[3][] = [(int)$initialresult['day_epoch']*1000, $percentageChange];
            }
            else {
                $results[3][] = [(int)$initialresult['day_epoch']*1000, 0];
            }

            $previousMonthSales = $all_businesses_sales;
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listMonthWeeklyAverageOnlineNoneStoreRetailingSales(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_retail_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $results = [];

        $retailOnlineWeeklyAverageStatsQuery = new RetailOnlineWeeklyAverageStats;
        $initialresults = $retailOnlineWeeklyAverageStatsQuery
            ->selectRaw('day_epoch, non_store_retailing AS online_sales')
            ->where('day_epoch', '>=', $args['start']/1000)
            ->where('day_epoch', '<', $args['end']/1000)
            ->groupBy('day_epoch')
            ->orderBy('day_epoch', 'ASC')
            ->get();

        /**
         * process the results
         */
        foreach ($initialresults as $initialresult) {
            $results[] = [(int)$initialresult['day_epoch']*1000, (int)$initialresult['online_sales']];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listMonthWeeklyAverageOnlineNoneStoreRetailingSalesPercentageChange(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_retail_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $results = [];

        $retailOnlineWeeklyAverageStatsQuery = new RetailOnlineWeeklyAverageStats;
        $initialresults = $retailOnlineWeeklyAverageStatsQuery
            ->selectRaw('day_epoch, non_store_retailing AS online_sales')
            ->where('day_epoch', '>=', $args['start']/1000)
            ->where('day_epoch', '<', $args['end']/1000)
            ->groupBy('day_epoch')
            ->orderBy('day_epoch', 'ASC')
            ->get();

        /**
         * process the results
         */
        foreach ($initialresults as $initialresult) {
            $online_sales = (int)$initialresult['online_sales'];

            $oneYearAgo = Carbon::createFromTimestamp((int)$initialresult['day_epoch'])->subYears(1)->timestamp; 

            $salesOneYearAgo = $retailOnlineWeeklyAverageStatsQuery
                ->selectRaw('day_epoch, date, non_store_retailing AS online_sales')
                ->where('day_epoch', $oneYearAgo)
                ->first();

            if ($online_sales != 0 && $salesOneYearAgo['online_sales'] != 0 && $online_sales != $salesOneYearAgo['online_sales']) {
                $percentageChange = ((($salesOneYearAgo['online_sales'] - $online_sales) / $salesOneYearAgo['online_sales']) * 100) * -1;
                $results[] = [(int)$initialresult['day_epoch']*1000, $percentageChange];
            }
            else {
                $results[] = [(int)$initialresult['day_epoch']*1000, 0];
            }
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listMonthWeeklyAverageFuelSales(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_retail_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $results = [];

        $retailWeeklyAverageStatsQuery = new RetailWeeklyAverageStats;
        $initialresults = $retailWeeklyAverageStatsQuery
            ->selectRaw('day_epoch, automotive_fuel AS automotive_fuel')
            ->where('day_epoch', '>=', $args['start']/1000)
            ->where('day_epoch', '<', $args['end']/1000)
            ->groupBy('day_epoch')
            ->orderBy('day_epoch', 'ASC')
            ->get();

        /**
         * process the results
         */
        foreach ($initialresults as $initialresult) {
            $results[] = [(int)$initialresult['day_epoch']*1000, (int)$initialresult['automotive_fuel']];
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }

    public function listMonthWeeklyAverageFuelSalesPercentageChange(Request $request, Response $response, $args)
    {
        // Get the authorizer
        $authorizer = $this->ci->authorizer;

        // Get the current user
        $currentUser = $this->ci->currentUser;

        // Check if user has permissions
        if (!$authorizer->checkAccess($currentUser, 'uri_retail_dashboard')) {
            throw new NotFoundException($request, $response);
        }

        $results = [];

        $retailWeeklyAverageStatsQuery = new RetailWeeklyAverageStats;
        $initialresults = $retailWeeklyAverageStatsQuery
            ->selectRaw('day_epoch, automotive_fuel AS automotive_fuel')
            ->where('day_epoch', '>=', $args['start']/1000)
            ->where('day_epoch', '<', $args['end']/1000)
            ->groupBy('day_epoch')
            ->orderBy('day_epoch', 'ASC')
            ->get();

        /**
         * process the results
         */
        $previousMonthSales = 0;
        foreach ($initialresults as $initialresult) {
            $automotive_fuel = (int)$initialresult['automotive_fuel'];

            $oneYearAgo = Carbon::createFromTimestamp((int)$initialresult['day_epoch'])->subYears(1)->timestamp; 

            $salesOneYearAgo = $retailWeeklyAverageStatsQuery
                ->selectRaw('day_epoch, automotive_fuel AS automotive_fuel')
                ->where('day_epoch', $oneYearAgo)
                ->first();

            // All businesses
            if ($automotive_fuel != 0 && $salesOneYearAgo['automotive_fuel'] != 0 && $automotive_fuel != $salesOneYearAgo['automotive_fuel']) {
                $percentageChange = ((($salesOneYearAgo['automotive_fuel'] - $automotive_fuel) / $salesOneYearAgo['automotive_fuel']) * 100) * -1;
                $results[0][] = [(int)$initialresult['day_epoch']*1000, $percentageChange];
            }
            else {
                $results[0][] = [(int)$initialresult['day_epoch']*1000, 0];
            }

            // Month on Month percentage difference all businesses
            if ($previousMonthSales == 0) {
                // Do nothing
            }
            else if ($automotive_fuel > $previousMonthSales || $automotive_fuel < $previousMonthSales) {
                $percentageChange = ((($previousMonthSales - $automotive_fuel) / $previousMonthSales) * 100) * -1;
                $results[1][] = [(int)$initialresult['day_epoch']*1000, $percentageChange];
            }
            else {
                $results[1][] = [(int)$initialresult['day_epoch']*1000, 0];
            }

            $previousMonthSales = $automotive_fuel;
        }

        /**
         * output the results in correct json formatting
         */
        return $response->withJson($results, 200, JSON_PRETTY_PRINT);
    }
}